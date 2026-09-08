<?php
/**
 * Notification Message DTO
 *
 * Channel-agnostic data object for notification dispatching.
 * Each channel reads the fields it needs from the embedded FormEntry.
 *
 * @package HDForm\Notification
 */

declare(strict_types=1);

namespace HDForm\Notification;

use HDForm\FormEntry;
use HDForm\FormConfig;

defined( 'ABSPATH' ) || exit;

final class NotificationMessage {

	public function __construct(
		public readonly int $entryId,
		public readonly string $formTypeLabel,
		public readonly string $createdAt,
		public readonly FormEntry $entry,
	) {}

	/**
	 * Factory: build from a FormEntry DTO + entry ID.
	 *
	 * @param FormEntry $entry   The original form entry DTO.
	 * @param int       $entryId The saved database ID.
	 *
	 * @return self
	 */
	public static function fromEntry( FormEntry $entry, int $entryId ): self {
		$formTypeConfig = FormConfig::getFormType( $entry->formType );
		$formTypeLabel  = $formTypeConfig['label'] ?? ucfirst( $entry->formType );

		return new self(
			entryId:       $entryId,
			formTypeLabel: $formTypeLabel,
			createdAt:     current_time( 'mysql' ),
			entry:         $entry,
		);
	}

	/**
	 * Format a plain-text summary for messaging channels (Telegram, Viber, etc.).
	 *
	 * @param string $siteName Site name for header.
	 *
	 * @return string
	 */
	public function toPlainText( string $siteName = '' ): string {
		$entry = $this->entry;
		$lines = [];

		if ( '' !== $siteName ) {
			$lines[] = sprintf( '[%s] New contact — %s', $siteName, $this->formTypeLabel );
		} else {
			$lines[] = sprintf( 'New contact — %s', $this->formTypeLabel );
		}

		$lines[] = '';

		if ( '' !== $entry->name ) {
			$lines[] = sprintf( 'Name: %s', $entry->name );
		}
		if ( '' !== $entry->email ) {
			$lines[] = sprintf( 'Email: %s', $entry->email );
		}
		if ( '' !== $entry->phone ) {
			$lines[] = sprintf( 'Phone: %s', $entry->phone );
		}

		$message = $entry->data['message'] ?? '';
		if ( '' !== $message ) {
			$lines[] = '';
			$lines[] = sprintf( 'Message: %s', $message );
		}

		// Extra fields (exclude 'message' and metadata keys).
		$labels = $entry->data['__labels'] ?? [];
		$extras = array_diff_key(
			$entry->data,
			[
				'message'  => true,
				'__labels' => true,
				'__files'  => true,
				'__geo'    => true,
			]
		);
		if ( ! empty( $extras ) ) {
			$lines[] = '';
			foreach ( $extras as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = self::flattenValue( $value );
				}
				$label   = $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
				$lines[] = sprintf( '• %s: %s', $label, $value );
			}
		}

		$lines[] = '';
		$lines[] = sprintf( 'Page: %s', $entry->pageUrl );
		$lines[] = sprintf( 'Time: %s', $this->createdAt );
		$lines[] = sprintf( '#%d', $this->entryId );

		return implode( "\n", $lines );
	}

	/**
	 * Human-readable flattening for list values in plain-text output.
	 *
	 * One nesting level of scalars becomes a comma-joined line; anything
	 * deeper or non-scalar degrades to compact JSON, and encoding failures
	 * degrade to an empty string — no PHP type names ("Array") ever leak
	 * into notifications.
	 *
	 * @param array $value Raw field value.
	 */
	private static function flattenValue( array $value ): string {
		$parts = [];

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) || null === $item ) {
				$parts[] = (string) $item;
				continue;
			}

			if ( is_array( $item ) ) {
				$encoded = wp_json_encode( $item, JSON_UNESCAPED_UNICODE );

				if ( false !== $encoded && '' !== $encoded ) {
					$parts[] = $encoded;
				}
			}
		}

		return implode( ', ', $parts );
	}
}
