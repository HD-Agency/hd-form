<?php
/**
 * ClaimStorage Compat — minimal replacement for HD\Core\ClaimStorage.
 *
 * Atomic ephemeral claims using wp_cache_add() when object cache is available.
 * The DB path from the theme requires an extra table (hd_claims) — we reuse that
 * table if it exists, otherwise claims become unique-key option rows via
 * add_option(), which are atomic at the database level.
 *
 * @package HDForm\Compat
 */

declare(strict_types=1);

namespace HDForm\Compat;

defined( 'ABSPATH' ) || exit;

final class ClaimStorage {

	public const TABLE_NAME = 'hde_claims';

	private const CACHE_GROUP    = 'hde_claims';
	private const MAX_KEY_LENGTH = 128;

	/**
	 * Atomically claim a key.
	 *
	 * @param string $key        Unique claim key (max 128 chars).
	 * @param int    $ttlSeconds Claim lifetime in seconds.
	 * @param string $data       Optional data (max 255 chars).
	 *
	 * @return bool True if claim acquired, false if already active.
	 */
	public static function claim( string $key, int $ttlSeconds, string $data = '' ): bool {
		$safeKey = self::normalizeKey( $key );

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $safeKey, $data ?: '1', self::CACHE_GROUP, $ttlSeconds );
		}

		// DB path: use hd_claims table if available, else option-row fallback.
		if ( self::tableExists() ) {
			return self::dbClaim( $safeKey, $ttlSeconds, $data );
		}

		return self::optionRowClaim( $ttlSeconds, self::optionKey( $safeKey ) );
	}

	/**
	 * Release a claim.
	 *
	 * @param string $key Claim key to release.
	 */
	public static function release( string $key ): void {
		$safeKey = self::normalizeKey( $key );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $safeKey, self::CACHE_GROUP );

			return;
		}

		if ( self::tableExists() ) {
			global $wpdb;
			$table = DB::tableNameFull( self::TABLE_NAME );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->delete( $table, [ 'claim_key' => $safeKey ], [ '%s' ] );

			return;
		}

		delete_option( self::optionKey( $safeKey ) );
	}

	// -------------------------------------------------------------------------

	/**
	 * Atomic claim without an object cache or claims table.
	 *
	 * add_option() is a unique-key INSERT at the DB level, so concurrent
	 * claimants cannot both win — unlike the previous get/set transient
	 * check-then-set. The stored value is an absolute expiry timestamp;
	 * stale rows are cleared and retried once.
	 *
	 * @param int    $ttlSeconds Claim lifetime in seconds.
	 * @param string $optionKey  Storage key.
	 */
	private static function optionRowClaim( int $ttlSeconds, string $optionKey ): bool {
		$expiresAt = time() + max( 1, $ttlSeconds );

		if ( add_option( $optionKey, $expiresAt, '', false ) ) {
			return true;
		}

		// Key taken — is it still alive?
		if ( (int) get_option( $optionKey ) > time() ) {
			return false;
		}

		// Expired claim: clear and retry once.
		delete_option( $optionKey );

		return add_option( $optionKey, $expiresAt, '', false );
	}

	private static function dbClaim( string $safeKey, int $ttlSeconds, string $data ): bool {
		$result = DB::upsert(
			self::TABLE_NAME,
			[
				'claim_key'  => $safeKey,
				'data'       => mb_substr( $data, 0, 255 ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttlSeconds ),
			],
			[
				'data'       => 'IF(expires_at < UTC_TIMESTAMP(), VALUES(data), data)',
				'expires_at' => 'IF(expires_at < UTC_TIMESTAMP(), VALUES(expires_at), expires_at)',
			]
		);

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return $result > 0;
	}

	private static function tableExists(): bool {
		global $wpdb;
		static $exists = null;

		if ( null === $exists ) {
			$table  = DB::tableNameFull( self::TABLE_NAME );
			$exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ); // phpcs:ignore
		}

		return $exists;
	}

	private static function normalizeKey( string $key ): string {
		return mb_substr( $key, 0, self::MAX_KEY_LENGTH );
	}

	private static function optionKey( string $key ): string {
		return 'hdf_claim_' . substr( md5( $key ), 0, 20 );
	}
}
