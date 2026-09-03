<?php
/**
 * GitHub-based plugin auto-updater via plugin-update-checker (PUC).
 *
 * Repository URL and tracked branch are intentionally fixed to this plugin's
 * own repository (`HD-Agency/hd-form`, branch `main`).
 *
 * The Personal Access Token is stored encrypted in wp_options under its own
 * key, or read from HDF_GITHUB_TOKEN constant/env var.
 *
 * @package HDForm\Updater
 */

declare(strict_types=1);

namespace HDForm\Updater;

use HDForm\Support\Crypto;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker;

defined( 'ABSPATH' ) || exit;

final class GitHubUpdater {

	/** @internal Intentionally fixed — not a user-configurable setting. */
	private const REPO_URL              = 'https://github.com/HD-Agency/hd-form';
	public const TOKEN_OPTION           = '_hdf_github_token';
	private const CHECK_PERIOD_HOURS    = 24;
	private const ASYNC_CRON_HOOK       = 'hdf_async_github_update_check';
	private const ASYNC_SPAWN_TRANSIENT = '_hdf_puc_async_spawn';
	private const HTTP_TIMEOUT_WEB      = 2.5;
	private const HTTP_TIMEOUT_CRON     = 5.0;

	private ?PluginUpdateChecker $checker = null;

	public function __construct() {
		$this->initUpdateChecker();
	}

	public static function init(): self {
		return new self();
	}

	private function initUpdateChecker(): void {
		try {
			// Enforce strict HTTP timeouts to protect against network delay/hangs.
			add_filter( 'puc_request_info_options-hd-form', [ $this, 'filterHttpRequestOptions' ] );

			// Guard against synchronous blocking checks on passive admin page loads.
			add_filter( 'puc_check_now-hd-form', [ $this, 'shouldCheckNow' ] );

			/** @var PluginUpdateChecker $checker */
			$checker = PucFactory::buildUpdateChecker(
				self::REPO_URL,
				HD_FORM_PATH . 'hd-form.php',
				'hd-form',
				self::CHECK_PERIOD_HOURS
			);

			$checker->setBranch( 'main' );

			$token = $this->getToken();
			if ( $token ) {
				$checker->setAuthentication( $token );
			}

			$this->checker = $checker;

			// Register async background cron handler.
			add_action( self::ASYNC_CRON_HOOK, [ $checker, 'checkForUpdates' ] );
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
	 * Dispatches non-blocking async background checks when periodic check is due during admin browsing.
	 *
	 * @param bool $shouldCheck Current decision from PUC scheduler.
	 */
	public function shouldCheckNow( bool $shouldCheck ): bool {
		if ( ! $shouldCheck ) {
			return false;
		}

		// 1. Always allow cron-triggered checks.
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return true;
		}

		// 2. Allow manual "Check for updates" link on plugins.php.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['puc_check_for_updates'] ) || ! empty( $_GET['puc_slug'] ) ) {
			return true;
		}

		// 3. Allow manual "Check Again" on Dashboard → Updates or after bulk upgrades.
		if ( function_exists( 'doing_action' ) && ( doing_action( 'load-update-core.php' ) || doing_action( 'upgrader_process_complete' ) ) ) {
			return true;
		}

		// 4. On passive admin browsing (plugins.php, admin_init), dispatch non-blocking async background check.
		if ( function_exists( 'is_admin' ) && is_admin() && ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) ) {
			$this->maybeSpawnAsyncCheck();
		}

		// Block synchronous execution on the current page thread.
		return false;
	}

	/**
	 * Spawn an asynchronous non-blocking background check via WP-Cron.
	 */
	private function maybeSpawnAsyncCheck(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// Rate-limit async spawn attempts to once per hour to avoid redundant worker spawns.
		if ( get_transient( self::ASYNC_SPAWN_TRANSIENT ) ) {
			return;
		}

		set_transient( self::ASYNC_SPAWN_TRANSIENT, 1, HOUR_IN_SECONDS );

		if ( ! wp_next_scheduled( self::ASYNC_CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::ASYNC_CRON_HOOK );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	// ── Token resolution ────────────────────────────────────────────────

	public function getToken(): ?string {
		$stored = get_option( self::TOKEN_OPTION, '' );
		if ( ! empty( $stored ) ) {
			$decrypted = Crypto::decrypt( (string) $stored );
			if ( '' !== $decrypted ) {
				return $decrypted;
			}
		}

		return self::getEnvironmentToken();
	}

	/**
	 * Resolve token from environment variables or defined constants.
	 */
	public static function getEnvironmentToken(): ?string {
		if ( defined( 'HDF_GITHUB_TOKEN' ) && \HDF_GITHUB_TOKEN ) {
			return (string) \HDF_GITHUB_TOKEN;
		}

		if ( function_exists( 'env' ) ) {
			$val = env( 'HDF_GITHUB_TOKEN' );
			if ( ! empty( $val ) ) {
				return (string) $val;
			}
		}

		if ( ! empty( $_ENV['HDF_GITHUB_TOKEN'] ) ) {
			return (string) $_ENV['HDF_GITHUB_TOKEN'];
		}

		if ( ! empty( $_SERVER['HDF_GITHUB_TOKEN'] ) ) {
			return (string) $_SERVER['HDF_GITHUB_TOKEN'];
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

		if ( null !== self::getEnvironmentToken() ) {
			return 'constant';
		}

		return 'none';
	}
}
