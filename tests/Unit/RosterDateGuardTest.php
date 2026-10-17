<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Data\Models\Roster;
use PHPUnit\Framework\TestCase;

class RosterDateGuardTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
    }

    public function test_roster_has_real_date_helper(): void {
        if (!function_exists('intersoccer_roster_has_real_date')) {
            $this->fail('intersoccer_roster_has_real_date must be loaded');
        }
        $this->assertFalse(intersoccer_roster_has_real_date(null));
        $this->assertFalse(intersoccer_roster_has_real_date(''));
        $this->assertFalse(intersoccer_roster_has_real_date('1970-01-01'));
        $this->assertFalse(intersoccer_roster_has_real_date('0000-00-00'));
        $this->assertTrue(intersoccer_roster_has_real_date('2026-10-19'));
    }

    public function test_roster_date_range_filter_excludes_null_start_date(): void {
        $roster = new Roster([
            'start_date' => null,
            'end_date' => null,
            'order_status' => 'completed',
        ]);
        $this->assertFalse($roster->matches(['date_range' => [
            'start' => '2026-01-01',
            'end' => '2026-12-31',
        ]]));
    }

    public function test_roster_date_range_end_only_keeps_null_dates(): void {
        $roster = new Roster([
            'start_date' => null,
            'end_date' => null,
            'order_status' => 'completed',
        ]);
        $this->assertTrue($roster->matches(['date_range' => [
            'end' => '2026-12-31',
        ]]));
    }

    public function test_roster_date_range_filter_includes_real_dates(): void {
        $roster = new Roster([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'order_status' => 'completed',
        ]);
        $this->assertTrue($roster->matches(['date_range' => [
            'start' => '2026-01-01',
            'end' => '2026-12-31',
        ]]));
    }

    public function test_conflicts_with_returns_false_when_either_start_date_missing(): void {
        $a = new Roster([
            'customer_id' => 1,
            'player_index' => 0,
            'start_date' => null,
            'end_date' => null,
        ]);
        $b = new Roster([
            'customer_id' => 1,
            'player_index' => 0,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-05',
        ]);
        $this->assertFalse($a->conflictsWith($b));
        $this->assertFalse($b->conflictsWith($a));
    }
}
