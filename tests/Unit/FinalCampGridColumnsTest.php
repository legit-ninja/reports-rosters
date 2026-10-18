<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FinalCampGridColumnsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        require_once $includes . '/final-reports-totals.php';
        require_once $includes . '/final-reports-aggregation.php';
    }

    public function test_visible_column_sum_matches_header_for_mixed_booking_types(): void {
        $group = [
            ['order_item_id' => 1, 'booking_type' => 'Full Week', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
            ['order_item_id' => 2, 'booking_type' => 'other', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
            ['order_item_id' => 3, 'booking_type' => 'Single Day(s)', 'selected_days' => '', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
        ];
        $header = intersoccer_reports_compute_camp_registration_totals($group, false)['all'];
        $metrics = intersoccer_reports_aggregate_camp_location_group($group, false);
        $visible = intersoccer_reports_camp_metrics_visible_place_sum($metrics);
        $this->assertSame($header, $visible);
    }

    public function test_merge_activity_scope_keeps_camp_thin_lines_off_course(): void {
        require_once dirname(__DIR__, 2) . '/includes/reports-data.php';
        $camp_row = ['activity_type' => 'Camp', 'order_item_id' => 1];
        $course_row = ['activity_type' => 'Course', 'order_item_id' => 2];
        $this->assertTrue(intersoccer_reports_merge_row_matches_activity_scope($camp_row, 'camp'));
        $this->assertFalse(intersoccer_reports_merge_row_matches_activity_scope($camp_row, 'course'));
        $this->assertTrue(intersoccer_reports_merge_row_matches_activity_scope($course_row, 'course'));
        $this->assertFalse(intersoccer_reports_merge_row_matches_activity_scope($course_row, 'camp'));
    }
}
