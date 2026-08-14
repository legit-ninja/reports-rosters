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
            || !function_exists('intersoccer_reports_order_status_allowed_for_mode')
            || !function_exists('intersoccer_reports_resolve_program_year')
            || !function_exists('intersoccer_reports_roster_matches_close_year')
            || !function_exists('intersoccer_reports_entry_matches_season_type')
            || !function_exists('intersoccer_reports_season_label_indicates_camp')) {
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
        $this->assertSame(1, $included['full_week']);
        $this->assertSame(1, $included['buyclub']);
        $this->assertSame(1, $excluded['full_week']);
        $this->assertSame(0, $excluded['buyclub']);
        // Min-max base includes BuyClub: min = FW+BC, max = FW+BC+peak singles.
        $this->assertSame('2-2', $included['min_max']);
        $this->assertSame('1-1', $excluded['min_max']);
    }

    public function test_camp_min_max_uses_fw_plus_buyclub_and_peak_singles() {
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
            [
                'order_item_id' => 3,
                'booking_type' => 'Single Days',
                'selected_days' => 'Wednesday',
                'is_buyclub' => false,
            ],
            [
                'order_item_id' => 4,
                'booking_type' => 'Single Days',
                'selected_days' => 'Wednesday',
                'is_buyclub' => false,
            ],
            [
                'order_item_id' => 5,
                'booking_type' => 'Single Days',
                'selected_days' => 'Monday',
                'is_buyclub' => false,
            ],
        ];

        // min = 1 FW + 1 BC = 2; max = 2 + peak Wed(2) = 4
        $agg = intersoccer_reports_aggregate_camp_location_group($group, false);
        $this->assertSame(1, $agg['full_week']);
        $this->assertSame(1, $agg['buyclub']);
        $this->assertSame(2, $agg['individual_days']['Wednesday']);
        $this->assertSame(1, $agg['individual_days']['Monday']);
        $this->assertSame('2-4', $agg['min_max']);
    }

    public function test_camp_grand_totals_match_workbook_formulas() {
        $this->skipIfMissingHelpers();
        if (!function_exists('intersoccer_reports_camp_grand_totals')) {
            $this->markTestSkipped('intersoccer_reports_camp_grand_totals not loaded');
        }

        $report = [
            'July 13 - July 17, 2026' => [
                'Geneva' => [
                    'Varembe' => [
                        'Full Day' => [
                            'full_week' => 10,
                            'buyclub' => 3,
                            'individual_days' => [
                                'Monday' => 5,
                                'Tuesday' => 7,
                                'Wednesday' => 2,
                                'Thursday' => 1,
                                'Friday' => 0,
                            ],
                            'min_max' => '10-17',
                            'unique_records' => 20,
                        ],
                        'Mini - Half Day' => [
                            'full_week' => 6,
                            'buyclub' => 1,
                            'individual_days' => [
                                'Monday' => 1,
                                'Tuesday' => 0,
                                'Wednesday' => 0,
                                'Thursday' => 0,
                                'Friday' => 0,
                            ],
                            'min_max' => '6-7',
                            'unique_records' => 7,
                        ],
                    ],
                ],
            ],
        ];

        $grand = intersoccer_reports_camp_grand_totals($report, false);
        $this->assertSame(10, $grand['full_day']['full_week']);
        $this->assertSame(3, $grand['full_day']['buyclub']);
        $this->assertSame(15, $grand['full_day']['individual_day_slots']);
        $this->assertSame(13, $grand['full_day']['all_registrations']);
        $this->assertSame(6, $grand['mini']['full_week']);
        $this->assertSame(1, $grand['mini']['buyclub']);
        $this->assertSame(1, $grand['mini']['individual_day_slots']);
        $this->assertSame(7, $grand['mini']['all_registrations']);
        $this->assertArrayNotHasKey('pitchside', $grand['full_day']);
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

    public function test_evergreen_season_uses_program_year() {
        $this->skipIfMissingHelpers();

        $entries = [
            [
                'order_item_id' => 1,
                'season' => 'Autumn',
                'program_year' => '2026',
                'booking_type' => 'Full Week',
                'event_start_date' => '2026-10-05',
            ],
            [
                'order_item_id' => 2,
                'season' => 'Autumn',
                'program_year' => '2027',
                'booking_type' => 'Full Week',
                'event_start_date' => '2027-10-04',
            ],
            [
                'order_item_id' => 3,
                'season' => 'Autumn 2026',
                'booking_type' => 'Full Week',
                'event_start_date' => '2026-10-12',
            ],
            [
                'order_item_id' => 4,
                'season' => 'Autumn',
                'program_year' => '2026',
                'booking_type' => 'Full Week',
                // Undated — kept via program_year
            ],
        ];

        $filtered = intersoccer_reports_filter_entries_by_season_year($entries, 2026, 'Autumn');
        $this->assertCount(3, $filtered);
        $ids = array_column($filtered, 'order_item_id');
        $this->assertContains(1, $ids);
        $this->assertContains(3, $ids);
        $this->assertContains(4, $ids);
        $this->assertNotContains(2, $ids);

        $this->assertSame(2026, intersoccer_reports_resolve_program_year($entries[0]));
        $this->assertSame(2027, intersoccer_reports_resolve_program_year($entries[1]));
        $this->assertSame(2026, intersoccer_reports_resolve_program_year($entries[2]));
    }

    public function test_close_season_requires_year_helper() {
        $this->skipIfMissingHelpers();

        $row_2026 = ['season' => 'Autumn', 'program_year' => '2026', 'start_date' => '2026-10-05'];
        $row_2027 = ['season' => 'Autumn', 'program_year' => '2027', 'start_date' => '2027-10-04'];
        $this->assertTrue(intersoccer_reports_roster_matches_close_year($row_2026, 2026));
        $this->assertFalse(intersoccer_reports_roster_matches_close_year($row_2027, 2026));
        $this->assertFalse(intersoccer_reports_roster_matches_close_year($row_2026, 0));
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
        $this->assertSame(2, $report['Spring 2026']['Geneva']['50|Tuesday|Pitch A']['registrations']);
        $this->assertSame(1, $report['Spring 2026']['Geneva']['51|Wednesday|Pitch A']['registrations']);
    }

    public function test_course_season_type_filter_keeps_spring_drops_winter() {
        $this->skipIfMissingHelpers();

        $base = [
            'variation_id' => 0,
            'is_buyclub' => false,
            'line_subtotal' => 50,
            'line_total' => 50,
            'canton' => 'Geneva',
            'venue' => 'Pitch A',
        ];
        $entries = [
            array_merge($base, [
                'roster_row_id' => 1,
                'order_item_id' => 1,
                'product_id' => 10,
                'order_item_name' => 'Winter Monday',
                'course_day' => 'Monday',
                'season' => 'Winter 2026',
                'event_start_date' => '2026-01-12',
            ]),
            array_merge($base, [
                'roster_row_id' => 2,
                'order_item_id' => 2,
                'product_id' => 11,
                'order_item_name' => 'Spring Tuesday',
                'course_day' => 'Tuesday',
                'season' => 'Spring 2026',
                'event_start_date' => '2026-04-07',
            ]),
            array_merge($base, [
                'roster_row_id' => 3,
                'order_item_id' => 3,
                'product_id' => 12,
                'order_item_name' => 'Spring Wednesday',
                'course_day' => 'Wednesday',
                'season' => 'Spring 2026',
                'event_start_date' => '2026-04-08',
            ]),
        ];

        $this->assertTrue(intersoccer_reports_entry_matches_season_type($entries[1], 'Spring'));
        $this->assertFalse(intersoccer_reports_entry_matches_season_type($entries[0], 'Spring'));

        $filtered = array_values(array_filter($entries, static function ($row) {
            return intersoccer_reports_entry_matches_season_type($row, 'Spring');
        }));
        $this->assertCount(2, $filtered);

        $report = intersoccer_reports_build_course_report_from_entries($filtered, false);
        $this->assertSame(2, $report['__player_registration_totals__']['all']);
        unset($report['__player_registration_totals__']);
        $this->assertSame(['Spring 2026'], array_keys($report));
        $this->assertArrayHasKey('11|Tuesday|Pitch A', $report['Spring 2026']['Geneva']);
        $this->assertArrayHasKey('12|Wednesday|Pitch A', $report['Spring 2026']['Geneva']);
    }

    public function test_course_report_does_not_group_under_camp_season_label() {
        $this->skipIfMissingHelpers();

        $this->assertTrue(intersoccer_reports_season_label_indicates_camp('Summer Camps 2026'));
        $this->assertFalse(intersoccer_reports_season_label_indicates_camp('Spring/Summer 2026'));
        $this->assertFalse(intersoccer_reports_season_label_indicates_camp('Summer-courses-2026'));

        $base = [
            'variation_id' => 0,
            'is_buyclub' => false,
            'line_subtotal' => 50,
            'line_total' => 50,
            'canton' => 'Geneva',
            'venue' => 'Pitch A',
        ];
        $entries = [
            array_merge($base, [
                'roster_row_id' => 1,
                'order_item_id' => 1,
                'product_id' => 37424,
                'order_item_name' => 'Mis-stamped Sunday',
                'course_day' => 'Sunday',
                'season' => 'Summer Camps 2026',
                'product_season' => 'Spring/Summer 2026',
            ]),
            array_merge($base, [
                'roster_row_id' => 2,
                'order_item_id' => 2,
                'product_id' => 37424,
                'order_item_name' => 'Correct Sunday',
                'course_day' => 'Sunday',
                'season' => 'Spring/Summer 2026',
            ]),
        ];

        $report = intersoccer_reports_build_course_report_from_entries($entries, false);
        $this->assertSame(2, $report['__player_registration_totals__']['all']);
        unset($report['__player_registration_totals__']);
        $this->assertArrayNotHasKey('Summer Camps 2026', $report);
        $this->assertSame(['Spring/Summer 2026'], array_keys($report));
        $this->assertSame(2, $report['Spring/Summer 2026']['Geneva']['37424|Sunday|Pitch A']['registrations']);
    }

    public function test_course_report_groups_season_region_then_weekday() {
        $this->skipIfMissingHelpers();

        $base = [
            'variation_id' => 0,
            'is_buyclub' => false,
            'line_subtotal' => 50,
            'line_total' => 50,
        ];
        $entries = [
            array_merge($base, [
                'roster_row_id' => 1,
                'order_item_id' => 1,
                'canton' => 'Zurich',
                'venue' => 'Pitch Z',
                'product_id' => 10,
                'order_item_name' => 'Zurich Monday',
                'course_day' => 'Monday',
                'season' => 'Spring 2026',
                'event_start_date' => '2026-04-06',
            ]),
            array_merge($base, [
                'roster_row_id' => 2,
                'order_item_id' => 2,
                'canton' => 'Zurich',
                'venue' => 'Pitch Z',
                'product_id' => 20,
                'order_item_name' => 'Zurich Winter Monday',
                'course_day' => 'Monday',
                'season' => 'Winter 2026',
                'event_start_date' => '2026-01-12',
            ]),
            array_merge($base, [
                'roster_row_id' => 3,
                'order_item_id' => 3,
                'canton' => 'Geneva',
                'venue' => 'Pitch B',
                'product_id' => 11,
                'order_item_name' => 'Geneva Monday B',
                'course_day' => 'Monday',
                'season' => 'Winter 2026',
                'event_start_date' => '2026-01-12',
            ]),
            array_merge($base, [
                'roster_row_id' => 4,
                'order_item_id' => 4,
                'canton' => 'Geneva',
                'venue' => 'Pitch A',
                'product_id' => 12,
                'order_item_name' => 'Geneva Monday A',
                'course_day' => 'Monday',
                'season' => 'Winter 2026',
                'event_start_date' => '2026-01-12',
            ]),
            array_merge($base, [
                'roster_row_id' => 5,
                'order_item_id' => 5,
                'canton' => 'Geneva',
                'venue' => 'Pitch A',
                'product_id' => 13,
                'order_item_name' => 'Geneva Friday',
                'course_day' => 'Friday',
                'season' => 'Winter 2026',
                'event_start_date' => '2026-01-16',
            ]),
            array_merge($base, [
                'roster_row_id' => 6,
                'order_item_id' => 6,
                'canton' => 'Geneva',
                'venue' => 'Pitch A',
                'product_id' => 14,
                'order_item_name' => 'Geneva Wednesday',
                'course_day' => 'Wednesday',
                'season' => 'Winter 2026',
                'event_start_date' => '2026-01-14',
            ]),
            array_merge($base, [
                'roster_row_id' => 7,
                'order_item_id' => 7,
                'canton' => 'Geneva',
                'venue' => 'Pitch C',
                'product_id' => 15,
                'order_item_name' => 'Geneva Vendredi',
                'course_day' => 'vendredi',
                'season' => 'Winter 2026',
                'event_start_date' => '2026-01-16',
            ]),
            array_merge($base, [
                'roster_row_id' => 8,
                'order_item_id' => 8,
                'canton' => 'Geneva',
                'venue' => 'Pitch B',
                'product_id' => 12,
                'order_item_name' => 'Geneva Monday A at B',
                'course_day' => 'Monday',
                'season' => 'Winter 2026',
                'event_start_date' => '2026-01-12',
            ]),
        ];

        $report = intersoccer_reports_build_course_report_from_entries($entries, false);
        $this->assertSame(8, $report['__player_registration_totals__']['all']);
        unset($report['__player_registration_totals__']);

        $this->assertSame(['Winter 2026', 'Spring 2026'], array_keys($report));
        $this->assertSame(['Geneva', 'Zurich'], array_keys($report['Winter 2026']));
        $this->assertSame(['Zurich'], array_keys($report['Spring 2026']));

        $geneva_rows = array_values($report['Winter 2026']['Geneva']);
        $days = array_column($geneva_rows, 'course_day');
        $this->assertSame(
            ['Monday', 'Monday', 'Monday', 'Wednesday', 'Friday', 'Friday'],
            $days,
            'Within a region, rows must sort Monday through Sunday'
        );

        $monday_venues = [];
        foreach ($geneva_rows as $row) {
            if ($row['course_day'] === 'Monday') {
                $monday_venues[] = $row['venue'];
            }
        }
        $this->assertSame(['Pitch A', 'Pitch B', 'Pitch B'], $monday_venues);

        $this->assertSame(1, $report['Winter 2026']['Geneva']['12|Monday|Pitch A']['registrations']);
        $this->assertSame(1, $report['Winter 2026']['Geneva']['12|Monday|Pitch B']['registrations']);
        $this->assertSame('Friday', $report['Winter 2026']['Geneva']['15|Friday|Pitch C']['course_day']);
        $this->assertSame('Pitch C', $report['Winter 2026']['Geneva']['15|Friday|Pitch C']['venue']);
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
            // Index 12 = All registrations (Full Week + BuyClub), not FW+sum(days).
            $excel_total += (int) $row[12];
        }

        $cell_total = 0;
        foreach ($report as $week => $cantons) {
            if ($week === '__player_registration_totals__' || !is_array($cantons)) {
                continue;
            }
            foreach ($cantons as $venues) {
                foreach ($venues as $camp_types) {
                    foreach ($camp_types as $data) {
                        $cell_total += (int) ($data['full_week'] ?? 0)
                            + (int) ($data['buyclub'] ?? 0);
                    }
                }
            }
        }

        $this->assertSame($cell_total, $excel_total);
        $this->assertGreaterThan(0, $excel_total);
    }

    public function test_camp_girls_only_separate_venue_grouping() {
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
                'girls_only' => 0,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
            [
                'order_item_id' => 2,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Venue A',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
                'is_buyclub' => false,
                'girls_only' => 1,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
            [
                'order_item_id' => 3,
                'booking_type' => 'Single Days',
                'selected_days' => 'Monday',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Venue A',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
                'is_buyclub' => false,
                'girls_only' => 0,
                'line_subtotal' => 50,
                'line_total' => 50,
            ],
        ];

        $report = intersoccer_reports_build_camp_report_from_entries($entries, false, 2026);
        $week_key = 'July 6 - July 10, 2026';

        $this->assertArrayHasKey($week_key, $report);
        $venues = $report[$week_key]['Geneva'];
        $this->assertArrayHasKey('Venue A', $venues, 'Regular venue row should exist');
        $this->assertArrayHasKey('Venue A (Girls Only)', $venues, 'Girls-only venue row should exist');

        $regular = $venues['Venue A']['Full Day'];
        $girls = $venues['Venue A (Girls Only)']['Full Day'];

        $this->assertSame(1, $regular['full_week']);
        $this->assertSame(1, $regular['individual_days']['Monday']);
        $this->assertSame('1-2', $regular['min_max']);

        $this->assertSame(1, $girls['full_week']);
        $this->assertSame('1-1', $girls['min_max']);
    }

    public function test_camp_girls_only_via_activity_type_text() {
        $this->skipIfMissingHelpers();

        $entries = [
            [
                'order_item_id' => 1,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Venue B',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
                'is_buyclub' => false,
                'activity_type' => 'Camp, Girls Only',
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
            [
                'order_item_id' => 2,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Venue B',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
                'is_buyclub' => false,
                'activity_type' => 'Camp',
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
        ];

        $report = intersoccer_reports_build_camp_report_from_entries($entries, false, 2026);
        $week_key = 'July 6 - July 10, 2026';
        $venues = $report[$week_key]['Geneva'];

        $this->assertArrayHasKey('Venue B', $venues, 'Regular venue row should exist');
        $this->assertArrayHasKey('Venue B (Girls Only)', $venues, 'Girls-only venue row from activity_type text should exist');
        $this->assertSame(1, $venues['Venue B']['Full Day']['full_week']);
        $this->assertSame(1, $venues['Venue B (Girls Only)']['Full Day']['full_week']);
    }

    public function test_french_camp_terms_slug_parses_to_english_calendar_date_range() {
        $this->skipIfMissingHelpers();
        if (!function_exists('intersoccer_parse_camp_dates_fixed')) {
            $this->markTestSkipped('intersoccer_parse_camp_dates_fixed not loaded');
        }

        // Regression: French camp_terms slug must group under English calendar Date Range
        list($start, $end) = intersoccer_parse_camp_dates_fixed(
            'semaine-dete-4-13-17-juillet-5-jours',
            'Summer 2026'
        );
        $this->assertSame('2026-07-13', $start);
        $this->assertSame('2026-07-17', $end);

        list($de_start, $de_end) = intersoccer_parse_camp_dates_fixed(
            'sommer-woche-4-13-17-juli-5-tage',
            'Summer 2026'
        );
        $this->assertSame('2026-07-13', $de_start);
        $this->assertSame('2026-07-17', $de_end);

        $report = intersoccer_reports_build_camp_report_from_entries([
            [
                'order_item_id' => 901,
                'booking_type' => 'Full Week',
                'selected_days' => '',
                'age_group' => '6-9y',
                'canton' => 'Geneva',
                'venue' => 'Venue A',
                'camp_terms' => 'semaine-dete-4-13-17-juillet-5-jours',
                'season' => 'Summer 2026',
                'event_start_date' => '',
                'event_end_date' => '',
                'is_buyclub' => false,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
        ], false, 2026);

        $this->assertArrayHasKey('July 13 - July 17, 2026', $report);
        $this->assertArrayNotHasKey('semaine-dete-4-13-17-juillet-5-jours', $report);
        $this->assertSame(1, $report['July 13 - July 17, 2026']['Geneva']['Venue A']['Full Day']['full_week']);
    }

    public function test_camp_date_groups_sorted_chronologically() {
        $this->skipIfMissingHelpers();

        $entries = [
            [
                'order_item_id' => 1,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Venue A',
                'event_start_date' => '2026-07-13',
                'event_end_date' => '2026-07-17',
                'is_buyclub' => false,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
            [
                'order_item_id' => 2,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Venue B',
                'event_start_date' => '2026-06-29',
                'event_end_date' => '2026-07-03',
                'is_buyclub' => false,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
            [
                'order_item_id' => 3,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Zurich',
                'venue' => 'Venue C',
                'event_start_date' => '2026-07-06',
                'event_end_date' => '2026-07-10',
                'is_buyclub' => false,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
        ];

        $report = intersoccer_reports_build_camp_report_from_entries($entries, false, 2026);

        $keys = array_keys($report);
        $keys = array_values(array_filter($keys, function ($k) {
            return $k !== '__player_registration_totals__';
        }));

        $this->assertSame([
            'June 29 - July 3, 2026',
            'July 6 - July 10, 2026',
            'July 13 - July 17, 2026',
        ], $keys, 'Date groups must be in chronological order');
    }
}
