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

use HDForm\FileUploadHandler;
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

		// Deduplicate valid To recipients case-insensitively.
		$validToRecipients = [];
		$seenTo            = [];
		foreach ( $recipients as $rawTo ) {
			if ( ! is_string( $rawTo ) ) {
				continue;
			}
			$cleanTo = self::sanitizeEmailAddress( $rawTo );
			$lowerTo = strtolower( $cleanTo );
			if ( '' !== $cleanTo && ! isset( $seenTo[ $lowerTo ] ) ) {
				$seenTo[ $lowerTo ]  = true;
				$validToRecipients[] = $cleanTo;
			}
		}

		if ( empty( $validToRecipients ) ) {
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

		$subject               = sprintf( '[%s] New contact from "%s"', $siteName, $message->formTypeLabel );
		$fromAddress           = Helper::getOption( 'admin_email', '' );
		$ccRecipients          = FormConfig::getEmailCcRecipients( $formType );
		$bccRecipients         = FormConfig::getEmailBccRecipients( $formType );
		$firstRecipientHeaders = self::buildHeaders(
			$entry,
			$siteName,
			$fromAddress,
			$ccRecipients,
			$bccRecipients,
			$validToRecipients
		);
		$baseHeaders           = ( count( $validToRecipients ) > 1 && ( ! empty( $ccRecipients ) || ! empty( $bccRecipients ) ) )
			? self::buildHeaders( $entry, $siteName, $fromAddress )
			: $firstRecipientHeaders;

		$repository  = new MailQueueRepository();
		$queuedCount = 0;
		$attachments = self::entryAttachments( $entry );

		foreach ( $validToRecipients as $toEmail ) {
			$headers = ( 0 === $queuedCount ) ? $firstRecipientHeaders : $baseHeaders;

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

		return FileUploadHandler::extractUrls( $files );
	}

	/**
	 * Build safe mail headers from site settings and submitted values.
	 *
	 * @param FormEntry         $entry         Submitted form entry DTO.
	 * @param string            $siteName      Site title.
	 * @param string            $fromAddress   Sender email address.
	 * @param array<int, mixed> $ccRecipients  Optional CC recipient list.
	 * @param array<int, mixed> $bccRecipients Optional BCC recipient list.
	 * @param array<int, mixed> $toRecipients  Optional To recipient list (excluded from CC/BCC).
	 *
	 * @return array<int, string>
	 */
	private static function buildHeaders(
		FormEntry $entry,
		string $siteName,
		string $fromAddress,
		array $ccRecipients = [],
		array $bccRecipients = [],
		array $toRecipients = []
	): array {
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$from    = self::sanitizeEmailAddress( $fromAddress );
		if ( '' !== $from ) {
			$headers[] = sprintf( 'From: %s <%s>', self::encodeHeaderText( $siteName ), $from );
		}

		$replyTo = self::sanitizeEmailAddress( $entry->email );
		if ( '' !== $replyTo ) {
			$headers[] = sprintf( 'Reply-To: %s', $replyTo );
		}

		$seen = [];
		foreach ( $toRecipients as $toEmail ) {
			if ( ! is_string( $toEmail ) ) {
				continue;
			}
			$cleanTo = self::sanitizeEmailAddress( $toEmail );
			if ( '' !== $cleanTo ) {
				$seen[ strtolower( $cleanTo ) ] = true;
			}
		}

		foreach ( $ccRecipients as $ccEmail ) {
			if ( ! is_string( $ccEmail ) ) {
				continue;
			}
			$cleanCc = self::sanitizeEmailAddress( $ccEmail );
			$lowerCc = strtolower( $cleanCc );
			if ( '' !== $cleanCc && ! isset( $seen[ $lowerCc ] ) ) {
				$seen[ $lowerCc ] = true;
				$headers[]        = sprintf( 'Cc: %s', $cleanCc );
			}
		}

		foreach ( $bccRecipients as $bccEmail ) {
			if ( ! is_string( $bccEmail ) ) {
				continue;
			}
			$cleanBcc = self::sanitizeEmailAddress( $bccEmail );
			$lowerBcc = strtolower( $cleanBcc );
			if ( '' !== $cleanBcc && ! isset( $seen[ $lowerBcc ] ) ) {
				$seen[ $lowerBcc ] = true;
				$headers[]         = sprintf( 'Bcc: %s', $cleanBcc );
			}
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
