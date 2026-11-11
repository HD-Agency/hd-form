<?php
/**
 * RateLimitStorage Compat — minimal replacement for HD\Core\RateLimitStorage.
 *
 * Uses object cache (Redis/Memcached) when available.
 * Falls back to hd_rate_limits table if it exists (shared with theme),
 * or to transients.
 *
 * @package HDForm\Compat
 */

declare(strict_types=1);

namespace HDForm\Compat;

defined( 'ABSPATH' ) || exit;

final class RateLimitStorage {

	public const TABLE_NAME = 'hde_rate_limits';

	private const CACHE_GROUP = 'hdf_rl';

	/**
	 * Increment a counter for an IP and action.
	 *
	 * @param string $ip            Client IP address.
	 * @param string $action        Action identifier.
	 * @param int    $windowSeconds Expiration window in seconds.
	 *
	 * @return int Current hit count after increment.
	 */
	public static function increment( string $ip, string $action, int $windowSeconds ): int {
		if ( wp_using_ext_object_cache() ) {
			return self::extCacheIncrement( self::transientKey( $ip, $action ), $windowSeconds );
		}

		// Try the shared DB table (may exist if themes/hd is also installed).
		if ( self::tableExists() ) {
			return self::dbIncrement( $ip, $action, $windowSeconds );
		}

		// Transient fallback.
		$key   = self::transientKey( $ip, $action );
		$count = (int) get_transient( $key );
		++$count;
		set_transient( $key, $count, $windowSeconds );

		return $count;
	}

	// -------------------------------------------------------------------------

	/**
	 * Atomic hit counter on the external object cache.
	 *
	 * Seeds the key with wp_cache_add() — exactly one concurrent request wins
	 * the insert — then counts with wp_cache_incr(), which Redis/Memcached
	 * execute atomically server-side. A backend whose incr() cannot report
	 * success falls back to the previous read-modify-write behaviour.
	 *
	 * @param string $key            Counter key.
	 * @param int    $windowSeconds  Window length in seconds.
	 *
	 * @return int Current hit count after increment.
	 */
	private static function extCacheIncrement( string $key, int $windowSeconds ): int {
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			// Store 0 = "no hits counted yet"; winning this insert is hit #1.
			if ( wp_cache_add( $key, 0, self::CACHE_GROUP, $windowSeconds ) ) {
				return 1;
			}

			$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );
			if ( is_int( $count ) && $count >= 1 ) {
				if ( 1 === $count ) {
					// Ambiguous: either our seeded 0 was bumped, or the backend
					// created the key without a TTL. Re-write with a TTL so the
					// window can never become immortal.
					wp_cache_set( $key, 1, self::CACHE_GROUP, $windowSeconds );
				}

				return $count + 1;
			}
		}

		// Non-atomic incr backend: degrade gracefully instead of blocking.
		$count = (int) wp_cache_get( $key, self::CACHE_GROUP ) + 1;
		wp_cache_set( $key, $count, self::CACHE_GROUP, $windowSeconds );

		return $count;
	}

	private static function dbIncrement( string $ip, string $action, int $windowSeconds ): int {
		global $wpdb;

		$ipBin = inet_pton( $ip );

		if ( false === $ipBin ) {
			return 0;
		}

		$table   = DB::tableNameFull( self::TABLE_NAME );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $windowSeconds );

		// Atomic single-query upsert counter.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO `{$table}` (ip_address, action, hits, data, expires_at)
				 VALUES (%s, %s, LAST_INSERT_ID(1), '', %s)
				 ON DUPLICATE KEY UPDATE
				   hits       = LAST_INSERT_ID(IF(expires_at < UTC_TIMESTAMP(), 1, hits + 1)),
				   expires_at = VALUES(expires_at)",
				$ipBin,
				$action,
				$expires
			)
		);

		return (int) $wpdb->insert_id;
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

	private static function transientKey( string $ip, string $action ): string {
		return 'hdf_rl_' . substr( md5( $action . '_' . $ip ), 0, 16 );
	}
}
