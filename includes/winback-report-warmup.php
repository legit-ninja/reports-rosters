<?php
/**
 * Debounced background warm-up for win-back report base cache.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Load win-back engine + cache modules for cron/event rebuild (not loaded on every request).
 */
function intersoccer_rr_winback_warmup_load_engine_modules() {
	static $loaded = false;
	if ($loaded) {
		return;
	}
	$dir = dirname(__FILE__);
	foreach (
		[
			'utils.php',
			'roster-report-loader.php',
			'winback-report-perf.php',
			'winback-report-facets.php',
			'winback-catalogue.php',
			'winback-report-engine.php',
			'winback-report-cache.php',
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
 * Single-event hook: debounced win-back base rebuild.
 */
function intersoccer_rr_winback_warmup_event_hook() {
	return 'intersoccer_rr_winback_warmup_event';
}

/**
 * Schedule one debounced win-back base rebuild (deduped).
 *
 * @param int $lookback_months
 */
function intersoccer_rr_winback_warmup_schedule($lookback_months = 12) {
	if (!function_exists('intersoccer_winback_report_cache_enabled')
		|| !intersoccer_winback_report_cache_enabled()) {
		return;
	}
	$lookback_months = max(1, (int) $lookback_months);
	$hook = intersoccer_rr_winback_warmup_event_hook();
	$args = [ $lookback_months ];
	if (function_exists('wp_next_scheduled') && wp_next_scheduled($hook, $args)) {
		return;
	}
	if (!function_exists('wp_schedule_single_event')) {
		return;
	}
	$debounce = function_exists('intersoccer_rr_pcs_warmup_debounce_seconds')
		? intersoccer_rr_pcs_warmup_debounce_seconds()
		: 120;
	wp_schedule_single_event(time() + $debounce, $hook, $args);
}

function intersoccer_rr_winback_warmup_schedule_default() {
	$lookback = 12;
	if (function_exists('intersoccer_winback_report_request_filters')) {
		$filters = intersoccer_winback_report_request_filters();
		$lookback = (int) ( $filters['lookback_months'] ?? 12 );
	}
	intersoccer_rr_winback_warmup_schedule($lookback);
}

/**
 * @param int $lookback_months
 */
function intersoccer_rr_winback_warmup_rebuild($lookback_months = 12) {
	intersoccer_rr_winback_warmup_load_engine_modules();
	$lookback_months = max(1, (int) $lookback_months);
	if (!function_exists('intersoccer_winback_report_engine_generate')
		|| !function_exists('intersoccer_winback_report_cache_write')) {
		return;
	}
	if (!intersoccer_winback_report_cache_enabled()) {
		return;
	}
	$memo_started = false;
	if (function_exists('intersoccer_rr_product_type_memo_begin')) {
		intersoccer_rr_product_type_memo_begin();
		$memo_started = true;
	}
	try {
		$base = intersoccer_winback_report_engine_generate([
			'kind' => 'all',
			'venue' => '',
			'age_group' => '',
			'lookback_months' => $lookback_months,
		]);
		if (is_array($base)) {
			intersoccer_winback_report_cache_write($lookback_months, $base);
		}
	} finally {
		if ($memo_started && function_exists('intersoccer_rr_product_type_memo_end')) {
			intersoccer_rr_product_type_memo_end();
		}
	}
}

add_action(intersoccer_rr_winback_warmup_event_hook(), 'intersoccer_rr_winback_warmup_rebuild', 10, 1);
