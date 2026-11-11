<?php
/**
 * Async form follow-up processor.
 *
 * @package HDForm\Cron
 */

declare(strict_types=1);

namespace HDForm\Cron;

use HDForm\FormEntry;
use HDForm\FormConfig;
use HDForm\Notification\NotificationDispatcher;
use HDForm\Notification\NotificationMessage;
use HDForm\Repository\EntryCountCache;
use HDForm\Repository\FormEntryRepository;
use HDForm\Repository\FormLogRepository;
use HDForm\Repository\MailQueueRepository;
use HDForm\Security\SpamChecker;

defined( 'ABSPATH' ) || exit;

final class AsyncFormProcessor {
	public const AKISMET_HOOK = 'hd_form_async_akismet_check';

	public static function init(): void {
		add_action( self::AKISMET_HOOK, [ self::class, 'processAkismet' ] );
	}

	public static function enqueueAkismet( int $entryId ): bool {
		return self::schedule( self::AKISMET_HOOK, $entryId );
	}

	public static function enqueueNotifications( int $entryId, ?FormEntry $entry = null ): bool {
		$entry ??= self::entryById( $entryId );
		if ( null === $entry ) {
			return false;
		}

		$channels = NotificationDispatcher::enabledChannels( $entry->formType );
		unset( $channels['email'] );

		if ( ! $channels ) {
			return true;
		}

		$repo        = new MailQueueRepository();
		$queuedCount = 0;

		// Enabled-but-unconfigured channels would only burn retry cycles.
		$channels = array_filter(
			$channels,
			static fn( mixed $config, string $slug ): bool => self::channelIsConfigured( $slug, is_array( $config ) ? $config : [] ),
			ARRAY_FILTER_USE_BOTH
		);

		foreach ( array_keys( $channels ) as $channel ) {
			$result = $repo->enqueueChannel( $channel, $entryId, [ 'entry_id' => $entryId ] );
			if ( is_int( $result ) && $result > 0 ) {
				++$queuedCount;
			}
		}

		return $queuedCount === count( $channels );
	}

	/**
	 * Whether a channel carries the credentials it needs to actually deliver.
	 *
	 * Unknown channel slugs pass through untouched so third-party channels
	 * keep their existing behavior.
	 *
	 * @param string $slug    Channel slug.
	 * @param array  $config  Channel config.
	 */
	private static function channelIsConfigured( string $slug, array $config ): bool {
		$has = static fn( string $key ): bool => '' !== trim( (string) ( $config[ $key ] ?? '' ) );

		return match ( $slug ) {
			'webhook'       => $has( 'url' ),
			'google_sheets' => $has( 'sheet_id' ) && $has( 'credentials' ),
			'telegram', 'zalo' => $has( 'bot_token' ) && $has( 'chat_id' ),
			'viber'         => $has( 'auth_token' ) && $has( 'receiver' ),
			default         => true,
		};
	}

	public static function processAkismet( int $entryId ): void {
		$entry = self::entryById( $entryId );
		if ( null === $entry ) {
			return;
		}

		$reasons = SpamChecker::checkAkismet( $entry );
		if ( ! $reasons ) {
			return;
		}

		$entryRepo = new FormEntryRepository();
		$entryRepo->bulkUpdateStatus( [ $entryId ], 'spam' );
		EntryCountCache::flush();

		( new FormLogRepository() )->log(
			$entryId,
			'spam_detected_async',
			'Async spam check classified the entry as spam.',
			[ 'spam_reasons' => $reasons ],
			'cron',
			$entry->ipAddress
		);
	}

	public static function dispatchNotificationChannel( int $entryId, string $channel, ?array &$errors = null ): bool {
		$entry = self::entryById( $entryId );
		if ( null === $entry ) {
			return false;
		}

		$formTypeConfig = FormConfig::getFormType( $entry->formType );
		$message        = new NotificationMessage(
			entryId:       $entryId,
			formTypeLabel: $formTypeConfig['label'] ?? ucfirst( $entry->formType ),
			createdAt:     current_time( 'mysql' ),
			entry:         $entry,
		);
		$results        = NotificationDispatcher::dispatch( $message, [ $channel ], $errors );

		return ! empty( $results[ $channel ] );
	}

	private static function schedule( string $hook, int $entryId ): bool {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}

		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( $hook, [ $entryId ] ) ) {
			return true;
		}

		return (bool) wp_schedule_single_event( time(), $hook, [ $entryId ] );
	}

	private static function entryById( int $entryId ): ?FormEntry {
		$row = ( new FormEntryRepository() )->findById( $entryId );

		return null !== $row ? FormEntry::fromRow( $row ) : null;
	}
}
