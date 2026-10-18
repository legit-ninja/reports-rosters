<?php
/**
 * Repro (failing tests): Final Numbers camp grid (header vs columns).
 *
 * Synthetic data only. Scenario names follow the report-accuracy recount (R6 sections).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FinalNumbersAccuracyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        require_once $includes . '/utils.php';
        require_once $includes . '/final-reports-totals.php';
        require_once $includes . '/final-reports-aggregation.php';
        if (!function_exists('intersoccer_reports_aggregate_camp_location_group')
            || !function_exists('intersoccer_normalize_booking_type_slug_for_reports')) {
            $this->markTestSkipped('Final reports aggregation helpers not loaded');
        }
    }

    private static function row(int $id, string $booking_type, string $selected_days = '', string $days_selected = ''): array {
        return [
            'order_item_id' => $id,
            'booking_type' => $booking_type,
            'selected_days' => $selected_days,   // r.selected_days (what Final Numbers reads)
            'days_selected' => $days_selected,   // r.days_selected (roster column that has the days)
            'age_group' => '5-13y Full Day',
            'is_buyclub' => false,
            'line_subtotal' => 250,
            'line_total' => 250,
        ];
    }

    /** Rows that land in some grid column: full week, BuyClub, or at least one day slot. */
    private static function rowsInColumns(array $group): int {
        $n = 0;
        foreach ($group as $r) {
            $agg = intersoccer_reports_aggregate_camp_location_group([$r], false);
            if ($agg['full_week'] + $agg['buyclub'] + array_sum($agg['individual_days']) > 0) {
                $n++;
            }
        }
        return $n;
    }

    /** S14a: booking type 'other' / empty is counted in the header but in no column. */
    public function test_S14a_unknown_booking_type_row_appears_in_a_column(): void {
        $this->markTestIncomplete('Pending fix F: Final Numbers unknown booking type column.');
        $group = [self::row(1, 'other'), self::row(2, '')];

        $header = intersoccer_reports_compute_camp_registration_totals($group, false)['all'];
        $in_columns = self::rowsInColumns($group);

        $this->assertSame($header, $in_columns,
            "S14a: expected header ($header) = rows shown in columns; report shows $in_columns of $header rows in columns");
    }

    /** S14b: single-day row whose days are only in days_selected (selected_days empty) gets no day slot. */
    public function test_S14b_single_day_row_counts_days_from_days_selected(): void {
        $this->markTestIncomplete('Pending fix F: Final Numbers days_selected backfill.');
        $group = [self::row(3, 'Single Day(s)', '', 'Monday, Tuesday')];

        $agg = intersoccer_reports_aggregate_camp_location_group($group, false);
        $got = ['Monday' => $agg['individual_days']['Monday'], 'Tuesday' => $agg['individual_days']['Tuesday']];

        $this->assertSame(['Monday' => 1, 'Tuesday' => 1], $got,
            'S14b: expected Monday 1 / Tuesday 1; report Monday ' . $got['Monday'] . ' / Tuesday ' . $got['Tuesday']);
    }

    /** Control (PASSES): with selected_days set, the same row is counted. */
    public function test_S14b_control_selected_days_counted(): void {
        $agg = intersoccer_reports_aggregate_camp_location_group([self::row(4, 'Single Day(s)', 'Monday, Tuesday')], false);
        $this->assertSame([1, 1], [$agg['individual_days']['Monday'], $agg['individual_days']['Tuesday']]);
    }
}
