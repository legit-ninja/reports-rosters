<?php
/**
 * Final Numbers registration totalling helpers and status policy.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FinalReportsTotalsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        if (file_exists($includes . '/utils.php')) {
            require_once $includes . '/utils.php';
        }
        if (file_exists($includes . '/final-reports-totals.php')) {
            require_once $includes . '/final-reports-totals.php';
        }
    }

    public function test_final_report_order_statuses_returns_wc_completed_only() {
        if (!function_exists('intersoccer_reports_final_report_order_statuses')) {
            $this->markTestSkipped('intersoccer_reports_final_report_order_statuses not loaded');
        }
        $this->assertSame(['wc-completed'], intersoccer_reports_final_report_order_statuses());
        $this->assertSame(['wc-completed'], intersoccer_reports_final_report_order_statuses('final'));
    }

    public function test_final_report_order_statuses_live_includes_processing() {
        if (!function_exists('intersoccer_reports_final_report_order_statuses')) {
            $this->markTestSkipped('intersoccer_reports_final_report_order_statuses not loaded');
        }
        $this->assertSame(
            ['wc-completed', 'wc-processing'],
            intersoccer_reports_final_report_order_statuses('live')
        );
    }

    public function test_calculate_final_reports_totals_full_week_and_individual_days() {
        if (!function_exists('intersoccer_calculate_final_reports_totals')) {
            require_once dirname(__DIR__, 2) . '/includes/reports-data.php';
        }
        if (!function_exists('intersoccer_calculate_final_reports_totals')) {
            $this->markTestSkipped('intersoccer_calculate_final_reports_totals not loaded');
        }

        $report_data = [
            'Week of 2026-07-06' => [
                'Geneva' => [
                    'Venue A' => [
                        'Full Day' => [
                            'full_week' => 2,
                            'individual_days' => [
                                'Monday' => 1,
                                'Tuesday' => 0,
                                'Wednesday' => 1,
                                'Thursday' => 0,
                                'Friday' => 0,
                            ],
                            'min_max' => '2-3',
                            'unique_records' => 4,
                        ],
                        'Mini - Half Day' => [
                            'full_week' => 1,
                            'individual_days' => [
                                'Monday' => 0,
                                'Tuesday' => 0,
                                'Wednesday' => 0,
                                'Thursday' => 0,
                                'Friday' => 0,
                            ],
                            'min_max' => '1-1',
                            'unique_records' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $totals = intersoccer_calculate_final_reports_totals($report_data, 'Camp');

        $this->assertSame(2, $totals['full_day']['full_week']);
        $this->assertSame(1, $totals['full_day']['individual_days']['Monday']);
        $this->assertSame(1, $totals['full_day']['individual_days']['Wednesday']);
        $this->assertSame(1, $totals['mini']['full_week']);
        $this->assertSame(3, $totals['all']['full_week']);
    }

    public function test_camp_registration_totals_mini_vs_full_day() {
        if (!function_exists('intersoccer_reports_compute_camp_registration_totals')) {
            $this->markTestSkipped('intersoccer_reports_compute_camp_registration_totals not loaded');
        }

        $rows = [
            ['age_group' => '6-9y Full Day', 'line_subtotal' => 100, 'line_total' => 100],
            ['age_group' => '3-5y Half Day', 'line_subtotal' => 80, 'line_total' => 80],
            ['age_group' => 'Mini half-day camp', 'line_subtotal' => 70, 'line_total' => 70],
        ];

        $totals = intersoccer_reports_compute_camp_registration_totals($rows, false);

        $this->assertSame(1, $totals['full_day']);
        $this->assertSame(2, $totals['mini']);
        $this->assertSame(3, $totals['all']);
    }

    public function test_camp_registration_totals_excludes_buyclub() {
        if (!function_exists('intersoccer_reports_compute_camp_registration_totals')) {
            $this->markTestSkipped('intersoccer_reports_compute_camp_registration_totals not loaded');
        }

        $rows = [
            ['age_group' => '6-9y', 'line_subtotal' => 100, 'line_total' => 100, 'is_buyclub' => false],
            ['age_group' => '6-9y', 'line_subtotal' => 120, 'line_total' => 0, 'is_buyclub' => true],
        ];

        $included = intersoccer_reports_compute_camp_registration_totals($rows, false);
        $excluded = intersoccer_reports_compute_camp_registration_totals($rows, true);

        $this->assertSame(2, $included['all']);
        $this->assertSame(1, $excluded['all']);
    }

    public function test_course_registration_total_dedupes_by_roster_row_id() {
        if (!function_exists('intersoccer_reports_compute_course_registration_total')) {
            $this->markTestSkipped('intersoccer_reports_compute_course_registration_total not loaded');
        }

        $rows = [
            ['roster_row_id' => 101, 'order_item_id' => 5001, 'line_subtotal' => 50, 'line_total' => 50],
            ['roster_row_id' => 102, 'order_item_id' => 5001, 'line_subtotal' => 50, 'line_total' => 50],
        ];

        $this->assertSame(2, intersoccer_reports_compute_course_registration_total($rows, false));
    }

    public function test_course_registration_total_dedupes_by_order_item_fallback() {
        if (!function_exists('intersoccer_reports_compute_course_registration_total')) {
            $this->markTestSkipped('intersoccer_reports_compute_course_registration_total not loaded');
        }

        $rows = [
            ['order_item_id' => 6001, 'line_subtotal' => 50, 'line_total' => 50],
            ['order_item_id' => 6001, 'line_subtotal' => 50, 'line_total' => 50],
            ['order_item_id' => 6002, 'line_subtotal' => 50, 'line_total' => 50],
        ];

        $this->assertSame(2, intersoccer_reports_compute_course_registration_total($rows, false));
    }
}
