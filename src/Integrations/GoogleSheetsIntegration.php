<?php
/**
 * Google Sheets Integration
 *
 * Provides RS256 JWT authentication and Google Sheets API v4 row append functionality.
 *
 * @package HDForm\Integrations
 */

declare(strict_types=1);

namespace HDForm\Integrations;

defined( 'ABSPATH' ) || exit;

final class GoogleSheetsIntegration {
	private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const API_BASE        = 'https://sheets.googleapis.com/v4/spreadsheets/';

	/**
	 * Test access to a Google Sheet spreadsheet by ID.
	 *
	 * @param array  $credentials Parsed Service Account JSON credentials array.
	 * @param string $sheetId     Google Spreadsheet ID.
	 *
	 * @return array{success: bool, message: string, title?: string}
	 */
	public static function testConnection( array $credentials, string $sheetId ): array {
		$token = self::getAccessToken( $credentials );
		if ( ! $token ) {
			return [
				'success' => false,
				'message' => 'Failed to obtain OAuth2 access token. Please check Service Account JSON credentials.',
			];
		}

		$url      = self::API_BASE . rawurlencode( $sheetId );
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		$statusCode = wp_remote_retrieve_response_code( $response );
		$body       = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $statusCode || ! is_array( $body ) ) {
			$errMsg = $body['error']['message'] ?? ( 'HTTP ' . $statusCode );

			return [
				'success' => false,
				'message' => 'Google Sheets API error: ' . $errMsg,
			];
		}

		$title = $body['properties']['title'] ?? 'Untitled Spreadsheet';

		return [
			'success' => true,
			'message' => sprintf( 'Connected successfully to "%s"', $title ),
			'title'   => $title,
		];
	}

	/**
	 * Append a row of data to a Google Sheet using insertDataOption=INSERT_ROWS.
	 *
	 * @param array  $credentials Parsed Service Account credentials.
	 * @param string $sheetId     Spreadsheet ID.
	 * @param string $sheetName   Tab name (e.g. 'Sheet1').
	 * @param array  $values      Ordered array of cell values for the new row.
	 *
	 * @return bool
	 */
	public static function appendRow( array $credentials, string $sheetId, string $sheetName, array $values ): bool {
		if ( empty( $values ) ) {
			return false;
		}

		$token = self::getAccessToken( $credentials );
		if ( ! $token ) {
			return false;
		}

		$options = apply_filters(
			'hd_form_google_sheets_append_options',
			[
				'valueInputOption' => 'USER_ENTERED',
				'insertDataOption' => 'INSERT_ROWS',
			],
			$sheetId,
			$sheetName
		);

		$sheetNameTrimmed = trim( $sheetName, "'" );
		$quotedSheetName  = "'" . str_replace( "'", "''", $sheetNameTrimmed ) . "'";
		$valOpt           = rawurlencode( (string) ( $options['valueInputOption'] ?? 'USER_ENTERED' ) );
		$insOpt           = rawurlencode( (string) ( $options['insertDataOption'] ?? 'INSERT_ROWS' ) );
		$range            = rawurlencode( $quotedSheetName ) . '!A1';
		$url              = self::API_BASE . rawurlencode( $sheetId ) . '/values/' . $range . ':append?valueInputOption=' . $valOpt . '&insertDataOption=' . $insOpt;

		$payload = [
			'majorDimension' => 'ROWS',
			'values'         => [ array_values( $values ) ],
		];

		$response = wp_safe_remote_post(
			$url,
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=UTF-8',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$statusCode = wp_remote_retrieve_response_code( $response );

		return $statusCode >= 200 && $statusCode < 300;
	}

	/**
	 * Generate OAuth2 Bearer Access Token using Service Account RS256 JWT.
	 *
	 * @param array $credentials Parsed Service Account JSON array.
	 *
	 * @return string|null Access Token string or null on failure.
	 */
	public static function getAccessToken( array $credentials ): ?string {
		$clientEmail = $credentials['client_email'] ?? '';
		$privateKey  = $credentials['private_key'] ?? '';

		if ( '' === $clientEmail || '' === $privateKey ) {
			return null;
		}

		$cacheKey = 'hd_gsheet_token_' . md5( $clientEmail );
		$cached   = wp_using_ext_object_cache()
			? wp_cache_get( $cacheKey, 'hd_form' )
			: get_transient( $cacheKey );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$jwt = self::generateJWT( $clientEmail, $privateKey );
		if ( ! $jwt ) {
			return null;
		}

		$response = wp_safe_remote_post(
			self::OAUTH_TOKEN_URL,
			[
				'timeout' => 10,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return null;
		}

		$accessToken = (string) $body['access_token'];
		$expiresIn   = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $cacheKey, $accessToken, 'hd_form', $expiresIn );
		} else {
			set_transient( $cacheKey, $accessToken, $expiresIn );
		}

		return $accessToken;
	}

	/**
	 * Generate RS256 JWT assertion string for Google OAuth2 using OpenSSL.
	 */
	private static function generateJWT( string $clientEmail, string $privateKey ): ?string {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return null;
		}

		$privateKey = str_replace( "\\n", "\n", $privateKey );

		$scope  = (string) apply_filters( 'hd_form_google_sheets_scopes', 'https://www.googleapis.com/auth/spreadsheets', $clientEmail );
		$now    = time();
		$header = [
			'alg' => 'RS256',
			'typ' => 'JWT',
		];
		$claims = [
			'iss'   => $clientEmail,
			'scope' => $scope,
			'aud'   => self::OAUTH_TOKEN_URL,
			'exp'   => $now + 3600,
			'iat'   => $now,
		];

		$encodedHeader = self::base64UrlEncode( (string) wp_json_encode( $header ) );
		$encodedClaims = self::base64UrlEncode( (string) wp_json_encode( $claims ) );
		$toSign        = $encodedHeader . '.' . $encodedClaims;

		$signature = '';
		$success   = openssl_sign( $toSign, $signature, $privateKey, 'SHA256' );
		if ( ! $success ) {
			return null;
		}

		return $toSign . '.' . self::base64UrlEncode( $signature );
	}

	/**
	 * Encode string data per RFC 7515 URL-safe Base64 specification (JWT standard).
	 *
	 * phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	 *
	 * @param string $data Raw data string.
	 *
	 * @return string URL-safe base64 string.
	 */
	private static function base64UrlEncode( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
