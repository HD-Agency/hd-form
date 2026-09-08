<?php
/**
 * FormEntryRepository Unit Tests
 *
 * @package HDForm\Tests\Unit
 */

declare(strict_types=1);

namespace HDForm\Tests\Unit;

use HDForm\FormEntry;
use HDForm\FormEntryStatus;
use HDForm\Repository\FormEntryRepository;
use HDForm\Schema;
use PHPUnit\Framework\TestCase;

final class FormEntryRepositoryTest extends TestCase {

	public function test_schema_constants_use_hde_prefix(): void {
		$this->assertSame( 'hde_form_entries', Schema::TABLE_ENTRIES );
		$this->assertSame( 'hde_mail_queue', Schema::TABLE_MAIL_QUEUE );
		$this->assertSame( 'hde_form_logs', Schema::TABLE_LOGS );
		$this->assertSame( 'hde_form_workflow_history', Schema::TABLE_WORKFLOW_HISTORY );
	}

	public function test_form_entry_status_enums(): void {
		$this->assertSame( 'new', FormEntryStatus::New->value );
		$this->assertSame( 'read', FormEntryStatus::Read->value );
		$this->assertSame( 'starred', FormEntryStatus::Starred->value );
		$this->assertSame( 'spam', FormEntryStatus::Spam->value );
		$this->assertSame( 'trash', FormEntryStatus::Trash->value );

		$this->assertSame( FormEntryStatus::New, FormEntryStatus::fromRaw( 'new' ) );
		$this->assertSame( FormEntryStatus::Read, FormEntryStatus::fromRaw( 'read' ) );
		$this->assertNull( FormEntryStatus::fromRaw( 'invalid_status' ) );
	}

	public function test_form_entry_dto_instantiation(): void {
		$entry = new FormEntry(
			formType:       'contact',
			formId:         'main-contact',
			name:           'David Smith',
			email:          'david@example.com',
			phone:          '0909090909',
			phoneCountry:   'VN',
			phoneNational:  '0909090909',
			ipAddress:      '10.0.0.1',
			userAgent:      'TestAgent',
			refererUrl:     'https://example.com',
			pageUrl:        'https://example.com/contact',
			data:           [
				'message'  => 'Hello',
				'__labels' => [ 'message' => 'Your message' ],
			],
			submissionHash: 'abc123hash',
			utmSource:      'facebook',
			utmMedium:      'ads',
			utmCampaign:    'summer',
			utmTerm:        'promo',
			utmContent:     'btn',
			attachments:    [],
			userId:         0
		);

		$this->assertSame( 'contact', $entry->formType );
		$this->assertSame( 'David Smith', $entry->name );
		$this->assertSame( 'david@example.com', $entry->email );
		$this->assertSame( '0909090909', $entry->phone );
		$this->assertSame( 'facebook', $entry->utmSource );
		$this->assertArrayHasKey( 'message', $entry->data );
	}

	public function test_form_log_repository_filters(): void {
		$logRepo = new \HDForm\Repository\FormLogRepository();
		$this->assertIsInt( $logRepo->countAll() );
		$this->assertIsInt( $logRepo->countAll( [ 'event' => 'submitted' ] ) );
		$this->assertIsArray( $logRepo->findAll( [ 'event' => 'submitted' ], 1, 10 ) );
		$this->assertIsArray( $logRepo->findByEntryId( 123 ) );
	}
}
