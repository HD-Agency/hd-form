<?php
/**
 * Plugin Name: HD Form
 * Plugin URI: https://webhd.vn
 * Version: 1.1.1
 * Requires PHP: 8.1
 * Author: HD
 * Author URI: https://webhd.vn
 * Description: Standalone, theme-independent form plugin with REST API submission, CAPTCHA, honeypot, spam detection, file upload, email notifications, cron jobs, and admin UI.
 * License: MIT
 * Text Domain: hd-form
 */

use HDForm\Plugin;

defined( 'ABSPATH' ) || exit;

// Prevent double loading.
if ( defined( 'HD_FORM_VERSION' ) ) {
	return;
}

// ── Constants ───────────────────────────────────

const HD_FORM_VERSION = '1.1.1';

// REST namespace — intentionally matches the theme's 'hd/v1' so existing frontend
// JS continues to work without modification after porting.
if ( ! defined( 'HD_FORM_REST_NAMESPACE' ) ) {
	define( 'HD_FORM_REST_NAMESPACE', defined( 'REST_NAMESPACE' ) ? REST_NAMESPACE : 'hd/v1' );
}

define( 'HD_FORM_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) . DIRECTORY_SEPARATOR );
define( 'HD_FORM_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) . '/' );
define( 'HD_FORM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ── Guards ──────────────────────────────────────

// PHP version guard.
if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static fn() => printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( 'HD Form requires PHP 8.1 or newer. Please upgrade your PHP version.' )
		)
	);
	return;
}

// Autoload guard.
$hd_form_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_file( $hd_form_autoload ) ) {
	require_once $hd_form_autoload;
}

if ( ! class_exists( Plugin::class ) ) {
	add_action(
		'admin_notices',
		static fn() => printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( 'HD Form: missing vendor directory. Please run composer install.' )
		)
	);
	return;
}

// ── Activation Hook ─────────────────────────────

register_activation_hook( __FILE__, [ 'HDForm\Schema', 'maybeUpgrade' ] );

// ── Deactivation Hook ───────────────────────────

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( \HDForm\Cron\MailQueueProcessor::HOOK );
		wp_clear_scheduled_hook( \HDForm\Cron\WeeklyDigestCron::HOOK );
		wp_clear_scheduled_hook( \HDForm\Cron\FormEntryCleaner::HOOK );
	}
);

// ── Bootstrap ───────────────────────────────────

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ], 15 );

// ── Global Helpers ──────────────────────────────

if ( ! function_exists( 'hd_form_captcha' ) ) {
	/**
	 * Render CAPTCHA widget for a given form type.
	 *
	 * @param string $formType Form type slug.
	 *
	 * @return string
	 */
	function hd_form_captcha( string $formType = '' ): string {
		return Plugin::renderCaptcha( $formType );
	}
}
