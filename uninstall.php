<?php
/**
 * HD Form uninstall handler.
 *
 * Default behaviour removes only plugin-owned options, transients and cron
 * events. The shared `hde_*` tables and uploaded files are kept: the tables
 * may be owned by the HDE theme module, and delivered attachments may still
 * be referenced by sent emails.
 *
 * Define HD_FORM_UNINSTALL_PURGE_ALL as true (e.g. in wp-config.php) before
 * deleting the plugin to additionally remove uploaded files referenced by
 * stored entries and empty the shared tables' rows. Table structure is kept
 * in both modes so an active HDE installation keeps working.
 *
 * @package HDForm
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$hdfPurgeAll = defined( 'HD_FORM_UNINSTALL_PURGE_ALL' ) && HD_FORM_UNINSTALL_PURGE_ALL;

// ── Own options ─────────────────────────────────────────────────

delete_option( 'hd_form_settings' );
delete_option( 'hd_form_db_version' );
delete_option( 'hd_mail_queue_processor_lock' );

// ── Own cron events ─────────────────────────────────────────────

wp_clear_scheduled_hook( 'hd_process_mail_queue' );
wp_clear_scheduled_hook( 'hd_form_weekly_digest' );
wp_clear_scheduled_hook( 'hd_form_entry_cleanup' );
wp_clear_scheduled_hook( 'hd_form_async_akismet_check' );

// ── Own named transients ────────────────────────────────────────

delete_transient( 'hd_form_unread_count' );
delete_transient( 'hd_form_view_counts' );
delete_transient( 'hd_form_entry_cleanup_lock' );

// ── Hashed transient families (rate limits, claims, API caches) ──
// Their keys embed md5 digests, so they can only be removed by prefix.
// Object-cache-backed transients expire on their own TTL.

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_hdf_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_hdf_rl_' ) . '%',
		$wpdb->esc_like( '_transient_hdf_claim_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_hdf_claim_' ) . '%',
		$wpdb->esc_like( '_transient_hd_gsheet_token_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_hd_gsheet_token_' ) . '%',
		$wpdb->esc_like( '_transient_hd_geoip_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_hd_geoip_' ) . '%',
		$wpdb->esc_like( 'hdf_claim_' ) . '%'
	)
);

// ── Optional deep purge ─────────────────────────────────────────

if ( ! $hdfPurgeAll ) {
	return;
}

// Composer autoload for FileUploadHandler::urlToPath(); rows are purged even
// when it is missing — only the file removal is skipped then.
if ( is_file( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

$hdfTables = [
	'hde_form_logs',
	'hde_mail_queue',
	'hde_form_workflow_history',
	'hde_form_entries',
];

$hdfEntryTable = $wpdb->prefix . 'hde_form_entries';

// Collect upload URLs before their owning rows disappear.
$hdfFileUrls = [];
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$hdfRows = $wpdb->get_col( "SELECT data FROM {$hdfEntryTable}" );
foreach ( $hdfRows ?? [] as $hdfRaw ) {
	$hdfData = json_decode( (string) $hdfRaw, true );
	if ( ! is_array( $hdfData ) || ! is_array( $hdfData['__files'] ?? null ) ) {
		continue;
	}

	foreach ( $hdfData['__files'] as $hdfUrl ) {
		if ( is_string( $hdfUrl ) && '' !== $hdfUrl ) {
			$hdfFileUrls[] = $hdfUrl;
		}
	}
}

foreach ( $hdfTables as $hdfTable ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->prefix}{$hdfTable}" ); // Table names are plugin constants, not user input.
}

// Remove uploads last so a mid-loop failure never orphans live references.
if ( class_exists( \HDForm\FileUploadHandler::class ) ) {
	foreach ( array_unique( $hdfFileUrls ) as $hdfUrl ) {
		$hdfPath = \HDForm\FileUploadHandler::urlToPath( $hdfUrl );
		if ( null !== $hdfPath ) {
			wp_delete_file( $hdfPath );
		}
	}
}
