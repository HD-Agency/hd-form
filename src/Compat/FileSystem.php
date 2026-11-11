<?php
/**
 * FileSystem Compat — minimal replacement for HD\Support\FileSystem.
 *
 * Implements only the methods used by the Form module classes.
 *
 * @package HDForm\Compat
 */

declare(strict_types=1);

namespace HDForm\Compat;

defined( 'ABSPATH' ) || exit;

final class FileSystem {

	/**
	 * Read the contents of a local file.
	 *
	 * Uses WP_Filesystem when available, falls back to file_get_contents.
	 *
	 * @param string $path Absolute file path.
	 *
	 * @return string|null File contents or null if not found.
	 */
	public static function fileRead( string $path ): ?string {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();
		}

		if ( $wp_filesystem && $wp_filesystem->is_file( $path ) ) {
			return $wp_filesystem->get_contents( $path ) ?: null;
		}

		// Fallback.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_file( $path ) ? file_get_contents( $path ) : null;
	}
}
