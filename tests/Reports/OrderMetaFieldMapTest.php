<?php
/**
 * Order meta field map and SQL key candidate tests.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class OrderMetaFieldMapTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (file_exists(dirname(__DIR__, 2) . '/includes/order-meta-keys.php')) {
            require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        }
    }

    public function test_field_map_includes_attendee_and_player_keys() {
        if (!function_exists('intersoccer_get_order_meta_field_map')) {
            $this->markTestSkipped('order-meta-keys not loaded');
        }

        $map = intersoccer_get_order_meta_field_map();
        $this->assertSame('attendee_dob', $map['Attendee DOB']);
        $this->assertSame('attendee_gender', $map['Attendee Gender']);
        $this->assertSame('medical_conditions', $map['Medical Conditions']);
        $this->assertSame('player_index', $map['assigned_player']);
    }

    public function test_normalize_attendee_gender_aliases() {
        if (!function_exists('intersoccer_normalize_order_item_meta_key')) {
            $this->markTestSkipped('normalize helper not loaded');
        }

        $this->assertSame('Attendee Gender', intersoccer_normalize_order_item_meta_key('gender'));
        $this->assertSame('Attendee Gender', intersoccer_normalize_order_item_meta_key('Genre du participant'));
    }

    public function test_sql_meta_key_candidates_for_reports() {
        if (!function_exists('intersoccer_reports_sql_meta_key_candidates')) {
            $this->markTestSkipped('sql meta helper not loaded');
        }

        $this->assertSame(['Days Selected', 'Days of Week'], intersoccer_reports_sql_meta_key_candidates('selected_days'));
        $this->assertSame(['Attendee Gender', 'gender'], intersoccer_reports_sql_meta_key_candidates('attendee_gender'));
        $this->assertContains('Discount', intersoccer_reports_sql_meta_key_candidates('discount_applied'));
    }
}
