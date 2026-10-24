<?php
/**
 * Player course status: all PM players left-joined to course roster bookings.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

$__rr_loader = dirname(__FILE__) . '/roster-report-loader.php';
if (file_exists($__rr_loader)) {
	require_once $__rr_loader;
}

if (!function_exists('intersoccer_roster_field_player_first_name')) {
	$__cf = dirname(__FILE__) . '/roster-canonical-fields.php';
	if (file_exists($__cf)) {
		require_once $__cf;
	}
}

if (!function_exists('intersoccer_player_camp_status_load_players')) {
	$__pcs = dirname(__FILE__) . '/player-camp-status.php';
	if (file_exists($__pcs)) {
		require_once $__pcs;
	}
}

if (!function_exists('intersoccer_reports_resolve_program_year')) {
	$__fra = dirname(__FILE__) . '/final-reports-aggregation.php';
	if (file_exists($__fra)) {
		require_once $__fra;
	}
}

if (!function_exists('intersoccer_roster_row_matches_listing_kind')) {
	$__utils = dirname(__FILE__) . '/utils.php';
	if (file_exists($__utils)) {
		require_once $__utils;
	}
}

if (!function_exists('intersoccer_winback_normalize_roster_facet_slug')) {
	$__wf = dirname(__FILE__) . '/winback-report-facets.php';
	if (file_exists($__wf)) {
		require_once $__wf;
	}
}

/**
 * Whether the current user may see DOB (reuses player camp status gate).
 */
function intersoccer_player_course_status_user_can_view_dob() {
	if (function_exists('intersoccer_player_camp_status_user_can_view_dob')) {
		return intersoccer_player_camp_status_user_can_view_dob();
	}

	return false;
}

/**
 * @return string[]
 */
function intersoccer_player_course_status_export_headers() {
	$headers = [
		'Player Name',
		'Parent Email',
		'User ID',
	];
	if (intersoccer_player_course_status_user_can_view_dob()) {
		$headers[] = 'DOB';
	}
	$headers[] = 'Age';
	$headers[] = 'Booked';
	$headers[] = 'Course Bookings';
	$headers[] = 'Match Tier';

	return $headers;
}

/**
 * @param array<string,mixed> $row
 */
function intersoccer_player_course_status_row_is_course(array $row) {
	return function_exists('intersoccer_roster_row_matches_listing_kind')
		&& intersoccer_roster_row_matches_listing_kind($row, 'course');
}

/**
 * @param array<string,mixed> $row
 * @param int                 $year
 */
function intersoccer_player_course_status_row_in_year(array $row, $year) {
	$year = (int) $year;
	if ($year < 2000) {
		return false;
	}
	$start = trim((string) ($row['start_date'] ?? ''));
	$end = trim((string) ($row['end_date'] ?? ''));
	if (intersoccer_roster_report_has_real_date($start)) {
		$start_year = (int) substr($start, 0, 4);
		$end_year = intersoccer_roster_report_has_real_date($end)
			? (int) substr($end, 0, 4)
			: $start_year;
		if ($start_year === $year || $end_year === $year) {
			return true;
		}
	}
	if (function_exists('intersoccer_reports_resolve_program_year')) {
		$resolved = intersoccer_reports_resolve_program_year($row);
		if ($resolved !== null && (int) $resolved === $year) {
			return true;
		}
	}

	return false;
}

/**
 * Active course booking (PCS parity: completed/processing only; pending/on-hold excluded).
 *
 * @param array<string,mixed> $row
 */
function intersoccer_player_course_status_row_is_active_booking(array $row) {
	$status = (string) ( $row['order_status'] ?? '' );
	if (function_exists('intersoccer_roster_report_order_status_is_wholly_refunded')
		&& intersoccer_roster_report_order_status_is_wholly_refunded($status)) {
		return false;
	}
	if (function_exists('intersoccer_roster_report_row_line_is_fully_refunded')
		&& intersoccer_roster_report_row_line_is_fully_refunded($row)) {
		return false;
	}

	return function_exists('intersoccer_roster_report_status_counts_as_booked')
		&& intersoccer_roster_report_status_counts_as_booked($status);
}

/**
 * @param array<string,mixed> $rows
 * @param int                 $year
 * @param string              $season_type
 * @param string              $girls_mode
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_course_status_filter_scoped_rows(array $rows, $year, $season_type = '', $girls_mode = 'all') {
	$season_type = (string) $season_type;
	$girls_mode = in_array($girls_mode, ['all', 'mixed', 'girls_only'], true) ? $girls_mode : 'all';
	$out = [];

	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		if (!empty($row['is_placeholder'])) {
			continue;
		}
		if (!intersoccer_player_course_status_row_is_course($row)) {
			continue;
		}
		if ($girls_mode === 'mixed' && !empty($row['girls_only'])) {
			continue;
		}
		if ($girls_mode === 'girls_only' && empty($row['girls_only'])) {
			continue;
		}
		if (!intersoccer_player_course_status_row_in_year($row, $year)) {
			continue;
		}
		if ($season_type !== '') {
			$season = (string) ($row['season'] ?? '');
			$extracted = function_exists('intersoccer_extract_season_type')
				? intersoccer_extract_season_type($season)
				: null;
			if ($extracted !== $season_type && stripos($season, $season_type) === false) {
				continue;
			}
		}
		$out[] = $row;
	}

	return $out;
}

/**
 * @param string $raw
 */
function intersoccer_player_course_status_course_day_slug($raw) {
	$raw = trim((string) $raw);
	if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
		return '';
	}
	if (function_exists('intersoccer_roster_facet_for_grouping')) {
		return intersoccer_roster_facet_for_grouping($raw, 'pa_course-day');
	}
	if (function_exists('intersoccer_normalize_weekday_token')) {
		$day = intersoccer_normalize_weekday_token($raw);

		return $day ? strtolower((string) $day) : strtolower($raw);
	}

	return strtolower($raw);
}

/**
 * @param array<string,mixed> $row
 * @return array{slug:string,label:string}
 */
function intersoccer_player_course_status_resolve_venue_facet(array $row) {
	$label = trim((string) ($row['venue'] ?? ''));
	$slug = '';
	if (function_exists('intersoccer_winback_normalize_roster_facet_slug')) {
		$slug = intersoccer_winback_normalize_roster_facet_slug(
			$label,
			'pa_intersoccer-venues',
			'venue',
			$row
		);
	}
	if ($slug === '' && $label !== '') {
		$slug = sanitize_title($label);
	}

	return ['slug' => $slug, 'label' => $label !== '' ? $label : $slug];
}

/**
 * @param array<string,mixed> $row
 * @return array{slug:string,label:string}
 */
function intersoccer_player_course_status_resolve_age_group_facet(array $row) {
	$label = trim((string) ($row['age_group'] ?? ''));
	$slug = '';
	if (function_exists('intersoccer_winback_normalize_roster_facet_slug')) {
		$slug = intersoccer_winback_normalize_roster_facet_slug(
			$label,
			'pa_age-group',
			'age_group',
			$row
		);
	}
	if ($slug === '' && $label !== '') {
		$slug = sanitize_title($label);
	}

	return ['slug' => $slug, 'label' => $label !== '' ? $label : $slug];
}

/**
 * @param array<string,mixed> $row
 */
function intersoccer_player_course_status_resolve_times(array $row) {
	$times = trim((string) ($row['times'] ?? ''));
	if ($times === '' || strcasecmp($times, 'N/A') === 0) {
		$times = trim((string) ($row['course_times'] ?? ''));
	}
	if (strcasecmp($times, 'N/A') === 0) {
		$times = '';
	}

	return $times;
}

/**
 * @param array<string,mixed> $row
 */
function intersoccer_player_course_status_booking_label(array $row) {
	$product = trim((string) ($row['product_name'] ?? ''));
	$venue = trim((string) ($row['venue'] ?? ''));
	$course_day = trim((string) ($row['course_day'] ?? ''));
	$times = intersoccer_player_course_status_resolve_times($row);
	$season = trim((string) ($row['season'] ?? ''));
	$start = trim((string) ($row['start_date'] ?? ''));
	$end = trim((string) ($row['end_date'] ?? ''));
	if (!intersoccer_roster_report_has_real_date($start)) {
		$start = '';
	}
	if (!intersoccer_roster_report_has_real_date($end)) {
		$end = '';
	}
	$range = '';
	if ($start !== '' && $end !== '' && $start !== $end) {
		$range = $start . '–' . $end;
	} elseif ($start !== '') {
		$range = $start;
	} elseif ($end !== '') {
		$range = $end;
	}
	$parts = array_filter([$product, $venue, $course_day, $times, $season, $range], static function ($p) {
		return $p !== '';
	});

	return implode(' · ', $parts);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_build_booking_record(array $row) {
	$venue_facet = intersoccer_player_course_status_resolve_venue_facet($row);
	$age_facet = intersoccer_player_course_status_resolve_age_group_facet($row);
	$course_day = trim((string) ($row['course_day'] ?? ''));
	$course_day_slug = intersoccer_player_course_status_course_day_slug($course_day);

	return [
		'order_id' => (int) ($row['order_id'] ?? 0),
		'order_item_id' => (int) ($row['order_item_id'] ?? 0),
		'product' => trim((string) ($row['product_name'] ?? '')),
		'venue' => $venue_facet['label'],
		'venue_slug' => $venue_facet['slug'],
		'age_group' => $age_facet['label'],
		'age_group_slug' => $age_facet['slug'],
		'course_day' => $course_day,
		'course_day_slug' => $course_day_slug,
		'times' => intersoccer_player_course_status_resolve_times($row),
		'season' => trim((string) ($row['season'] ?? '')),
		'start_date' => intersoccer_roster_report_has_real_date($row['start_date'] ?? '') ? (string) $row['start_date'] : '',
		'end_date' => intersoccer_roster_report_has_real_date($row['end_date'] ?? '') ? (string) $row['end_date'] : '',
		'order_status' => (string) ($row['order_status'] ?? ''),
		'girls_only' => !empty($row['girls_only']),
		'label' => intersoccer_player_course_status_booking_label($row),
	];
}

/**
 * @param array<string,mixed> $booking
 * @param array<string,mixed> $filters
 */
function intersoccer_player_course_status_booking_matches_filters(array $booking, array $filters) {
	$venue = trim((string) ($filters['venue'] ?? ''));
	if ($venue !== '' && (string) ($booking['venue_slug'] ?? '') !== $venue) {
		return false;
	}
	$course_day = trim((string) ($filters['course_day'] ?? ''));
	if ($course_day !== '' && (string) ($booking['course_day_slug'] ?? '') !== $course_day) {
		return false;
	}
	$age_group = trim((string) ($filters['age_group'] ?? ''));
	if ($age_group !== '' && (string) ($booking['age_group_slug'] ?? '') !== $age_group) {
		return false;
	}

	return true;
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_course_status_prepare_roster_rows(array $rows) {
	if (function_exists('intersoccer_roster_report_rows_apply_live_order_status')) {
		$rows = intersoccer_roster_report_rows_apply_live_order_status($rows);
	}
	if (function_exists('intersoccer_roster_report_prime_order_customer_ids_from_rows')) {
		intersoccer_roster_report_prime_order_customer_ids_from_rows($rows);
	}
	if (function_exists('intersoccer_roster_report_prime_line_refund_checks_from_rows')) {
		intersoccer_roster_report_prime_line_refund_checks_from_rows($rows);
	}
	if (function_exists('intersoccer_roster_report_prime_program_year_meta_from_rows')) {
		$rows = intersoccer_roster_report_prime_program_year_meta_from_rows($rows);
	}
	if (function_exists('intersoccer_roster_report_prime_assigned_player_ids_from_rows')) {
		$rows = intersoccer_roster_report_prime_assigned_player_ids_from_rows($rows);
	}
	if (function_exists('intersoccer_winback_prime_facet_resolution_from_rows')) {
		intersoccer_winback_prime_facet_resolution_from_rows($rows, ['venue', 'age_group']);
	}
	foreach ($rows as &$row) {
		if (!is_array($row)) {
			continue;
		}
		if (function_exists('intersoccer_roster_report_row_with_resolved_customer')) {
			$row = intersoccer_roster_report_row_with_resolved_customer($row);
		} elseif (function_exists('intersoccer_roster_report_resolve_row_customer_id')) {
			$row['customer_id'] = intersoccer_roster_report_resolve_row_customer_id($row);
		}
	}
	unset($row);

	return $rows;
}

/**
 * @param int $year
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_course_status_load_course_roster_rows($year) {
	global $wpdb;

	$table = $wpdb->prefix . 'intersoccer_rosters';
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
	if ($exists !== $table) {
		return [];
	}

	$columns = $wpdb->get_col("DESCRIBE {$table}", 0);
	$select = [
		'id',
		'order_id',
		'order_item_id',
		'first_name',
		'last_name',
		'player_first_name',
		'player_last_name',
		'product_name',
		'product_id',
		'variation_id',
		'venue',
		'course_day',
		'times',
		'course_times',
		'age_group',
		'season',
		'start_date',
		'end_date',
		'activity_type',
	];
	foreach (['order_status', 'customer_id', 'player_index', 'girls_only', 'is_placeholder'] as $col) {
		if (in_array($col, (array) $columns, true)) {
			$select[] = $col;
		}
	}

	$year = (int) $year;
	$ymin = $year - 1;
	$ymax = $year + 1;

	$sql = 'SELECT ' . implode(', ', $select) . " FROM {$table} WHERE 1=1";
	$params = [];
	if (in_array('is_placeholder', (array) $columns, true)) {
		$sql .= ' AND (is_placeholder = 0 OR is_placeholder IS NULL)';
	}
	$sql .= ' AND (
		start_date IS NULL OR start_date = \'\' OR start_date = \'N/A\' OR start_date = \'1970-01-01\'
		OR LEFT(start_date, 4) BETWEEN %d AND %d
		OR end_date IS NULL OR end_date = \'\' OR end_date = \'N/A\' OR end_date = \'1970-01-01\'
		OR LEFT(end_date, 4) BETWEEN %d AND %d
	)';
	$params[] = $ymin;
	$params[] = $ymax;
	$params[] = $ymin;
	$params[] = $ymax;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
	if (!is_array($rows)) {
		return [];
	}

	return intersoccer_player_course_status_prepare_roster_rows($rows);
}

/**
 * @param array<int,array<string,mixed>> $scoped_rows Active rows in year/season/girls scope.
 * @return array<string,array<int,array<string,mixed>>>
 */
function intersoccer_player_course_status_build_booking_index(array $scoped_rows) {
	if (function_exists('intersoccer_roster_report_reset_idx_name_collision_state')) {
		intersoccer_roster_report_reset_idx_name_collision_state();
	}
	foreach ($scoped_rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		if (function_exists('intersoccer_roster_report_note_idx_name_for_collision_detection')) {
			intersoccer_roster_report_note_idx_name_for_collision_detection($row);
		}
	}

	$index = [];
	foreach ($scoped_rows as $row) {
		if (!is_array($row) || !intersoccer_player_course_status_row_is_active_booking($row)) {
			continue;
		}
		$booking = intersoccer_player_course_status_build_booking_record($row);
		$keys = function_exists('intersoccer_roster_report_collect_player_alias_keys')
			? intersoccer_roster_report_collect_player_alias_keys($row)
			: [];
		foreach ($keys as $key) {
			if ($key === '') {
				continue;
			}
			$index[ $key ][] = $booking;
		}
	}

	return $index;
}

/**
 * @param array<string,mixed> $player
 * @param array<string,array<int,array<string,mixed>>> $index
 * @return array{bookings:array<int,array<string,mixed>>,tier:string,key:string}
 */
function intersoccer_player_course_status_match_player_bookings(array $player, array $index) {
	$user_id = (int) ($player['user_id'] ?? 0);
	$player_index = isset($player['player_index']) ? (int) $player['player_index'] : -1;
	$uuid = trim((string) ($player['player_id'] ?? ''));
	$first = function_exists('intersoccer_player_camp_status_normalize_name')
		? intersoccer_player_camp_status_normalize_name($player['first_name'] ?? '')
		: strtolower(trim((string) ($player['first_name'] ?? '')));
	$last = function_exists('intersoccer_player_camp_status_normalize_name')
		? intersoccer_player_camp_status_normalize_name($player['last_name'] ?? '')
		: strtolower(trim((string) ($player['last_name'] ?? '')));

	$try = [];
	if ($uuid !== '') {
		$try[] = ['key' => 'uuid:' . $uuid, 'tier' => 'uuid'];
	}
	if ($user_id > 0 && $player_index >= 0) {
		$try[] = ['key' => 'idx:' . $user_id . ':' . $player_index, 'tier' => 'idx'];
	}
	if ($user_id > 0 && $first !== '' && $last !== '') {
		$try[] = ['key' => 'name:' . $user_id . ':' . $first . '|' . $last, 'tier' => 'name'];
	}

	$bookings = [];
	$seen_items = [];
	$best_tier = '';
	$best_tier_rank = 0;
	$key_used = '';

	foreach ($try as $attempt) {
		$k = $attempt['key'];
		if ($k === '' || !isset($index[ $k ])) {
			continue;
		}
		$attempt_tier = (string) $attempt['tier'];
		$attempt_rank = function_exists('intersoccer_roster_report_player_key_tier_rank')
			? intersoccer_roster_report_player_key_tier_rank($attempt_tier)
			: 0;
		if ($attempt_rank > $best_tier_rank) {
			$best_tier_rank = $attempt_rank;
			$best_tier = $attempt_tier;
			$key_used = $k;
		}
		foreach ($index[ $k ] as $booking) {
			$item_id = (int) ($booking['order_item_id'] ?? 0);
			if ($item_id > 0 && isset($seen_items[ $item_id ])) {
				continue;
			}
			if ($item_id > 0) {
				$seen_items[ $item_id ] = true;
			}
			$bookings[] = $booking;
		}
	}

	usort($bookings, static function ($a, $b) {
		$da = (string) ($a['start_date'] ?? '');
		$db = (string) ($b['start_date'] ?? '');
		if ($da === $db) {
			return 0;
		}
		if ($da === '') {
			return 1;
		}
		if ($db === '') {
			return -1;
		}

		return strcmp($da, $db);
	});

	return [
		'bookings' => $bookings,
		'tier' => $best_tier,
		'key' => $key_used,
	];
}

/**
 * @param array<int,array<string,mixed>> $scoped_rows
 * @return array{venues:array<string,string>,course_days:array<int,string>,age_groups:array<string,string>,seasons:array<int,string>}
 */
function intersoccer_player_course_status_build_filter_options(array $scoped_rows) {
	$venues = [];
	$course_days = [];
	$age_groups = [];
	$seasons = [];

	foreach ($scoped_rows as $row) {
		if (!is_array($row) || !intersoccer_player_course_status_row_is_active_booking($row)) {
			continue;
		}
		$booking = intersoccer_player_course_status_build_booking_record($row);
		if ($booking['venue_slug'] !== '') {
			$venues[ $booking['venue_slug'] ] = $booking['venue'];
		}
		if ($booking['course_day_slug'] !== '') {
			$course_days[ $booking['course_day_slug'] ] = $booking['course_day_slug'];
		}
		if ($booking['age_group_slug'] !== '') {
			$age_groups[ $booking['age_group_slug'] ] = $booking['age_group'];
		}
		$season = $booking['season'];
		if ($season !== '') {
			$extracted = function_exists('intersoccer_extract_season_type')
				? intersoccer_extract_season_type($season)
				: $season;
			if ($extracted !== null && $extracted !== '') {
				$seasons[ $extracted ] = $extracted;
			}
		}
	}

	ksort($venues);
	ksort($age_groups);
	$course_day_list = array_values($course_days);
	sort($course_day_list);
	$season_list = array_values($seasons);
	sort($season_list);

	return [
		'venues' => $venues,
		'course_days' => $course_day_list,
		'age_groups' => $age_groups,
		'seasons' => $season_list,
	];
}

/**
 * Resolve parent account email (batched cache when win-back helper is available).
 *
 * @param int $user_id
 */
function intersoccer_player_course_status_resolve_parent_email($user_id) {
	$user_id = (int) $user_id;
	if ($user_id <= 0) {
		return '';
	}
	if (function_exists('intersoccer_winback_prime_contact_users')) {
		intersoccer_winback_prime_contact_users([$user_id]);
		if (isset($GLOBALS['intersoccer_rr_user_account_email_cache'][ $user_id ])) {
			return (string) $GLOBALS['intersoccer_rr_user_account_email_cache'][ $user_id ];
		}
	}
	$user = get_userdata($user_id);

	return $user ? (string) $user->user_email : '';
}

/**
 * @param array<string,mixed> $filters
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_normalize_filters(array $filters) {
	$year = isset($filters['year']) ? (int) $filters['year'] : (int) date('Y');
	if ($year < 2000) {
		$year = (int) date('Y');
	}
	$girls_mode = isset($filters['girls_mode']) ? (string) $filters['girls_mode'] : 'all';
	if (!in_array($girls_mode, ['all', 'mixed', 'girls_only'], true)) {
		$girls_mode = 'all';
	}
	$booked = isset($filters['booked']) ? (string) $filters['booked'] : 'all';
	if (!in_array($booked, ['all', 'booked', 'not_booked'], true)) {
		$booked = 'all';
	}

	return [
		'year' => $year,
		'season_type' => isset($filters['season_type']) ? sanitize_text_field((string) $filters['season_type']) : '',
		'girls_mode' => $girls_mode,
		'booked' => $booked,
		'search' => isset($filters['search']) ? sanitize_text_field((string) $filters['search']) : '',
		'venue' => isset($filters['venue']) ? sanitize_text_field((string) $filters['venue']) : '',
		'course_day' => isset($filters['course_day']) ? sanitize_text_field((string) $filters['course_day']) : '',
		'age_group' => isset($filters['age_group']) ? sanitize_text_field((string) $filters['age_group']) : '',
	];
}

/**
 * Player course status engine.
 *
 * @param array<string,mixed> $filters
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_generate(array $filters) {
	$filters = intersoccer_player_course_status_normalize_filters($filters);
	$year = (int) $filters['year'];

	if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING
		&& isset($GLOBALS['intersoccer_test_player_course_status_players'])
		&& is_array($GLOBALS['intersoccer_test_player_course_status_players'])) {
		$players = $GLOBALS['intersoccer_test_player_course_status_players'];
	} else {
		$players = function_exists('intersoccer_player_camp_status_load_players')
			? intersoccer_player_camp_status_load_players()
			: [];
	}

	$user_ids = [];
	foreach ($players as $player) {
		$uid = (int) ($player['user_id'] ?? 0);
		if ($uid > 0) {
			$user_ids[ $uid ] = $uid;
		}
	}
	if (!empty($user_ids) && function_exists('intersoccer_winback_prime_contact_users')) {
		require_once dirname(__FILE__) . '/winback-report-engine.php';
		intersoccer_winback_prime_contact_users(array_values($user_ids));
	}

	if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING
		&& isset($GLOBALS['intersoccer_test_player_course_status_roster_rows'])
		&& is_array($GLOBALS['intersoccer_test_player_course_status_roster_rows'])) {
		$raw_rows = intersoccer_player_course_status_prepare_roster_rows(
			$GLOBALS['intersoccer_test_player_course_status_roster_rows']
		);
	} else {
		$raw_rows = intersoccer_player_course_status_load_course_roster_rows($year);
	}
	$scoped_rows = intersoccer_player_course_status_filter_scoped_rows(
		$raw_rows,
		$year,
		$filters['season_type'],
		$filters['girls_mode']
	);

	$filter_options = intersoccer_player_course_status_build_filter_options($scoped_rows);
	$index = intersoccer_player_course_status_build_booking_index($scoped_rows);

	$matched_item_ids = [];
	$output_rows = [];
	$booked_total = 0;
	$not_booked_total = 0;
	$can_view_dob = intersoccer_player_course_status_user_can_view_dob();

	foreach ($players as $player) {
		if (!is_array($player)) {
			continue;
		}
		$match = intersoccer_player_course_status_match_player_bookings($player, $index);
		$bookings = $match['bookings'];
		foreach ($bookings as $booking) {
			$oid = (int) ($booking['order_item_id'] ?? 0);
			if ($oid > 0) {
				$matched_item_ids[ $oid ] = true;
			}
		}

		$active_for_filters = [];
		foreach ($bookings as $booking) {
			if (!intersoccer_player_course_status_booking_matches_filters($booking, $filters)) {
				continue;
			}
			$active_for_filters[] = $booking;
		}
		$is_booked = !empty($active_for_filters);

		if ($is_booked) {
			$booked_total++;
		} else {
			$not_booked_total++;
		}

		$user_id = (int) ($player['user_id'] ?? 0);
		$email = (string) ($player['user_email'] ?? '');
		if ($email === '' && $user_id > 0) {
			$email = intersoccer_player_course_status_resolve_parent_email($user_id);
		}

		$dob_raw = (string) ($player['dob'] ?? '');
		$age = function_exists('intersoccer_player_camp_status_compute_age')
			? intersoccer_player_camp_status_compute_age($dob_raw)
			: null;

		$labels = [];
		foreach ($bookings as $booking) {
			if (!empty($booking['label'])) {
				$labels[] = $booking['label'];
			}
		}

		$row_out = [
			'player' => [
				'name' => trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '')),
				'first_name' => (string) ($player['first_name'] ?? ''),
				'last_name' => (string) ($player['last_name'] ?? ''),
				'player_index' => isset($player['player_index']) ? (int) $player['player_index'] : null,
				'key' => $match['key'],
				'tier' => $match['tier'],
			],
			'parent' => [
				'id' => $user_id,
				'email' => $email,
			],
			'dob' => $can_view_dob && $dob_raw !== '' ? $dob_raw : null,
			'dob_restricted' => !$can_view_dob,
			'age' => $age,
			'booked' => $is_booked,
			'bookings' => $bookings,
			'bookings_label' => implode('; ', $labels),
		];

		if ($filters['booked'] === 'booked' && !$is_booked) {
			continue;
		}
		if ($filters['booked'] === 'not_booked' && $is_booked) {
			continue;
		}

		$search = function_exists('intersoccer_player_camp_status_normalize_name')
			? intersoccer_player_camp_status_normalize_name($filters['search'])
			: strtolower(trim($filters['search']));
		if ($search !== '') {
			$hay = function_exists('intersoccer_player_camp_status_normalize_name')
				? intersoccer_player_camp_status_normalize_name(
					($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '') . ' ' . $email
				)
				: strtolower(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '') . ' ' . $email);
			if (strpos($hay, $search) === false) {
				continue;
			}
		}

		$output_rows[] = $row_out;
	}

	$unmatched = [];
	foreach ($scoped_rows as $row) {
		if (!is_array($row) || !intersoccer_player_course_status_row_is_active_booking($row)) {
			continue;
		}
		$item_id = (int) ($row['order_item_id'] ?? 0);
		if ($item_id > 0 && !empty($matched_item_ids[ $item_id ])) {
			continue;
		}
		$booking = intersoccer_player_course_status_build_booking_record($row);
		$first = function_exists('intersoccer_roster_report_player_first_name')
			? intersoccer_roster_report_player_first_name($row)
			: trim((string) ($row['player_first_name'] ?? $row['first_name'] ?? ''));
		$last = function_exists('intersoccer_roster_report_player_last_name')
			? intersoccer_roster_report_player_last_name($row)
			: trim((string) ($row['player_last_name'] ?? $row['last_name'] ?? ''));
		$unmatched[] = $booking + [
			'player_name' => trim($first . ' ' . $last),
			'customer_id' => (int) ($row['customer_id'] ?? 0),
		];
	}

	return [
		'rows' => $output_rows,
		'unmatched_bookings' => $unmatched,
		'filter_options' => $filter_options,
		'totals' => [
			'players' => count($players),
			'booked' => $booked_total,
			'not_booked' => $not_booked_total,
			'unmatched_bookings' => count($unmatched),
		],
	];
}

/**
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_request_filters() {
	$year = isset($_GET['year']) ? absint($_GET['year']) : (int) date('Y');
	$season_type = isset($_GET['season_type']) ? sanitize_text_field(wp_unslash($_GET['season_type'])) : '';
	$girls_mode = isset($_GET['girls_mode']) ? sanitize_text_field(wp_unslash($_GET['girls_mode'])) : 'all';
	$booked = isset($_GET['booked']) ? sanitize_text_field(wp_unslash($_GET['booked'])) : 'all';
	$search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
	$venue = isset($_GET['venue']) ? sanitize_text_field(wp_unslash($_GET['venue'])) : '';
	$course_day = isset($_GET['course_day']) ? sanitize_text_field(wp_unslash($_GET['course_day'])) : '';
	$age_group = isset($_GET['age_group']) ? sanitize_text_field(wp_unslash($_GET['age_group'])) : '';
	$paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

	return intersoccer_player_course_status_normalize_filters([
		'year' => $year,
		'season_type' => $season_type,
		'girls_mode' => $girls_mode,
		'booked' => $booked,
		'search' => $search,
		'venue' => $venue,
		'course_day' => $course_day,
		'age_group' => $age_group,
	]) + ['paged' => $paged];
}

/**
 * @param array<string,mixed> $row
 * @return list<string|int>
 */
function intersoccer_player_course_status_export_row(array $row) {
	$player = is_array($row['player'] ?? null) ? $row['player'] : [];
	$parent = is_array($row['parent'] ?? null) ? $row['parent'] : [];
	$out = [
		(string) ($player['name'] ?? ''),
		(string) ($parent['email'] ?? ''),
		(int) ($parent['id'] ?? 0),
	];
	if (intersoccer_player_course_status_user_can_view_dob()) {
		$out[] = (string) ($row['dob'] ?? '');
	}
	$out[] = $row['age'] ?? '';
	$out[] = !empty($row['booked']) ? 'Yes' : 'No';
	$out[] = (string) ($row['bookings_label'] ?? '');
	$out[] = (string) ($player['tier'] ?? '');

	return $out;
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @param int|string                     $year
 */
function intersoccer_player_course_status_stream_excel(array $rows, $year) {
	$autoload = dirname(__DIR__) . '/vendor/autoload.php';
	if (file_exists($autoload)) {
		require_once $autoload;
	}

	if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
		wp_die(esc_html__('Excel export is unavailable (PhpSpreadsheet missing).', 'intersoccer-reports-rosters'));
	}

	while (ob_get_level() > 0) {
		ob_end_clean();
	}

	$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
	$sheet = $spreadsheet->getActiveSheet();
	$sheet->setTitle('Player Course Status');

	$headers = intersoccer_player_course_status_export_headers();
	$sheet->fromArray($headers, null, 'A1');
	$last_col = chr(ord('A') + count($headers) - 1);
	$sheet->getStyle('A1:' . $last_col . '1')->getFont()->setBold(true);

	$row_index = 2;
	foreach ($rows as $row) {
		$sheet->fromArray(intersoccer_player_course_status_export_row($row), null, 'A' . $row_index);
		$row_index++;
	}

	foreach (range('A', $last_col) as $col) {
		$sheet->getColumnDimension($col)->setAutoSize(true);
	}

	$filename = 'player-course-status-' . (int) $year . '.xlsx';
	nocache_headers();
	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Cache-Control: max-age=0');

	$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
	$writer->save('php://output');
	exit;
}

function intersoccer_player_course_status_maybe_export_excel() {
	if (empty($_GET['export_excel'])) {
		return;
	}
	$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
	if ($page !== 'intersoccer-player-course-status') {
		return;
	}

	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
	}
	check_admin_referer('intersoccer_player_course_status_excel');

	$filters = intersoccer_player_course_status_request_filters();
	$result = intersoccer_player_course_status_generate($filters);
	intersoccer_player_course_status_stream_excel($result['rows'], $filters['year']);
}

function intersoccer_render_player_course_status_page() {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
	}

	$filters = intersoccer_player_course_status_request_filters();
	$result = intersoccer_player_course_status_generate($filters);

	$per_page = 50;
	$total_rows = count($result['rows']);
	$total_pages = max(1, (int) ceil($total_rows / $per_page));
	$paged = min((int) $filters['paged'], $total_pages);
	$offset = ($paged - 1) * $per_page;
	$page_rows = array_slice($result['rows'], $offset, $per_page);

	$base_args = [
		'page' => 'intersoccer-player-course-status',
		'year' => $filters['year'],
		'season_type' => $filters['season_type'],
		'girls_mode' => $filters['girls_mode'],
		'booked' => $filters['booked'],
		'search' => $filters['search'],
		'venue' => $filters['venue'],
		'course_day' => $filters['course_day'],
		'age_group' => $filters['age_group'],
	];

	$excel_url = wp_nonce_url(
		add_query_arg(array_merge($base_args, ['export_excel' => 1]), admin_url('admin.php')),
		'intersoccer_player_course_status_excel'
	);
	?>
	<div class="wrap intersoccer-player-course-status">
		<h1><?php esc_html_e('Player course status', 'intersoccer-reports-rosters'); ?></h1>
		<p><?php esc_html_e('QA view: all Player Management players with course roster bookings for the selected filters.', 'intersoccer-reports-rosters'); ?></p>

		<p class="description" style="margin-bottom:16px;">
			<?php
			printf(
				esc_html__('Players: %1$d · Booked: %2$d · Not booked: %3$d · Unmatched bookings: %4$d · Showing: %5$d', 'intersoccer-reports-rosters'),
				(int) ($result['totals']['players'] ?? 0),
				(int) ($result['totals']['booked'] ?? 0),
				(int) ($result['totals']['not_booked'] ?? 0),
				(int) ($result['totals']['unmatched_bookings'] ?? 0),
				$total_rows
			);
			?>
		</p>

		<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="intersoccer-player-course-status" />
			<label for="pcrs-year"><?php esc_html_e('Year:', 'intersoccer-reports-rosters'); ?></label>
			<input type="number" name="year" id="pcrs-year" value="<?php echo esc_attr((string) $filters['year']); ?>" min="2020" max="<?php echo esc_attr((string) ((int) date('Y') + 2)); ?>" />

			<label for="pcrs-season"><?php esc_html_e('Season:', 'intersoccer-reports-rosters'); ?></label>
			<select name="season_type" id="pcrs-season">
				<option value="" <?php selected($filters['season_type'], ''); ?>><?php esc_html_e('All seasons', 'intersoccer-reports-rosters'); ?></option>
				<?php foreach (['Summer', 'Winter', 'Autumn', 'Spring'] as $season_opt) : ?>
					<option value="<?php echo esc_attr($season_opt); ?>" <?php selected($filters['season_type'], $season_opt); ?>><?php echo esc_html($season_opt); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="pcrs-girls"><?php esc_html_e('Girls mode:', 'intersoccer-reports-rosters'); ?></label>
			<select name="girls_mode" id="pcrs-girls">
				<option value="all" <?php selected($filters['girls_mode'], 'all'); ?>><?php esc_html_e('All', 'intersoccer-reports-rosters'); ?></option>
				<option value="mixed" <?php selected($filters['girls_mode'], 'mixed'); ?>><?php esc_html_e('Mixed only', 'intersoccer-reports-rosters'); ?></option>
				<option value="girls_only" <?php selected($filters['girls_mode'], 'girls_only'); ?>><?php esc_html_e('Girls only', 'intersoccer-reports-rosters'); ?></option>
			</select>

			<label for="pcrs-venue"><?php esc_html_e('Venue:', 'intersoccer-reports-rosters'); ?></label>
			<select name="venue" id="pcrs-venue">
				<option value=""><?php esc_html_e('All venues', 'intersoccer-reports-rosters'); ?></option>
				<?php foreach (($result['filter_options']['venues'] ?? []) as $slug => $label) : ?>
					<option value="<?php echo esc_attr((string) $slug); ?>" <?php selected($filters['venue'], (string) $slug); ?>><?php echo esc_html((string) $label); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="pcrs-day"><?php esc_html_e('Course day:', 'intersoccer-reports-rosters'); ?></label>
			<select name="course_day" id="pcrs-day">
				<option value=""><?php esc_html_e('All days', 'intersoccer-reports-rosters'); ?></option>
				<?php foreach (($result['filter_options']['course_days'] ?? []) as $day_slug) : ?>
					<option value="<?php echo esc_attr((string) $day_slug); ?>" <?php selected($filters['course_day'], (string) $day_slug); ?>><?php echo esc_html((string) $day_slug); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="pcrs-age"><?php esc_html_e('Age group:', 'intersoccer-reports-rosters'); ?></label>
			<select name="age_group" id="pcrs-age">
				<option value=""><?php esc_html_e('All age groups', 'intersoccer-reports-rosters'); ?></option>
				<?php foreach (($result['filter_options']['age_groups'] ?? []) as $slug => $label) : ?>
					<option value="<?php echo esc_attr((string) $slug); ?>" <?php selected($filters['age_group'], (string) $slug); ?>><?php echo esc_html((string) $label); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="pcrs-booked"><?php esc_html_e('Booked:', 'intersoccer-reports-rosters'); ?></label>
			<select name="booked" id="pcrs-booked">
				<option value="all" <?php selected($filters['booked'], 'all'); ?>><?php esc_html_e('All', 'intersoccer-reports-rosters'); ?></option>
				<option value="booked" <?php selected($filters['booked'], 'booked'); ?>><?php esc_html_e('Booked only', 'intersoccer-reports-rosters'); ?></option>
				<option value="not_booked" <?php selected($filters['booked'], 'not_booked'); ?>><?php esc_html_e('Not booked only', 'intersoccer-reports-rosters'); ?></option>
			</select>

			<label for="pcrs-search"><?php esc_html_e('Search:', 'intersoccer-reports-rosters'); ?></label>
			<input type="text" name="search" id="pcrs-search" value="<?php echo esc_attr($filters['search']); ?>" />

			<button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'intersoccer-reports-rosters'); ?></button>
			<a class="button" href="<?php echo esc_url($excel_url); ?>"><?php esc_html_e('Export to Excel', 'intersoccer-reports-rosters'); ?></a>
		</form>

		<?php if ($total_pages > 1) : ?>
			<div class="tablenav top" style="margin-bottom:8px;">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links([
							'base' => add_query_arg(array_merge($base_args, ['paged' => '%#%']), admin_url('admin.php')),
							'format' => '',
							'current' => $paged,
							'total' => $total_pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						])
					);
					?>
				</div>
			</div>
		<?php endif; ?>

		<?php if (!empty($result['unmatched_bookings'])) : ?>
			<p><strong><?php esc_html_e('Unmatched active course bookings:', 'intersoccer-reports-rosters'); ?></strong>
				<?php echo esc_html((string) count($result['unmatched_bookings'])); ?></p>
		<?php endif; ?>

		<?php if (empty($page_rows)) : ?>
			<p><?php esc_html_e('No players matched the selected filters.', 'intersoccer-reports-rosters'); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Player', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('Parent email', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('Age', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('Booked', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('Course bookings', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('Match tier', 'intersoccer-reports-rosters'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($page_rows as $row) :
						$player = is_array($row['player'] ?? null) ? $row['player'] : [];
						$parent = is_array($row['parent'] ?? null) ? $row['parent'] : [];
						?>
						<tr>
							<td><?php echo esc_html((string) ($player['name'] ?? '')); ?></td>
							<td><?php echo esc_html((string) ($parent['email'] ?? '')); ?></td>
							<td><?php echo esc_html($row['age'] !== null && $row['age'] !== '' ? (string) $row['age'] : '—'); ?></td>
							<td><?php echo !empty($row['booked']) ? esc_html__('Yes', 'intersoccer-reports-rosters') : esc_html__('No', 'intersoccer-reports-rosters'); ?></td>
							<td><?php echo esc_html((string) ($row['bookings_label'] ?? '—')); ?></td>
							<td><?php echo esc_html((string) ($player['tier'] ?? '')); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
