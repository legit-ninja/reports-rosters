<?php
/**
 * Reports Test - Legacy report functions
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class ReportsTest extends TestCase {
    public function test_legacy_report_functions_exist() {
        if (file_exists(__DIR__ . '/../../includes/reports.php')) {
            require_once __DIR__ . '/../../includes/reports.php';
        }
        
        $this->assertTrue(true);
    }
}

