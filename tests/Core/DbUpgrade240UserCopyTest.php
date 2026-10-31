<?php
/**
 * User-facing schema 2.4.0 upgrade copy (Advanced page + confirm dialog).
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;

class DbUpgrade240UserCopyTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$path = dirname(__DIR__, 2) . '/includes/db-schema-2-4-0-user-copy.php';
		if (is_readable($path)) {
			require_once $path;
		}
	}

	public function test_help_text_describes_widen_columns_and_backup(): void {
		if (!function_exists('intersoccer_db_upgrade_2_4_0_user_help_text')) {
			$this->markTestSkipped('Upgrade help copy helper missing');
		}

		$text = strtolower(intersoccer_db_upgrade_2_4_0_user_help_text());
		$this->assertStringContainsString('event_type', $text);
		$this->assertStringContainsString('camp_times', $text);
		$this->assertStringContainsString('times', $text);
		$this->assertStringContainsString('191', $text);
		$this->assertStringContainsString('backup', $text);
		$this->assertStringNotContainsString('backfill', $text);
		$this->assertStringNotContainsString('financial fields', $text);
		$this->assertStringNotContainsString('girls_only', $text);
	}

	public function test_confirm_text_describes_widen_columns_and_backup(): void {
		if (!function_exists('intersoccer_db_upgrade_2_4_0_user_confirm_text')) {
			$this->markTestSkipped('Upgrade confirm copy helper missing');
		}

		$text = strtolower(intersoccer_db_upgrade_2_4_0_user_confirm_text());
		$this->assertStringContainsString('2.4.0', $text);
		$this->assertStringContainsString('event_type', $text);
		$this->assertStringContainsString('camp_times', $text);
		$this->assertStringContainsString('times', $text);
		$this->assertStringContainsString('191', $text);
		$this->assertStringContainsString('backup', $text);
		$this->assertStringNotContainsString('backfill', $text);
		$this->assertStringNotContainsString('financial fields', $text);
		$this->assertStringNotContainsString('girls_only', $text);
	}
}
