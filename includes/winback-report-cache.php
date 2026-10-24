<?php
/**
 * Win-back report full-engine result cache (tab/paging applied in admin).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Cache schema version (bump when payload shape changes).
 */
function intersoccer_winback_report_cache_schema_version() {
	return 1;
}

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
	if (isset($GLOBALS['intersoccer_test_winback_cache_data_version'])) {
		return (string) $GLOBALS['intersoccer_test_winback_cache_data_version'];
	}
	if (function_exists('intersoccer_player_course_status_cache_data_version')) {
		return intersoccer_player_course_status_cache_data_version();
	}

	return '0';
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
 * @param array<string,mixed> $engine_filters
 */
function intersoccer_winback_report_cache_transient_key(array $engine_filters) {
	$today = function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d');
	$payload = [
		'schema' => intersoccer_winback_report_cache_schema_version(),
		'filters' => intersoccer_winback_report_cache_normalize_engine_filters($engine_filters),
		'today' => $today,
		'data' => intersoccer_winback_report_cache_data_version(),
	];
	$json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);

	return 'intersoccer_wb_' . md5((string) $json);
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
function intersoccer_winback_report_cache_read(array $engine_filters) {
	$key = intersoccer_winback_report_cache_transient_key($engine_filters);
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
function intersoccer_winback_report_cache_write(array $engine_filters, array $result) {
	$key = intersoccer_winback_report_cache_transient_key($engine_filters);
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
	$engine_filters = intersoccer_winback_report_cache_normalize_engine_filters($filters);
	$force = !empty($options['force_cache_refresh']) || intersoccer_winback_report_cache_refresh_requested();

	if (intersoccer_winback_report_cache_enabled() && !$force) {
		$cached = intersoccer_winback_report_cache_read($engine_filters);
		if (is_array($cached)) {
			return $cached;
		}
	}

	if (isset($GLOBALS['intersoccer_test_winback_engine_generate_call_count'])) {
		$GLOBALS['intersoccer_test_winback_engine_generate_call_count'] = (int) $GLOBALS['intersoccer_test_winback_engine_generate_call_count'] + 1;
	}

	if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING
		&& isset($GLOBALS['intersoccer_test_winback_engine_stub_result'])
		&& is_array($GLOBALS['intersoccer_test_winback_engine_stub_result'])) {
		$result = $GLOBALS['intersoccer_test_winback_engine_stub_result'];
	} else {
		$result = intersoccer_winback_report_engine_generate($engine_filters);
	}

	if (intersoccer_winback_report_cache_enabled()) {
		intersoccer_winback_report_cache_write($engine_filters, $result);
	}

	return $result;
}
