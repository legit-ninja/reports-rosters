<?php
/**
 * PV-shaped camp schedule reader for PHPUnit (see intersoccer-product-variations includes/woocommerce/camp-schedule.php ~55-69).
 */

if (!function_exists('intersoccer_get_camp_schedule_meta')) {
    function intersoccer_get_camp_schedule_meta($variation_id) {
        $map = $GLOBALS['intersoccer_test_camp_schedule_meta_stub'] ?? [];
        $id = (int) $variation_id;
        if (isset($map[$id]) && is_array($map[$id])) {
            return $map[$id];
        }

        $start = trim((string) get_post_meta($id, '_camp_start_date', true));
        $end = trim((string) get_post_meta($id, '_camp_end_date', true));
        $week_raw = get_post_meta($id, '_camp_week_index', true);
        $week = ($week_raw !== '' && $week_raw !== null && is_numeric($week_raw)) ? (int) $week_raw : null;

        return [
            'start' => $start,
            'end'   => $end,
            'week'  => $week,
        ];
    }
}
