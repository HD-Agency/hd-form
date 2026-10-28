<?php
/**
 * Mail Queue Repository
 *
 * @package HDForm\Repository
 */

declare(strict_types=1);

namespace HDForm\Repository;

use HDForm\Compat\DB;
use HDForm\Schema;

defined( 'ABSPATH' ) || exit;

class MailQueueRepository {
	use JsonDecodeTrait;

	private const TABLE = Schema::TABLE_MAIL_QUEUE;

	/**
	 * Enqueue a new email.
	 *
	 * @param string        $to          Recipient.
	 * @param string        $subject     Email subject.
	 * @param string        $body        HTML body.
	 * @param int           $entryId     Related entry ID.
	 * @param array         $headers     Email headers.
	 * @param array<string> $attachments Upload URLs to deliver with the email.
	 *
	 * @return int|\WP_Error Insert ID on success.
	 */
	public function enqueue( string $to, string $subject, string $body, int $entryId = 0, array $headers = [], array $attachments = [] ): int|\WP_Error {
		$data = [
			'entry_id'     => $entryId,
			'channel'      => 'email',
			'to_email'     => $to,
			'subject'      => $subject,
			'body'         => $body,
			'headers'      => wp_json_encode( $headers ),
			'attachments'  => wp_json_encode( array_values( array_filter( $attachments, 'is_string' ) ) ),
			'payload'      => wp_json_encode( [] ),
			'status'       => 'pending',
			'scheduled_at' => current_time( 'mysql' ),
			'created_at'   => current_time( 'mysql' ),
		];

		return DB::insertOneRow( self::TABLE, $data );
	}

	/**
	 * Enqueue a webhook notification.
	 *
	 * @param string $url     Webhook URL.
	 * @param array  $payload Webhook payload.
	 * @param int    $entryId Related entry ID.
	 *
	 * @return int|\WP_Error Insert ID on success.
	 */
	public function enqueueWebhook( string $url, array $payload, int $entryId = 0 ): int|\WP_Error {
		$data = [
			'entry_id'     => $entryId,
			'channel'      => 'webhook',
			'to_email'     => $url,
			'subject'      => 'Webhook Dispatch',
			'body'         => '',
			'headers'      => wp_json_encode( [] ),
			'attachments'  => wp_json_encode( [] ),
			'payload'      => wp_json_encode( $payload ),
			'status'       => 'pending',
			'scheduled_at' => current_time( 'mysql' ),
			'created_at'   => current_time( 'mysql' ),
		];

		return DB::insertOneRow( self::TABLE, $data );
	}

	/**
	 * Enqueue a non-email notification channel.
	 *
	 * @param string               $channel     Channel slug (e.g. 'telegram', 'viber').
	 * @param int                  $entryId     Related entry ID.
	 * @param array<string, mixed> $payload     Channel payload.
	 * @param int                  $maxAttempts Max delivery attempts.
	 *
	 * @return int|\WP_Error Insert ID on success.
	 */
	public function enqueueChannel( string $channel, int $entryId, array $payload = [], int $maxAttempts = 3 ): int|\WP_Error {
		$channel = sanitize_key( $channel );
		if ( '' === $channel || 'email' === $channel ) {
			return new \WP_Error( 'invalid_channel', 'Invalid notification channel.' );
		}

		$data = [
			'entry_id'     => $entryId,
			'channel'      => $channel,
			'to_email'     => '',
			'subject'      => '',
			'body'         => '',
			'headers'      => wp_json_encode( [] ),
			'attachments'  => wp_json_encode( [] ),
			'payload'      => wp_json_encode( $payload ),
			'status'       => 'pending',
			'max_attempts' => $maxAttempts,
			'created_at'   => current_time( 'mysql' ),
			'scheduled_at' => current_time( 'mysql' ),
		];

		return DB::insertOneRow( self::TABLE, $data );
	}

	/**
	 * Get pending queue items to process.
	 *
	 * @param int $limit Max items to fetch.
	 *
	 * @return array
	 */
	public function getPending( int $limit = 10 ): array {
		$table = DB::tableNameFull( self::TABLE );
		$now   = current_time( 'mysql' );

		// Include stale 'processing' items (>15 min) to recover from crashed workers.
		$sql = "
			SELECT * FROM {$table}
			WHERE (
				(`status` IN ('pending', 'failed') AND `scheduled_at` <= %s)
				OR (`status` = 'processing' AND COALESCE(`claimed_at`, `scheduled_at`) <= DATE_SUB(%s, INTERVAL 15 MINUTE))
			)
			AND `attempts` < `max_attempts`
			ORDER BY `scheduled_at` ASC
			LIMIT %d
		";

		$prepared = DB::db()->prepare( $sql, $now, $now, $limit );
		$results  = DB::db()->get_results( $prepared, ARRAY_A );

		if ( is_array( $results ) ) {
			foreach ( $results as &$result ) {
				$result['headers']     = self::decodeJsonArray( $result['headers'] ?? '', 'mail_queue.headers.' . ( $result['id'] ?? 'unknown' ) );
				$result['attachments'] = self::decodeJsonArray( $result['attachments'] ?? '', 'mail_queue.attachments.' . ( $result['id'] ?? 'unknown' ) );
				$result['payload']     = self::decodeJsonArray( $result['payload'] ?? '', 'mail_queue.payload.' . ( $result['id'] ?? 'unknown' ) );
			}
			unset( $result );
		}

		return $results ?: [];
	}

	/**
	 * Mark an item as processing (atomic increment of attempts).
	 *
	 * @param int    $id          Queue item ID.
	 * @param string $workerToken Unique worker identifier.
	 *
	 * @return bool
	 */
	public function markProcessing( int $id, string $workerToken ): bool {
		$table = DB::tableNameFull( self::TABLE );
		$now   = current_time( 'mysql' );

		// Atomic claim: only update if pending/failed is due or processing is
		// stale. scheduled_at keeps its schedule/backoff value; the claim window
		// lives in claimed_at so ORDER BY scheduled_at fairness survives
		// recovery claims.
		$sql = "UPDATE {$table}
			SET `status` = 'processing',
				`attempts` = `attempts` + 1,
				`worker_token` = %s,
				`claimed_at` = %s
			WHERE `id` = %d
				AND `attempts` < `max_attempts`
				AND (
					(`status` IN ('pending', 'failed') AND `scheduled_at` <= %s)
					OR (`status` = 'processing' AND COALESCE(`claimed_at`, `scheduled_at`) <= DATE_SUB(%s, INTERVAL 15 MINUTE))
				)";

		$result = DB::db()->query( DB::db()->prepare( $sql, $workerToken, $now, $id, $now, $now ) );

		return false !== $result && DB::db()->rows_affected > 0;
	}

	/**
	 * Dead-letter stale 'processing' rows whose retry budget is exhausted.
	 *
	 * A crash mid-send leaves such rows invisible to getPending() forever —
	 * this sweeper gives them a terminal state so retention can purge them.
	 *
	 * @param int $staleMinutes Claim age after which a row counts as abandoned.
	 *
	 * @return int Number of rows transitioned to dead.
	 */
	public function failStale( int $staleMinutes = 15 ): int {
		$table = DB::tableNameFull( self::TABLE );
		$now   = current_time( 'mysql' );

		$sql = "UPDATE {$table}
			SET `status` = 'dead',
				`last_error` = %s,
				`worker_token` = NULL
			WHERE `status` = 'processing'
				AND `attempts` >= `max_attempts`
				AND COALESCE(`claimed_at`, `scheduled_at`) <= DATE_SUB(%s, INTERVAL %d MINUTE)";

		$prepared = DB::db()->prepare( $sql, 'Worker crashed mid-send.', $now, $staleMinutes );
		$result   = DB::db()->query( $prepared );

		return false !== $result ? (int) DB::db()->rows_affected : 0;
	}

	/**
	 * Mark an item as sent.
	 *
	 * @param int $id Item ID.
	 *
	 * @return bool
	 */
	public function markSent( int $id ): bool {
		$result = DB::updateOneRow(
			self::TABLE,
			$id,
			[
				'status'       => 'sent',
				'sent_at'      => current_time( 'mysql' ),
				'worker_token' => null,
			]
		);

		return (bool) $result;
	}

	/**
	 * Mark an item as failed after its delivery attempt.
	 *
	 * Attempts are incremented exactly once, at claim time (markProcessing).
	 * This method must not increment again — with MySQL's left-to-right SET
	 * evaluation the double increment let a single failure exhaust the whole
	 * retry budget and dead-letter the row.
	 *
	 * @param int    $id    Item ID.
	 * @param string $error Error message.
	 *
	 * @return bool
	 */
	public function markFailed( int $id, string $error ): bool {
		$table = DB::tableNameFull( self::TABLE );
		$now   = current_time( 'mysql' );

		// Exponential backoff (capped at 60 min) from the post-claim count.
		$sql = "UPDATE {$table}
			SET `last_error` = %s,
				`worker_token` = NULL,
				`status` = CASE WHEN `attempts` >= `max_attempts` THEN 'dead' ELSE 'pending' END,
				`scheduled_at` = DATE_ADD(%s, INTERVAL LEAST(POW(2, `attempts`), 60) MINUTE)
			WHERE `id` = %d";

		$prepared = DB::db()->prepare( $sql, $error, $now, $id );
		$result   = DB::db()->query( $prepared );

		return false !== $result;
	}
}
