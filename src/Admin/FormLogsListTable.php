<?php
/**
 * Form Logs List Table
 *
 * @package HDForm\Admin
 */

declare(strict_types=1);

namespace HDForm\Admin;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use HDForm\Repository\FormLogRepository;
use HDForm\Compat\Helper;

defined( 'ABSPATH' ) || exit;

class FormLogsListTable extends \WP_List_Table {

	private FormLogRepository $repo;

	public function __construct() {
		parent::__construct(
			[
				'singular' => __( 'Log', 'hd-form' ),
				'plural'   => __( 'Logs', 'hd-form' ),
				'ajax'     => false,
			]
		);

		$this->repo = new FormLogRepository();
	}

	public static function getColumns(): array {
		return [
			'id'         => __( 'ID', 'hd-form' ),
			'entry_id'   => __( 'Entry ID', 'hd-form' ),
			'event'      => __( 'Event', 'hd-form' ),
			'message'    => __( 'Message', 'hd-form' ),
			'actor'      => __( 'Actor', 'hd-form' ),
			'ip_address' => __( 'IP Address', 'hd-form' ),
			'created_at' => __( 'Date', 'hd-form' ),
		];
	}

	public function get_columns(): array {
		return self::getColumns();
	}

	public function get_sortable_columns(): array {
		return [
			'id'         => [ 'id', true ],
			'entry_id'   => [ 'entry_id', false ],
			'created_at' => [ 'created_at', false ],
		];
	}

	public function prepare_items(): void {
		$userPerPage = (int) get_user_option( 'hd_form_logs_per_page' );
		$perPage     = $userPerPage > 0 ? $userPerPage : 50;

		$columns  = $this->get_columns();
		$hidden   = get_hidden_columns( $this->screen );
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = [ $columns, $hidden, $sortable ];

		$currentPage = $this->get_pagenum();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters = [];
		if ( ! empty( $_REQUEST['event'] ) ) {
			$filters['event'] = sanitize_text_field( wp_unslash( $_REQUEST['event'] ) );
		}

		$totalItems  = $this->repo->countAll( $filters );
		$this->items = $this->repo->findAll( $filters, $currentPage, $perPage );

		$this->set_pagination_args(
			[
				'total_items' => $totalItems,
				'per_page'    => $perPage,
				'total_pages' => (int) ceil( $totalItems / $perPage ),
			]
		);
	}

	protected function column_default( $item, $column_name ) {
		$entryId  = absint( $item['entry_id'] ?? 0 );
		$entryUrl = add_query_arg(
			[
				'page'   => 'hd-form-entries',
				'action' => 'view',
				'entry'  => $entryId,
			],
			admin_url( 'admin.php' )
		);

		return match ( $column_name ) {
			'id'         => esc_html( $item['id'] ),
			'entry_id'   => sprintf( '<a href="%s">%d</a>', esc_url( $entryUrl ), $entryId ),
			'event'      => '<code>' . esc_html( $item['event'] ) . '</code>',
			'message'    => esc_html( $item['message'] ),
			'actor'      => esc_html( $item['actor'] ),
			'ip_address' => esc_html( $item['ip_address'] ),
			'created_at' => esc_html( Helper::formatDate( $item['created_at'] ) ),
			default      => isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '',
		};
	}

	public function no_items(): void {
		esc_html_e( 'No form logs found.', 'hd-form' );
	}
}
