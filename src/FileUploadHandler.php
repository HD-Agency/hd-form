<?php
/**
 * File Upload Handler.
 *
 * Processes uploaded files from form submissions and stores them
 * in the WordPress uploads directory.
 *
 * @package HD\Modules\Form
 */

declare(strict_types=1);

namespace HDForm;

use HDForm\Compat\Helper;

defined( 'ABSPATH' ) || exit;

final class FileUploadHandler {

	/**
	 * Move valid uploaded files into WordPress uploads and preserve per-field diagnostics.
	 *
	 * @param array<string, mixed> $files          $_FILES-style payload.
	 * @param callable|null       $isUploadedFile Optional upload-origin checker for tests.
	 * @param callable|null       $uploadHandler  Optional upload handler for tests.
	 *
	 * @return array<string, string>|\WP_Error
	 */
	public function store( array $files, ?callable $isUploadedFile = null, ?callable $uploadHandler = null ): array|\WP_Error {
		if ( null === $uploadHandler && ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$isUploadedFile ??= static fn( string $tmpName ): bool => is_uploaded_file( $tmpName );
		$uploadHandler  ??= static fn( array $file ): array => wp_handle_upload(
			$file,
			[
				'test_form'                => false,
				// Submitter-controlled names must not reach the public uploads dir.
				'unique_filename_callback' => [ self::class, 'randomFilename' ],
			]
		);

		$uploadedFiles = [];
		$fieldErrors   = [];
		$diagnostics   = [];

		foreach ( $files as $key => $file ) {
			$fieldKey = FormValidator::uploadFieldKey( $key );

			if ( ! is_array( $file ) ) {
				$fieldErrors[ $fieldKey ] = __( 'Invalid upload payload.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [ 'reason' => 'invalid_shape' ];
				continue;
			}

			$errorCode = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
			if ( UPLOAD_ERR_NO_FILE === $errorCode ) {
				self::logDrop( $fieldKey, 'no_file' );
				continue;
			}

			if ( UPLOAD_ERR_OK !== $errorCode ) {
				$fieldErrors[ $fieldKey ] = __( 'File upload error. Please try again.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [
					'reason' => 'php_upload_error',
					'code'   => $errorCode,
				];
				continue;
			}

			$tmpName = (string) ( $file['tmp_name'] ?? '' );
			if ( '' === $tmpName ) {
				$fieldErrors[ $fieldKey ] = __( 'Invalid upload payload.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [ 'reason' => 'missing_tmp_name' ];
				continue;
			}

			if ( ! $isUploadedFile( $tmpName ) ) {
				self::logDrop( $fieldKey, 'not_uploaded_file' );
				continue;
			}

			$upload = $uploadHandler( $file );
			if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
				$message                  = is_array( $upload ) ? (string) ( $upload['error'] ?? '' ) : '';
				$fieldErrors[ $fieldKey ] = $message ?: __( 'File upload error. Please try again.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [
					'reason' => 'wp_handle_upload_failed',
					'error'  => $message,
				];
				continue;
			}

			$url = sanitize_url( (string) ( $upload['url'] ?? '' ) );
			if ( '' === $url ) {
				$fieldErrors[ $fieldKey ] = __( 'File upload error. Please try again.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [ 'reason' => 'missing_upload_url' ];
				continue;
			}

			$uploadedFiles[ $fieldKey ] = $url;
		}

		if ( $fieldErrors ) {
			return new \WP_Error(
				'upload_failed',
				__( 'Please fix uploaded files.', 'hd-form' ),
				[
					'status'        => 422,
					'fields'        => $fieldErrors,
					'upload_errors' => $diagnostics,
				]
			);
		}

		return $uploadedFiles;
	}

	/**
	 * Log a silently dropped upload field for diagnostics.
	 *
	 * @param string $fieldKey Upload field key.
	 * @param string $reason   Drop reason.
	 */
	private static function logDrop( string $fieldKey, string $reason ): void {
		Helper::errorLog( sprintf( '[FileUploadHandler] Dropped upload field "%s": %s', $fieldKey, $reason ) );
	}

	/**
	 * Random upload filename callback (wp_handle_upload).
	 *
	 * Discards the submitter-controlled stem; keeps the validated extension.
	 *
	 * @param string $dir          Target directory (unused).
	 * @param string $originalName Original filename without extension (unused).
	 * @param string $ext          Extension including the leading dot, when known.
	 */
	public static function randomFilename( string $dir, string $originalName, string $ext = '' ): string {
		unset( $dir, $originalName );

		return wp_generate_password( 24, false, false ) . strtolower( $ext );
	}

	/**
	 * Map a stored upload URL to its absolute path under the uploads directory.
	 *
	 * Returns null for URLs outside the current uploads baseurl or containing
	 * traversal segments — those are never legitimate plugin uploads.
	 *
	 * @param string $url Stored upload URL.
	 */
	public static function urlToPath( string $url ): ?string {
		static $base = null;
		if ( null === $base ) {
			$uploads = wp_upload_dir();
			$base    = [
				'url' => rtrim( sanitize_url( (string) ( $uploads['baseurl'] ?? '' ) ), '/' ),
				'dir' => rtrim( (string) ( $uploads['basedir'] ?? '' ), '/' ),
			];
		}

		if ( '' === $base['url'] || '' === $base['dir'] ) {
			return null;
		}

		if ( ! str_starts_with( $url, $base['url'] . '/' ) ) {
			return null;
		}

		$relative = rawurldecode( substr( $url, strlen( $base['url'] ) + 1 ) );
		if (
			'' === $relative
			|| str_contains( $relative, '..' )
			|| str_contains( $relative, '\\' )
			|| str_contains( $relative, "\0" )
		) {
			return null;
		}

		$path = $base['dir'] . '/' . $relative;

		return is_file( $path ) ? $path : null;
	}
}
