<?php
/**
 * Win-back report engine (camps + courses).
 *
 * Stable contract: docs/winback-report-contract.md
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Generates win-back rows from roster history and the upcoming catalogue.
 *
 * Filters (accepted directly by this function):
 * - kind: camp|course|all (default all)
 * - venue: EN venue slug filter (empty = all)
 * - age_group: EN age-group slug filter (empty = all)
 * - lookback_months: int (default 12)
 *
 * @param array<string,mixed> $filters See docs/winback-report-contract.md.
 * @return array{rows:array<int,array<string,mixed>>,unmapped_facets:array<string,array<string,int>>}
 */
function intersoccer_winback_report_engine_generate(array $filters) {
	intersoccer_winback_report_engine_load_dependencies();

		$kind_filter = isset($filters['kind']) ? strtolower((string) $filters['kind']) : 'all';
		if (!in_array($kind_filter, ['camp', 'course', 'all'], true)) {
			$kind_filter = 'all';
		}
		$venue_filter = isset($filters['venue']) ? sanitize_title((string) $filters['venue']) : '';
		$age_filter = isset($filters['age_group']) ? sanitize_title((string) $filters['age_group']) : '';
		$lookback_months = isset($filters['lookback_months']) ? max(1, (int) $filters['lookback_months']) : 12;

		$today = function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d');
		$lookback_start = intersoccer_winback_report_engine_subtract_months($today, $lookback_months);

		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
		if (function_exists('intersoccer_roster_report_reset_order_customer_id_cache')) {
			intersoccer_roster_report_reset_order_customer_id_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_fake_customer_cache')) {
			intersoccer_roster_report_reset_fake_customer_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		unset($GLOBALS['intersoccer_rr_user_billing_phone_cache'], $GLOBALS['intersoccer_rr_user_account_email_cache']);
		if (function_exists('intersoccer_winback_reset_catalogue_target_cache')) {
			intersoccer_winback_reset_catalogue_target_cache();
		}
		if (function_exists('intersoccer_winback_reset_catalogue_enumeration_memo')) {
			intersoccer_winback_reset_catalogue_enumeration_memo();
		}
		if (function_exists('intersoccer_winback_reset_j8_lookup_cache')) {
			intersoccer_winback_reset_j8_lookup_cache();
		}
		if (function_exists('intersoccer_winback_reset_current_course_term_cache')) {
			intersoccer_winback_reset_current_course_term_cache();
		}

		intersoccer_winback_reset_unmapped_facets();
		$catalogue = intersoccer_winback_enumerate_upcoming_catalogue($today);
		$history_rows = intersoccer_winback_report_engine_load_history_rows($lookback_start, $today, $kind_filter);
		if (function_exists('intersoccer_roster_report_prime_order_customer_ids_from_rows')) {
			intersoccer_roster_report_prime_order_customer_ids_from_rows($history_rows);
		}
		if (function_exists('intersoccer_roster_report_prime_line_refund_checks_from_rows')) {
			intersoccer_roster_report_prime_line_refund_checks_from_rows($history_rows);
		}
		if (function_exists('intersoccer_winback_reset_facet_resolution_cache')) {
			intersoccer_winback_reset_facet_resolution_cache();
		}
		if (function_exists('intersoccer_winback_prime_facet_resolution_from_rows')) {
			intersoccer_winback_prime_facet_resolution_from_rows($history_rows);
		}
		if (function_exists('intersoccer_winback_reset_order_created_date_cache')) {
			intersoccer_winback_reset_order_created_date_cache();
		}
		if (function_exists('intersoccer_winback_prime_order_created_dates_from_rows')) {
			intersoccer_winback_prime_order_created_dates_from_rows($history_rows);
		}
		if (function_exists('intersoccer_roster_report_prime_fake_customer_checks')) {
			intersoccer_roster_report_prime_fake_customer_checks(
				intersoccer_roster_report_collect_resolved_customer_ids_from_rows($history_rows)
			);
		}
		$uuid_map = intersoccer_winback_report_engine_load_assigned_player_ids($history_rows);
		$identity_map = new Intersoccer_Roster_Report_Player_Identity_Map();

		foreach ($history_rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row = intersoccer_roster_report_row_with_resolved_customer($row);
			if (intersoccer_roster_report_is_fake_customer((int) $row['customer_id'])) {
				continue;
			}
			$order_item_id = (int) ( $row['order_item_id'] ?? 0 );
			$uuid = $order_item_id > 0 ? (string) ( $uuid_map[ $order_item_id ] ?? '' ) : '';
			$identity_map->register_row($row, $uuid);
		}

		$upcoming = intersoccer_winback_report_engine_load_upcoming_roster_rows($today, $kind_filter, $uuid_map, $identity_map);
		if (function_exists('intersoccer_roster_report_prime_order_customer_ids_from_rows')) {
			intersoccer_roster_report_prime_order_customer_ids_from_rows($upcoming);
		}
		if (function_exists('intersoccer_roster_report_prime_line_refund_checks_from_rows')) {
			intersoccer_roster_report_prime_line_refund_checks_from_rows($upcoming);
		}
		if (function_exists('intersoccer_winback_prime_order_created_dates_from_rows')) {
			intersoccer_winback_prime_order_created_dates_from_rows($upcoming);
		}
		if (function_exists('intersoccer_roster_report_prime_fake_customer_checks')) {
			intersoccer_roster_report_prime_fake_customer_checks(
				intersoccer_roster_report_collect_resolved_customer_ids_from_rows($upcoming)
			);
		}
		intersoccer_winback_report_engine_build_j8_lookup($today, $identity_map);

		$by_player_kind = [];
		$all_rows_by_group = [];

		foreach ($history_rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row = intersoccer_roster_report_row_with_resolved_customer($row);
			$customer_id = (int) $row['customer_id'];
			if (intersoccer_roster_report_is_fake_customer($customer_id)) {
				continue;
			}

			if (!intersoccer_roster_report_row_counts_for_winback($row, 'history')) {
				continue;
			}

			$kind = intersoccer_winback_report_engine_row_kind($row);
			if ($kind === '' || ($kind_filter !== 'all' && $kind_filter !== $kind)) {
				continue;
			}

			$date_bits = intersoccer_winback_resolve_row_event_date($row);
			$event_date = $date_bits['date'];
			$date_source = $date_bits['source'];
			if ($date_source !== 'unknown' && $event_date !== null) {
				if ($event_date < $lookback_start || $event_date > $today) {
					continue;
				}
			}

			$order_item_id = (int) ($row['order_item_id'] ?? 0);
			$uuid = $order_item_id > 0 ? (string) ($uuid_map[$order_item_id] ?? '') : '';
			$key_bits = intersoccer_roster_report_build_player_key($row, $uuid);
			if ($key_bits['key'] === '') {
				continue;
			}

			$venue_slug = intersoccer_winback_normalize_roster_facet_slug(
				(string) ($row['venue'] ?? ''),
				'pa_intersoccer-venues',
				'venue',
				$row
			);
			$age_slug = intersoccer_winback_normalize_roster_facet_slug(
				(string) ($row['age_group'] ?? ''),
				'pa_age-group',
				'age_group',
				$row
			);

			$group_id = $identity_map->group_id($key_bits['key']);
			$entry = [
				'row' => $row,
				'kind' => $kind,
				'event_date' => $event_date,
				'date_source' => $date_source,
				'venue_slug' => $venue_slug,
				'age_slug' => $age_slug,
				'key' => $key_bits['key'],
				'tier' => $key_bits['tier'],
				'group_id' => $group_id,
			];

			$pk = $group_id . '|' . $kind;
			if (!isset($by_player_kind[$pk]) || intersoccer_winback_report_engine_is_later_history($entry, $by_player_kind[$pk])) {
				$by_player_kind[$pk] = $entry;
			}
			$all_rows_by_group[ $group_id ][] = $entry;
		}

		unset($history_rows, $uuid_map);

		$output_customer_ids = [];
		foreach ($by_player_kind as $history_entry) {
			$cid = (int) ( $history_entry['row']['customer_id'] ?? 0 );
			if ($cid > 0) {
				$output_customer_ids[ $cid ] = $cid;
			}
		}
		if (!empty($output_customer_ids) && function_exists('intersoccer_winback_prime_contact_users')) {
			intersoccer_winback_prime_contact_users(array_values($output_customer_ids));
		}

		$output = [];

		foreach ($by_player_kind as $pk => $history) {
			if (!intersoccer_winback_report_engine_last_event_passes_filters($history, $venue_filter, $age_filter)) {
				continue;
			}

			$row = $history['row'];
			$kind = $history['kind'];
			$key = $history['key'];
			$customer_id = (int) $row['customer_id'];

			$rebooked_elsewhere = false;
			$sibling_booked = false;
			$full = false;
			$target = null;
			$status = 'actionable';

			if ($history['date_source'] === 'unknown' || $history['event_date'] === null) {
				$status = 'date_unknown';
			}

			$rebook_bits = intersoccer_winback_detect_rebooked_flags($key, $kind, $history['venue_slug'], $upcoming, $identity_map);
			$rebooked_elsewhere = $rebook_bits['elsewhere'];
			if ($rebook_bits['same_venue']) {
				continue;
			}

			if ($kind === 'course' && intersoccer_winback_report_engine_player_in_current_course_term($key, $kind, $today, $upcoming, $identity_map)) {
				continue;
			}

			$more_targets = [];
			$target_pick = null;
			if ($history['venue_slug'] !== '' && $history['age_slug'] !== '') {
				$resolved = intersoccer_winback_resolve_catalogue_target_and_more(
					$catalogue,
					$kind,
					$history['venue_slug'],
					$history['age_slug'],
					intersoccer_winback_report_engine_row_dob($row)
				);
				$target = $resolved['target'];
				$more_targets = $resolved['more_targets'];
				$full = (bool) $resolved['full'];
				if ($target !== null) {
					$target_pick = ['aged_up' => (bool) $resolved['aged_up']];
				}
			}

			if ($target === null && $status !== 'date_unknown') {
				$status = 'no_upcoming';
				if ($history['age_slug'] !== '' && function_exists('intersoccer_winback_register_unmapped_age_if_not_catalogue_band')) {
					intersoccer_winback_register_unmapped_age_if_not_catalogue_band(
						$history['age_slug'],
						$catalogue,
						$kind,
						$history['venue_slug']
					);
				}
			}

			$sibling_booked = intersoccer_winback_report_engine_sibling_has_upcoming($customer_id, $key, $kind, $upcoming, $identity_map);

			$contact = intersoccer_winback_report_engine_build_contact($customer_id, $row);
			$group_id = (string) ( $history['group_id'] ?? $identity_map->group_id($key) );
			$events_in_lookback = intersoccer_winback_report_engine_count_history_events($group_id, $kind, $all_rows_by_group);

			$output[] = [
				'player' => [
					'name' => trim(intersoccer_roster_report_player_first_name($row) . ' ' . intersoccer_roster_report_player_last_name($row)),
					'key' => $identity_map->best_key($key),
					'tier' => $identity_map->best_tier($key),
				],
				'parent' => [
					'name' => trim((string) ($row['parent_first_name'] ?? '') . ' ' . (string) ($row['parent_last_name'] ?? '')),
					'id' => $customer_id,
				],
				'contact' => $contact,
				'last_event' => [
					'product' => (string) ($row['product_name'] ?? ''),
					'venue' => $history['venue_slug'] !== '' ? $history['venue_slug'] : (string) ($row['venue'] ?? ''),
					'age_group' => $history['age_slug'] !== '' ? $history['age_slug'] : (string) ($row['age_group'] ?? ''),
					'date' => $history['event_date'],
					'date_source' => $history['date_source'],
				],
				'target' => $target,
				'more_targets' => $more_targets,
				'aged_up' => $target_pick ? (bool) $target_pick['aged_up'] : false,
				'sibling_booked' => $sibling_booked,
				'rebooked_elsewhere' => $rebooked_elsewhere,
				'full' => $full,
				'status' => $status,
				'dob' => intersoccer_winback_report_engine_row_dob($row),
				'age' => intersoccer_winback_compute_age_at_date(intersoccer_winback_report_engine_row_dob($row), $today),
				'account_email' => $contact['account_email'] ?? '',
				'events_in_lookback' => $events_in_lookback,
			];
		}

		unset($by_player_kind, $all_rows_by_group, $upcoming, $catalogue);

		return [
			'rows' => $output,
			'unmapped_facets' => intersoccer_winback_get_unmapped_facets(),
		];
	}

if (!function_exists('intersoccer_winback_report_engine_last_event_passes_filters')) {
	/**
	 * Venue / age filters apply to the per-player latest history row only (plan 2.4 step 2).
	 *
	 * @param array<string,mixed> $history_entry Entry from by_player_kind aggregation.
	 */
	function intersoccer_winback_report_engine_last_event_passes_filters(array $history_entry, $venue_filter, $age_filter) {
		$venue_filter = (string) $venue_filter;
		$age_filter = (string) $age_filter;
		if ($venue_filter !== '' && (string) ( $history_entry['venue_slug'] ?? '' ) !== $venue_filter) {
			return false;
		}
		if ($age_filter !== '' && (string) ( $history_entry['age_slug'] ?? '' ) !== $age_filter) {
			return false;
		}

		return true;
	}
}

if (!function_exists('intersoccer_winback_report_engine_load_dependencies')) {
	function intersoccer_winback_report_engine_load_dependencies() {
		$base = dirname(__FILE__) . '/';
		foreach (['roster-report-loader.php', 'winback-report-facets.php', 'winback-catalogue.php'] as $file) {
			$path = $base . $file;
			if (file_exists($path)) {
				require_once $path;
			}
		}
		if (!function_exists('intersoccer_roster_row_matches_listing_kind')) {
			$utils = $base . 'utils.php';
			if (file_exists($utils)) {
				require_once $utils;
			}
		}
	}
}

if (!function_exists('intersoccer_winback_report_engine_load_history_rows')) {
	/**
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_winback_report_engine_load_history_rows($lookback_start, $today, $kind_filter) {
		global $wpdb;
		$table = $wpdb->prefix . 'intersoccer_rosters';
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ($exists !== $table) {
			return [];
		}

		$where = ['(is_placeholder = 0 OR is_placeholder IS NULL)', 'order_item_id > 0'];
		$params = [];

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where);
		if (!empty($params)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results($sql, ARRAY_A);
		}

		if (!is_array($rows)) {
			$rows = [];
		}

		return intersoccer_roster_report_rows_apply_live_order_status($rows);
	}

	/**
	 * @param array<int,array<string,mixed>> $history_rows
	 * @return array<int,string> order_item_id => uuid
	 */
	function intersoccer_winback_report_engine_load_assigned_player_ids(array $history_rows) {
		$ids = [];
		foreach ($history_rows as $row) {
			$oid = (int) ($row['order_item_id'] ?? 0);
			if ($oid > 0) {
				$ids[$oid] = $oid;
			}
		}
		if (empty($ids)) {
			return [];
		}
		global $wpdb;
		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$sql = "SELECT order_item_id, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta
			WHERE meta_key = 'assigned_player_id' AND order_item_id IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results($wpdb->prepare($sql, array_values($ids)), ARRAY_A);
		$map = [];
		foreach ((array) $results as $r) {
			$val = trim((string) ($r['meta_value'] ?? ''));
			if ($val !== '') {
				$map[(int) $r['order_item_id']] = $val;
			}
		}

		return $map;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	function intersoccer_winback_report_engine_row_kind(array $row) {
		if (function_exists('intersoccer_roster_row_matches_listing_kind')) {
			if (intersoccer_roster_row_matches_listing_kind($row, 'course')) {
				return 'course';
			}
			if (intersoccer_roster_row_matches_listing_kind($row, 'camp')) {
				return 'camp';
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 */
	function intersoccer_winback_report_engine_is_later_history(array $a, array $b) {
		$da = $a['event_date'] ?? '';
		$db = $b['event_date'] ?? '';
		if ($da === $db) {
			return (int) ($a['row']['id'] ?? 0) > (int) ($b['row']['id'] ?? 0);
		}
		if ($da === '' || $da === null) {
			return false;
		}
		if ($db === '' || $db === null) {
			return true;
		}

		return strcmp((string) $da, (string) $db) > 0;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_winback_report_engine_load_upcoming_roster_rows($today, $kind_filter, array $uuid_map, $identity_map = null) {
		global $wpdb;
		$table = $wpdb->prefix . 'intersoccer_rosters';
		$where = ['(is_placeholder = 0 OR is_placeholder IS NULL)', 'order_item_id > 0', 'start_date > %s'];
		$params = [$today];
		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = intersoccer_roster_report_rows_apply_live_order_status(
			(array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
		);
		$out = [];
		if (function_exists('intersoccer_winback_prime_facet_resolution_from_rows')) {
			intersoccer_winback_prime_facet_resolution_from_rows($rows, ['venue']);
		}
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row = intersoccer_roster_report_row_with_resolved_customer($row);
			$customer_id = (int) $row['customer_id'];
			if (intersoccer_roster_report_is_fake_customer($customer_id)) {
				continue;
			}
			if (!intersoccer_roster_report_row_counts_for_winback($row, 'upcoming_rebooked')) {
				continue;
			}
			$kind = intersoccer_winback_report_engine_row_kind($row);
			if ($kind === '' || ($kind_filter !== 'all' && $kind_filter !== $kind)) {
				continue;
			}
			$oid = (int) ($row['order_item_id'] ?? 0);
			$uuid = (string) ( $uuid_map[ $oid ] ?? '' );
			if ($identity_map instanceof Intersoccer_Roster_Report_Player_Identity_Map) {
				$identity_map->register_row($row, $uuid);
			}
			$key_bits = intersoccer_roster_report_build_player_key($row, $uuid);
			if ($key_bits['key'] === '') {
				continue;
			}
			$row['player_key'] = $key_bits['key'];
			$row['kind'] = $kind;
			$row['venue_slug'] = intersoccer_winback_normalize_roster_facet_slug(
				(string) ($row['venue'] ?? ''),
				'pa_intersoccer-venues',
				'venue',
				$row
			);
			$out[] = $row;
		}

		return $out;
	}

	function intersoccer_winback_reset_current_course_term_cache() {
		unset($GLOBALS['intersoccer_winback_current_course_term_cache']);
	}

	function intersoccer_winback_reset_j8_lookup_cache() {
		unset($GLOBALS['intersoccer_winback_j8_lookup_built_for']);
		unset($GLOBALS['intersoccer_winback_j8_active_keys']);
		unset($GLOBALS['intersoccer_winback_j8_active_group_ids']);
	}

	/**
	 * Precompute J8 running-course player keys and identity groups (once per engine run).
	 *
	 * @param Intersoccer_Roster_Report_Player_Identity_Map|null $identity_map
	 */
	function intersoccer_winback_report_engine_build_j8_lookup($today, $identity_map = null) {
		$today = (string) $today;
		if (isset($GLOBALS['intersoccer_winback_j8_lookup_built_for'])
			&& $GLOBALS['intersoccer_winback_j8_lookup_built_for'] === $today) {
			return;
		}
		$GLOBALS['intersoccer_winback_j8_active_keys'] = [];
		$GLOBALS['intersoccer_winback_j8_active_group_ids'] = [];
		$rows = intersoccer_winback_report_engine_load_current_course_term_rows($today);
		if (function_exists('intersoccer_roster_report_prime_order_customer_ids_from_rows')) {
			intersoccer_roster_report_prime_order_customer_ids_from_rows($rows);
		}
		if (function_exists('intersoccer_roster_report_prime_line_refund_checks_from_rows')) {
			intersoccer_roster_report_prime_line_refund_checks_from_rows($rows);
		}
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			if (!function_exists('intersoccer_roster_row_matches_listing_kind')
				|| !intersoccer_roster_row_matches_listing_kind($row, 'course')) {
				continue;
			}
			if (!intersoccer_roster_report_row_counts_for_winback($row, 'upcoming_rebooked')) {
				continue;
			}
			$row = intersoccer_roster_report_row_with_resolved_customer($row);
			if ($identity_map instanceof Intersoccer_Roster_Report_Player_Identity_Map) {
				$identity_map->register_row($row, '');
			}
			$key_bits = intersoccer_roster_report_build_player_key($row, '');
			$candidate = $key_bits['key'];
			if ($candidate === '') {
				continue;
			}
			$GLOBALS['intersoccer_winback_j8_active_keys'][ $candidate ] = true;
			if ($identity_map instanceof Intersoccer_Roster_Report_Player_Identity_Map) {
				$group_id = $identity_map->group_id($candidate);
				if ($group_id !== '') {
					$GLOBALS['intersoccer_winback_j8_active_group_ids'][ $group_id ] = true;
				}
			}
		}
		$GLOBALS['intersoccer_winback_j8_lookup_built_for'] = $today;
	}

	/**
	 * SQL for roster rows that may overlap "today" for J8 (placeholders for start/end Y-m-d).
	 *
	 * @param string $table Prefixed intersoccer_rosters table name.
	 */
	function intersoccer_winback_report_engine_current_course_term_sql($table) {
		return "SELECT * FROM {$table} WHERE (is_placeholder = 0 OR is_placeholder IS NULL) AND order_item_id > 0
			AND start_date IS NOT NULL AND start_date <= %s AND (end_date IS NULL OR end_date >= %s)";
	}

	/**
	 * @param array<string,mixed> $row
	 */
	function intersoccer_winback_report_engine_row_in_current_course_term_window(array $row, $today) {
		$start = isset($row['start_date']) ? trim((string) $row['start_date']) : '';
		if (!intersoccer_roster_report_has_real_date($start) || $start > $today) {
			return false;
		}
		$end = isset($row['end_date']) ? trim((string) $row['end_date']) : '';
		if ($end === '' || !intersoccer_roster_report_has_real_date($end)) {
			return true;
		}

		return $end >= $today;
	}

	/**
	 * Running course roster candidates for J8, loaded once per engine run (cached by $today).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_winback_report_engine_load_current_course_term_rows($today) {
		$today = (string) $today;
		if (!isset($GLOBALS['intersoccer_winback_current_course_term_cache'])
			|| !is_array($GLOBALS['intersoccer_winback_current_course_term_cache'])) {
			$GLOBALS['intersoccer_winback_current_course_term_cache'] = [];
		}
		if (array_key_exists($today, $GLOBALS['intersoccer_winback_current_course_term_cache'])) {
			return $GLOBALS['intersoccer_winback_current_course_term_cache'][ $today ];
		}

		if (isset($GLOBALS['intersoccer_test_winback_current_course_term_rows'])
			&& is_array($GLOBALS['intersoccer_test_winback_current_course_term_rows'])) {
			$raw = $GLOBALS['intersoccer_test_winback_current_course_term_rows'];
		} else {
			global $wpdb;
			$table = $wpdb->prefix . 'intersoccer_rosters';
			$sql = intersoccer_winback_report_engine_current_course_term_sql($table);
			$params = [$today, $today];
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$raw = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
		}

		$filtered = [];
		foreach ($raw as $row) {
			if (!is_array($row)) {
				continue;
			}
			if (!intersoccer_winback_report_engine_row_in_current_course_term_window($row, $today)) {
				continue;
			}
			$filtered[] = $row;
		}

		$rows = intersoccer_roster_report_rows_apply_live_order_status($filtered);
		$GLOBALS['intersoccer_winback_current_course_term_cache'][ $today ] = $rows;

		return $rows;
	}

	/**
	 * J8: skip win-back when the player is in a currently running course term.
	 *
	 * @param array<int,array<string,mixed>> $upcoming
	 */
	function intersoccer_winback_report_engine_player_in_current_course_term($player_key, $kind, $today, array $upcoming, $identity_map = null) {
		if ($kind !== 'course' || $player_key === '') {
			return false;
		}
		intersoccer_winback_report_engine_build_j8_lookup($today, $identity_map);
		$active_keys = $GLOBALS['intersoccer_winback_j8_active_keys'] ?? [];
		if (!empty($active_keys[ $player_key ])) {
			return true;
		}
		if ($identity_map instanceof Intersoccer_Roster_Report_Player_Identity_Map) {
			$group_id = $identity_map->group_id($player_key);
			$active_groups = $GLOBALS['intersoccer_winback_j8_active_group_ids'] ?? [];
			if ($group_id !== '' && !empty($active_groups[ $group_id ])) {
				return true;
			}
		}

		return false;
	}

	function intersoccer_winback_prime_contact_users(array $customer_ids) {
		$customer_ids = array_values(array_unique(array_filter(array_map('intval', $customer_ids))));
		if (empty($customer_ids)) {
			return;
		}
		if (function_exists('cache_users')) {
			cache_users($customer_ids);
		}
		if (function_exists('update_meta_cache')) {
			update_meta_cache('user', $customer_ids);
		}
		if (!isset($GLOBALS['intersoccer_rr_user_billing_phone_cache'])
			|| !is_array($GLOBALS['intersoccer_rr_user_billing_phone_cache'])) {
			$GLOBALS['intersoccer_rr_user_billing_phone_cache'] = [];
		}
		if (!isset($GLOBALS['intersoccer_rr_user_account_email_cache'])
			|| !is_array($GLOBALS['intersoccer_rr_user_account_email_cache'])) {
			$GLOBALS['intersoccer_rr_user_account_email_cache'] = [];
		}
		$pending = [];
		foreach ($customer_ids as $customer_id) {
			if (!array_key_exists($customer_id, $GLOBALS['intersoccer_rr_user_billing_phone_cache'])) {
				$pending[] = $customer_id;
			}
		}
		if (!empty($pending)) {
			if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
				foreach ($pending as $customer_id) {
					$seeded = $GLOBALS['intersoccer_test_user_meta'][ $customer_id ]['billing_phone'] ?? '';
					$GLOBALS['intersoccer_rr_user_billing_phone_cache'][ $customer_id ] = trim((string) $seeded);
				}
			} else {
				global $wpdb;
				if (is_object($wpdb) && isset($wpdb->prefix)) {
					foreach (array_chunk($pending, 500) as $chunk) {
						$placeholders = implode(',', array_fill(0, count($chunk), '%d'));
						$sql = "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'billing_phone' AND user_id IN ({$placeholders})";
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$results = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);
						foreach ((array) $results as $result) {
							if (!is_array($result)) {
								continue;
							}
							$uid = (int) ( $result['user_id'] ?? 0 );
							if ($uid > 0) {
								$GLOBALS['intersoccer_rr_user_billing_phone_cache'][ $uid ] = trim((string) ( $result['meta_value'] ?? '' ));
							}
						}
					}
				}
				foreach ($pending as $customer_id) {
					if (!array_key_exists($customer_id, $GLOBALS['intersoccer_rr_user_billing_phone_cache'])) {
						$GLOBALS['intersoccer_rr_user_billing_phone_cache'][ $customer_id ] = '';
					}
				}
			}
		}
		foreach ($customer_ids as $customer_id) {
			if (array_key_exists($customer_id, $GLOBALS['intersoccer_rr_user_account_email_cache'])) {
				continue;
			}
			$account_email = '';
			if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING
				&& isset($GLOBALS['intersoccer_test_get_userdata'][ $customer_id ])) {
				$user = $GLOBALS['intersoccer_test_get_userdata'][ $customer_id ];
				if ($user && !empty($user->user_email)) {
					$account_email = (string) $user->user_email;
				}
			} elseif (function_exists('get_userdata')) {
				$user = get_userdata($customer_id);
				if ($user && !empty($user->user_email)) {
					$account_email = (string) $user->user_email;
				}
			}
			$GLOBALS['intersoccer_rr_user_account_email_cache'][ $customer_id ] = $account_email;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $upcoming
	 */
	function intersoccer_winback_report_engine_sibling_has_upcoming($customer_id, $player_key, $kind, array $upcoming, $identity_map = null) {
		$customer_id = (int) $customer_id;
		if ($customer_id <= 0) {
			return false;
		}
		foreach ($upcoming as $row) {
			if ((int) ($row['customer_id'] ?? 0) !== $customer_id) {
				continue;
			}
			$upcoming_key = (string) ( $row['player_key'] ?? '' );
			if ($upcoming_key === $player_key) {
				continue;
			}
			if ($identity_map instanceof Intersoccer_Roster_Report_Player_Identity_Map
				&& $identity_map->same_player($upcoming_key, $player_key)) {
				continue;
			}
			if (($row['kind'] ?? '') === $kind) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,array<int,array<string,mixed>>> $catalogue
	 * @return string[]
	 */
	function intersoccer_winback_report_engine_catalogue_age_slugs_for_venue(array $catalogue, $kind, $venue_slug) {
		$slugs = [];
		foreach ($catalogue as $key => $list) {
			if (strpos($key, $kind . '|' . $venue_slug . '|') !== 0) {
				continue;
			}
			foreach ($list as $row) {
				$slugs[] = (string) ($row['age_group_slug'] ?? '');
			}
		}

		return array_values(array_unique(array_filter($slugs)));
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{email:string,phone:string,contact_differs:bool,account_email:string}
	 */
	function intersoccer_winback_report_engine_build_contact($customer_id, array $row) {
		$billing_email = trim((string) ($row['parent_email'] ?? ''));
		$billing_phone = trim((string) ($row['parent_phone'] ?? ''));
		$account_email = '';
		if ($customer_id > 0) {
			if (isset($GLOBALS['intersoccer_rr_user_account_email_cache'][ $customer_id ])) {
				$account_email = (string) $GLOBALS['intersoccer_rr_user_account_email_cache'][ $customer_id ];
			} elseif (function_exists('get_userdata')) {
				$user = get_userdata($customer_id);
				if ($user) {
					$account_email = (string) $user->user_email;
				}
			}
		}
		$billing_phone_meta = '';
		if ($customer_id > 0) {
			if (isset($GLOBALS['intersoccer_rr_user_billing_phone_cache'][ $customer_id ])) {
				$billing_phone_meta = (string) $GLOBALS['intersoccer_rr_user_billing_phone_cache'][ $customer_id ];
			} else {
				$billing_phone_meta = trim((string) get_user_meta($customer_id, 'billing_phone', true));
			}
		}
		if ($billing_phone === '' && $billing_phone_meta !== '') {
			$billing_phone = $billing_phone_meta;
		}
		$differs = $account_email !== '' && $billing_email !== '' && strcasecmp($account_email, $billing_email) !== 0;

		return [
			'email' => $billing_email,
			'phone' => $billing_phone,
			'contact_differs' => $differs,
			'account_email' => $account_email,
		];
	}

	/**
	 * @param array<string,array<int,array<string,mixed>>> $all_rows_by_group
	 */
	function intersoccer_winback_report_engine_count_history_events($group_id, $kind, array $all_rows_by_group) {
		if ($group_id === '' || empty($all_rows_by_group[ $group_id ])) {
			return 0;
		}
		$n = 0;
		foreach ($all_rows_by_group[ $group_id ] as $entry) {
			if (($entry['kind'] ?? '') === $kind) {
				$n++;
			}
		}

		return $n;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	function intersoccer_winback_report_engine_row_dob(array $row) {
		$dob = trim((string) ($row['player_dob'] ?? $row['dob'] ?? ''));
		if (!intersoccer_roster_report_has_real_date($dob)) {
			return '';
		}

		return $dob;
	}

	function intersoccer_winback_report_engine_subtract_months($ymd, $months) {
		try {
			$dt = new \DateTimeImmutable($ymd);
			$dt = $dt->modify('-' . (int) $months . ' months');

			return $dt->format('Y-m-d');
		} catch (\Exception $e) {
			return $ymd;
		}
	}
}
