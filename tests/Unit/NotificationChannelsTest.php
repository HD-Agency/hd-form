<?php
/**
 * Notification Channels Unit Tests
 *
 * @package HDForm\Tests\Unit
 */

declare(strict_types=1);

namespace HDForm\Tests\Unit;

use HDForm\FormEntry;
use HDForm\Notification\Channel\EmailChannel;
use HDForm\Notification\Channel\GoogleSheetsChannel;
use HDForm\Notification\Channel\TelegramChannel;
use HDForm\Notification\Channel\ViberChannel;
use HDForm\Notification\Channel\WebhookChannel;
use HDForm\Notification\Channel\ZaloChannel;
use HDForm\Notification\NotificationDispatcher;
use HDForm\Notification\NotificationMessage;
use PHPUnit\Framework\TestCase;

final class NotificationChannelsTest extends TestCase {

	private function createTestMessage(): NotificationMessage {
		$entry = new FormEntry(
			formType:       'contact',
			formId:         'contact-1',
			name:           'Test User',
			email:          'test@example.com',
			phone:          '0912345678',
			phoneCountry:   'VN',
			phoneNational:  '0912345678',
			ipAddress:      '127.0.0.1',
			userAgent:      'Mozilla/5.0 PHPUnit',
			refererUrl:     'https://example.com/contact',
			pageUrl:        'https://example.com/contact',
			data:           [ 'message' => 'Test inquiry content' ],
			submissionHash: 'hash123',
			utmSource:      'google',
			utmMedium:      'cpc',
			utmCampaign:    'spring_promo',
			utmTerm:        'services',
			utmContent:     'banner',
			attachments:    [],
			userId:         0
		);

		return new NotificationMessage(
			entryId:       101,
			formTypeLabel: 'Contact Form',
			createdAt:     '2026-08-22 10:00:00',
			entry:         $entry
		);
	}

	public function test_channel_slugs(): void {
		$this->assertSame( 'email', ( new EmailChannel( [] ) )->getSlug() );
		$this->assertSame( 'webhook', ( new WebhookChannel( [] ) )->getSlug() );
		$this->assertSame( 'google_sheets', ( new GoogleSheetsChannel( [] ) )->getSlug() );
		$this->assertSame( 'telegram', ( new TelegramChannel( [] ) )->getSlug() );
		$this->assertSame( 'viber', ( new ViberChannel( [] ) )->getSlug() );
		$this->assertSame( 'zalo', ( new ZaloChannel( [] ) )->getSlug() );
	}

	public function test_webhook_channel_returns_false_when_url_empty(): void {
		$channel = new WebhookChannel( [ 'url' => '' ] );
		$this->assertFalse( $channel->send( $this->createTestMessage() ) );
	}

	public function test_google_sheets_channel_returns_false_when_sheet_id_or_creds_empty(): void {
		$channel = new GoogleSheetsChannel(
			[
				'sheet_id'    => '',
				'credentials' => [],
			]
		);
		$this->assertFalse( $channel->send( $this->createTestMessage() ) );
	}

	public function test_dispatcher_resolves_enabled_channels(): void {
		$channels = NotificationDispatcher::enabledChannels( 'contact' );
		$this->assertIsArray( $channels );
	}
}
