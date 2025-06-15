<?php
/**
 * Roster data functions for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.62
 */

defined('ABSPATH') or die('Restricted access');

function intersoccer_parse_dates($date_string) {
    if (preg_match('/(\w+\s+\d+(?:st|nd|rd|th)?)\s*(?:-|\s+-\s+)(\w+\s+\d+(?:st|nd|rd|th)?)\s*\((\d+)\s+days\)/i', $date_string, $matches)) {
        $start = DateTime::createFromFormat('F j Y', trim($matches[1]) . ' ' . date('Y'));
        $end = DateTime::createFromFormat('F j Y', trim($matches[2]) . ' ' . date('Y'));
        return ($start && $end && $end >= $start) ? ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')] : null;
    }
    return null;
}

function intersoccer_pe_get_event_roster_by_variation($variation_id, $context = []) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $roster = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $rosters_table WHERE order_item_id IN (
                SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items 
                WHERE order_item_type = 'line_item' 
                AND order_id IN (
                    SELECT order_id FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                    WHERE meta_key = '_variation_id' AND meta_value = %d
                )
            )",
            $variation_id
        ),
        ARRAY_A
    );

    error_log('InterSoccer: Retrieved ' . count($roster) . ' roster entries for variation ' . $variation_id);
    return $roster ?: [];
}

function intersoccer_pe_get_camp_variations($filters) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $where = ["activity_type = 'Camp'"];
    if ($filters['region'] ?? '') $where[] = $wpdb->prepare("venue LIKE %s", '%' . intersoccer_normalize_attribute($filters['region']) . '%');
    if ($filters['venue'] ?? '') $where[] = $wpdb->prepare("venue = %s", intersoccer_normalize_attribute($filters['venue']));
    if ($filters['age_group'] ?? '') $where[] = $wpdb->prepare("age_group = %s", intersoccer_normalize_attribute($filters['age_group']));
    $where_clause = implode(' AND ', $where);

    $results = $wpdb->get_results(
        "SELECT camp_terms, venue, age_group, product_name, 
                COUNT(*) as total_players, GROUP_CONCAT(DISTINCT order_item_id) as variation_ids
         FROM $rosters_table
         WHERE $where_clause
         GROUP BY camp_terms, venue, age_group, product_name",
        ARRAY_A
    );

    $config_grouped = [];
    foreach ($results as $row) {
        $config_key = $row['camp_terms'] . '|' . $row['venue'] . '|' . $row['age_group'];
        $config_grouped[$config_key] = [
            'product_name' => $row['product_name'],
            'camp_terms' => $row['camp_terms'],
            'region' => '', // Add region logic if needed
            'venues' => [$row['venue'] => ['variation_ids' => explode(',', $row['variation_ids'])]],
            'age_group' => $row['age_group'],
            'total_players' => $row['total_players'],
            'variation_ids' => explode(',', $row['variation_ids'])
        ];
    }

    uasort($config_grouped, fn($a, $b) => (intersoccer_parse_dates($a['camp_terms'])['start'] ?? new DateTime()) <=> (intersoccer_parse_dates($b['camp_terms'])['start'] ?? new DateTime()));
    return $config_grouped;
}

function intersoccer_pe_get_course_variations($filters) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $where = ["activity_type = 'Course'"];
    if ($filters['region'] ?? '') $where[] = $wpdb->prepare("venue LIKE %s", '%' . intersoccer_normalize_attribute($filters['region']) . '%');
    if ($filters['venue'] ?? '') $where[] = $wpdb->prepare("venue = %s", intersoccer_normalize_attribute($filters['venue']));
    if ($filters['age_group'] ?? '') $where[] = $wpdb->prepare("age_group = %s", intersoccer_normalize_attribute($filters['age_group']));
    $where_clause = implode(' AND ', $where);

    $results = $wpdb->get_results(
        "SELECT selected_days as course_day, venue, age_group, product_name, 
                COUNT(*) as total_players, GROUP_CONCAT(DISTINCT order_item_id) as variation_ids
         FROM $rosters_table
         WHERE $where_clause
         GROUP BY course_day, venue, age_group, product_name",
        ARRAY_A
    );

    $config_grouped = [];
    foreach ($results as $row) {
        $config_key = $row['course_day'] . '|' . $row['venue'] . '|' . $row['age_group'];
        $config_grouped[$config_key] = [
            'product_name' => $row['product_name'],
            'course_day' => $row['course_day'],
            'region' => '', // Add region logic if needed
            'venues' => [$row['venue'] => ['variation_ids' => explode(',', $row['variation_ids'])]],
            'age_group' => $row['age_group'],
            'total_players' => $row['total_players'],
            'variation_ids' => explode(',', $row['variation_ids'])
        ];
    }

    uasort($config_grouped, fn($a, $b) => (new DateTime($a['start_date'] ?? '1970-01-01')) <=> (new DateTime($b['start_date'] ?? '1970-01-01')));
    return $config_grouped;
}

function intersoccer_pe_get_girls_only_variations($filters) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $where = ["activity_type = 'Girls-Only'"];
    if ($filters['region'] ?? '') $where[] = $wpdb->prepare("venue LIKE %s", '%' . intersoccer_normalize_attribute($filters['region']) . '%');
    if ($filters['venue'] ?? '') $where[] = $wpdb->prepare("venue = %s", intersoccer_normalize_attribute($filters['venue']));
    if ($filters['age_group'] ?? '') $where[] = $wpdb->prepare("age_group = %s", intersoccer_normalize_attribute($filters['age_group']));
    $where_clause = implode(' AND ', $where);

    $results = $wpdb->get_results(
        "SELECT camp_terms, venue, age_group, product_name, 
                COUNT(*) as total_players, GROUP_CONCAT(DISTINCT order_item_id) as variation_ids
         FROM $rosters_table
         WHERE $where_clause
         GROUP BY camp_terms, venue, age_group, product_name",
        ARRAY_A
    );

    $config_grouped = [];
    foreach ($results as $row) {
        $config_key = $row['camp_terms'] . '|' . $row['venue'] . '|' . $row['age_group'];
        $config_grouped[$config_key] = [
            'product_name' => $row['product_name'],
            'camp_terms' => $row['camp_terms'],
            'region' => '', // Add region logic if needed
            'venues' => [$row['venue'] => ['variation_ids' => explode(',', $row['variation_ids'])]],
            'age_group' => $row['age_group'],
            'total_players' => $row['total_players'],
            'variation_ids' => explode(',', $row['variation_ids'])
        ];
    }

    uasort($config_grouped, fn($a, $b) => (intersoccer_parse_dates($a['camp_terms'])['start'] ?? new DateTime()) <=> (intersoccer_parse_dates($b['camp_terms'])['start'] ?? new DateTime()));
    return $config_grouped;
}
?>
