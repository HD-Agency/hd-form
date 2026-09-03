<?php
declare(strict_types=1);

namespace HDForm\Tests\Unit;

use HDForm\Support\Crypto;
use HDForm\Updater\GitHubUpdater;
use PHPUnit\Framework\TestCase;

final class GitHubUpdaterTest extends TestCase {

	public function test_crypto_encrypt_and_decrypt(): void {
		$plain     = 'ghp_TestSecretTokenHDF123456789';
		$encrypted = Crypto::encrypt( $plain );

		$this->assertNotEmpty( $encrypted );
		$this->assertNotSame( $plain, $encrypted );

		$decrypted = Crypto::decrypt( $encrypted );
		$this->assertSame( $plain, $decrypted );

		// Tampered ciphertext returns empty string
		$this->assertSame( '', Crypto::decrypt( 'invalid-base64-payload' ) );
		$this->assertSame( '', Crypto::encrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( '' ) );
	}

	public function test_filter_http_request_options_sets_timeouts(): void {
		$updater = new GitHubUpdater();

		$options = $updater->filterHttpRequestOptions( [ 'timeout' => 30 ] );
		$this->assertSame( 2.5, $options['timeout'] );
	}

	public function test_should_check_now_blocks_passive_requests(): void {
		$updater = new GitHubUpdater();

		// When decision is false, always false
		$this->assertFalse( $updater->shouldCheckNow( false ) );

		// On passive request (no cron, no manual param), returns false to prevent thread blocking
		$this->assertFalse( $updater->shouldCheckNow( true ) );
	}

	public function test_token_source_resolution(): void {
		$hasToken = GitHubUpdater::hasToken();
		$source   = GitHubUpdater::tokenSource();

		$this->assertIsBool( $hasToken );
		$this->assertContains( $source, [ 'db', 'constant', 'none' ] );
	}
}
