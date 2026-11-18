<?php
/**
 * Notification Dispatcher
 *
 * Registry + Dispatcher: resolves enabled channels from config,
 * instantiates each, and dispatches the notification message.
 *
 * @package HDForm\Notification
 */

declare(strict_types=1);

namespace HDForm\Notification;

use HDForm\FormConfig;
use HDForm\Notification\Channel\EmailChannel;
use HDForm\Notification\Channel\GoogleSheetsChannel;
use HDForm\Notification\Channel\TelegramChannel;
use HDForm\Notification\Channel\ViberChannel;
use HDForm\Notification\Channel\WebhookChannel;
use HDForm\Notification\Channel\ZaloChannel;

defined( 'ABSPATH' ) || exit;

final class NotificationDispatcher {

	/**
	 * Registry: channel slug → FQCN.
	 *
	 * @var array<string, string>
	 */
	private static array $channelMap = [
		'email'         => EmailChannel::class,
		'webhook'       => WebhookChannel::class,
		'google_sheets' => GoogleSheetsChannel::class,
		'telegram'      => TelegramChannel::class,
		'viber'         => ViberChannel::class,
		'zalo'          => ZaloChannel::class,
	];

	/**
	 * Dispatch notification to all enabled channels for a form type.
	 *
	 * @param NotificationMessage $message       The channel-agnostic message DTO.
	 * @param array|null          $onlyChannels  Restrict dispatch to these slugs.
	 * @param array|null          $errors        Out-param: scrubbed exception
	 *                                           messages keyed by channel slug.
	 *
	 * @return array<string, bool> Results keyed by channel slug.
	 */
	public static function dispatch( NotificationMessage $message, ?array $onlyChannels = null, ?array &$errors = null ): array {
		$results = [];
		$errors  = [];

		$channels = self::resolveChannels( $message->entry->formType );
		if ( null !== $onlyChannels ) {
			$channels = array_intersect_key( $channels, array_flip( $onlyChannels ) );
		}

		foreach ( $channels as $slug => $config ) {
			$channelMap = self::channelMap( $message );
			$class      = $channelMap[ $slug ] ?? null;
			if ( null === $class || ! class_exists( $class ) ) {
				continue;
			}

			try {
				/** @var NotificationChannelInterface $channel */
				$channel          = new $class( $config );
				$results[ $slug ] = $channel->send( $message );
			} catch ( \Throwable $error ) {
				$results[ $slug ] = false;
				$errors[ $slug ]  = self::scrubSecrets( $error->getMessage() );
			}
		}

		// Filter: allow third-party code to modify results or add custom channels.
		return apply_filters( 'hd_notification_dispatch_results', $results, $message );
	}

	/**
	 * Strip credential material from an exception message before persisting.
	 *
	 * Provider SDKs and HTTP clients routinely echo request URLs that carry
	 * bot tokens or key query parameters.
	 *
	 * @param string $message Raw exception message.
	 */
	private static function scrubSecrets( string $message ): string {
		// Telegram-style bot tokens embedded in URL paths.
		$message = (string) preg_replace( '/\/bot[^\/\s?#]+/', '/bot<redacted>', $message );

		// Key-bearing query parameters.
		$message = (string) preg_replace(
			'/([?&](?:key|token|secret|password|access_token|api_key)=)[^&\s]+/i',
			'$1<redacted>',
			$message
		);

		// Long opaque credential-shaped strings.
		$message = (string) preg_replace( '/\b[A-Za-z0-9_\-]{40,}\b/', '<redacted>', $message );

		return mb_substr( $message, 0, 500 );
	}

	/**
	 * @return array<string, string>
	 */
	private static function channelMap( NotificationMessage $message ): array {
		$map = apply_filters( 'hd_notification_channels', self::$channelMap, $message );

		return is_array( $map ) ? $map : self::$channelMap;
	}

	/**
	 * @return array<string, array>
	 */
	public static function enabledChannels( string $formType ): array {
		return self::resolveChannels( $formType );
	}

	/**
	 * Resolve which channels to activate for a given form type.
	 *
	 * @param string $formType Form type slug.
	 *
	 * @return array<string, array> Enabled channel configs keyed by slug.
	 */
	private static function resolveChannels( string $formType ): array {
		$config   = FormConfig::all();
		$channels = $config['notifications']['channels'] ?? [];

		// Filter to only enabled channels.
		$enabled = [];
		foreach ( $channels as $slug => $channelConfig ) {
			if ( ! empty( $channelConfig['enabled'] ) ) {
				$enabled[ $slug ] = $channelConfig;
			}
		}

		// Per-type override: if form_type defines specific channels, keep only those.
		$formTypeConfig  = $config['form_types'][ $formType ] ?? [];
		$allowedChannels = $formTypeConfig['channels'] ?? null;

		if ( is_array( $allowedChannels ) ) {
			$enabled = array_intersect_key( $enabled, array_flip( $allowedChannels ) );
		}

		return $enabled;
	}
}
