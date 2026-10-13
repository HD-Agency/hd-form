<?php
/**
 * Settings REST API Controller for HD Form.
 *
 * @package HDForm\API
 */

declare(strict_types=1);

namespace HDForm\API;

use HDForm\Support\Crypto;
use HDForm\Updater\GitHubUpdater;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class SettingsController extends AbstractAPI {

	public function __construct() {
		$this->namespace = HD_FORM_REST_NAMESPACE;
		$this->rest_base = 'settings';
	}

	protected function registerRoutes(): void {
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/github-token",
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'handleGetGithubTokenStatus' ],
					'permission_callback' => [ $this, 'checkPermission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'handleSaveGithubToken' ],
					'permission_callback' => [ $this, 'checkPermission' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'handleDeleteGithubToken' ],
					'permission_callback' => [ $this, 'checkPermission' ],
				],
			]
		);
	}

	public function checkPermission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function handleGetGithubTokenStatus( WP_REST_Request $request ): WP_REST_Response {
		return $this->sendResponse(
			[
				'has_token' => GitHubUpdater::hasToken(),
				'source'    => GitHubUpdater::tokenSource(),
			]
		);
	}

	public function handleSaveGithubToken( WP_REST_Request $request ): WP_REST_Response {
		$nonceError = $this->verifyNonce( $request );
		if ( $nonceError ) {
			return $nonceError;
		}

		$token = trim( sanitize_text_field( (string) $request->get_param( 'token' ) ) );
		if ( '' === $token ) {
			return $this->sendResponse(
				[
					'success' => false,
					'message' => __( 'Token cannot be empty.', 'hd-form' ),
				],
				400
			);
		}

		$encrypted = Crypto::encrypt( $token );
		if ( '' === $encrypted ) {
			return $this->sendResponse(
				[
					'success' => false,
					'message' => __( 'Failed to encrypt token.', 'hd-form' ),
				],
				500
			);
		}

		update_option( GitHubUpdater::TOKEN_OPTION, $encrypted );

		return $this->sendResponse(
			[
				'message'   => __( 'Access token saved securely.', 'hd-form' ),
				'has_token' => true,
				'source'    => 'db',
			]
		);
	}

	public function handleDeleteGithubToken( WP_REST_Request $request ): WP_REST_Response {
		$nonceError = $this->verifyNonce( $request );
		if ( $nonceError ) {
			return $nonceError;
		}

		delete_option( GitHubUpdater::TOKEN_OPTION );

		return $this->sendResponse(
			[
				'message'   => __( 'Access token removed from database.', 'hd-form' ),
				'has_token' => GitHubUpdater::hasToken(),
				'source'    => GitHubUpdater::tokenSource(),
			]
		);
	}
}
