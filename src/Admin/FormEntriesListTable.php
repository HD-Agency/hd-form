<?php
/**
 * Form Entries List Table
 *
 * @package HDForm\Admin
 */

declare(strict_types=1);

namespace HDForm\Admin;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use HDForm\FormConfig;
use HDForm\Repository\EntryCountCache;
use HDForm\Repository\FormEntryRepository;
use HDForm\Compat\Helper;
use HDForm\Plugin;

defined( 'ABSPATH' ) || exit;

class FormEntriesListTable extends \WP_List_Table {

	private FormEntryRepository $repo;

	public function __construct() {
		parent::__construct(
			[
				'singular' => __( 'Entry', 'hd-form' ),
				'plural'   => __( 'Entries', 'hd-form' ),
				'ajax'     => false,
			]
		);

		$this->repo = new FormEntryRepository();

		$this->process_bulk_action();
	}

	public static function getColumns(): array {
		$columns = [
			'cb' => '<input type="checkbox" />',
			'id' => __( 'ID', 'hd-form' ),
		];

		if ( FormConfig::hasWorkflowStatuses() ) {
			$columns['workflow'] = __( 'Workflow', 'hd-form' );
		}

		$columns['form_type']  = __( 'Form Type', 'hd-form' );
		$columns['name']       = __( 'Name', 'hd-form' );
		$columns['email']      = __( 'Email', 'hd-form' );
		$columns['phone']      = __( 'Phone', 'hd-form' );
		$columns['status']     = __( 'Status', 'hd-form' );
		$columns['ip_address'] = __( 'IP Address', 'hd-form' );
		$columns['created_at'] = __( 'Date', 'hd-form' );

		return $columns;
	}

	public function get_columns(): array {
		return self::getColumns();
	}

	public function get_sortable_columns(): array {
		$sortable = [
			'id'         => [ 'id', true ],
			'name'       => [ 'name', false ],
			'email'      => [ 'email', false ],
			'created_at' => [ 'created_at', false ],
		];

		if ( FormConfig::hasWorkflowStatuses() ) {
			$sortable['workflow'] = [ 'workflow_status', false ];
		}

		return $sortable;
	}

	public function get_bulk_actions(): array {
		return [
			'mark_read' => __( 'Mark Read', 'hd-form' ),
			'mark_spam' => __( 'Mark Spam', 'hd-form' ),
			'trash'     => __( 'Move to Trash', 'hd-form' ),
			'delete'    => __( 'Delete Permanently', 'hd-form' ),
		];
	}

	public function process_bulk_action(): void {
		if ( ! current_user_can( Plugin::CAP_VIEW_ENTRIES ) ) {
			return;
		}

		$action = $this->current_action();
		if ( ! $action ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$entries = isset( $_REQUEST['entry'] ) ? (array) wp_unslash( $_REQUEST['entry'] ) : [];
		$ids     = array_map( 'absint', $entries );

		if ( empty( $ids ) ) {
			return;
		}

		match ( $action ) {
			'mark_read' => $this->repo->bulkUpdateStatus( $ids, 'read' ),
			'mark_spam' => $this->repo->bulkUpdateStatus( $ids, 'spam' ),
			'trash'     => $this->repo->bulkUpdateStatus( $ids, 'trash' ),
			'delete'    => $this->repo->bulkDelete( $ids ),
			default     => null,
		};

		EntryCountCache::flush();

		$sendback = remove_query_arg( [ 'action', 'action2', 'entry', 'action_id', 'action2_id' ], wp_get_referer() ?: admin_url( 'admin.php?page=hd-form-entries' ) );
		wp_safe_redirect( $sendback );
		exit;
	}

	public function prepare_items(): void {
		$userPerPage = (int) get_user_option( 'hd_form_entries_per_page' );
		$perPage     = $userPerPage > 0 ? $userPerPage : 20;

		$columns  = $this->get_columns();
		$hidden   = get_hidden_columns( $this->screen );
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = [ $columns, $hidden, $sortable ];

		$currentPage = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderBy = sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ?? 'id' ) );
		$order   = sanitize_text_field( wp_unslash( $_REQUEST['order'] ?? 'DESC' ) );

		$filters = [];
		if ( ! empty( $_REQUEST['status'] ) ) {
			$filters['status'] = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) );
		}
		if ( ! empty( $_REQUEST['form_type'] ) ) {
			$filters['form_type'] = sanitize_text_field( wp_unslash( $_REQUEST['form_type'] ) );
		}
		if ( ! empty( $_REQUEST['workflow_status'] ) && FormConfig::hasWorkflowStatuses() ) {
			$filters['workflow_status'] = sanitize_key( wp_unslash( $_REQUEST['workflow_status'] ) );
		}
		if ( ! empty( $_REQUEST['s'] ) ) {
			$filters['search'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}
		// phpcs:enable

		$totalItems  = EntryCountCache::filteredTotal( $this->repo, $filters );
		$this->items = $this->repo->findAll( $filters, $currentPage, $perPage, $orderBy, $order );

		$this->set_pagination_args(
			[
				'total_items' => $totalItems,
				'per_page'    => $perPage,
				'total_pages' => (int) ceil( $totalItems / $perPage ),
			]
		);
	}

	protected function column_default( $item, $column_name ) {
		if ( 'status' === $column_name ) {
			$label = match ( $item['status'] ) {
				'new'     => __( 'New', 'hd-form' ),
				'read'    => __( 'Read', 'hd-form' ),
				'spam'    => __( 'Spam', 'hd-form' ),
				'starred' => __( 'Starred', 'hd-form' ),
				'trash'   => __( 'Trash', 'hd-form' ),
				default   => ucfirst( $item['status'] ),
			};

			return sprintf(
				'<span class="hd-entry-status hd-entry-status--%s">%s</span>',
				esc_attr( $item['status'] ),
				esc_html( $label )
			);
		}

		return match ( $column_name ) {
			'form_type'  => esc_html( FormConfig::getFormType( $item['form_type'] )['label'] ?? $item['form_type'] ),
			'name'       => esc_html( $item['name'] ),
			'email'      => esc_html( $item['email'] ),
			'phone'      => esc_html( $item['phone'] ),
			'ip_address' => esc_html( $item['ip_address'] ),
			'created_at' => esc_html( Helper::formatDate( $item['created_at'] ) ),
			default      => isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '',
		};
	}

	protected function column_workflow( $item ): string {
		return $this->renderWorkflowColumn( (array) $item );
	}

	private function renderWorkflowColumn( array $item ): string {
		static $statusMap = null;
		if ( null === $statusMap ) {
			$statusMap = [];
			foreach ( FormConfig::getWorkflowStatuses() as $st ) {
				if ( isset( $st['slug'] ) ) {
					$statusMap[ $st['slug'] ] = $st;
				}
			}
		}

		$entryId = (int) ( $item['id'] ?? 0 );
		$slug    = (string) ( $item['workflow_status'] ?? '' );
		$status  = '' !== $slug ? ( $statusMap[ $slug ] ?? null ) : null;

		if ( '' === $slug ) {
			return sprintf(
				'<button type="button" class="hd-workflow-picker-btn hd-workflow-picker-btn--empty" data-entry-id="%d" data-current-slug=""><span class="dashicons dashicons-plus-alt2" style="font-size: 12px; width: 12px; height: 12px; line-height: 12px; margin-right: 2px;"></span><span class="hd-wf-label">%s</span></button>',
				$entryId,
				esc_html__( 'Set Status', 'hd-form' )
			);
		}

		if ( null === $status ) {
			return sprintf(
				'<button type="button" class="hd-workflow-picker-btn hd-workflow-picker-btn--orphan" data-entry-id="%d" data-current-slug="%s"><span class="hd-wf-dot" style="background:#a7aaad;"></span><span class="hd-wf-label">%s</span></button>',
				$entryId,
				esc_attr( $slug ),
				esc_html( $slug )
			);
		}

		$color = esc_attr( $status['color'] ?? '#a7aaad' );
		$label = esc_html( $status['label'] ?? $slug );

		return sprintf(
			'<button type="button" class="hd-workflow-picker-btn" data-entry-id="%d" data-current-slug="%s"><span class="hd-wf-dot" style="background:%s;"></span><span class="hd-wf-label">%s</span></button>',
			$entryId,
			esc_attr( $slug ),
			$color,
			$label
		);
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="%1$s[]" value="%2$s" />',
			'entry',
			esc_attr( (string) absint( $item['id'] ) )
		);
	}

	protected function column_id( $item ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : 'hd-form-entries';

		$id        = absint( $item['id'] );
		$viewUrl   = add_query_arg(
			[
				'page'   => $page,
				'action' => 'view',
				'entry'  => $id,
			],
			admin_url( 'admin.php' )
		);
		$deleteUrl = wp_nonce_url(
			add_query_arg(
				[
					'page'    => $page,
					'action'  => 'delete',
					'entry[]' => $id,
				],
				admin_url( 'admin.php' )
			),
			'bulk-' . $this->_args['plural']
		);

		$actions = [
			'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $viewUrl ), esc_html__( 'View', 'hd-form' ) ),
			'delete' => sprintf( '<a href="%s" class="delete" onclick="return confirm(\'%s\');">%s</a>', esc_url( $deleteUrl ), esc_js( __( 'Are you sure?', 'hd-form' ) ), __( 'Delete', 'hd-form' ) ),
		];

		return sprintf( '%1$s %2$s', esc_html( (string) $id ), $this->row_actions( $actions ) );
	}

	protected function get_views() {
		$views = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : 'all';

		$statuses = [
			'all'     => __( 'All', 'hd-form' ),
			'new'     => __( 'New', 'hd-form' ),
			'read'    => __( 'Read', 'hd-form' ),
			'starred' => __( 'Starred', 'hd-form' ),
			'spam'    => __( 'Spam', 'hd-form' ),
			'trash'   => __( 'Trash', 'hd-form' ),
		];

		$baseUrl = admin_url( 'admin.php?page=hd-form-entries' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$extraFilters = [];
		if ( ! empty( $_REQUEST['form_type'] ) ) {
			$extraFilters['form_type'] = sanitize_text_field( wp_unslash( $_REQUEST['form_type'] ) );
		}
		if ( ! empty( $_REQUEST['workflow_status'] ) && FormConfig::hasWorkflowStatuses() ) {
			$extraFilters['workflow_status'] = sanitize_key( wp_unslash( $_REQUEST['workflow_status'] ) );
		}
		if ( ! empty( $_REQUEST['s'] ) ) {
			$extraFilters['s'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}
		// phpcs:enable

		// Optimize: single query for all counts.
		$counts     = $this->repo->countByStatus();
		$totalCount = (int) ( $counts['all'] ?? 0 );

		foreach ( $statuses as $status => $label ) {
			$urlParams = array_merge( $extraFilters, 'all' === $status ? [] : [ 'status' => $status ] );
			$url       = add_query_arg( $urlParams, $baseUrl );
			$class     = $current === $status ? ' class="current"' : '';

			$count = 'all' === $status ? $totalCount : ( $counts[ $status ] ?? 0 );

			if ( 'all' === $status || $count > 0 ) {
				$views[ $status ] = sprintf( '<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>', esc_url( $url ), $class, $label, $count );
			}
		}

		return $views;
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$config    = FormConfig::all();
		$formTypes = $config['form_types'] ?? [];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_REQUEST['form_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['form_type'] ) ) : '';

		echo '<div class="alignleft actions">';
		echo '<select name="form_type" id="filter-by-form-type">';
		echo '<option value="">' . esc_html__( 'All Form Types', 'hd-form' ) . '</option>';
		foreach ( $formTypes as $slug => $type ) {
			$label = $type['label'] ?? $slug;
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $current, $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		if ( FormConfig::hasWorkflowStatuses() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$currentWorkflow = isset( $_REQUEST['workflow_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['workflow_status'] ) ) : '';
			echo '<select name="workflow_status" id="filter-by-workflow-status">';
			echo '<option value="">' . esc_html__( 'All Workflow Statuses', 'hd-form' ) . '</option>';
			foreach ( FormConfig::getWorkflowStatuses() as $wfStatus ) {
				$wfSlug  = $wfStatus['slug'] ?? '';
				$wfLabel = $wfStatus['label'] ?? $wfSlug;
				if ( '' === $wfSlug ) {
					continue;
				}
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $wfSlug ),
					selected( $currentWorkflow, $wfSlug, false ),
					esc_html( $wfLabel )
				);
			}
			echo '</select>';
		}

		submit_button( __( 'Filter', 'hd-form' ), 'button', '', false, [ 'id' => 'post-query-submit' ] );

		echo '</div>';
	}
}
