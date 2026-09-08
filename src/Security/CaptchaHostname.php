<?php
/**
 * CAPTCHA hostname verification.
 *
 * Single shared implementation used by every provider guard: accepts the
 * site's own hostname, its www/apex twin, any subdomain of the same
 * registrable domain, and extra hostnames added via the
 * `hd_form_captcha_hostnames` filter (e.g. staging mirrors).
 *
 * @package HDForm\Security
 */

declare(strict_types=1);

namespace HDForm\Security;

defined( 'ABSPATH' ) || exit;

final class CaptchaHostname {

	/**
	 * Common two-part public suffixes for the lightweight registrable-domain
	 * heuristic. Full PSL accuracy is unnecessary because the filter above
	 * covers exotic cases.
	 */
	private const MULTI_PART_SUFFIXES = [
		'co.uk',
		'org.uk',
		'ac.uk',
		'gov.uk',
		'co.jp',
		'co.kr',
		'co.in',
		'co.id',
		'com.au',
		'net.au',
		'org.au',
		'co.nz',
		'com.br',
		'com.vn',
		'net.vn',
		'org.vn',
		'edu.vn',
		'gov.vn',
	];

	/**
	 * Whether a provider-reported hostname belongs to this site.
	 *
	 * @param mixed $hostname Hostname reported by the CAPTCHA provider.
	 */
	public static function verify( mixed $hostname ): bool {
		$actual   = strtolower( rtrim( (string) $hostname, '.' ) );
		$expected = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		if ( '' === $expected || '' === $actual ) {
			return false;
		}

		// Explicit allow-list wins first.
		$extraHostnames = apply_filters( 'hd_form_captcha_hostnames', [] );
		if ( is_array( $extraHostnames ) ) {
			foreach ( $extraHostnames as $candidate ) {
				$candidate = strtolower( rtrim( (string) $candidate, '.' ) );

				if ( '' !== $candidate && $candidate === $actual ) {
					return true;
				}
			}
		}

		if ( $actual === $expected ) {
			return true;
		}

		// www.example.com vs example.com equivalence.
		if ( self::stripWww( $actual ) === self::stripWww( $expected ) ) {
			return true;
		}

		// Any subdomain sharing the expected host's registrable domain.
		$registrable = self::registrableDomain( self::stripWww( $expected ) );

		return '' !== $registrable && str_ends_with( $actual, '.' . $registrable );
	}

	private static function stripWww( string $host ): string {
		$stripped = preg_replace( '/^www\./i', '', $host );

		return is_string( $stripped ) ? $stripped : $host;
	}

	/**
	 * Lightweight registrable domain: last two labels, extended to three for
	 * known two-part suffixes. Returns '' when the host has no parent domain.
	 */
	private static function registrableDomain( string $host ): string {
		$labels = explode( '.', strtolower( $host ) );
		$count  = count( $labels );

		if ( $count < 2 ) {
			return '';
		}

		$lastTwo = implode( '.', array_slice( $labels, -2 ) );

		if ( in_array( $lastTwo, self::MULTI_PART_SUFFIXES, true ) && $count >= 3 ) {
			return implode( '.', array_slice( $labels, -3 ) );
		}

		return $lastTwo;
	}
}
