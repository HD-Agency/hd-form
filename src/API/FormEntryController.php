<?php
/**
 * Form Entry Controller
 *
 * Admin-only REST endpoint for per-entry workflow status management.
 * Route: POST /wp-json/hd/v1/form-entries/{id}/workflow-status
 *
 * @package HDForm\API
 */

declare(strict_types=1);

namespace HDForm\API;

use HDForm\FormConfig;
use HDForm\Plugin;
use HDForm\Repository\FormEntryRepository;
use HDForm\Repository\WorkflowHistoryRepository;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class FormEntryController extends AbstractAPI {

	public function __construct() {
		$this->namespace = HD_FORM_REST_NAMESPACE;
		$this->rest_base = 'form-entries';
	}

	/**
	 * Register REST routes.
	 */
	protected function registerRoutes(): void {
		$namespaces = array_unique(
			array_filter(
				[
					$this->namespace,
					'wp/v2',
					'hd/v1',
					'hd',
				]
			)
		);

		foreach ( $namespaces as $ns ) {
			register_rest_route(
				$ns,
				'/' . $this->rest_base . '/(?P<id>[\d]+)/workflow-status',
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'updateWorkflowStatus' ],
					'permission_callback' => [ $this, 'checkPermission' ],
					'args'                => [
						'id'              => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'workflow_status' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => '',
						],
						'note'            => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'default'           => '',
						],
					],
				]
			);
		}
	}

	/**
	 * Permission check: only users authorized to view/manage form entries.
	 */
	public function checkPermission(): bool {
		return current_user_can( Plugin::CAP_VIEW_ENTRIES )
			|| current_user_can( Plugin::CAPABILITY )
			|| current_user_can( 'manage_options' );
	}

	/**
	 * POST /form-entries/{id}/workflow-status
	 *
	 * Updates workflow_status for a single entry and records the transition
	 * in hde_form_workflow_history.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function updateWorkflowStatus( WP_REST_Request $request ): WP_REST_Response {
		$nonceError = $this->verifyNonce( $request );
		if ( $nonceError ) {
			return $nonceError;
		}

		if ( ! FormConfig::hasWorkflowStatuses() ) {
			return $this->sendResponse( [ 'message' => __( 'Workflow statuses are not configured.', 'hd-form' ) ], 422 );
		}

		$entryId      = (int) $request->get_param( 'id' );
		$workflowSlug = sanitize_key( (string) $request->get_param( 'workflow_status' ) );
		$note         = sanitize_textarea_field( (string) $request->get_param( 'note' ) );

		// Validate slug against configured statuses (empty string = clear).
		if ( '' !== $workflowSlug && null === FormConfig::getWorkflowStatusBySlug( $workflowSlug ) ) {
			return $this->sendResponse( [ 'message' => __( 'Invalid workflow status.', 'hd-form' ) ], 422 );
		}

		$repo  = new FormEntryRepository();
		$entry = $repo->findById( $entryId );
		if ( null === $entry ) {
			return $this->sendResponse( [ 'message' => __( 'Entry not found.', 'hd-form' ) ], 404 );
		}

		$currentSlug = (string) ( $entry['workflow_status'] ?? '' );
		if ( $currentSlug !== $workflowSlug ) {
			$repo->updateWorkflowStatus( $entryId, $workflowSlug );
		}

		// Record history row.
		( new WorkflowHistoryRepository() )->insert( $entryId, $workflowSlug, $note, get_current_user_id() );

		return $this->sendResponse( [ 'message' => __( 'Workflow status updated.', 'hd-form' ) ], 200 );
	}
}
