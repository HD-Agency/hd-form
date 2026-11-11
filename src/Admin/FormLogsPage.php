<?php
/**
 * Form Logs Page
 *
 * @package HDForm\Admin
 */

declare(strict_types=1);

namespace HDForm\Admin;

defined( 'ABSPATH' ) || exit;

use HDForm\Plugin;

class FormLogsPage {

	/**
	 * Register admin submenu.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'addMenuPage' ], 40 );
		add_filter( 'set-screen-option', [ self::class, 'saveScreenOption' ], 10, 3 );
		add_filter( 'manage_form-entries_page_hd-form-logs_columns', [ FormLogsListTable::class, 'getColumns' ] );
	}

	public static function addMenuPage(): void {
		$hookSuffix = add_submenu_page(
			'hd-form-entries',
			__( 'Form Logs', 'hd-form' ),
			__( 'Logs', 'hd-form' ),
			Plugin::CAP_VIEW_ENTRIES,
			'hd-form-logs',
			[ self::class, 'renderPage' ]
		);

		if ( is_string( $hookSuffix ) && '' !== $hookSuffix ) {
			add_action( "load-{$hookSuffix}", [ self::class, 'loadScreenOptions' ] );
		}
	}

	/**
	 * Configure Screen Options when viewing form-logs page.
	 */
	public static function loadScreenOptions(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Logs per page', 'hd-form' ),
				'default' => 50,
				'option'  => 'hd_form_logs_per_page',
			]
		);
	}

	/**
	 * Save per_page screen option for hd-form-logs.
	 */
	public static function saveScreenOption( mixed $status, string $option, mixed $value ): mixed {
		if ( 'hd_form_logs_per_page' === $option ) {
			return absint( $value );
		}

		return $status;
	}

	public static function renderPage(): void {
		if ( ! current_user_can( Plugin::CAP_VIEW_ENTRIES ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hd-form' ) );
		}

		$listTable = new FormLogsListTable();
		$listTable->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Form Logs', 'hd-form' ) . '</h1>';
		echo '<form method="get">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<input type="hidden" name="page" value="' . esc_attr( isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '' ) . '">';
		$listTable->display();
		echo '</form>';
		echo '</div>';
	}
}
