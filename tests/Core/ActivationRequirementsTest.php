<?php
/**
 * Activation must not fail on host ini or missing Woo caps.
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Core\Dependencies;
use InterSoccer\ReportsRosters\Core\Logger;
use Mockery;

class ActivationRequirementsTest extends TestCase {

	/** @var Dependencies */
	private $dependencies;

	protected function setUp(): void {
		parent::setUp();

		$logger = Mockery::mock(Logger::class);
		$logger->shouldReceive('debug')->andReturn(null);
		$logger->shouldReceive('info')->andReturn(null);
		$logger->shouldReceive('warning')->andReturn(null);
		$logger->shouldReceive('error')->andReturn(null);

		$this->dependencies = new Dependencies($logger);
	}

	public function test_check_system_requirements_pass_when_max_execution_time_is_thirty() {
		global $wp_version;
		$wp_version = '6.0';

		$previous = ini_get('max_execution_time');
		ini_set('max_execution_time', '30');
		try {
			$this->assertTrue($this->dependencies->check_system_requirements());
		} finally {
			ini_set('max_execution_time', (string) $previous);
		}
	}

	public function test_check_all_passes_when_woocommerce_caps_missing() {
		global $wp_version;
		$wp_version = '6.0';

		$logger = Mockery::mock(Logger::class);
		$logger->shouldReceive('debug')->andReturn(null);
		$logger->shouldReceive('info')->andReturn(null);
		$logger->shouldReceive('warning')->andReturn(null);
		$logger->shouldReceive('error')->andReturn(null);

		$dependencies = new class($logger) extends Dependencies {
			public function check_user_capabilities() {
				return false;
			}

			public function check_required_plugins() {
				return true;
			}

			public function check_php_extensions() {
				return true;
			}

			public function check_database_requirements() {
				return true;
			}
		};

		$this->assertFalse($dependencies->check_user_capabilities());
		$this->assertTrue($dependencies->check_all(true));
	}
}
