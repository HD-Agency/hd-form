<?php
/**
 * Str Compat — minimal replacement for HD\Support\Str.
 *
 * Implements only the methods used by the Form module classes.
 *
 * @package HDForm\Compat
 */

declare(strict_types=1);

namespace HDForm\Compat;

defined( 'ABSPATH' ) || exit;

final class Str {

	/**
	 * Validate a Vietnamese phone number.
	 *
	 * Accepts formats: 0xxxxxxxxx, +84xxxxxxxxx, 84xxxxxxxxx
	 * Covers Viettel, Vinaphone, Mobifone, Vietnamobile, Gmobile, Reddi.
	 *
	 * @param string $phone Phone number to validate.
	 *
	 * @return bool True if valid Vietnamese phone number.
	 */
	public static function isValidPhone( string $phone ): bool {
		// Strip spaces, dashes, dots, and grouping parentheses — mirrors the
		// international validator so formatted input passes both paths.
		$phone = preg_replace( '/[\s\-.()]/', '', $phone );

		if ( ! is_string( $phone ) ) {
			return false;
		}

		// Normalize: convert +84 or 84 prefix to 0.
		if ( str_starts_with( $phone, '+84' ) ) {
			$phone = '0' . substr( $phone, 3 );
		} elseif ( str_starts_with( $phone, '84' ) && strlen( $phone ) >= 11 ) {
			$phone = '0' . substr( $phone, 2 );
		}

		// Must be 10 digits starting with 0.
		return (bool) preg_match(
			'/^(0)(3[2-9]|5[25689]|7[06-9]|8[0-9]|9[0-9])[0-9]{7}$/',
			$phone
		);
	}
}
