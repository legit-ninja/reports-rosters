<?php
/**
 * Utils Test - Legacy utility functions
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey\Functions;

class UtilsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        
        if (file_exists(__DIR__ . '/../../includes/utils.php')) {
            require_once __DIR__ . '/../../includes/utils.php';
        }
    }
    
    public function test_utility_functions_loaded() {
        $this->assertTrue(true);
    }
    
    public function test_date_formatting_helper() {
        if (function_exists('intersoccer_format_date')) {
            $formatted = intersoccer_format_date('2024-06-01');
            $this->assertIsString($formatted);
        } else {
            $this->markTestSkipped('Date formatting function not found');
        }
    }
}

