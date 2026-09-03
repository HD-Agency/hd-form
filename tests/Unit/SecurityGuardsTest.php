<?php
/**
 * Security Guards Unit Tests
 *
 * @package HDForm\Tests\Unit
 */

declare(strict_types=1);

namespace HDForm\Tests\Unit;

use HDForm\Security\CaptchaGuard;
use HDForm\Security\DuplicateGuard;
use HDForm\Security\HoneypotGuard;
use HDForm\Security\NullGuard;
use PHPUnit\Framework\TestCase;

final class SecurityGuardsTest extends TestCase {

	public function test_honeypot_detects_filled_field_with_signed_payload(): void {
		$payload   = HoneypotGuard::payload();
		$fieldName = $payload['field'];

		// Legitimate user: honeypot field is empty
		$legitInput = [
			'_hp_name' => $fieldName,
			'_hp_ts'   => $payload['timestamp'],
			'_hp_sig'  => $payload['signature'],
			$fieldName => '',
		];
		$this->assertFalse( HoneypotGuard::isBot( $legitInput ) );

		// Bot: honeypot field is filled
		$botInput = [
			'_hp_name' => $fieldName,
			'_hp_ts'   => $payload['timestamp'],
			'_hp_sig'  => $payload['signature'],
			$fieldName => 'I am a bot spammer',
		];
		$this->assertTrue( HoneypotGuard::isBot( $botInput ) );
	}

	public function test_honeypot_detects_legacy_field(): void {
		// Legacy field empty
		$this->assertFalse( HoneypotGuard::isBot( [ '_hp_field' => '' ] ) );

		// Legacy field filled
		$this->assertTrue( HoneypotGuard::isBot( [ '_hp_field' => 'bot_spam' ] ) );
	}

	public function test_honeypot_expired_but_signed_payload_is_not_bot(): void {
		$stale     = HoneypotGuard::payload( time() - DAY_IN_SECONDS - 60 );
		$fieldName = $stale['field'];

		// Stale page served from a full-page cache: signature still authentic.
		$input = [
			'_hp_name' => $fieldName,
			'_hp_ts'   => $stale['timestamp'],
			'_hp_sig'  => $stale['signature'],
			$fieldName => '',
		];

		$this->assertSame( 'expired', HoneypotGuard::inspect( $input ) );
		$this->assertFalse( HoneypotGuard::isBot( $input ) );
	}

	public function test_honeypot_tampered_signature_is_bot(): void {
		$payload  = HoneypotGuard::payload();
		$tampered = [
			'_hp_name'        => $payload['field'],
			'_hp_ts'          => $payload['timestamp'],
			'_hp_sig'         => 'forged-signature',
			$payload['field'] => '',
		];

		$this->assertSame( 'bot', HoneypotGuard::inspect( $tampered ) );
		$this->assertTrue( HoneypotGuard::isBot( $tampered ) );
	}

	public function test_duplicate_guard_hash_generation(): void {
		$ip       = '192.168.1.100';
		$formType = 'contact';
		$data     = [
			'name'  => 'John Doe',
			'email' => 'john@example.com',
			'phone' => '0901234567',
		];

		$hash1 = DuplicateGuard::hash( $ip, $formType, $data );
		$hash2 = DuplicateGuard::hash( $ip, $formType, $data );

		$this->assertNotEmpty( $hash1 );
		$this->assertSame( $hash1, $hash2 );

		// Different data produces different hash
		$hash3 = DuplicateGuard::hash( $ip, $formType, [ 'name' => 'Jane' ] );
		$this->assertNotSame( $hash1, $hash3 );
	}

	public function test_captcha_guard_make_can_be_called_without_arguments(): void {
		$guard = CaptchaGuard::make();
		$this->assertInstanceOf( NullGuard::class, $guard );
		$this->assertSame( '', $guard->getScriptUrl() );
	}
}
