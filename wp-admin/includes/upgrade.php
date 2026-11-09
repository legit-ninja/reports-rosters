<?php
/**
 * Harness stand-in for WordPress wp-admin/includes/upgrade.php.
 *
 * Unit tests set ABSPATH to this plugin root, and includes/db.php plus
 * Core\Database::create_tables() require this path. A real WordPress install
 * uses its own ABSPATH, so this file is not the copy WordPress loads.
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
