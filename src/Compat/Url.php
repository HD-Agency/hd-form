<?php
/**
 * Url Compat — minimal replacement for HD\Support\Url.
 *
 * Implements only the methods used by the Form module classes.
 *
 * @package HDForm\Compat
 */

declare(strict_types=1);

namespace HDForm\Compat;

defined( 'ABSPATH' ) || exit;

final class Url {

	/**
	 * Get the site home URL.
	 *
	 * @param string      $path   Optional path to append.
	 * @param string|null $scheme URL scheme.
	 *
	 * @return string
	 */
	public static function home( string $path = '', ?string $scheme = null ): string {
		return esc_url( home_url( $path, $scheme ) );
	}

	/**
	 * Get the real client IP address.
	 *
	 * Trusts proxy headers only when REMOTE_ADDR is in the trusted proxy list
	 * defined via the 'hd_trusted_proxies' filter.
	 *
	 * @return string Client IP address.
	 */
	public static function ipAddress(): string {
		$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! filter_var( $remoteAddr, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}

		/** @var string[] $trustedProxies */
		$trustedProxies = (array) apply_filters( 'hd_trusted_proxies', [] );

		if ( $trustedProxies && self::ipInRanges( $remoteAddr, $trustedProxies ) ) {
			$headers = [
				'HTTP_CF_CONNECTING_IP',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_REAL_IP',
				'HTTP_CLIENT_IP',
			];

			foreach ( $headers as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					continue;
				}

				$ip = trim( explode( ',', $_SERVER[ $header ] )[0] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return apply_filters( 'hd_client_ip_filter', $ip, $remoteAddr );
				}
			}
		}

		return apply_filters( 'hd_client_ip_filter', $remoteAddr, $remoteAddr );
	}

	/**
	 * Check if an IP matches any CIDR range or exact IP in the list.
	 *
	 * Dual-stack: handles IPv4 and IPv6 addresses/ranges. An entry whose
	 * family differs from the checked IP simply never matches.
	 *
	 * @param string   $ip     IP address to check.
	 * @param string[] $ranges Array of IPs or CIDR ranges.
	 *
	 * @return bool
	 */
	public static function ipInRanges( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::ipInRange( $ip, trim( (string) $range ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match one IP against a single IP or CIDR range.
	 *
	 * Uses inet_pton plus byte-level comparison, so results are identical on
	 * 32- and 64-bit platforms and no integer shifts wrap.
	 *
	 * @param string $ip    IP address to check.
	 * @param string $range Exact IP or CIDR range.
	 */
	private static function ipInRange( string $ip, string $range ): bool {
		if ( '' === $ip || '' === $range ) {
			return false;
		}

		// Address families must agree.
		if ( str_contains( $ip, ':' ) !== str_contains( $range, ':' ) ) {
			return false;
		}

		if ( ! str_contains( $range, '/' ) ) {
			$normalizedIp      = self::normalizeIp( $ip );
			$normalizedAddress = self::normalizeIp( $range );

			return null !== $normalizedIp && $normalizedIp === $normalizedAddress;
		}

		[ $subnet, $bits ] = explode( '/', $range, 2 );

		if ( ! preg_match( '/^\d{1,3}$/', $bits ) ) {
			return false;
		}
		$bits = (int) $bits;

		$isIpv6    = str_contains( $subnet, ':' );
		$maxPrefix = $isIpv6 ? 128 : 32;
		if ( $bits < 1 || $bits > $maxPrefix ) {
			return false;
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) || false === filter_var( $subnet, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$ipBinary     = inet_pton( strtolower( $ip ) );
		$subnetBinary = inet_pton( strtolower( $subnet ) );

		if ( false === $ipBinary || false === $subnetBinary
			|| strlen( $ipBinary ) !== strlen( $subnetBinary )
		) {
			return false;
		}

		// Whole-byte prefix.
		$prefixBytes = intdiv( $bits, 8 );
		if (
			$prefixBytes > 0
			&& substr_compare( (string) $ipBinary, (string) $subnetBinary, 0, $prefixBytes ) !== 0
		) {
			return false;
		}

		// Remaining high bits within the next byte.
		$remainderBits = $bits % 8;
		if ( 0 === $remainderBits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $remainderBits ) ) & 0xFF;

		return ( ( ord( $ipBinary[ $prefixBytes ] ) ^ ord( $subnetBinary[ $prefixBytes ] ) ) & $mask ) === 0;
	}

	/**
	 * Canonical textual form of an IP, or null when invalid.
	 */
	private static function normalizeIp( string $ip ): ?string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$binary = inet_pton( strtolower( $ip ) );

		return false === $binary ? null : (string) inet_ntop( $binary );
	}
}
