<?php
/**
 * HD Form Plugin — Bootstrap
 *
 * Coordinates all subsystem initialization:
 * Cron hooks, admin pages, REST API, frontend config.
 *
 * @package HDForm
 */

declare(strict_types=1);

namespace HDForm;

use HDForm\Admin\FormEntriesPage;
use HDForm\Admin\FormExporter;
use HDForm\Admin\FormLogsPage;
use HDForm\Admin\FormSettingsPage;
use HDForm\API\DynamicFieldAPI;
use HDForm\API\FormAPI;
use HDForm\API\FormEntryController;
use HDForm\API\SettingsController;
use HDForm\Cron\AsyncFormProcessor;
use HDForm\Cron\FormEntryCleaner;
use HDForm\Cron\MailQueueProcessor;
use HDForm\Cron\WeeklyDigestCron;
use HDForm\Frontend\FormSetup;
use HDForm\Security\CaptchaGuard;
use HDForm\Security\HoneypotGuard;
use HDForm\Updater\GitHubUpdater;
use HDForm\Compat\Helper;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const CAPABILITY = 'hd_manage_forms';

	/**
	 * View entries/logs in the admin.
	 *
	 * Granted to configured roles; CAPABILITY above remains a legacy alias
	 * that resolves to the same grant.
	 */
	public const CAP_VIEW_ENTRIES = 'hd_view_entries';

	/**
	 * Export entries — a bulk PII channel, so administrators only.
	 */
	public const CAP_EXPORT_ENTRIES = 'hd_export_entries';

	/**
	 * Boot the plugin.
	 *
	 * Called on `plugins_loaded` at priority 15.
	 */
	public static function boot(): void {
		// Core config.
		FormConfig::register();

		// Auto-updater.
		GitHubUpdater::init();

		// Cron hooks.
		MailQueueProcessor::init();
		FormEntryCleaner::init();
		WeeklyDigestCron::init();
		AsyncFormProcessor::init();

		// Guarded, idempotent schema migrations.
		add_action( 'admin_init', [ Schema::class, 'maybeUpgrade' ] );

		// Export handler (admin-post).
		FormExporter::register();

		// REST API.
		add_action( 'rest_api_init', [ self::class, 'registerRestRoutes' ] );

		// Admin UI.
		if ( is_admin() ) {
			FormEntriesPage::register();
			FormLogsPage::register();
			FormSettingsPage::register();
		} else {
			// Frontend: loader script + honeypot config.
			FormSetup::register();
			add_action( 'wp_enqueue_scripts', [ self::class, 'injectFrontendConfig' ], 20 );
		}

		// Shortcodes.
		add_shortcode( 'hd_form_captcha', [ self::class, 'renderCaptchaShortcode' ] );

		// Load plugin textdomain.
		add_action( 'init', [ self::class, 'loadTextdomain' ] );

		// Dynamic capability checks.
		add_filter( 'user_has_cap', [ self::class, 'filterUserCapabilities' ], 10, 4 );
	}

	/**
	 * Render CAPTCHA widget for a form type.
	 *
	 * @param string $formType Form type slug.
	 *
	 * @return string HTML widget output.
	 */
	public static function renderCaptcha( string $formType = '' ): string {
		return CaptchaGuard::make( $formType )->renderField();
	}

	/**
	 * Shortcode callback for [hd_form_captcha type="contact"].
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public static function renderCaptchaShortcode( array|string $atts = [] ): string {
		$atts = is_array( $atts ) ? $atts : [];
		$type = sanitize_key( (string) ( $atts['type'] ?? $atts['form_type'] ?? '' ) );

		return self::renderCaptcha( $type );
	}

	/**
	 * Register all REST API controllers.
	 */
	public static function registerRestRoutes(): void {
		( new FormAPI() )->register_routes();
		( new DynamicFieldAPI() )->register_routes();
		( new FormEntryController() )->register_routes();
		( new SettingsController() )->register_routes();
	}

	/**
	 * Merge form-specific config into the global hdConfig JS object.
	 *
	 * Provides restApiUrl, restToken (WP REST nonce), and honeypot payload.
	 * When the HD theme is active it already injects hdConfig — Object.assign
	 * merges our keys without overwriting existing ones.
	 */
	public static function injectFrontendConfig(): void {
		$config = [
			'restApiUrl' => esc_url_raw( rest_url( HD_FORM_REST_NAMESPACE . '/' ) ),
			'restToken'  => wp_create_nonce( 'wp_rest' ),
			'form'       => [
				'restApiUrl' => esc_url_raw( rest_url( HD_FORM_REST_NAMESPACE . '/' ) ),
				'honeypot'   => HoneypotGuard::payload(),
			],
		];

		$captcha = CaptchaGuard::frontendConfig();
		if ( null !== $captcha ) {
			$config['captcha'] = $captcha;

			$scriptUrl = CaptchaGuard::make()->getScriptUrl();
			if ( '' !== $scriptUrl ) {
				// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External 3rd-party CAPTCHA library manages its own caching.
				wp_enqueue_script( 'hd-form-captcha', $scriptUrl, [], null, true );
			}
		}

		$json = wp_json_encode( $config );

		if ( false !== $json ) {
			wp_add_inline_script(
				'hd-form-loader',
				sprintf( 'window.hdConfig=Object.assign(window.hdConfig||{},%s);', $json ),
				'before'
			);
		}
	}

	/**
	 * Load plugin textdomain.
	 */
	public static function loadTextdomain(): void {
		load_plugin_textdomain(
			'hd-form',
			false,
			dirname( plugin_basename( HD_FORM_PATH . 'hd-form.php' ) ) . '/languages'
		);
	}

	/**
	 * Dynamic capability filter for form capabilities.
	 *
	 * Grants hd_view_entries (and the legacy hd_manage_forms alias) to users
	 * with configured roles; administrators always qualify. Export stays
	 * administrator-only because it is a bulk PII channel. Settings access is
	 * governed by WordPress core's manage_options and not granted here.
	 *
	 * @param array    $allcaps User's actual capabilities.
	 * @param array    $caps    Capabilities being checked.
	 * @param array    $args    Arguments passed to current_user_can (capability, user_id, etc.).
	 * @param \WP_User $user    The user object.
	 *
	 * @return array Updated capabilities.
	 */
	public static function filterUserCapabilities( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		$requested = array_intersect( $caps, [ self::CAPABILITY, self::CAP_VIEW_ENTRIES, self::CAP_EXPORT_ENTRIES ] );
		if ( empty( $requested ) ) {
			return $allcaps;
		}

		// Administrator is always allowed.
		$isAdmin = in_array( 'administrator', $user->roles, true );

		foreach ( $requested as $cap ) {
			// Bulk PII export stays administrator-only.
			if ( self::CAP_EXPORT_ENTRIES === $cap && ! $isAdmin ) {
				unset( $allcaps[ $cap ] );
				continue;
			}

			if ( $isAdmin || self::isAllowedRole( $user ) ) {
				$allcaps[ $cap ] = true;
			} else {
				// Explicitly unset when not allowed.
				unset( $allcaps[ $cap ] );
			}
		}

		return $allcaps;
	}

	/**
	 * Whether the user holds any role enabled in plugin settings.
	 *
	 * @param \WP_User $user The user object.
	 */
	private static function isAllowedRole( \WP_User $user ): bool {
		$settings     = Helper::getOption( 'hd_form_settings', [] );
		$allowedRoles = $settings['roles'] ?? [ 'administrator' ];
		if ( ! is_array( $allowedRoles ) ) {
			$allowedRoles = [ 'administrator' ];
		}

		foreach ( $user->roles as $userRole ) {
			if ( in_array( $userRole, $allowedRoles, true ) ) {
				return true;
			}
		}

		return false;
	}
}
