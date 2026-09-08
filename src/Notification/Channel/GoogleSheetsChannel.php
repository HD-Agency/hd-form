<?php
/**
 * Google Sheets Channel
 *
 * Appends submission data to Google Sheets via GoogleSheetsIntegration.
 *
 * @package HDForm\Notification\Channel
 */

declare(strict_types=1);

namespace HDForm\Notification\Channel;

use HDForm\Integrations\GoogleSheetsIntegration;
use HDForm\Notification\NotificationChannelInterface;
use HDForm\Notification\NotificationMessage;

defined( 'ABSPATH' ) || exit;

final class GoogleSheetsChannel implements NotificationChannelInterface {
	private array $credentials;
	private string $sheetId;
	private string $tabName;

	/**
	 * @param array $config Channel configuration from notifications.channels.google_sheets.
	 */
	public function __construct( array $config = [] ) {
		$rawCredentials    = $config['credentials'] ?? [];
		$this->credentials = is_string( $rawCredentials )
			? ( json_decode( wp_unslash( $rawCredentials ), true ) ?: [] )
			: (array) $rawCredentials;
		$this->sheetId     = (string) ( $config['sheet_id'] ?? '' );
		$this->tabName     = (string) ( $config['tab_name'] ?? 'Sheet1' );
	}

	/** @inheritDoc */
	public function getSlug(): string {
		return 'google_sheets';
	}

	/**
	 * Append submission row to configured Google Sheet.
	 *
	 * @param NotificationMessage $message Notification message DTO.
	 *
	 * @return bool
	 */
	public function send( NotificationMessage $message ): bool {
		if ( empty( $this->credentials ) || '' === $this->sheetId ) {
			return false;
		}

		$entry = $message->entry;
		$row   = [
			$message->entryId,
			$message->createdAt,
			$message->formTypeLabel,
			$entry->name,
			$entry->email,
			$entry->phone,
			$entry->data['message'] ?? '',
			$entry->pageUrl,
			$entry->ipAddress,
		];

		// Flatten extra fields.
		$extras = array_diff_key(
			$entry->data,
			[
				'message'  => true,
				'__labels' => true,
				'__files'  => true,
				'__geo'    => true,
			]
		);
		foreach ( $extras as $val ) {
			$row[] = is_array( $val ) ? wp_json_encode( $val, JSON_UNESCAPED_UNICODE ) : (string) $val;
		}

		$row = apply_filters( 'hd_form_google_sheets_row_data', $row, $message, $this->sheetId, $this->tabName );

		return GoogleSheetsIntegration::appendRow(
			$this->credentials,
			$this->sheetId,
			$this->tabName,
			(array) $row
		);
	}
}
