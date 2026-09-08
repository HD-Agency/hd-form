<?php
/**
 * Telegram Channel
 *
 * Sends form submission notifications via Telegram Bot API.
 *
 * @package HDForm\Notification\Channel
 */

declare(strict_types=1);

namespace HDForm\Notification\Channel;

use HDForm\Notification\NotificationChannelInterface;
use HDForm\Notification\NotificationMessage;
use HDForm\Compat\Helper;

defined( 'ABSPATH' ) || exit;

final class TelegramChannel implements NotificationChannelInterface {
	private const API_BASE = 'https://api.telegram.org/bot';

	private string $botToken;
	private string $chatId;

	/**
	 * @param array $config Channel configuration from notifications.channels.telegram.
	 */
	public function __construct( array $config = [] ) {
		$this->botToken = $config['bot_token'] ?? '';
		$this->chatId   = $config['chat_id'] ?? '';
	}

	/** @inheritDoc */
	public function getSlug(): string {
		return 'telegram';
	}

	/**
	 * Send notification via Telegram Bot API.
	 *
	 * @param NotificationMessage $message The notification message DTO.
	 *
	 * @return bool True if the API responded with ok=true.
	 */
	public function send( NotificationMessage $message ): bool {
		if ( '' === $this->botToken || '' === $this->chatId ) {
			return false;
		}

		$siteName = Helper::getOption( 'blogname', '' );
		$text     = $message->toPlainText( $siteName );

		$url      = $this->apiUrl( 'sendMessage' );
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 10,
				'body'    => [
					'chat_id' => $this->chatId,
					'text'    => $text,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			Helper::errorLog( '[TelegramChannel] Request failed for ' . $this->redactedUrl( $url ) . ': ' . $response->get_error_message() );

			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$success = ! empty( $body['ok'] );
		if ( ! $success ) {
			Helper::errorLog( '[TelegramChannel] API rejected request for ' . $this->redactedUrl( $url ) );
		}

		return $success;
	}

	private function apiUrl( string $method ): string {
		return self::API_BASE . $this->botToken . '/' . ltrim( $method, '/' );
	}

	private function redactedUrl( string $url ): string {
		return '' === $this->botToken
			? $url
			: str_replace( $this->botToken, '[redacted]', $url );
	}
}
