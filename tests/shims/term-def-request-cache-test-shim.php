<?php
/**
 * PHPUnit-only term-def request cache (loads before includes/utils.php).
 *
 * @package InterSoccer_Reports_Rosters
 */

if (!function_exists('intersoccer_term_def_request_cache_ref')) {
    /**
     * @return array<string, mixed>
     */
    function &intersoccer_term_def_request_cache_ref() {
        static $cache = [];
        if (isset($GLOBALS['intersoccer_test_term_def_request_cache'])
            && is_array($GLOBALS['intersoccer_test_term_def_request_cache'])) {
            return $GLOBALS['intersoccer_test_term_def_request_cache'];
        }

        return $cache;
    }
}

if (!function_exists('intersoccer_reset_term_def_request_cache')) {
    function intersoccer_reset_term_def_request_cache() {
        $GLOBALS['intersoccer_test_term_def_request_cache'] = [];
        $cache = &intersoccer_term_def_request_cache_ref();
        $cache = [];
    }
}
