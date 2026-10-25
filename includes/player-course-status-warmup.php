<?php
/**
 * Debounced background warm-up for player course status year-base cache.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Load PCS report modules for cron/event rebuild (not loaded on every request).
 */
function intersoccer_rr_pcs_warmup_load_report_modules() {
	static $loaded = false;
	if ($loaded) {
		return;
	}
	$dir = dirname(__FILE__);
	foreach (
		[
			'reports-data.php',
			'final-reports-aggregation.php',
			'utils.php',
			'roster-report-loader.php',
			'winback-report-facets.php',
			'player-camp-status.php',
			'player-course-status.php',
		] as $file
	) {
		$path = $dir . '/' . $file;
		if (file_exists($path)) {
			require_once $path;
		}
	}
	$loaded = true;
}

/**
 * Cron hook: daily safety net rebuild for the current calendar year base.
 */
function intersoccer_rr_pcs_warmup_daily_cron_hook() {
	return 'intersoccer_rr_pcs_warmup_daily';
}

/**
 * Single-event hook: debounced rebuild for one calendar year.
 */
function intersoccer_rr_pcs_warmup_year_event_hook() {
	return 'intersoccer_rr_pcs_warmup_year_event';
}

/**
 * @return int
 */
function intersoccer_rr_pcs_warmup_debounce_seconds() {
	$seconds = apply_filters('intersoccer_player_course_status_warmup_debounce_seconds', 120);

	return max(30, (int) $seconds);
}

/**
 * Register order hooks (schedule only; never build inline).
 */
function intersoccer_rr_pcs_warmup_register_order_hooks() {
	add_action('woocommerce_order_status_changed', 'intersoccer_rr_pcs_warmup_on_order_touch', 20, 4);
	add_action('woocommerce_update_order', 'intersoccer_rr_pcs_warmup_on_order_touch', 20, 2);
}

/**
 * @param mixed $order_id
 */
function intersoccer_rr_pcs_warmup_on_order_touch($order_id = 0) {
	$schedule = apply_filters('intersoccer_rr_pcs_warmup_should_schedule_on_order_touch', true, $order_id);
	if (!$schedule) {
		return;
	}
	intersoccer_rr_pcs_warmup_schedule_current_year();
	if (function_exists('intersoccer_rr_winback_warmup_schedule_default')) {
		intersoccer_rr_winback_warmup_schedule_default();
	}
}

/**
 * Schedule one debounced rebuild for the current year (deduped).
 */
function intersoccer_rr_pcs_warmup_schedule_current_year() {
	if (!function_exists('intersoccer_player_course_status_cache_enabled')
		|| !intersoccer_player_course_status_cache_enabled()) {
		return;
	}
	$year = (int) (function_exists('wp_date') ? wp_date('Y') : gmdate('Y'));
	intersoccer_rr_pcs_warmup_schedule_year($year);
}

/**
 * @param int $year
 */
function intersoccer_rr_pcs_warmup_schedule_year($year) {
	$year = (int) $year;
	if ($year < 2000) {
		return;
	}
	$hook = intersoccer_rr_pcs_warmup_year_event_hook();
	$args = [ $year ];
	if (function_exists('wp_next_scheduled') && wp_next_scheduled($hook, $args)) {
		return;
	}
	if (!function_exists('wp_schedule_single_event')) {
		return;
	}
	$when = time() + intersoccer_rr_pcs_warmup_debounce_seconds();
	wp_schedule_single_event($when, $hook, $args);
}

/**
 * Cron callback: rebuild current year base into transient cache.
 */
function intersoccer_rr_pcs_warmup_run_daily() {
	intersoccer_rr_pcs_warmup_load_report_modules();
	$year = (int) (function_exists('wp_date') ? wp_date('Y') : gmdate('Y'));
	intersoccer_rr_pcs_warmup_rebuild_year($year);
}

/**
 * Event callback: rebuild one year base into transient cache.
 *
 * @param int $year
 */
function intersoccer_rr_pcs_warmup_rebuild_year($year) {
	intersoccer_rr_pcs_warmup_load_report_modules();
	$year = (int) $year;
	if ($year < 2000) {
		return;
	}
	if (!function_exists('intersoccer_player_course_status_build_year_base_dataset')
		|| !function_exists('intersoccer_player_course_status_cache_write_base')) {
		return;
	}
	if (!intersoccer_player_course_status_cache_enabled()) {
		return;
	}
	$memo_started = false;
	if (function_exists('intersoccer_rr_product_type_memo_begin')) {
		intersoccer_rr_product_type_memo_begin();
		$memo_started = true;
	}
	try {
		$base = intersoccer_player_course_status_build_year_base_dataset($year, false);
		if (is_array($base)) {
			intersoccer_player_course_status_cache_write_base($year, $base);
		}
	} finally {
		if ($memo_started && function_exists('intersoccer_rr_product_type_memo_end')) {
			intersoccer_rr_product_type_memo_end();
		}
	}
}

/**
 * Ensure daily cron exists; register runtime hooks.
 */
function intersoccer_rr_pcs_warmup_bootstrap() {
	if (!function_exists('intersoccer_player_course_status_cache_enabled')) {
		return;
	}
	$daily = intersoccer_rr_pcs_warmup_daily_cron_hook();
	if (function_exists('wp_next_scheduled') && !wp_next_scheduled($daily)) {
		if (function_exists('wp_schedule_event')) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $daily);
		}
	}
	add_action($daily, 'intersoccer_rr_pcs_warmup_run_daily');
	add_action(intersoccer_rr_pcs_warmup_year_event_hook(), 'intersoccer_rr_pcs_warmup_rebuild_year', 10, 1);
	intersoccer_rr_pcs_warmup_register_order_hooks();
}

/**
 * Clear scheduled warm-up events (deactivation).
 */
function intersoccer_rr_pcs_warmup_clear_schedules() {
	$daily = intersoccer_rr_pcs_warmup_daily_cron_hook();
	$year_hook = intersoccer_rr_pcs_warmup_year_event_hook();
	if (function_exists('wp_clear_scheduled_hook')) {
		wp_clear_scheduled_hook($daily);
		wp_clear_scheduled_hook($year_hook);
	}
}

add_action('init', 'intersoccer_rr_pcs_warmup_bootstrap', 20);
