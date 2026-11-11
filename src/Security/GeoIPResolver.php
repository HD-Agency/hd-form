<?php
/**
 * GeoIP Resolver
 *
 * Resolves visitor IP to country data via a filter hook.
 * The theme itself has NO dependency on MaxMind or any GeoIP library.
 *
 * External plugins (e.g., HDA) hook into `hd_form_geoip_resolve`
 * to provide the actual geo data. If no plugin provides data,
 * the resolver gracefully returns null.
 *
 * @package HDForm\Security
 */

declare(strict_types=1);

namespace HDForm\Security;

defined( 'ABSPATH' ) || exit;

final class GeoIPResolver {

	/**
	 * Resolve IP address to geo data.
	 *
	 * Delegates to external providers via `hd_form_geoip_resolve` filter.
	 * Returns null if no provider is available.
	 *
	 * @param string $ip Client IP address.
	 *
	 * @return array{country: string, country_name: string}|null Null if unavailable.
	 */
	public static function resolve( string $ip ): ?array {
		// Skip local/private IPs.
		if ( ! $ip || in_array( $ip, [ '127.0.0.1', '::1' ], true ) ) {
			return null;
		}

		// Check object cache first.
		$cacheKey = 'hd_geoip_' . md5( $ip );
		$cached   = wp_cache_get( $cacheKey, 'hd_form' );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		/**
		 * Filter: resolve GeoIP data for an IP address.
		 *
		 * External plugins should hook here to provide geo data.
		 * Expected return: ['country' => 'VN', 'country_name' => 'Vietnam'] or null.
		 */
		$geo = apply_filters( 'hd_form_geoip_resolve', null, $ip );

		if ( is_array( $geo ) && ! empty( $geo['country'] ) ) {
			wp_cache_set( $cacheKey, $geo, 'hd_form', HOUR_IN_SECONDS );

			return $geo;
		}

		// Cache negative result to avoid repeated filter calls.
		wp_cache_set( $cacheKey, [], 'hd_form', HOUR_IN_SECONDS );

		return null;
	}
}
