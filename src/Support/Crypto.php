<?php
/**
 * Symmetric authenticated encryption utility.
 *
 * Uses Sodium XChaCha20-Poly1305 (`sodium_crypto_secretbox`) with
 * BLAKE2b key derivation from WP salts. Provides a single encrypt/decrypt
 * API for any module that needs to store secrets in the database.
 *
 * CRYPTO_CONTEXT must never change or all stored ciphertext breaks.
 *
 * @package HDForm\Support
 */

declare(strict_types=1);

namespace HDForm\Support;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	private const CRYPTO_CONTEXT = 'hdf_plugin_encryption_v1';

	/**
	 * Encrypt a plaintext string for storage.
	 *
	 * Output format: base64( nonce(24) + ciphertext + MAC(16) )
	 *
	 * @param string $value Plaintext value to encrypt.
	 * @return string Encrypted value (base64) or empty string on failure.
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$key = '';
		try {
			$key    = self::deriveKey();
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $value, $nonce, $key );

			return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( \Throwable ) {
			return '';
		} finally {
			if ( '' !== $key && extension_loaded( 'sodium' ) && function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}
		}
	}

	/**
	 * Decrypt an encrypted string from storage.
	 *
	 * Returns empty string if MAC verification fails (tampered data).
	 *
	 * @param string $stored Encrypted value (base64) from DB.
	 * @return string Plaintext value or empty string on failure.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		$key = '';
		try {
			$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw ) {
				return '';
			}

			$minLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
			if ( strlen( $raw ) <= $minLen ) {
				return '';
			}

			$key    = self::deriveKey();
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

			return false !== $plain ? $plain : '';
		} catch ( \Throwable ) {
			return '';
		} finally {
			if ( '' !== $key && extension_loaded( 'sodium' ) && function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}
		}
	}

	/**
	 * Derive a 32-byte key from WP salts via BLAKE2b.
	 *
	 * @throws \SodiumException
	 */
	private static function deriveKey(): string {
		$salt = ( defined( 'SECURE_AUTH_KEY' ) ? \SECURE_AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? \AUTH_SALT : '' );

		return sodium_crypto_generichash(
			$salt,
			self::CRYPTO_CONTEXT,
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}
}
