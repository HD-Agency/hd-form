<?php
/**
 * Unit Tests — GitHubUpdater (HD Form).
 *
 * Covers cooldown logic, manual check bypass, package integrity guard,
 * and Live Push response rendering.
 *
 * @package HDForm\Tests\Unit
 */

declare(strict_types=1);

namespace HDForm\Tests\Unit;

use HDForm\Plugin;
use HDForm\Support\Crypto;
use HDForm\Updater\GitHubUpdater;
use PHPUnit\Framework\TestCase;
use stdClass;
use WP_Error;
use WP_REST_Request;

final class GitHubUpdaterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_transients']     = [];
		$GLOBALS['wp_test_options']        = [];
		$GLOBALS['hd_test_user_can']       = true;
		$GLOBALS['wp_test_inline_scripts'] = [];
		$GLOBALS['wp_test_rest_routes']    = [];
		$_GET                              = [];
	}

	protected function tearDown(): void {
		parent::tearDown();
		$_GET                          = [];
		$GLOBALS['wp_test_transients'] = [];
	}

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

	public function test_token_source_resolution(): void {
		$hasToken = GitHubUpdater::hasToken();
		$source   = GitHubUpdater::tokenSource();

		$this->assertIsBool( $hasToken );
		$this->assertContains( $source, [ 'db', 'constant', 'none' ] );
	}

	public function test_should_check_now_blocks_passive_web_browsing(): void {
		$updater = new GitHubUpdater();
		// In passive web browsing without cron or query params, shouldCheckNow must return false (0ms latency).
		$this->assertFalse( $updater->shouldCheckNow( true ) );
		$this->assertFalse( $updater->shouldCheckNow( false ) );
	}

	public function test_should_check_now_allows_manual_check_and_resets_cooldown(): void {
		$updater = new GitHubUpdater();
		set_transient( GitHubUpdater::COOLDOWN_TRANSIENT, 1, 7200 );
		$this->assertTrue( (bool) get_transient( GitHubUpdater::COOLDOWN_TRANSIENT ) );

		$_GET['puc_check_for_updates'] = '1';
		$_GET['puc_slug']              = 'hd-form';

		$allowed = $updater->shouldCheckNow( true );
		$this->assertTrue( $allowed );
		// Manual check must clear cooldown transient so next checks are fresh.
		$this->assertFalse( (bool) get_transient( GitHubUpdater::COOLDOWN_TRANSIENT ) );
	}

	public function test_validate_package_integrity_passes_when_core_files_exist(): void {
		global $wp_filesystem;

		$wp_filesystem = new class() {
			public function exists( string $path ): bool {
				return true;
			}
		};

		$updater        = new GitHubUpdater();
		$upgrader       = new stdClass();
		$skin           = new stdClass();
		$skin->plugin   = 'hd-form/hd-form.php';
		$upgrader->skin = $skin;

		$source = '/tmp/upgrade/hd-form.tmp/hd-form/';
		$result = $updater->validatePackageIntegrity( $source, '/tmp/upgrade/', $upgrader );

		$this->assertSame( $source, $result );
	}

	public function test_validate_package_integrity_aborts_when_core_files_are_missing(): void {
		global $wp_filesystem;

		// Simulate archive missing vendor/autoload.php
		$wp_filesystem = new class() {
			public function exists( string $path ): bool {
				if ( str_ends_with( $path, 'vendor/autoload.php' ) ) {
					return false;
				}
				return true;
			}
		};

		$updater        = new GitHubUpdater();
		$upgrader       = new stdClass();
		$skin           = new stdClass();
		$skin->plugin   = 'hd-form/hd-form.php';
		$upgrader->skin = $skin;

		$source = '/tmp/upgrade/hd-form.tmp/hd-form/';
		$result = $updater->validatePackageIntegrity( $source, '/tmp/upgrade/', $upgrader );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hdf_corrupted_package', $result->get_error_code() );
		$this->assertStringContainsString( 'vendor/autoload.php', $result->get_error_message() );
	}

	public function test_validate_package_integrity_handles_null_or_invalid_source(): void {
		$updater = new GitHubUpdater();

		// 1. Existing WP_Error passed through
		$inputError = new WP_Error( 'upgrader_pre_error', 'Pre-existing error' );
		$result     = $updater->validatePackageIntegrity( $inputError, null, null );
		$this->assertSame( $inputError, $result );

		// 2. Null source returns WP_Error, never null
		$resultNull = $updater->validatePackageIntegrity( null, null, null );
		$this->assertInstanceOf( WP_Error::class, $resultNull );
		$this->assertSame( 'hdf_invalid_source', $resultNull->get_error_code() );

		// 3. Empty string source returns WP_Error
		$resultEmpty = $updater->validatePackageIntegrity( '', null, null );
		$this->assertInstanceOf( WP_Error::class, $resultEmpty );
		$this->assertSame( 'hdf_invalid_source', $resultEmpty->get_error_code() );
	}

	public function test_validate_package_integrity_identifies_hdf_via_hook_extra(): void {
		global $wp_filesystem;

		// Simulate missing hd-form.php in archive
		$wp_filesystem = new class() {
			public function exists( string $path ): bool {
				return ! str_ends_with( $path, 'hd-form.php' );
			}
		};

		$updater   = new GitHubUpdater();
		$upgrader  = new stdClass();
		$hookExtra = [ 'plugin' => 'hd-form/hd-form.php' ];

		$result = $updater->validatePackageIntegrity( '/tmp/upgrade/custom-dir/', '/tmp/upgrade/', $upgrader, $hookExtra );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hdf_corrupted_package', $result->get_error_code() );
		$this->assertStringContainsString( 'hd-form.php', $result->get_error_message() );
	}

	public function test_build_update_row_html_contains_version_and_native_markup(): void {
		$updater = new GitHubUpdater();
		$html    = $updater->buildUpdateRowHtml( '1.1.2' );

		$this->assertStringContainsString( 'plugin-update-tr', $html );
		$this->assertStringContainsString( 'id="hdf-update"', $html );
		$this->assertStringContainsString( '1.1.2', $html );
		$this->assertStringContainsString( 'update-link', $html );
		$this->assertStringContainsString( 'update.php?action=upgrade-plugin', $html );
	}

	public function test_check_rest_permission_requires_update_plugins_cap_and_nonce(): void {
		$updater = new GitHubUpdater();
		$request = new WP_REST_Request( 'GET', '/hd/v1/updater/check' );

		// 1. Without nonce -> forbidden
		$this->assertFalse( $updater->checkRestPermission( $request ) );

		// 2. With valid nonce and cap -> allowed
		$request->set_header( 'X-WP-Nonce', 'valid-nonce' );
		$this->assertTrue( $updater->checkRestPermission( $request ) );

		// 3. Lacking capability -> forbidden
		$GLOBALS['hd_test_user_can'] = false;
		$this->assertFalse( $updater->checkRestPermission( $request ) );
	}

	public function test_handle_rest_check_returns_false_when_no_update(): void {
		$updater  = new GitHubUpdater();
		$request  = new WP_REST_Request( 'GET', '/hd/v1/updater/check' );
		$response = $updater->handleRestCheck( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'has_update', $data );
		$this->assertFalse( $data['has_update'] );
		$this->assertArrayHasKey( 'current_version', $data );
	}

	public function test_enqueue_plugins_script_runs_only_on_plugins_php(): void {
		$updater = new GitHubUpdater();

		// On other admin pages like edit.php -> do not enqueue
		$GLOBALS['wp_test_inline_scripts'] = [];
		$updater->enqueuePluginsScript( 'edit.php' );
		$this->assertEmpty( $GLOBALS['wp_test_inline_scripts'] );

		// On plugins.php with capability -> enqueues inline script
		$updater->enqueuePluginsScript( 'plugins.php' );
		$this->assertNotEmpty( $GLOBALS['wp_test_inline_scripts'] );
	}

	public function test_is_rest_request_detection(): void {
		$oldServer = $_SERVER;
		$oldGet    = $_GET;

		try {
			// 1. Regular web page
			$_SERVER['REQUEST_URI'] = '/sample-page/';
			$_GET                   = [];
			$this->assertFalse( Plugin::isRestRequest() );

			// 2. REST API pretty permalink
			$_SERVER['REQUEST_URI'] = '/wp-json/hd/v1/updater/check';
			$this->assertTrue( Plugin::isRestRequest() );

			// 3. REST API query parameter
			$_SERVER['REQUEST_URI'] = '/index.php';
			$_GET['rest_route']     = '/hd/v1/updater/check';
			$this->assertTrue( Plugin::isRestRequest() );
		} finally {
			$_SERVER = $oldServer;
			$_GET    = $oldGet;
		}
	}

	public function test_register_rest_routes_registers_endpoint_and_is_idempotent(): void {
		$GLOBALS['wp_test_rest_routes'] = [];
		$updater                        = new GitHubUpdater();

		$updater->registerRestRoutes();
		$this->assertArrayHasKey( 'hd/v1', $GLOBALS['wp_test_rest_routes'] );
		$this->assertArrayHasKey( '/updater/check', $GLOBALS['wp_test_rest_routes']['hd/v1'] );

		// Second invocation must be a no-op (idempotent)
		$updater->registerRestRoutes();
		$this->assertArrayHasKey( '/updater/check', $GLOBALS['wp_test_rest_routes']['hd/v1'] );
	}
}
