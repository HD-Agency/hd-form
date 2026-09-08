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
	public const COOLDOWN_SECONDS    = 900; // 15 minutes
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

			// Fail-safe package integrity guard: abort before clear_destination() if package is incomplete.
			add_filter( 'upgrader_source_selection', [ $this, 'validatePackageIntegrity' ], 25, 4 );

			// Client-side live push updater on plugins.php.
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueuePluginsScript' ] );

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

		// 3. Allow manual "Check Again" on Dashboard → Updates or after bulk upgrades.
		if ( doing_action( 'load-update-core.php' ) || doing_action( 'upgrader_process_complete' ) ) {
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
	 * REST handler for checking updates in background.
	 */
	public function handleRestCheck( WP_REST_Request $request ): WP_REST_Response {
		$force       = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$hasCooldown = (bool) get_transient( self::COOLDOWN_TRANSIENT );

		if ( ( ! $hasCooldown || $force ) && $this->checker ) {
			set_transient( self::COOLDOWN_TRANSIENT, 1, self::COOLDOWN_SECONDS );
			$this->checker->checkForUpdates();
		}

		$update         = $this->checker ? $this->checker->getUpdate() : null;
		$currentVersion = defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '1.1.2';
		$hasUpdate      = ( null !== $update && ! empty( $update->version ) && version_compare( (string) $update->version, $currentVersion, '>' ) );

		if ( $hasUpdate ) {
			$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
			return new WP_REST_Response(
				[
					'has_update'      => true,
					'current_version' => $currentVersion,
					'new_version'     => (string) $update->version,
					'update_url'      => wp_nonce_url(
						self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $pluginBasename ) ),
						'upgrade-plugin_' . $pluginBasename
					),
					'details_url'     => self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=hd-form&section=changelog&TB_iframe=true&width=600&height=800' ),
					'row_html'        => $this->buildUpdateRowHtml( (string) $update->version ),
				],
				200
			);
		}

		return new WP_REST_Response(
			[
				'has_update'      => false,
				'current_version' => $currentVersion,
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
	 * Enqueue lightweight Live Push script on wp-admin/plugins.php.
	 */
	public function enqueuePluginsScript( string $hook ): void {
		if ( 'plugins.php' !== $hook || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$namespace = defined( 'HD_FORM_REST_NAMESPACE' ) ? HD_FORM_REST_NAMESPACE : 'hd/v1';

		$config = [
			'endpoint'    => esc_url_raw( rest_url( $namespace . '/updater/check' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'hasCooldown' => (bool) get_transient( self::COOLDOWN_TRANSIENT ),
		];

		wp_register_script( 'hdf-updater-live', '', [], defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '1.1.2', [ 'in_footer' => true ] );
		wp_enqueue_script( 'hdf-updater-live' );
		wp_add_inline_script(
			'hdf-updater-live',
			sprintf(
				'window.hdfUpdaterLiveConfig = %s;
(function() {
	if (document.getElementById("hdf-update")) {
		return;
	}
	var config = window.hdfUpdaterLiveConfig;
	if (!config || config.hasCooldown) {
		return;
	}
	function checkLiveUpdate() {
		fetch(config.endpoint, {
			headers: { "X-WP-Nonce": config.nonce }
		})
		.then(function(res) { return res.ok ? res.json() : null; })
		.then(function(data) {
			if (!data || !data.has_update || !data.row_html) {
				return;
			}
			if (document.getElementById("hdf-update")) {
				return;
			}
			var targetRow = document.querySelector("tr[data-plugin=\'hd-form/hd-form.php\']") || document.getElementById("hd-form");
			if (!targetRow) {
				return;
			}
			targetRow.insertAdjacentHTML("afterend", data.row_html);
			targetRow.classList.add("update");

			// Update admin menu badge count.
			var menuBadges = document.querySelectorAll("#menu-plugins .plugin-count, #wp-admin-bar-updates .ab-label");
			if (menuBadges.length > 0) {
				menuBadges.forEach(function(el) {
					var count = parseInt(el.textContent, 10) || 0;
					el.textContent = String(count + 1);
				});
			} else {
				var menuLink = document.querySelector("#menu-plugins a.wp-has-submenu[href=\'plugins.php\'], #menu-plugins a[href=\'plugins.php\']");
				if (menuLink && !menuLink.querySelector(".update-plugins")) {
					menuLink.insertAdjacentHTML("beforeend", \'<span class="update-plugins count-1"><span class="plugin-count">1</span></span>\');
				}
			}
		})
		.catch(function() {});
	}
	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", checkLiveUpdate);
	} else {
		setTimeout(checkLiveUpdate, 400);
	}
})();',
				wp_json_encode( $config )
			)
		);
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

		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';

		// Identify whether HD Form is the plugin being upgraded.
		$isHdf = false;
		if ( is_array( $hookExtra ) && isset( $hookExtra['plugin'] ) && $pluginBasename === $hookExtra['plugin'] ) {
			$isHdf = true;
		} elseif ( isset( $upgrader->skin->plugin ) && $pluginBasename === $upgrader->skin->plugin ) {
			$isHdf = true;
		} elseif ( isset( $this->checker ) && $this->checker->isBeingUpgraded( $upgrader ) ) {
			$isHdf = true;
		} elseif ( 'hd-form' === basename( rtrim( $source, '/\\' ) ) ) {
			$isHdf = true;
		}

		if ( ! $isHdf ) {
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

	// ── Token resolution ────────────────────────────────────────────────

	private function getToken(): ?string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! empty( $stored ) ) {
			$decrypted = Crypto::decrypt( (string) $stored );
			if ( '' !== $decrypted ) {
				return $decrypted;
			}
		}

		if ( defined( 'HDF_GITHUB_TOKEN' ) && \HDF_GITHUB_TOKEN ) {
			return (string) \HDF_GITHUB_TOKEN;
		}

		$envToken = getenv( 'HDF_GITHUB_TOKEN' );
		if ( ! empty( $envToken ) ) {
			return (string) $envToken;
		}

		return null;
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

		if ( defined( 'HDF_GITHUB_TOKEN' ) && \HDF_GITHUB_TOKEN ) {
			return 'constant';
		}

		$envToken = getenv( 'HDF_GITHUB_TOKEN' );
		if ( ! empty( $envToken ) ) {
			return 'constant';
		}

		return 'none';
	}
}
