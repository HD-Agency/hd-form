<?php
/**
 * Weekly Email Summary — WP Cron handler.
 *
 * Sends a weekly digest email summarizing form submissions
 * grouped by form type with total/new/spam counts.
 *
 * @package HDForm\Cron
 */

declare(strict_types=1);

namespace HDForm\Cron;

use HDForm\Compat\DB;
use HDForm\Compat\Helper;
use HDForm\FormConfig;
use HDForm\Repository\MailQueueRepository;
use HDForm\Schema;

defined( 'ABSPATH' ) || exit;

final class WeeklyDigestCron {
	public const HOOK = 'hd_form_weekly_digest';

	/**
	 * Initialize cron.
	 */
	public static function init(): void {
		add_action( self::HOOK, [ self::class, 'send' ] );
		add_action( 'init', [ self::class, 'ensureScheduled' ] );
	}

	/**
	 * Ensure the cron event is scheduled.
	 */
	public static function ensureScheduled(): void {
		$settings = self::getSettings();
		if ( empty( $settings['enabled'] ) ) {
			// Unschedule if disabled.
			$ts = wp_next_scheduled( self::HOOK );
			if ( $ts ) {
				wp_unschedule_event( $ts, self::HOOK );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( self::nextScheduledTime( $settings ), 'weekly', self::HOOK );
		}
	}

	/**
	 * Unschedule on deactivation.
	 */
	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/**
	 * Send the weekly digest email.
	 */
	public static function send(): void {
		$settings = self::getSettings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$recipients = $settings['recipients'] ?? [];
		if ( empty( $recipients ) ) {
			$recipients = [ Helper::getOption( 'admin_email' ) ];
		}

		[ $startDate, $endDate ] = self::weeklyWindow();
		$stats                   = self::getWeeklyStats( $startDate, $endDate );

		if ( empty( $stats ) ) {
			return; // No submissions this week.
		}

		$siteName = get_bloginfo( 'name' );
		$subject  = sprintf(
			'[%s] HD Forms — Weekly Summary (%s – %s)',
			$siteName,
			wp_date( 'M j', self::mysqlTimestamp( $startDate ) ),
			wp_date( 'M j', self::mysqlTimestamp( $endDate ) )
		);

		$body = self::buildEmailBody( $stats, $startDate, $endDate );

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$queue   = new MailQueueRepository();

		foreach ( $recipients as $email ) {
			$result = $queue->enqueue( $email, $subject, $body, 0, $headers );
			if ( is_wp_error( $result ) ) {
				Helper::errorLog( '[WeeklyDigestCron] Failed to enqueue digest email: ' . $result->get_error_message() );
			}
		}
	}

	/**
	 * Query weekly submission stats grouped by form_type.
	 *
	 * @param string $startDate Start datetime.
	 * @param string $endDate   End datetime.
	 *
	 * @return array
	 */
	private static function getWeeklyStats( string $startDate, string $endDate ): array {
		$table          = DB::tableNameFull( Schema::TABLE_ENTRIES );
		$spamExpression = DB::tableHasColumn( Schema::TABLE_ENTRIES, 'is_spam' )
			? "SUM( CASE WHEN status = 'spam' OR is_spam = 1 THEN 1 ELSE 0 END ) AS spam_count"
			: "SUM( CASE WHEN status = 'spam' THEN 1 ELSE 0 END ) AS spam_count";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT
			form_type,
			COUNT(*) AS total,
			SUM( CASE WHEN status = 'new' THEN 1 ELSE 0 END ) AS new_count,
			{$spamExpression}
		FROM {$table}
		WHERE created_at BETWEEN %s AND %s
		GROUP BY form_type
		ORDER BY total DESC";

		$results = DB::db()->get_results(
			DB::db()->prepare( $sql, $startDate, $endDate ),
			ARRAY_A
		);

		if ( ! is_array( $results ) ) {
			Helper::errorLog( '[WeeklyDigestCron] Stats query failed: ' . ( DB::db()->last_error ?: 'Unknown database error.' ) );

			return [];
		}

		return $results;
	}

	/**
	 * Build the HTML email body.
	 *
	 * @param array  $stats     Stats rows.
	 * @param string $startDate Period start.
	 * @param string $endDate   Period end.
	 *
	 * @return string
	 */
	private static function buildEmailBody( array $stats, string $startDate, string $endDate ): string {
		$config   = FormConfig::all();
		$adminUrl = admin_url( 'admin.php?page=hd-form-entries' );
		$totalAll = 0;
		$newAll   = 0;
		$spamAll  = 0;

		$rows = '';
		foreach ( $stats as $row ) {
			$label     = $config['form_types'][ $row['form_type'] ]['label'] ?? ucfirst( $row['form_type'] );
			$totalAll += (int) $row['total'];
			$newAll   += (int) $row['new_count'];
			$spamAll  += (int) $row['spam_count'];

			$rows .= sprintf(
				'<tr><td style="padding:8px 12px;border:1px solid #e0e0e0;">%s</td>
				<td style="padding:8px 12px;border:1px solid #e0e0e0;text-align:center;">%d</td>
				<td style="padding:8px 12px;border:1px solid #e0e0e0;text-align:center;">%d</td>
				<td style="padding:8px 12px;border:1px solid #e0e0e0;text-align:center;">%d</td></tr>',
				esc_html( $label ),
				(int) $row['total'],
				(int) $row['new_count'],
				(int) $row['spam_count']
			);
		}

		$period = sprintf(
			'%s – %s',
			wp_date( 'M j, Y', self::mysqlTimestamp( $startDate ) ),
			wp_date( 'M j, Y', self::mysqlTimestamp( $endDate ) )
		);

		return sprintf(
			'<div style="font-family:-apple-system,sans-serif;max-width:600px;margin:0 auto;">
				<h2 style="color:#1d2939;">HD Forms — Weekly Summary</h2>
				<p style="color:#667085;">Period: %s</p>
				<table style="border-collapse:collapse;width:100%%;margin:16px 0;">
					<thead>
						<tr style="background:#f9fafb;">
							<th style="padding:8px 12px;border:1px solid #e0e0e0;text-align:left;">Form Type</th>
							<th style="padding:8px 12px;border:1px solid #e0e0e0;">Total</th>
							<th style="padding:8px 12px;border:1px solid #e0e0e0;">New</th>
							<th style="padding:8px 12px;border:1px solid #e0e0e0;">Spam</th>
						</tr>
					</thead>
					<tbody>%s</tbody>
					<tfoot>
						<tr style="background:#f9fafb;font-weight:600;">
							<td style="padding:8px 12px;border:1px solid #e0e0e0;">TOTAL</td>
							<td style="padding:8px 12px;border:1px solid #e0e0e0;text-align:center;">%d</td>
							<td style="padding:8px 12px;border:1px solid #e0e0e0;text-align:center;">%d</td>
							<td style="padding:8px 12px;border:1px solid #e0e0e0;text-align:center;">%d</td>
						</tr>
					</tfoot>
				</table>
				<p><a href="%s" style="color:#2271b1;">View all entries →</a></p>
			</div>',
			esc_html( $period ),
			$rows,
			$totalAll,
			$newAll,
			$spamAll,
			esc_url( $adminUrl )
		);
	}

	/**
	 * Calculate next scheduled time based on configured day.
	 *
	 * @param array $settings Digest settings.
	 *
	 * @return int Unix timestamp.
	 */
	private static function nextScheduledTime( array $settings ): int {
		$day = $settings['day'] ?? 'monday';
		$day = in_array( $day, [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ], true ) ? $day : 'monday';

		try {
			$next = current_datetime()->modify( "next {$day}" );
			if ( false === $next ) {
				return time() + WEEK_IN_SECONDS;
			}

			return $next->setTime( 8, 0 )->getTimestamp();
		} catch ( \Exception ) {
			return time() + WEEK_IN_SECONDS;
		}
	}

	/**
	 * Return the digest query window in site-time MySQL format.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function weeklyWindow(): array {
		$end   = current_datetime();
		$start = $end->sub( new \DateInterval( 'P7D' ) );

		return [
			$start->format( 'Y-m-d H:i:s' ),
			$end->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Convert a site-time MySQL datetime to a Unix timestamp.
	 */
	private static function mysqlTimestamp( string $mysqlDate ): int {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysqlDate, wp_timezone() );

		return $date instanceof \DateTimeImmutable ? $date->getTimestamp() : time();
	}

	/**
	 * Get digest settings from admin config.
	 *
	 * @return array
	 */
	private static function getSettings(): array {
		$options = Helper::getOption( 'hd_form_settings', [] );

		return $options['weekly_digest'] ?? [];
	}
}
