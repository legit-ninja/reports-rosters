<?php

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FinalNumbersRowPlacementAccuracyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
        $discounts = dirname(__DIR__, 2) . '/includes/reporting-discounts.php';
        if (file_exists($discounts)) {
            require_once $discounts;
        }
    }

    public function test_deleted_product_course_row_stays_in_grid_not_not_on_roster(): void {
        $row = [
            'activity_type' => 'Course',
            'start_date' => '2026-01-10',
            'event_details' => json_encode(['product_deleted' => true]),
        ];
        $this->assertTrue(intersoccer_reports_final_row_is_deleted_product_grid_row($row));
        $this->assertFalse(intersoccer_reports_final_row_counts_as_not_on_roster($row));
    }

    public function test_undated_event_row_counts_as_not_on_roster(): void {
        $row = [
            'activity_type' => 'Event',
            'start_date' => '',
            'event_details' => json_encode([]),
        ];
        $this->assertTrue(intersoccer_reports_final_row_is_undated_event($row));
        $this->assertFalse(intersoccer_reports_final_row_is_deleted_product_grid_row($row));
        $this->assertTrue(intersoccer_reports_final_row_counts_as_not_on_roster($row));
    }

    public function test_thin_attribute_only_line_counts_as_not_on_roster(): void {
        $row = [
            'activity_type' => '',
            'event_details' => json_encode(['thin_line' => true]),
            '_final_numbers_thin_order_line' => true,
        ];
        $this->assertTrue(intersoccer_reports_final_row_is_thin_line($row));
        $this->assertTrue(intersoccer_reports_final_row_counts_as_not_on_roster($row));
    }

    public function test_zero_total_buyclub_coupon_line_classifies_for_buyclub_column(): void {
        $this->assertTrue(intersoccer_reports_line_is_buyclub(200.0, 0.0, 0));
    }
}
