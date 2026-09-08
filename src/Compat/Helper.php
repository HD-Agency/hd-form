<?php
/**
 * Helper Compat — minimal replacement for HD\Core\Helper.
 *
 * Implements only the methods used by the Form module classes.
 * No dependency on themes/hd.
 *
 * @package HDForm\Compat
 */

declare(strict_types=1);

namespace HDForm\Compat;

defined( 'ABSPATH' ) || exit;

final class Helper {

	/**
	 * Get a WordPress option value.
	 *
	 * @param string $key           Option name.
	 * @param mixed  $default_value Default value.
	 *
	 * @return mixed
	 */
	public static function getOption( string $key, mixed $default_value = false ): mixed {
		return get_option( $key, $default_value );
	}

	/**
	 * Get a filtered setting from the 'hd_theme_settings_filter' or 'hd_settings_filter' hook.
	 *
	 * Compatible with the HD theme filter contract.
	 *
	 * @param string $name         Setting key.
	 * @param array  $defaultValue Default if not found.
	 *
	 * @return mixed
	 */
	public static function filterSettingOptions( string $name, array $defaultValue = [] ): mixed {
		$filters = apply_filters( 'hd_theme_settings_filter', apply_filters( 'hd_settings_filter', [] ) );

		if ( ! isset( $filters[ $name ] ) ) {
			return $defaultValue;
		}

		return $filters[ $name ] ?: $defaultValue;
	}

	/**
	 * Add a new WP option (no overwrite).
	 *
	 * @param string $key    Option name.
	 * @param mixed  $value  Option value.
	 * @param bool   $autoload Auto-load with WordPress.
	 *
	 * @return bool True if the option was added successfully.
	 */
	public static function addOption( string $key, mixed $value, bool $autoload = true ): bool {
		return add_option( $key, $value, '', $autoload );
	}

	/**
	 * Remove a WP option.
	 *
	 * @param string $key Option name.
	 *
	 * @return bool True if the option was deleted.
	 */
	public static function removeOption( string $key ): bool {
		return delete_option( $key );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $msg Error message.
	 */
	public static function errorLog( string $msg ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $msg );
	}

	/**
	 * Get theme modification value.
	 *
	 * @param string|null $modName      Mod name.
	 * @param mixed       $defaultValue Default value.
	 *
	 * @return mixed
	 */
	public static function getThemeMod( ?string $modName, mixed $defaultValue = false ): mixed {
		if ( ! $modName ) {
			return $defaultValue;
		}

		$mod = get_theme_mod( $modName, $defaultValue );

		if ( is_ssl() && is_string( $mod ) && str_contains( $mod, 'http://' ) ) {
			return str_replace( 'http://', 'https://', $mod );
		}

		return $mod;
	}

	/**
	 * Get attachment image source URL.
	 *
	 * @param int|mixed $attachmentId Attachment post ID.
	 * @param string    $size         Image size.
	 *
	 * @return string|null
	 */
	public static function attachmentImageSrc( mixed $attachmentId, string $size = 'thumbnail' ): ?string {
		if ( ! $attachmentId ) {
			return null;
		}

		$src = wp_get_attachment_image_url( (int) $attachmentId, $size );

		return $src ?: null;
	}

	/**
	 * Format a database datetime string (UTC) into the site's localized date/time.
	 *
	 * @param string|null $dateStr Raw datetime string (e.g. '2026-08-27 04:33:37').
	 * @param string|null $format  Optional format string.
	 *
	 * @return string Localized date string.
	 */
	public static function formatDate( ?string $dateStr, ?string $format = null ): string {
		if ( empty( $dateStr ) ) {
			return '—';
		}

		$timestamp = strtotime( $dateStr . ' UTC' );
		if ( false === $timestamp ) {
			$timestamp = strtotime( $dateStr );
		}

		if ( false === $timestamp ) {
			return (string) $dateStr;
		}

		if ( null === $format ) {
			$dateFormat = (string) get_option( 'date_format', 'Y-m-d' );
			$timeFormat = (string) get_option( 'time_format', 'H:i:s' );
			$format     = $dateFormat . ' ' . $timeFormat;
		}

		return function_exists( 'wp_date' )
			? wp_date( $format, $timestamp )
			: date_i18n( $format, $timestamp );
	}
}
