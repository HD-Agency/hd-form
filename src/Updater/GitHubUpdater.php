<?php
/**
 * GitHub-based plugin auto-updater via plugin-update-checker (PUC).
 *
 * Repository URL and tracked branch are intentionally fixed to this plugin's
 * own repository (`HD-Agency/hd-form`, branch `main`).
 *
 * @package HDForm\Updater
 */

declare(strict_types=1);

namespace HDForm\Updater;

use HDForm\Support\Crypto;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker;

defined( 'ABSPATH' ) || exit;

final class GitHubUpdater {

	/** @internal Intentionally fixed — not a user-configurable setting. */
	private const REPO_URL              = 'https://github.com/HD-Agency/hd-form';
	public const TOKEN_OPTION           = '_hdf_github_token';
	public const COOLDOWN_TRANSIENT     = '_hdf_github_update_cooldown';
	public const COOLDOWN_SECONDS       = 300;
	private const CHECK_PERIOD_HOURS    = 2;
	private const HTTP_TIMEOUT_WEB      = 5.0;
	private const HTTP_TIMEOUT_CRON     = 10.0;
	private const MAX_DOWNLOAD_ATTEMPTS = 3;

	private static ?self $instance        = null;
	private ?PluginUpdateChecker $checker = null;
	private bool $routesRegistered        = false;
	private ?string $cachedToken          = null;
	private bool $tokenLoaded             = false;

	public static function init(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function instance(): ?self {
		return self::$instance;
	}

	public function __construct() {
		self::$instance = $this;
		$this->initUpdateChecker();
	}

	public function getChecker(): ?PluginUpdateChecker {
		return $this->checker;
	}

	public function resetTokenCache(): void {
		$this->tokenLoaded = false;
		$this->cachedToken = null;
	}

	public function resetCachedUpdateState(): void {
		if ( null !== $this->checker ) {
			$this->checker->resetUpdateState();
		}
		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
		$currentUpdates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $currentUpdates ) ) {
			return;
		}
		$modified = false;
		if ( isset( $currentUpdates->response ) && is_array( $currentUpdates->response ) && isset( $currentUpdates->response[ $pluginBasename ] ) ) {
			unset( $currentUpdates->response[ $pluginBasename ] );
			$modified = true;
		}
		if ( isset( $currentUpdates->no_update ) && is_array( $currentUpdates->no_update ) && isset( $currentUpdates->no_update[ $pluginBasename ] ) ) {
			unset( $currentUpdates->no_update[ $pluginBasename ] );
			$modified = true;
		}
		if ( $modified ) {
			set_site_transient( 'update_plugins', $currentUpdates );
		}
	}

	private function initUpdateChecker(): void {
		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}
		try {
			add_filter( 'puc_request_info_options-hd-form', [ $this, 'filterHttpRequestOptions' ] );
			add_filter( 'puc_check_now-hd-form', [ $this, 'shouldCheckNow' ], 10, 2 );
			add_filter( 'site_transient_update_plugins', [ $this, 'injectUpdateFromPucState' ], 20 );
			add_action( 'requests-requests.before_redirect', [ $this, 'filterRequestsRedirect' ], 10, 2 );
			add_filter( 'http_request_host_is_external', [ $this, 'filterExternalHosts' ], 10, 2 );
			add_filter( 'http_request_args', [ $this, 'filterTrustedHttpRequestArgs' ], 9999, 2 );
			add_filter( 'upgrader_pre_download', [ $this, 'validateDownloadArchive' ], 20, 4 );
			add_filter( 'upgrader_source_selection', [ $this, 'normalizeSourceDirectory' ], 5, 4 );
			add_filter( 'upgrader_source_selection', [ $this, 'validatePackageIntegrity' ], 25, 4 );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueuePluginsScript' ] );
			add_filter( 'puc_vcs_update_detection_strategies-hd-form', [ $this, 'filterUpdateDetectionStrategies' ] );
			add_filter( 'puc_manual_check_message-hd-form', [ $this, 'filterManualCheckMessage' ], 10, 2 );
			add_filter( 'puc_manual_check_link-hd-form', [ $this, 'filterManualCheckLink' ] );

			if ( did_action( 'rest_api_init' ) ) {
				$this->registerRestRoutes();
			} else {
				add_action( 'rest_api_init', [ $this, 'registerRestRoutes' ] );
			}

			$pluginFile = defined( 'HD_FORM_PATH' ) ? HD_FORM_PATH . 'hd-form.php' : dirname( __DIR__, 2 ) . '/hd-form.php';

			/** @var PluginUpdateChecker $checker */
			$checker = PucFactory::buildUpdateChecker( self::REPO_URL, $pluginFile, 'hd-form', self::CHECK_PERIOD_HOURS );
			$checker->setBranch( 'main' );
			$api = $checker->getVcsApi();
			if ( is_object( $api ) && method_exists( $api, 'addDownloadRequestFilters' ) ) {
				remove_filter( 'upgrader_pre_download', [ $api, 'addDownloadRequestFilters' ] );
			}
			$this->checker = $checker;
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			defined( 'WP_DEBUG' ) && WP_DEBUG && error_log( sprintf( '[HDF Updater] Failed to initialize update checker: %s', $e->getMessage() ) );
		}
	}

	public function filterHttpRequestOptions( mixed $options ): mixed {
		if ( ! is_array( $options ) ) {
			return $options;
		}
		$token = $this->getToken();
		if ( null !== $token && '' !== $token ) {
			$options['headers']                  = is_array( $options['headers'] ?? null ) ? $options['headers'] : [];
			$options['headers']['Authorization'] = 'Bearer ' . $token;
		}
		$isCron             = ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron();
		$options['timeout'] = $isCron ? self::HTTP_TIMEOUT_CRON : self::HTTP_TIMEOUT_WEB;
		return $options;
	}

	public function filterUpdateDetectionStrategies( mixed $strategies ): mixed {
		if ( is_array( $strategies ) ) {
			unset( $strategies['latest_release'], $strategies['latest_tag'] );
		}
		return $strategies;
	}

	public function shouldCheckNow( mixed $shouldCheck = false, mixed $lastCheck = 0 ): bool {
		if ( ! self::hasToken() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$isManualCheck = ! empty( $_GET['puc_check_for_updates'] ) && ( ( $_GET['puc_slug'] ?? '' ) === 'hd-form' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$isForceCheck = doing_action( 'load-update-core.php' ) && ! empty( $_GET['force-check'] );
		if ( $isManualCheck || $isForceCheck ) {
			delete_transient( self::COOLDOWN_TRANSIENT );
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action             = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$isUpgradeExecution = in_array( $action, [ 'update-plugin', 'upgrade-plugin', 'update-selected', 'do-plugin-upgrade' ], true ) || doing_action( 'load-update.php' );
		if ( $isUpgradeExecution ) {
			return ! $this->hasValidCachedUpdate();
		}
		if ( doing_action( 'upgrader_process_complete' ) || ! (bool) $shouldCheck ) {
			return false;
		}
		$isCron = ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron();
		return $isCron || ( (int) $lastCheck <= 0 && ! $this->hasValidCachedUpdate() );
	}

	private function hasValidCachedUpdate(): bool {
		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
		$currentVersion = defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '1.1.7';
		$currentUpdates = get_site_transient( 'update_plugins' );
		if ( is_object( $currentUpdates ) && ! empty( $currentUpdates->response[ $pluginBasename ] ) && is_object( $currentUpdates->response[ $pluginBasename ] ) ) {
			$item = $currentUpdates->response[ $pluginBasename ];
			if ( ! empty( $item->new_version ) && ! empty( $item->package ) && version_compare( (string) $item->new_version, $currentVersion, '>' ) ) {
				return true;
			}
		}
		$update = $this->checker?->getUpdate();
		return is_object( $update ) && ! empty( $update->version ) && ! empty( $update->downloadUrl ) && version_compare( (string) $update->version, $currentVersion, '>' );
	}

	public function injectUpdateFromPucState( mixed $updates ): mixed {
		if ( ! is_object( $updates ) ) {
			return $updates;
		}
		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
		if ( ! self::hasToken() ) {
			if ( isset( $updates->response ) && is_array( $updates->response ) ) {
				unset( $updates->response[ $pluginBasename ] );
			}
			if ( isset( $updates->no_update ) && is_array( $updates->no_update ) ) {
				unset( $updates->no_update[ $pluginBasename ] );
			}
			return $updates;
		}
		if ( ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
			$updates->response = [];
		}
		$currentVersion = defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '1.1.7';
		$existing       = $updates->response[ $pluginBasename ] ?? null;
		if ( is_object( $existing ) && ! empty( $existing->new_version ) && ! empty( $existing->package ) && version_compare( (string) $existing->new_version, $currentVersion, '>' ) ) {
			return $updates;
		}
		$pucUpdate = $this->checker?->getUpdate();
		if ( is_object( $pucUpdate ) && ! empty( $pucUpdate->version ) && ! empty( $pucUpdate->downloadUrl ) && version_compare( (string) $pucUpdate->version, $currentVersion, '>' ) ) {
			$wpUpdate = method_exists( $pucUpdate, 'toWpFormat' ) ? $pucUpdate->toWpFormat() : null;
			if ( is_object( $wpUpdate ) ) {
				$wpUpdate->plugin                     = $pluginBasename;
				$updates->response[ $pluginBasename ] = $wpUpdate;
				if ( isset( $updates->no_update ) && is_array( $updates->no_update ) ) {
					unset( $updates->no_update[ $pluginBasename ] );
				}
			}
		}
		return $updates;
	}

	public function filterManualCheckLink( mixed $link ): string {
		return is_string( $link ) && self::hasToken() ? $link : '';
	}

	public function filterManualCheckMessage( mixed $message, mixed $status = '' ): mixed {
		if ( 'error' === $status && ! self::hasToken() ) {
			return sprintf(
				/* translators: %s: settings page URL */
				__( 'GitHub Access Token is not configured. Please visit <a href="%s">HD Form Settings</a> to configure a token before checking for updates.', 'hd-form' ),
				esc_url( admin_url( 'admin.php?page=hd-form-settings#update' ) )
			);
		}
		return $message;
	}

	public function registerRestRoutes(): void {
		if ( $this->routesRegistered ) {
			return;
		}
		$this->routesRegistered = true;
		$namespace              = defined( 'HD_FORM_REST_NAMESPACE' ) ? HD_FORM_REST_NAMESPACE : 'hd/v1';

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

	public function checkRestPermission( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return false;
		}
		$nonce = $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' );
		return is_string( $nonce ) && '' !== $nonce && (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	public function getCooldownRemaining(): int {
		$expireAt = get_transient( self::COOLDOWN_TRANSIENT );
		if ( ! $expireAt ) {
			return 0;
		}
		if ( is_numeric( $expireAt ) && (int) $expireAt > 1 ) {
			return max( 0, (int) $expireAt - (int) time() );
		}
		$timeout = (int) get_option( '_transient_timeout_' . self::COOLDOWN_TRANSIENT );
		return $timeout <= 0 ? (int) self::COOLDOWN_SECONDS : max( 0, $timeout - (int) time() );
	}

	public function handleRestCheck( WP_REST_Request $request ): WP_REST_Response {
		$currentVersion = defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '1.1.7';
		if ( ! self::hasToken() ) {
			$this->resetCachedUpdateState();
			return new WP_REST_Response(
				[
					'has_update'           => false,
					'auto_update_disabled' => true,
					'current_version'      => $currentVersion,
					'cooldown_remaining'   => 0,
				],
				200
			);
		}

		$force          = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$canCheck       = ! get_transient( self::COOLDOWN_TRANSIENT ) || ( $force && ! get_transient( 'hdf_updater_force_throttle' ) );
		$checker        = $this->checker;
		$previousUpdate = $checker ? $checker->getUpdate() : null;
		$update         = $previousUpdate;

		if ( $canCheck && $checker ) {
			set_transient( self::COOLDOWN_TRANSIENT, (int) time() + self::COOLDOWN_SECONDS, self::COOLDOWN_SECONDS );
			if ( $force ) {
				set_transient( 'hdf_updater_force_throttle', 1, 30 );
			}
			$update = $checker->checkForUpdates() ?? $previousUpdate;
		}

		$hasUpdate         = ( null !== $update && ! empty( $update->version ) && version_compare( (string) $update->version, $currentVersion, '>' ) );
		$cooldownRemaining = $this->getCooldownRemaining();

		if ( $hasUpdate ) {
			$newVersion     = sanitize_text_field( (string) $update->version );
			$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
			$wpUpdate       = method_exists( $update, 'toWpFormat' ) ? $update->toWpFormat() : null;
			if ( is_object( $wpUpdate ) ) {
				$wpUpdate->plugin = $pluginBasename;
				$currentUpdates   = get_site_transient( 'update_plugins' );
				if ( ! is_object( $currentUpdates ) ) {
					$currentUpdates           = new \stdClass();
					$currentUpdates->response = [];
				}
				$currentUpdates->response[ $pluginBasename ] = $wpUpdate;
				if ( isset( $currentUpdates->no_update ) && is_array( $currentUpdates->no_update ) ) {
					unset( $currentUpdates->no_update[ $pluginBasename ] );
				}
				set_site_transient( 'update_plugins', $currentUpdates );
			}
			return new WP_REST_Response(
				[
					'has_update'         => true,
					'current_version'    => $currentVersion,
					'new_version'        => $newVersion,
					'cooldown_remaining' => $cooldownRemaining,
					'row_html'           => $this->buildUpdateRowHtml( $newVersion ),
				],
				200
			);
		}

		return new WP_REST_Response(
			[
				'has_update'         => false,
				'current_version'    => $currentVersion,
				'cooldown_remaining' => $cooldownRemaining,
			],
			200
		);
	}

	public function buildUpdateRowHtml( string $newVersion ): string {
		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
		$updateUrl      = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $pluginBasename ) ), 'upgrade-plugin_' . $pluginBasename );
		$detailsUrl     = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=hd-form&section=changelog&TB_iframe=true&width=600&height=800' );
		$noticeText     = sprintf(
			/* translators: 1: Plugin name, 2: Details link, 3: Details link aria label, 4: Version, 5: Update link, 6: Update link aria label */
			__( 'There is a new version of %1$s available. <a href="%2$s" class="thickbox open-plugin-details-modal" aria-label="%3$s">View version %4$s details</a> or <a href="%5$s" class="update-link" aria-label="%6$s">update now</a>.', 'hd-form' ),
			'HD Form',
			esc_url( $detailsUrl ),
			esc_attr( sprintf( __( 'View HD Form version %s details', 'hd-form' ), $newVersion ) ),
			esc_html( $newVersion ),
			esc_url( $updateUrl ),
			esc_attr( __( 'Update HD Form now', 'hd-form' ) )
		);
		return sprintf(
			'<tr class="plugin-update-tr active" id="hdf-update" data-slug="hd-form" data-plugin="%1$s"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>%2$s</p></div></td></tr>',
			esc_attr( $pluginBasename ),
			$noticeText
		);
	}

	public function enqueuePluginsScript( mixed $hook = '' ): void {
		if ( ! is_string( $hook ) || ! in_array( $hook, [ 'plugins.php', 'update-core.php' ], true ) || ! current_user_can( 'update_plugins' ) || ! self::hasToken() ) {
			return;
		}
		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
		$currentUpdates = get_site_transient( 'update_plugins' );
		$currentVersion = defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '1.1.7';
		if ( is_object( $currentUpdates ) && ! empty( $currentUpdates->response[ $pluginBasename ]->new_version ) && version_compare( (string) $currentUpdates->response[ $pluginBasename ]->new_version, $currentVersion, '>' ) ) {
			return;
		}
		$namespace = defined( 'HD_FORM_REST_NAMESPACE' ) ? HD_FORM_REST_NAMESPACE : 'hd/v1';
		$config    = [
			'endpoint'          => esc_url_raw( rest_url( $namespace . '/updater/check' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'cooldownRemaining' => $this->getCooldownRemaining(),
			'cooldownSeconds'   => (int) self::COOLDOWN_SECONDS,
		];
		wp_register_script( 'hdf-updater-live', '', [], $currentVersion, [ 'in_footer' => true ] );
		wp_enqueue_script( 'hdf-updater-live' );
		wp_add_inline_script(
			'hdf-updater-live',
			sprintf(
				'window.hdfUpdaterLiveConfig=%s;(function(){'
				. 'function hasUpdateNotice(){if(document.getElementById("hdf-update")||document.getElementById("hdf-update-core-notice")||document.querySelector("tr.plugin-update-tr[data-plugin=\"%s\"]"))return true;'
				. 'var targetRow=document.querySelector("tr[data-plugin=\"%s\"]"),next=targetRow?targetRow.nextElementSibling:null;return Boolean(next&&(next.classList.contains("plugin-update-tr")||next.querySelector(".update-message")));}'
				. 'if(hasUpdateNotice())return;var config=window.hdfUpdaterLiveConfig;if(!config||!config.endpoint)return;'
				. 'var pollTimer=null,isChecking=false,storageKey="hdf_updater_last_check";'
				. 'function scheduleNext(delayMs){if(pollTimer){clearTimeout(pollTimer);pollTimer=null;}if(!hasUpdateNotice())pollTimer=setTimeout(executeCheck,Math.max(100,delayMs));}'
				. 'function executeCheck(){if(hasUpdateNotice()||document.hidden||isChecking)return;var minGap=(config.cooldownSeconds||300)*1000;'
				. 'try{var lastCheck=parseInt(localStorage.getItem(storageKey),10)||0,now=Date.now();if(lastCheck>0&&(now-lastCheck)<(minGap-500)){scheduleNext(minGap-(now-lastCheck));return;}localStorage.setItem(storageKey,String(now));}catch(e){}isChecking=true;'
				. 'fetch(config.endpoint,{headers:{"X-WP-Nonce":config.nonce},credentials:"same-origin"}).then(function(res){return res.ok?res.json():null;}).then(function(data){isChecking=false;if(!data){scheduleNext(minGap);return;}'
				. 'if(data.auto_update_disabled){if(pollTimer){clearTimeout(pollTimer);pollTimer=null;}try{localStorage.removeItem(storageKey);}catch(e){}return;}'
				. 'if(data.has_update){if(hasUpdateNotice())return;var targetRow=document.querySelector("tr[data-plugin=\"%s\"]")||document.getElementById("hd-form");'
				. 'if(targetRow&&data.row_html){var existing=document.querySelector("tr.plugin-update-tr[data-plugin=\"%s\"]")||document.getElementById("hdf-update");if(existing)existing.remove();targetRow.insertAdjacentHTML("afterend",data.row_html);'
				. 'var alreadyMarked=targetRow.classList.contains("update");targetRow.classList.add("update");if(!alreadyMarked){var menuBadges=document.querySelectorAll("#menu-plugins .plugin-count, #wp-admin-bar-updates .ab-label, #menu-dashboard .update-count");'
				. 'if(menuBadges.length>0)menuBadges.forEach(function(el){el.textContent=String((parseInt(el.textContent,10)||0)+1);});else{var menuLink=document.querySelector("#menu-plugins a.wp-has-submenu[href=\"plugins.php\"], #menu-plugins a[href=\"plugins.php\"]");if(menuLink&&!menuLink.querySelector(".update-plugins"))menuLink.insertAdjacentHTML("beforeend",\'<span class="update-plugins count-1"><span class="plugin-count">1</span></span>\');}}}'
				. 'else{var wrap=document.querySelector(".wrap");if(wrap&&!document.getElementById("hdf-update-core-notice")){var notice=document.createElement("div"),ver=String(data.new_version||"").replace(/[^0-9a-zA-Z.-]/g,""),h1=wrap.querySelector("h1");notice.id="hdf-update-core-notice";notice.className="notice notice-warning is-dismissible";notice.innerHTML="<p><strong>HD Form:</strong> A new version <strong>"+ver+"</strong> is available. <a href=\""+window.location.href+"\">Reload page</a> to update.</p>";if(h1&&h1.nextSibling)wrap.insertBefore(notice,h1.nextSibling);else wrap.prepend(notice);}}'
				. 'if(pollTimer){clearTimeout(pollTimer);pollTimer=null;}return;}scheduleNext((typeof data.cooldown_remaining==="number"&&data.cooldown_remaining>0)?data.cooldown_remaining*1000:minGap);}).catch(function(){isChecking=false;scheduleNext(minGap*2);});}'
				. 'document.addEventListener("visibilitychange",function(){if(document.hidden){if(pollTimer){clearTimeout(pollTimer);pollTimer=null;}}else if(!hasUpdateNotice()){var minGap=(config.cooldownSeconds||300)*1000,lastCheck=0;try{lastCheck=parseInt(localStorage.getItem(storageKey),10)||0;}catch(e){}var elapsed=Date.now()-lastCheck;scheduleNext(elapsed>=minGap?100:minGap-elapsed);}});'
				. 'scheduleNext((typeof config.cooldownRemaining==="number"&&config.cooldownRemaining>0)?config.cooldownRemaining*1000:500);})();',
				wp_json_encode( $config ),
				esc_js( $pluginBasename ),
				esc_js( $pluginBasename ),
				esc_js( $pluginBasename ),
				esc_js( $pluginBasename )
			)
		);
	}

	public function normalizeSourceDirectory( mixed $source, mixed $remoteSource, mixed $upgrader, mixed $hookExtra = null ): string|WP_Error {
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error( 'hdf_invalid_source', __( 'Invalid update source path.', 'hd-form' ) );
		}
		if ( ! is_string( $remoteSource ) || '' === $remoteSource || ! $this->isHdfUpgrade( $source, $upgrader, $hookExtra ) ) {
			return $source;
		}
		$correctedSource = trailingslashit( $remoteSource ) . 'hd-form/';
		if ( wp_normalize_path( trailingslashit( $source ) ) === wp_normalize_path( $correctedSource ) ) {
			return $source;
		}
		if ( ! function_exists( 'move_dir' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$result = move_dir( $source, $correctedSource, true );
		return is_wp_error( $result ) ? $result : $correctedSource;
	}

	public function validatePackageIntegrity( mixed $source, mixed $remoteSource, mixed $upgrader, mixed $hookExtra = null ): string|WP_Error {
		unset( $remoteSource );
		global $wp_filesystem;
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error( 'hdf_invalid_source', __( 'Invalid update source path.', 'hd-form' ) );
		}
		if ( ! isset( $upgrader, $wp_filesystem ) || ! is_object( $wp_filesystem ) || ! $this->isHdfUpgrade( $source, $upgrader, $hookExtra ) ) {
			return $source;
		}
		$sourceDir = trailingslashit( $source );
		$missing   = array_filter(
			[
				! $wp_filesystem->exists( $sourceDir . 'hd-form.php' ) ? 'hd-form.php' : null,
				! $wp_filesystem->exists( $sourceDir . 'src/Plugin.php' ) ? 'src/Plugin.php' : null,
				! $wp_filesystem->exists( $sourceDir . 'vendor/autoload.php' ) ? 'vendor/autoload.php' : null,
			]
		);
		if ( ! empty( $missing ) ) {
			return new WP_Error( 'hdf_corrupted_package', sprintf( __( 'HD Form update aborted: The downloaded package is incomplete (missing: %s). The existing plugin installation was preserved.', 'hd-form' ), implode( ', ', $missing ) ) );
		}
		return $source;
	}

	private function isHdfUpgrade( string $source, mixed $upgrader, mixed $hookExtra ): bool {
		$pluginBasename = defined( 'HD_FORM_PLUGIN_BASENAME' ) ? HD_FORM_PLUGIN_BASENAME : 'hd-form/hd-form.php';
		if ( is_array( $hookExtra ) ) {
			if ( ! empty( $hookExtra['theme'] ) ) {
				return false;
			}
			if ( ! empty( $hookExtra['plugin'] ) && is_string( $hookExtra['plugin'] ) ) {
				return $hookExtra['plugin'] === $pluginBasename;
			}
		}
		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && isset( $upgrader->skin->plugin ) && is_string( $upgrader->skin->plugin ) && '' !== $upgrader->skin->plugin ) {
			return $upgrader->skin->plugin === $pluginBasename;
		}
		if ( isset( $this->checker ) && $this->checker->isBeingUpgraded( $upgrader ) ) {
			return true;
		}
		$base = strtolower( basename( rtrim( $source, '/\\' ) ) );
		if ( 'hd-form' === $base || str_starts_with( $base, 'hd-agency-hd-form-' ) ) {
			return true;
		}
		$repoPath = strtolower( $this->getRepoPath() );
		return '' !== $repoPath && str_contains( strtolower( wp_normalize_path( $source ) ), $repoPath . '/' );
	}

	private function getRepoPath(): string {
		return trim( (string) wp_parse_url( self::REPO_URL, PHP_URL_PATH ), '/' );
	}

	public function validateDownloadArchive( mixed $reply = false, mixed $package = '', mixed $upgrader = null, mixed $hookExtra = [] ): mixed {
		if ( is_wp_error( $reply ) || ! is_string( $package ) || '' === $package || ! $this->isHdfUpgrade( $package, $upgrader, (array) $hookExtra ) ) {
			return $reply;
		}
		if ( ! self::hasToken() ) {
			return new WP_Error( 'hdf_missing_token', __( 'HD Form update aborted: GitHub authentication token is missing or empty.', 'hd-form' ) );
		}
		$this->cleanStaleTempArchives();
		if ( is_string( $reply ) && '' !== $reply && file_exists( $reply ) ) {
			$validation = $this->verifyZipMagicBytes( $reply );
			if ( is_wp_error( $validation ) ) {
				$tempDir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
				if ( str_starts_with( wp_normalize_path( $reply ), wp_normalize_path( $tempDir ) ) && is_file( $reply ) ) {
					wp_delete_file( $reply );
				}
				return $validation;
			}
			return $reply;
		}
		if ( ! preg_match( '!^(http|https|ftp)://!i', $package ) && file_exists( $package ) ) {
			$validation = $this->verifyZipMagicBytes( $package );
			return is_wp_error( $validation ) ? $validation : $package;
		}
		if ( ! $this->isTrustedPackageUrl( $package ) ) {
			return new WP_Error( 'hdf_untrusted_package_url', __( 'HD Form update aborted: Untrusted package download URL.', 'hd-form' ) );
		}
		return $this->downloadArchiveToTempFile( $package );
	}

	private function downloadArchiveToTempFile( string $packageUrl ): string|WP_Error {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$token     = (string) $this->getToken();
		$userAgent = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url();
		$lastError = null;

		for ( $attempt = 1; $attempt <= self::MAX_DOWNLOAD_ATTEMPTS; $attempt++ ) {
			$downloadUrl = $packageUrl;
			$parsedPkg   = wp_parse_url( $packageUrl );
			$pkgHost     = strtolower( (string) ( $parsedPkg['host'] ?? '' ) );
			$pkgPath     = strtolower( (string) ( $parsedPkg['path'] ?? '' ) );

			// Step 1: Pre-resolve api.github.com 302 redirect per attempt without opening a file stream.
			if ( 'api.github.com' === $pkgHost ) {
				$probeAccept  = str_contains( $pkgPath, '/releases/assets/' )
					? 'application/octet-stream'
					: 'application/vnd.github+json';
				$probeHeaders = [
					'Accept'          => $probeAccept,
					'Accept-Encoding' => 'identity',
					'User-Agent'      => $userAgent,
				];
				if ( '' !== $token ) {
					$probeHeaders['Authorization'] = 'Bearer ' . $token;
				}

				$probe = wp_remote_get(
					$packageUrl,
					[
						'timeout'            => 15,
						'redirection'        => 0,
						'stream'             => false,
						'reject_unsafe_urls' => false,
						'headers'            => $probeHeaders,
					]
				);

				if ( ! is_wp_error( $probe ) ) {
					$probeCode = (int) wp_remote_retrieve_response_code( $probe );
					if ( in_array( $probeCode, [ 401, 403, 404 ], true ) ) {
						return new WP_Error(
							'hdf_download_http_error',
							sprintf(
								/* translators: 1: HTTP status code, 2: HTTP status message */
								__( 'HD Form update aborted: GitHub returned HTTP %1$d (%2$s).', 'hd-form' ),
								$probeCode,
								wp_remote_retrieve_response_message( $probe )
							)
						);
					}

					$location = (string) wp_remote_retrieve_header( $probe, 'location' );
					if ( in_array( $probeCode, [ 301, 302, 303, 307, 308 ], true ) && '' !== $location ) {
						if ( ! $this->isTrustedPackageUrl( $location ) ) {
							return new WP_Error(
								'hdf_untrusted_package_url',
								__( 'HD Form update aborted: Untrusted redirect package URL.', 'hd-form' )
							);
						}
						$downloadUrl = $location;
					}
				}
			}

			// Step 2: Stream from resolved URL into a fresh temp file.
			$tmpFile = wp_tempnam( 'hd-form-package.zip' );
			if ( ! $tmpFile ) {
				return new WP_Error( 'hdf_temp_failed', __( 'HD Form update aborted: Could not create temporary file for package download.', 'hd-form' ) );
			}

			$parsedDl   = wp_parse_url( $downloadUrl );
			$dlHost     = strtolower( (string) ( $parsedDl['host'] ?? '' ) );
			$dlPath     = strtolower( (string) ( $parsedDl['path'] ?? '' ) );
			$dlQuery    = (string) ( $parsedDl['query'] ?? '' );
			$isGitHub   = in_array( $dlHost, [ 'api.github.com', 'codeload.github.com', 'github.com' ], true );
			$hasSignedQ = str_starts_with( $dlQuery, 'token=' )
				|| str_contains( $dlQuery, '&token=' )
				|| str_contains( $dlQuery, 'AWSAccessKeyId=' )
				|| str_contains( $dlQuery, 'X-Amz-Signature=' );

			$dlAccept        = str_contains( $dlPath, '/releases/assets/' )
				? 'application/octet-stream'
				: 'application/zip, application/octet-stream, */*';
			$downloadHeaders = [
				'Accept'          => $dlAccept,
				'Accept-Encoding' => 'identity',
				'User-Agent'      => $userAgent,
			];
			if ( '' !== $token && $isGitHub && ! $hasSignedQ ) {
				$downloadHeaders['Authorization'] = 'Bearer ' . $token;
			}

			$response = wp_remote_get(
				$downloadUrl,
				[
					'timeout'            => 300,
					'redirection'        => 5,
					'stream'             => true,
					'filename'           => $tmpFile,
					'reject_unsafe_urls' => false,
					'headers'            => $downloadHeaders,
				]
			);

			if ( is_wp_error( $response ) ) {
				is_file( $tmpFile ) && wp_delete_file( $tmpFile );
				$lastError = $response;
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				is_file( $tmpFile ) && wp_delete_file( $tmpFile );
				$lastError = new WP_Error( 'hdf_download_http_error', sprintf( __( 'HD Form update aborted: GitHub returned HTTP %1$d (%2$s).', 'hd-form' ), $code, wp_remote_retrieve_response_message( $response ) ) );
				if ( $code >= 400 && $code < 500 && 429 !== $code ) {
					break;
				}
				continue;
			}

			$contentType = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
			if ( str_starts_with( $contentType, 'text/html' ) || str_starts_with( $contentType, 'application/json' ) ) {
				is_file( $tmpFile ) && wp_delete_file( $tmpFile );
				$lastError = new WP_Error(
					'hdf_invalid_archive_signature',
					sprintf(
						/* translators: %s: HTTP Content-Type header */
						__( 'HD Form update aborted: The downloaded package is not a valid ZIP archive (missing PK magic header) [Content-Type: %s].', 'hd-form' ),
						$contentType
					)
				);
				continue;
			}

			$check = $this->verifyZipMagicBytes( $tmpFile );
			if ( is_wp_error( $check ) ) {
				is_file( $tmpFile ) && wp_delete_file( $tmpFile );
				$lastError = $check;
				continue;
			}
			return $tmpFile;
		}
		return $lastError ?? new WP_Error( 'hdf_download_failed', __( 'HD Form update aborted: Failed to download update package.', 'hd-form' ) );
	}

	public function verifyZipMagicBytes( string $filePath ): bool|WP_Error {
		clearstatcache( true, $filePath );

		if ( ! is_file( $filePath ) ) {
			return new WP_Error( 'hdf_archive_missing', __( 'The update package file does not exist.', 'hd-form' ) );
		}

		$size = filesize( $filePath );
		if ( false === $size || $size < 10240 ) {
			return new WP_Error( 'hdf_archive_truncated', sprintf( __( 'HD Form update aborted: The downloaded package is truncated or invalid (%d bytes).', 'hd-form' ), (int) $size ) );
		}

		if ( ! is_readable( $filePath ) ) {
			return new WP_Error( 'hdf_archive_unreadable', __( 'HD Form update aborted: Package file is not readable for verification.', 'hd-form' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen( $filePath, 'rb' );
		if ( ! is_resource( $fp ) ) {
			return new WP_Error( 'hdf_archive_unreadable', __( 'HD Form update aborted: Package file is not readable for verification.', 'hd-form' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$prefix = fread( $fp, 1024 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		if ( is_string( $prefix ) && '' !== $prefix && ! str_starts_with( $prefix, "\x50\x4b\x03\x04" ) ) {
			if ( str_starts_with( $prefix, "\x1f\x8b\x08" ) && $this->tryDecompressGzipArchive( $filePath ) ) {
				clearstatcache( true, $filePath );
				$size = filesize( $filePath );
			} else {
				$pkOffset = strpos( $prefix, "\x50\x4b\x03\x04" );
				if ( false !== $pkOffset && $pkOffset > 0 && $this->tryStripLeadingBytes( $filePath, $pkOffset ) ) {
					clearstatcache( true, $filePath );
					$size = filesize( $filePath );
				}
			}

			if ( false === $size || $size < 10240 ) {
				return new WP_Error( 'hdf_archive_truncated', sprintf( __( 'HD Form update aborted: The downloaded package is truncated or invalid (%d bytes).', 'hd-form' ), (int) $size ) );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fp = fopen( $filePath, 'rb' );
		if ( ! is_resource( $fp ) ) {
			return new WP_Error( 'hdf_archive_unreadable', __( 'HD Form update aborted: Package file is not readable for verification.', 'hd-form' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$magic    = fread( $fp, 4 );
		$tailSize = (int) min( (int) $size, 65557 );
		$tailData = '';
		if ( 0 === fseek( $fp, -1 * $tailSize, SEEK_END ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$readTail = fread( $fp, $tailSize );
			$tailData = is_string( $readTail ) ? $readTail : '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );

		if ( "\x50\x4b\x03\x04" !== $magic ) {
			$trimmedPrefix = is_string( $prefix ) ? ltrim( $prefix ) : '';
			$detectedHint  = '';
			if ( str_starts_with( strtolower( $trimmedPrefix ), '<!doctype' ) || str_starts_with( strtolower( $trimmedPrefix ), '<html' ) ) {
				$detectedHint = ' [detected: HTML document]';
			} elseif ( str_starts_with( $trimmedPrefix, '{' ) ) {
				$detectedHint = ' [detected: JSON response]';
			}

			return new WP_Error(
				'hdf_invalid_archive_signature',
				__( 'HD Form update aborted: The downloaded package is not a valid ZIP archive (missing PK magic header).', 'hd-form' ) . $detectedHint
			);
		}

		$eocdPos = strrpos( $tailData, "\x50\x4b\x05\x06" );
		if ( false === $eocdPos || ( strlen( $tailData ) - $eocdPos ) < 22 ) {
			return new WP_Error( 'hdf_archive_truncated', __( 'HD Form update aborted: The downloaded ZIP archive is incomplete (missing End of Central Directory record).', 'hd-form' ) );
		}

		if ( class_exists( '\ZipArchive' ) ) {
			$zip  = new \ZipArchive();
			$open = $zip->open( $filePath, \ZipArchive::CHECKCONS );
			if ( true !== $open ) {
				return new WP_Error( 'hdf_invalid_archive_structure', sprintf( __( 'HD Form update aborted: ZIP consistency check failed (ZipArchive error code: %s).', 'hd-form' ), (string) $open ) );
			}
			$numFiles = $zip->numFiles;
			$zip->close();
			if ( $numFiles <= 0 ) {
				return new WP_Error( 'hdf_invalid_archive_structure', __( 'HD Form update aborted: The downloaded ZIP archive contains no files.', 'hd-form' ) );
			}
		}
		return true;
	}

	private function tryDecompressGzipArchive( string $filePath ): bool {
		if ( ! function_exists( 'gzopen' ) ) {
			return false;
		}

		$gz = gzopen( $filePath, 'rb' );
		if ( ! is_resource( $gz ) ) {
			return false;
		}

		$unpackedPath = $filePath . '.gunzip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $unpackedPath, 'wb' );
		if ( ! is_resource( $out ) ) {
			gzclose( $gz );
			return false;
		}

		$written = 0;
		while ( ! gzeof( $gz ) ) {
			$chunk = gzread( $gz, 65536 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$bytes = fwrite( $out, $chunk );
			if ( false === $bytes ) {
				break;
			}
			$written += $bytes;
		}

		gzclose( $gz );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );

		if ( $written < 10240 ) {
			wp_delete_file( $unpackedPath );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( rename( $unpackedPath, $filePath ) ) {
			return true;
		}

		wp_delete_file( $unpackedPath );
		return false;
	}

	private function tryStripLeadingBytes( string $filePath, int $offset ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$in = fopen( $filePath, 'rb' );
		if ( ! is_resource( $in ) ) {
			return false;
		}

		if ( 0 !== fseek( $in, $offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $in );
			return false;
		}

		$strippedPath = $filePath . '.strip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( $strippedPath, 'wb' );
		if ( ! is_resource( $out ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $in );
			return false;
		}

		$copied = stream_copy_to_stream( $in, $out );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $in );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );

		if ( false === $copied || $copied < 10240 ) {
			wp_delete_file( $strippedPath );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( rename( $strippedPath, $filePath ) ) {
			return true;
		}

		wp_delete_file( $strippedPath );
		return false;
	}

	public function filterRequestsRedirect( mixed &$location, mixed &$headers = [] ): void {
		if ( ! is_string( $location ) || ! is_array( $headers ) ) {
			return;
		}
		$parsedUrl     = wp_parse_url( $location );
		$host          = strtolower( (string) ( $parsedUrl['host'] ?? '' ) );
		$query         = (string) ( $parsedUrl['query'] ?? '' );
		$isAwsS3       = str_ends_with( $host, '.s3.amazonaws.com' ) || 's3.amazonaws.com' === $host;
		$hasAmzSig     = str_contains( $query, 'AWSAccessKeyId=' ) || str_contains( $query, 'X-Amz-Signature=' );
		$hasQueryToken = str_starts_with( $query, 'token=' ) || str_contains( $query, '&token=' );

		if ( $isAwsS3 || $hasAmzSig || $hasQueryToken ) {
			foreach ( array_keys( $headers ) as $headerKey ) {
				if ( 'authorization' === strtolower( (string) $headerKey ) ) {
					$authVal = is_string( $headers[ $headerKey ] ) ? $headers[ $headerKey ] : '';
					if ( ! str_starts_with( $authVal, 'AWS4-HMAC-SHA256' ) ) {
						unset( $headers[ $headerKey ] );
					}
				}
			}
		}
	}

	public function filterExternalHosts( mixed $isExternal, mixed $host ): bool {
		if ( ! is_string( $host ) || '' === $host ) {
			return (bool) $isExternal;
		}
		$cleanHost = strtolower( trim( $host, '.' ) );
		if ( in_array( $cleanHost, [ 'api.github.com', 'codeload.github.com', 'github.com', 's3.amazonaws.com' ], true ) || str_ends_with( $cleanHost, '.github.com' ) || str_ends_with( $cleanHost, '.githubusercontent.com' ) || str_ends_with( $cleanHost, '.s3.amazonaws.com' ) ) {
			return true;
		}
		return (bool) $isExternal;
	}

	public function filterTrustedHttpRequestArgs( mixed $parsedArgs, mixed $url = '' ): mixed {
		if ( is_array( $parsedArgs ) && is_string( $url ) && '' !== $url && $this->isTrustedPackageUrl( $url ) ) {
			$parsedArgs['reject_unsafe_urls'] = false;
		}
		return $parsedArgs;
	}

	private function isTrustedPackageUrl( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || 'https' !== strtolower( (string) ( $parsed['scheme'] ?? '' ) ) ) {
			return false;
		}
		$host = strtolower( trim( (string) ( $parsed['host'] ?? '' ), '.' ) );
		$path = strtolower( (string) ( $parsed['path'] ?? '' ) );
		if ( '' === $host || ! $this->filterExternalHosts( false, $host ) ) {
			return false;
		}
		if ( 'api.github.com' === $host ) {
			return str_starts_with( $path, '/repos/hd-agency/hd-form/' );
		}
		if ( 'github.com' === $host || 'codeload.github.com' === $host ) {
			return str_starts_with( $path, '/hd-agency/hd-form/' );
		}
		return str_ends_with( $host, '.githubusercontent.com' ) || 's3.amazonaws.com' === $host || str_ends_with( $host, '.s3.amazonaws.com' );
	}

	public function cleanStaleTempArchives( int $maxAgeSeconds = 300 ): void {
		$tempDir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$tempDir = trailingslashit( $tempDir );
		if ( ! is_dir( $tempDir ) || ! is_readable( $tempDir ) ) {
			return;
		}
		$now = time();
		foreach ( [ 'hd-agency-hd-form-*.zip', 'hd-form-package-*.tmp', 'hd-form-package-*.zip' ] as $pattern ) {
			$files = glob( $tempDir . $pattern );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( ! is_file( $file ) || ! wp_is_writable( $file ) ) {
					continue;
				}

				$mtime = filemtime( $file );
				if ( false !== $mtime && ( $now - $mtime ) >= $maxAgeSeconds ) {
					wp_delete_file( $file );
				}
			}
		}
	}

	public static function getEnvironmentToken(): ?string {
		$keys = [ 'HDF_GITHUB_TOKEN', 'GITHUB_TOKEN' ];
		foreach ( $keys as $key ) {
			if ( defined( $key ) ) {
				$constVal = constant( $key );
				if ( is_scalar( $constVal ) ) {
					$trimmed = trim( (string) $constVal );
					if ( '' !== $trimmed ) {
						return $trimmed;
					}
				}
			}
			if ( function_exists( '\Env\env' ) ) {
				$envVal = \Env\env( $key );
				if ( is_string( $envVal ) && '' !== trim( $envVal ) ) {
					return trim( $envVal );
				}
			} elseif ( function_exists( 'env' ) ) {
				$envVal = env( $key );
				if ( is_string( $envVal ) && '' !== trim( $envVal ) ) {
					return trim( $envVal );
				}
			}
			foreach ( [ $_ENV[ $key ] ?? null, $_SERVER[ $key ] ?? null, getenv( $key ) ] as $val ) {
				if ( is_string( $val ) && '' !== trim( $val ) ) {
					return trim( $val );
				}
			}
		}
		return null;
	}

	private static function getStoredToken(): ?string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decrypted = trim( Crypto::decrypt( $stored ) );
			if ( '' !== $decrypted ) {
				return $decrypted;
			}
		}
		return null;
	}

	public function getToken(): ?string {
		if ( $this->tokenLoaded ) {
			return $this->cachedToken;
		}
		$this->tokenLoaded = true;
		$this->cachedToken = self::getStoredToken() ?? self::getEnvironmentToken();
		return $this->cachedToken;
	}

	public static function hasToken(): bool {
		return 'none' !== self::tokenSource();
	}

	/**
	 * @return 'db'|'constant'|'none'
	 */
	public static function tokenSource(): string {
		if ( null !== self::getStoredToken() ) {
			return 'db';
		}
		if ( null !== self::getEnvironmentToken() ) {
			return 'constant';
		}
		return 'none';
	}
}
