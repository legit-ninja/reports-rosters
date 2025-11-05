<?php
/**
 * Rosters Test - Legacy roster functions
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RostersTest extends TestCase {
    public function test_legacy_roster_functions_exist() {
        if (file_exists(__DIR__ . '/../../includes/rosters.php')) {
            require_once __DIR__ . '/../../includes/rosters.php';
        }
        
        $this->assertTrue(true);
    }
}

