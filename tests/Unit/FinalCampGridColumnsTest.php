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

    public function test_multi_day_single_day_places_reconcile_header_helper_ui_and_export(): void {
        require_once dirname(__DIR__, 2) . '/includes/reports-ui.php';
        $group = [
            ['order_item_id' => 10, 'booking_type' => 'Full Week', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100, 'line_quantity' => 1],
            ['order_item_id' => 11, 'booking_type' => 'Single Day(s)', 'selected_days' => 'Monday, Tuesday', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
            ['order_item_id' => 12, 'booking_type' => 'Full Week', 'is_buyclub' => false, 'line_subtotal' => 200, 'line_total' => 200, 'line_quantity' => 2],
        ];
        $header = intersoccer_reports_compute_camp_registration_totals($group, false)['all'];
        $metrics = intersoccer_reports_aggregate_camp_location_group($group, false);
        $helper_sum = intersoccer_reports_camp_metrics_visible_place_sum($metrics);
        $this->assertSame(4, $header);
        $this->assertSame(4, $helper_sum);
        $this->assertSame(1, (int) $metrics['single_day_places']);
        $this->assertSame(1, (int) $metrics['individual_days']['Monday']);
        $this->assertSame(1, (int) $metrics['individual_days']['Tuesday']);

        ob_start();
        intersoccer_reports_echo_camp_metrics_cells($metrics);
        $html = (string) ob_get_clean();
        if (!preg_match_all('/<td[^>]*>([^<]*)<\/td>/', $html, $matches)) {
            $this->fail('Expected camp metric cells in rendered output');
        }
        $place_cells = array_slice($matches[1], 0, 5);
        $slot_cells = array_slice($matches[1], 5, 5);
        $this->assertSame($header, array_sum(array_map('intval', $place_cells)));
        $this->assertSame(2, array_sum(array_map('intval', $slot_cells)));

        $export_places = intersoccer_reports_camp_export_metric_place_values($metrics);
        $this->assertSame($header, array_sum($export_places));
        $export_row = intersoccer_reports_camp_export_metric_row_values($metrics);
        $this->assertSame($header, array_sum(array_slice($export_row, 0, 5)));
        $this->assertSame(2, array_sum(array_slice($export_row, 5, 5)));
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
        $place_cells = array_slice($matches[1], 0, 5);
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

    public function test_empty_activity_type_scoped_by_cached_product_meta_without_writes(): void {
        require_once dirname(__DIR__, 2) . '/includes/reports-data.php';
        \InterSoccerRRTestMeta::$data[9001]['_intersoccer_product_type'] = 'course';
        \InterSoccerRRTestMeta::$data[9002]['_intersoccer_product_type'] = 'camp';
        $meta_after_fixture = json_encode(\InterSoccerRRTestMeta::$data);

        $course_row = ['activity_type' => '', 'product_id' => 9001, 'order_item_id' => 0];
        $this->assertFalse(intersoccer_reports_merge_row_matches_activity_scope($course_row, 'camp'));
        $this->assertTrue(intersoccer_reports_merge_row_matches_activity_scope($course_row, 'course'));

        $camp_row = ['activity_type' => '', 'product_id' => 9002, 'order_item_id' => 0];
        $this->assertTrue(intersoccer_reports_merge_row_matches_activity_scope($camp_row, 'camp'));
        $this->assertFalse(intersoccer_reports_merge_row_matches_activity_scope($camp_row, 'course'));

        $unknown_row = ['activity_type' => '', 'product_id' => 9003, 'order_item_id' => 0];
        $this->assertTrue(intersoccer_reports_merge_row_matches_activity_scope($unknown_row, 'camp'));
        $this->assertFalse(intersoccer_reports_merge_row_matches_activity_scope($unknown_row, 'course'));

        $this->assertSame($meta_after_fixture, json_encode(\InterSoccerRRTestMeta::$data));
    }

    /**
     * @return array{camp_grand: array, place_total: int, fd: array, mini_place: int}
     */
    private function campGrandTotalsFixtureForReview8(): array {
        $group1 = [
            ['order_item_id' => 1, 'booking_type' => 'Full Week', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
            ['order_item_id' => 2, 'booking_type' => 'other', 'is_buyclub' => false, 'line_subtotal' => 100, 'line_total' => 100],
            [
                'order_item_id' => 3,
                'booking_type' => 'Single Day(s)',
                'selected_days' => 'Monday, Tuesday',
                'is_buyclub' => false,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
        ];
        $group2 = [
            [
                'order_item_id' => 4,
                'booking_type' => 'Full Week',
                'is_buyclub' => false,
                'line_subtotal' => 200,
                'line_total' => 200,
                'line_quantity' => 2,
            ],
            [
                'order_item_id' => 5,
                'booking_type' => 'Single Day(s)',
                'selected_days' => '',
                'is_buyclub' => false,
                'line_subtotal' => 100,
                'line_total' => 100,
            ],
        ];
        $m1 = intersoccer_reports_aggregate_camp_location_group($group1, false);
        $m2 = intersoccer_reports_aggregate_camp_location_group($group2, false);
        $empty = intersoccer_reports_empty_camp_metrics();
        $report = [
            'Week A' => [
                'Canton A' => [
                    'Venue A' => [
                        'Full Day' => $m1,
                        'Mini - Half Day' => $empty,
                    ],
                ],
            ],
            'Week B' => [
                'Canton B' => [
                    'Venue B' => [
                        'Full Day' => $m2,
                        'Mini - Half Day' => $empty,
                    ],
                ],
            ],
        ];
        $camp_grand = intersoccer_reports_camp_grand_totals($report, false);
        $fd = $camp_grand['full_day'];
        $place_total = (int) $fd['full_week']
            + (int) $fd['buyclub']
            + (int) ($fd['unknown_booking_type'] ?? 0)
            + (int) ($fd['days_unknown'] ?? 0)
            + (int) ($fd['single_day_places'] ?? 0);

        return [
            'camp_grand' => $camp_grand,
            'place_total' => $place_total,
            'fd' => $fd,
            'mini_place' => intersoccer_reports_camp_metrics_visible_place_sum($camp_grand['mini']),
        ];
    }

    public function test_camp_grand_all_registrations_matches_place_buckets_and_ui_footers(): void {
        require_once dirname(__DIR__, 2) . '/includes/reports-ui.php';
        $fixture = $this->campGrandTotalsFixtureForReview8();
        $grand = $fixture['camp_grand'];
        $place_total = $fixture['place_total'];
        $fd = $fixture['fd'];

        $this->assertSame($place_total, intersoccer_reports_camp_metrics_visible_place_sum($fd));
        $this->assertSame($place_total, (int) ($fd['all_registrations'] ?? 0));

        ob_start();
        intersoccer_reports_echo_camp_grand_footer_rows($grand, 'full_day');
        $html_fd = (string) ob_get_clean();
        $this->assertStringContainsString('class="all-reg"', $html_fd);
        if (!preg_match('/class="all-reg"[\s\S]*?<td colspan="5">(\d+)<\/td>/', $html_fd, $all_reg_match)) {
            $this->fail('Expected All registrations place total in full-day footer');
        }
        $this->assertSame((string) $place_total, $all_reg_match[1]);
        if (!preg_match('/class="grand-total"[\s\S]*?<td>(\d+)<\/td>\s*<td>(\d+)<\/td>\s*<td>(\d+)<\/td>\s*<td>(\d+)<\/td>\s*<td>(\d+)<\/td>/', $html_fd, $total_match)) {
            $this->fail('Expected TOTAL place bucket cells in full-day footer');
        }
        $this->assertSame($place_total, array_sum(array_map('intval', array_slice($total_match, 1))));

        ob_start();
        intersoccer_reports_echo_camp_grand_footer_rows($grand, 'mini');
        $html_mini = (string) ob_get_clean();
        if (!preg_match('/class="all-reg"[\s\S]*?<td colspan="5">(\d+)<\/td>/', $html_mini, $mini_all_reg)) {
            $this->fail('Expected All registrations in mini footer');
        }
        $this->assertSame((string) $fixture['mini_place'], $mini_all_reg[1]);
    }
}
