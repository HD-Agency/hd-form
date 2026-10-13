<?php
/**
 * GitHub-based plugin auto-updater via plugin-update-checker (PUC).
 *
 * Repository URL and tracked branch are intentionally fixed to this plugin's
 * own repository (`HD-Agency/hd-form`, branch `main`).
 * They are not user-configurable settings. Hardcoding prevents accidental
 * redirection to an untrusted source.
 *
 * Provides non-blocking background update checks, client-side Live Push updates
 * on `plugins.php`, strict HTTP timeouts, and a fail-safe package integrity guard.
 *
 * The Personal Access Token is stored encrypted in wp_options under its own
 * key, or read from HDF_GITHUB_TOKEN constant/env var in wp-config.php.
 *
 * @package HDForm\Updater
 */

declare(strict_types=1);

namespace HDForm\Updater;

use HDForm\Support\Crypto;
use WP_Error;
use WP_Filesystem_Base;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker;

defined( 'ABSPATH' ) || exit;

final class GitHubUpdater {

	/** @internal Intentionally fixed — not a user-configurable setting. */
	private const REPO_URL           = 'https://github.com/HD-Agency/hd-form';
	public const TOKEN_OPTION        = '_hdf_github_token';
	public const COOLDOWN_TRANSIENT  = '_hdf_github_update_cooldown';
	public const COOLDOWN_SECONDS    = 300; // 5 minutes
	private const CHECK_PERIOD_HOURS = 2;
	private const HTTP_TIMEOUT_WEB   = 2.5;
	private const HTTP_TIMEOUT_CRON  = 5.0;

	private static ?self $instance        = null;
	private ?PluginUpdateChecker $checker = null;
	private bool $routesRegistered        = false;

	public static function init(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		$this->initUpdateChecker();
	}

	private function initUpdateChecker(): void {
		try {
			// Enforce strict HTTP timeouts to protect against network delay/hangs.
			add_filter( 'puc_request_info_options-hd-form', [ $this, 'filterHttpRequestOptions' ] );

			// Guard against synchronous blocking checks on passive admin page loads.
			add_filter( 'puc_check_now-hd-form', [ $this, 'shouldCheckNow' ] );

			// Strip Authorization header on external redirect (e.g. api.github.com -> codeload.github.com).
			add_action( 'requests-requests.before_redirect', [ $this, 'filterRequestsRedirect' ], 10, 2 );

			// Pre-unpack archive integrity guard: validate ZIP magic bytes and reject corrupt/truncated downloads.
			add_filter( 'upgrader_pre_download', [ $this, 'validateDownloadArchive' ], 20, 4 );

			// Normalize GitHub archive directory on Windows (Laragon/NTFS) to prevent rename failures.
			add_filter( 'upgrader_source_selection', [ $this, 'normalizeSourceDirectory' ], 5, 4 );

			// Fail-safe package integrity guard: abort before clear_destination() if package is incomplete.
			add_filter( 'upgrader_source_selection', [ $this, 'validatePackageIntegrity' ], 25, 4 );

			// Client-side live push updater on plugins.php.
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueuePluginsScript' ] );

			// Prevent redundant HTTP 404 query to /releases/latest when repo does not use GitHub releases.
			add_filter( 'puc_vcs_update_detection_strategies-hd-form', [ $this, 'filterUpdateDetectionStrategies' ] );

			// REST check endpoint.
			if ( did_action( 'rest_api_init' ) ) {
				$this->registerRestRoutes();
			} else {
				add_action( 'rest_api_init', [ $this, 'registerRestRoutes' ] );
			}

			$pluginFile = defined( 'HD_FORM_PATH' )
				? HD_FORM_PATH . 'hd-form.php'
				: dirname( __DIR__, 2 ) . '/hd-form.php';

			/** @var PluginUpdateChecker $checker */
			$checker = PucFactory::buildUpdateChecker(
				self::REPO_URL,
				$pluginFile,
				'hd-form',
				self::CHECK_PERIOD_HOURS
			);

			$checker->setBranch( 'main' );

			$token = $this->getToken();
			if ( $token ) {
				$checker->setAuthentication( $token );
			}

			$this->checker = $checker;
		} catch ( \Throwable $e ) {
			// Silently degrade in production; surface a safe diagnostic in debug mode.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[HDF Updater] Failed to initialize update checker: %s', $e->getMessage() ) );
			}
		}
	}

	// ── HTTP Timeout Guard ───────────────────────────────────────────────

	/**
	 * Enforce strict HTTP timeout on GitHub API requests to prevent PHP worker stalls.
	 *
	 * @param array<string, mixed> $options HTTP request options for wp_remote_get.
	 * @return array<string, mixed>
	 */
	public function filterHttpRequestOptions( array $options ): array {
		$isCron             = ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron();
		$options['timeout'] = $isCron ? self::HTTP_TIMEOUT_CRON : self::HTTP_TIMEOUT_WEB;

		return $options;
	}

	/**
	 * Filter update detection strategies.
	 *
	 * Unsets 'latest_release' when repository relies on branch or tags instead of GitHub Releases,
	 * preventing an unwanted HTTP 404 error and eliminating ~760ms TTFB overhead on update checks.
	 *
	 * @param array<string, callable> $strategies
	 * @return array<string, callable>
	 */
	public function filterUpdateDetectionStrategies( array $strategies ): array {
		unset( $strategies['latest_release'], $strategies['latest_tag'] );

		return $strategies;
	}

	// ── Synchronous & Asynchronous Check Dispatcher ─────────────────────

	/**
	 * Allow update checks during WP-Cron, "Dashboard → Updates", manual "Check for updates", or bulk upgrades.
	 * Blocks synchronous blocking checks on passive admin page loads for 0ms latency.
	 *
	 * @param bool $shouldCheck Current decision from PUC scheduler.
	 */
	public function shouldCheckNow( bool $shouldCheck ): bool {
		if ( ! $shouldCheck ) {
			return false;
		}

		// 1. Always allow cron-triggered checks.
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron() ) {
			return true;
		}

		// 2. Allow manual "Check for updates" link on plugins.php.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['puc_check_for_updates'] ) || ! empty( $_GET['puc_slug'] ) ) {
			delete_transient( self::COOLDOWN_TRANSIENT );
			return true;
		}

		// 3. Allow manual "Check Again" on Dashboard → Updates ONLY on explicit force-check request, or after bulk upgrades.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ( doing_action( 'load-update-core.php' ) && ! empty( $_GET['force-check'] ) ) || doing_action( 'upgrader_process_complete' ) ) {
			delete_transient( self::COOLDOWN_TRANSIENT );
			return true;
		}

		// Block synchronous execution on regular web thread for 0ms page load latency.
		return false;
	}

	// ── REST API Route & Live Check ─────────────────────────────────────

	/**
	 * Register REST routes for background Live Push checks.
	 */
	public function registerRestRoutes(): void {
		if ( $this->routesRegistered ) {
			return;
		}
		$this->routesRegistered = true;

		$namespace = defined( 'HD_FORM_REST_NAMESPACE' ) ? HD_FORM_REST_NAMESPACE : 'hd/v1';

		register_rest_route(
			$namespace,
			'/updater/check',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handleRestCheck' ],
				'permission_callback' => [ $this, 'checkRestPermission' ],
				'args'                => [
					'force' => [
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);
	}

	/**
	 * Permission callback: only authenticated administrators who can update plugins.
	 */
	public function checkRestPermission( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return false;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' );

		return (bool) ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) );
	}

	/**
	 * Get the number of seconds remaining before the next update check is permitted.
	 *
	 * @return int Seconds remaining, or 0 if cooldown has elapsed or transient is absent.
	 */
	public function getCooldownRemaining(): int {
		$expireAt = get_transient( self::COOLDOWN_TRANSIENT );
		if ( ! $expireAt ) {
			return 0;
		}

		if ( is_numeric( $expireAt ) && (int) $expireAt > 1 ) {
			return max( 0, (int) $expireAt - (int) time() );
		}

		$timeout = (int) get_option( '_transient_timeout_' . self::COOLDOWN_TRANSIENT );
		if ( $timeout <= 0 ) {
			return (int) self::COOLDOWN_SECONDS;
		}

		$remaining = $timeout - (int) time();

		return max( 0, $remaining );
	}

	/**
	 * REST handler for checking updates in background.
	 */
	public function handleRestCheck( WP_REST_Request $request ): WP_REST_Response {
		$force       = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$hasCooldown = (bool) get_transient( self::COOLDOWN_TRANSIENT );

		if ( ( ! $hasCooldown || $force ) && $this->checker ) {
			$expireAt = (int) time() + self::COOLDOWN_SECONDS;
			set_transient( self::COOLDOWN_TRANSIENT, $expireAt, self::COOLDOWN_SECONDS );
			$this->checker->checkForUpdates();
		}

		$update         = $this->checker ? $this->checker->getUpdate() : null;
		$currentVersion = defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '1.1.5';
		$hasUpdate      = ( null !== $update && ! empty( $update->version ) && version_compare( (string) $update->version, $currentVersion, '>' ) );

		if ( $hasUpdate ) {
			$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
			return new WP_REST_Response(
				[
					'has_update'         => true,
					'current_version'    => $currentVersion,
					'new_version'        => (string) $update->version,
					'cooldown_remaining' => $this->getCooldownRemaining(),
					'update_url'         => wp_nonce_url(
						self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $pluginBasename ) ),
						'upgrade-plugin_' . $pluginBasename
					),
					'details_url'        => self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=hd-form&section=changelog&TB_iframe=true&width=600&height=800' ),
					'row_html'           => $this->buildUpdateRowHtml( (string) $update->version ),
				],
				200
			);
		}

		return new WP_REST_Response(
			[
				'has_update'         => false,
				'current_version'    => $currentVersion,
				'cooldown_remaining' => $this->getCooldownRemaining(),
			],
			200
		);
	}

	/**
	 * Render WordPress standard plugin update table row HTML.
	 */
	public function buildUpdateRowHtml( string $newVersion ): string {
		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
		$updateUrl      = wp_nonce_url(
			self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $pluginBasename ) ),
			'upgrade-plugin_' . $pluginBasename
		);
		$detailsUrl     = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=hd-form&section=changelog&TB_iframe=true&width=600&height=800' );

		$noticeText = sprintf(
			/* translators: 1: Plugin name, 2: Details link, 3: Details link aria label, 4: Version, 5: Update link, 6: Update link aria label */
			__( 'There is a new version of %1$s available. <a href="%2$s" class="thickbox open-plugin-details-modal" aria-label="%3$s">View version %4$s details</a> or <a href="%5$s" class="update-link" aria-label="%6$s">update now</a>.', 'hd-form' ),
			'HD Form',
			esc_url( $detailsUrl ),
			/* translators: %s: Plugin version. */
			esc_attr( sprintf( __( 'View HD Form version %s details', 'hd-form' ), $newVersion ) ),
			esc_html( $newVersion ),
			esc_url( $updateUrl ),
			esc_attr( __( 'Update HD Form now', 'hd-form' ) )
		);

		return sprintf(
			'<tr class="plugin-update-tr active" id="hdf-update" data-slug="hd-form" data-plugin="%1$s">'
			. '<td colspan="4" class="plugin-update colspanchange">'
			. '<div class="update-message notice inline notice-warning notice-alt">'
			. '<p>%2$s</p>'
			. '</div>'
			. '</td>'
			. '</tr>',
			esc_attr( $pluginBasename ),
			$noticeText
		);
	}

	// ── Client-Side Live Push on plugins.php ─────────────────────────────

	/**
	 * Enqueue lightweight Live Push script on wp-admin/plugins.php and update-core.php.
	 */
	public function enqueuePluginsScript( string $hook ): void {
		if ( ! in_array( $hook, [ 'plugins.php', 'update-core.php' ], true ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';

		// Guard: If WordPress already knows an update is available via site transient,
		// WP Core will render the update notice row server-side. Skip polling to prevent duplicates.
		$currentUpdates = get_site_transient( 'update_plugins' );
		$currentVersion = defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '1.1.5';
		if (
			is_object( $currentUpdates )
			&& isset( $currentUpdates->response[ $pluginBasename ] )
			&& ! empty( $currentUpdates->response[ $pluginBasename ]->new_version )
			&& version_compare( (string) $currentUpdates->response[ $pluginBasename ]->new_version, $currentVersion, '>' )
		) {
			return;
		}

		$namespace = defined( 'HD_FORM_REST_NAMESPACE' ) ? HD_FORM_REST_NAMESPACE : 'hd/v1';

		$config = [
			'endpoint'          => esc_url_raw( rest_url( $namespace . '/updater/check' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'hasCooldown'       => (bool) get_transient( self::COOLDOWN_TRANSIENT ),
			'cooldownRemaining' => $this->getCooldownRemaining(),
			'cooldownSeconds'   => (int) self::COOLDOWN_SECONDS,
		];

		wp_register_script( 'hdf-updater-live', '', [], defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '1.1.5', [ 'in_footer' => true ] );
		wp_enqueue_script( 'hdf-updater-live' );
		wp_add_inline_script(
			'hdf-updater-live',
			sprintf(
				'window.hdfUpdaterLiveConfig = %s;
(function() {
	function hasUpdateNotice() {
		if (document.getElementById("hdf-update") || document.getElementById("hdf-update-core-notice")) {
			return true;
		}
		if (document.querySelector("tr.plugin-update-tr[data-plugin=\"%s\"]")) {
			return true;
		}
		var targetRow = document.querySelector("tr[data-plugin=\"%s\"]");
		if (targetRow) {
			var next = targetRow.nextElementSibling;
			if (next && (next.classList.contains("plugin-update-tr") || next.querySelector(".update-message"))) {
				return true;
			}
		}
		return false;
	}

	if (hasUpdateNotice()) {
		return;
	}
	var config = window.hdfUpdaterLiveConfig;
	if (!config || !config.endpoint) {
		return;
	}

	var pollTimer = null;
	var isChecking = false;
	var storageKey = "hdf_updater_last_check";

	function scheduleNext(delayMs) {
		if (pollTimer) {
			clearTimeout(pollTimer);
			pollTimer = null;
		}
		if (hasUpdateNotice()) {
			return;
		}
		pollTimer = setTimeout(executeCheck, Math.max(100, delayMs));
	}

	function executeCheck() {
		if (hasUpdateNotice()) {
			return;
		}
		if (document.hidden) {
			return;
		}
		if (isChecking) {
			return;
		}

		var minGap = (config.cooldownSeconds || 300) * 1000;
		try {
			var lastCheck = parseInt(localStorage.getItem(storageKey), 10) || 0;
			var now = Date.now();
			if (lastCheck > 0 && (now - lastCheck) < (minGap - 500)) {
				scheduleNext(minGap - (now - lastCheck));
				return;
			}
		} catch (e) {}

		isChecking = true;
		try {
			localStorage.setItem(storageKey, String(Date.now()));
		} catch (e) {}

		fetch(config.endpoint, {
			headers: { "X-WP-Nonce": config.nonce },
			credentials: "same-origin"
		})
		.then(function(res) { return res.ok ? res.json() : null; })
		.then(function(data) {
			isChecking = false;
			if (!data) {
				scheduleNext(minGap);
				return;
			}

			if (data.has_update) {
				if (hasUpdateNotice()) {
					return;
				}
				var targetRow = document.querySelector("tr[data-plugin=\"%s\"]") || document.getElementById("hd-form");
				if (targetRow && data.row_html) {
					// Purge any existing update row before insertion to guarantee zero duplicate rows.
					var existing = document.querySelector("tr.plugin-update-tr[data-plugin=\"%s\"]") || document.getElementById("hdf-update");
					if (existing) {
						existing.remove();
					}
					targetRow.insertAdjacentHTML("afterend", data.row_html);
					var alreadyMarked = targetRow.classList.contains("update");
					targetRow.classList.add("update");

					// Update admin menu badge count only if not already counted.
					if (!alreadyMarked) {
						var menuBadges = document.querySelectorAll("#menu-plugins .plugin-count, #wp-admin-bar-updates .ab-label, #menu-dashboard .update-count");
						if (menuBadges.length > 0) {
							menuBadges.forEach(function(el) {
								var count = parseInt(el.textContent, 10) || 0;
								el.textContent = String(count + 1);
							});
						} else {
							var menuLink = document.querySelector("#menu-plugins a.wp-has-submenu[href=\"plugins.php\"], #menu-plugins a[href=\"plugins.php\"]");
							if (menuLink && !menuLink.querySelector(".update-plugins")) {
								menuLink.insertAdjacentHTML("beforeend", \'<span class="update-plugins count-1"><span class="plugin-count">1</span></span>\');
							}
						}
					}
				} else {
					var wrap = document.querySelector(".wrap");
					if (wrap && !document.getElementById("hdf-update-core-notice")) {
						var notice = document.createElement("div");
						notice.id = "hdf-update-core-notice";
						notice.className = "notice notice-warning is-dismissible";
						notice.innerHTML = "<p><strong>HD Form:</strong> A new version <strong>" + (data.new_version || "") + "</strong> is available. <a href=\"" + window.location.href + "\">Reload page</a> to update.</p>";
						var h1 = wrap.querySelector("h1");
						if (h1 && h1.nextSibling) {
							wrap.insertBefore(notice, h1.nextSibling);
						} else {
							wrap.prepend(notice);
						}
					}
				}

				// Terminal state: clear timer and teardown polling.
				if (pollTimer) {
					clearTimeout(pollTimer);
					pollTimer = null;
				}
				return;
			}

			// No update available: schedule next poll based on remaining cooldown.
			var nextDelay = (typeof data.cooldown_remaining === "number" && data.cooldown_remaining > 0)
				? data.cooldown_remaining * 1000
				: minGap;
			scheduleNext(nextDelay);
		})
		.catch(function() {
			isChecking = false;
			// Exponential backoff on network failure.
			scheduleNext(minGap * 2);
		});
	}

	// Page visibility listener: freeze timer when hidden, evaluate when visible.
	document.addEventListener("visibilitychange", function() {
		if (document.hidden) {
			if (pollTimer) {
				clearTimeout(pollTimer);
				pollTimer = null;
			}
		} else {
			if (hasUpdateNotice()) {
				return;
			}
			var minGap = (config.cooldownSeconds || 300) * 1000;
			var lastCheck = 0;
			try {
				lastCheck = parseInt(localStorage.getItem(storageKey), 10) || 0;
			} catch (e) {}
			var elapsed = Date.now() - lastCheck;
			if (elapsed >= minGap) {
				scheduleNext(100);
			} else {
				scheduleNext(minGap - elapsed);
			}
		}
	});

	// Initial schedule: if cooldown remaining is passed, use it, otherwise check immediately.
	var initialDelay = (typeof config.cooldownRemaining === "number" && config.cooldownRemaining > 0)
		? config.cooldownRemaining * 1000
		: 500;
	scheduleNext(initialDelay);
})();',
				wp_json_encode( $config ),
				esc_js( $pluginBasename ),
				esc_js( $pluginBasename ),
				esc_js( $pluginBasename ),
				esc_js( $pluginBasename )
			)
		);
	}

	// ── Windows NTFS Normalization Guard ─────────────────────────────────

	/**
	 * Normalize GitHub archive directory name to standard plugin slug.
	 *
	 * GitHub zipball downloads unpack into arbitrary directory names (e.g. `HD-Agency-hd-form-1234567`).
	 * On Windows (Laragon/NTFS), PHP's rename() inside WP_Filesystem_Direct::move() fails with
	 * Win32 Error 5 (Access Denied). WordPress core's move_dir() gracefully falls back to copy_dir()
	 * and deletes the source, preventing `puc-rename-failed` update aborts.
	 *
	 * Hooked at priority 5 (before PUC's fixDirectoryName at priority 10).
	 *
	 * @param mixed $source
	 * @param mixed $remoteSource
	 * @param mixed $upgrader
	 * @param mixed $hookExtra
	 *
	 * @return string|WP_Error
	 */
	public function normalizeSourceDirectory( mixed $source, mixed $remoteSource, mixed $upgrader, mixed $hookExtra = null ): string|WP_Error {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error( 'hdf_invalid_source', __( 'Invalid update source path.', 'hd-form' ) );
		}

		if ( ! is_string( $remoteSource ) || '' === $remoteSource ) {
			return $source;
		}

		if ( ! $this->isHdfUpgrade( $source, $upgrader, $hookExtra ) ) {
			return $source;
		}

		$correctedSource = trailingslashit( $remoteSource ) . 'hd-form/';
		if ( trailingslashit( $source ) === $correctedSource ) {
			return $source;
		}

		if ( ! function_exists( 'move_dir' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$result = move_dir( $source, $correctedSource, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $correctedSource;
	}

	// ── Fail-Safe Package Integrity Guard ────────────────────────────────

	/**
	 * Fail-Safe Package Integrity Guard.
	 *
	 * Inspects the unzipped archive before WordPress deletes the existing plugin installation.
	 * If critical core files are missing, the update aborts cleanly and preserves the active plugin.
	 *
	 * @param mixed $source
	 * @param mixed $remoteSource
	 * @param mixed $upgrader
	 * @param mixed $hookExtra
	 *
	 * @return string|WP_Error
	 */
	public function validatePackageIntegrity( mixed $source, mixed $remoteSource, mixed $upgrader, mixed $hookExtra = null ): string|WP_Error {
		global $wp_filesystem;

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error( 'hdf_invalid_source', __( 'Invalid update source path.', 'hd-form' ) );
		}

		if ( ! isset( $upgrader, $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
			return $source;
		}

		// Identify whether HD Form is the plugin being upgraded.
		if ( ! $this->isHdfUpgrade( $source, $upgrader, $hookExtra ) ) {
			return $source;
		}

		$sourceDir = trailingslashit( $source );
		$hasMain   = $wp_filesystem->exists( $sourceDir . 'hd-form.php' );
		$hasPlugin = $wp_filesystem->exists( $sourceDir . 'src/Plugin.php' );
		$hasVendor = $wp_filesystem->exists( $sourceDir . 'vendor/autoload.php' );

		if ( ! $hasMain || ! $hasPlugin || ! $hasVendor ) {
			$missing = array_filter(
				[
					! $hasMain ? 'hd-form.php' : null,
					! $hasPlugin ? 'src/Plugin.php' : null,
					! $hasVendor ? 'vendor/autoload.php' : null,
				]
			);

			return new WP_Error(
				'hdf_corrupted_package',
				sprintf(
					/* translators: %s: Comma-separated list of missing files */
					__( 'HD Form update aborted: The downloaded package is incomplete (missing: %s). The existing plugin installation was preserved.', 'hd-form' ),
					implode( ', ', $missing )
				)
			);
		}

		return $source;
	}

	/**
	 * Determine if current upgrade action targets HD Form.
	 *
	 * Canonical detection checking:
	 * 1. $hookExtra['plugin']
	 * 2. $upgrader->skin->plugin
	 * 3. PUC isBeingUpgraded()
	 * 4. Directory slug basename match
	 *
	 * @param string $source
	 * @param mixed  $upgrader
	 * @param mixed  $hookExtra
	 *
	 * @return bool
	 */
	private function isHdfUpgrade( string $source, mixed $upgrader, mixed $hookExtra ): bool {
		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';

		if ( is_array( $hookExtra ) && isset( $hookExtra['plugin'] ) && $pluginBasename === $hookExtra['plugin'] ) {
			return true;
		}

		if ( isset( $upgrader->skin->plugin ) && $pluginBasename === $upgrader->skin->plugin ) {
			return true;
		}

		if ( isset( $this->checker ) && $this->checker->isBeingUpgraded( $upgrader ) ) {
			return true;
		}

		if ( 'hd-form' === basename( rtrim( $source, '/\\' ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the GitHub repository path (e.g. `HD-Agency/hd-form`) derived from REPO_URL.
	 */
	private function getRepoPath(): string {
		return trim( (string) wp_parse_url( self::REPO_URL, PHP_URL_PATH ), '/' );
	}

	// ── Pre-Unpack ZIP Magic Bytes & Filesize Guard ──────────────────────

	/**
	 * Validate and process update archive download before unpacking.
	 *
	 * Intercepts download package flow via `upgrader_pre_download` at priority 20.
	 * Guarantees that:
	 * 1. Stale temporary archives in `%TEMP%` are purged before download to avoid Windows NTFS rename collision.
	 * 2. Remote HD-Form packages are safely fetched with environment/encrypted token.
	 * 3. The archive exists, has non-trivial size (>= 10KB), and starts with ZIP magic signature 0x04034b50 (PK\x03\x04).
	 * 4. If corrupted or truncated, immediately unlinks the temporary file and aborts with descriptive WP_Error,
	 *    preventing PclZip from throwing PCLZIP_ERR_BAD_FORMAT (-10) : Invalid archive structure.
	 *
	 * @param mixed                $reply     Pass-through value from earlier filters.
	 * @param string               $package   Package URL or local file path.
	 * @param mixed                $upgrader  WP_Upgrader instance.
	 * @param array<string, mixed> $hookExtra Extra parameters passed by WordPress upgrader.
	 * @return mixed Downloaded file path on success, WP_Error on failure, or pass-through value.
	 */
	public function validateDownloadArchive( mixed $reply, string $package, mixed $upgrader, array $hookExtra = [] ): mixed {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		if ( ! $this->isHdfUpgrade( $package, $upgrader, $hookExtra ) ) {
			return $reply;
		}

		// Purge any stale archive artifacts before download proceeds.
		$this->cleanStaleTempArchives();

		// If a previous filter already supplied a local package path, validate its magic bytes.
		if ( is_string( $reply ) && '' !== $reply && file_exists( $reply ) ) {
			$validation = $this->verifyZipMagicBytes( $reply );
			if ( is_wp_error( $validation ) ) {
				$tempDir     = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
				$normReply   = wp_normalize_path( $reply );
				$normTempDir = wp_normalize_path( $tempDir );
				if ( str_starts_with( $normReply, $normTempDir ) && is_file( $reply ) ) {
					wp_delete_file( $reply );
				}
				return $validation;
			}
			return $reply;
		}

		// If package is already a local file, validate and return untouched or error.
		if ( ! preg_match( '!^(http|https|ftp)://!i', $package ) && file_exists( $package ) ) {
			$validation = $this->verifyZipMagicBytes( $package );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			return $package;
		}

		// Download remote package directly with token and stream to verified temp file.
		$token = $this->getToken();
		if ( empty( $token ) ) {
			return new WP_Error(
				'hdf_missing_token',
				__( 'HD Form update aborted: GitHub authentication token is missing or empty.', 'hd-form' )
			);
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmpFile = wp_tempnam( 'hd-form-package.zip' );
		if ( ! $tmpFile ) {
			return new WP_Error(
				'hdf_temp_failed',
				__( 'HD Form update aborted: Could not create temporary file for package download.', 'hd-form' )
			);
		}

		$response = wp_safe_remote_get(
			$package,
			[
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmpFile,
				'headers'  => [
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/vnd.github+json',
					'User-Agent'    => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( is_file( $tmpFile ) ) {
				wp_delete_file( $tmpFile );
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			if ( is_file( $tmpFile ) ) {
				wp_delete_file( $tmpFile );
			}
			return new WP_Error(
				'hdf_download_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: HTTP status message */
					__( 'HD Form update aborted: GitHub returned HTTP %1$d (%2$s).', 'hd-form' ),
					(int) $code,
					wp_remote_retrieve_response_message( $response )
				)
			);
		}

		$check = $this->verifyZipMagicBytes( $tmpFile );
		if ( is_wp_error( $check ) ) {
			if ( is_file( $tmpFile ) ) {
				wp_delete_file( $tmpFile );
			}
			return $check;
		}

		return $tmpFile;
	}

	/**
	 * Verify that a file exists, has sufficient size, and starts with the ZIP magic signature.
	 *
	 * @param string $filePath Full filesystem path to the archive file.
	 * @return bool|WP_Error True if valid ZIP archive, WP_Error otherwise.
	 */
	public function verifyZipMagicBytes( string $filePath ): bool|WP_Error {
		clearstatcache( true, $filePath );

		global $wp_filesystem;
		if ( ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}

		if ( ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			return new WP_Error(
				'hdf_filesystem_uninitialized',
				__( 'HD Form update aborted: WordPress filesystem abstraction could not be initialized.', 'hd-form' )
			);
		}

		if ( ! $wp_filesystem->exists( $filePath ) ) {
			return new WP_Error(
				'hdf_archive_missing',
				__( 'The update package file does not exist.', 'hd-form' )
			);
		}

		$size = $wp_filesystem->size( $filePath );
		if ( false === $size || $size < 10240 ) {
			return new WP_Error(
				'hdf_archive_truncated',
				sprintf(
					/* translators: %d: File size in bytes */
					__( 'HD Form update aborted: The downloaded package is truncated or invalid (%d bytes).', 'hd-form' ),
					(int) $size
				)
			);
		}

		if ( ! $wp_filesystem->is_readable( $filePath ) ) {
			return new WP_Error(
				'hdf_archive_unreadable',
				__( 'HD Form update aborted: Package file is not readable for verification.', 'hd-form' )
			);
		}

		$content = $wp_filesystem->get_contents( $filePath );
		if ( false === $content || ! str_starts_with( $content, "\x50\x4b\x03\x04" ) ) {
			return new WP_Error(
				'hdf_invalid_archive_signature',
				__( 'HD Form update aborted: The downloaded package is not a valid ZIP archive (missing PK magic header).', 'hd-form' )
			);
		}

		return true;
	}

	// ── Requests 2.x Redirect & Stale Temp Archive Guard ─────────────────

	/**
	 * Strip Authorization header when following redirects away from api.github.com.
	 *
	 * WordPress 6.2+ dispatches `requests-requests.before_redirect` via Requests Hooks bridge.
	 *
	 * @param string               $location Target redirect URI.
	 * @param array<string, mixed> $headers  Outgoing HTTP request headers passed by reference.
	 */
	public function filterRequestsRedirect( string &$location, array &$headers ): void {
		$apiPrefix = 'https://api.github.com/repos/' . $this->getRepoPath() . '/';
		if ( ! str_starts_with( $location, $apiPrefix ) ) {
			unset( $headers['Authorization'] );
		}
	}

	/**
	 * Purge stale HD-Form archive temporary files from system temp directory.
	 *
	 * Prevents Windows NTFS rename collisions when download_url() renames temp files
	 * to existing locked or 0-byte destination paths.
	 *
	 * @param int $maxAgeSeconds Maximum age in seconds before a file is considered stale. Default 300 (5 minutes).
	 */
	public function cleanStaleTempArchives( int $maxAgeSeconds = 300 ): void {
		$tempDir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$tempDir = trailingslashit( $tempDir );
		if ( ! is_dir( $tempDir ) || ! is_readable( $tempDir ) ) {
			return;
		}

		$patterns = [ 'hd-agency-hd-form-*.zip', 'hd-form-package-*.tmp', 'hd-form-package-*.zip' ];
		$now      = time();

		foreach ( $patterns as $pattern ) {
			$files = glob( $tempDir . $pattern );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) && wp_is_writable( $file ) ) {
						$mtime = filemtime( $file );
						if ( false !== $mtime && ( $now - $mtime ) >= $maxAgeSeconds ) {
							wp_delete_file( $file );
						}
					}
				}
			}
		}
	}

	// ── Token resolution ────────────────────────────────────────────────

	/**
	 * Retrieve GitHub token from environment variables or wp-config.php constants.
	 * Checks HDF_GITHUB_TOKEN first across 5 sources, then falls back to shared
	 * GITHUB_TOKEN or HD_GITHUB_TOKEN.
	 */
	public static function getEnvironmentToken(): ?string {
		$keys = [ 'HDF_GITHUB_TOKEN', 'GITHUB_TOKEN', 'HD_GITHUB_TOKEN' ];

		foreach ( $keys as $key ) {
			// 1. Direct PHP constant (wp-config.php)
			if ( defined( $key ) && constant( $key ) ) {
				return (string) constant( $key );
			}

			// 2. env() helper (Bedrock / Roots)
			if ( function_exists( 'env' ) ) {
				$val = env( $key );
				if ( ! empty( $val ) ) {
					return (string) $val;
				}
			}

			// 3. $_ENV superglobal (Dotenv)
			if ( ! empty( $_ENV[ $key ] ) ) {
				return (string) $_ENV[ $key ];
			}

			// 4. $_SERVER superglobal (Dotenv / Web server)
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return (string) $_SERVER[ $key ];
			}

			// 5. Native getenv()
			$val = getenv( $key );
			if ( false !== $val && '' !== $val ) {
				return (string) $val;
			}
		}

		return null;
	}

	private function getToken(): ?string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! empty( $stored ) ) {
			$decrypted = Crypto::decrypt( (string) $stored );
			if ( '' !== $decrypted ) {
				return $decrypted;
			}
		}

		return self::getEnvironmentToken();
	}

	// ── Token status ────────────────────────────────────────────────────

	public static function hasToken(): bool {
		return 'none' !== self::tokenSource();
	}

	/**
	 * Token source for status reporting.
	 *
	 * @return 'db'|'constant'|'none'
	 */
	public static function tokenSource(): string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! empty( $stored ) && '' !== Crypto::decrypt( (string) $stored ) ) {
			return 'db';
		}

		if ( null !== self::getEnvironmentToken() ) {
			return 'constant';
		}

		return 'none';
	}
}
