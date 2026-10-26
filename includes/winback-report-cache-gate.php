<?php
/**
 * Lightweight win-back transient cache enablement (warm-up scheduling without full report).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_winback_report_cache_enabled')) {
	function intersoccer_winback_report_cache_enabled() {
		if (defined('INTERSOCCER_RR_WINBACK_CACHE_DISABLED') && INTERSOCCER_RR_WINBACK_CACHE_DISABLED) {
			return false;
		}
		if (array_key_exists('intersoccer_test_winback_cache_force_enabled', $GLOBALS)) {
			return (bool) $GLOBALS['intersoccer_test_winback_cache_force_enabled'];
		}
		$filtered = apply_filters('intersoccer_winback_report_cache_enabled', null);
		if ($filtered !== null) {
			return (bool) $filtered;
		}
		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			return false;
		}

		return true;
	}
}
