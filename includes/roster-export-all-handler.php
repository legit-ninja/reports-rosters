<?php
/**
 * Bulk export for roster listing pages (filtered) — streams CSV for reliability on large datasets.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Print hidden inputs so bulk export uses the same filters as the current roster list view.
 *
 * @param array<string,scalar> $filter
 */
function intersoccer_export_safe_placeholder_sql(): string {
    global $wpdb;
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $table = $wpdb->prefix . 'intersoccer_rosters';
    $cols = $wpdb->get_col("DESCRIBE {$table}", 0);
    $cached = (is_array($cols) && in_array('is_placeholder', $cols, true))
        ? ' AND (is_placeholder = 0 OR is_placeholder IS NULL)'
        : '';

    return $cached;
}

/**
 * @param array<string,string> $filter
 * @param array<string,mixed>  $context
 * @return string[]
 */
function intersoccer_export_other_events_signatures_sql(array $filter, array $context): array {
    global $wpdb;
    $table = $wpdb->prefix . 'intersoccer_rosters';
    $sql = "SELECT DISTINCT event_signature FROM {$table}
            WHERE event_signature != ''
            AND activity_type NOT IN ('Camp','Course')
            AND activity_type NOT LIKE %s
            AND girls_only = 0";
    $params = ['%Girls%'];
    $sql .= intersoccer_export_safe_placeholder_sql();
    if (!empty($filter['season'])) {
        $sql .= ' AND season = %s';
        $params[] = $filter['season'];
    }
    if (!empty($filter['venue'])) {
        $sql .= ' AND venue = %s';
        $params[] = $filter['venue'];
    }
    if (!empty($filter['product_name'])) {
        $sql .= ' AND product_name = %s';
        $params[] = $filter['product_name'];
    }
    if (!empty($context['is_coach']) && !empty($context['accessible_venues'])) {
        $ph = implode(',', array_fill(0, count($context['accessible_venues']), '%s'));
        $sql .= " AND venue IN ({$ph})";
        $params = array_merge($params, $context['accessible_venues']);
    } elseif (!empty($context['is_coach']) && empty($context['accessible_venues'])) {
        return [];
    }
    $prepared = $wpdb->prepare($sql, $params);
    $col = $wpdb->get_col($prepared);

    return is_array($col) ? array_values(array_filter(array_map('strval', $col))) : [];
}

/**
 * @param array<string,string> $filter
 * @param array<string,mixed>  $context
 * @return string[]
 */
function intersoccer_export_all_rosters_signatures_sql(array $filter, array $context): array {
    global $wpdb;
    $table = $wpdb->prefix . 'intersoccer_rosters';
    $sql = "SELECT DISTINCT event_signature FROM {$table} WHERE event_signature != ''";
    $params = [];
    $sql .= intersoccer_export_safe_placeholder_sql();
    if (!empty($filter['product_name'])) {
        $sql .= ' AND product_name = %s';
        $params[] = $filter['product_name'];
    }
    if (!empty($context['is_coach']) && !empty($context['accessible_venues'])) {
        $ph = implode(',', array_fill(0, count($context['accessible_venues']), '%s'));
        $sql .= " AND venue IN ({$ph})";
        $params = array_merge($params, $context['accessible_venues']);
    } elseif (!empty($context['is_coach']) && empty($context['accessible_venues'])) {
        return [];
    }
    $col = $params === []
        ? $wpdb->get_col($sql)
        : $wpdb->get_col($wpdb->prepare($sql, $params));

    return is_array($col) ? array_values(array_filter(array_map('strval', $col))) : [];
}

/**
 * @param string[] $signatures
 */
function intersoccer_export_stream_csv_from_signatures(array $signatures, string $slug): void {
    if ($signatures === []) {
        wp_die(esc_html__('No roster data found for export.', 'intersoccer-reports-rosters'));
    }
    if (!function_exists('intersoccer_oop_get_roster_export_service')) {
        wp_die(esc_html__('Export services are not available.', 'intersoccer-reports-rosters'));
    }
    $export_service = intersoccer_oop_get_roster_export_service();
    $activity_types = ['Camp', 'Course', 'Tournament', 'Girls Only', 'Camp, Girls Only', "Camp, Girls' only", 'Course, Girls Only', "Course, Girls' only", 'Tournament, Girls Only', "Tournament, Girls' only", 'Birthday', 'Other'];
    $chunk_size = 800;
    $chunks = array_chunk($signatures, $chunk_size);
    $all_rows = [];
    foreach ($chunks as $chunk) {
        $part = $export_service->getExportRows([
            'use_fields' => true,
            'event_signatures' => $chunk,
            'activity_types' => $activity_types,
        ]);
        if (is_array($part) && $part !== []) {
            $all_rows = array_merge($all_rows, $part);
        }
    }
    if ($all_rows === []) {
        wp_die(esc_html__('No roster rows found for export.', 'intersoccer-reports-rosters'));
    }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=intersoccer-rosters-' . sanitize_file_name($slug) . '-' . gmdate('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    $headers = array_keys($all_rows[0]);
    fputcsv($out, $headers);
    foreach ($all_rows as $r) {
        $line = [];
        foreach ($headers as $h) {
            $line[] = isset($r[$h]) ? (is_scalar($r[$h]) ? (string) $r[$h] : wp_json_encode($r[$h])) : '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function intersoccer_rosters_print_export_filter_hidden_fields(array $filter): void {
    foreach ($filter as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        printf(
            '<input type="hidden" name="filter[%1$s]" value="%2$s" />',
            esc_attr((string) $key),
            esc_attr((string) $value)
        );
    }
}

/**
 * @param array<int,array<string,mixed>> $groups
 * @return string[]
 */
function intersoccer_roster_export_collect_signatures_from_groups(array $groups): array {
    $sigs = [];
    foreach ($groups as $g) {
        if (!empty($g['merged_event_signatures']) && is_array($g['merged_event_signatures'])) {
            foreach ($g['merged_event_signatures'] as $sig) {
                $sig = is_string($sig) ? trim($sig) : '';
                if ($sig !== '') {
                    $sigs[$sig] = true;
                }
            }
        } elseif (!empty($g['event_signature']) && is_string($g['event_signature'])) {
            $sig = trim($g['event_signature']);
            if ($sig !== '') {
                $sigs[$sig] = true;
            }
        }
    }

    return array_keys($sigs);
}

/**
 * @param array<string,string> $filter
 */
function intersoccer_roster_export_listing_context_from_globals(): array {
    $current_user = wp_get_current_user();
    $is_coach = in_array('coach', (array) $current_user->roles, true);
    $venues = [];
    if ($is_coach) {
        if (!class_exists('InterSoccer_Admin_Coach_Assignments')) {
            $path = WP_PLUGIN_DIR . '/customer-referral-system/includes/class-admin-coach-assignments.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (class_exists('InterSoccer_Admin_Coach_Assignments')) {
            $venues = InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues($current_user->ID);
        }
    }

    return [
        'is_coach' => $is_coach,
        'accessible_venues' => $venues,
    ];
}

/**
 * @param array<string,mixed> $raw
 * @return array<string,string>
 */
function intersoccer_roster_export_parse_filter_post(array $raw): array {
    $out = [];
    $keys = ['season', 'venue', 'camp_terms', 'course_day', 'age_group', 'city', 'status', 'type', 'consolidated', 'product_name', 'times'];
    foreach ($keys as $k) {
        if (isset($raw[$k]) && $raw[$k] !== '') {
            $out[$k] = sanitize_text_field((string) $raw[$k]);
        }
    }

    return $out;
}

function intersoccer_export_all_rosters_handler(): void {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'intersoccer_reports_rosters_nonce')) {
        wp_die(esc_html__('Security check failed.', 'intersoccer-reports-rosters'));
    }
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(esc_html__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    $export_type = isset($_POST['export_type']) ? sanitize_key(wp_unslash($_POST['export_type'])) : '';
    $filter = isset($_POST['filter']) && is_array($_POST['filter'])
        ? intersoccer_roster_export_parse_filter_post(wp_unslash($_POST['filter']))
        : [];

    $consolidated = !isset($filter['consolidated']) || $filter['consolidated'] !== '0';

    if (!function_exists('intersoccer_oop_get_roster_listing_service') || !function_exists('intersoccer_oop_get_roster_export_service')) {
        wp_die(esc_html__('Export services are not available.', 'intersoccer-reports-rosters'));
    }

    $service = intersoccer_oop_get_roster_listing_service();
    $context = intersoccer_roster_export_listing_context_from_globals();

    $camp_filters = [
        'season' => $filter['season'] ?? '',
        'venue' => $filter['venue'] ?? '',
        'camp_terms' => $filter['camp_terms'] ?? '',
        'age_group' => $filter['age_group'] ?? '',
        'city' => $filter['city'] ?? '',
        'status' => $filter['status'] ?? '',
    ];
    $course_filters = [
        'season' => $filter['season'] ?? '',
        'venue' => $filter['venue'] ?? '',
        'course_day' => $filter['course_day'] ?? '',
        'age_group' => $filter['age_group'] ?? '',
        'city' => $filter['city'] ?? '',
        'status' => $filter['status'] ?? '',
    ];

    $groups = [];
    switch ($export_type) {
        case 'camps':
            $res = $service->getCampListings($camp_filters, $context, false, $consolidated);
            $groups = $res['display_groups'] ?? [];
            break;
        case 'courses':
            $res = $service->getCourseListings($course_filters, $context, false, $consolidated);
            $groups = $res['display_groups'] ?? [];
            break;
        case 'girls_only':
            $gf = [
                'season' => $filter['season'] ?? '',
                'venue' => $filter['venue'] ?? '',
                'camp_terms' => $filter['camp_terms'] ?? '',
                'course_day' => $filter['course_day'] ?? '',
                'age_group' => $filter['age_group'] ?? '',
                'city' => $filter['city'] ?? '',
                'type' => $filter['type'] ?? '',
                'status' => $filter['status'] ?? '',
            ];
            $res = $service->getGirlsOnlyListings($gf, $context);
            $groups = array_merge($res['display_groups'] ?? [], []);
            break;
        case 'tournaments':
            $tf = [
                'season' => $filter['season'] ?? '',
                'venue' => $filter['venue'] ?? '',
                'age_group' => $filter['age_group'] ?? '',
                'city' => $filter['city'] ?? '',
                'times' => $filter['times'] ?? '',
            ];
            $res = $service->getTournamentListings($tf, $context);
            $groups = $res['display_groups'] ?? [];
            break;
        case 'other':
            $signatures = intersoccer_export_other_events_signatures_sql($filter, $context);
            intersoccer_export_stream_csv_from_signatures($signatures, 'other');

            return;
        case 'all':
            $signatures = intersoccer_export_all_rosters_signatures_sql($filter, $context);
            intersoccer_export_stream_csv_from_signatures($signatures, 'all');

            return;
        default:
            wp_die(esc_html__('Unsupported export type.', 'intersoccer-reports-rosters'));
    }

    $signatures = intersoccer_roster_export_collect_signatures_from_groups($groups);
    if ($signatures === []) {
        wp_die(esc_html__('No roster groups match the current filters for export.', 'intersoccer-reports-rosters'));
    }

    intersoccer_export_stream_csv_from_signatures($signatures, $export_type);
}

add_action('wp_ajax_intersoccer_export_all_rosters', 'intersoccer_export_all_rosters_handler');
