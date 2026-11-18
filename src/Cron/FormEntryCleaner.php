<?php
/**
 * Form Entry Cleaner — WP Cron handler.
 *
 * Monthly cleanup of:
 * - Trashed form entries older than configured retention days.
 * - Sent/failed mail queue items older than configured retention days.
 * - Old form logs older than configured retention days.
 *
 * @package HDForm\Cron
 */

declare(strict_types=1);

namespace HDForm\Cron;

use HDForm\Compat\DB;
use HDForm\FormConfig;
use HDForm\Repository\FormEntryRepository;
use HDForm\Repository\FormLogRepository;
use HDForm\Schema;

defined( 'ABSPATH' ) || exit;

class FormEntryCleaner {
	public const HOOK     = 'hd_form_entry_cleanup';
	public const INTERVAL = 'monthly';

	private const LOCK_KEY          = 'hd_form_entry_cleanup_lock';
	private const DELETE_BATCH_SIZE = 500;

	/**
	 * Register cron schedule and hook.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', [ self::class, 'registerSchedule' ] );
		add_action( self::HOOK, [ self::class, 'cleanup' ] );
		add_action( 'init', [ self::class, 'ensureScheduled' ] );
	}

	/**
	 * Add custom "monthly" schedule if missing.
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array
	 */
	public static function registerSchedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::INTERVAL ] ) ) {
			$schedules[ self::INTERVAL ] = [
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => 'Monthly',
			];
		}

		return $schedules;
	}

	/**
	 * Ensure the cron event is scheduled.
	 */
	public static function ensureScheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( self::nextScheduledTime(), self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * On theme deactivation / cleanup — unschedule the event.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Run the cleanup.
	 */
	public static function cleanup(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, HOUR_IN_SECONDS );

		try {
			$config = self::getCleanupConfig();

			$trashDeleted = self::cleanTrashEntries( $config['trash_days'] );
			$queueDeleted = self::cleanMailQueue( $config['mail_queue_days'] );
			$logsDeleted  = self::cleanOldLogs( $config['log_days'] );

			if ( $trashDeleted || $queueDeleted || $logsDeleted ) {
				$logRepo = new FormLogRepository();
				$logRepo->log(
					0,
					'cleanup_run',
					sprintf( 'Cleaned: %d trash entries, %d queue items, %d logs', $trashDeleted, $queueDeleted, $logsDeleted ),
					[
						'trash_deleted' => $trashDeleted,
						'queue_deleted' => $queueDeleted,
						'logs_deleted'  => $logsDeleted,
						'config'        => $config,
					],
					'cron'
				);
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Get cleanup config with fallback defaults.
	 *
	 * @return array
	 */
	private static function getCleanupConfig(): array {
		$config = FormConfig::all()['cleanup'] ?? [];

		return wp_parse_args( $config, FormConfig::CLEANUP_DEFAULTS );
	}

	/**
	 * Delete trashed form entries older than N days.
	 *
	 * @param int $days Retention days.
	 *
	 * @return int Number of deleted rows.
	 */
	private static function cleanTrashEntries( int $days ): int {
		$table  = DB::tableNameFull( Schema::TABLE_ENTRIES );
		$cutoff = self::cutoffDate( $days );
		$total  = 0;
		$repo   = new FormEntryRepository();

		do {
			$sql = DB::db()->prepare(
				"SELECT `id` FROM {$table} WHERE `status` = 'trash' AND `updated_at` < %s ORDER BY `id` ASC LIMIT %d",
				$cutoff,
				self::DELETE_BATCH_SIZE
			);
			$ids = DB::db()->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : [];
			if ( empty( $ids ) ) {
				break;
			}

			$count   = count( $ids );
			$deleted = $repo->bulkDelete( $ids );
			$total  += $deleted;
		} while ( $count === self::DELETE_BATCH_SIZE && $deleted > 0 );

		return $total;
	}

	/**
	 * Delete sent/failed mail queue items older than N days.
	 *
	 * @param int $days Retention days.
	 *
	 * @return int Number of deleted rows.
	 */
	private static function cleanMailQueue( int $days ): int {
		$table  = DB::tableNameFull( Schema::TABLE_MAIL_QUEUE );
		$cutoff = self::cutoffDate( $days );

		return self::deleteBatched( "DELETE FROM {$table} WHERE `status` IN ('sent', 'failed', 'dead') AND `created_at` < %s", [ $cutoff ] );
	}

	/**
	 * Delete form logs older than N days.
	 *
	 * @param int $days Retention days.
	 *
	 * @return int Number of deleted rows.
	 */
	private static function cleanOldLogs( int $days ): int {
		$table  = DB::tableNameFull( Schema::TABLE_LOGS );
		$cutoff = self::cutoffDate( $days );

		return self::deleteBatched( "DELETE FROM {$table} WHERE `created_at` < %s", [ $cutoff ] );
	}

	/**
	 * Schedule first cleanup at a future off-peak time.
	 */
	private static function nextScheduledTime(): int {
		$now  = current_datetime();
		$next = $now->setTime( 3, 20 );
		if ( $next <= $now ) {
			$next = $next->add( new \DateInterval( 'P1D' ) );
		}

		return $next->getTimestamp();
	}

	/**
	 * Calculate a site-time MySQL cutoff date.
	 */
	private static function cutoffDate( int $days ): string {
		$days = max( 0, $days );

		return current_datetime()
			->sub( new \DateInterval( 'P' . $days . 'D' ) )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Run DELETE statements in bounded batches.
	 *
	 * @param string       $sql  SQL without the LIMIT clause.
	 * @param array<mixed> $args Prepared values.
	 */
	private static function deleteBatched( string $sql, array $args ): int {
		$total = 0;

		do {
			$prepared = DB::db()->prepare( $sql . ' LIMIT %d', ...array_merge( $args, [ self::DELETE_BATCH_SIZE ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result   = DB::db()->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $result ) {
				break;
			}

			$deleted = (int) $result;
			$total  += $deleted;
		} while ( self::DELETE_BATCH_SIZE === $deleted );

		return $total;
	}
}
