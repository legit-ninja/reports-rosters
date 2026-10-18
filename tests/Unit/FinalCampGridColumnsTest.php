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

    public function test_echo_camp_metrics_cells_place_sum_matches_header(): void {
        require_once dirname(__DIR__, 2) . '/includes/reports-ui.php';
        $group = [
            ['order_item_id' => 1, 'booking_type' => 'Full Week', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
            ['order_item_id' => 2, 'booking_type' => 'other', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
            ['order_item_id' => 3, 'booking_type' => 'Single Day(s)', 'selected_days' => '', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
        ];
        $header = intersoccer_reports_compute_camp_registration_totals($group, false)['all'];
        $metrics = intersoccer_reports_aggregate_camp_location_group($group, false);
        ob_start();
        intersoccer_reports_echo_camp_metrics_cells($metrics);
        $html = (string) ob_get_clean();
        $this->assertNotSame('', $html);
        if (!preg_match_all('/<td[^>]*>([^<]*)<\/td>/', $html, $matches)) {
            $this->fail('Expected camp metric cells in rendered output');
        }
        $place_cells = array_slice($matches[1], 0, 9);
        $rendered_sum = array_sum(array_map('intval', $place_cells));
        $this->assertSame($header, $rendered_sum);
    }

    public function test_export_metric_place_values_align_with_unknown_columns(): void {
        $group = [
            ['order_item_id' => 1, 'booking_type' => 'Full Week', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
            ['order_item_id' => 2, 'booking_type' => 'other', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
            ['order_item_id' => 3, 'booking_type' => 'Single Day(s)', 'selected_days' => '', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
        ];
        $header = intersoccer_reports_compute_camp_registration_totals($group, false)['all'];
        $metrics = intersoccer_reports_aggregate_camp_location_group($group, false);
        $normalized = intersoccer_reports_normalize_camp_metrics($metrics);
        $export_values = intersoccer_reports_camp_export_metric_place_values($metrics);
        $this->assertSame((int) $normalized['unknown_booking_type'], $export_values[2]);
        $this->assertSame((int) $normalized['days_unknown'], $export_values[3]);
        $this->assertSame($header, array_sum($export_values));
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
