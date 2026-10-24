<?php
/**
 * Frozen PCS generate path from 20bc7c1 (per-filter build, legacy Summer matching).
 * Test-only — do not load in production.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

function pcs_legacy_20bc7c1_season_matches_filter($row_season, $season_type) {
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
	if ($extracted !== null && $extracted === $season_type) {
		$row_type = function_exists('intersoccer_extract_season_type')
			? intersoccer_extract_season_type($row_canonical)
			: null;
		if ($extracted === $row_type) {
			return true;
		}

		return stripos($row_canonical, $season_type) !== false;
	}

	return stripos($row_canonical, $season_type) !== false;
}

function pcs_legacy_20bc7c1_filter_scoped_rows(array $rows, $year, $season_type = '', $girls_mode = 'all') {
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
		if ($season_type !== '' && !pcs_legacy_20bc7c1_season_matches_filter(
			(string) ( $row['season'] ?? '' ),
			$season_type
		)) {
			continue;
		}
		$out[] = $row;
	}

	return $out;
}

function pcs_legacy_20bc7c1_generate(array $filters) {
	$filters = intersoccer_player_course_status_normalize_filters($filters);
	$year = (int) $filters['year'];
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
		$raw_rows = intersoccer_player_course_status_prepare_roster_rows(
			intersoccer_player_course_status_filter_raw_course_rows($fixture_rows)
		);
	} else {
		$raw_rows = intersoccer_player_course_status_load_course_roster_rows($year);
		if (intersoccer_player_course_status_roster_query_failed()) {
			$warnings[] = intersoccer_player_course_status_roster_query_failed_warning();
		}
	}
	$scoped_rows = pcs_legacy_20bc7c1_filter_scoped_rows(
		$raw_rows,
		$year,
		$filters['season_type'],
		$filters['girls_mode']
	);
	unset($raw_rows);

	$booking_records = intersoccer_player_course_status_build_booking_records_map($scoped_rows);
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
