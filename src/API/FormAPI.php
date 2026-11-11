<?php
/**
 * Form REST API Endpoint
 *
 * POST /wp-json/hd/v1/form/submit
 *
 * Thin controller: handles HTTP concerns (rate limit, nonce, response format)
 * and delegates business logic to FormManager.
 *
 * @package HDForm\API
 */

declare(strict_types=1);

namespace HDForm\API;

use HDForm\FormConfig;
use HDForm\FormManager;
use HDForm\Compat\Url;

defined( 'ABSPATH' ) || exit;

final class FormAPI extends AbstractAPI {

	public function __construct() {
		$this->namespace = HD_FORM_REST_NAMESPACE;
		$this->rest_base = 'form';
	}

	/** ---------------------------------------- */

	/**
	 * Register routes.
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
				"/{$this->rest_base}/submit",
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'submitCallback' ],
					'permission_callback' => '__return_true',
				]
			);
		}
	}

	/** ---------------------------------------- */

	/**
	 * Handle form submission.
	 *
	 * HTTP-layer only: rate limit → nonce → delegate to FormManager → format response.
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function submitCallback( \WP_REST_Request $request ): \WP_Error|\WP_REST_Response {

		// 1. Parse input (JSON or multipart) so rate limits can scope by form type.
		$contentType = $request->get_content_type();
		$isMultipart = $contentType && str_contains( $contentType['value'] ?? '', 'multipart/form-data' );

		if ( $isMultipart ) {
			$bodyParams = $request->get_body_params();
			$input      = json_decode( $bodyParams['payload'] ?? '{}', true ) ?: [];
			$files      = $request->get_file_params();
		} else {
			$input = $request->get_json_params() ?: [];
			$files = [];
		}

		$formType = sanitize_key( (string) ( $input['form_type'] ?? 'unknown' ) );
		$formType = '' !== $formType ? $formType : 'unknown';

		// 2. Rate limit: 5 submissions per minute per IP per form type.
		$rateLimitCheck = $this->rateLimit( 'form_submit_' . $formType, 5, 60 );
		if ( $rateLimitCheck instanceof \WP_REST_Response ) {
			return $rateLimitCheck;
		}

		// 3. Nonce verify.
		$nonceCheck = $this->verifyNonce( $request );
		if ( $nonceCheck instanceof \WP_REST_Response ) {
			return $nonceCheck;
		}

		// 4. Delegate business logic to FormManager.
		$manager = new FormManager();
		$result  = $manager->processSubmission(
			input:     $input,
			ip:        Url::ipAddress(),
			userAgent: sanitize_text_field( wp_unslash( $request->get_header( 'user-agent' ) ?? '' ) ),
			referer:   sanitize_url( $request->get_header( 'referer' ) ?? '' ),
			files:     $files,
		);

		// 5. Format response.
		if ( is_wp_error( $result ) ) {
			$data = [
				'success' => false,
				'message' => $result->get_error_message(),
			];

			// Include per-field errors for frontend highlighting.
			$errorData = $result->get_error_data();
			$errorData = is_array( $errorData ) ? $errorData : [];

			if ( ! empty( $errorData['fields'] ) ) {
				$data['fields'] = $errorData['fields'];
			}

			return $this->sendResponse(
				$data,
				(int) ( $errorData['status'] ?? 422 )
			);
		}

		$onSuccess = FormConfig::getOnSuccess( $formType, self::onSuccessOverrideFromInput( $input ) );

		return $this->sendResponse(
			[
				'success'    => true,
				'message'    => ! empty( $onSuccess['message'] ) ? $onSuccess['message'] : __( 'Thank you! We have received your information.', 'hd-form' ),
				'entry_id'   => $result['entry_id'] ?? 0,
				'on_success' => $onSuccess,
			]
		);
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed>
	 */
	private static function onSuccessOverrideFromInput( array $input ): array {
		$override = [];

		if ( array_key_exists( 'success_action', $input ) ) {
			$override['action'] = $input['success_action'];
		}

		if ( array_key_exists( 'success_redirect', $input ) ) {
			$override['redirect_url'] = $input['success_redirect'];
		}

		if ( array_key_exists( 'success_delay', $input ) ) {
			$override['redirect_delay'] = $input['success_delay'];
		}

		return $override;
	}
}
