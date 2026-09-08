<?php
/**
 * FormValidator Unit Tests
 *
 * @package HDForm\Tests\Unit
 */

declare(strict_types=1);

namespace HDForm\Tests\Unit;

use HDForm\FormValidator;
use PHPUnit\Framework\TestCase;

final class FormValidatorTest extends TestCase {

	private FormValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new FormValidator();
	}

	public function test_validate_fails_when_unregistered_form_type_provided(): void {
		$result = $this->validator->validate( [ 'form_type' => 'unknown_hack' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unknown_form_type', $result->get_error_code() );
	}

	public function test_validate_fails_when_required_fields_are_missing(): void {
		$result = $this->validator->validate(
			[
				'form_type' => 'contact',
				'name'      => '',
				'email'     => '',
				'phone'     => '',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'validation_failed', $result->get_error_code() );
	}

	public function test_validate_fails_when_email_format_is_invalid(): void {
		$result = $this->validator->validate(
			[
				'form_type' => 'contact',
				'name'      => 'Alice',
				'email'     => 'not-an-email',
				'phone'     => '0912345678',
				'message'   => 'Test message',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'validation_failed', $result->get_error_code() );
	}

	public function test_validate_passes_with_valid_contact_data(): void {
		$result = $this->validator->validate(
			[
				'form_type' => 'contact',
				'name'      => 'Alice Doe',
				'email'     => 'alice@example.com',
				'phone'     => '0912345678',
				'message'   => 'Hello from test',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'contact', $result['formType'] );
		$this->assertSame( 'Alice Doe', $result['name'] );
		$this->assertSame( 'alice@example.com', $result['email'] );
		$this->assertSame( '0912345678', $result['phone'] );
	}

	public function test_validate_sanitizes_html_tags_in_text_inputs(): void {
		$result = $this->validator->validate(
			[
				'form_type' => 'contact',
				'name'      => '<script>alert(1)</script>Bob',
				'email'     => 'bob@example.com',
				'phone'     => '0987654321',
				'message'   => '<b>Bold text</b>',
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<script>', $result['name'] );
	}
}
