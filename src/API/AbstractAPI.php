<?php
/**
 * Abstract base class for HD Form REST API controllers.
 *
 * Provides shared methods for rate limiting, nonce verification,
 * and standardized response formatting.
 *
 * @package HDForm\API
 */

declare(strict_types=1);

namespace HDForm\API;

use HDForm\Compat\RateLimitStorage;
use HDForm\Compat\Url;

defined( 'ABSPATH' ) || exit;

abstract class AbstractAPI extends \WP_REST_Controller {

	/**
	 * Reserved for intentionally public endpoints.
	 * When set to true, verifyNonce() will allow anonymous requests.
	 */
	public const BYPASS_NONCE = false;

	/** Register routes hook entry point. */
	public function register_routes(): void {
		$this->registerRoutes();
	}

	abstract protected function registerRoutes(): void;

	/**
	 * Generate full REST API URL for a given route.
	 */
	public function restApiUrl( string $route = '' ): string {
		return esc_url_raw( rest_url( HD_FORM_REST_NAMESPACE . '/' . ltrim( $route, '/' ) ) );
	}

	/**
	 * Verify nonce from request header.
	 *
	 * Returns null if valid, or a 403 WP_REST_Response if invalid.
	 */
	protected function verifyNonce( \WP_REST_Request $request ): ?\WP_REST_Response {
		if ( static::BYPASS_NONCE ) {
			return null;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		return ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) )
			? null
			: $this->sendResponse(
				[
					'success' => false,
					'message' => 'Invalid CSRF token.',
				],
				403
			);
	}

	/**
	 * Send standardized REST response.
	 */
	public function sendResponse( array $result = [], int $status = 200, array $data = [] ): \WP_REST_Response {
		$result = [
			'success'   => $status < 400,
			'status'    => $status,
			'errorCode' => 0,
			...$result,
		];

		if ( $data ) {
			$result['data'] = $data;
		}

		$response = rest_ensure_response( $result );
		$response->set_status( $status );

		return $response;
	}

	/**
	 * Send an item-list payload under the standard `data` key.
	 *
	 * @param array $items  Item list.
	 * @param int   $status HTTP status code.
	 */
	public function sendItems( array $items, int $status = 200 ): \WP_REST_Response {
		return $this->sendResponse( [], $status, $items );
	}

	/**
	 * Rate limiter using hybrid RateLimitStorage.
	 *
	 * Returns null if within limit, or a 429 WP_REST_Response if exceeded.
	 *
	 * @param string $keyPrefix Action identifier.
	 * @param int    $limit     Max requests allowed in window.
	 * @param int    $window    Time window in seconds.
	 */
	protected function rateLimit( string $keyPrefix, int $limit = 30, int $window = 60 ): ?\WP_REST_Response {
		$ip    = Url::ipAddress();
		$count = RateLimitStorage::increment( $ip, 'api_' . $keyPrefix, $window );

		return $count <= $limit
			? null
			: $this->sendResponse(
				[
					'success' => false,
					'message' => __( 'Too many requests. Please try again later.', 'hd-form' ),
				],
				429
			);
	}
}
