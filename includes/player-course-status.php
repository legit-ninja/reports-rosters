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

if (!function_exists('intersoccer_rr_product_type_memo_begin')) {
	$__perf = dirname(__FILE__) . '/winback-report-perf.php';
	if (file_exists($__perf)) {
		require_once $__perf;
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
 * Reset per-run memos (facet slugs, roster query failure, win-back facet cache).
 */
function intersoccer_player_course_status_reset_run_memos() {
	intersoccer_player_course_status_reset_roster_query_state();
	intersoccer_player_course_status_reset_facet_slug_memos();
	if (function_exists('intersoccer_winback_reset_facet_resolution_cache')) {
		intersoccer_winback_reset_facet_resolution_cache();
	}
}

/**
 * Clear course-day slug memo for the current generate() run.
 */
function intersoccer_player_course_status_reset_facet_slug_memos() {
	unset($GLOBALS['intersoccer_pcs_course_day_slug_memo']);
}

/**
 * Read-only batched PM player loader (no intersoccer_get_user_players / UUID backfill writes).
 *
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_course_status_load_players() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$meta_rows = $wpdb->get_results(
		"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'intersoccer_players'",
		ARRAY_A
	);
	if (!is_array($meta_rows)) {
		return [];
	}

	$user_ids = [];
	$players_by_user = [];
	foreach ($meta_rows as $meta_row) {
		if (!is_array($meta_row)) {
			continue;
		}
		$user_id = (int) ($meta_row['user_id'] ?? 0);
		if ($user_id <= 0) {
			continue;
		}
		$user_ids[ $user_id ] = $user_id;
		$raw = isset($meta_row['meta_value']) ? $meta_row['meta_value'] : '';
		$rows = is_array($raw) ? $raw : maybe_unserialize($raw);
		if (!is_array($rows) || empty($rows)) {
			continue;
		}
		$players_by_user[ $user_id ] = $rows;
	}

	$emails_by_user = [];
	if (!empty($user_ids)) {
		$chunk_size = function_exists('intersoccer_roster_report_batch_chunk_size')
			? intersoccer_roster_report_batch_chunk_size()
			: 500;
		$id_list = array_values($user_ids);
		foreach (array_chunk($id_list, $chunk_size) as $chunk) {
			if (empty($chunk)) {
				continue;
			}
			$placeholders = implode(',', array_fill(0, count($chunk), '%d'));
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder
			$sql = "SELECT ID, user_email FROM {$wpdb->users} WHERE ID IN ({$placeholders})";
			$email_rows = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);
			if (!is_array($email_rows)) {
				continue;
			}
			foreach ($email_rows as $email_row) {
				if (!is_array($email_row)) {
					continue;
				}
				$uid = (int) ($email_row['ID'] ?? 0);
				if ($uid <= 0) {
					continue;
				}
				$emails_by_user[ $uid ] = (string) ($email_row['user_email'] ?? '');
			}
		}
		if (!isset($GLOBALS['intersoccer_rr_user_account_email_cache'])
			|| !is_array($GLOBALS['intersoccer_rr_user_account_email_cache'])) {
			$GLOBALS['intersoccer_rr_user_account_email_cache'] = [];
		}
		foreach ($emails_by_user as $uid => $email) {
			$GLOBALS['intersoccer_rr_user_account_email_cache'][ (int) $uid ] = $email;
		}
	}

	$players = [];
	foreach ($players_by_user as $user_id => $rows) {
		$user_id = (int) $user_id;
		$email = isset($emails_by_user[ $user_id ]) ? $emails_by_user[ $user_id ] : '';

		foreach ($rows as $index => $player) {
			if (!is_array($player)) {
				continue;
			}
			$first = trim((string) ($player['first_name'] ?? ''));
			$last = trim((string) ($player['last_name'] ?? ''));
			if ($first === '' && $last === '') {
				continue;
			}
			$dob = (string) ($player['dob'] ?? '');
			$player_id = trim((string) ($player['player_id'] ?? ''));
			$players[] = [
				'user_id' => $user_id,
				'user_email' => $email,
				'player_id' => $player_id,
				'player_index' => is_numeric($index) ? (int) $index : $index,
				'first_name' => $first,
				'last_name' => $last,
				'dob' => $dob,
				'age' => function_exists('intersoccer_player_camp_status_compute_age')
					? intersoccer_player_camp_status_compute_age($dob)
					: null,
			];
		}
	}

	usort($players, static function ($a, $b) {
		$cmp = strcasecmp((string) ($a['last_name'] ?? ''), (string) ($b['last_name'] ?? ''));
		if ($cmp !== 0) {
			return $cmp;
		}

		return strcasecmp((string) ($a['first_name'] ?? ''), (string) ($b['first_name'] ?? ''));
	});

	return $players;
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
	$headers[] = 'Status';
	$headers[] = 'Course Bookings';
	$headers[] = 'Match Tier';

	return $headers;
}

/**
 * Translated label for a player row booking_status slug.
 *
 * @param string $status booked|pending_payment|on_hold|not_booked
 */
function intersoccer_player_course_status_booking_status_label($status) {
	switch ((string) $status) {
		case 'booked':
			return __('Booked', 'intersoccer-reports-rosters');
		case 'pending_payment':
			return __('Pending payment', 'intersoccer-reports-rosters');
		case 'on_hold':
			return __('On hold', 'intersoccer-reports-rosters');
		default:
			return __('Not booked', 'intersoccer-reports-rosters');
	}
}

/**
 * Schema version for the player course status full-result transient (bump when payload shape changes).
 */
function intersoccer_player_course_status_cache_schema_version() {
	return 4;
}

/**
 * Canonical season label for the courses report (title case per word, including after "/").
 *
 * @param string $season Raw roster season label.
 */
function intersoccer_player_course_status_canonical_season_label($season) {
	$season = preg_replace('/\s+/u', ' ', trim((string) $season));
	if ($season === '') {
		return '';
	}
	$tokens = preg_split('/\s+/u', $season, -1, PREG_SPLIT_NO_EMPTY);
	if (!is_array($tokens)) {
		return '';
	}
	$out_tokens = [];
	foreach ($tokens as $token) {
		$parts = preg_split('#/#u', (string) $token, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($parts)) {
			continue;
		}
		$out_parts = [];
		foreach ($parts as $part) {
			$part = (string) $part;
			if ($part === '') {
				continue;
			}
			if (function_exists('mb_convert_case')) {
				$out_parts[] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
			} else {
				$out_parts[] = ucfirst(strtolower($part));
			}
		}
		if (!empty($out_parts)) {
			$out_tokens[] = implode('/', $out_parts);
		}
	}

	return implode(' ', $out_tokens);
}

/**
 * Season value shown in filter_options['seasons'] (compound labels stay full; simple labels keep season type).
 *
 * @param string $canonical Canonical season label.
 */
function intersoccer_player_course_status_season_filter_option_label($canonical) {
	$canonical = (string) $canonical;
	if ($canonical === '') {
		return '';
	}
	if (strpos($canonical, '/') !== false) {
		return $canonical;
	}
	if (function_exists('intersoccer_extract_season_type')) {
		$extracted = intersoccer_extract_season_type($canonical);
		if ($extracted !== null && $extracted !== '') {
			return $extracted;
		}
	}

	return $canonical;
}

/**
 * Whether a roster season matches the season_type filter (courses report only).
 *
 * @param string $row_season
 * @param string $season_type  Filter value (season type or full label).
 */
function intersoccer_player_course_status_season_matches_filter($row_season, $season_type) {
	$season_type = (string) $season_type;
	if ($season_type === '') {
		return true;
	}
	$row_canonical = intersoccer_player_course_status_canonical_season_label($row_season);
	$filter_canonical = intersoccer_player_course_status_canonical_season_label($season_type);
	if ($row_canonical !== '' && $filter_canonical !== ''
		&& strcasecmp($row_canonical, $filter_canonical) === 0) {
		return true;
	}
	$extracted = function_exists('intersoccer_extract_season_type')
		? intersoccer_extract_season_type($season_type)
		: null;
	if ($extracted !== null && $extracted === $season_type && strpos($filter_canonical, '/') === false) {
		if (strpos($row_canonical, '/') !== false) {
			return false;
		}
		$row_type = function_exists('intersoccer_extract_season_type')
			? intersoccer_extract_season_type($row_canonical)
			: null;

		return $row_type !== null && strcasecmp((string) $row_type, $extracted) === 0;
	}

	if (strpos($filter_canonical, '/') !== false) {
		return $row_canonical !== '' && strcasecmp($row_canonical, $filter_canonical) === 0;
	}

	return stripos($row_canonical, $season_type) !== false;
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
 * Whether refund gates allow treating a row as a live pending/on-hold intent line.
 *
 * @param array<string,mixed> $row
 */
function intersoccer_player_course_status_row_passes_refund_gates(array $row) {
	$status = (string) ( $row['order_status'] ?? '' );
	if (function_exists('intersoccer_roster_report_order_status_is_wholly_refunded')
		&& intersoccer_roster_report_order_status_is_wholly_refunded($status)) {
		return false;
	}
	if (function_exists('intersoccer_roster_report_row_line_is_fully_refunded')
		&& intersoccer_roster_report_row_line_is_fully_refunded($row)) {
		return false;
	}

	return true;
}

/**
 * Normalized pending intent: pending_payment or on_hold, or empty when not applicable.
 *
 * @param array<string,mixed> $row
 * @return string ''|'pending_payment'|'on_hold'
 */
function intersoccer_player_course_status_row_pending_intent_kind(array $row) {
	if (!intersoccer_player_course_status_row_passes_refund_gates($row)) {
		return '';
	}
	$norm = function_exists('intersoccer_roster_report_normalize_order_status')
		? intersoccer_roster_report_normalize_order_status($row['order_status'] ?? '')
		: strtolower(trim((string) ( $row['order_status'] ?? '' )));
	if ($norm === 'pending') {
		return 'pending_payment';
	}
	if ($norm === 'on-hold') {
		return 'on_hold';
	}

	return '';
}

/**
 * @param array<string,mixed> $row
 */
function intersoccer_player_course_status_row_is_pending_intent_booking(array $row) {
	return intersoccer_player_course_status_row_pending_intent_kind($row) !== '';
}

/**
 * @param array<int,array<string,mixed>> $scoped_rows
 * @return array<string,array<int,array<string,mixed>>>
 */
function intersoccer_player_course_status_build_pending_booking_index(array $scoped_rows, array $booking_records = []) {
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
		if (!is_array($row) || !intersoccer_player_course_status_row_is_pending_intent_booking($row)) {
			continue;
		}
		$item_id = (int) ($row['order_item_id'] ?? 0);
		$booking = ( $item_id > 0 && isset($booking_records[ $item_id ]) )
			? $booking_records[ $item_id ]
			: intersoccer_player_course_status_build_booking_record($row);
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
 * @param array<int,array<string,mixed>> $pending_active Facet-matched pending/on-hold bookings.
 * @return string pending_payment|on_hold
 */
function intersoccer_player_course_status_booking_status_from_pending_matches(array $pending_active) {
	foreach ($pending_active as $booking) {
		$norm = function_exists('intersoccer_roster_report_normalize_order_status')
			? intersoccer_roster_report_normalize_order_status($booking['order_status'] ?? '')
			: '';
		if ($norm === 'pending') {
			return 'pending_payment';
		}
	}
	foreach ($pending_active as $booking) {
		$norm = function_exists('intersoccer_roster_report_normalize_order_status')
			? intersoccer_roster_report_normalize_order_status($booking['order_status'] ?? '')
			: '';
		if ($norm === 'on-hold') {
			return 'on_hold';
		}
	}

	return 'not_booked';
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
		if ($season_type !== '' && !intersoccer_player_course_status_season_matches_filter(
			(string) ( $row['season'] ?? '' ),
			$season_type
		)) {
			continue;
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
	if (!isset($GLOBALS['intersoccer_pcs_course_day_slug_memo'])
		|| !is_array($GLOBALS['intersoccer_pcs_course_day_slug_memo'])) {
		$GLOBALS['intersoccer_pcs_course_day_slug_memo'] = [];
	}
	$memo_key = strtolower($raw);
	if (array_key_exists($memo_key, $GLOBALS['intersoccer_pcs_course_day_slug_memo'])) {
		return (string) $GLOBALS['intersoccer_pcs_course_day_slug_memo'][ $memo_key ];
	}
	if (function_exists('intersoccer_roster_facet_for_grouping')) {
		$slug = intersoccer_roster_facet_for_grouping($raw, 'pa_course-day');
		$GLOBALS['intersoccer_pcs_course_day_slug_memo'][ $memo_key ] = $slug;

		return $slug;
	}
	if (function_exists('intersoccer_normalize_weekday_token')) {
		$day = intersoccer_normalize_weekday_token($raw);
		$slug = $day ? strtolower((string) $day) : strtolower($raw);
		$GLOBALS['intersoccer_pcs_course_day_slug_memo'][ $memo_key ] = $slug;

		return $slug;
	}

	$slug = strtolower($raw);
	$GLOBALS['intersoccer_pcs_course_day_slug_memo'][ $memo_key ] = $slug;

	return $slug;
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
	$season = intersoccer_player_course_status_canonical_season_label((string) ( $row['season'] ?? '' ));
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
 * Booking display label from a {@see intersoccer_player_course_status_build_booking_record()} array.
 *
 * @param array<string,mixed> $booking
 */
function intersoccer_player_course_status_booking_label_from_record(array $booking) {
	$product = trim((string) ($booking['product'] ?? ''));
	$venue = trim((string) ($booking['venue'] ?? ''));
	$course_day = trim((string) ($booking['course_day'] ?? ''));
	$times = trim((string) ($booking['times'] ?? ''));
	$season = intersoccer_player_course_status_canonical_season_label((string) ( $booking['season'] ?? '' ));
	$start = trim((string) ($booking['start_date'] ?? ''));
	$end = trim((string) ($booking['end_date'] ?? ''));
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
 * @param array<int,array<string,mixed>> $scoped_rows
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_course_status_build_booking_records_map(array $scoped_rows) {
	$map = [];
	foreach ($scoped_rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$item_id = (int) ($row['order_item_id'] ?? 0);
		if ($item_id <= 0 || isset($map[ $item_id ])) {
			continue;
		}
		$map[ $item_id ] = intersoccer_player_course_status_build_booking_record($row);
	}

	return $map;
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
		'season' => intersoccer_player_course_status_canonical_season_label((string) ( $row['season'] ?? '' )),
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
/**
 * Keep course roster rows only (before batched priming).
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_course_status_filter_raw_course_rows(array $rows) {
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
		$out[] = $row;
	}

	return $out;
}

/**
 * Warm post caches and product-type memo before course-kind filtering.
 *
 * @param array<int,array<string,mixed>> $rows
 */
function intersoccer_player_course_status_prime_roster_product_types(array $rows) {
	$post_ids = [];
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$product_id = (int) ( $row['product_id'] ?? 0 );
		if ($product_id > 0) {
			$post_ids[ $product_id ] = $product_id;
		}
		$variation_id = (int) ( $row['variation_id'] ?? 0 );
		if ($variation_id > 0) {
			$post_ids[ $variation_id ] = $variation_id;
		}
	}
	if (!empty($post_ids)) {
		$chunk_size = function_exists('intersoccer_roster_report_batch_chunk_size')
			? intersoccer_roster_report_batch_chunk_size()
			: 500;
		foreach (array_chunk(array_values($post_ids), $chunk_size) as $chunk) {
			if (function_exists('_prime_post_caches')) {
				_prime_post_caches($chunk, false, true);
			} elseif (function_exists('update_meta_cache')) {
				update_meta_cache('post', $chunk);
			}
		}
	}
	if (function_exists('intersoccer_rr_winback_prime_product_types_from_rows')) {
		intersoccer_rr_winback_prime_product_types_from_rows($rows);
	}
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
 * Clears roster-query failure memo before {@see intersoccer_player_course_status_load_course_roster_rows()}.
 */
function intersoccer_player_course_status_reset_roster_query_state() {
	unset($GLOBALS['intersoccer_player_course_status_roster_query_failed']);
}

/**
 * Whether the last roster load hit a DB error (see reset/consume in generate).
 */
function intersoccer_player_course_status_roster_query_failed() {
	return !empty($GLOBALS['intersoccer_player_course_status_roster_query_failed']);
}

/**
 * @return array{code:string,message:string}
 */
function intersoccer_player_course_status_roster_query_failed_warning() {
	return [
		'code' => 'roster_query_failed',
		'message' => __(
			'The course roster query failed, so booked counts may be wrong. Check the PHP error log.',
			'intersoccer-reports-rosters'
		),
	];
}

/**
 * @param string $wpdb_last_error
 */
function intersoccer_player_course_status_log_roster_query_error($wpdb_last_error) {
	$message = 'InterSoccer RR player course status: roster query failed: ' . (string) $wpdb_last_error;
	if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
		if (!isset($GLOBALS['intersoccer_test_player_course_status_error_log'])) {
			$GLOBALS['intersoccer_test_player_course_status_error_log'] = [];
		}
		$GLOBALS['intersoccer_test_player_course_status_error_log'][] = $message;

		return;
	}
	error_log($message);
}

/**
 * Strict-mode-safe DATE prefilter for course roster SQL (year window [Y-1, Y+1]).
 *
 * @param int $year Report year Y.
 * @return array{sql:string,params:list<string>}
 */
function intersoccer_player_course_status_course_roster_year_prefilter_clause($year) {
	$year = (int) $year;
	$ymin = $year - 1;
	$ymax = $year + 1;
	$date_min = sprintf('%d-01-01', $ymin);
	$date_max = sprintf('%d-12-31', $ymax);

	return [
		'sql' => ' AND (
		start_date IS NULL OR start_date = \'1970-01-01\' OR start_date BETWEEN %s AND %s
		OR end_date IS NULL OR end_date = \'1970-01-01\' OR end_date BETWEEN %s AND %s
	)',
		'params' => [$date_min, $date_max, $date_min, $date_max],
	];
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

	$sql = 'SELECT ' . implode(', ', $select) . " FROM {$table} WHERE 1=1";
	$params = [];
	if (in_array('is_placeholder', (array) $columns, true)) {
		$sql .= ' AND (is_placeholder = 0 OR is_placeholder IS NULL)';
	}
	$prefilter = intersoccer_player_course_status_course_roster_year_prefilter_clause($year);
	$sql .= $prefilter['sql'];
	$params = array_merge($params, $prefilter['params']);

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
	if ($wpdb->last_error !== '') {
		$GLOBALS['intersoccer_player_course_status_roster_query_failed'] = true;
		intersoccer_player_course_status_log_roster_query_error($wpdb->last_error);
	}
	if (!is_array($rows)) {
		return [];
	}

	intersoccer_player_course_status_prime_roster_product_types($rows);

	$course_rows = intersoccer_player_course_status_filter_raw_course_rows($rows);
	unset($rows);

	return intersoccer_player_course_status_prepare_roster_rows($course_rows);
}

/**
 * @param array<int,array<string,mixed>> $scoped_rows Active rows in year/season/girls scope.
 * @return array<string,array<int,array<string,mixed>>>
 */
function intersoccer_player_course_status_build_booking_index(array $scoped_rows, array $booking_records = []) {
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
		$item_id = (int) ($row['order_item_id'] ?? 0);
		$booking = ( $item_id > 0 && isset($booking_records[ $item_id ]) )
			? $booking_records[ $item_id ]
			: intersoccer_player_course_status_build_booking_record($row);
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
function intersoccer_player_course_status_build_filter_options(array $scoped_rows, array $booking_records = []) {
	$venues = [];
	$course_days = [];
	$age_groups = [];
	$seasons = [];

	foreach ($scoped_rows as $row) {
		if (!is_array($row) || !intersoccer_player_course_status_row_is_active_booking($row)) {
			continue;
		}
		$item_id = (int) ($row['order_item_id'] ?? 0);
		$booking = ( $item_id > 0 && isset($booking_records[ $item_id ]) )
			? $booking_records[ $item_id ]
			: intersoccer_player_course_status_build_booking_record($row);
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
			$canonical = intersoccer_player_course_status_canonical_season_label($season);
			if ($canonical !== '') {
				$option = intersoccer_player_course_status_season_filter_option_label($canonical);
				if ($option !== '') {
					$seasons[ strtolower($option) ] = $option;
				}
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
	if (isset($GLOBALS['intersoccer_rr_user_account_email_cache'][ $user_id ])) {
		return (string) $GLOBALS['intersoccer_rr_user_account_email_cache'][ $user_id ];
	}

	return '';
}

$__intersoccer_pcs_cache_gate = dirname(__FILE__) . '/player-course-status-cache-gate.php';
if (file_exists($__intersoccer_pcs_cache_gate)) {
	require_once $__intersoccer_pcs_cache_gate;
}

/**
 * Transient TTL in seconds for cached full results.
 */
function intersoccer_player_course_status_cache_ttl() {
	$default = defined('HOUR_IN_SECONDS') ? 12 * HOUR_IN_SECONDS : 43200;
	$ttl = apply_filters('intersoccer_player_course_status_cache_ttl', $default);

	return max(1, (int) $ttl);
}

/**
 * Max stored transient payload bytes (base64 gzip blob) before skipping cache write.
 */
function intersoccer_player_course_status_cache_max_bytes() {
	if (isset($GLOBALS['intersoccer_test_pcs_cache_max_bytes'])) {
		return max(1, (int) $GLOBALS['intersoccer_test_pcs_cache_max_bytes']);
	}
	$max = apply_filters('intersoccer_player_course_status_cache_max_bytes', 5 * 1024 * 1024);

	return max(1024, (int) $max);
}

/**
 * Cheap data-version marker (roster volume + latest shop order touch).
 */
function intersoccer_player_course_status_cache_data_version($reset_memo = false) {
	static $memo = null;
	if ($reset_memo) {
		$memo = null;

		return '';
	}
	if (isset($GLOBALS['intersoccer_test_pcs_cache_data_version_override'])) {
		return (string) $GLOBALS['intersoccer_test_pcs_cache_data_version_override'];
	}
	if ($memo !== null) {
		return $memo;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'intersoccer_rosters';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$max_id = $wpdb->get_var("SELECT MAX(id) FROM {$table}");
	$order_touch = '';
	if (function_exists('intersoccer_roster_report_uses_hpos_orders_table')
		&& intersoccer_roster_report_uses_hpos_orders_table()) {
		$orders_table = $wpdb->prefix . 'wc_orders';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$order_touch = $wpdb->get_var(
			"SELECT MAX(date_updated_gmt) FROM {$orders_table} WHERE type = 'shop_order'"
		);
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$order_touch = $wpdb->get_var(
			"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = 'shop_order'"
		);
	}
	$memo = implode('|', [
		(string) $count,
		(string) $max_id,
		(string) $order_touch,
	]);

	return $memo;
}

/**
 * @param int|array<string,mixed> $year_or_filters Calendar year or normalized filters (year key used).
 */
function intersoccer_player_course_status_cache_transient_key($year_or_filters) {
	$year = is_array($year_or_filters)
		? (int) ( $year_or_filters['year'] ?? 0 )
		: (int) $year_or_filters;
	if ($year < 2000) {
		$year = (int) date('Y');
	}
	$payload = [
		'schema' => intersoccer_player_course_status_cache_schema_version(),
		'year' => $year,
		'data' => intersoccer_player_course_status_cache_data_version(),
	];
	$json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);

	return 'intersoccer_pcs_' . md5((string) $json);
}

/**
 * @param string $key
 * @return mixed|false
 */
function intersoccer_player_course_status_transient_get($key) {
	if (isset($GLOBALS['intersoccer_test_pcs_transient_store'])
		&& is_array($GLOBALS['intersoccer_test_pcs_transient_store'])
		&& array_key_exists($key, $GLOBALS['intersoccer_test_pcs_transient_store'])) {
		return $GLOBALS['intersoccer_test_pcs_transient_store'][ $key ];
	}

	return get_transient($key);
}

/**
 * @param string $key
 * @param mixed  $value
 * @param int    $ttl
 */
function intersoccer_player_course_status_transient_set($key, $value, $ttl) {
	if (isset($GLOBALS['intersoccer_test_pcs_transient_store'])
		&& is_array($GLOBALS['intersoccer_test_pcs_transient_store'])) {
		$GLOBALS['intersoccer_test_pcs_transient_store'][ $key ] = $value;
	}

	return set_transient($key, $value, $ttl);
}

/**
 * @param string $key
 */
function intersoccer_player_course_status_transient_delete($key) {
	if (isset($GLOBALS['intersoccer_test_pcs_transient_store'])
		&& is_array($GLOBALS['intersoccer_test_pcs_transient_store'])) {
		unset($GLOBALS['intersoccer_test_pcs_transient_store'][ $key ]);
	}

	return delete_transient($key);
}

/**
 * @param array<string,mixed> $booking
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_cache_restore_booking(array $booking) {
	if (!is_array($booking)) {
		return $booking;
	}
	if (!isset($booking['label']) || (string) $booking['label'] === '') {
		$booking['label'] = intersoccer_player_course_status_booking_label_from_record($booking);
	}

	return $booking;
}

/**
 * @param array<string,mixed> $booking
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_cache_slim_booking(array $booking) {
	if (!is_array($booking)) {
		return $booking;
	}
	unset($booking['label']);

	return $booking;
}

/**
 * @param array<string,mixed> $result Full generate() result.
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_cache_slim_result(array $result) {
	$slim = $result;
	if (isset($slim['rows']) && is_array($slim['rows'])) {
		$rows = [];
		foreach ($slim['rows'] as $row) {
			if (!is_array($row)) {
				$rows[] = $row;
				continue;
			}
			unset($row['bookings_label'], $row['booking_status_label']);
			if (isset($row['player']) && is_array($row['player'])) {
				unset($row['player']['name']);
			}
			if (isset($row['bookings']) && is_array($row['bookings'])) {
				$bookings = [];
				foreach ($row['bookings'] as $booking) {
					$bookings[] = intersoccer_player_course_status_cache_slim_booking($booking);
				}
				$row['bookings'] = $bookings;
			}
			if (isset($row['pending_bookings']) && is_array($row['pending_bookings'])) {
				$pending = [];
				foreach ($row['pending_bookings'] as $booking) {
					$pending[] = intersoccer_player_course_status_cache_slim_booking($booking);
				}
				$row['pending_bookings'] = $pending;
			}
			$rows[] = $row;
		}
		$slim['rows'] = $rows;
	}
	if (isset($slim['unmatched_bookings']) && is_array($slim['unmatched_bookings'])) {
		$unmatched = [];
		foreach ($slim['unmatched_bookings'] as $booking) {
			$unmatched[] = intersoccer_player_course_status_cache_slim_booking($booking);
		}
		$slim['unmatched_bookings'] = $unmatched;
	}

	return $slim;
}

/**
 * @param array<string,mixed> $slim Slim stored payload.
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_cache_expand_result(array $slim) {
	$full = $slim;
	if (isset($full['rows']) && is_array($full['rows'])) {
		$rows = [];
		foreach ($full['rows'] as $row) {
			if (!is_array($row)) {
				$rows[] = $row;
				continue;
			}
			$player = isset($row['player']) && is_array($row['player']) ? $row['player'] : [];
			$first = (string) ($player['first_name'] ?? '');
			$last = (string) ($player['last_name'] ?? '');
			$player_out = [
				'name' => trim($first . ' ' . $last),
				'first_name' => $first,
				'last_name' => $last,
				'player_index' => isset($player['player_index']) ? (int) $player['player_index'] : null,
				'key' => (string) ($player['key'] ?? ''),
				'tier' => (string) ($player['tier'] ?? ''),
			];
			$bookings = [];
			if (isset($row['bookings']) && is_array($row['bookings'])) {
				foreach ($row['bookings'] as $booking) {
					$bookings[] = intersoccer_player_course_status_cache_restore_booking($booking);
				}
			}
			$pending = [];
			if (isset($row['pending_bookings']) && is_array($row['pending_bookings'])) {
				foreach ($row['pending_bookings'] as $booking) {
					$pending[] = intersoccer_player_course_status_cache_restore_booking($booking);
				}
			}
			$labels = [];
			foreach ($bookings as $booking) {
				if (is_array($booking) && !empty($booking['label'])) {
					$labels[] = (string) $booking['label'];
				}
			}
			$status = isset($row['booking_status']) ? (string) $row['booking_status'] : 'not_booked';
			$rows[] = [
				'player' => $player_out,
				'parent' => isset($row['parent']) && is_array($row['parent']) ? $row['parent'] : [],
				'dob' => array_key_exists('dob', $row) ? $row['dob'] : null,
				'dob_restricted' => !empty($row['dob_restricted']),
				'age' => array_key_exists('age', $row) ? $row['age'] : null,
				'booked' => !empty($row['booked']),
				'booking_status' => $status,
				'booking_status_label' => intersoccer_player_course_status_booking_status_label($status),
				'bookings' => $bookings,
				'pending_bookings' => $pending,
				'bookings_label' => implode('; ', $labels),
			];
		}
		$full['rows'] = $rows;
	}
	if (isset($full['unmatched_bookings']) && is_array($full['unmatched_bookings'])) {
		$unmatched = [];
		foreach ($full['unmatched_bookings'] as $booking) {
			$unmatched[] = intersoccer_player_course_status_cache_restore_booking($booking);
		}
		$full['unmatched_bookings'] = $unmatched;
	}

	return $full;
}

/**
 * @param array<string,mixed> $result
 * @return string|false Base64-encoded gzcompressed serialized slim result.
 */
function intersoccer_player_course_status_cache_encode_stored_payload(array $result) {
	$slim = intersoccer_player_course_status_cache_slim_result($result);
	$serialized = maybe_serialize($slim);
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
 * @param string $stored Base64 gzip blob.
 * @return array<string,mixed>|null
 */
function intersoccer_player_course_status_cache_decode_stored_payload($stored) {
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
	if (!is_array($decoded)) {
		return null;
	}

	return intersoccer_player_course_status_cache_expand_result($decoded);
}

/**
 * @param array<string,mixed> $base Year-base dataset from build_year_base_dataset().
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_cache_slim_base(array $base) {
	$slim_records = [];
	if (isset($base['booking_records']) && is_array($base['booking_records'])) {
		foreach ($base['booking_records'] as $item_id => $booking) {
			if (!is_array($booking)) {
				continue;
			}
			$slim_records[ (int) $item_id ] = intersoccer_player_course_status_cache_slim_booking($booking);
		}
	}

	return [
		'year' => (int) ( $base['year'] ?? 0 ),
		'players' => isset($base['players']) && is_array($base['players']) ? $base['players'] : [],
		'year_rows' => isset($base['year_rows']) && is_array($base['year_rows']) ? $base['year_rows'] : [],
		'booking_records' => $slim_records,
		'warnings' => isset($base['warnings']) && is_array($base['warnings']) ? $base['warnings'] : [],
	];
}

/**
 * @param array<string,mixed> $slim
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_cache_expand_base(array $slim) {
	$records = [];
	if (isset($slim['booking_records']) && is_array($slim['booking_records'])) {
		foreach ($slim['booking_records'] as $item_id => $booking) {
			if (!is_array($booking)) {
				continue;
			}
			$records[ (int) $item_id ] = intersoccer_player_course_status_cache_restore_booking($booking);
		}
	}

	return [
		'year' => (int) ( $slim['year'] ?? 0 ),
		'players' => isset($slim['players']) && is_array($slim['players']) ? $slim['players'] : [],
		'year_rows' => isset($slim['year_rows']) && is_array($slim['year_rows']) ? $slim['year_rows'] : [],
		'booking_records' => $records,
		'warnings' => isset($slim['warnings']) && is_array($slim['warnings']) ? $slim['warnings'] : [],
	];
}

/**
 * @param array<int,array<string,mixed>>     $scoped_rows
 * @param array<int,array<string,mixed>>     $booking_records_by_item
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_course_status_booking_records_for_scoped_rows(array $scoped_rows, array $booking_records_by_item) {
	$map = [];
	foreach ($scoped_rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$item_id = (int) ( $row['order_item_id'] ?? 0 );
		if ($item_id > 0 && isset($booking_records_by_item[ $item_id ]) && is_array($booking_records_by_item[ $item_id ])) {
			$map[ $item_id ] = $booking_records_by_item[ $item_id ];
		}
	}

	return $map;
}

/**
 * @param array<string,mixed> $base
 * @return string|false
 */
function intersoccer_player_course_status_cache_encode_base_payload(array $base) {
	$slim = intersoccer_player_course_status_cache_slim_base($base);
	$serialized = maybe_serialize($slim);
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
function intersoccer_player_course_status_cache_decode_base_payload($stored) {
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
	if (!is_array($decoded)) {
		return null;
	}
	$year = (int) ( $decoded['year'] ?? 0 );
	if ($year < 2000) {
		return null;
	}

	return intersoccer_player_course_status_cache_expand_base($decoded);
}

/**
 * @param int $year
 * @return array<string,mixed>|null
 */
function intersoccer_player_course_status_cache_read_base($year) {
	$key = intersoccer_player_course_status_cache_transient_key((int) $year);
	$raw = intersoccer_player_course_status_transient_get($key);
	if ($raw === false || $raw === null) {
		return null;
	}
	if (!is_string($raw)) {
		intersoccer_player_course_status_transient_delete($key);

		return null;
	}
	$decoded = intersoccer_player_course_status_cache_decode_base_payload($raw);
	if (!is_array($decoded)) {
		intersoccer_player_course_status_transient_delete($key);

		return null;
	}

	return $decoded;
}

/**
 * @param int                 $year
 * @param array<string,mixed> $base
 */
function intersoccer_player_course_status_cache_write_base($year, array $base) {
	if (!empty($base['warnings']) && is_array($base['warnings'])) {
		return;
	}
	$key = intersoccer_player_course_status_cache_transient_key((int) $year);
	$stored = intersoccer_player_course_status_cache_encode_base_payload($base);
	if ($stored === false) {
		return;
	}
	$size = strlen($stored);
	$max = intersoccer_player_course_status_cache_max_bytes();
	if ($size > $max) {
		return;
	}
	if (isset($GLOBALS['intersoccer_test_pcs_cache_last_stored_bytes'])) {
		$GLOBALS['intersoccer_test_pcs_cache_last_stored_bytes'] = $size;
	}
	$ttl = intersoccer_player_course_status_cache_ttl();
	intersoccer_player_course_status_transient_set($key, $stored, $ttl);
}

/**
 * Whether the current request asks to bypass cache read (valid nonce + capability).
 */
function intersoccer_player_course_status_cache_refresh_requested() {
	if (empty($_REQUEST['pcs_refresh'])) {
		return false;
	}
	if (!current_user_can('manage_options')) {
		return false;
	}
	$nonce = isset($_REQUEST['_pcs_nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_pcs_nonce'])) : '';
	if (array_key_exists('intersoccer_test_pcs_verify_refresh_nonce', $GLOBALS)) {
		return (bool) $GLOBALS['intersoccer_test_pcs_verify_refresh_nonce'];
	}
	$verified = apply_filters('intersoccer_player_course_status_verify_refresh_nonce', null, $nonce);
	if ($verified !== null) {
		return (bool) $verified;
	}

	return (bool) wp_verify_nonce($nonce, 'intersoccer_pcs_refresh');
}

/**
 * Admin URL with pcs_refresh + nonce for the courses report (Finn UI).
 *
 * @param array<string,mixed> $args Extra query args (merged over current filters).
 */
function intersoccer_player_course_status_refresh_url(array $args = []) {
	$base = [
		'page' => 'intersoccer-player-course-status',
	];
	if (!empty($args)) {
		$filters = intersoccer_player_course_status_normalize_filters($args);
	} else {
		$req = intersoccer_player_course_status_request_filters();
		unset($req['paged']);
		$filters = $req;
	}
	$query = array_merge($base, $filters, $args);
	$query['pcs_refresh'] = 1;
	$url = add_query_arg($query, admin_url('admin.php'));

	return wp_nonce_url($url, 'intersoccer_pcs_refresh', '_pcs_nonce');
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
	$booking_status = isset($filters['booking_status']) ? (string) $filters['booking_status'] : '';
	if (!in_array($booking_status, ['', 'booked', 'pending_payment', 'on_hold', 'not_booked'], true)) {
		$booking_status = '';
	}

	return [
		'year' => $year,
		'season_type' => isset($filters['season_type']) ? sanitize_text_field((string) $filters['season_type']) : '',
		'girls_mode' => $girls_mode,
		'booked' => $booked,
		'booking_status' => $booking_status,
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
 * @param array<string,mixed> $options Optional: force_cache_refresh (bool).
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_generate(array $filters, array $options = []) {
	$memo_started = false;
	if (function_exists('intersoccer_rr_product_type_memo_begin')) {
		intersoccer_rr_product_type_memo_begin();
		$memo_started = true;
	}
	try {
		$normalized = intersoccer_player_course_status_normalize_filters($filters);
		$year = (int) $normalized['year'];
		$force_refresh = !empty($options['force_cache_refresh'])
			|| intersoccer_player_course_status_cache_refresh_requested();

		$base = null;
		if (intersoccer_player_course_status_cache_enabled() && !$force_refresh) {
			$base = intersoccer_player_course_status_cache_read_base($year);
		}
		if (!is_array($base)) {
			$base = intersoccer_player_course_status_build_year_base_dataset($year, true);
			if (intersoccer_player_course_status_cache_enabled()) {
				intersoccer_player_course_status_cache_write_base($year, $base);
			}
		}

		return intersoccer_player_course_status_apply_filters_to_base($base, $normalized);
	} finally {
		if ($memo_started && function_exists('intersoccer_rr_product_type_memo_end')) {
			intersoccer_rr_product_type_memo_end();
		}
	}
}

/**
 * Load players + prepared course roster rows for a calendar year (no season/status filters).
 *
 * @param int  $year
 * @param bool $track_inner_call Increment test inner-call counter when true.
 * @return array{year:int,players:array<int,array<string,mixed>>,year_rows:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>}
 */
function intersoccer_player_course_status_build_year_base_dataset($year, $track_inner_call = true) {
	if ($track_inner_call && isset($GLOBALS['intersoccer_test_pcs_generate_inner_call_count'])) {
		$GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] = (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] + 1;
	}
	$year = (int) $year;
	$warnings = [];
	intersoccer_player_course_status_reset_run_memos();

	if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING
		&& isset($GLOBALS['intersoccer_test_player_course_status_players'])
		&& is_array($GLOBALS['intersoccer_test_player_course_status_players'])) {
		$players = $GLOBALS['intersoccer_test_player_course_status_players'];
	} else {
		$players = function_exists('intersoccer_player_course_status_load_players')
			? intersoccer_player_course_status_load_players()
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
		$fixture_rows = $GLOBALS['intersoccer_test_player_course_status_roster_rows'];
		intersoccer_player_course_status_prime_roster_product_types($fixture_rows);
		$year_rows = intersoccer_player_course_status_prepare_roster_rows(
			intersoccer_player_course_status_filter_raw_course_rows($fixture_rows)
		);
	} else {
		$year_rows = intersoccer_player_course_status_load_course_roster_rows($year);
		if (intersoccer_player_course_status_roster_query_failed()) {
			$warnings[] = intersoccer_player_course_status_roster_query_failed_warning();
		}
	}

	$booking_records = intersoccer_player_course_status_build_booking_records_map($year_rows);

	return [
		'year' => $year,
		'players' => $players,
		'year_rows' => $year_rows,
		'booking_records' => $booking_records,
		'warnings' => $warnings,
	];
}

/**
 * Apply report filters to a year-base dataset (PHP-only; no roster reload).
 *
 * @param array<string,mixed> $base
 * @param array<string,mixed> $filters Normalized filters.
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_apply_filters_to_base(array $base, array $filters) {
	$filters = intersoccer_player_course_status_normalize_filters($filters);
	$year = (int) ( $base['year'] ?? $filters['year'] );
	$players = isset($base['players']) && is_array($base['players']) ? $base['players'] : [];
	$year_rows = isset($base['year_rows']) && is_array($base['year_rows']) ? $base['year_rows'] : [];
	$warnings = isset($base['warnings']) && is_array($base['warnings']) ? $base['warnings'] : [];
	$all_booking_records = isset($base['booking_records']) && is_array($base['booking_records'])
		? $base['booking_records']
		: [];

	$scoped_rows = intersoccer_player_course_status_filter_scoped_rows(
		$year_rows,
		$year,
		$filters['season_type'],
		$filters['girls_mode']
	);

	$booking_records = intersoccer_player_course_status_booking_records_for_scoped_rows($scoped_rows, $all_booking_records);
	$filter_options = intersoccer_player_course_status_build_filter_options($scoped_rows, $booking_records);
	$index = intersoccer_player_course_status_build_booking_index($scoped_rows, $booking_records);
	$pending_index = intersoccer_player_course_status_build_pending_booking_index($scoped_rows, $booking_records);

	$matched_item_ids = [];
	$output_rows = [];
	$booked_total = 0;
	$not_booked_total = 0;
	$pending_payment_total = 0;
	$on_hold_total = 0;
	$can_view_dob = intersoccer_player_course_status_user_can_view_dob();

	foreach ($players as $player) {
		if (!is_array($player)) {
			continue;
		}
		$match = intersoccer_player_course_status_match_player_bookings($player, $index);
		$pending_match = intersoccer_player_course_status_match_player_bookings($player, $pending_index);
		$bookings = $match['bookings'];
		$pending_bookings = $pending_match['bookings'];
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

		$pending_active_for_filters = [];
		foreach ($pending_bookings as $booking) {
			if (!intersoccer_player_course_status_booking_matches_filters($booking, $filters)) {
				continue;
			}
			$pending_active_for_filters[] = $booking;
		}

		if ($is_booked) {
			$booking_status = 'booked';
		} elseif (!empty($pending_active_for_filters)) {
			$booking_status = intersoccer_player_course_status_booking_status_from_pending_matches($pending_active_for_filters);
		} else {
			$booking_status = 'not_booked';
		}

		if ($is_booked) {
			$booked_total++;
		} else {
			$not_booked_total++;
		}
		if ($booking_status === 'pending_payment') {
			$pending_payment_total++;
		} elseif ($booking_status === 'on_hold') {
			$on_hold_total++;
		}

		$tier = $match['tier'];
		$key_used = $match['key'];
		if ($tier === '' && $key_used === '' && !empty($pending_bookings)) {
			$tier = $pending_match['tier'];
			$key_used = $pending_match['key'];
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
				'key' => $key_used,
				'tier' => $tier,
			],
			'parent' => [
				'id' => $user_id,
				'email' => $email,
			],
			'dob' => $can_view_dob && $dob_raw !== '' ? $dob_raw : null,
			'dob_restricted' => !$can_view_dob,
			'age' => $age,
			'booked' => $is_booked,
			'booking_status' => $booking_status,
			'booking_status_label' => intersoccer_player_course_status_booking_status_label($booking_status),
			'bookings' => $bookings,
			'pending_bookings' => $pending_bookings,
			'bookings_label' => implode('; ', $labels),
		];

		if ($filters['booked'] === 'booked' && !$is_booked) {
			continue;
		}
		if ($filters['booked'] === 'not_booked' && $is_booked) {
			continue;
		}
		if ($filters['booking_status'] !== '' && $booking_status !== $filters['booking_status']) {
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
		$booking = isset($booking_records[ $item_id ])
			? $booking_records[ $item_id ]
			: intersoccer_player_course_status_build_booking_record($row);
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
			'pending_payment' => $pending_payment_total,
			'on_hold' => $on_hold_total,
			'unmatched_bookings' => count($unmatched),
		],
		'warnings' => $warnings,
	];
}

/**
 * @param array<string,mixed> $filters
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_generate_inner(array $filters) {
	$filters = intersoccer_player_course_status_normalize_filters($filters);
	$year = (int) $filters['year'];
	$base = intersoccer_player_course_status_build_year_base_dataset($year, true);

	return intersoccer_player_course_status_apply_filters_to_base($base, $filters);
}

/**
 * @return array<string,mixed>
 */
function intersoccer_player_course_status_request_filters() {
	$year = isset($_GET['year']) ? absint($_GET['year']) : (int) date('Y');
	$season_type = isset($_GET['season_type']) ? sanitize_text_field(wp_unslash($_GET['season_type'])) : '';
	$girls_mode = isset($_GET['girls_mode']) ? sanitize_text_field(wp_unslash($_GET['girls_mode'])) : 'all';
	$booked = isset($_GET['booked']) ? sanitize_text_field(wp_unslash($_GET['booked'])) : 'all';
	$booking_status = isset($_GET['booking_status']) ? sanitize_text_field(wp_unslash($_GET['booking_status'])) : '';
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
		'booking_status' => $booking_status,
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
	$out[] = (string) ($row['booking_status_label'] ?? intersoccer_player_course_status_booking_status_label('not_booked'));
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
	if (!empty($result['warnings']) && is_array($result['warnings'])) {
		$messages = [];
		foreach ($result['warnings'] as $warning) {
			if (!is_array($warning)) {
				continue;
			}
			$msg = isset($warning['message']) ? (string) $warning['message'] : '';
			if ($msg !== '') {
				$messages[] = $msg;
			}
		}
		if (!empty($messages)) {
			wp_die(
				esc_html(implode(' ', $messages)),
				esc_html__('Export blocked', 'intersoccer-reports-rosters'),
				['response' => 500]
			);
		}
	}
	intersoccer_player_course_status_stream_excel($result['rows'], $filters['year']);
}

function intersoccer_render_player_course_status_page() {
	require_once __DIR__ . '/player-course-status-admin.php';
	intersoccer_player_course_status_admin_render_page();
}
