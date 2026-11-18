<?php
/**
 * Duplicate Submission Guard.
 *
 * Detects and prevents duplicate form submissions using
 * time-windowed hashing and atomic ClaimStorage claims.
 *
 * @package HDForm\Security
 */

declare(strict_types=1);

namespace HDForm\Security;

use HDForm\Compat\Helper;
use HDForm\Compat\ClaimStorage;
use HDForm\Repository\FormLogRepository;

defined( 'ABSPATH' ) || exit;

final class DuplicateGuard {

	/**
	 * Create a time-windowed hash for duplicate detection.
	 *
	 * Combines IP + form type + selected fields + time tick.
	 * Same data within the same window produces the same hash.
	 *
	 * @param string $ip            Client IP.
	 * @param string $formType      Form type slug.
	 * @param array  $validated     Validated input data.
	 * @param string $checkMode     Field mode: 'email_phone', 'email_only', 'phone_only', 'all_fields'.
	 * @param int    $windowMinutes Window duration in minutes.
	 *
	 * @return string Hash string.
	 */
	public static function hash( string $ip, string $formType, array $validated, string $checkMode = 'email_phone', int $windowMinutes = 30 ): string {
		$parts = [ $ip, $formType ];

		if ( 'email_only' === $checkMode ) {
			$parts[] = $validated['email'] ?? '';
		} elseif ( 'phone_only' === $checkMode ) {
			$parts[] = $validated['phone'] ?? '';
		} elseif ( 'all_fields' === $checkMode ) {
			$parts[] = $validated['name'] ?? '';
			$parts[] = $validated['email'] ?? '';
			$parts[] = $validated['phone'] ?? '';

			$fields = $validated['fields'] ?? [];
			ksort( $fields );
			foreach ( $fields as $k => $v ) {
				$parts[] = $k . '=' . ( is_array( $v ) ? (string) wp_json_encode( $v ) : (string) $v );
			}
		} else {
			// Default 'email_phone' mode (fallback to name when both email and phone are empty).
			$email   = (string) ( $validated['email'] ?? '' );
			$phone   = (string) ( $validated['phone'] ?? '' );
			$parts[] = $email;
			$parts[] = $phone;
			if ( '' === $email && '' === $phone ) {
				$parts[] = (string) ( $validated['name'] ?? '' );
			}
		}

		// Time-windowing (tick duration based on windowMinutes).
		$tickWindow = max( MINUTE_IN_SECONDS, ( $windowMinutes * MINUTE_IN_SECONDS ) );
		$parts[]    = (string) floor( time() / $tickWindow );

		return wp_hash( implode( '|', $parts ), 'nonce' );
	}

	/**
	 * Atomically claim the duplicate-submission window before persistence.
	 *
	 * Uses ClaimStorage::claim() for atomic, single-query claim.
	 * Returns true if this is the first submission with this key.
	 *
	 * @param string $claimKey      Claim key derived from submission hash.
	 * @param int    $windowMinutes Claim duration in minutes.
	 *
	 * @return bool True if the claim was acquired (not a duplicate).
	 */
	public static function claim( string $claimKey, int $windowMinutes = 30 ): bool {
		$ttl = max( MINUTE_IN_SECONDS, $windowMinutes * MINUTE_IN_SECONDS );

		return ClaimStorage::claim( $claimKey, $ttl );
	}

	/**
	 * Release a duplicate claim when persistence fails.
	 *
	 * @param string $claimKey Claim key to release.
	 */
	public static function release( string $claimKey ): void {
		if ( '' === $claimKey ) {
			return;
		}

		ClaimStorage::release( $claimKey );
	}

	/**
	 * Record silently dropped duplicate submissions for admin diagnostics.
	 *
	 * @param string $formType       Form type slug.
	 * @param array  $validated      Validated form payload.
	 * @param string $submissionHash Hash of the duplicate submission.
	 * @param string $ip             Client IP.
	 */
	public static function logDrop( string $formType, array $validated, string $submissionHash, string $ip ): void {
		try {
			( new FormLogRepository() )->log(
				0,
				'duplicate_dropped',
				'Duplicate form submission dropped.',
				[
					'form_type'       => $formType,
					'form_id'         => (string) ( $validated['formId'] ?? '' ),
					'submission_hash' => $submissionHash,
				],
				'system',
				$ip
			);
		} catch ( \Throwable $e ) {
			Helper::errorLog( '[DuplicateGuard] Failed to log duplicate drop: ' . $e->getMessage() );
		}
	}

	/**
	 * Build the claim key from a submission hash.
	 *
	 * @param string $hash Submission hash.
	 *
	 * @return string Claim key (42 chars, fits varchar(128)).
	 */
	public static function claimKey( string $hash ): string {
		return 'form_dupe_' . $hash;
	}
}
