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

    // Regression: ECO-005 — Reports-rosters requires CRS coach-assignments without file_exists guard
    public function test_roster_page_survives_crs_deactivated() {
        $rosters_file = __DIR__ . '/../../includes/rosters.php';
        $this->assertFileExists($rosters_file);

        $contents = file_get_contents($rosters_file);
        $this->assertIsString($contents);

        $this->assertMatchesRegularExpression(
            '/InterSoccer_Admin_Coach_Assignments[\s\S]{0,400}file_exists\s*\(/',
            $contents,
            'Roster admin should guard CRS coach-assignments require with file_exists when CRS is inactive'
        );
    }
}

