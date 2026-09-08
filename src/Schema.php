<?php
/**
 * Schema — DB table creation/upgrade on plugin activation.
 *
 * Uses shared `hde_*` table names for zero-migration switching between HDE and HD Form.
 *
 * Clock convention (HDE coordination): every timestamp written by this plugin
 * uses WP site-local time via current_time('mysql'). SQL predicates must bind
 * that value as a parameter — never NOW()/CURRENT_TIMESTAMP, whose MySQL
 * server clock can differ from site time. Column DEFAULTs below only apply
 * when a writer omits the column entirely.
 *
 * @package HDForm
 */

declare(strict_types=1);

namespace HDForm;

use HDForm\Compat\DB;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const TABLE_ENTRIES          = 'hde_form_entries';
	public const TABLE_MAIL_QUEUE       = 'hde_mail_queue';
	public const TABLE_LOGS             = 'hde_form_logs';
	public const TABLE_WORKFLOW_HISTORY = 'hde_form_workflow_history';

	/**
	 * Current schema revision.
	 *
	 * Bump whenever schemas() changes so maybeUpgrade() re-runs dbDelta on the
	 * next admin request instead of waiting for a plugin reactivation.
	 */
	public const DB_VERSION = '1.2.0';

	private const DB_VERSION_OPTION = 'hd_form_db_version';

	/**
	 * Install or upgrade database tables.
	 *
	 * Called by maybeUpgrade() on activation and from the admin_init migration
	 * runner. dbDelta only adds what is missing, so repeated runs are no-ops.
	 */
	public static function install(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = self::charsetCollate();

		foreach ( self::schemas() as $table => $sql ) {
			$tableFull = DB::tableNameFull( $table );
			dbDelta( "CREATE TABLE `{$tableFull}` (\n{$sql}\n) ENGINE=InnoDB {$charset};" );
		}
	}

	/**
	 * Run schema upgrades when the stored DB version lags behind DB_VERSION.
	 *
	 * Hooked on admin_init and used as the activation callback so shared-table
	 * changes apply without reactivation. Idempotent and cheap when current:
	 * one cached get_option comparison.
	 */
	public static function maybeUpgrade(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		self::install();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Table DDL definitions (column list only, without CREATE TABLE wrapper).
	 *
	 * Identical to HDE FormModule to share the same tables.
	 *
	 * @return array<string, string>
	 */
	public static function schemas(): array {
		return [
			self::TABLE_ENTRIES          => <<<'SQL'
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			form_type varchar(50) NOT NULL DEFAULT '' COMMENT 'contact|quote|service|registration',
			form_id varchar(100) NOT NULL DEFAULT '' COMMENT 'Instance slug',
			status varchar(20) NOT NULL DEFAULT 'new' COMMENT 'new|read|starred|spam|trash',
			submission_hash varchar(64) DEFAULT NULL COMMENT 'Duplicate submission idempotency key',
			name varchar(255) NOT NULL DEFAULT '',
			email varchar(255) NOT NULL DEFAULT '',
			phone varchar(30) NOT NULL DEFAULT '',
			phone_country varchar(5) NOT NULL DEFAULT '',
			phone_national varchar(30) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(500) NOT NULL DEFAULT '',
			referer_url varchar(500) NOT NULL DEFAULT '',
			page_url varchar(500) NOT NULL DEFAULT '',
			utm_source varchar(200) NOT NULL DEFAULT '',
			utm_medium varchar(200) NOT NULL DEFAULT '',
			utm_campaign varchar(200) NOT NULL DEFAULT '',
			utm_term varchar(200) NOT NULL DEFAULT '',
			utm_content varchar(200) NOT NULL DEFAULT '',
			data longtext NOT NULL COMMENT 'JSON fields',
			notes text NOT NULL,
			workflow_status varchar(100) NOT NULL DEFAULT '',
			is_spam tinyint(1) NOT NULL DEFAULT 0,
			user_id bigint unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_form_type (form_type),
			KEY idx_form_id (form_id),
			KEY idx_status (status),
			KEY idx_workflow_status (workflow_status),
			KEY idx_email (email),
			KEY idx_phone (phone),
			KEY idx_ip (ip_address),
			KEY idx_is_spam (is_spam),
			KEY idx_created_at (created_at),
			KEY idx_type_status (form_type, status),
			KEY idx_type_created (form_type, created_at),
			KEY idx_status_id (status, id),
			KEY idx_utm_source (utm_source),
			KEY idx_utm_campaign (utm_campaign),
			UNIQUE KEY uniq_form_submission (form_type, submission_hash)
			SQL,

			self::TABLE_MAIL_QUEUE       => <<<'SQL'
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint unsigned NOT NULL DEFAULT 0,
			channel varchar(50) NOT NULL DEFAULT 'email',
			to_email varchar(255) NOT NULL DEFAULT '',
			subject varchar(500) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			headers text NOT NULL,
			attachments text NOT NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|sent|failed|dead',
			worker_token varchar(64) DEFAULT NULL,
			claimed_at datetime NULL DEFAULT NULL,
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			max_attempts tinyint unsigned NOT NULL DEFAULT 3,
			last_error text NOT NULL,
			scheduled_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			sent_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_channel_status (channel, status),
			KEY idx_entry_id (entry_id),
			KEY idx_scheduled_status (scheduled_at, status)
			SQL,

			self::TABLE_LOGS             => <<<'SQL'
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint unsigned NOT NULL DEFAULT 0,
			event varchar(50) NOT NULL DEFAULT '',
			message text NOT NULL,
			context text NOT NULL,
			actor varchar(100) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_entry_id (entry_id),
			KEY idx_event (event),
			KEY idx_created_at (created_at)
			SQL,

			self::TABLE_WORKFLOW_HISTORY => <<<'SQL'
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint unsigned NOT NULL DEFAULT 0,
			workflow_status varchar(100) NOT NULL DEFAULT '',
			note text NOT NULL,
			user_id bigint unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_entry_id (entry_id),
			KEY idx_created_at (created_at)
			SQL,
		];
	}

	/**
	 * Get DB charset collate string.
	 */
	private static function charsetCollate(): string {
		global $wpdb;

		return $wpdb->get_charset_collate();
	}
}
