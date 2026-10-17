<?php
/**
 * Read-only dry-run helpers (backfill script + PHPUnit).
 */

if (!function_exists('intersoccer_reports_sql_roster_date_is_bad')) {
    /**
     * SQL fragment: true when a roster DATE column is NULL, legacy 1970, or invalid zero date.
     * Never compares DATE to '' (strict SQL mode).
     *
     * @param string $column SQL column reference e.g. "start_date" or "r.end_date"
     */
    function intersoccer_reports_sql_roster_date_is_bad($column) {
        $col = trim((string) $column);
        return "({$col} IS NULL OR {$col} = '1970-01-01' OR {$col} < '1000-01-01')";
    }
}

if (!function_exists('intersoccer_reports_sql_roster_date_is_real')) {
    function intersoccer_reports_sql_roster_date_is_real($column) {
        return '(' . intersoccer_reports_sql_roster_date_is_bad($column) . ') = 0';
    }
}

if (!function_exists('intersoccer_reports_dryrun_note_sql_error')) {
    function intersoccer_reports_dryrun_note_sql_error($context = '') {
        if (!intersoccer_reports_is_readonly_mode()) {
            return;
        }
        global $wpdb;
        $err = isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';
        if ($err === '') {
            return;
        }
        if (!isset($GLOBALS['intersoccer_dryrun_sql_errors'])) {
            $GLOBALS['intersoccer_dryrun_sql_errors'] = [];
        }
        $GLOBALS['intersoccer_dryrun_sql_errors'][] = [
            'context' => (string) $context,
            'message' => $err,
        ];
    }
}

if (!function_exists('intersoccer_reports_dryrun_get_sql_error_count')) {
    function intersoccer_reports_dryrun_get_sql_error_count() {
        return isset($GLOBALS['intersoccer_dryrun_sql_errors'])
            ? count($GLOBALS['intersoccer_dryrun_sql_errors'])
            : 0;
    }
}

if (!function_exists('intersoccer_reports_dryrun_mask_sql')) {
    function intersoccer_reports_dryrun_mask_sql($query) {
        $q = preg_replace("/'[^']*'/", "'?'", (string) $query);
        $q = preg_replace('/\b\d+\b/', '?', $q);
        if (strlen($q) > 200) {
            $q = substr($q, 0, 197) . '...';
        }
        return $q;
    }
}

if (!function_exists('intersoccer_reports_dryrun_classify_blocked_query')) {
    function intersoccer_reports_dryrun_classify_blocked_query($query) {
        $trimmed = ltrim((string) $query);
        $type = 'OTHER';
        if (preg_match('/^(\w+)/', $trimmed, $m)) {
            $type = strtoupper($m[1]);
        }
        $table = '';
        if (preg_match('/\b(?:FROM|INTO|UPDATE|JOIN)\s+`?([a-z0-9_]+)`?/i', $trimmed, $m)) {
            $table = strtolower($m[1]);
        } elseif (preg_match('/^DELETE\s+FROM\s+`?([a-z0-9_]+)`?/i', $trimmed, $m)) {
            $type = 'DELETE';
            $table = strtolower($m[1]);
        }
        return [$type, $table];
    }
}

if (!function_exists('intersoccer_reports_note_blocked_query')) {
    function intersoccer_reports_note_blocked_query($query) {
        if (!intersoccer_reports_is_readonly_mode()) {
            return;
        }
        list($type, $table) = intersoccer_reports_dryrun_classify_blocked_query($query);
        if (!isset($GLOBALS['intersoccer_dryrun_blocked_queries'])) {
            $GLOBALS['intersoccer_dryrun_blocked_queries'] = [
                'by_type' => [],
                'by_table' => [],
                'samples' => [],
            ];
        }
        $bucket = &$GLOBALS['intersoccer_dryrun_blocked_queries'];
        $bucket['by_type'][$type] = (int) ($bucket['by_type'][$type] ?? 0) + 1;
        if ($table !== '') {
            $bucket['by_table'][$table] = (int) ($bucket['by_table'][$table] ?? 0) + 1;
        }
        if (count($bucket['samples']) < 5) {
            $bucket['samples'][] = intersoccer_reports_dryrun_mask_sql($query);
        }
        intersoccer_reports_note_readonly_write_attempt();
    }
}

if (!function_exists('intersoccer_reports_dryrun_query_is_allowed')) {
    function intersoccer_reports_dryrun_query_is_allowed($query) {
        $trimmed = ltrim((string) $query);
        if (preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/is', $trimmed)) {
            return true;
        }
        if (preg_match('/^SET\s+SESSION\b/is', $trimmed)) {
            return true;
        }
        if (preg_match('/^(START\s+TRANSACTION|BEGIN)\b/is', $trimmed)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('intersoccer_reports_register_dryrun_query_guard')) {
    function intersoccer_reports_register_dryrun_query_guard() {
        static $registered = false;
        if ($registered || !function_exists('add_filter')) {
            return;
        }
        $registered = true;
        add_filter('query', static function ($query) {
            if (!intersoccer_reports_is_readonly_mode()) {
                return $query;
            }
            if (intersoccer_reports_dryrun_query_is_allowed($query)) {
                return $query;
            }
            intersoccer_reports_note_blocked_query($query);
            return 'SELECT 1';
        }, 999);
    }
}

if (!function_exists('intersoccer_reports_dryrun_bootstrap_readonly')) {
    /**
     * Object-cache transients, query guard, and WP cron off for dry-run.
     */
    function intersoccer_reports_dryrun_bootstrap_readonly() {
        if (!defined('DISABLE_WP_CRON')) {
            define('DISABLE_WP_CRON', true);
        }
        if (function_exists('wp_using_ext_object_cache')) {
            wp_using_ext_object_cache(true);
        }
        if (function_exists('intersoccer_reports_set_readonly_mode')) {
            intersoccer_reports_set_readonly_mode(true);
        }
        if (function_exists('intersoccer_reports_register_readonly_write_guards')) {
            intersoccer_reports_register_readonly_write_guards();
        }
        intersoccer_reports_register_dryrun_query_guard();
        $GLOBALS['intersoccer_dryrun_sql_errors'] = [];
        $GLOBALS['intersoccer_dryrun_blocked_queries'] = [
            'by_type' => [],
            'by_table' => [],
            'samples' => [],
        ];
    }
}

if (!function_exists('intersoccer_reports_dryrun_signature_component_diff')) {
    /**
     * @return list<string> Normalized component keys that differ between two generate inputs.
     */
    function intersoccer_reports_dryrun_signature_component_diff(array $a, array $b) {
        if (!function_exists('intersoccer_normalize_event_data_for_signature')
            || !function_exists('intersoccer_generate_event_signature')) {
            return [];
        }
        $norm_a = intersoccer_normalize_event_data_for_signature($a);
        $norm_b = intersoccer_normalize_event_data_for_signature($b);
        if (array_key_exists('start_date', $a)) {
            $norm_a['start_date'] = $a['start_date'];
        }
        if (array_key_exists('start_date', $b)) {
            $norm_b['start_date'] = $b['start_date'];
        }
        $sig_a = intersoccer_generate_event_signature($norm_a);
        $sig_b = intersoccer_generate_event_signature($norm_b);
        if ($sig_a === $sig_b) {
            return [];
        }
        $keys = array_unique(array_merge(array_keys($norm_a), array_keys($norm_b)));
        $diff = [];
        foreach ($keys as $key) {
            $va = $norm_a[$key] ?? null;
            $vb = $norm_b[$key] ?? null;
            if ((string) $va !== (string) $vb) {
                $diff[] = (string) $key;
            }
        }
        return $diff;
    }
}

if (!function_exists('intersoccer_reports_dryrun_evaluate_result')) {
    /**
     * @return array{valid:bool,reasons:list<string>}
     */
    function intersoccer_reports_dryrun_evaluate_result() {
        $reasons = [];
        $writes = function_exists('intersoccer_reports_get_readonly_write_attempts')
            ? intersoccer_reports_get_readonly_write_attempts()
            : 0;
        if ($writes > 0) {
            $reasons[] = "blocked_write_attempts={$writes}";
        }
        $sql_errors = intersoccer_reports_dryrun_get_sql_error_count();
        if ($sql_errors > 0) {
            $reasons[] = "sql_errors={$sql_errors}";
        }
        if (isset($GLOBALS['intersoccer_dryrun_signature_branch_vs_master_drift'])
            && (int) $GLOBALS['intersoccer_dryrun_signature_branch_vs_master_drift'] > 0) {
            $reasons[] = 'signature_branch_vs_master_drift>0';
        }
        return [
            'valid' => $reasons === [],
            'reasons' => $reasons,
        ];
    }
}
