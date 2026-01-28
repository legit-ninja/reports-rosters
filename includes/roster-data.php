<?php
/**
 * Roster data functions for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.66
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Helper: Check if is_placeholder column exists (cached)
 */
function intersoccer_has_placeholder_column() {
    static $has_column = null;
    
    if ($has_column === null) {
        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $columns = $wpdb->get_col("DESCRIBE $rosters_table", 0);
        $has_column = in_array('is_placeholder', $columns);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: is_placeholder column exists: ' . ($has_column ? 'YES' : 'NO'));
        }
    }
    
    return $has_column;
}

/**
 * Helper: Get placeholder filter SQL clause
 */
function intersoccer_get_placeholder_filter($alias = 'r') {
    if (!intersoccer_has_placeholder_column()) {
        return ''; // Column doesn't exist yet, no filter
    }
    return " AND ({$alias}.is_placeholder = 0 OR {$alias}.is_placeholder IS NULL)";
}

/**
 * @deprecated Use intersoccer_parse_date_unified() instead (single source of truth for date parsing).
 *
 * Legacy helper that parsed a specific “(X days)” date range format.
 * Kept for backwards compatibility; avoid introducing new callers.
 */
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

    $query = "SELECT * FROM $rosters_table WHERE variation_id = %d" . intersoccer_get_placeholder_filter();
    if (!empty($context['age_group'])) {
        $query .= $wpdb->prepare(" AND (age_group = %s OR age_group LIKE %s)", $context['age_group'], '%' . $wpdb->esc_like($context['age_group']) . '%');
    }
    $roster = $wpdb->get_results($wpdb->prepare($query, $variation_id), ARRAY_A);

    error_log('InterSoccer: Retrieved ' . count($roster) . ' roster entries for variation ' . $variation_id . ' with context ' . json_encode($context));
    return $roster ?: [];
}

function intersoccer_pe_get_camp_variations($filters) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';

    $where = ["r.activity_type IN ('Camp', 'Girls Only')"];
    $placeholder_filter = intersoccer_get_placeholder_filter('r');
    if ($placeholder_filter) {
        $where[] = "1=1" . $placeholder_filter; // Add as separate condition
    }
    if ($filters['region'] ?? '') $where[] = $wpdb->prepare("r.venue LIKE %s", '%' . intersoccer_normalize_attribute($filters['region']) . '%');
    if ($filters['venue'] ?? '') $where[] = $wpdb->prepare("r.venue = %s", intersoccer_normalize_attribute($filters['venue']));
    if ($filters['age_group'] ?? '') {
        $where[] = $wpdb->prepare("(r.age_group = %s OR r.age_group LIKE %s)", $filters['age_group'], '%' . $wpdb->esc_like($filters['age_group']) . '%');
    }
    $where_clause = implode(' AND ', $where);

    $query = "SELECT r.product_id, r.camp_terms, r.venue, r.age_group, r.product_name, r.times, r.start_date, 
                COUNT(*) as total_players, GROUP_CONCAT(DISTINCT r.order_item_id) as order_item_ids, r.event_signature
         FROM $rosters_table r
         JOIN $posts_table p ON r.product_id = p.ID AND p.post_type = 'product' AND p.post_status = 'publish'
         WHERE $where_clause
         GROUP BY r.event_signature, r.product_id, r.camp_terms, r.venue, r.age_group, r.product_name, r.times";
    error_log('InterSoccer: Camp variations query: ' . $query);

    $results = $wpdb->get_results($query, ARRAY_A);
    error_log('InterSoccer: Camp variations results count: ' . count($results));

    if ($results) {
        error_log('InterSoccer: Sample camp variation row: ' . json_encode($results[0]));
    }

    $config_grouped = [];
    foreach ($results as $row) {
        $config_key = $row['product_id'] . '|' . $row['camp_terms'] . '|' . $row['venue'] . '|' . $row['age_group'] . '|' . $row['times'];
        error_log('InterSoccer: Processing config_key: ' . $config_key);
        $config_grouped[$config_key] = [
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'camp_terms' => $row['camp_terms'],
            'region' => '', // Add region logic if needed
            'venues' => [$row['venue'] => ['variation_ids' => explode(',', $row['variation_ids'])]],
            'age_group' => $row['age_group'],
            'times' => $row['times'],
            'total_players' => $row['total_players'],
            'variation_ids' => explode(',', $row['variation_ids']),
            'start_date' => $row['start_date']
        ];
    }

    uasort($config_grouped, fn($a, $b) => (new DateTime($a['start_date'] ?? '1970-01-01')) <=> (new DateTime($b['start_date'] ?? '1970-01-01')));
    error_log('InterSoccer: Final grouped camps count: ' . count($config_grouped));
    return $config_grouped;
}

function intersoccer_pe_get_course_variations($filters) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';

    $where = ["r.activity_type = 'Course'"];
    $placeholder_filter = intersoccer_get_placeholder_filter('r');
    if ($placeholder_filter) {
        $where[] = "1=1" . $placeholder_filter; // Add as separate condition
    }
    if ($filters['region'] ?? '') $where[] = $wpdb->prepare("r.venue LIKE %s", '%' . intersoccer_normalize_attribute($filters['region']) . '%');
    if ($filters['venue'] ?? '') $where[] = $wpdb->prepare("r.venue = %s", intersoccer_normalize_attribute($filters['venue']));
    if ($filters['age_group'] ?? '') {
        $where[] = $wpdb->prepare("(r.age_group = %s OR r.age_group LIKE %s)", $filters['age_group'], '%' . $wpdb->esc_like($filters['age_group']) . '%');
    }
    $where_clause = implode(' AND ', $where);

    $query = "SELECT r.product_id, r.course_day, r.venue, r.age_group, r.product_name, r.times, r.start_date, 
                COUNT(*) as total_players, GROUP_CONCAT(DISTINCT r.order_item_id) as order_item_ids, r.event_signature
         FROM $rosters_table r
         JOIN $posts_table p ON r.product_id = p.ID AND p.post_type = 'product' AND p.post_status = 'publish'
         WHERE $where_clause
         GROUP BY r.event_signature, r.product_id, r.course_day, r.venue, r.age_group, r.product_name, r.times";
    error_log('InterSoccer: Course variations query: ' . $query);

    $results = $wpdb->get_results($query, ARRAY_A);
    error_log('InterSoccer: Course variations results count: ' . count($results));

    if ($results) {
        error_log('InterSoccer: Sample course variation row: ' . json_encode($results[0]));
    }

    $config_grouped = [];
    foreach ($results as $row) {
        $config_key = $row['product_id'] . '|' . $row['course_day'] . '|' . $row['venue'] . '|' . $row['age_group'] . '|' . $row['times'];
        error_log('InterSoccer: Processing config_key: ' . $config_key);
        $config_grouped[$config_key] = [
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'course_day' => $row['course_day'],
            'region' => '', // Add region logic if needed
            'venues' => [$row['venue'] => ['variation_ids' => explode(',', $row['variation_ids'])]],
            'age_group' => $row['age_group'],
            'times' => $row['times'],
            'total_players' => $row['total_players'],
            'variation_ids' => explode(',', $row['variation_ids']),
            'start_date' => $row['start_date']
        ];
    }

    uasort($config_grouped, fn($a, $b) => (new DateTime($a['start_date'] ?? '1970-01-01')) <=> (new DateTime($b['start_date'] ?? '1970-01-01')));
    error_log('InterSoccer: Final grouped courses count: ' . count($config_grouped));
    return $config_grouped;
}

function intersoccer_pe_get_girls_only_variations($filters) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';

    $where = ["r.activity_type IN ('Girls Only', 'Camp, Girls'' Only')"];
    if ($filters['region'] ?? '') $where[] = $wpdb->prepare("r.venue LIKE %s", '%' . intersoccer_normalize_attribute($filters['region']) . '%');
    if ($filters['venue'] ?? '') $where[] = $wpdb->prepare("r.venue = %s", intersoccer_normalize_attribute($filters['venue']));
    if ($filters['age_group'] ?? '') {
        $where[] = $wpdb->prepare("(r.age_group = %s OR r.age_group LIKE %s)", $filters['age_group'], '%' . $wpdb->esc_like($filters['age_group']) . '%');
    }
    $where_clause = implode(' AND ', $where);

    $query = "SELECT r.product_id, r.camp_terms, r.venue, r.age_group, r.product_name, r.times, r.shirt_size, r.shorts_size, r.start_date,
                COUNT(*) as total_players, GROUP_CONCAT(DISTINCT r.order_item_id) as order_item_ids, r.event_signature
         FROM $rosters_table r
         JOIN $posts_table p ON r.product_id = p.ID AND p.post_type = 'product' AND p.post_status = 'publish'
         WHERE $where_clause
         GROUP BY r.event_signature, r.product_id, r.camp_terms, r.venue, r.age_group, r.product_name, r.times, r.shirt_size, r.shorts_size";
    error_log('InterSoccer: Girls Only variations query: ' . $query);

    $results = $wpdb->get_results($query, ARRAY_A);
    error_log('InterSoccer: Girls Only variations results count: ' . count($results));

    if ($results) {
        error_log('InterSoccer: Sample girls only variation row: ' . json_encode($results[0]));
    }

    $config_grouped = [];
    foreach ($results as $row) {
        $config_key = $row['product_id'] . '|' . $row['camp_terms'] . '|' . $row['venue'] . '|' . $row['age_group'] . '|' . $row['times'] . '|' . $row['shirt_size'] . '|' . $row['shorts_size'];
        error_log('InterSoccer: Processing config_key: ' . $config_key);
        $config_grouped[$config_key] = [
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'],
            'camp_terms' => $row['camp_terms'],
            'region' => '', // Add region logic if needed
            'venues' => [$row['venue'] => ['variation_ids' => explode(',', $row['variation_ids'])]],
            'age_group' => $row['age_group'],
            'times' => $row['times'],
            'shirt_size' => $row['shirt_size'],
            'shorts_size' => $row['shorts_size'],
            'total_players' => $row['total_players'],
            'variation_ids' => explode(',', $row['variation_ids']),
            'start_date' => $row['start_date']
        ];
    }

    uasort($config_grouped, fn($a, $b) => (new DateTime($a['start_date'] ?? '1970-01-01')) <=> (new DateTime($b['start_date'] ?? '1970-01-01')));
    error_log('InterSoccer: Final grouped girls only count: ' . count($config_grouped));
    return $config_grouped;
}
?>