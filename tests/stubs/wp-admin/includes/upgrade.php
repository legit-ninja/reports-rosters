<?php
/**
 * Harness stand-in for WordPress wp-admin/includes/upgrade.php.
 *
 * The test bootstrap sets ABSPATH to tests/stubs/, so the plugin's
 * require_once ABSPATH . 'wp-admin/includes/upgrade.php' (includes/db.php,
 * Core\Database::create_tables() and others) loads this file in tests. It lives
 * under tests/ so nothing under wp-admin/ ships in the plugin zip; a real
 * WordPress install uses its own ABSPATH and its own upgrade.php.
 */

if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', $execute = true) {
        if (!isset($GLOBALS['intersoccer_test_dbdelta_queries']) || !is_array($GLOBALS['intersoccer_test_dbdelta_queries'])) {
            $GLOBALS['intersoccer_test_dbdelta_queries'] = [];
        }
        $GLOBALS['intersoccer_test_dbdelta_queries'][] = $queries;

        return is_array($queries) ? $queries : [$queries];
    }
}
