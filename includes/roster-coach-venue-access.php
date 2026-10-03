<?php
/**
 * Shared coach venue access helpers for roster paths.
 *
 * Reuses InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues —
 * the same check used by listing pages and bulk export-all.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_roster_load_coach_assignments_class')) {
    /**
     * Ensure the coach assignments class from customer-referral-system is loaded.
     */
    function intersoccer_roster_load_coach_assignments_class(): void {
        if (class_exists('InterSoccer_Admin_Coach_Assignments')) {
            return;
        }
        $path = WP_PLUGIN_DIR . '/customer-referral-system/includes/class-admin-coach-assignments.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
}

if (!function_exists('intersoccer_roster_get_coach_accessible_venues_for_user')) {
    /**
     * Return venues the coach may access via the existing CRS helper.
     *
     * @param int $user_id
     * @return string[]
     */
    function intersoccer_roster_get_coach_accessible_venues_for_user(int $user_id): array {
        intersoccer_roster_load_coach_assignments_class();
        if (!class_exists('InterSoccer_Admin_Coach_Assignments')) {
            return [];
        }
        $venues = InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues($user_id);
        if (!is_array($venues)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $venues), static function ($v) {
            return $v !== '';
        }));
    }
}

if (!function_exists('intersoccer_roster_resolve_venues_from_request')) {
    /**
     * Resolve distinct roster venues for the given export/details request keys.
     *
     * @param array{
     *   venue?: string,
     *   variation_id?: int,
     *   variation_ids?: int[],
     *   order_item_ids?: int[],
     *   event_signature?: string,
     *   event_signatures?: string[]
     * } $args
     * @return string[]
     */
    function intersoccer_roster_resolve_venues_from_request(array $args): array {
        global $wpdb;

        $venues = [];
        if (!empty($args['venue']) && is_string($args['venue'])) {
            $venues[] = $args['venue'];
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $clauses = [];
        $params = [];

        $variation_ids = [];
        if (!empty($args['variation_id'])) {
            $variation_ids[] = (int) $args['variation_id'];
        }
        if (!empty($args['variation_ids']) && is_array($args['variation_ids'])) {
            foreach ($args['variation_ids'] as $vid) {
                $vid = (int) $vid;
                if ($vid > 0) {
                    $variation_ids[] = $vid;
                }
            }
        }
        $variation_ids = array_values(array_unique(array_filter($variation_ids)));
        if ($variation_ids !== []) {
            $ph = implode(',', array_fill(0, count($variation_ids), '%d'));
            $clauses[] = "variation_id IN ({$ph})";
            $params = array_merge($params, $variation_ids);
        }

        $order_item_ids = [];
        if (!empty($args['order_item_ids']) && is_array($args['order_item_ids'])) {
            foreach ($args['order_item_ids'] as $oid) {
                $oid = (int) $oid;
                if ($oid > 0) {
                    $order_item_ids[] = $oid;
                }
            }
        }
        $order_item_ids = array_values(array_unique($order_item_ids));
        if ($order_item_ids !== []) {
            $ph = implode(',', array_fill(0, count($order_item_ids), '%d'));
            $clauses[] = "order_item_id IN ({$ph})";
            $params = array_merge($params, $order_item_ids);
        }

        $signatures = [];
        if (!empty($args['event_signature']) && is_string($args['event_signature']) && $args['event_signature'] !== 'N/A') {
            $signatures[] = $args['event_signature'];
        }
        if (!empty($args['event_signatures']) && is_array($args['event_signatures'])) {
            foreach ($args['event_signatures'] as $sig) {
                $sig = trim((string) $sig);
                if ($sig !== '' && $sig !== 'N/A') {
                    $signatures[] = $sig;
                }
            }
        }
        $signatures = array_values(array_unique($signatures));
        if ($signatures !== []) {
            $ph = implode(',', array_fill(0, count($signatures), '%s'));
            $clauses[] = "event_signature IN ({$ph})";
            $params = array_merge($params, $signatures);
        }

        if ($clauses !== []) {
            $sql = "SELECT DISTINCT venue FROM {$table} WHERE (" . implode(' OR ', $clauses) . ") AND venue IS NOT NULL AND venue != ''";
            $found = $params === []
                ? $wpdb->get_col($sql)
                : $wpdb->get_col($wpdb->prepare($sql, $params));
            if (is_array($found)) {
                foreach ($found as $v) {
                    $v = (string) $v;
                    if ($v !== '') {
                        $venues[] = $v;
                    }
                }
            }
        }

        return array_values(array_unique($venues));
    }
}

if (!function_exists('intersoccer_roster_venues_allowed_for_coach')) {
    /**
     * Pure venue intersection used by export and details paths.
     * Empty accessible list or empty/unknown target venues => deny.
     *
     * @param string[] $target_venues
     * @param string[] $accessible_venues from get_coach_accessible_venues
     */
    function intersoccer_roster_venues_allowed_for_coach(array $target_venues, array $accessible_venues): bool {
        $accessible_venues = array_values(array_filter(array_map('strval', $accessible_venues), static function ($v) {
            return $v !== '';
        }));
        if ($accessible_venues === []) {
            return false;
        }

        $target_venues = array_values(array_filter(array_map('strval', $target_venues), static function ($v) {
            return $v !== '';
        }));
        if ($target_venues === []) {
            return false;
        }

        foreach ($target_venues as $venue) {
            if (!in_array($venue, $accessible_venues, true)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('intersoccer_roster_current_user_can_access_venues')) {
    /**
     * Admins keep full access. Coaches must only access venues returned by
     * InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues.
     *
     * @param string[] $venues
     */
    function intersoccer_roster_current_user_can_access_venues(array $venues): bool {
        if (current_user_can('manage_options')) {
            return true;
        }

        $current_user = wp_get_current_user();
        $is_coach = in_array('coach', (array) $current_user->roles, true) || current_user_can('coach');
        if (!$is_coach) {
            return false;
        }

        $accessible = intersoccer_roster_get_coach_accessible_venues_for_user((int) $current_user->ID);
        return intersoccer_roster_venues_allowed_for_coach($venues, $accessible);
    }
}

if (!function_exists('intersoccer_roster_current_user_coach_venue_scope')) {
    /**
     * Venue scope for the current user.
     *
     * @return string[]|null null = admin / unrestricted; string[] = coach allowed venues
     *                      (empty array means no venues — deny all rows).
     */
    function intersoccer_roster_current_user_coach_venue_scope(): ?array {
        if (current_user_can('manage_options')) {
            return null;
        }

        $current_user = wp_get_current_user();
        $is_coach = in_array('coach', (array) $current_user->roles, true) || current_user_can('coach');
        if (!$is_coach) {
            return [];
        }

        return intersoccer_roster_get_coach_accessible_venues_for_user((int) $current_user->ID);
    }
}

if (!function_exists('intersoccer_roster_filter_rows_to_accessible_venues')) {
    /**
     * Keep only rows whose venue is an exact member of $accessible_venues.
     * Empty / NULL venue is never accessible to a coach.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $accessible_venues
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_roster_filter_rows_to_accessible_venues(array $rows, array $accessible_venues): array {
        $accessible_venues = array_values(array_filter(array_map('strval', $accessible_venues), static function ($v) {
            return $v !== '';
        }));
        if ($accessible_venues === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $venue = isset($row['venue']) ? (string) $row['venue'] : '';
            if ($venue !== '' && in_array($venue, $accessible_venues, true)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}

if (!function_exists('intersoccer_roster_append_coach_venue_in_clause')) {
    /**
     * Append exact venue IN (...) for coaches (same pattern as export-all).
     *
     * @param string[] $where_clauses
     * @param array<int,mixed> $params
     * @param string[] $accessible_venues
     * @return bool false when accessible list is empty (caller should return no rows)
     */
    function intersoccer_roster_append_coach_venue_in_clause(array &$where_clauses, array &$params, array $accessible_venues): bool {
        $accessible_venues = array_values(array_filter(array_map('strval', $accessible_venues), static function ($v) {
            return $v !== '';
        }));
        if ($accessible_venues === []) {
            return false;
        }
        $ph = implode(',', array_fill(0, count($accessible_venues), '%s'));
        $where_clauses[] = "venue IN ({$ph})";
        $params = array_merge($params, $accessible_venues);

        return true;
    }
}

