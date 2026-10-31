<?php
/**
 * Win-back report full-engine result cache (tab/paging applied in admin).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

$__intersoccer_winback_cache_gate = dirname(__FILE__) . '/winback-report-cache-gate.php';
if (file_exists($__intersoccer_winback_cache_gate)) {
	require_once $__intersoccer_winback_cache_gate;
}

/**
 * Cache schema version (bump when payload shape changes).
 */
function intersoccer_winback_report_cache_schema_version() {
	return 2;
}

function intersoccer_winback_report_cache_ttl() {
	$ttl = apply_filters('intersoccer_winback_report_cache_ttl', 12 * HOUR_IN_SECONDS);

	return max(1, (int) $ttl);
}

function intersoccer_winback_report_cache_max_bytes() {
	if (isset($GLOBALS['intersoccer_test_winback_cache_max_bytes'])) {
		return max(1, (int) $GLOBALS['intersoccer_test_winback_cache_max_bytes']);
	}
	$max = apply_filters('intersoccer_winback_report_cache_max_bytes', 5 * 1024 * 1024);

	return max(1024, (int) $max);
}

function intersoccer_winback_report_cache_data_version() {
	$version = '0';
	if (function_exists('intersoccer_player_course_status_cache_data_version')) {
		$version = intersoccer_player_course_status_cache_data_version();
	}

	return (string) apply_filters('intersoccer_winback_report_cache_data_version', $version);
}

/**
 * @param array<string,mixed> $filters Engine filters (kind, venue, age_group, lookback_months).
 * @return array<string,mixed>
 */
function intersoccer_winback_report_cache_normalize_engine_filters(array $filters) {
	$kind = isset($filters['kind']) ? strtolower((string) $filters['kind']) : 'all';
	if (!in_array($kind, ['camp', 'course', 'all'], true)) {
		$kind = 'all';
	}
	$venue = isset($filters['venue']) ? (string) $filters['venue'] : '';
	$age = isset($filters['age_group']) ? (string) $filters['age_group'] : '';
	if (function_exists('sanitize_title')) {
		$venue = sanitize_title($venue);
		$age = sanitize_title($age);
	} else {
		$venue = strtolower(trim($venue));
		$age = strtolower(trim($age));
	}
	$lookback = isset($filters['lookback_months']) ? max(1, (int) $filters['lookback_months']) : 12;

	return [
		'kind' => $kind,
		'venue' => $venue,
		'age_group' => $age,
		'lookback_months' => $lookback,
	];
}

/**
 * @param int $lookback_months
 */
function intersoccer_winback_report_cache_transient_key($lookback_months) {
	$lookback_months = max(1, (int) $lookback_months);
	$today = function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d');
	$payload = [
		'schema' => intersoccer_winback_report_cache_schema_version(),
		'lookback_months' => $lookback_months,
		'today' => $today,
		'data' => intersoccer_winback_report_cache_data_version(),
	];
	$json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);

	return 'intersoccer_wb_' . md5((string) $json);
}

/**
 * @param array<string,mixed> $result Full engine result (kind=all base).
 * @param array<string,mixed> $filters Request filters (kind, venue, age_group, lookback_months).
 * @return array<string,mixed>
 */
function intersoccer_winback_report_apply_list_filters(array $result, array $filters) {
	$normalized = intersoccer_winback_report_cache_normalize_engine_filters($filters);
	$kind = $normalized['kind'];
	$venue = $normalized['venue'];
	$age = $normalized['age_group'];
	$rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [];
	$out_rows = [];
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$row_kind = isset($row['listing_kind']) ? (string) $row['listing_kind'] : '';
		if ($kind !== 'all' && $row_kind !== '' && $row_kind !== $kind) {
			continue;
		}
		$last = isset($row['last_event']) && is_array($row['last_event']) ? $row['last_event'] : [];
		$row_venue = (string) ( $last['venue'] ?? '' );
		$row_age = (string) ( $last['age_group'] ?? '' );
		if ($venue !== '') {
			$row_venue_slug = function_exists('sanitize_title') ? sanitize_title($row_venue) : strtolower($row_venue);
			if ($row_venue_slug !== $venue) {
				continue;
			}
		}
		if ($age !== '') {
			$row_age_slug = function_exists('sanitize_title') ? sanitize_title($row_age) : strtolower($row_age);
			if ($row_age_slug !== $age) {
				continue;
			}
		}
		$out_rows[] = $row;
	}
	$result['rows'] = $out_rows;

	return $result;
}

function intersoccer_winback_report_transient_get($key) {
	if (isset($GLOBALS['intersoccer_test_winback_transient_store'])
		&& is_array($GLOBALS['intersoccer_test_winback_transient_store'])
		&& array_key_exists($key, $GLOBALS['intersoccer_test_winback_transient_store'])) {
		return $GLOBALS['intersoccer_test_winback_transient_store'][ $key ];
	}

	return get_transient($key);
}

function intersoccer_winback_report_transient_set($key, $value, $ttl) {
	if (isset($GLOBALS['intersoccer_test_winback_transient_store'])
		&& is_array($GLOBALS['intersoccer_test_winback_transient_store'])) {
		$GLOBALS['intersoccer_test_winback_transient_store'][ $key ] = $value;
	}

	return set_transient($key, $value, $ttl);
}

function intersoccer_winback_report_transient_delete($key) {
	if (isset($GLOBALS['intersoccer_test_winback_transient_store'])
		&& is_array($GLOBALS['intersoccer_test_winback_transient_store'])) {
		unset($GLOBALS['intersoccer_test_winback_transient_store'][ $key ]);
	}

	return delete_transient($key);
}

/**
 * @param array<string,mixed> $result
 * @return string|false
 */
function intersoccer_winback_report_cache_encode_payload(array $result) {
	$serialized = maybe_serialize($result);
	if (!function_exists('gzcompress')) {
		return false;
	}
	$compressed = @gzcompress($serialized, 6);
	if ($compressed === false) {
		return false;
	}

	return base64_encode($compressed);
}

/**
 * @param string $stored
 * @return array<string,mixed>|null
 */
function intersoccer_winback_report_cache_decode_payload($stored) {
	if (!is_string($stored) || $stored === '') {
		return null;
	}
	$binary = base64_decode($stored, true);
	if ($binary === false || !function_exists('gzuncompress')) {
		return null;
	}
	$unpacked = @gzuncompress($binary);
	if ($unpacked === false) {
		return null;
	}
	$decoded = maybe_unserialize($unpacked);

	return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string,mixed> $engine_filters
 * @return array<string,mixed>|null
 */
function intersoccer_winback_report_cache_read($lookback_months) {
	$key = intersoccer_winback_report_cache_transient_key($lookback_months);
	$raw = intersoccer_winback_report_transient_get($key);
	if ($raw === false || $raw === null || !is_string($raw)) {
		return null;
	}
	$decoded = intersoccer_winback_report_cache_decode_payload($raw);
	if (!is_array($decoded)) {
		intersoccer_winback_report_transient_delete($key);

		return null;
	}

	return $decoded;
}

/**
 * @param array<string,mixed> $engine_filters
 * @param array<string,mixed> $result
 */
function intersoccer_winback_report_cache_write($lookback_months, array $result) {
	if (!empty($result['engine_error'])) {
		return;
	}
	$key = intersoccer_winback_report_cache_transient_key($lookback_months);
	$stored = intersoccer_winback_report_cache_encode_payload($result);
	if ($stored === false) {
		return;
	}
	$size = strlen($stored);
	if ($size > intersoccer_winback_report_cache_max_bytes()) {
		return;
	}
	if (isset($GLOBALS['intersoccer_test_winback_cache_last_stored_bytes'])) {
		$GLOBALS['intersoccer_test_winback_cache_last_stored_bytes'] = $size;
	}
	intersoccer_winback_report_transient_set($key, $stored, intersoccer_winback_report_cache_ttl());
}

function intersoccer_winback_report_cache_refresh_requested() {
	if (empty($_REQUEST['wb_refresh'])) {
		return false;
	}
	if (!current_user_can('manage_options')) {
		return false;
	}
	$nonce = isset($_REQUEST['_wb_nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wb_nonce'])) : '';
	if (array_key_exists('intersoccer_test_winback_verify_refresh_nonce', $GLOBALS)) {
		return (bool) $GLOBALS['intersoccer_test_winback_verify_refresh_nonce'];
	}

	return (bool) wp_verify_nonce($nonce, 'intersoccer_wb_refresh');
}

/**
 * @param array<string,mixed> $args
 */
function intersoccer_winback_report_refresh_url(array $args = []) {
	$base = [ 'page' => 'intersoccer-winback-report' ];
	if (function_exists('intersoccer_winback_report_request_filters')) {
		$filters = !empty($args) ? $args : intersoccer_winback_report_request_filters();
	} else {
		$filters = $args;
	}
	$query = array_merge($base, $filters, $args, [ 'wb_refresh' => 1 ]);
	$url = add_query_arg($query, admin_url('admin.php'));

	return wp_nonce_url($url, 'intersoccer_wb_refresh', '_wb_nonce');
}

/**
 * Cached wrapper around {@see intersoccer_winback_report_engine_generate()}.
 *
 * @param array<string,mixed> $filters
 * @param array<string,mixed> $options force_cache_refresh (bool)
 * @return array<string,mixed>
 */
function intersoccer_winback_report_engine_generate_cached(array $filters, array $options = []) {
	$normalized = intersoccer_winback_report_cache_normalize_engine_filters($filters);
	$lookback = (int) $normalized['lookback_months'];
	$force = !empty($options['force_cache_refresh']) || intersoccer_winback_report_cache_refresh_requested();

	$base = null;
	if (intersoccer_winback_report_cache_enabled() && !$force) {
		$base = intersoccer_winback_report_cache_read($lookback);
	}
	if (!is_array($base)) {
		if (isset($GLOBALS['intersoccer_test_winback_engine_generate_call_count'])) {
			$GLOBALS['intersoccer_test_winback_engine_generate_call_count'] = (int) $GLOBALS['intersoccer_test_winback_engine_generate_call_count'] + 1;
		}
		$stub = apply_filters('intersoccer_test_winback_engine_stub_result', null);
		if (is_array($stub)) {
			$base = $stub;
		} else {
			$base = intersoccer_winback_report_engine_generate([
				'kind' => 'all',
				'venue' => '',
				'age_group' => '',
				'lookback_months' => $lookback,
			]);
		}
		if (intersoccer_winback_report_cache_enabled()) {
			intersoccer_winback_report_cache_write($lookback, $base);
		}
	}

	return intersoccer_winback_report_apply_list_filters($base, $filters);
}

$__intersoccer_winback_warmup = dirname(__FILE__) . '/winback-report-warmup.php';
if (file_exists($__intersoccer_winback_warmup)) {
	require_once $__intersoccer_winback_warmup;
}
