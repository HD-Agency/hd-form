<?php
/**
 * Entry count cache — shared transient layer for admin view counts.
 *
 * Both the menu badge (unread entries) and the list-table pagination totals
 * are COUNT(*) queries; this caches them briefly and exposes a single flush
 * point so mutation paths can invalidate every derived counter at once.
 *
 * @package HDForm\Repository
 */

declare(strict_types=1);

namespace HDForm\Repository;

defined( 'ABSPATH' ) || exit;

final class EntryCountCache {
	public const UNREAD_TRANSIENT  = 'hd_form_unread_count';
	private const COUNTS_TRANSIENT = 'hd_form_view_counts';
	private const TTL              = 5 * MINUTE_IN_SECONDS;

	/**
	 * Unread ("new") entry total used for the admin menu badge.
	 */
	public static function unread( FormEntryRepository $repo ): int {
		$cached = get_transient( self::UNREAD_TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = $repo->countAll( [ 'status' => 'new' ] );
		set_transient( self::UNREAD_TRANSIENT, $count, self::TTL );

		return $count;
	}

	/**
	 * Cached pagination total for the given list filters.
	 *
	 * Search-filtered totals are high-cardinality and never cached.
	 *
	 * @param FormEntryRepository $repo    Repository performing the count.
	 * @param array               $filters List filters ('status', 'form_type', 'search').
	 */
	public static function filteredTotal( FormEntryRepository $repo, array $filters ): int {
		if ( ! empty( $filters['search'] ) ) {
			return $repo->countAll( $filters );
		}

		$map = get_transient( self::COUNTS_TRANSIENT );
		$map = is_array( $map ) ? $map : [];
		$key = md5( wp_json_encode( $filters ) ?: '' );

		if ( isset( $map[ $key ][0], $map[ $key ][1] )
			&& is_int( $map[ $key ][1] )
			&& $map[ $key ][1] > time()
		) {
			return (int) $map[ $key ][0];
		}

		$count       = $repo->countAll( $filters );
		$map[ $key ] = [ $count, time() + self::TTL ];
		$map         = array_slice( $map, -25, null, true ); // Bound growth.
		set_transient( self::COUNTS_TRANSIENT, $map, self::TTL );

		return $count;
	}

	/**
	 * Invalidate every cached counter (entry insert / status change / delete).
	 */
	public static function flush(): void {
		delete_transient( self::UNREAD_TRANSIENT );
		delete_transient( self::COUNTS_TRANSIENT );
	}
}
