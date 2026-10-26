<?php
/**
 * Lightweight PCS transient cache enablement (hook registration without full report).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_player_course_status_cache_enabled')) {
	/**
	 * Whether full-result caching is enabled (constant, filter, default off under INTERSOCCER_RR_TESTING).
	 */
	function intersoccer_player_course_status_cache_enabled() {
		if (defined('INTERSOCCER_RR_PCS_CACHE_DISABLED') && INTERSOCCER_RR_PCS_CACHE_DISABLED) {
			return false;
		}
		if (array_key_exists('intersoccer_test_pcs_cache_force_enabled', $GLOBALS)) {
			return (bool) $GLOBALS['intersoccer_test_pcs_cache_force_enabled'];
		}
		$filtered = apply_filters('intersoccer_player_course_status_cache_enabled', null);
		if ($filtered !== null) {
			return (bool) $filtered;
		}
		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			return false;
		}

		return true;
	}
}
