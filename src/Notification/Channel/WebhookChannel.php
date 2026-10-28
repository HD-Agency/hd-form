<?php
/**
 * Webhook Channel
 *
 * Dispatches form submission data to remote Webhook endpoints asynchronously.
 *
 * @package HDForm\Notification\Channel
 */

declare(strict_types=1);

namespace HDForm\Notification\Channel;

use HDForm\Integrations\WebhookIntegration;
use HDForm\Notification\NotificationChannelInterface;
use HDForm\Notification\NotificationMessage;

defined( 'ABSPATH' ) || exit;

final class WebhookChannel implements NotificationChannelInterface {

	private string $url;
	private string $method;
	private string $format;
	private string $secretKey;

	/**
	 * @param array $config Channel configuration from notifications.channels.webhook.
	 */
	public function __construct( array $config = [] ) {
		$this->url       = wp_unslash( (string) ( $config['url'] ?? '' ) );
		$this->method    = (string) ( $config['method'] ?? 'POST' );
		$this->format    = (string) ( $config['format'] ?? 'json' );
		$this->secretKey = wp_unslash( (string) ( $config['secret_key'] ?? '' ) );
	}

	/** @inheritDoc */
	public function getSlug(): string {
		return 'webhook';
	}

	/**
	 * Send submission payload to Webhook endpoint.
	 *
	 * @param NotificationMessage $message Notification message DTO.
	 *
	 * @return bool
	 */
	public function send( NotificationMessage $message ): bool {
		if ( '' === $this->url ) {
			return false;
		}

		$entry   = $message->entry;
		$payload = [
			'event'           => 'hd_form_submission',
			'entry_id'        => $message->entryId,
			'created_at'      => $message->createdAt,
			'form_type'       => $entry->formType,
			'form_type_label' => $message->formTypeLabel,
			'name'            => $entry->name,
			'email'           => $entry->email,
			'phone'           => $entry->phone,
			'page_url'        => $entry->pageUrl,
			'user_agent'      => $entry->userAgent,
			'ip_address'      => $entry->ipAddress,
			'data'            => $entry->data,
		];

		$payload       = apply_filters( 'hd_form_webhook_payload', $payload, $message, $this->url );
		$customHeaders = apply_filters( 'hd_form_webhook_headers', [], $message, $this->url );

		$result = WebhookIntegration::sendWebhook(
			$this->url,
			$this->method,
			$this->format,
			(array) $payload,
			$this->secretKey,
			(array) $customHeaders
		);

		return (bool) ( $result['success'] ?? false );
	}
}
