<?php
/**
 * Admin Tools AJAX Handler
 *
 * Registers admin-only AJAX endpoints that were historically implemented in
 * legacy include files (Advanced/tools UI).
 *
 * Action names are preserved for backward compatibility.
 *
 * @package InterSoccer\ReportsRosters\Ajax
 */

namespace InterSoccer\ReportsRosters\Ajax;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;

defined('ABSPATH') or die('Restricted access');

class AdminToolsAjaxHandler {
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var OrderProcessor
     */
    private $order_processor;

    public function __construct(Logger $logger = null, OrderProcessor $order_processor = null) {
        $this->logger = $logger ?: new Logger();
        if ($order_processor !== null) {
            $this->order_processor = $order_processor;
        } elseif (function_exists('intersoccer_oop_get_order_processor')) {
            $this->order_processor = intersoccer_oop_get_order_processor();
        } else {
            $this->order_processor = new OrderProcessor($this->logger);
        }
    }

    public function register(): void {
        add_action('wp_ajax_intersoccer_get_rebuild_errors', [$this, 'getRebuildErrors']);
        add_action('wp_ajax_intersoccer_clear_rebuild_data', [$this, 'clearRebuildData']);

        add_action('wp_ajax_intersoccer_process_existing_orders', [$this, 'processExistingOrders']);

        add_action('wp_ajax_intersoccer_close_out_roster', [$this, 'closeOutRoster']);
        add_action('wp_ajax_intersoccer_reopen_roster', [$this, 'reopenRoster']);
        add_action('wp_ajax_intersoccer_bulk_close_rosters', [$this, 'bulkCloseRosters']);
        add_action('wp_ajax_intersoccer_bulk_reopen_rosters', [$this, 'bulkReopenRosters']);
        add_action('wp_ajax_intersoccer_close_season_rosters', [$this, 'closeSeasonRosters']);

        // "Repair Day Presence" tool
        add_action('wp_ajax_intersoccer_repair_day_presence', [$this, 'repairDayPresence']);

        // Complex migration endpoint: keep legacy implementation for now.
        add_action('wp_ajax_intersoccer_move_players', [$this, 'delegateMovePlayersLegacy']);
    }

    public function getRebuildErrors(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_ajax_referer('intersoccer_rebuild_nonce', 'nonce');

        $errors = get_option('intersoccer_rebuild_errors', []);
        $errors = array_slice((array) $errors, -50);

        wp_send_json_success([
            'errors' => $errors,
            'count' => count($errors),
        ]);
    }

    public function clearRebuildData(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_ajax_referer('intersoccer_rebuild_nonce', 'nonce');

        delete_option('intersoccer_rebuild_progress');
        delete_option('intersoccer_rebuild_errors');
        delete_option('intersoccer_rebuild_status');

        wp_send_json_success([
            'message' => __('Rebuild data cleared successfully', 'intersoccer-reports-rosters'),
        ]);
    }

    public function processExistingOrders(): void {
        // Accept both nonce field names used by legacy UI and woo-op.js
        $nonce_valid = false;
        if (isset($_POST['intersoccer_rebuild_nonce_field'])) {
            $nonce_valid = wp_verify_nonce(sanitize_text_field($_POST['intersoccer_rebuild_nonce_field']), 'intersoccer_rebuild_nonce');
        } elseif (isset($_POST['nonce'])) {
            $nonce_valid = wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'intersoccer_rebuild_nonce');
        }

        if (!$nonce_valid) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'intersoccer-reports-rosters')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to process orders.', 'intersoccer-reports-rosters')]);
        }

        if (!function_exists('wc_get_orders')) {
            wp_send_json_error(['message' => __('WooCommerce is not available.', 'intersoccer-reports-rosters')]);
        }

        try {
            $order_ids = wc_get_orders([
                'limit' => -1,
                'status' => ['processing', 'on-hold', 'completed'],
                'return' => 'ids',
            ]);

            $summary = $this->order_processor->process_batch(array_map('intval', (array) $order_ids));

            if (!empty($summary['success'])) {
                wp_send_json_success([
                    'status' => 'success',
                    'processed' => $summary['processed_orders'] ?? 0,
                    'completed' => $summary['completed_orders'] ?? 0,
                    'failed' => count($summary['failed_orders'] ?? []),
                    'roster_entries' => $summary['roster_entries'] ?? 0,
                    'message' => $summary['message'] ?? __('Orders processed.', 'intersoccer-reports-rosters'),
                ]);
            }

            wp_send_json_error([
                'message' => $summary['message'] ?? __('Order processing failed. Check logs for details.', 'intersoccer-reports-rosters'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('AdminToolsAjaxHandler: processExistingOrders failed', [
                'error' => $e->getMessage(),
            ]);
            wp_send_json_error([
                'message' => __('Processing failed: ', 'intersoccer-reports-rosters') . $e->getMessage(),
            ]);
        }
    }

    public function closeOutRoster(): void {
        $this->setRosterCompletedFlag(1);
    }

    public function reopenRoster(): void {
        $this->setRosterCompletedFlag(0);
    }

    private function setRosterCompletedFlag(int $flag): void {
        check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
        }

        $event_signature = isset($_POST['event_signature']) ? sanitize_text_field($_POST['event_signature']) : '';
        if ($event_signature === '') {
            wp_send_json_error(['message' => __('Event signature is required.', 'intersoccer-reports-rosters')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';

        $updated = $wpdb->update(
            $table,
            ['event_completed' => $flag],
            ['event_signature' => $event_signature],
            ['%d'],
            ['%s']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('Operation failed.', 'intersoccer-reports-rosters')]);
        }

        wp_cache_flush();
        delete_transient('intersoccer_rosters_cache');

        $message = $flag === 1
            ? sprintf(__('Roster closed successfully. %d entries updated.', 'intersoccer-reports-rosters'), $updated)
            : sprintf(__('Roster reopened successfully. %d entries updated.', 'intersoccer-reports-rosters'), $updated);

        wp_send_json_success([
            'message' => $message,
            'updated' => $updated,
        ]);
    }

    public function bulkCloseRosters(): void {
        $this->bulkSetRosterCompletedFlag(1);
    }

    public function bulkReopenRosters(): void {
        $this->bulkSetRosterCompletedFlag(0);
    }

    private function bulkSetRosterCompletedFlag(int $flag): void {
        check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
        }

        $event_signatures = isset($_POST['event_signatures']) ? (array) $_POST['event_signatures'] : [];
        $event_signatures = array_values(array_filter(array_map('sanitize_text_field', $event_signatures)));

        if (empty($event_signatures)) {
            wp_send_json_error(['message' => __('No rosters selected.', 'intersoccer-reports-rosters')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $placeholders = implode(',', array_fill(0, count($event_signatures), '%s'));

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET event_completed = %d WHERE event_signature IN ($placeholders)",
            array_merge([$flag], $event_signatures)
        ));

        if ($updated === false) {
            wp_send_json_error(['message' => __('Operation failed.', 'intersoccer-reports-rosters')]);
        }

        wp_cache_flush();
        delete_transient('intersoccer_rosters_cache');

        $message = $flag === 1
            ? sprintf(__('%d roster(s) closed successfully. %d entries updated.', 'intersoccer-reports-rosters'), count($event_signatures), $updated)
            : sprintf(__('%d roster(s) reopened successfully. %d entries updated.', 'intersoccer-reports-rosters'), count($event_signatures), $updated);

        wp_send_json_success([
            'message' => $message,
            'updated' => $updated,
            'count' => count($event_signatures),
        ]);
    }

    public function closeSeasonRosters(): void {
        check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
        }

        $season = isset($_POST['season']) ? sanitize_text_field($_POST['season']) : '';
        if ($season === '') {
            wp_send_json_error(['message' => __('Season is required.', 'intersoccer-reports-rosters')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';

        // Match case-insensitively; close ALL rosters in the matching season.
        $season_escaped = $wpdb->esc_like($season);
        $matching_seasons = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT season FROM {$table}
             WHERE (event_completed = 0 OR event_completed IS NULL)
             AND (LOWER(TRIM(season)) = LOWER(TRIM(%s)) OR LOWER(season) LIKE LOWER(%s))",
            $season,
            '%' . $season_escaped . '%'
        ));

        if (empty($matching_seasons)) {
            wp_send_json_error(['message' => sprintf(__('No active rosters found for season "%s".', 'intersoccer-reports-rosters'), esc_html($season))]);
        }

        $season_placeholders = implode(',', array_fill(0, count($matching_seasons), '%s'));
        $where_conditions = ["season IN ($season_placeholders)", "(event_completed = 0 OR event_completed IS NULL)"];

        $count_query = "SELECT COUNT(DISTINCT event_signature) FROM {$table} WHERE " . implode(' AND ', $where_conditions);
        $roster_count = (int) $wpdb->get_var($wpdb->prepare($count_query, $matching_seasons));

        if ($roster_count <= 0) {
            wp_send_json_error(['message' => __('No active rosters found for this season.', 'intersoccer-reports-rosters')]);
        }

        $update_query = "UPDATE {$table} SET event_completed = 1 WHERE " . implode(' AND ', $where_conditions);
        $updated = $wpdb->query($wpdb->prepare($update_query, $matching_seasons));

        if ($updated === false) {
            wp_send_json_error(['message' => __('Failed to close rosters in season.', 'intersoccer-reports-rosters')]);
        }

        wp_cache_flush();
        delete_transient('intersoccer_rosters_cache');

        wp_send_json_success([
            'message' => sprintf(__('Successfully closed %d roster(s) in season "%s". %d entries updated.', 'intersoccer-reports-rosters'), $roster_count, esc_html($season), $updated),
            'updated' => $updated,
            'roster_count' => $roster_count,
            'season' => $season,
        ]);
    }

    public function repairDayPresence(): void {
        // Same nonce used across roster details actions
        $nonce_ok = false;
        if (isset($_POST['nonce'])) {
            $nonce_ok = wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'intersoccer_reports_rosters_nonce');
        }
        if (!$nonce_ok) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh and try again.', 'intersoccer-reports-rosters')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
        }

        $event_signature = isset($_POST['event_signature']) ? sanitize_text_field($_POST['event_signature']) : '';
        if ($event_signature === '') {
            wp_send_json_error(['message' => __('Event signature is required.', 'intersoccer-reports-rosters')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, booking_type, selected_days FROM {$table} WHERE event_signature = %s",
            $event_signature
        ), ARRAY_A);

        if (!is_array($rows) || empty($rows)) {
            wp_send_json_error(['message' => __('No roster entries found for this event signature.', 'intersoccer-reports-rosters')]);
        }

        $updated = 0;
        foreach ($rows as $row) {
            $presence = $this->computeDayPresence((string) ($row['booking_type'] ?? ''), (string) ($row['selected_days'] ?? ''));
            $ok = $wpdb->update(
                $table,
                ['day_presence' => wp_json_encode($presence)],
                ['id' => (int) $row['id']],
                ['%s'],
                ['%d']
            );
            if ($ok !== false) {
                $updated++;
            }
        }

        wp_send_json_success([
            'message' => sprintf(__('Repaired day presence for %d roster entries.', 'intersoccer-reports-rosters'), $updated),
            'updated' => $updated,
        ]);
    }

    private function computeDayPresence(string $booking_type, string $selected_days): array {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

        $presence = array_fill_keys($days, 'No');
        $booking_type = strtolower(trim($booking_type));

        if ($booking_type === 'full-week') {
            foreach ($days as $day) {
                $presence[$day] = 'Yes';
            }
            return $presence;
        }

        // single-days: normalize tokens and mark those present
        $tokens = array_filter(array_map('trim', explode(',', (string) $selected_days)));
        foreach ($tokens as $token) {
            $normalized = $this->normalizeWeekdayToken($token);
            if ($normalized && isset($presence[$normalized])) {
                $presence[$normalized] = 'Yes';
            }
        }

        return $presence;
    }

    private function normalizeWeekdayToken(string $token): ?string {
        $t = strtolower(trim($token));
        if ($t === '') {
            return null;
        }

        $map = [
            // English
            'mon' => 'Monday',
            'monday' => 'Monday',
            'tue' => 'Tuesday',
            'tues' => 'Tuesday',
            'tuesday' => 'Tuesday',
            'wed' => 'Wednesday',
            'weds' => 'Wednesday',
            'wednesday' => 'Wednesday',
            'thu' => 'Thursday',
            'thur' => 'Thursday',
            'thurs' => 'Thursday',
            'thursday' => 'Thursday',
            'fri' => 'Friday',
            'friday' => 'Friday',
            // French
            'lundi' => 'Monday',
            'mardi' => 'Tuesday',
            'mercredi' => 'Wednesday',
            'jeudi' => 'Thursday',
            'vendredi' => 'Friday',
            // German
            'montag' => 'Monday',
            'dienstag' => 'Tuesday',
            'mittwoch' => 'Wednesday',
            'donnerstag' => 'Thursday',
            'freitag' => 'Friday',
        ];

        $t = rtrim($t, '.');

        return $map[$t] ?? null;
    }

    public function delegateMovePlayersLegacy(): void {
        // Preserve existing behavior by delegating to the legacy implementation.
        $plugin_root = dirname(__DIR__, 2) . '/';

        $path = rtrim($plugin_root, '/') . '/includes/advanced.php';
        if (!file_exists($path)) {
            wp_send_json_error(['message' => __('Legacy move-players handler not found.', 'intersoccer-reports-rosters')]);
        }

        require_once $path;

        if (!function_exists('intersoccer_move_players_ajax')) {
            wp_send_json_error(['message' => __('Legacy move-players handler is unavailable.', 'intersoccer-reports-rosters')]);
        }

        intersoccer_move_players_ajax();
    }
}

