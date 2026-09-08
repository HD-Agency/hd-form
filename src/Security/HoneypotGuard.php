<?php
/**
 * Honeypot Guard - zero-friction bot detection.
 *
 * @package HDForm\Security
 */

declare(strict_types=1);

namespace HDForm\Security;

defined( 'ABSPATH' ) || exit;

final class HoneypotGuard {
	private const LEGACY_FIELD_NAME = '_hp_field';
	private const FIELD_PREFIX      = '_hp_';
	private const META_NAME         = '_hp_name';
	private const META_TIMESTAMP    = '_hp_ts';
	private const META_SIGNATURE    = '_hp_sig';
	// Six hours: comfortably above any real tab-open duration, short enough
	// that cache-served pages surface as 'expired' (accepted with a log entry)
	// instead of masquerading as fresh traffic for a full day.
	private const TOKEN_MAX_AGE = 21600;

	/**
	 * Build the signed honeypot metadata exposed to frontend form JS.
	 *
	 * @return array{field: string, timestamp: int, signature: string}
	 */
	public static function payload( ?int $timestamp = null ): array {
		$timestamp = $timestamp ?? time();
		$fieldName = self::fieldName( $timestamp );

		return [
			'field'     => $fieldName,
			'timestamp' => $timestamp,
			'signature' => self::signature( $fieldName, $timestamp ),
		];
	}

	/**
	 * Classify the honeypot state of a submission.
	 *
	 * Expired payloads carry a validly signed token from a page older than
	 * TOKEN_MAX_AGE (e.g. served by a full-page cache) — they are authentic
	 * and must not be classified as tampering.
	 *
	 * @param array<string, mixed> $input Raw request payload.
	 *
	 * @return string 'bot', 'expired', or 'clean'.
	 */
	public static function inspect( array $input ): string {
		$fieldName = sanitize_key( (string) ( $input[ self::META_NAME ] ?? '' ) );
		$timestamp = (int) ( $input[ self::META_TIMESTAMP ] ?? 0 );
		$signature = (string) ( $input[ self::META_SIGNATURE ] ?? '' );

		// Signed dynamic honeypot token check.
		if ( '' !== $signature && $timestamp > 0 && '' !== $fieldName ) {
			if ( ! self::isAuthentic( $fieldName, $timestamp, $signature ) ) {
				return 'bot';
			}

			if ( self::isExpired( $timestamp ) ) {
				return 'expired';
			}

			return '' !== trim( (string) ( $input[ $fieldName ] ?? '' ) ) ? 'bot' : 'clean';
		}

		// Fallback / legacy unsigned honeypot check: bot if the hidden field is filled.
		if ( '' !== $fieldName && '' !== trim( (string) ( $input[ $fieldName ] ?? '' ) ) ) {
			return 'bot';
		}

		$legacyValue = $input[ self::LEGACY_FIELD_NAME ] ?? '';

		return '' !== trim( (string) $legacyValue ) ? 'bot' : 'clean';
	}

	/**
	 * Check if the honeypot field was filled or tampered with.
	 *
	 * @param array<string, mixed> $input Raw request payload.
	 */
	public static function isBot( array $input ): bool {
		return 'bot' === self::inspect( $input );
	}

	private static function isAuthentic( string $fieldName, int $timestamp, string $signature ): bool {
		if ( '' === $fieldName || $timestamp <= 0 || '' === $signature ) {
			return false;
		}

		if ( ! str_starts_with( $fieldName, self::FIELD_PREFIX ) ) {
			return false;
		}

		$expected = self::payload( $timestamp );

		return $fieldName === $expected['field']
			&& hash_equals( $expected['signature'], $signature );
	}

	private static function isExpired( int $timestamp ): bool {
		$age = time() - $timestamp;

		return $age < 0 || $age > self::TOKEN_MAX_AGE;
	}

	private static function fieldName( int $timestamp ): string {
		return self::FIELD_PREFIX . substr( wp_hash( 'honeypot_field|' . $timestamp, 'nonce' ), 0, 16 );
	}

	private static function signature( string $fieldName, int $timestamp ): string {
		return wp_hash( 'honeypot_signature|' . $fieldName . '|' . $timestamp, 'nonce' );
	}
}
