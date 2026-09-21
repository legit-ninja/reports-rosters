<?php
/**
 * oop-adapter.php file-scope calls must follow function_exists wrappers.
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;

class OopAdapterLoadOrderTest extends TestCase {

	public function test_roster_ajax_register_call_follows_declaration() {
		// Regression: local sandbox HTTP 500 — function_exists-wrapped
		// intersoccer_oop_register_roster_ajax_handlers() is not hoisted, so a
		// file-scope call above the wrapper fatals on every request.
		$path = dirname(__DIR__, 2) . '/includes/oop-adapter.php';
		$this->assertFileExists($path);
		$src = file_get_contents($path);
		$this->assertNotFalse($src);

		$declaration = strpos($src, 'function intersoccer_oop_register_roster_ajax_handlers(');
		$call = strpos($src, 'intersoccer_oop_register_roster_ajax_handlers();');
		$migrator = strpos($src, 'function intersoccer_oop_get_database_migrator(');

		$this->assertNotFalse($declaration, 'AJAX register helper must be declared');
		$this->assertNotFalse($call, 'AJAX register helper must still be invoked at load');
		$this->assertNotFalse($migrator, 'Database migrator factory must be declared');
		$this->assertLessThan($call, $declaration, 'Call must follow function_exists-wrapped declaration');
		$this->assertLessThan($call, $migrator, 'Handler constructor factories must be declared before the file-scope register call');
	}
}
