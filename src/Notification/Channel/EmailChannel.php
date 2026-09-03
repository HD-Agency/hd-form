<?php
/**
 * Email Channel
 *
 * Composes and enqueues notification emails to admin(s) via MailQueueRepository.
 * Emails are sent asynchronously by MailQueueProcessor (WP Cron).
 *
 * @package HDForm\Notification\Channel
 */

declare(strict_types=1);

namespace HDForm\Notification\Channel;

use HDForm\FormConfig;
use HDForm\FormEntry;
use HDForm\Notification\NotificationChannelInterface;
use HDForm\Notification\NotificationMessage;
use HDForm\Notification\TemplateEngine;
use HDForm\Repository\MailQueueRepository;
use HDForm\Compat\Helper;

defined( 'ABSPATH' ) || exit;

final class EmailChannel implements NotificationChannelInterface {

	/**
	 * @param array $config Channel configuration from notifications.channels.email.
	 */
	public function __construct( private readonly array $config = [] ) {}

	/** @inheritDoc */
	public function getSlug(): string {
		return 'email';
	}

	/**
	 * Compose and enqueue admin notification email(s).
	 *
	 * @param NotificationMessage $message The notification message DTO.
	 *
	 * @return bool True if at least one email was queued.
	 */
	public function send( NotificationMessage $message ): bool {
		$entry      = $message->entry;
		$formType   = $entry->formType;
		$recipients = FormConfig::getEmailRecipients( $formType );

		if ( empty( $recipients ) ) {
			return false;
		}

		$templateName = FormConfig::getEmailTemplate( $formType );
		$siteName     = Helper::getOption( 'blogname', '' );

		// Prepare body data.
		$data = [
			'name'            => $entry->name,
			'email'           => $entry->email,
			'phone'           => $entry->phone,
			'message'         => $entry->data['message'] ?? '',
			'form_type'       => $entry->formType,
			'form_type_label' => $message->formTypeLabel,
			'entry_id'        => $message->entryId,
			'page_url'        => $entry->pageUrl,
			'ip_address'      => $entry->ipAddress,
			'created_at'      => $message->createdAt,
			'fields'          => $entry->data,
			'utm_source'      => $entry->utmSource,
			'utm_medium'      => $entry->utmMedium,
			'utm_campaign'    => $entry->utmCampaign,
			'utm_term'        => $entry->utmTerm,
			'utm_content'     => $entry->utmContent,
		];

		// Render HTML via TemplateEngine.
		$htmlBody = TemplateEngine::render( $templateName, $data );

		$subject     = sprintf( '[%s] New contact from "%s"', $siteName, $message->formTypeLabel );
		$fromAddress = Helper::getOption( 'admin_email', '' );
		$headers     = self::buildHeaders( $entry, $siteName, $fromAddress );

		$repository  = new MailQueueRepository();
		$queuedCount = 0;
		$attachments = self::entryAttachments( $entry );

		foreach ( $recipients as $toEmail ) {
			if ( ! is_email( $toEmail ) ) {
				continue;
			}

			$result = $repository->enqueue(
				to:          $toEmail,
				subject:     $subject,
				body:        $htmlBody,
				entryId:     $message->entryId,
				headers:     $headers,
				attachments: $attachments
			);

			if ( is_numeric( $result ) && $result > 0 ) {
				++$queuedCount;
			}
		}

		if ( $queuedCount > 0 ) {
			if ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time(), \HDForm\Cron\MailQueueProcessor::HOOK_INSTANT, [ microtime( true ) ] );
			}
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}

		return $queuedCount > 0;
	}

	/**
	 * Uploaded-file URLs stored on the entry under __files.
	 *
	 * @return array<int, string>
	 */
	private static function entryAttachments( FormEntry $entry ): array {
		$files = $entry->data['__files'] ?? [];
		if ( ! is_array( $files ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map( static fn( mixed $url ): string => (string) $url, $files ),
				static fn( string $url ): bool => '' !== $url
			)
		);
	}

	/**
	 * Build safe mail headers from site settings and submitted values.
	 *
	 * @return array<int, string>
	 */
	private static function buildHeaders( FormEntry $entry, string $siteName, string $fromAddress ): array {
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$from    = self::sanitizeEmailAddress( $fromAddress );
		if ( '' !== $from ) {
			$headers[] = sprintf( 'From: %s <%s>', self::encodeHeaderText( $siteName ), $from );
		}

		$replyTo = self::sanitizeEmailAddress( $entry->email );
		if ( '' !== $replyTo ) {
			$headers[] = sprintf( 'Reply-To: %s', $replyTo );
		}

		return $headers;
	}

	private static function sanitizeHeaderText( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = (string) preg_replace( '/\s+/', ' ', $value );

		return trim( $value );
	}

	private static function sanitizeEmailAddress( string $value ): string {
		$value = self::sanitizeHeaderText( $value );
		$value = trim( current( explode( ',', $value ) ) ?: '' );

		return is_email( $value ) ? $value : '';
	}

	private static function encodeHeaderText( string $value ): string {
		$value = self::sanitizeHeaderText( $value );

		return function_exists( 'mb_encode_mimeheader' )
			? mb_encode_mimeheader( $value, 'UTF-8', 'B' )
			: $value;
	}
}
