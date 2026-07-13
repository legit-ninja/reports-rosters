<?php
/**
 * Golden fixtures for Final Numbers / Live Snapshot aggregation accuracy.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FinalReportsAggregationAccuracyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        // Load utils first for booking-type / weekday / buyclub helpers (side effects OK under bootstrap stubs).
        if (file_exists($includes . '/utils.php')) {
            require_once $includes . '/utils.php';
        }
        if (file_exists($includes . '/final-reports-totals.php')) {
            require_once $includes . '/final-reports-totals.php';
        }
        if (file_exists($includes . '/final-reports-aggregation.php')) {
            require_once $includes . '/final-reports-aggregation.php';
        }
        // extract_year_from_season / extract_season_type live in reports-data.php
        if (file_exists($includes . '/reports-data.php') && !function_exists('intersoccer_extract_season_type')) {
            require_once $includes . '/reports-data.php';
        }
    }

    private function skipIfMissingHelpers() {
        if (!function_exists('intersoccer_reports_aggregate_camp_location_group')
            || !function_exists('intersoccer_reports_build_camp_report_from_entries')
            || !function_exists('intersoccer_reports_filter_entries_by_season_year')
            || !function_exists('intersoccer_reports_build_course_report_from_entries')
            || !function_exists('intersoccer_reports_camp_excel_data_rows')
            || !function_exists('intersoccer_reports_order_status_allowed_for_mode')) {
            $this->markTestSkipped('Final reports aggregation helpers not loaded');
        }
    }

    public function test_camp_full_week_and_individual_day_counts() {
        $this->skipIfMissingHelpers();

        $group = [
            [
                'order_item_id' => 1,
                'booking_type' => 'Full Week',
                'selected_days' => '',
                'age_group' => '6-9y Full Day',
                'is_buyclub' => false,
            ],
            [
                'order_item_id' => 2,
                'booking_type' => 'Full Week',
                'selected_days' => '',
                'age_group' => '6-9y Full Day',
                'is_buyclub' => false,
            ],
            [
                'order_item_id' => 3,
                'booking_type' => 'Single Days',
                'selected_days' => 'Monday',
                'age_group' => '6-9y Full Day',
                'is_buyclub' => false,
            ],
            [
                'order_item_id' => 4,
                'booking_type' => 'Single Days',
                'selected_days' => 'Wednesday',
                'age_group' => '6-9y Full Day',
                'is_buyclub' => false,
            ],
        ];

        $agg = intersoccer_reports_aggregate_camp_location_group($group, false);

        $this->assertSame(2, $agg['full_week']);
        $this->assertSame(1, $agg['individual_days']['Monday']);
        $this->assertSame(0, $agg['individual_days']['Tuesday']);
        $this->assertSame(1, $agg['individual_days']['Wednesday']);
        $this->assertSame('2-3', $agg['min_max']);
        $this->assertSame(4, $agg['unique_records']);
    }

    public function test_camp_dedupes_duplicate_order_item_id() {
        $this->skipIfMissingHelpers();

        $group = [
            [
                'order_item_id' => 10,
                'booking_type' => 'Full Week',
                'is_buyclub' => false,
            ],
            [
                'order_item_id' => 10,
                'booking_type' => 'Full Week',
                'is_buyclub' => false,
            ],
        ];

        $agg = intersoccer_reports_aggregate_camp_location_group($group, false);
        $this->assertSame(1, $agg['full_week']);
        $this->assertSame(1, $agg['unique_records']);
    }

    public function test_camp_mini_vs_full_day_buckets_in_report() {
        $this->skipIfMissingHelpers();

        $entries = [
            [
                'order_item_id' => 1,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Venue A',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
                'is_buyclub' => false,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
            [
                'order_item_id' => 2,
                'booking_type' => 'Full Week',
                'age_group' => '3-5y Half Day',
                'canton' => 'Geneva',
                'venue' => 'Venue A',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
                'is_buyclub' => false,
                'line_subtotal' => 80,
                'line_total' => 80,
            ],
        ];

        $report = intersoccer_reports_build_camp_report_from_entries($entries, false, 2026);
        $week_key = 'July 6 - July 10, 2026';
        $this->assertArrayHasKey($week_key, $report);
        $this->assertArrayHasKey('Full Day', $report[$week_key]['Geneva']['Venue A']);
        $this->assertArrayHasKey('Mini - Half Day', $report[$week_key]['Geneva']['Venue A']);
        $this->assertSame(1, $report[$week_key]['Geneva']['Venue A']['Full Day']['full_week']);
        $this->assertSame(1, $report[$week_key]['Geneva']['Venue A']['Mini - Half Day']['full_week']);
    }

    public function test_camp_buyclub_exclude() {
        $this->skipIfMissingHelpers();

        $group = [
            [
                'order_item_id' => 1,
                'booking_type' => 'Full Week',
                'is_buyclub' => false,
            ],
            [
                'order_item_id' => 2,
                'booking_type' => 'Full Week',
                'is_buyclub' => true,
                'line_subtotal' => 100,
                'line_total' => 0,
            ],
        ];

        $included = intersoccer_reports_aggregate_camp_location_group($group, false);
        $excluded = intersoccer_reports_aggregate_camp_location_group($group, true);
        $this->assertSame(2, $included['full_week']);
        $this->assertSame(1, $excluded['full_week']);
    }

    public function test_summer_season_filter_keeps_summer_drops_winter() {
        $this->skipIfMissingHelpers();

        $entries = [
            [
                'order_item_id' => 1,
                'season' => 'Summer 2026',
                'booking_type' => 'Full Week',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
            ],
            [
                'order_item_id' => 2,
                'season' => 'Winter 2026',
                'booking_type' => 'Full Week',
                'event_start_date' => '2026-01-06',
                'event_end_date' => '2026-01-10',
            ],
        ];

        $filtered = intersoccer_reports_filter_entries_by_season_year($entries, 2026, 'Summer');
        $this->assertCount(1, $filtered);
        $this->assertSame('Summer 2026', $filtered[0]['season']);
    }

    public function test_live_vs_final_order_status_mode() {
        $this->skipIfMissingHelpers();

        $this->assertTrue(intersoccer_reports_order_status_allowed_for_mode('wc-completed', 'final'));
        $this->assertFalse(intersoccer_reports_order_status_allowed_for_mode('wc-processing', 'final'));
        $this->assertTrue(intersoccer_reports_order_status_allowed_for_mode('wc-processing', 'live'));
        $this->assertTrue(intersoccer_reports_order_status_allowed_for_mode('processing', 'live'));

        $completed = [
            [
                'order_item_id' => 1,
                'booking_type' => 'Full Week',
                'order_status' => 'wc-completed',
                'is_buyclub' => false,
            ],
        ];
        $with_processing = array_merge($completed, [
            [
                'order_item_id' => 2,
                'booking_type' => 'Full Week',
                'order_status' => 'wc-processing',
                'is_buyclub' => false,
            ],
        ]);

        $final_rows = array_values(array_filter($with_processing, static function ($row) {
            return intersoccer_reports_order_status_allowed_for_mode($row['order_status'], 'final');
        }));
        $live_rows = array_values(array_filter($with_processing, static function ($row) {
            return intersoccer_reports_order_status_allowed_for_mode($row['order_status'], 'live');
        }));

        $this->assertSame(1, intersoccer_reports_aggregate_camp_location_group($final_rows, false)['full_week']);
        $this->assertSame(2, intersoccer_reports_aggregate_camp_location_group($live_rows, false)['full_week']);
    }

    public function test_course_dedupe_and_year_filter() {
        $this->skipIfMissingHelpers();

        $entries = [
            [
                'roster_row_id' => 101,
                'order_item_id' => 5001,
                'canton' => 'Geneva',
                'venue' => 'Pitch A',
                'product_id' => 50,
                'variation_id' => 0,
                'order_item_name' => 'Tuesday Course',
                'course_day' => 'Tuesday',
                'season' => 'Spring 2026',
                'is_buyclub' => false,
                'line_subtotal' => 50,
                'line_total' => 50,
            ],
            [
                'roster_row_id' => 102,
                'order_item_id' => 5001,
                'canton' => 'Geneva',
                'venue' => 'Pitch A',
                'product_id' => 50,
                'variation_id' => 0,
                'order_item_name' => 'Tuesday Course',
                'course_day' => 'Tuesday',
                'season' => 'Spring 2026',
                'is_buyclub' => false,
                'line_subtotal' => 50,
                'line_total' => 50,
            ],
            [
                'order_item_id' => 6001,
                'canton' => 'Geneva',
                'venue' => 'Pitch A',
                'product_id' => 51,
                'variation_id' => 0,
                'order_item_name' => 'Fallback Course',
                'course_day' => 'Wednesday',
                'season' => 'Spring 2026',
                'is_buyclub' => false,
                'line_subtotal' => 50,
                'line_total' => 50,
            ],
            [
                'order_item_id' => 6001,
                'canton' => 'Geneva',
                'venue' => 'Pitch A',
                'product_id' => 51,
                'variation_id' => 0,
                'order_item_name' => 'Fallback Course',
                'course_day' => 'Wednesday',
                'season' => 'Spring 2026',
                'is_buyclub' => false,
                'line_subtotal' => 50,
                'line_total' => 50,
            ],
            [
                'roster_row_id' => 200,
                'order_item_id' => 7001,
                'canton' => 'Geneva',
                'venue' => 'Pitch A',
                'product_id' => 52,
                'variation_id' => 0,
                'order_item_name' => 'Old Course',
                'course_day' => 'Monday',
                'season' => 'Spring 2025',
                'is_buyclub' => false,
                'line_subtotal' => 50,
                'line_total' => 50,
            ],
        ];

        $filtered = intersoccer_reports_filter_entries_by_season_year($entries, 2026, null);
        $this->assertCount(4, $filtered);

        $report = intersoccer_reports_build_course_report_from_entries($filtered, false);
        $this->assertSame(3, $report['__player_registration_totals__']['all']);
        $this->assertSame(2, $report['Geneva']['Pitch A']['50|Tuesday']['registrations']);
        $this->assertSame(1, $report['Geneva']['Pitch A']['51|Wednesday']['registrations']);
    }

    public function test_camp_excel_row_totals_match_aggregation() {
        $this->skipIfMissingHelpers();

        $entries = [
            [
                'order_item_id' => 1,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y',
                'canton' => 'Geneva',
                'venue' => 'Venue A',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
                'is_buyclub' => false,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
            [
                'order_item_id' => 2,
                'booking_type' => 'Single Days',
                'selected_days' => 'Monday, Friday',
                'age_group' => '6-9y',
                'canton' => 'Geneva',
                'venue' => 'Venue A',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
                'is_buyclub' => false,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
        ];

        $report = intersoccer_reports_build_camp_report_from_entries($entries, false, 2026);
        $excel_rows = intersoccer_reports_camp_excel_data_rows($report);

        $excel_total = 0;
        foreach ($excel_rows as $row) {
            $excel_total += (int) $row[11];
        }

        $cell_total = 0;
        foreach ($report as $week => $cantons) {
            if ($week === '__player_registration_totals__' || !is_array($cantons)) {
                continue;
            }
            foreach ($cantons as $venues) {
                foreach ($venues as $camp_types) {
                    foreach ($camp_types as $data) {
                        $cell_total += (int) $data['full_week'] + array_sum($data['individual_days']);
                    }
                }
            }
        }

        $this->assertSame($cell_total, $excel_total);
        $this->assertGreaterThan(0, $excel_total);
    }
}
