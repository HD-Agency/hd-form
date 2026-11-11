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

		$allowedMimes = (array) apply_filters(
			'hd_form_allowed_mimes',
			[
				'pdf'  => 'application/pdf',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'zip'  => 'application/zip',
				'rar'  => 'application/x-rar-compressed',
				'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'xls'  => 'application/vnd.ms-excel',
				'csv'  => 'text/csv',
			]
		);

		$isUploadedFile ??= static fn( string $tmpName ): bool => is_uploaded_file( $tmpName );
		$uploadHandler  ??= static fn( array $file ): array => wp_handle_upload(
			$file,
			[
				'test_form'                => false,
				'mimes'                    => $allowedMimes,
				'unique_filename_callback' => [ self::class, 'filterFilename' ],
			]
		);

		$allUploadedUrls    = [];
		$uploadedFilesMap   = [];
		$fieldErrors        = [];
		$diagnostics        = [];
		$maxUploadFiles     = FormValidator::maxUploadFiles();
		$allowMultiple      = FormConfig::isAllowMultipleUploadsEnabled();
		$totalUploadedFiles = 0;

		foreach ( $files as $key => $rawFile ) {
			$fieldKey = FormValidator::uploadFieldKey( $key );

			if ( ! is_array( $rawFile ) ) {
				$fieldErrors[ $fieldKey ] = __( 'Invalid upload payload.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [ 'reason' => 'invalid_shape' ];
				continue;
			}

			$fileList  = self::normalizeFileItem( $rawFile );
			$fieldUrls = [];

			foreach ( $fileList as $file ) {
				$errorCode = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
				if ( UPLOAD_ERR_NO_FILE === $errorCode ) {
					self::logDrop( $fieldKey, 'no_file' );
					continue;
				}

				++$totalUploadedFiles;

				if ( $totalUploadedFiles > 1 && ! $allowMultiple ) {
					$fieldErrors[ $fieldKey ] = __( 'Multiple file upload is not allowed.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [ 'reason' => 'multiple_not_allowed' ];
					break;
				}

				if ( $totalUploadedFiles > $maxUploadFiles ) {
					$fieldErrors[ $fieldKey ] = __( 'Too many uploaded files.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [
						'reason' => 'max_files_exceeded',
						'limit'  => $maxUploadFiles,
					];
					break;
				}

				if ( UPLOAD_ERR_OK !== $errorCode ) {
					$fieldErrors[ $fieldKey ] = __( 'File upload error. Please try again.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [
						'reason' => 'php_upload_error',
						'code'   => $errorCode,
					];
					break;
				}

				$tmpName = (string) ( $file['tmp_name'] ?? '' );
				if ( '' === $tmpName ) {
					$fieldErrors[ $fieldKey ] = __( 'Invalid upload payload.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [ 'reason' => 'missing_tmp_name' ];
					break;
				}

				if ( ! $isUploadedFile( $tmpName ) ) {
					self::logDrop( $fieldKey, 'not_uploaded_file' );
					$fieldErrors[ $fieldKey ] = __( 'Invalid upload payload.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [ 'reason' => 'not_uploaded_file' ];
					break;
				}

				$upload = $uploadHandler( $file );
				if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
					$message                  = is_array( $upload ) ? (string) ( $upload['error'] ?? '' ) : '';
					$fieldErrors[ $fieldKey ] = $message ?: __( 'File upload error. Please try again.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [
						'reason' => 'wp_handle_upload_failed',
						'error'  => $message,
					];
					break;
				}

				$url = sanitize_url( (string) ( $upload['url'] ?? '' ) );
				if ( '' === $url ) {
					$fieldErrors[ $fieldKey ] = __( 'File upload error. Please try again.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [ 'reason' => 'missing_upload_url' ];
					break;
				}

				$fieldUrls[]       = $url;
				$allUploadedUrls[] = $url;
			}

			if ( ! empty( $fieldUrls ) ) {
				$uploadedFilesMap[ $fieldKey ] = 1 === count( $fieldUrls ) ? $fieldUrls[0] : implode( "\n", $fieldUrls );
			}
		}

		if ( $fieldErrors ) {
			self::cleanupFiles( $allUploadedUrls );

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

		return $uploadedFilesMap;
	}

	/**
	 * Normalize a single or multi-file item from $_FILES into a list of single-file arrays.
	 *
	 * @param array<string, mixed> $fileItem Single or multi-file array from $_FILES.
	 *
	 * @return array<int, array{name: string, type: string, tmp_name: string, error: int, size: int}>
	 */
	public static function normalizeFileItem( array $fileItem ): array {
		if ( ! isset( $fileItem['name'] ) ) {
			return [];
		}

		if ( ! is_array( $fileItem['name'] ) ) {
			return [ $fileItem ];
		}

		$normalized = [];
		$names      = $fileItem['name'];
		$types      = (array) ( $fileItem['type'] ?? [] );
		$tmpNames   = (array) ( $fileItem['tmp_name'] ?? [] );
		$errors     = (array) ( $fileItem['error'] ?? [] );
		$sizes      = (array) ( $fileItem['size'] ?? [] );

		foreach ( $names as $idx => $name ) {
			$normalized[] = [
				'name'     => (string) $name,
				'type'     => (string) ( $types[ $idx ] ?? '' ),
				'tmp_name' => (string) ( $tmpNames[ $idx ] ?? '' ),
				'error'    => (int) ( $errors[ $idx ] ?? UPLOAD_ERR_NO_FILE ),
				'size'     => (int) ( $sizes[ $idx ] ?? 0 ),
			];
		}

		return $normalized;
	}

	/**
	 * Filter upload filename based on random_filename setting.
	 *
	 * @param string $dir  Target directory.
	 * @param string $name Filename without extension.
	 * @param string $ext  Extension including leading dot.
	 *
	 * @return string Filtered unique filename.
	 */
	public static function filterFilename( string $dir, string $name, string $ext = '' ): string {
		$isRandom = FormConfig::isRandomFilenameEnabled();

		$baseName = pathinfo( $name, PATHINFO_FILENAME );
		if ( '' === $ext ) {
			$parsedExt = pathinfo( $name, PATHINFO_EXTENSION );
			if ( '' !== $parsedExt ) {
				$ext = '.' . $parsedExt;
			}
		}

		if ( $isRandom ) {
			$randomName = wp_generate_password( 24, false, false ) . strtolower( $ext );

			return wp_unique_filename( $dir, $randomName );
		}

		$cleanName = remove_accents( $baseName );
		$cleanName = strtolower( $cleanName );
		$cleanName = preg_replace( '/[\s_]+/', '-', $cleanName );
		$cleanName = preg_replace( '/[^a-z0-9\-]/', '', (string) $cleanName );
		$cleanName = trim( (string) preg_replace( '/-+/', '-', (string) $cleanName ), '-' );

		if ( '' === $cleanName ) {
			$cleanName = 'file';
		}

		$filename = $cleanName . strtolower( $ext );

		return wp_unique_filename( $dir, $filename );
	}

	/**
	 * Extract flat list of non-empty URL strings from single/multi-file upload structures.
	 *
	 * Supports nested arrays and newline-delimited multi-file strings produced by store().
	 *
	 * @param mixed $uploadedFiles Raw upload URL string, newline-delimited string, or array map.
	 *
	 * @return list<string>
	 */
	public static function extractUrls( mixed $uploadedFiles ): array {
		$flatUrls = [];
		$extract  = static function ( mixed $val, callable $recurse ) use ( &$flatUrls ): void {
			if ( is_array( $val ) ) {
				foreach ( $val as $subVal ) {
					$recurse( $subVal, $recurse );
				}
			} elseif ( is_scalar( $val ) && '' !== (string) $val ) {
				foreach ( explode( "\n", (string) $val ) as $line ) {
					$line = trim( $line );
					if ( '' !== $line ) {
						$flatUrls[] = $line;
					}
				}
			}
		};

		$extract( $uploadedFiles, $extract );

		return $flatUrls;
	}

	/**
	 * Delete uploaded files from disk on failure.
	 *
	 * @param array<int|string, mixed> $uploadedFiles Flat or mapped array of upload URLs.
	 */
	public static function cleanupFiles( array $uploadedFiles ): void {
		if ( empty( $uploadedFiles ) ) {
			return;
		}

		foreach ( self::extractUrls( $uploadedFiles ) as $url ) {
			$path = self::urlToPath( $url );
			if ( null !== $path && wp_is_writable( $path ) ) {
				wp_delete_file( $path );
			}
		}
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
