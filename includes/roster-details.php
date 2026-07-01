<?php
/**
 * Roster Details and Specific Event Pages
 *
 * Handles rendering of detailed roster views.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.50  // Incremented for activity type and referer fix
 * @author Jeremy Lee
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once plugin_dir_path(dirname(__FILE__)) . 'includes/roster-data.php';

/**
 * Generate URL for sortable column
 */
function intersoccer_get_sort_url($sort_field, $current_sort, $current_order) {
    $params = $_GET;
    $params['sort'] = $sort_field;
    $params['order'] = ($current_sort === $sort_field && $current_order === 'asc') ? 'desc' : 'asc';
    return add_query_arg($params, admin_url('admin.php?page=intersoccer-roster-details'));
}

/**
 * Get sort indicator for column header
 */
function intersoccer_get_sort_indicator($field, $current_sort, $current_order) {
    if ($current_sort !== $field) {
        return ' ⇅'; // Neutral sort indicator
    }
    return $current_order === 'asc' ? ' ↑' : ' ↓';
}

/**
 * Parse comma-separated integer IDs from request values.
 *
 * @param string $raw
 * @return int[]
 */
function intersoccer_roster_parse_csv_int_ids($raw) {
    $ids = array_map('intval', explode(',', (string) $raw));
    $ids = array_values(array_unique(array_filter($ids, static function ($value) {
        return $value > 0;
    })));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * Parse comma-separated tokens from request values with de-duplication.
 *
 * @param string $raw
 * @return string[]
 */
function intersoccer_roster_parse_csv_string_tokens($raw) {
    $tokens = array_map('trim', explode(',', (string) $raw));
    $tokens = array_values(array_filter($tokens, static function ($token) {
        return $token !== '' && strcasecmp($token, 'N/A') !== 0;
    }));
    $tokens = array_values(array_unique($tokens));
    sort($tokens, SORT_STRING);
    return $tokens;
}

/**
 * Case-insensitive referer page check.
 *
 * @param string $referer
 * @param string $needle
 * @return bool
 */
function intersoccer_roster_referer_has_page($referer, $needle) {
    $referer = (string) $referer;
    return $referer !== '' && stripos($referer, (string) $needle) !== false;
}

/**
 * Consolidated listing kind for a roster row ("course" or "camp").
 *
 * @param array<string,mixed> $row
 * @return string
 */
function intersoccer_roster_consolidated_kind_for_row(array $row) {
    $activity = strtolower((string) ($row['activity_type'] ?? ''));
    return strpos($activity, 'course') !== false ? 'course' : 'camp';
}

/**
 * Roster Details "from" query param for back-navigation context.
 *
 * @param array<string,mixed> $row
 * @return string
 */
function intersoccer_roster_details_from_page_for_row(array $row) {
    $girls_only = !empty($row['girls_only']);
    $activity = (string) ($row['activity_type'] ?? '');

    if (stripos($activity, 'Tournament') !== false) {
        return 'tournaments';
    }
    if (stripos($activity, 'Course') !== false) {
        return $girls_only ? 'girls-only' : 'courses';
    }
    if (stripos($activity, 'Camp') !== false) {
        return $girls_only ? 'girls-only' : 'camps';
    }

    return '';
}

/**
 * Collect order_item_ids sharing the same consolidated roster group key as $anchor.
 *
 * @param array<string,mixed>   $anchor
 * @param array<int,array<string,mixed>> $candidates
 * @param string $kind "course" or "camp"
 * @return int[]
 */
function intersoccer_collect_consolidated_order_item_ids_for_roster_row(array $anchor, array $candidates, $kind) {
    $anchor_id = (int) ($anchor['order_item_id'] ?? 0);
    if (!function_exists('intersoccer_consolidated_roster_group_key')) {
        return $anchor_id > 0 ? [$anchor_id] : [];
    }

    $kind = strtolower((string) $kind) === 'course' ? 'course' : 'camp';
    $target_key = intersoccer_consolidated_roster_group_key($anchor, $kind);
    $ids = [];

    foreach ($candidates as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (intersoccer_consolidated_roster_group_key($row, $kind) !== $target_key) {
            continue;
        }
        $oid = (int) ($row['order_item_id'] ?? 0);
        if ($oid > 0) {
            $ids[$oid] = $oid;
        }
    }

    if ($anchor_id > 0) {
        $ids[$anchor_id] = $anchor_id;
    }

    $result = array_values($ids);
    sort($result, SORT_NUMERIC);

    return $result;
}

/**
 * Pick the best roster row when multiple exist for one order_item_id.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,mixed>|null
 */
function intersoccer_pick_best_roster_row_for_order_item(array $rows) {
    if (empty($rows)) {
        return null;
    }

    $best = null;
    $best_score = -1;
    $best_id = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $score = 0;
        $venue = trim((string) ($row['venue'] ?? ''));
        $day = trim((string) ($row['course_day'] ?? ''));
        if ($venue !== '' && strcasecmp($venue, 'N/A') !== 0) {
            $score++;
        }
        if ($day !== '' && strcasecmp($day, 'N/A') !== 0) {
            $score++;
        }
        if (function_exists('intersoccer_roster_row_names_incomplete') && !intersoccer_roster_row_names_incomplete($row)) {
            $score += 2;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($best === null || $score > $best_score || ($score === $best_score && $id > $best_id)) {
            $best = $row;
            $best_score = $score;
            $best_id = $id;
        }
    }

    return $best;
}

/**
 * Admin URL for consolidated Roster Details for a WooCommerce order line item.
 *
 * @param int $order_item_id
 * @return string|null Null when no roster row exists for the line item.
 */
function intersoccer_get_roster_details_url_for_order_item($order_item_id) {
    global $wpdb;

    $order_item_id = (int) $order_item_id;
    if ($order_item_id <= 0 || !isset($wpdb) || !is_object($wpdb)) {
        return null;
    }

    $table = $wpdb->prefix . 'intersoccer_rosters';
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$table} WHERE order_item_id = %d ORDER BY id DESC", $order_item_id),
        ARRAY_A
    );

    if (empty($rows) || !is_array($rows)) {
        return null;
    }

    $anchor = intersoccer_pick_best_roster_row_for_order_item($rows);
    if ($anchor === null) {
        return null;
    }

    if (function_exists('intersoccer_roster_row_resolve_event_signature_for_url')) {
        $resolved_sig = intersoccer_roster_row_resolve_event_signature_for_url($anchor);
        if ($resolved_sig !== '') {
            $anchor['event_signature'] = $resolved_sig;
            $anchor_id = (int) ($anchor['id'] ?? 0);
            if ($anchor_id > 0 && empty(trim((string) ($rows[0]['event_signature'] ?? '')))) {
                global $wpdb;
                if (isset($wpdb) && is_object($wpdb)) {
                    $wpdb->update(
                        $table,
                        ['event_signature' => $resolved_sig],
                        ['id' => $anchor_id],
                        ['%s'],
                        ['%d']
                    );
                }
            }
        }
    }

    $from = intersoccer_roster_details_from_page_for_row($anchor);
    $activity = (string) ($anchor['activity_type'] ?? '');

    if (stripos($activity, 'Tournament') !== false) {
        $event_signature = trim((string) ($anchor['event_signature'] ?? ''));
        $params = ['page' => 'intersoccer-roster-details', 'from' => 'tournaments'];
        if ($event_signature !== '' && strcasecmp($event_signature, 'N/A') !== 0) {
            $params['event_signature'] = $event_signature;
        } else {
            $params['order_item_ids'] = (string) $order_item_id;
        }
        return add_query_arg($params, admin_url('admin.php'));
    }

    $kind = intersoccer_roster_consolidated_kind_for_row($anchor);
    $candidates = intersoccer_fetch_roster_sibling_candidates_for_consolidation($anchor, $kind, 500);
    $order_item_ids = intersoccer_collect_consolidated_order_item_ids_for_roster_row($anchor, $candidates, $kind);

    if (empty($order_item_ids)) {
        $order_item_ids = [$order_item_id];
    }

    $merged_signatures = [];
    $target_key = function_exists('intersoccer_consolidated_roster_group_key')
        ? intersoccer_consolidated_roster_group_key($anchor, $kind)
        : null;

    $collect_signature = static function (array $row) use (&$merged_signatures) {
        $sig = trim((string) ($row['event_signature'] ?? ''));
        if ($sig !== '' && strcasecmp($sig, 'N/A') !== 0) {
            $merged_signatures[$sig] = $sig;
        }
    };

    $collect_signature($anchor);
    foreach ($candidates as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($target_key !== null
            && intersoccer_consolidated_roster_group_key($row, $kind) !== $target_key) {
            continue;
        }
        $collect_signature($row);
    }

    $group = [
        'event_signature' => trim((string) ($anchor['event_signature'] ?? '')),
        'merged_event_signatures' => array_values($merged_signatures),
        'order_item_ids' => $order_item_ids,
        'venue' => $anchor['venue'] ?? '',
        'course_day' => $anchor['course_day'] ?? '',
        'camp_terms' => $anchor['camp_terms'] ?? '',
    ];

    if (function_exists('intersoccer_get_roster_details_url_for_listing_group')) {
        $url = intersoccer_get_roster_details_url_for_listing_group($group, $from);
        if ($url !== '') {
            return $url;
        }
    }

    $params = [
        'page' => 'intersoccer-roster-details',
        'order_item_ids' => implode(',', $order_item_ids),
    ];
    if ($from !== '') {
        $params['from'] = $from;
    }

    return add_query_arg($params, admin_url('admin.php'));
}

/**
 * Narrow roster rows that may belong to the same consolidated listing group as $anchor.
 *
 * @param array<string,mixed> $anchor
 * @param string              $kind
 * @param int                 $limit
 * @return array<int,array<string,mixed>>
 */
function intersoccer_fetch_roster_sibling_candidates_for_consolidation(array $anchor, $kind, $limit = 500) {
    global $wpdb;

    $limit = max(1, min(500, (int) $limit));
    if (!isset($wpdb) || !is_object($wpdb)) {
        return [$anchor];
    }

    $table = $wpdb->prefix . 'intersoccer_rosters';
    $variation_id = (int) ($anchor['variation_id'] ?? 0);
    $venue_raw = trim((string) ($anchor['venue'] ?? ''));
    $kind = strtolower((string) $kind) === 'course' ? 'course' : 'camp';

    $facet = static function ($value, $taxonomy) {
        if (function_exists('intersoccer_roster_facet_for_grouping')) {
            return intersoccer_roster_facet_for_grouping($value, $taxonomy);
        }
        if ($taxonomy !== '' && function_exists('intersoccer_get_term_name')) {
            $name = intersoccer_get_term_name($value, $taxonomy);
            if ($name !== '' && $name !== 'N/A') {
                return strtolower(trim($name));
            }
        }
        return strtolower(trim((string) $value));
    };

    $venue_canon = ($venue_raw !== '' && strcasecmp($venue_raw, 'N/A') !== 0)
        ? $facet($venue_raw, 'pa_intersoccer-venues')
        : '';
    $course_day_canon = '';
    $camp_terms_canon = '';
    if ($kind === 'course') {
        $course_day_raw = trim((string) ($anchor['course_day'] ?? ''));
        if ($course_day_raw !== '' && strcasecmp($course_day_raw, 'N/A') !== 0) {
            $course_day_canon = $facet($course_day_raw, 'pa_course-day');
        }
    } else {
        $camp_terms_raw = trim((string) ($anchor['camp_terms'] ?? ''));
        if ($camp_terms_raw !== '' && strcasecmp($camp_terms_raw, 'N/A') !== 0) {
            $camp_terms_canon = $facet($camp_terms_raw, 'pa_camp-terms');
        }
    }

    $where = [];
    $values = [];

    if ($variation_id > 0) {
        $where[] = 'variation_id = %d';
        $values[] = $variation_id;
    } elseif ((int) ($anchor['product_id'] ?? 0) > 0) {
        $where[] = 'product_id = %d';
        $values[] = (int) $anchor['product_id'];
    }

    if ($kind === 'course') {
        $where[] = "(activity_type LIKE %s OR activity_type LIKE %s)";
        $values[] = '%Course%';
        $values[] = '%course%';
    } else {
        $where[] = "(activity_type LIKE %s OR activity_type LIKE %s)";
        $values[] = '%Camp%';
        $values[] = '%camp%';
    }

    if (empty($where)) {
        return [$anchor];
    }

    $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d';
    $values[] = $limit;

    $prepared = $wpdb->prepare($sql, $values);
    $results = $wpdb->get_results($prepared, ARRAY_A);

    if (empty($results) || !is_array($results)) {
        return [$anchor];
    }

    if ($venue_canon !== '' || $course_day_canon !== '' || $camp_terms_canon !== '') {
        $results = array_values(array_filter($results, static function ($row) use ($venue_canon, $course_day_canon, $camp_terms_canon, $facet) {
            if ($venue_canon !== '') {
                $row_venue = $facet($row['venue'] ?? '', 'pa_intersoccer-venues');
                if ($row_venue !== $venue_canon) {
                    return false;
                }
            }
            if ($course_day_canon !== '') {
                $row_day = $facet($row['course_day'] ?? '', 'pa_course-day');
                if ($row_day !== $course_day_canon) {
                    return false;
                }
            }
            if ($camp_terms_canon !== '') {
                $row_terms = $facet($row['camp_terms'] ?? '', 'pa_camp-terms');
                if ($row_terms !== $camp_terms_canon) {
                    return false;
                }
            }
            return true;
        }));
    }

    if (empty($results)) {
        return [$anchor];
    }

    if (function_exists('intersoccer_roster_resolve_listing_year')) {
        $anchor_year = intersoccer_roster_resolve_listing_year($anchor);
        if ($anchor_year !== null) {
            $results = array_values(array_filter($results, static function ($row) use ($anchor_year) {
                $row_year = intersoccer_roster_resolve_listing_year($row);
                return $row_year === null || $row_year === $anchor_year;
            }));
        }
    }

    if (empty($results)) {
        return [$anchor];
    }

    return $results;
}

/**
 * Render the roster details page
 */
function intersoccer_render_roster_details_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    // Get query parameters
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    $variation_id = isset($_GET['variation_id']) ? intval($_GET['variation_id']) : 0;
    $variation_ids_str = isset($_GET['variation_ids']) ? sanitize_text_field($_GET['variation_ids']) : '';
    $variation_ids = $variation_ids_str ? intersoccer_roster_parse_csv_int_ids($variation_ids_str) : [];
    $order_item_ids_str = isset($_GET['order_item_ids']) ? sanitize_text_field($_GET['order_item_ids']) : '';
    $order_item_ids = $order_item_ids_str ? intersoccer_roster_parse_csv_int_ids($order_item_ids_str) : [];
    $event_signature = isset($_GET['event_signature']) ? sanitize_text_field($_GET['event_signature']) : '';
    $event_signatures_str = isset($_GET['event_signatures']) ? sanitize_text_field($_GET['event_signatures']) : '';
    $event_signatures = $event_signatures_str ? intersoccer_roster_parse_csv_string_tokens($event_signatures_str) : [];
    $camp_terms = isset($_GET['camp_terms']) ? sanitize_text_field($_GET['camp_terms']) : '';
    $course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';
    $venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $times = isset($_GET['times']) ? sanitize_text_field($_GET['times']) : '';
    $product_name = isset($_GET['product_name']) ? sanitize_text_field($_GET['product_name']) : '';
    $event_dates = isset($_GET['event_dates']) ? sanitize_text_field($_GET['event_dates']) : '';
    $from_page = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
    $girls_only = isset($_GET['girls_only']) ? (bool) $_GET['girls_only'] : false;
    $season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $sort_by = isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'order_date';
    $sort_order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'desc';

    // Validate sort parameters
    $allowed_sort_fields = ['order_date', 'player_name', 'last_name', 'gender', 'age', 'age_group'];
    if (!in_array($sort_by, $allowed_sort_fields)) {
        $sort_by = 'order_date';
    }
    if (!in_array($sort_order, ['asc', 'desc'], true)) {
        $sort_order = 'desc';
    }

    // Check referer and from param
    $referer = wp_get_referer();
    $is_from_camps_page = $from_page === 'camps' || intersoccer_roster_referer_has_page($referer, 'page=intersoccer-camps');
    $is_from_courses_page = $from_page === 'courses' || intersoccer_roster_referer_has_page($referer, 'page=intersoccer-courses');
    $is_from_girls_only_page = $from_page === 'girls-only' || intersoccer_roster_referer_has_page($referer, 'page=intersoccer-girls-only') || $girls_only;
    $is_from_tournaments_page = $from_page === 'tournaments' || intersoccer_roster_referer_has_page($referer, 'page=intersoccer-tournaments');

    $use_oop_rosters = defined('INTERSOCCER_OOP_ACTIVE')
        && INTERSOCCER_OOP_ACTIVE
        && function_exists('intersoccer_use_oop_for')
        && intersoccer_use_oop_for('rosters')
        && function_exists('intersoccer_oop_get_roster_details_service');
    if ($use_oop_rosters) {
        $service = intersoccer_oop_get_roster_details_service();
        $result = $service->getRosterContext(
            [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'variation_ids' => $variation_ids,
                'order_item_ids' => $order_item_ids,
                'event_signature' => $event_signature,
                'event_signatures' => $event_signatures,
                'camp_terms' => $camp_terms,
                'course_day' => $course_day,
                'venue' => $venue,
                'age_group' => $age_group,
                'times' => $times,
                'season' => $season,
                'girls_only' => $girls_only,
            ],
            [
                'is_from_camps_page' => $is_from_camps_page,
                'is_from_courses_page' => $is_from_courses_page,
                'is_from_girls_only_page' => $is_from_girls_only_page,
                'is_from_tournaments_page' => $is_from_tournaments_page,
                'sort_by' => $sort_by,
                'sort_order' => $sort_order,
            ]
        );

        if (!$result['success']) {
            echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
            echo '<p>' . esc_html($result['error']) . '</p></div>';
            return;
        }

        $rosters = $result['rosters'];
        $base_roster = $result['base_roster'];
        $available_rosters = $result['available_rosters'];
        $cross_gender_rosters = $result['cross_gender_rosters'];
        $unknown_count = $result['unknown_count'];

        error_log('InterSoccer OOP: Roster details results count: ' . count($rosters));

        if ($product_id <= 0 && !empty($base_roster->product_id)) {
            $product_id = (int) $base_roster->product_id;
        }
        if (empty($camp_terms) && !empty($base_roster->camp_terms)) {
            $camp_terms = $base_roster->camp_terms;
        }
        if (empty($course_day) && !empty($base_roster->course_day)) {
            $course_day = $base_roster->course_day;
        }
        if (empty($venue) && !empty($base_roster->venue)) {
            $venue = $base_roster->venue;
        }
        if (empty($age_group) && !empty($base_roster->age_group)) {
            $age_group = $base_roster->age_group;
        }
        if (empty($times) && !empty($base_roster->times)) {
            $times = $base_roster->times;
        }
        if (!$girls_only && !empty($base_roster->girls_only)) {
            $girls_only = (bool) $base_roster->girls_only;
        }
        if (empty($season) && !empty($base_roster->season)) {
            $season = $base_roster->season;
        }
    } else {
        $query = "SELECT r.player_name, r.first_name, r.last_name, r.gender, r.parent_phone, r.parent_email, r.age, r.medical_conditions, r.late_pickup, r.late_pickup_days, r.booking_type, r.course_day, r.shirt_size, r.shorts_size, r.day_presence, r.selected_days, r.days_selected, r.event_details, r.order_item_id, r.variation_id, r.age_group, r.activity_type, r.product_name, r.camp_terms, r.venue, r.times, r.product_id, r.girls_only, p.post_date as order_date";
        $query .= " FROM $rosters_table r";
        $query .= " JOIN {$wpdb->posts} p ON r.order_id = p.ID";

        $where_clauses = [];
        $query_params = [];

        $where_clauses[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold')";

        if ($is_from_girls_only_page || $girls_only) {
            $where_clauses[] = "r.girls_only = 1";
        } elseif ($is_from_camps_page) {
            $where_clauses[] = "r.activity_type = %s AND r.girls_only = 0";
            $query_params[] = 'Camp';
        } elseif ($is_from_courses_page) {
            $where_clauses[] = "r.activity_type = %s AND r.girls_only = 0";
            $query_params[] = 'Course';
        } elseif ($is_from_tournaments_page) {
            $where_clauses[] = "r.activity_type = %s AND r.girls_only = 0";
            $query_params[] = 'Tournament';
        }

        if ($product_id > 0) {
            $where_clauses[] = "r.product_id = %d";
            $query_params[] = $product_id;
        }

        if ($product_name) {
            $where_clauses[] = "r.product_name = %s";
            $query_params[] = $product_name;
            if ($event_dates && $event_dates !== 'N/A') {
                $where_clauses[] = "r.event_dates = %s";
                $query_params[] = $event_dates;
            }
        }

        if ($variation_id > 0) {
            $where_clauses[] = "r.variation_id = %d";
            $query_params[] = $variation_id;
        }

        if (!empty($variation_ids)) {
            $placeholders = implode(',', array_fill(0, count($variation_ids), '%d'));
            $where_clauses[] = "r.variation_id IN ($placeholders)";
            $query_params = array_merge($query_params, $variation_ids);
        }

        if (!empty($event_signatures)) {
            $placeholders = implode(',', array_fill(0, count($event_signatures), '%s'));
            $where_clauses[] = "r.event_signature IN ($placeholders)";
            $query_params = array_merge($query_params, $event_signatures);
        } elseif ($event_signature && $event_signature !== 'N/A') {
            $where_clauses[] = "r.event_signature = %s";
            $query_params[] = $event_signature;
        } elseif (!empty($order_item_ids)) {
            $placeholders = implode(',', array_fill(0, count($order_item_ids), '%d'));
            $where_clauses[] = "r.order_item_id IN ($placeholders)";
            $query_params = array_merge($query_params, $order_item_ids);
        } else {
            if ($camp_terms && $camp_terms !== 'N/A') {
                $where_clauses[] = "r.camp_terms = %s";
                $query_params[] = $camp_terms;
            }

            if ($course_day && $course_day !== 'N/A') {
                $where_clauses[] = "r.course_day = %s";
                $query_params[] = $course_day;
            }

            if ($venue) {
                $where_clauses[] = "r.venue = %s";
                $query_params[] = $venue;
            }

            if ($age_group) {
                $where_clauses[] = "r.age_group = %s";
                $query_params[] = $age_group;
            }

            if ($times) {
                $where_clauses[] = "r.times = %s";
                $query_params[] = $times;
            }
        }

        if ($season && strcasecmp($season, 'N/A') !== 0) {
            $where_clauses[] = "r.season = %s";
            $query_params[] = $season;
        }

        if (empty($where_clauses)) {
            error_log('InterSoccer: No valid parameters provided for roster details');
            echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
            echo '<p>' . esc_html__('Invalid parameters provided.', 'intersoccer-reports-rosters') . '</p></div>';
            return;
        }

        $query .= " WHERE " . implode(' AND ', $where_clauses);

        $order_by_map = [
            'order_date' => 'p.post_date',
            'player_name' => 'r.first_name',
            'last_name' => 'r.last_name',
            'gender' => 'r.gender',
            'age' => 'CAST(r.age AS UNSIGNED)',
            'age_group' => 'r.age_group'
        ];

        $order_field = $order_by_map[$sort_by] ?? 'p.post_date';
        $query .= " ORDER BY {$order_field} {$sort_order}, r.first_name ASC, r.last_name ASC";

        $rosters = $wpdb->get_results($wpdb->prepare($query, $query_params), OBJECT);

        error_log('InterSoccer: Roster details query: ' . $wpdb->last_query);
        error_log('InterSoccer: Roster details results count: ' . count($rosters));

        if (!$rosters) {
            echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
            echo '<p>' . esc_html__('No rosters found for the provided parameters.', 'intersoccer-reports-rosters') . '</p></div>';
            return;
        }

        $base_roster = $rosters[0];
        $base_roster_girls_only = (int) ($base_roster->girls_only ?? 0);

        $available_rosters_query = $wpdb->prepare("
        SELECT DISTINCT 
            r.product_id,
            r.variation_id,
            r.product_name,
            r.venue,
            r.age_group,
            r.activity_type,
            r.camp_terms,
            r.course_day,
            r.times,
            r.season,
            r.girls_only,
            COUNT(DISTINCT r.order_item_id) as current_players
        FROM $rosters_table r
        JOIN {$wpdb->posts} p ON r.order_id = p.ID
        WHERE p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND r.activity_type = %s
        AND r.girls_only = %d
        AND r.variation_id != %d
        GROUP BY r.product_id, r.variation_id, r.product_name, r.venue, r.age_group, r.activity_type, r.camp_terms, r.course_day, r.times, r.season, r.girls_only
        ORDER BY r.product_name, r.venue, r.age_group
    ", $base_roster->activity_type, $base_roster_girls_only, $variation_id);

        $available_rosters = $wpdb->get_results($available_rosters_query, OBJECT);

        $cross_gender_rosters_query = $wpdb->prepare("
        SELECT DISTINCT 
            r.product_id,
            r.variation_id,
            r.product_name,
            r.venue,
            r.age_group,
            r.activity_type,
            r.camp_terms,
            r.course_day,
            r.times,
            r.season,
            r.girls_only,
            COUNT(DISTINCT r.order_item_id) as current_players
        FROM $rosters_table r
        JOIN {$wpdb->posts} p ON r.order_id = p.ID
        WHERE p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND r.activity_type = %s
        AND r.girls_only != %d
        AND r.variation_id != %d
        GROUP BY r.product_id, r.variation_id, r.product_name, r.venue, r.age_group, r.activity_type, r.camp_terms, r.course_day, r.times, r.season, r.girls_only
        ORDER BY r.product_name, r.venue, r.age_group
    ", $base_roster->activity_type, $base_roster_girls_only, $variation_id);

        $cross_gender_rosters = $wpdb->get_results($cross_gender_rosters_query, OBJECT);

        $unknown_count = count(array_filter($rosters, fn($row) => $row->player_name === 'Unknown Attendee'));
    }

    // Get base roster for event attributes
    $base_roster = $base_roster ?? $rosters[0];

    // Determine if the event is camp-like - SIMPLIFIED
    $is_camp_like = ($base_roster->activity_type === 'Camp' || (!empty($base_roster->camp_terms) && $base_roster->camp_terms !== 'N/A'));
    $is_girls_only = (bool) $base_roster->girls_only;

    // Render the page
    echo '<div class="wrap">';
    $title_suffix = $is_girls_only ? ' (Girls Only)' : '';
    // Normalize product name to English for display
    $product_name = $base_roster->product_name ?: '';
    if (!empty($product_name) && function_exists('intersoccer_get_english_product_name')) {
        $product_name = intersoccer_get_english_product_name($product_name, $base_roster->product_id ?? 0);
    }
    $event_label = $product_name ?: ($base_roster->course_day ?: ($base_roster->camp_terms ?: __('Unknown Event', 'intersoccer-reports-rosters')));
    
    // Get age group for title - use base_roster if GET parameter is empty
    $display_age_group = !empty($age_group) ? $age_group : ($base_roster->age_group ?? '');
    if (function_exists('intersoccer_get_term_name')) {
        $display_age_group = intersoccer_get_term_name($display_age_group, 'pa_age-group');
    }
    $display_venue = $base_roster->venue ?? '';
    if (function_exists('intersoccer_get_term_name')) {
        $display_venue = intersoccer_get_term_name($display_venue, 'pa_intersoccer-venues');
    }
    // Only add parentheses if age group is not empty
    $age_group_suffix = !empty($display_age_group) && $display_age_group !== 'N/A'
        ? ' (' . esc_html($display_age_group) . ')'
        : '';
    
    echo '<h1>' . esc_html__('Roster Details for ', 'intersoccer-reports-rosters') . esc_html($event_label) . ' - ' . esc_html($display_venue) . $age_group_suffix . $title_suffix . '</h1>';
    
    if ($unknown_count > 0) {
        echo '<p style="color: red;">' . esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)) . '</p>';
    }
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th style="width: 15px;"><input type="checkbox" id="selectAll"></th>'; // New: Checkbox for select all
    echo '<th style="width: 120px;"><a href="' . esc_url(intersoccer_get_sort_url('order_date', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Order Date', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('order_date', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 140px;"><a href="' . esc_url(intersoccer_get_sort_url('player_name', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Name', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('player_name', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 140px;"><a href="' . esc_url(intersoccer_get_sort_url('last_name', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Surname', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('last_name', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 80px;"><a href="' . esc_url(intersoccer_get_sort_url('gender', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Gender', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('gender', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 130px;">' . esc_html__('Phone', 'intersoccer-reports-rosters') . '</th>';
    echo '<th style="width: 200px;">' . esc_html__('Email', 'intersoccer-reports-rosters') . '</th>';
    echo '<th style="width: 50px;"><a href="' . esc_url(intersoccer_get_sort_url('age', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Age', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('age', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 200px;">' . esc_html__('Medical/Dietary', 'intersoccer-reports-rosters') . '</th>';
    
    if ($is_camp_like) {
        echo '<th style="width: 100px;">' . esc_html__('Late Pickup', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 120px;">' . esc_html__('Late Pickup Days', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 120px;">' . esc_html__('Booking Type', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Monday', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Tuesday', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Wednesday', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Thursday', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Friday', 'intersoccer-reports-rosters') . '</th>';
    }
    
    echo '<th style="width: 100px;"><a href="' . esc_url(intersoccer_get_sort_url('age_group', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Age Group', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('age_group', $sort_by, $sort_order) . '</a></th>';

    // Add pa_date column for tournaments
    if ($base_roster->activity_type === 'Tournament') {
        echo '<th style="width: 120px;">' . esc_html__('Tournament Date (pa_date)', 'intersoccer-reports-rosters') . '</th>';
    }

    if ($is_girls_only) {
        echo '<th style="width: 90px;">' . esc_html__('Shirt Size', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 90px;">' . esc_html__('Shorts Size', 'intersoccer-reports-rosters') . '</th>';
    }
    
    echo '</tr>';
    echo '</thead><tbody>';
    
    foreach ($rosters as $row) {
        if (function_exists('intersoccer_roster_backfill_player_name_fields')) {
            $row_array = intersoccer_roster_backfill_player_name_fields((array) $row);
            foreach (['first_name', 'last_name', 'player_name', 'player_first_name', 'player_last_name'] as $name_field) {
                if (isset($row_array[$name_field])) {
                    $row->{$name_field} = $row_array[$name_field];
                }
            }
            if (!empty($row_array['id']) && function_exists('intersoccer_roster_persist_player_name_fields')) {
                intersoccer_roster_persist_player_name_fields($row_array);
            }
        }
        $is_unknown = $row->player_name === 'Unknown Attendee';
        $day_presence = [
            'Monday' => 'No',
            'Tuesday' => 'No',
            'Wednesday' => 'No',
            'Thursday' => 'No',
            'Friday' => 'No',
        ];
        if ($is_camp_like) {
            if (function_exists('intersoccer_roster_enrich_camp_fields_from_order_item')) {
                intersoccer_roster_enrich_camp_fields_from_order_item($row);
            }
            $bt = $row->booking_type ?? '';
            $sd = function_exists('intersoccer_roster_effective_selected_days_string')
                ? intersoccer_roster_effective_selected_days_string($row)
                : (string) ($row->selected_days ?? '');
            if (function_exists('intersoccer_roster_compute_camp_day_presence_for_display')) {
                $day_presence = intersoccer_roster_compute_camp_day_presence_for_display($bt, $sd);
            }
        }

        echo '<tr data-order-item-id="' . esc_attr($row->order_item_id) . '">';
        echo '<td><input type="checkbox" class="player-select"></td>'; // New: Checkbox for selection
        echo '<td>' . esc_html($row->order_date ? date_i18n('Y-m-d H:i', strtotime($row->order_date)) : 'N/A') . '</td>';
        $display_first = function_exists('intersoccer_roster_display_first_name')
            ? intersoccer_roster_display_first_name($row)
            : ($row->first_name ?? 'N/A');
        $display_last = function_exists('intersoccer_roster_display_last_name')
            ? intersoccer_roster_display_last_name($row)
            : ($row->last_name ?? 'N/A');
        echo '<td' . ($is_unknown ? ' style="font-style: italic; color: red;"' : '') . '>' . esc_html($display_first) . '</td>';
        echo '<td' . ($is_unknown ? ' style="font-style: italic; color: red;"' : '') . '>' . esc_html($display_last) . '</td>';
        echo '<td>' . esc_html($row->gender ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->parent_phone ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->parent_email ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->age ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->medical_conditions ?? 'N/A') . '</td>';
        
        if ($is_camp_like) {
            $display_late_pickup_days = function_exists('intersoccer_roster_display_late_pickup_days')
                ? intersoccer_roster_display_late_pickup_days($row)
                : ($row->late_pickup_days ?? 'N/A');
            echo '<td>' . esc_html($row->late_pickup ?? 'No') . '</td>';
            echo '<td>' . esc_html($display_late_pickup_days !== '' ? $display_late_pickup_days : 'N/A') . '</td>';
            echo '<td>' . esc_html($row->booking_type ?? 'N/A') . '</td>';
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            foreach ($days as $day) {
                $presence = $day_presence[$day] ?? 'No';
                $style = ($presence === 'Yes') ? 'background-color: green; color: black;' : 'background-color: lightpink; color: black;';
                echo '<td style="' . esc_attr($style) . '">' . esc_html($presence) . '</td>';
            }
        }
        
        echo '<td>' . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($row->age_group, 'pa_age-group') : $row->age_group ?? 'N/A') . '</td>';
        
        // Display pa_date for tournaments
        if ($base_roster->activity_type === 'Tournament') {
            $pa_date = 'N/A';
            if (!empty($row->variation_id)) {
                $variation = wc_get_product($row->variation_id);
                if ($variation) {
                    $pa_date = $variation->get_attribute('pa_date') ?: $variation->get_attribute('Date') ?: 'N/A';
                }
            }
            if ($pa_date === 'N/A' && !empty($row->product_id)) {
                $product = wc_get_product($row->product_id);
                if ($product) {
                    $pa_date = $product->get_attribute('pa_date') ?: $product->get_attribute('Date') ?: 'N/A';
                }
            }
            echo '<td>' . esc_html($pa_date) . '</td>';
        }
        
        if ($is_girls_only) {
            echo '<td>' . esc_html($row->shirt_size ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($row->shorts_size ?? 'N/A') . '</td>';
        }
        
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    // Bulk actions section - New: Added for player migration
    echo '<div class="bulk-actions">';
    echo '    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">';
    echo '        <div>';
    echo '            <label for="bulkActionSelect" style="font-weight: 500; margin-right: 8px;">Action:</label>';
    echo '            <select id="bulkActionSelect">';
    echo '                <option value="">Select Action</option>';
    echo '                <option value="move">Move to Another Roster</option>';
    echo '            </select>';
    echo '        </div>';
    echo '    </div>';
    
    // Cross-Gender Override Option
    echo '    <div id="crossGenderOption" style="display: none; margin: 15px 0 10px 0; padding: 12px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">';
    echo '        <label style="cursor: pointer;">';
    echo '            <input type="checkbox" id="allowCrossGender" value="1">';
    echo '            <strong style="color: #856404;">⚠️ Allow moving between Girls Only and Regular rosters</strong>';
    echo '        </label>';
    echo '        <p style="margin: 8px 0 0 24px; font-size: 13px; color: #856404; line-height: 1.5;">';
    echo '            <strong>Use this to fix purchase mistakes.</strong> When enabled, you can move players between rosters with different gender types. ';
    echo '            The player\'s details will be preserved, but they will be assigned to a different event type.';
    echo '        </p>';
    echo '    </div>';
            
    echo '    <div id="moveOptions" style="display: none; margin-top: 10px;">';
    echo '        <div style="display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;">';
    echo '            <div>';
    echo '                <label for="targetSeasonSelect" style="font-weight: 500; margin-right: 8px;">' . esc_html__('Season', 'intersoccer-reports-rosters') . ':</label>';
    echo '                <select id="targetSeasonSelect" style="min-width: 170px;">';
    echo '                    <option value="">' . esc_html__('Select season...', 'intersoccer-reports-rosters') . '</option>';
    echo '                </select>';
    echo '            </div>';
    echo '            <div>';
    echo '                <label for="targetScheduleSelect" style="font-weight: 500; margin-right: 8px;">' . esc_html__('Day / Term', 'intersoccer-reports-rosters') . ':</label>';
    echo '                <select id="targetScheduleSelect" style="min-width: 170px;" disabled>';
    echo '                    <option value="">' . esc_html__('Select season first...', 'intersoccer-reports-rosters') . '</option>';
    echo '                </select>';
    echo '            </div>';
    echo '            <div>';
    echo '                <label for="targetVenueSelect" style="font-weight: 500; margin-right: 8px;">' . esc_html__('Venue', 'intersoccer-reports-rosters') . ':</label>';
    echo '                <select id="targetVenueSelect" style="min-width: 170px;" disabled>';
    echo '                    <option value="">' . esc_html__('Select day or term first...', 'intersoccer-reports-rosters') . '</option>';
    echo '                </select>';
    echo '            </div>';
    echo '            <div>';
    echo '                <label for="targetAgeGroupSelect" style="font-weight: 500; margin-right: 8px;">' . esc_html__('Age Group', 'intersoccer-reports-rosters') . ':</label>';
    echo '                <select id="targetAgeGroupSelect" style="min-width: 170px;" disabled>';
    echo '                    <option value="">' . esc_html__('Select venue first...', 'intersoccer-reports-rosters') . '</option>';
    echo '                </select>';
    echo '            </div>';
    echo '            <div>';
    echo '                <label for="targetTimeSelect" style="font-weight: 500; margin-right: 8px;">' . esc_html__('Time', 'intersoccer-reports-rosters') . ':</label>';
    echo '                <select id="targetTimeSelect" style="min-width: 170px;" disabled>';
    echo '                    <option value="">' . esc_html__('Select age group first...', 'intersoccer-reports-rosters') . '</option>';
    echo '                </select>';
    echo '            </div>';
    echo '            <div>';
    echo '                <label for="targetRosterSelect" style="font-weight: 500; margin-right: 8px;">' . esc_html__('Destination Roster', 'intersoccer-reports-rosters') . ':</label>';
    echo '                <select id="targetRosterSelect" style="min-width: 400px;" disabled>';
    echo '                    <option value="">' . esc_html__('Select time first...', 'intersoccer-reports-rosters') . '</option>';
    echo '                </select>';
    echo '            </div>';
    echo '            <div>';
    echo '                <button id="applyBulk" class="button button-primary">' . esc_html__('Apply', 'intersoccer-reports-rosters') . '</button>';
    echo '            </div>';
    echo '        </div>';
    echo '        <div style="margin-top: 8px; font-size: 12px; color: #666;">' . esc_html__('Use each filter in order to narrow destination rosters.', 'intersoccer-reports-rosters') . '</div>';
    echo '        <select id="targetRosterOptionsSource" style="display: none;" aria-hidden="true" tabindex="-1">';
    echo '            <option value="">' . esc_html__('Select a destination roster...', 'intersoccer-reports-rosters') . '</option>';
    
    // Cache taxonomy label lookups once per unique value to keep rendering lightweight.
    $term_label_cache = [];
    $resolve_term_label = static function($value, $taxonomy) use (&$term_label_cache) {
        $raw = trim((string) $value);
        if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
            return '';
        }
        $cache_key = $taxonomy . '|' . strtolower($raw);
        if (!array_key_exists($cache_key, $term_label_cache)) {
            $resolved = function_exists('intersoccer_get_term_name')
                ? intersoccer_get_term_name($raw, $taxonomy)
                : $raw;
            $term_label_cache[$cache_key] = ($resolved && strcasecmp((string) $resolved, 'N/A') !== 0)
                ? (string) $resolved
                : $raw;
        }
        return $term_label_cache[$cache_key];
    };
    $normalize_sortable = static function($value) {
        return strtolower(trim((string) $value));
    };
    $build_roster_label = static function($roster) use ($resolve_term_label) {
        $product = trim((string) ($roster->product_name ?? ''));
        $venue = $resolve_term_label($roster->venue ?? '', 'pa_intersoccer-venues');
        $age_group = $resolve_term_label($roster->age_group ?? '', 'pa_age-group');
        $season = trim((string) ($roster->season ?? ''));
        $course_day = $resolve_term_label($roster->course_day ?? '', 'pa_course-day');
        $camp_terms = $resolve_term_label($roster->camp_terms ?? '', 'pa_camp-terms');
        $times = trim((string) ($roster->times ?? ''));
        $players = (int) ($roster->current_players ?? 0);

        $schedule_label = '';
        if ($course_day !== '') {
            $schedule_label = 'Day: ' . $course_day;
        } elseif ($camp_terms !== '') {
            $schedule_label = 'Term: ' . $camp_terms;
        } elseif ($season !== '') {
            $schedule_label = 'Season: ' . $season;
        }

        $parts = [];
        $parts[] = $product !== '' ? $product : __('Untitled roster', 'intersoccer-reports-rosters');
        if ($season !== '') {
            $parts[] = $season;
        }
        if ($schedule_label !== '') {
            $parts[] = $schedule_label;
        }
        if ($venue !== '') {
            $parts[] = $venue;
        }
        if ($age_group !== '') {
            $parts[] = $age_group;
        }
        if ($times !== '' && strcasecmp($times, 'N/A') !== 0) {
            $parts[] = $times;
        }
        $parts[] = sprintf(_n('%d player', '%d players', $players, 'intersoccer-reports-rosters'), $players);

        return implode(' | ', $parts);
    };

    $render_roster_options = static function(array $rosters, $cross_gender) use ($normalize_sortable, $resolve_term_label, $build_roster_label) {
        if (empty($rosters)) {
            return;
        }
        usort($rosters, static function($a, $b) use ($normalize_sortable) {
            $a_season = $normalize_sortable($a->season ?? '');
            $b_season = $normalize_sortable($b->season ?? '');
            if ($a_season !== $b_season) {
                return strcmp($a_season, $b_season);
            }
            $a_schedule = $normalize_sortable(($a->course_day ?? '') ?: ($a->camp_terms ?? ''));
            $b_schedule = $normalize_sortable(($b->course_day ?? '') ?: ($b->camp_terms ?? ''));
            if ($a_schedule !== $b_schedule) {
                return strcmp($a_schedule, $b_schedule);
            }
            $a_venue = $normalize_sortable($a->venue ?? '');
            $b_venue = $normalize_sortable($b->venue ?? '');
            if ($a_venue !== $b_venue) {
                return strcmp($a_venue, $b_venue);
            }
            $a_age = $normalize_sortable($a->age_group ?? '');
            $b_age = $normalize_sortable($b->age_group ?? '');
            if ($a_age !== $b_age) {
                return strcmp($a_age, $b_age);
            }
            return strcmp($normalize_sortable($a->times ?? ''), $normalize_sortable($b->times ?? ''));
        });

        foreach ($rosters as $roster) {
            $season = trim((string) ($roster->season ?? ''));
            $season = ($season !== '' && strcasecmp($season, 'N/A') !== 0) ? $season : __('Not set', 'intersoccer-reports-rosters');
            $schedule = $resolve_term_label($roster->course_day ?? '', 'pa_course-day');
            if ($schedule === '') {
                $schedule = $resolve_term_label($roster->camp_terms ?? '', 'pa_camp-terms');
            }
            if ($schedule === '') {
                $schedule = __('Not set', 'intersoccer-reports-rosters');
            }
            $venue = $resolve_term_label($roster->venue ?? '', 'pa_intersoccer-venues');
            if ($venue === '') {
                $venue = __('Not set', 'intersoccer-reports-rosters');
            }
            $age_group = $resolve_term_label($roster->age_group ?? '', 'pa_age-group');
            if ($age_group === '') {
                $age_group = __('Not set', 'intersoccer-reports-rosters');
            }
            $time = trim((string) ($roster->times ?? ''));
            if ($time === '' || strcasecmp($time, 'N/A') === 0) {
                $time = __('Not set', 'intersoccer-reports-rosters');
            }
            $roster_label = $build_roster_label($roster);
            echo '            <option value="' . esc_attr($roster->variation_id) . '" data-season="' . esc_attr($season) . '" data-schedule="' . esc_attr($schedule) . '" data-venue="' . esc_attr($venue) . '" data-age-group="' . esc_attr($age_group) . '" data-time="' . esc_attr($time) . '" data-girls-only="' . esc_attr($roster->girls_only ? '1' : '0') . '" data-cross-gender="' . esc_attr($cross_gender ? '1' : '0') . '">' . esc_html($roster_label) . '</option>';
        }
    };

    // Same-gender rosters (always shown)
    if (!empty($available_rosters)) {
        $render_roster_options($available_rosters, false);
    }

    // Cross-gender rosters (hidden by default, shown when checkbox enabled)
    if (!empty($cross_gender_rosters)) {
        $render_roster_options($cross_gender_rosters, true);
    }
    
    if (empty($available_rosters) && empty($cross_gender_rosters)) {
        echo '            <option value="" disabled>' . esc_html__('No other rosters available', 'intersoccer-reports-rosters') . '</option>';
    }
    echo '        </select>';
    echo '    </div>';
        
    echo '    <div style="margin-top: 10px; font-size: 13px; color: #666;">';
    echo '        <strong>' . esc_html__('Instructions:', 'intersoccer-reports-rosters') . '</strong> ';
    echo '        ' . esc_html__('1) Select players using checkboxes', 'intersoccer-reports-rosters') . ' ';
    echo '        ' . esc_html__('2) Choose "Move to Another Roster"', 'intersoccer-reports-rosters') . ' ';
    echo '        ' . esc_html__('3) Choose season, day/term, venue, age group, and time to reveal destination roster options', 'intersoccer-reports-rosters') . ' ';
    echo '        ' . esc_html__('4) Select destination roster and click Apply', 'intersoccer-reports-rosters');
    echo '        <br>';
    echo '        <strong>' . esc_html__('Note:', 'intersoccer-reports-rosters') . '</strong> ' . esc_html__('This will update order items and preserve original pricing. Changes cannot be undone.', 'intersoccer-reports-rosters');
    echo '    </div>';
    echo '</div>';
    
    // Export form - include order_item_ids from displayed rosters for reliable export (fallback when event_signature has no match)
    $export_order_item_ids = [];
    foreach ($rosters as $r) {
        $oid = is_object($r) ? (isset($r->order_item_id) ? (int) $r->order_item_id : 0) : (isset($r['order_item_id']) ? (int) $r['order_item_id'] : 0);
        if ($oid > 0) {
            $export_order_item_ids[$oid] = $oid;
        }
    }
    $export_order_item_ids = array_values($export_order_item_ids);

    echo '<div id="roster-export-notice" style="margin-top: 20px;"></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '" class="export-form" id="roster-export-form" style="margin-top: 20px;">';
    echo '<input type="hidden" name="action" value="intersoccer_export_roster">';
    echo '<input type="hidden" name="use_fields" value="1">';
    if (!empty($export_order_item_ids)) {
        echo '<input type="hidden" name="order_item_ids" value="' . esc_attr(implode(',', $export_order_item_ids)) . '">';
    }
    if ($event_signature) {
        echo '<input type="hidden" name="event_signature" value="' . esc_attr($event_signature) . '">';
    }
    if (!empty($variation_ids)) {
        echo '<input type="hidden" name="variation_ids" value="' . esc_attr(implode(',', array_map('intval', $variation_ids))) . '">';
    }
    if ($variation_id > 0) {
        echo '<input type="hidden" name="variation_id" value="' . esc_attr($variation_id) . '">';
    }
    echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">';
    echo '<input type="hidden" name="activity_types" value="' . esc_attr($base_roster->activity_type) . '">';
    echo '<input type="hidden" name="camp_terms" value="' . esc_attr($camp_terms) . '">';
    echo '<input type="hidden" name="course_day" value="' . esc_attr($course_day) . '">';
    echo '<input type="hidden" name="venue" value="' . esc_attr($venue) . '">';
    echo '<input type="hidden" name="age_group" value="' . esc_attr($age_group) . '">';
    echo '<input type="hidden" name="times" value="' . esc_attr($times) . '">';
    echo '<input type="hidden" name="girls_only" value="' . ($is_girls_only ? '1' : '0') . '">';
    echo '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')) . '">';
    echo '<label style="margin-right:12px;"><input type="checkbox" name="sync_to_office365" id="roster-sync-office365" value="1" /> ' . esc_html__('Also sync to Office 365', 'intersoccer-reports-rosters') . '</label>';
    echo '<input type="submit" name="export_roster" id="roster-export-button" class="button button-primary" value="' . esc_attr__('Export Roster', 'intersoccer-reports-rosters') . '">';
    echo '</form>';
    
    echo '<p><strong>' . esc_html__('Event Details', 'intersoccer-reports-rosters') . ':</strong></p>';
    echo '<p>' . esc_html__('Product Name: ', 'intersoccer-reports-rosters') . esc_html($base_roster->product_name ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Venue: ', 'intersoccer-reports-rosters') . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->venue, 'pa_intersoccer-venues') : $base_roster->venue ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Age Group: ', 'intersoccer-reports-rosters') . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->age_group, 'pa_age-group') : $base_roster->age_group ?? 'N/A') . '</p>';
    // Display day/terms based on activity type
    if ($base_roster->activity_type === 'Tournament') {
        echo '<p>' . esc_html__('Tournament Day: ', 'intersoccer-reports-rosters') . esc_html($base_roster->course_day ?? 'N/A') . '</p>';
    } elseif ($base_roster->course_day && $base_roster->course_day !== 'N/A') {
        echo '<p>' . esc_html__('Course Day: ', 'intersoccer-reports-rosters') . esc_html($base_roster->course_day) . '</p>';
    } else {
        echo '<p>' . esc_html__('Camp Terms: ', 'intersoccer-reports-rosters') . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->camp_terms, 'pa_camp-terms') : $base_roster->camp_terms ?? 'N/A') . '</p>';
    }
    
    // Display times label based on activity type
    if ($base_roster->activity_type === 'Tournament') {
        $times_label = __('Tournament Time: ', 'intersoccer-reports-rosters');
        $times_value = $base_roster->times ?? 'N/A';
    } elseif ($is_camp_like) {
        $times_label = __('Camp Times: ', 'intersoccer-reports-rosters');
        $times_value = function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->times, 'pa_camp-times') : ($base_roster->times ?? 'N/A');
    } else {
        $times_label = __('Course Times: ', 'intersoccer-reports-rosters');
        $times_value = function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->times, 'pa_course-times') : ($base_roster->times ?? 'N/A');
    }
    echo '<p>' . esc_html($times_label) . esc_html($times_value) . '</p>';
    echo '<p>' . esc_html__('Girls Only: ', 'intersoccer-reports-rosters') . ($is_girls_only ? 'Yes' : 'No') . '</p>';
    
    // Check if roster is closed
    $is_closed = !empty($base_roster->event_completed);
    if ($is_closed) {
        echo '<p><strong style="color: #d63638;">' . esc_html__('Status: ', 'intersoccer-reports-rosters') . '</strong><span style="color: #d63638;">' . esc_html__('Closed', 'intersoccer-reports-rosters') . '</span></p>';
    }
    
    echo '<p><strong>' . esc_html__('Total Players', 'intersoccer-reports-rosters') . ':</strong> ' . esc_html(count($rosters)) . '</p>';
    
    // Close Out / Reopen button
    $action_event_signature = $event_signature ?: ($base_roster->event_signature ?? '');
    echo '<div style="margin-top: 20px;">';
    if ($is_closed) {
        echo '<button type="button" class="reopen-roster-btn" id="roster-reopen-btn" 
                data-event-signature="' . esc_attr($action_event_signature) . '"
                title="' . esc_attr__('Reopen Roster', 'intersoccer-reports-rosters') . '">';
        echo esc_html__('Reopen', 'intersoccer-reports-rosters');
        echo '</button>';
    } else {
        echo '<button type="button" class="close-roster-btn" id="roster-close-btn"
                data-event-signature="' . esc_attr($action_event_signature) . '"
                title="' . esc_attr__('Close Out Roster', 'intersoccer-reports-rosters') . '">';
        echo esc_html__('Close Out', 'intersoccer-reports-rosters');
        echo '</button>';
    }
    echo '</div>';

    // Repair day presence (admin only) - updates only the day_presence JSON for this event_signature.
    $repair_event_signature = null;
    if (isset($base_roster)) {
        if (is_object($base_roster) && !empty($base_roster->event_signature)) {
            $repair_event_signature = $base_roster->event_signature;
        } elseif (is_array($base_roster) && !empty($base_roster['event_signature'])) {
            $repair_event_signature = $base_roster['event_signature'];
        }
    }
    // Fallback to URL parameter (legacy query does not select event_signature into base_roster)
    if (empty($repair_event_signature) && !empty($event_signature) && $event_signature !== 'N/A') {
        $repair_event_signature = $event_signature;
    }

    if (current_user_can('manage_options') && !empty($repair_event_signature)) {
        echo '<div style="margin-top: 10px;">';
        echo '<button type="button" class="button button-secondary" id="repair-day-presence-btn" data-event-signature="' . esc_attr($repair_event_signature) . '">';
        echo esc_html__('Repair Day Presence', 'intersoccer-reports-rosters');
        echo '</button>';
        echo '<span id="repair-day-presence-result" style="margin-left: 10px;"></span>';
        echo '</div>';
    }
    
    // Add CSS for icon buttons
    echo '<style>
        .close-roster-btn, .reopen-roster-btn {
            background: #dc3232;
            color: white;
            padding: 8px 10px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            transition: all 0.2s ease;
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-indent: -9999px;
            position: relative;
        }
        .close-roster-btn::before {
            content: "\\00D7";
            position: absolute;
            text-indent: 0;
            font-size: 24px;
            font-weight: bold;
            line-height: 1;
        }
        .close-roster-btn:hover {
            background: #a00;
            transform: translateY(-1px);
        }
        .reopen-roster-btn {
            background: #46b450;
        }
        .reopen-roster-btn::before {
            content: "\\21BB";
            position: absolute;
            text-indent: 0;
            font-size: 18px;
        }
        .reopen-roster-btn:hover {
            background: #2e7d32;
            transform: translateY(-1px);
        }
    </style>';
    
    echo '</div>';
    
    // JavaScript for bulk actions and close/reopen
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var intersoccerAjaxUrl = (typeof ajaxurl !== 'undefined' && ajaxurl) ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        var rosterDetailsBaseUrl = '<?php echo esc_js(admin_url('admin.php?page=intersoccer-roster-details')); ?>';
        var rosterDetailsFromPage = '<?php echo esc_js($from_page); ?>';
        
        // Repair day presence handler
        $(document).on('click', '#repair-day-presence-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var eventSignature = $btn.data('event-signature');
            var $result = $('#repair-day-presence-result');

            if (!eventSignature) {
                $result.text('Missing event signature.');
                return;
            }

            $btn.prop('disabled', true);
            $result.text('Repairing...');

            $.ajax({
                url: typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'intersoccer_repair_day_presence',
                    event_signature: eventSignature,
                    nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>'
                },
                success: function(resp) {
                    if (resp && resp.success) {
                        $result.text(resp.data && resp.data.message ? resp.data.message : 'Repair completed.');
                    } else {
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Repair failed.';
                        $result.text(msg);
                    }
                },
                error: function() {
                    $result.text('Repair failed (network/server error).');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });

        // Close roster handler (for roster details page)
        $(document).on('click', '.close-roster-btn, #roster-close-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var eventSignature = $btn.data('event-signature');
            
            if (!confirm('<?php echo esc_js(__('Are you sure you want to close out this roster? This will mark the event as completed.', 'intersoccer-reports-rosters')); ?>')) {
                return;
            }
            
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Closing...', 'intersoccer-reports-rosters')); ?>');
            
            $.ajax({
                url: intersoccerAjaxUrl,
                type: 'POST',
                data: {
                    action: 'intersoccer_close_out_roster',
                    nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                    event_signature: eventSignature,
                    source_page: 'girls-only-details'
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || '<?php echo esc_js(__('Roster closed successfully.', 'intersoccer-reports-rosters')); ?>');
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Failed to close roster.', 'intersoccer-reports-rosters')); ?>');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                    $btn.prop('disabled', false);
                }
            });
        });
        
        // Reopen roster handler (for roster details page)
        $(document).on('click', '.reopen-roster-btn, #roster-reopen-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var eventSignature = $btn.data('event-signature');
            
            if (!confirm('<?php echo esc_js(__('Are you sure you want to reopen this roster?', 'intersoccer-reports-rosters')); ?>')) {
                return;
            }
            
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Reopening...', 'intersoccer-reports-rosters')); ?>');
            
            $.ajax({
                url: intersoccerAjaxUrl,
                type: 'POST',
                data: {
                    action: 'intersoccer_reopen_roster',
                    nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                    event_signature: eventSignature,
                    source_page: 'girls-only-details'
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || '<?php echo esc_js(__('Roster reopened successfully.', 'intersoccer-reports-rosters')); ?>');
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Failed to reopen roster.', 'intersoccer-reports-rosters')); ?>');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                    $btn.prop('disabled', false);
                }
            });
        });
        
        const selectAll = $('#selectAll');
        const playerSelects = $('.player-select');
        const bulkActionSelect = $('#bulkActionSelect');
        const moveOptions = $('#moveOptions');
        const applyBulk = $('#applyBulk');
        const targetRosterSelect = $('#targetRosterSelect');
        const targetSeasonSelect = $('#targetSeasonSelect');
        const targetScheduleSelect = $('#targetScheduleSelect');
        const targetVenueSelect = $('#targetVenueSelect');
        const targetAgeGroupSelect = $('#targetAgeGroupSelect');
        const targetTimeSelect = $('#targetTimeSelect');
        const targetRosterOptionsSource = $('#targetRosterOptionsSource');
        const filterSelects = [
            targetSeasonSelect,
            targetScheduleSelect,
            targetVenueSelect,
            targetAgeGroupSelect,
            targetTimeSelect
        ];
        const allDestinationOptions = targetRosterOptionsSource.find('option[value!=""]').map(function() {
            return {
                value: $(this).val(),
                text: $(this).text(),
                season: String($(this).data('season') || ''),
                schedule: String($(this).data('schedule') || ''),
                venue: String($(this).data('venue') || ''),
                ageGroup: String($(this).data('age-group') || ''),
                time: String($(this).data('time') || ''),
                girlsOnly: String($(this).data('girls-only') || '0'),
                crossGender: String($(this).data('cross-gender') || '0')
            };
        }).get();

        // Enhanced logging for debugging
        console.log('InterSoccer Migration: JavaScript initialized');
        console.log('InterSoccer Migration: Found elements - selectAll:', selectAll.length, 'playerSelects:', playerSelects.length);
        console.log('InterSoccer Migration: Found destination options:', allDestinationOptions.length);

        const migrationTexts = {
            chooseDestination: '<?php echo esc_js(__('Select a destination roster...', 'intersoccer-reports-rosters')); ?>',
            chooseSeasonFirst: '<?php echo esc_js(__('Select season first...', 'intersoccer-reports-rosters')); ?>',
            chooseScheduleFirst: '<?php echo esc_js(__('Select day or term first...', 'intersoccer-reports-rosters')); ?>',
            chooseVenueFirst: '<?php echo esc_js(__('Select venue first...', 'intersoccer-reports-rosters')); ?>',
            chooseAgeFirst: '<?php echo esc_js(__('Select age group first...', 'intersoccer-reports-rosters')); ?>',
            chooseTimeFirst: '<?php echo esc_js(__('Select time first...', 'intersoccer-reports-rosters')); ?>',
            noMatchingRosters: '<?php echo esc_js(__('No destination rosters match current filters', 'intersoccer-reports-rosters')); ?>'
        };

        function resetSelectOptions($select, placeholderText, disabledState) {
            $select.empty().append($('<option>', { value: '', text: placeholderText }));
            $select.prop('disabled', !!disabledState);
            $select.val('');
        }

        function uniqueSortedValues(options, key) {
            return [...new Set(options.map((option) => option[key]).filter(Boolean))].sort((a, b) => a.localeCompare(b));
        }

        function currentCrossGenderSetting() {
            return $('#allowCrossGender').is(':checked');
        }

        function getEligibleDestinationOptions() {
            const includeCrossGender = currentCrossGenderSetting();
            return allDestinationOptions.filter((option) => includeCrossGender || option.crossGender !== '1');
        }

        function applyOptionsToSelect($select, values, placeholderText) {
            resetSelectOptions($select, placeholderText, false);
            values.forEach((value) => {
                $select.append($('<option>', { value: value, text: value }));
            });
            if (!values.length) {
                $select.prop('disabled', true);
            }
        }

        function rebuildDestinationFilters() {
            const eligible = getEligibleDestinationOptions();
            const seasonValue = targetSeasonSelect.val();
            const scheduleValue = targetScheduleSelect.val();
            const venueValue = targetVenueSelect.val();
            const ageGroupValue = targetAgeGroupSelect.val();
            const timeValue = targetTimeSelect.val();

            const seasonValues = uniqueSortedValues(eligible, 'season');
            applyOptionsToSelect(targetSeasonSelect, seasonValues, '<?php echo esc_js(__('Select season...', 'intersoccer-reports-rosters')); ?>');
            if (seasonValues.includes(seasonValue)) {
                targetSeasonSelect.val(seasonValue);
            }

            const bySeason = targetSeasonSelect.val() ? eligible.filter((option) => option.season === targetSeasonSelect.val()) : [];
            const scheduleValues = uniqueSortedValues(bySeason, 'schedule');
            applyOptionsToSelect(targetScheduleSelect, scheduleValues, migrationTexts.chooseSeasonFirst);
            targetScheduleSelect.prop('disabled', !targetSeasonSelect.val() || !scheduleValues.length);
            if (scheduleValues.includes(scheduleValue)) {
                targetScheduleSelect.val(scheduleValue);
            }

            const bySchedule = targetScheduleSelect.val() ? bySeason.filter((option) => option.schedule === targetScheduleSelect.val()) : [];
            const venueValues = uniqueSortedValues(bySchedule, 'venue');
            applyOptionsToSelect(targetVenueSelect, venueValues, migrationTexts.chooseScheduleFirst);
            targetVenueSelect.prop('disabled', !targetScheduleSelect.val() || !venueValues.length);
            if (venueValues.includes(venueValue)) {
                targetVenueSelect.val(venueValue);
            }

            const byVenue = targetVenueSelect.val() ? bySchedule.filter((option) => option.venue === targetVenueSelect.val()) : [];
            const ageGroupValues = uniqueSortedValues(byVenue, 'ageGroup');
            applyOptionsToSelect(targetAgeGroupSelect, ageGroupValues, migrationTexts.chooseVenueFirst);
            targetAgeGroupSelect.prop('disabled', !targetVenueSelect.val() || !ageGroupValues.length);
            if (ageGroupValues.includes(ageGroupValue)) {
                targetAgeGroupSelect.val(ageGroupValue);
            }

            const byAgeGroup = targetAgeGroupSelect.val() ? byVenue.filter((option) => option.ageGroup === targetAgeGroupSelect.val()) : [];
            const timeValues = uniqueSortedValues(byAgeGroup, 'time');
            applyOptionsToSelect(targetTimeSelect, timeValues, migrationTexts.chooseAgeFirst);
            targetTimeSelect.prop('disabled', !targetAgeGroupSelect.val() || !timeValues.length);
            if (timeValues.includes(timeValue)) {
                targetTimeSelect.val(timeValue);
            }

            const finalMatches = targetTimeSelect.val() ? byAgeGroup.filter((option) => option.time === targetTimeSelect.val()) : [];
            resetSelectOptions(targetRosterSelect, migrationTexts.chooseTimeFirst, !targetTimeSelect.val());
            finalMatches.forEach((option) => {
                targetRosterSelect.append(
                    $('<option>', {
                        value: option.value,
                        text: option.text,
                        'data-girls-only': option.girlsOnly,
                        'data-cross-gender': option.crossGender
                    })
                );
            });

            if (targetTimeSelect.val()) {
                targetRosterSelect.prop('disabled', !finalMatches.length);
                if (!finalMatches.length) {
                    resetSelectOptions(targetRosterSelect, migrationTexts.noMatchingRosters, true);
                } else if (finalMatches.length === 1) {
                    targetRosterSelect.val(finalMatches[0].value);
                }
            }
        }

        function resetDestinationFilters() {
            resetSelectOptions(targetSeasonSelect, '<?php echo esc_js(__('Select season...', 'intersoccer-reports-rosters')); ?>', false);
            resetSelectOptions(targetScheduleSelect, migrationTexts.chooseSeasonFirst, true);
            resetSelectOptions(targetVenueSelect, migrationTexts.chooseScheduleFirst, true);
            resetSelectOptions(targetAgeGroupSelect, migrationTexts.chooseVenueFirst, true);
            resetSelectOptions(targetTimeSelect, migrationTexts.chooseAgeFirst, true);
            resetSelectOptions(targetRosterSelect, migrationTexts.chooseTimeFirst, true);
            rebuildDestinationFilters();
        }

        selectAll.on('change', function() {
            console.log('InterSoccer Migration: Select all toggled:', this.checked);
            playerSelects.prop('checked', this.checked);
        });

        bulkActionSelect.on('change', function() {
            const isMove = this.value === 'move';
            console.log('InterSoccer Migration: Bulk action changed to:', this.value, 'Show move options:', isMove);
            moveOptions.toggle(isMove);
            $('#crossGenderOption').toggle(isMove);
                        
            // Reset target roster selection when switching away from move
            if (!isMove) {
                targetRosterSelect.val('');
                $('#allowCrossGender').prop('checked', false);
                resetDestinationFilters();
            } else {
                rebuildDestinationFilters();
            }
        });

        filterSelects.forEach(($select) => {
            $select.on('change', function() {
                rebuildDestinationFilters();
                console.log('InterSoccer Migration: Filter changed:', this.id, $(this).val());
            });
        });

        targetRosterSelect.on('change', function() {
            const value = $(this).val().trim();
            console.log('InterSoccer Migration: Destination roster selected:', value);
        });
        
        // Handle cross-gender checkbox toggle
        $('#allowCrossGender').on('change', function() {
            const isChecked = $(this).is(':checked');
            console.log('InterSoccer Migration: Cross-gender checkbox toggled:', isChecked);
            rebuildDestinationFilters();
        });

        resetDestinationFilters();

        applyBulk.on('click', function() {
            console.log('InterSoccer Migration: Apply bulk action clicked');
            
            const action = bulkActionSelect.val();
            if (action !== 'move') {
                console.log('InterSoccer Migration: No move action selected');
                return;
            }

            const targetVar = targetRosterSelect.val().trim();
            if (!targetVar) {
                alert('<?php echo esc_js(__('Please select a destination roster.', 'intersoccer-reports-rosters')); ?>');
                targetRosterSelect.focus();
                return;
            }

            const selectedItems = [];
            playerSelects.each(function() {
                if ($(this).prop('checked')) {
                    const itemId = $(this).closest('tr').data('order-item-id');
                    if (itemId) {
                        selectedItems.push(itemId);
                    }
                }
            });

            console.log('InterSoccer Migration: Selected items:', selectedItems);

            if (selectedItems.length === 0) {
                alert('No players selected.');
                return;
            }

            // Enhanced confirmation with details
            const selectedOption = targetRosterSelect.find('option:selected');
            const destinationName = selectedOption.text();
            const destinationGirlsOnly = selectedOption.data('girls-only') === '1' || selectedOption.data('girls-only') === 1;
            const sourceGirlsOnly = <?php echo $is_girls_only ? 'true' : 'false'; ?>;
            const allowCrossGender = $('#allowCrossGender').is(':checked');
            const isCrossGender = sourceGirlsOnly !== destinationGirlsOnly;
            
            // Build confirmation message
            let confirmMessage = `Move ${selectedItems.length} player(s) to:\n"${destinationName}"\n\n`;
            
            // Add gender warning if applicable
            if (isCrossGender) {
                if (sourceGirlsOnly && !destinationGirlsOnly) {
                    confirmMessage += '⚠️ WARNING: Moving from Girls Only to Regular (Mixed Gender) roster\n\n';
                } else {
                    confirmMessage += '⚠️ WARNING: Moving from Regular to Girls Only roster\n\n';
                }
            }
            
            confirmMessage += 'This will:\n';
            confirmMessage += '  ✓ Update order items to new variation\n';
            confirmMessage += '  ✓ Change roster assignment\n';
            confirmMessage += '  ✓ Preserve original pricing\n';
            confirmMessage += '  ✓ Update roster database\n\n';
            confirmMessage += 'This action cannot be undone.\n\n';
            confirmMessage += 'Continue?';
            
            if (!confirm(confirmMessage)) {
                console.log('InterSoccer Migration: Migration cancelled by user');
                return;
            }

            const targetRosterUrl = new URL(rosterDetailsBaseUrl, window.location.origin);
            targetRosterUrl.searchParams.set('variation_id', String(parseInt(targetVar, 10)));
            if (rosterDetailsFromPage) {
                targetRosterUrl.searchParams.set('from', rosterDetailsFromPage);
            }
            

            console.log('InterSoccer Migration: Starting AJAX request with allow_cross_gender=' + allowCrossGender);

            // Enhanced AJAX call with better error handling
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_move_players',
                    nonce: '<?php echo esc_js(wp_create_nonce('intersoccer_move_nonce')); ?>',
                    target_variation_id: parseInt(targetVar),
                    order_item_ids: selectedItems,
                    allow_cross_gender: allowCrossGender ? '1' : '0'
                },
                beforeSend: function() {
                    console.log('InterSoccer Migration: AJAX request started');
                    applyBulk.prop('disabled', true).text('Moving Players...');
                    
                    // Show progress indicator
                    $('<div id="migration-progress" style="margin-top: 10px; padding: 10px; background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px;">' +
                    '<strong>Migration in Progress...</strong><br>' +
                    'Moving ' + selectedItems.length + ' player(s) to "' + destinationName + '"...' +
                    '</div>').insertAfter(applyBulk);
                },
                success: function(response) {
                    console.log('InterSoccer Migration: AJAX success:', response);
                    
                    $('#migration-progress').remove();
                    
                    if (response.success) {
                        alert('Success: ' + response.data.message);
                        const shouldOpenTargetRoster = confirm('<?php echo esc_js(__('Open destination roster in a new tab to verify migrated player data?', 'intersoccer-reports-rosters')); ?>');
                        if (shouldOpenTargetRoster) {
                            window.open(targetRosterUrl.toString(), '_blank');
                        }
                        
                        // Clear selections and reset form
                        playerSelects.prop('checked', false);
                        selectAll.prop('checked', false);
                        bulkActionSelect.val('');
                        resetDestinationFilters();
                        moveOptions.hide();
                        
                        // Reload page to show updated data
                        console.log('InterSoccer Migration: Reloading page to show changes');
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data?.message || 'Unknown error occurred'));
                        console.error('InterSoccer Migration: Server returned error:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('InterSoccer Migration: AJAX error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                    
                    $('#migration-progress').remove();
                    
                    let errorMessage = 'AJAX error occurred.';
                    
                    // Try to parse JSON error response
                    try {
                        const errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse.data && errorResponse.data.message) {
                            errorMessage = 'Error: ' + errorResponse.data.message;
                        }
                    } catch (e) {
                        // If not JSON, use status text or generic message
                        if (xhr.status === 403) {
                            errorMessage = 'Permission denied. Please refresh the page and try again.';
                        } else if (xhr.status === 404) {
                            errorMessage = 'Migration function not found. Please check plugin configuration.';
                        } else if (xhr.status >= 500) {
                            errorMessage = 'Server error occurred. Please check the error logs.';
                        }
                    }
                    
                    alert(errorMessage);
                },
                complete: function() {
                    console.log('InterSoccer Migration: AJAX request completed');
                    applyBulk.prop('disabled', false).text('Apply');
                    $('#migration-progress').remove();
                }
            });
        });

        // Add keyboard shortcuts for better UX
        $(document).on('keydown', function(e) {
            // Ctrl+A to select all (when focused on table)
            if (e.ctrlKey && e.key === 'a' && $(e.target).closest('table').length) {
                e.preventDefault();
                selectAll.prop('checked', true).trigger('change');
            }
            
            // Escape to clear selections
            if (e.key === 'Escape') {
                playerSelects.prop('checked', false);
                selectAll.prop('checked', false);
                bulkActionSelect.val('');
                resetDestinationFilters();
                moveOptions.hide();
            }
        });

        // Export form AJAX handling with notification banners
        $('#roster-export-form').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $button = $('#roster-export-button');
            var $noticeContainer = $('#roster-export-notice');
            var originalButtonText = $button.val();
            
            // Helper function to show WordPress-style notification
            function showExportNotice(message, type) {
                type = type || 'info'; // success, error, warning, info
                var noticeClass = 'notice notice-' + type + ' is-dismissible';
                var notice = $('<div class="' + noticeClass + '"><p><strong>' + message + '</strong></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
                
                $noticeContainer.html(notice);
                
                // Auto-dismiss after 5 seconds for success, 10 seconds for errors
                var dismissDelay = (type === 'success') ? 5000 : 10000;
                setTimeout(function() {
                    notice.fadeOut(function() {
                        $(this).remove();
                    });
                }, dismissDelay);
                
                // Handle manual dismiss
                notice.find('.notice-dismiss').on('click', function() {
                    notice.fadeOut(function() {
                        $(this).remove();
                    });
                });
                
                // Scroll to notice
                $('html, body').animate({
                    scrollTop: $noticeContainer.offset().top - 50
                }, 300);
            }
            
            // Show "Exporting..." notice
            showExportNotice('<?php echo esc_js(__('Exporting roster...', 'intersoccer-reports-rosters')); ?>', 'info');
            $button.prop('disabled', true).val('<?php echo esc_js(__('Exporting...', 'intersoccer-reports-rosters')); ?>');
            
            // Submit via AJAX
            $.ajax({
                url: typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                type: 'POST',
                data: $form.serialize(),
                timeout: 120000, // 2 minutes timeout for large exports
                success: function(response) {
                    if (response.success && response.data && response.data.content && response.data.filename) {
                        // Create and trigger download
                        try {
                            var binary = atob(response.data.content);
                            var array = new Uint8Array(binary.length);
                            for (var i = 0; i < binary.length; i++) {
                                array[i] = binary.charCodeAt(i);
                            }
                            var blob = new Blob([array], {
                                type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            });
                            var link = document.createElement("a");
                            link.href = window.URL.createObjectURL(blob);
                            link.download = response.data.filename;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            window.URL.revokeObjectURL(link.href);
                            
                            // Show success notice; include Office 365 sync status if present
                            var successMsg = '<?php echo esc_js(__('Export completed successfully!', 'intersoccer-reports-rosters')); ?>';
                            if (response.data.synced === true) {
                                successMsg = '<?php echo esc_js(__('Export completed and synced to Office 365.', 'intersoccer-reports-rosters')); ?>';
                            } else if (response.data.synced === false && response.data.sync_error) {
                                successMsg = '<?php echo esc_js(__('Export completed. Sync to Office 365 failed:', 'intersoccer-reports-rosters')); ?> ' + response.data.sync_error;
                            }
                            showExportNotice(successMsg, response.data.synced === false && response.data.sync_error ? 'warning' : 'success');
                        } catch (err) {
                            console.error('Export download error:', err);
                            showExportNotice('<?php echo esc_js(__('Export generated but download failed. Please try again.', 'intersoccer-reports-rosters')); ?>', 'error');
                        }
                    } else {
                        var errorMsg = response.data && response.data.message 
                            ? response.data.message 
                            : '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>';
                        showExportNotice('<?php echo esc_js(__('Export failed: ', 'intersoccer-reports-rosters')); ?>' + errorMsg, 'error');
                    }
                    $button.prop('disabled', false).val(originalButtonText);
                },
                error: function(xhr, status, error) {
                    var errorMsg = '<?php echo esc_js(__('Export failed: Connection error', 'intersoccer-reports-rosters')); ?>';
                    
                    if (status === 'timeout') {
                        errorMsg = '<?php echo esc_js(__('Export timeout. The roster may be too large. Please try again or contact support.', 'intersoccer-reports-rosters')); ?>';
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = '<?php echo esc_js(__('Export failed: ', 'intersoccer-reports-rosters')); ?>' + xhr.responseJSON.data.message;
                    } else if (xhr.responseText) {
                        try {
                            var errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMsg = '<?php echo esc_js(__('Export failed: ', 'intersoccer-reports-rosters')); ?>' + errorResponse.data.message;
                            }
                        } catch (e) {
                            // Not JSON, use generic error
                        }
                    }
                    
                    showExportNotice(errorMsg, 'error');
                    $button.prop('disabled', false).val(originalButtonText);
                    console.error('Export AJAX error:', status, error, xhr.responseText);
                }
            });
        });
    });
    </script>
    <?php
}
?>