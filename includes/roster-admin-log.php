<?php
/**
 * Append-only admin activity log for roster-related actions.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * @param string               $action
 * @param string               $event_signature
 * @param array<string,mixed> $payload
 */
function intersoccer_roster_admin_log_insert(string $action, string $event_signature = '', array $payload = []): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'intersoccer_roster_admin_log';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return false;
    }
    $ok = $wpdb->insert(
        $table,
        [
            'user_id' => get_current_user_id(),
            'action' => substr($action, 0, 80),
            'event_signature' => substr($event_signature, 0, 64),
            'payload' => $payload ? wp_json_encode($payload) : null,
            'created_at' => current_time('mysql'),
        ],
        ['%d', '%s', '%s', '%s', '%s']
    );

    return $ok !== false;
}

/**
 * @return array<int,array<string,mixed>>
 */
function intersoccer_roster_admin_log_fetch_for_signature(string $event_signature, int $limit = 50): array {
    global $wpdb;
    $table = $wpdb->prefix . 'intersoccer_roster_admin_log';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, user_id, action, event_signature, payload, created_at FROM {$table}
             WHERE event_signature = %s ORDER BY id DESC LIMIT " . (int) $limit,
            $event_signature
        ),
        ARRAY_A
    );

    return is_array($rows) ? $rows : [];
}
