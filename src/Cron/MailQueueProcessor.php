<?php
/**
 * Mail Queue Processor — WP Cron handler.
 *
 * Processes pending/failed emails from the `hd_mail_queue` table.
 * Runs every 5 minutes via WP Cron. Batch size: 10 emails per run.
 *
 * @package HDForm\Cron
 */

declare(strict_types=1);

namespace HDForm\Cron;

use HDForm\Compat\ClaimStorage;
use HDForm\FileUploadHandler;
use HDForm\Repository\FormLogRepository;
use HDForm\Repository\MailQueueRepository;

defined( 'ABSPATH' ) || exit;

final class MailQueueProcessor {
	public const HOOK         = 'hd_process_mail_queue';
	public const HOOK_INSTANT = 'hd_process_mail_queue_instant';
	public const INTERVAL     = 'every_five_minutes';

	private const BATCH_SIZE = 10;
	private const LOCK_KEY   = 'hd_mail_queue_processor_lock';
	private const LOCK_TTL   = 10 * MINUTE_IN_SECONDS;

	/**
	 * Register cron schedule and hook.
	 */
	public static function init(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_filter( 'cron_schedules', [ self::class, 'registerSchedule' ] );
		add_action( self::HOOK, [ self::class, 'process' ] );
		add_action( self::HOOK_INSTANT, [ self::class, 'process' ] );
		add_action( 'init', [ self::class, 'ensureScheduled' ] );
	}

	/**
	 * Add custom "every_five_minutes" schedule if missing.
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array
	 */
	public static function registerSchedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::INTERVAL ] ) ) {
			$schedules[ self::INTERVAL ] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Every 5 minutes',
			];
		}

		return $schedules;
	}

	/**
	 * Ensure the cron event is scheduled.
	 */
	public static function ensureScheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL, self::HOOK );
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
	 * Process pending mail queue items.
	 */
	public static function process(): void {
		$workerToken = self::acquireLock();
		if ( null === $workerToken ) {
			return;
		}

		$repo    = new MailQueueRepository();
		$logRepo = new FormLogRepository();

		try {
			// Recover crashed workers first: exhausted stale rows go to dead.
			$repo->failStale();

			$pending = $repo->getPending( self::BATCH_SIZE );

			if ( empty( $pending ) ) {
				return;
			}

			foreach ( $pending as $item ) {
				$id = (int) $item['id'];

				// Mark as processing (also increments attempt counter).
				// Skip if another worker already claimed this item.
				if ( ! $repo->markProcessing( $id, $workerToken ) ) {
					continue;
				}

				$channel = (string) ( $item['channel'] ?? 'email' );
				if ( 'email' !== $channel ) {
					$dispatchErrors = [];
					$sent           = AsyncFormProcessor::dispatchNotificationChannel( (int) $item['entry_id'], $channel, $dispatchErrors );
					if ( $sent ) {
						$repo->markSent( $id );
						$logRepo->log(
							(int) $item['entry_id'],
							$channel . '_sent',
							sprintf( 'Queued notification via %s sent.', $channel ),
							[
								'queue_id' => $id,
								'channel'  => $channel,
							],
							'cron'
						);
					} else {
						// Exception messages arrive pre-scrubbed from the dispatcher.
						$errorMessage = $dispatchErrors[ $channel ] ?? 'Notification channel failed.';
						$repo->markFailed( $id, $errorMessage );
						$logRepo->log(
							(int) $item['entry_id'],
							$channel . '_failed',
							sprintf( 'Queued notification via %s failed.', $channel ),
							[
								'queue_id' => $id,
								'channel'  => $channel,
								'attempts' => (int) $item['attempts'] + 1,
								'error'    => $errorMessage,
							],
							'cron'
						);
					}

					continue;
				}

				// Capture real error via wp_mail_failed hook.
				$lastMailError = null;

				$errorCapture = function ( \WP_Error $error ) use ( &$lastMailError ): void {
					$lastMailError = $error->get_error_message();
				};

				add_action( 'wp_mail_failed', $errorCapture );

				$attachments = self::resolveAttachmentPaths( is_array( $item['attachments'] ?? null ) ? $item['attachments'] : [] );

				$sent = wp_mail(
					$item['to_email'],
					$item['subject'],
					$item['body'],
					$item['headers'],
					$attachments
				);

				remove_action( 'wp_mail_failed', $errorCapture );

				if ( $sent ) {
					$repo->markSent( $id );

					$logRepo->log(
						(int) $item['entry_id'],
						'email_sent',
						sprintf( 'Email sent to %s', $item['to_email'] ),
						[
							'queue_id' => $id,
							'subject'  => $item['subject'],
						],
						'cron'
					);
				} else {
					$errorMessage = $lastMailError ?: 'Unknown error';

					$repo->markFailed( $id, $errorMessage );

					$logRepo->log(
						(int) $item['entry_id'],
						'email_failed',
						sprintf( 'Email to %s failed: %s', $item['to_email'], $errorMessage ),
						[
							'queue_id' => $id,
							'attempts' => (int) $item['attempts'] + 1,
							'error'    => $errorMessage,
						],
						'cron'
					);
				}
			}
		} finally {
			self::releaseLock();
		}
	}

	/**
	 * Convert stored upload URLs into absolute paths for wp_mail.
	 *
	 * Only paths below the uploads baseurl resolve; anything else
	 * (foreign hosts, traversal attempts, deleted files) is dropped.
	 *
	 * @param array<int|string, mixed> $attachments Stored attachment URLs.
	 *
	 * @return array<int, string>
	 */
	private static function resolveAttachmentPaths( array $attachments ): array {
		$paths = [];

		foreach ( $attachments as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$path = FileUploadHandler::urlToPath( $url );
			if ( null !== $path ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Acquire the processor lock via a single atomic insert-if-absent.
	 *
	 * Delegates to ClaimStorage so every backend (object cache, shared claims
	 * table, option-row fallback) gets the same compare-and-set guarantee.
	 * Correctness does not depend on this lock alone: each queue row is
	 * claimed conditionally by worker_token, so a rare overlapping run only
	 * wastes a scan instead of double-sending.
	 */
	private static function acquireLock(): ?string {
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'hd_mail_queue_worker_', true );

		return ClaimStorage::claim( self::LOCK_KEY, self::LOCK_TTL, $token ) ? $token : null;
	}

	private static function releaseLock(): void {
		ClaimStorage::release( self::LOCK_KEY );
	}
}
