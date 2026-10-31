<?php

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FinalNumbersRowPlacementAccuracyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        if (file_exists($includes . '/utils.php')) {
            require_once $includes . '/utils.php';
        }
        if (file_exists($includes . '/reporting-discounts.php')) {
            require_once $includes . '/reporting-discounts.php';
        }
        if (file_exists($includes . '/final-reports-totals.php')) {
            require_once $includes . '/final-reports-totals.php';
        }
        if (file_exists($includes . '/final-reports-aggregation.php')) {
            require_once $includes . '/final-reports-aggregation.php';
        }
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_wc_get_order_callback']);
        if (class_exists('InterSoccerRRTestMeta')) {
            \InterSoccerRRTestMeta::$data = [];
        }
        parent::tearDown();
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

    public function test_deleted_product_course_row_shows_marker_in_final_numbers_ui_path(): void {
        require_once dirname(__DIR__, 2) . '/includes/reports-ui.php';

        $entry = [
            'roster_row_id' => 901,
            'order_item_id' => 901,
            'order_id' => 0,
            'activity_type' => 'Course',
            'canton' => 'Geneva',
            'venue' => 'Test Venue',
            'season' => 'Spring 2026',
            'start_date' => '2026-01-10',
            'end_date' => '2026-06-01',
            'course_day' => 'Tuesday',
            'product_id' => 88001,
            'variation_id' => 0,
            'order_item_name' => 'Deleted Course Fixture',
            'times' => '17:00-18:00',
            'line_subtotal' => 200,
            'line_total' => 200,
            'event_details' => json_encode(['product_deleted' => true]),
        ];

        $report = intersoccer_reports_build_course_report_from_entries([$entry], false);
        $course_data = null;
        foreach ($report as $season => $regions) {
            if ($season === '__player_registration_totals__' || !is_array($regions)) {
                continue;
            }
            foreach ($regions as $region_rows) {
                if (!is_array($region_rows)) {
                    continue;
                }
                foreach ($region_rows as $row) {
                    if (is_array($row)) {
                        $course_data = $row;
                        break 2;
                    }
                }
            }
        }
        $this->assertIsArray($course_data);
        $this->assertNotEmpty($course_data['has_deleted_product']);
        $this->assertSame(1, (int) ($course_data['registrations'] ?? 0));

        ob_start();
        echo esc_html($course_data['course_name'] ?? '');
        intersoccer_reports_echo_final_deleted_product_marker();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('deleted product', strtolower($html));
        $this->assertStringContainsString('intersoccer-final-deleted-product-marker', $html);
    }

    public function test_paid_buyclub_company_camp_line_lands_in_buyclub_column(): void {
        \InterSoccerRRTestMeta::$data[71001]['_billing_company'] = 'BuyClub SA';
        $entry = $this->campEntry(71, 71001);
        $metrics = $this->campMetricsFromBuild([$entry]);
        $this->assertSame(0, $metrics['full_week']);
        $this->assertSame(1, $metrics['buyclub']);
        $this->assertSame(0, array_sum($metrics['individual_days']));
    }

    public function test_paid_buyclub_coupon_camp_line_lands_in_buyclub_column(): void {
        $order = new class {
            public function get_coupon_codes() {
                return ['partner-buyclub-2026'];
            }
        };
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = static function ($id) use ($order) {
            return (int) $id === 72001 ? $order : null;
        };
        $entry = $this->campEntry(72, 72001, [
            'line_subtotal' => 300,
            'line_total' => 300,
        ]);
        $metrics = $this->campMetricsFromBuild([$entry]);
        $this->assertSame(0, $metrics['full_week']);
        $this->assertSame(1, $metrics['buyclub']);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function campMetricsFromBuild(array $entries): array {
        $report = intersoccer_reports_build_camp_report_from_entries($entries, false, 2026);
        foreach ($report as $week => $cantons) {
            if ($week === '__player_registration_totals__' || !is_array($cantons)) {
                continue;
            }
            foreach ($cantons as $venues) {
                if (!is_array($venues)) {
                    continue;
                }
                foreach ($venues as $venue_data) {
                    if (!is_array($venue_data)) {
                        continue;
                    }
                    return intersoccer_reports_normalize_camp_metrics($venue_data['Full Day'] ?? null);
                }
            }
        }
        $this->fail('Expected camp metrics in build_camp_report_from_entries output');
    }

    /**
     * @return array<string, mixed>
     */
    private function campEntry(int $order_item_id, int $order_id, array $extra = []): array {
        return array_merge([
            'order_item_id' => $order_item_id,
            'order_id' => $order_id,
            'booking_type' => 'Full Week',
            'selected_days' => '',
            'days_selected' => '',
            'age_group' => '5-13y Full Day',
            'activity_type' => 'Camp',
            'canton' => 'Geneva',
            'venue' => 'Placement Venue',
            'season' => 'Summer 2026',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-10',
            'line_subtotal' => 250,
            'line_total' => 250,
        ], $extra);
    }
}
