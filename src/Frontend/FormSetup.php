<?php
/**
 * Form Frontend Setup — asset enqueueing.
 *
 * Registers the lightweight form-loader script on every page.
 * The full form bundle is loaded dynamically when form triggers are detected in DOM.
 *
 * @package HDForm\Frontend
 */

declare(strict_types=1);

namespace HDForm\Frontend;

defined( 'ABSPATH' ) || exit;

final class FormSetup {

	/**
	 * Register frontend hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueAssets' ] );
		add_filter( 'script_loader_tag', [ self::class, 'filterScriptLoaderTag' ], 10, 2 );
	}

	/**
	 * Enqueue the lightweight loader script globally.
	 */
	public static function enqueueAssets(): void {
		wp_enqueue_script(
			'hd-form-loader',
			HD_FORM_URL . 'assets/form-loader.js',
			[ 'wp-i18n' ],
			HD_FORM_VERSION,
			true
		);

		wp_localize_script(
			'hd-form-loader',
			'hdFormConfig',
			[
				'jsUrl'  => HD_FORM_URL . 'assets/form.js',
				'cssUrl' => HD_FORM_URL . 'assets/form.css',
			]
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'hd-form-loader', 'hd-form', HD_FORM_PATH . 'languages' );
		}
	}

	/**
	 * Add type="module" to modern script tags.
	 */
	public static function filterScriptLoaderTag( string $tag, string $handle ): string {
		if ( in_array( $handle, [ 'hd-form-loader', 'hd-form' ], true ) ) {
			if ( ! preg_match( '#\stype=(["\'])module\1#', $tag ) ) {
				$tag = preg_replace( '#(?=></script>)#', ' type="module"', $tag, 1 );
			}
		}

		return $tag;
	}
}
