<?php
/**
 * Webhook Integration
 *
 * Dispatches HTTP requests to third-party endpoints (Zapier, Make, Pabbly, CRMs)
 * with format options (JSON / Form Data), HMAC SHA256 signatures, and status reporting.
 *
 * @package HDForm\Integrations
 */

declare(strict_types=1);

namespace HDForm\Integrations;

defined( 'ABSPATH' ) || exit;

final class WebhookIntegration {

	/**
	 * Send webhook payload to remote URL.
	 *
	 * @param string $url           Target HTTP/HTTPS Webhook URL.
	 * @param string $method        HTTP Method (POST, PUT, PATCH, GET).
	 * @param string $format        Request format ('json' or 'formdata').
	 * @param array  $payload       Data array to send.
	 * @param string $secretKey     Optional HMAC secret key.
	 * @param array  $customHeaders Optional extra HTTP headers.
	 *
	 * @return array{success: bool, message: string, status?: int, body?: string}
	 */
	public static function sendWebhook(
		string $url,
		string $method = 'POST',
		string $format = 'json',
		array $payload = [],
		string $secretKey = '',
		array $customHeaders = []
	): array {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return [
				'success' => false,
				'message' => 'Invalid Webhook URL provided.',
			];
		}

		$method = strtoupper( trim( $method ) );
		if ( ! in_array( $method, [ 'POST', 'PUT', 'PATCH', 'GET' ], true ) ) {
			$method = 'POST';
		}

		$format = strtolower( trim( $format ) );
		if ( ! in_array( $format, [ 'json', 'formdata' ], true ) ) {
			$format = 'json';
		}

		$version = defined( 'HD_FORM_VERSION' ) ? HD_FORM_VERSION : '2.0.0';
		$headers = [
			'User-Agent' => 'HD-Form-Webhook/' . $version . ' (' . esc_url( home_url() ) . ')',
		];

		if ( 'GET' === $method ) {
			if ( ! empty( $payload ) ) {
				$url = add_query_arg( $payload, $url );
			}
			$body = http_build_query( $payload );
		} elseif ( 'json' === $format ) {
			$headers['Content-Type'] = 'application/json; charset=UTF-8';
			$body                    = (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} else {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
			$body                    = http_build_query( $payload );
		}

		if ( '' !== $secretKey ) {
			$timestamp                 = time();
			$signature                 = hash_hmac( 'sha256', $timestamp . '.' . $body, $secretKey );
			$headers['X-HD-Signature'] = sprintf( 't=%d,v1=%s', $timestamp, $signature );
		}

		foreach ( $customHeaders as $k => $v ) {
			if ( is_string( $k ) && is_scalar( $v ) ) {
				$headers[ $k ] = (string) $v;
			}
		}

		$requestArgs = [
			'method'  => $method,
			'timeout' => 15,
			'headers' => $headers,
		];

		if ( 'GET' !== $method ) {
			$requestArgs['body'] = $body;
		}

		$response = wp_safe_remote_request( $url, $requestArgs );

		if ( is_wp_error( $response ) ) {
			$errMsg = $response->get_error_message();

			return [
				'success' => false,
				'message' => 'HTTP request failed: ' . $errMsg,
			];
		}

		$statusCode   = wp_remote_retrieve_response_code( $response );
		$responseBody = wp_remote_retrieve_body( $response );

		if ( $statusCode < 200 || $statusCode >= 300 ) {
			return [
				'success' => false,
				'message' => sprintf( 'Webhook endpoint returned HTTP status %d.', $statusCode ),
				'status'  => $statusCode,
				'body'    => $responseBody,
			];
		}

		return [
			'success' => true,
			'message' => sprintf( 'Webhook delivered successfully (HTTP %d).', $statusCode ),
			'status'  => $statusCode,
			'body'    => $responseBody,
		];
	}

	/**
	 * Test Webhook connection with a ping payload.
	 *
	 * @param string $url       Target Webhook URL.
	 * @param string $method    HTTP Method.
	 * @param string $format    Request format.
	 * @param string $secretKey HMAC Secret Key.
	 *
	 * @return array{success: bool, message: string, status?: int}
	 */
	public static function testConnection(
		string $url,
		string $method = 'POST',
		string $format = 'json',
		string $secretKey = ''
	): array {
		$testPayload = [
			'event'     => 'hd_webhook_test',
			'timestamp' => time(),
			'site_url'  => home_url(),
			'message'   => 'This is a test webhook payload sent from HD Form Engine.',
		];

		return self::sendWebhook( $url, $method, $format, $testPayload, $secretKey );
	}
}
