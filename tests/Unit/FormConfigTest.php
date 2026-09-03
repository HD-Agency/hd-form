<?php
/**
 * FormConfig Unit Tests
 *
 * @package HDForm\Tests\Unit
 */

declare(strict_types=1);

namespace HDForm\Tests\Unit;

use HDForm\FormConfig;
use PHPUnit\Framework\TestCase;

final class FormConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		FormConfig::resetCache();
	}

	public function test_all_returns_default_configuration_array(): void {
		$config = FormConfig::all();

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'form_types', $config );
		$this->assertArrayHasKey( 'contact', $config['form_types'] );
		$this->assertArrayHasKey( 'notifications', $config );
		$this->assertArrayHasKey( 'channels', $config['notifications'] );
	}

	public function test_is_registered_returns_true_for_valid_types(): void {
		$this->assertTrue( FormConfig::isRegistered( 'contact' ) );
	}

	public function test_is_registered_returns_true_for_dynamically_registered_types(): void {
		add_filter(
			'hd_form_config',
			static function ( array $config ): array {
				$config['form_types']['service'] = [
					'label'    => 'Service',
					'required' => [ 'name', 'phone' ],
				];
				return $config;
			}
		);
		FormConfig::resetCache();

		$this->assertTrue( FormConfig::isRegistered( 'service' ) );

		remove_all_filters( 'hd_form_config' );
		FormConfig::resetCache();
	}

	public function test_is_registered_returns_false_for_invalid_type(): void {
		$this->assertFalse( FormConfig::isRegistered( 'non_existent_form_12345' ) );
		$this->assertFalse( FormConfig::isRegistered( '' ) );
	}

	public function test_get_form_type_returns_correct_definition(): void {
		$contact = FormConfig::getFormType( 'contact' );

		$this->assertIsArray( $contact );
		$this->assertArrayHasKey( 'label', $contact );
		$this->assertArrayHasKey( 'required', $contact );
	}

	public function test_get_returns_expected_default_values(): void {
		$minSubmitTime = FormConfig::get( 'min_submit_time' );
		$this->assertSame( 3, $minSubmitTime );

		$maxRenderAge = FormConfig::get( 'max_render_age' );
		$this->assertSame( 1800, $maxRenderAge );

		$this->assertNull( FormConfig::get( 'non_existent_key_xyz' ) );
	}

	public function test_workflow_statuses_accessors(): void {
		$this->assertIsArray( FormConfig::getWorkflowStatuses() );

		// Test default empty state or filtered state.
		add_filter(
			'hd_form_config',
			static function ( array $config ): array {
				$config['workflow_statuses'] = [
					[
						'slug'  => 'pending',
						'label' => 'Pending Review',
						'color' => '#dba617',
					],
					[
						'slug'  => 'approved',
						'label' => 'Approved',
						'color' => '#00a32a',
					],
				];
				return $config;
			}
		);
		FormConfig::resetCache();

		$this->assertTrue( FormConfig::hasWorkflowStatuses() );
		$this->assertCount( 2, FormConfig::getWorkflowStatuses() );

		$pending = FormConfig::getWorkflowStatusBySlug( 'pending' );
		$this->assertNotNull( $pending );
		$this->assertSame( 'Pending Review', $pending['label'] );
		$this->assertSame( '#dba617', $pending['color'] );

		$invalid = FormConfig::getWorkflowStatusBySlug( 'non_existent' );
		$this->assertNull( $invalid );

		remove_all_filters( 'hd_form_config' );
		FormConfig::resetCache();
	}
}
