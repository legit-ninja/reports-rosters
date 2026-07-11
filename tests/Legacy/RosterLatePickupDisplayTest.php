<?php
/**
 * Late pickup roster display formatter tests.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterLatePickupDisplayTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        if (file_exists(__DIR__ . '/../../includes/order-meta-keys.php')) {
            require_once __DIR__ . '/../../includes/order-meta-keys.php';
        }
    }

    public function test_late_pickup_no_renders_as_dash() {
        $this->assertSame('-', intersoccer_roster_format_late_pickup_flag_for_display('No'));
    }

    public function test_late_pickup_yes_unchanged() {
        $this->assertSame('Yes', intersoccer_roster_format_late_pickup_flag_for_display('Yes'));
    }

    public function test_late_pickup_empty_renders_as_dash() {
        $this->assertSame('-', intersoccer_roster_format_late_pickup_flag_for_display(''));
    }
}
