<?php
/**
 * Final Numbers registration totalling helpers.
 *
 * @package InterSoccerReports
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_reports_compute_camp_registration_totals')) {
    /**
     * Count camp registrations from filtered report rows (one per roster row).
     *
     * @param array $rows             Filtered camp rows (post region/season/year filters).
     * @param bool  $exclude_buyclub  Whether to skip BuyClub / zero-net rows.
     * @return array{full_day: int, mini: int, all: int}
     */
    function intersoccer_reports_compute_camp_registration_totals(array $rows, $exclude_buyclub) {
        $totals = ['full_day' => 0, 'mini' => 0, 'all' => 0];

        foreach ($rows as $row) {
            if (!isset($row['is_buyclub'])) {
                $line_subtotal = floatval($row['line_subtotal'] ?? 0);
                $line_total = floatval($row['line_total'] ?? 0);
                $row['is_buyclub'] = $line_subtotal > 0 && $line_total === 0.0;
            }
            if (function_exists('intersoccer_reports_row_should_exclude_for_buyclub_option')
                && intersoccer_reports_row_should_exclude_for_buyclub_option($row, $exclude_buyclub)) {
                continue;
            }

            $age_group = $row['age_group'] ?? '';
            $is_mini = !empty($age_group)
                && (stripos($age_group, '3-5y') !== false || stripos($age_group, 'half-day') !== false);

            if ($is_mini) {
                $totals['mini']++;
            } else {
                $totals['full_day']++;
            }
            $totals['all']++;
        }

        return $totals;
    }
}

if (!function_exists('intersoccer_reports_compute_course_registration_total')) {
    /**
     * Count course registrations from filtered report rows with roster-row dedupe.
     *
     * @param array $rows             Filtered course rows (post region/date/year filters).
     * @param bool  $exclude_buyclub  Whether to skip BuyClub / zero-net rows.
     * @return int
     */
    function intersoccer_reports_compute_course_registration_total(array $rows, $exclude_buyclub) {
        $count = 0;
        $seen_roster_ids = [];
        $seen_order_items_no_roster = [];

        foreach ($rows as $row) {
            if (!isset($row['is_buyclub'])) {
                $line_subtotal = floatval($row['line_subtotal'] ?? 0);
                $line_total = floatval($row['line_total'] ?? 0);
                $row['is_buyclub'] = $line_subtotal > 0 && $line_total === 0.0;
            }
            if (function_exists('intersoccer_reports_row_should_exclude_for_buyclub_option')
                && intersoccer_reports_row_should_exclude_for_buyclub_option($row, $exclude_buyclub)) {
                continue;
            }

            $rrid = isset($row['roster_row_id']) ? (int) $row['roster_row_id'] : 0;
            if ($rrid > 0) {
                if (isset($seen_roster_ids[$rrid])) {
                    continue;
                }
                $seen_roster_ids[$rrid] = true;
            } else {
                $coi = isset($row['order_item_id']) ? (int) $row['order_item_id'] : 0;
                if ($coi > 0) {
                    if (isset($seen_order_items_no_roster[$coi])) {
                        continue;
                    }
                    $seen_order_items_no_roster[$coi] = true;
                }
            }

            $count++;
        }

        return $count;
    }
}
