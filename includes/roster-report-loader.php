<?php
/**
 * Shared roster row loading helpers for player status / win-back reports.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

use InterSoccer\ReportsRosters\Services\RosterRefundService;

if (!function_exists('intersoccer_roster_report_normalize_name')) {
	/**
	 * Normalize a name part for player matching (shared with player camp status).
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	function intersoccer_roster_report_normalize_name($name) {
		if (function_exists('intersoccer_player_camp_status_normalize_name')) {
			return intersoccer_player_camp_status_normalize_name($name);
		}
		$name = strtolower(trim((string) $name));
		$name = preg_replace('/\s+/', ' ', $name);

		return $name === null ? '' : $name;
	}
}

if (!function_exists('intersoccer_roster_report_normalize_order_status')) {
	/**
	 * @param string|null $status Roster order_status value.
	 * @return string Lowercase without wc- prefix.
	 */
	function intersoccer_roster_report_normalize_order_status($status) {
		$status = strtolower(trim((string) $status));
		if ($status === '') {
			return '';
		}
		if (str_starts_with($status, 'wc-')) {
			$status = substr($status, 3);
		}

		return $status;
	}
}

if (!function_exists('intersoccer_roster_report_excluded_history_statuses')) {
	/**
	 * Order statuses that never count as booked history (cancelled, refunded, failed + wc- forms).
	 *
	 * @return string[] Normalized (no wc- prefix).
	 */
	function intersoccer_roster_report_excluded_history_statuses() {
		$out = [];
		foreach (RosterRefundService::INACTIVE_REBUILD_ORDER_STATUSES as $status) {
			$out[] = intersoccer_roster_report_normalize_order_status($status);
		}

		return array_values(array_unique($out));
	}
}

if (!function_exists('intersoccer_roster_report_rebooked_intent_statuses')) {
	/**
	 * Pending / on-hold count as already rebooked (J10), not history.
	 *
	 * @return string[]
	 */
	function intersoccer_roster_report_rebooked_intent_statuses() {
		return ['pending', 'on-hold'];
	}
}

if (!function_exists('intersoccer_roster_report_active_booked_statuses')) {
	/**
	 * Statuses that count as an active booking (history + upcoming rebooked), incl. processing.
	 *
	 * @return string[]
	 */
	function intersoccer_roster_report_active_booked_statuses() {
		return ['completed', 'processing'];
	}
}

if (!function_exists('intersoccer_roster_report_status_counts_as_history')) {
	/**
	 * Whether a roster row counts toward win-back history lookback.
	 *
	 * @param string|null $order_status Raw roster order_status.
	 */
	function intersoccer_roster_report_status_counts_as_history($order_status) {
		$norm = intersoccer_roster_report_normalize_order_status($order_status);
		if ($norm === '') {
			return true;
		}
		if (in_array($norm, intersoccer_roster_report_excluded_history_statuses(), true)) {
			return false;
		}
		if (in_array($norm, intersoccer_roster_report_rebooked_intent_statuses(), true)) {
			return false;
		}

		return in_array($norm, intersoccer_roster_report_active_booked_statuses(), true);
	}
}

if (!function_exists('intersoccer_roster_report_status_counts_as_upcoming_rebooked')) {
	/**
	 * Upcoming rows that mean the family already rebooked (incl. pending/on-hold per J10).
	 *
	 * @param string|null $order_status Raw roster order_status.
	 */
	function intersoccer_roster_report_status_counts_as_upcoming_rebooked($order_status) {
		$norm = intersoccer_roster_report_normalize_order_status($order_status);
		if ($norm === '') {
			return true;
		}
		if (in_array($norm, intersoccer_roster_report_excluded_history_statuses(), true)) {
			return false;
		}
		if (in_array($norm, intersoccer_roster_report_rebooked_intent_statuses(), true)) {
			return true;
		}

		return in_array($norm, intersoccer_roster_report_active_booked_statuses(), true);
	}
}

if (!function_exists('intersoccer_roster_report_status_counts_as_booked')) {
	/**
	 * Whether player camp status should treat a roster row as "booked" (active only, not pending).
	 *
	 * @param string|null $order_status Raw roster order_status.
	 */
	function intersoccer_roster_report_status_counts_as_booked($order_status) {
		$norm = intersoccer_roster_report_normalize_order_status($order_status);
		if ($norm === '') {
			return true;
		}
		if (in_array($norm, intersoccer_roster_report_excluded_history_statuses(), true)) {
			return false;
		}
		if (in_array($norm, intersoccer_roster_report_rebooked_intent_statuses(), true)) {
			return false;
		}

		return in_array($norm, intersoccer_roster_report_active_booked_statuses(), true);
	}
}

if (!function_exists('intersoccer_roster_report_uses_hpos_orders_table')) {
	/**
	 * Whether WooCommerce order data should be read from the HPOS orders table.
	 */
	function intersoccer_roster_report_uses_hpos_orders_table() {
		if (function_exists('get_option')) {
			$enabled = get_option('woocommerce_custom_orders_table_enabled');
			if ($enabled === 'yes') {
				return true;
			}
		}
		global $wpdb;
		if (!is_object($wpdb) || !isset($wpdb->prefix)) {
			return false;
		}
		$table = $wpdb->prefix . 'wc_orders';
		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

		return $found === $table;
	}
}

if (!function_exists('intersoccer_roster_report_collect_order_ids_from_rows')) {
	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return int[]
	 */
	function intersoccer_roster_report_collect_order_ids_from_rows(array $rows) {
		$ids = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$order_id = (int) ( $row['order_id'] ?? 0 );
			if ($order_id > 0) {
				$ids[ $order_id ] = $order_id;
			}
		}

		return array_values($ids);
	}
}

if (!function_exists('intersoccer_roster_report_batch_load_live_order_statuses')) {
	/**
	 * Live Woo order statuses for many orders (one query per chunk, not per row).
	 *
	 * @param int[] $order_ids
	 * @return array<int,string> order_id => status (often wc-* from posts or HPOS).
	 */
	function intersoccer_roster_report_batch_load_live_order_statuses(array $order_ids) {
		$order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids))));
		if (empty($order_ids)) {
			return [];
		}

		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			$GLOBALS['intersoccer_test_live_order_status_batch_load_calls'] = (int) ( $GLOBALS['intersoccer_test_live_order_status_batch_load_calls'] ?? 0 ) + 1;
			if (isset($GLOBALS['intersoccer_test_live_order_status_map']) && is_array($GLOBALS['intersoccer_test_live_order_status_map'])) {
				$out = [];
				foreach ($order_ids as $order_id) {
					if (array_key_exists($order_id, $GLOBALS['intersoccer_test_live_order_status_map'])) {
						$out[ $order_id ] = (string) $GLOBALS['intersoccer_test_live_order_status_map'][ $order_id ];
					}
				}

				return $out;
			}
		}

		global $wpdb;
		if (!is_object($wpdb) || !isset($wpdb->prefix)) {
			return [];
		}

		$map = [];
		$use_hpos = intersoccer_roster_report_uses_hpos_orders_table();
		$chunks = array_chunk($order_ids, 500);

		foreach ($chunks as $chunk) {
			$placeholders = implode(',', array_fill(0, count($chunk), '%d'));
			if ($use_hpos) {
				$table = $wpdb->prefix . 'wc_orders';
				$sql = "SELECT id, status FROM {$table} WHERE id IN ({$placeholders})";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$results = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);
			} else {
				$sql = "SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND ID IN ({$placeholders})";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$results = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);
			}
			foreach ((array) $results as $result) {
				if (!is_array($result)) {
					continue;
				}
				if ($use_hpos) {
					$id = (int) ( $result['id'] ?? 0 );
					$status = (string) ( $result['status'] ?? '' );
				} else {
					$id = (int) ( $result['ID'] ?? 0 );
					$status = (string) ( $result['post_status'] ?? '' );
				}
				if ($id > 0 && $status !== '') {
					$map[ $id ] = $status;
				}
			}
		}

		return $map;
	}
}

if (!function_exists('intersoccer_roster_report_batch_load_order_created_dates')) {
	/**
	 * Order created dates (Y-m-d) for many orders — one query per chunk.
	 *
	 * @param int[] $order_ids
	 * @return array<int,string>
	 */
	function intersoccer_roster_report_batch_load_order_created_dates(array $order_ids) {
		$order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids))));
		if (empty($order_ids)) {
			return [];
		}

		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			if (isset($GLOBALS['intersoccer_test_order_created_date_map']) && is_array($GLOBALS['intersoccer_test_order_created_date_map'])) {
				$out = [];
				foreach ($order_ids as $order_id) {
					if (array_key_exists($order_id, $GLOBALS['intersoccer_test_order_created_date_map'])) {
						$raw = (string) $GLOBALS['intersoccer_test_order_created_date_map'][ $order_id ];
						if ($raw !== '') {
							$out[ $order_id ] = substr($raw, 0, 10);
						}
					}
				}

				return $out;
			}
		}

		global $wpdb;
		if (!is_object($wpdb) || !isset($wpdb->prefix)) {
			return [];
		}

		$map = [];
		$use_hpos = intersoccer_roster_report_uses_hpos_orders_table();
		$chunks = array_chunk($order_ids, 500);

		foreach ($chunks as $chunk) {
			$placeholders = implode(',', array_fill(0, count($chunk), '%d'));
			if ($use_hpos) {
				$table = $wpdb->prefix . 'wc_orders';
				$sql = "SELECT id, date_created_gmt FROM {$table} WHERE id IN ({$placeholders})";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$results = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);
				foreach ((array) $results as $result) {
					if (!is_array($result)) {
						continue;
					}
					$id = (int) ( $result['id'] ?? 0 );
					$raw = (string) ( $result['date_created_gmt'] ?? '' );
					if ($id > 0 && $raw !== '') {
						$ts = strtotime($raw);
						if ($ts) {
							$map[ $id ] = gmdate('Y-m-d', $ts);
						}
					}
				}
			} else {
				$sql = "SELECT ID, post_date FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND ID IN ({$placeholders})";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$results = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);
				foreach ((array) $results as $result) {
					if (!is_array($result)) {
						continue;
					}
					$id = (int) ( $result['ID'] ?? 0 );
					$raw = (string) ( $result['post_date'] ?? '' );
					if ($id > 0 && $raw !== '') {
						$map[ $id ] = substr($raw, 0, 10);
					}
				}
			}
		}

		return $map;
	}
}

if (!function_exists('intersoccer_roster_report_effective_order_status_for_row')) {
	/**
	 * @param array<string,mixed> $row
	 * @param array<int,string>   $live_status_map
	 */
	function intersoccer_roster_report_effective_order_status_for_row(array $row, array $live_status_map) {
		$order_id = (int) ( $row['order_id'] ?? 0 );
		if ($order_id > 0 && isset($live_status_map[ $order_id ]) && (string) $live_status_map[ $order_id ] !== '') {
			return (string) $live_status_map[ $order_id ];
		}

		return (string) ( $row['order_status'] ?? '' );
	}
}

if (!function_exists('intersoccer_roster_report_rows_apply_live_order_status')) {
	/**
	 * Replace row order_status with live Woo status when the order post/HPOS row exists.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,string>|null         $live_status_map Optional preloaded map.
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_roster_report_rows_apply_live_order_status(array $rows, $live_status_map = null) {
		if ($live_status_map === null) {
			$live_status_map = intersoccer_roster_report_batch_load_live_order_statuses(
				intersoccer_roster_report_collect_order_ids_from_rows($rows)
			);
		}
		foreach ($rows as &$row) {
			if (!is_array($row)) {
				continue;
			}
			if (!array_key_exists('roster_order_status', $row)) {
				$row['roster_order_status'] = $row['order_status'] ?? '';
			}
			$row['order_status'] = intersoccer_roster_report_effective_order_status_for_row($row, $live_status_map);
		}
		unset($row);

		return $rows;
	}
}

if (!function_exists('intersoccer_roster_report_reset_idx_name_collision_state')) {
	/**
	 * @internal Tests only.
	 */
	function intersoccer_roster_report_reset_idx_name_collision_state() {
		$GLOBALS['intersoccer_rr_idx_name_collision_keys'] = [];
	}
}

if (!function_exists('intersoccer_roster_report_player_name_key')) {
	/**
	 * Normalized name-tier key for a roster row (customer resolved).
	 *
	 * @param array<string,mixed> $row
	 */
	function intersoccer_roster_report_player_name_key(array $row) {
		$customer_id = intersoccer_roster_report_resolve_row_customer_id($row);
		$first = intersoccer_roster_report_normalize_name(intersoccer_roster_report_player_first_name($row));
		$last = intersoccer_roster_report_normalize_name(intersoccer_roster_report_player_last_name($row));
		if ($customer_id <= 0 || $first === '' || $last === '') {
			return '';
		}

		return 'name:' . $customer_id . ':' . $first . '|' . $last;
	}
}

if (!function_exists('intersoccer_roster_report_mark_idx_name_collision')) {
	/**
	 * @param int $customer_id Resolved customer user ID.
	 * @param int $player_index
	 */
	function intersoccer_roster_report_mark_idx_name_collision($customer_id, $player_index) {
		$customer_id = (int) $customer_id;
		$player_index = (int) $player_index;
		if ($customer_id <= 0) {
			return;
		}
		if (!isset($GLOBALS['intersoccer_rr_idx_name_collision_keys']) || !is_array($GLOBALS['intersoccer_rr_idx_name_collision_keys'])) {
			$GLOBALS['intersoccer_rr_idx_name_collision_keys'] = [];
		}
		$GLOBALS['intersoccer_rr_idx_name_collision_keys'][ $customer_id . ':' . $player_index ] = true;
	}
}

if (!function_exists('intersoccer_roster_report_idx_has_name_collision')) {
	/**
	 * Same customer + player_index maps to more than one normalized player name.
	 *
	 * @param int $customer_id Resolved customer user ID.
	 * @param int $player_index
	 */
	function intersoccer_roster_report_idx_has_name_collision($customer_id, $player_index) {
		$customer_id = (int) $customer_id;
		$player_index = (int) $player_index;
		if ($customer_id <= 0) {
			return false;
		}
		$store = $GLOBALS['intersoccer_rr_idx_name_collision_keys'] ?? [];

		return !empty($store[ $customer_id . ':' . $player_index ]);
	}
}

if (!function_exists('intersoccer_roster_report_note_idx_name_for_collision_detection')) {
	/**
	 * Record idx tier name; mark collision when a second distinct name appears.
	 *
	 * @param array<string,mixed> $row
	 */
	function intersoccer_roster_report_note_idx_name_for_collision_detection(array $row) {
		$stored_cid = intersoccer_roster_report_stored_customer_id($row);
		$customer_id = intersoccer_roster_report_resolve_row_customer_id($row);
		if ($stored_cid <= 0 || $customer_id <= 0) {
			return;
		}
		$player_index = isset($row['player_index']) ? (int) $row['player_index'] : 0;
		$name_key = intersoccer_roster_report_player_name_key($row);
		if ($name_key === '') {
			return;
		}
		if (!isset($GLOBALS['intersoccer_rr_idx_name_by_slot']) || !is_array($GLOBALS['intersoccer_rr_idx_name_by_slot'])) {
			$GLOBALS['intersoccer_rr_idx_name_by_slot'] = [];
		}
		$slot = $customer_id . ':' . $player_index;
		if (isset($GLOBALS['intersoccer_rr_idx_name_by_slot'][ $slot ])) {
			if ($GLOBALS['intersoccer_rr_idx_name_by_slot'][ $slot ] !== $name_key) {
				intersoccer_roster_report_mark_idx_name_collision($customer_id, $player_index);
			}
		} else {
			$GLOBALS['intersoccer_rr_idx_name_by_slot'][ $slot ] = $name_key;
		}
	}
}

if (!function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
	/**
	 * @internal Tests only.
	 */
	function intersoccer_roster_report_reset_line_refund_check_state() {
		unset($GLOBALS['intersoccer_rr_line_refund_order_cache']);
	}
}

if (!function_exists('intersoccer_roster_report_order_status_is_wholly_refunded')) {
	/**
	 * @param string|null $order_status Live or roster order status.
	 */
	function intersoccer_roster_report_order_status_is_wholly_refunded($order_status) {
		$norm = intersoccer_roster_report_normalize_order_status($order_status);

		return $norm === 'refunded';
	}
}

if (!function_exists('intersoccer_roster_report_row_line_is_fully_refunded')) {
	/**
	 * J11: line fully refunded on an otherwise active order (partial order refund).
	 *
	 * @param array<string,mixed> $row Roster row with order_id and order_item_id.
	 */
	function intersoccer_roster_report_row_line_is_fully_refunded(array $row) {
		$order_item_id = (int) ( $row['order_item_id'] ?? 0 );
		$order_id = (int) ( $row['order_id'] ?? 0 );
		if ($order_item_id <= 0 || $order_id <= 0) {
			return false;
		}

		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			if (isset($GLOBALS['intersoccer_test_line_fully_refunded_item_ids']) && is_array($GLOBALS['intersoccer_test_line_fully_refunded_item_ids'])) {
				return !empty($GLOBALS['intersoccer_test_line_fully_refunded_item_ids'][ $order_item_id ]);
			}

			return false;
		}

		if (!function_exists('wc_get_order')) {
			return false;
		}

		if (!isset($GLOBALS['intersoccer_rr_line_refund_order_cache']) || !is_array($GLOBALS['intersoccer_rr_line_refund_order_cache'])) {
			$GLOBALS['intersoccer_rr_line_refund_order_cache'] = [];
		}
		$cache = &$GLOBALS['intersoccer_rr_line_refund_order_cache'];
		if (!array_key_exists($order_id, $cache)) {
			$cache[ $order_id ] = wc_get_order($order_id);
		}
		$order = $cache[ $order_id ];
		if (!$order) {
			return false;
		}

		$service = new RosterRefundService();

		return $service->isOrderItemFullyRefunded($order, $order_item_id);
	}
}

if (!function_exists('intersoccer_roster_report_row_counts_for_winback')) {
	/**
	 * Whether a roster row counts toward win-back history / upcoming (status + line refund).
	 *
	 * @param array<string,mixed> $row Row with live order_status applied.
	 * @param string              $mode history|upcoming_rebooked
	 */
	function intersoccer_roster_report_row_counts_for_winback(array $row, $mode = 'history') {
		$status = (string) ( $row['order_status'] ?? '' );
		if (intersoccer_roster_report_order_status_is_wholly_refunded($status)) {
			return false;
		}
		if (intersoccer_roster_report_row_line_is_fully_refunded($row)) {
			return false;
		}
		if ($mode === 'upcoming_rebooked') {
			return intersoccer_roster_report_status_counts_as_upcoming_rebooked($status);
		}

		return intersoccer_roster_report_status_counts_as_history($status);
	}
}

if (!function_exists('intersoccer_roster_report_append_sql_exclude_inactive_statuses')) {
	/**
	 * Append SQL WHERE fragments excluding cancelled/refunded/failed roster rows.
	 *
	 * @param string[]         $where  WHERE fragments (by reference).
	 * @param array<int,mixed> $params Bound values (by reference).
	 * @param string           $column Column name, default order_status.
	 */
	function intersoccer_roster_report_append_sql_exclude_inactive_statuses(array &$where, array &$params, $column = 'order_status') {
		$statuses = [];
		foreach (RosterRefundService::INACTIVE_REBUILD_ORDER_STATUSES as $status) {
			$statuses[] = intersoccer_roster_report_normalize_order_status($status);
			if (!str_starts_with($status, 'wc-')) {
				$statuses[] = 'wc-' . intersoccer_roster_report_normalize_order_status($status);
			}
		}
		$statuses = array_values(array_unique($statuses));
		$placeholders = implode(',', array_fill(0, count($statuses), '%s'));
		$where[] = "({$column} IS NULL OR {$column} = '' OR {$column} NOT IN ({$placeholders}))";
		$params = array_merge($params, $statuses);
	}
}

if (!function_exists('intersoccer_roster_report_resolve_order_customer_id')) {
	/**
	 * Resolve Woo customer ID for an order (HPOS-aware when WC is loaded).
	 *
	 * @param int $order_id Order post ID.
	 * @return int Customer user ID or 0.
	 */
	function intersoccer_roster_report_resolve_order_customer_id($order_id) {
		$order_id = (int) $order_id;
		if ($order_id <= 0) {
			return 0;
		}
		if (function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);
			if ($order) {
				return (int) $order->get_customer_id();
			}
		}
		global $wpdb;
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				$order_id,
				'_customer_user'
			)
		);

		return (int) $val;
	}
}

if (!function_exists('intersoccer_roster_report_resolve_row_customer_id')) {
	/**
	 * Resolve customer_id on a roster row (legacy rows may store 0).
	 *
	 * @param array<string,mixed> $row Roster row.
	 * @return int
	 */
	function intersoccer_roster_report_resolve_row_customer_id(array $row) {
		$customer_id = (int) ($row['customer_id'] ?? 0);
		if ($customer_id > 0) {
			return $customer_id;
		}
		$order_id = (int) ($row['order_id'] ?? 0);
		if ($order_id <= 0) {
			return 0;
		}

		return intersoccer_roster_report_resolve_order_customer_id($order_id);
	}
}

if (!function_exists('intersoccer_roster_report_cleanup_audit_table_exists')) {
	function intersoccer_roster_report_cleanup_audit_table_exists() {
		global $wpdb;
		if (!is_object($wpdb) || !isset($wpdb->prefix) || $wpdb->prefix === '') {
			return false;
		}
		$table = $wpdb->prefix . 'intersoccer_cleanup_audit';
		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

		return $found === $table;
	}
}

if (!function_exists('intersoccer_roster_report_is_fake_customer')) {
	/**
	 * J19 proxy: missing user or listed in cleanup audit when that table exists.
	 *
	 * @param int $customer_id Resolved customer user ID.
	 */
	function intersoccer_roster_report_is_fake_customer($customer_id) {
		$customer_id = (int) $customer_id;
		if ($customer_id <= 0) {
			return true;
		}
		if (!function_exists('get_userdata') || !get_userdata($customer_id)) {
			return true;
		}
		if (!intersoccer_roster_report_cleanup_audit_table_exists()) {
			return false;
		}
		global $wpdb;
		if (!is_object($wpdb) || !isset($wpdb->prefix) || $wpdb->prefix === '') {
			return false;
		}
		$table = $wpdb->prefix . 'intersoccer_cleanup_audit';
		$col = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
		if (!is_array($col)) {
			return false;
		}
		$fields = array_column($col, 'Field');
		$id_column = in_array('user_id', $fields, true) ? 'user_id' : (in_array('customer_id', $fields, true) ? 'customer_id' : null);
		if ($id_column === null) {
			return false;
		}
		$hit = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$id_column} FROM {$table} WHERE {$id_column} = %d LIMIT 1",
				$customer_id
			)
		);

		return $hit !== null && (int) $hit === $customer_id;
	}
}

if (!function_exists('intersoccer_roster_report_player_first_name')) {
	/**
	 * @param array<string,mixed> $row
	 */
	function intersoccer_roster_report_player_first_name(array $row) {
		if (function_exists('intersoccer_roster_field_player_first_name')) {
			return trim((string) intersoccer_roster_field_player_first_name($row));
		}

		return trim((string) ($row['player_first_name'] ?? $row['first_name'] ?? ''));
	}
}

if (!function_exists('intersoccer_roster_report_player_last_name')) {
	/**
	 * @param array<string,mixed> $row
	 */
	function intersoccer_roster_report_player_last_name(array $row) {
		if (function_exists('intersoccer_roster_field_player_last_name')) {
			return trim((string) intersoccer_roster_field_player_last_name($row));
		}

		return trim((string) ($row['player_last_name'] ?? $row['last_name'] ?? ''));
	}
}

if (!function_exists('intersoccer_roster_report_stored_customer_id')) {
	/**
	 * Roster column customer_id before order fallback (legacy rows may be 0).
	 *
	 * @param array<string,mixed> $row
	 */
	function intersoccer_roster_report_stored_customer_id(array $row) {
		if (isset($row['_rr_stored_customer_id'])) {
			return (int) $row['_rr_stored_customer_id'];
		}

		return (int) ( $row['customer_id'] ?? 0 );
	}
}

if (!function_exists('intersoccer_roster_report_row_with_resolved_customer')) {
	/**
	 * Preserve roster customer_id for idx rules while exposing resolved customer on the row.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	function intersoccer_roster_report_row_with_resolved_customer(array $row) {
		$row['_rr_stored_customer_id'] = intersoccer_roster_report_stored_customer_id($row);
		$row['customer_id'] = intersoccer_roster_report_resolve_row_customer_id($row);

		return $row;
	}
}

if (!function_exists('intersoccer_roster_report_player_key_tier_rank')) {
	/**
	 * @param string $tier uuid|idx|name
	 */
	function intersoccer_roster_report_player_key_tier_rank($tier) {
		switch ($tier) {
			case 'uuid':
				return 3;
			case 'idx':
				return 2;
			default:
				return 1;
		}
	}
}

if (!function_exists('intersoccer_roster_report_row_assigned_uuid')) {
	/**
	 * @param array<string,mixed> $row
	 */
	function intersoccer_roster_report_row_assigned_uuid(array $row, $assigned_player_uuid = '') {
		$uuid = trim((string) $assigned_player_uuid);
		if ($uuid === '' && !empty($row['assigned_player_id'])) {
			$uuid = trim((string) $row['assigned_player_id']);
		}

		return $uuid;
	}
}

if (!function_exists('intersoccer_roster_report_collect_player_alias_keys')) {
	/**
	 * All lookup keys for one roster row (uuid, idx, name tiers combined).
	 *
	 * @param array<string,mixed> $row
	 * @return string[]
	 */
	function intersoccer_roster_report_collect_player_alias_keys(array $row, $assigned_player_uuid = '') {
		$aliases = [];
		$uuid = intersoccer_roster_report_row_assigned_uuid($row, $assigned_player_uuid);
		if ($uuid !== '') {
			$aliases[] = 'uuid:' . $uuid;
		}

		$stored_cid = intersoccer_roster_report_stored_customer_id($row);
		$customer_id = intersoccer_roster_report_resolve_row_customer_id($row);
		$player_index = isset($row['player_index']) ? (int) $row['player_index'] : 0;

		if ($stored_cid > 0 && $customer_id > 0 && !intersoccer_roster_report_idx_has_name_collision($customer_id, $player_index)) {
			$aliases[] = 'idx:' . $customer_id . ':' . $player_index;
		}

		$first = intersoccer_roster_report_normalize_name(intersoccer_roster_report_player_first_name($row));
		$last = intersoccer_roster_report_normalize_name(intersoccer_roster_report_player_last_name($row));
		if ($customer_id > 0 && $first !== '' && $last !== '') {
			$aliases[] = 'name:' . $customer_id . ':' . $first . '|' . $last;
		}

		return array_values(array_unique($aliases));
	}
}

if (!function_exists('intersoccer_roster_report_build_player_key')) {
	/**
	 * Build primary player key and tier for a roster/history row.
	 *
	 * idx tier applies when the stored roster customer_id was > 0 (player_index 0 is valid).
	 * Legacy rows with stored customer_id 0 never use idx (name tier after order resolve).
	 * Never returns idx:0:*.
	 *
	 * @param array<string,mixed> $row
	 * @param string              $assigned_player_uuid From order item meta when known.
	 * @return array{key:string,tier:string}
	 */
	function intersoccer_roster_report_build_player_key(array $row, $assigned_player_uuid = '') {
		$uuid = intersoccer_roster_report_row_assigned_uuid($row, $assigned_player_uuid);
		if ($uuid !== '') {
			return ['key' => 'uuid:' . $uuid, 'tier' => 'uuid'];
		}

		$stored_cid = intersoccer_roster_report_stored_customer_id($row);
		$customer_id = intersoccer_roster_report_resolve_row_customer_id($row);
		$player_index = isset($row['player_index']) ? (int) $row['player_index'] : 0;

		if ($stored_cid > 0 && $customer_id > 0) {
			if (!intersoccer_roster_report_idx_has_name_collision($customer_id, $player_index)) {
				return [
					'key' => 'idx:' . $customer_id . ':' . $player_index,
					'tier' => 'idx',
				];
			}
		}

		$first = intersoccer_roster_report_normalize_name(intersoccer_roster_report_player_first_name($row));
		$last = intersoccer_roster_report_normalize_name(intersoccer_roster_report_player_last_name($row));
		if ($customer_id > 0 && $first !== '' && $last !== '') {
			return [
				'key' => 'name:' . $customer_id . ':' . $first . '|' . $last,
				'tier' => 'name',
			];
		}

		return ['key' => '', 'tier' => 'name'];
	}
}

if (!class_exists('Intersoccer_Roster_Report_Player_Identity_Map')) {
	/**
	 * Union uuid / idx / name keys and cross-row customer+index / customer+name links.
	 */
	class Intersoccer_Roster_Report_Player_Identity_Map {
		/** @var array<string,string> */
		private $parent = [];

		/** @var array<string,string> */
		private $signature_anchor = [];

		/** @var array<string,string> ci:… => name-tier key at anchor */
		private $signature_name_by_ci = [];

		/** @var array<string,string> */
		private $alias_tier = [];

		/** @var array<string,string> */
		private $group_best_key = [];

		/** @var array<string,int> */
		private $group_tier_rank = [];

		/**
		 * @param array<string,mixed> $row
		 */
		public function register_row(array $row, $assigned_player_uuid = '') {
			intersoccer_roster_report_note_idx_name_for_collision_detection($row);

			$aliases = intersoccer_roster_report_collect_player_alias_keys($row, $assigned_player_uuid);
			if (empty($aliases)) {
				return;
			}

			$primary = intersoccer_roster_report_build_player_key($row, $assigned_player_uuid);
			foreach ($aliases as $alias) {
				$tier = 'name';
				if (strpos($alias, 'uuid:') === 0) {
					$tier = 'uuid';
				} elseif (strpos($alias, 'idx:') === 0) {
					$tier = 'idx';
				}
				$this->alias_tier[ $alias ] = $tier;
				$this->union( $alias, $aliases[0] );
			}

			$stored_cid = intersoccer_roster_report_stored_customer_id($row);
			$customer_id = intersoccer_roster_report_resolve_row_customer_id($row);
			$player_index = isset($row['player_index']) ? (int) $row['player_index'] : 0;
			$signatures = [];
			if ($stored_cid > 0 && $customer_id > 0) {
				$signatures[] = 'ci:' . $customer_id . ':' . $player_index;
			}
			$first = intersoccer_roster_report_normalize_name(intersoccer_roster_report_player_first_name($row));
			$last = intersoccer_roster_report_normalize_name(intersoccer_roster_report_player_last_name($row));
			if ($customer_id > 0 && $first !== '' && $last !== '') {
				$signatures[] = 'cn:' . $customer_id . ':' . $first . '|' . $last;
			}

			foreach ($signatures as $signature) {
				if (strpos($signature, 'ci:') === 0) {
					$name_key = intersoccer_roster_report_player_name_key($row);
					if ($name_key === '') {
						continue;
					}
					if (isset($this->signature_anchor[ $signature ])) {
						$anchor_name = $this->signature_name_by_ci[ $signature ] ?? '';
						if ($anchor_name !== '' && $anchor_name !== $name_key) {
							intersoccer_roster_report_mark_idx_name_collision(
								$customer_id,
								$player_index
							);
							continue;
						}
						$this->union( $aliases[0], $this->signature_anchor[ $signature ] );
					} else {
						$this->signature_anchor[ $signature ] = $aliases[0];
						$this->signature_name_by_ci[ $signature ] = $name_key;
					}
					continue;
				}
				if (isset($this->signature_anchor[ $signature ])) {
					$this->union( $aliases[0], $this->signature_anchor[ $signature ] );
				} else {
					$this->signature_anchor[ $signature ] = $aliases[0];
				}
			}

			if ($primary['key'] !== '') {
				$this->note_group_key($aliases[0], $primary['key'], $primary['tier']);
			}
			foreach ($aliases as $alias) {
				$this->note_group_key($aliases[0], $alias, $this->alias_tier[ $alias ] ?? 'name');
			}
		}

		public function same_player($alias_a, $alias_b) {
			$alias_a = (string) $alias_a;
			$alias_b = (string) $alias_b;
			if ($alias_a === '' || $alias_b === '') {
				return false;
			}

			return $this->find($alias_a) === $this->find($alias_b);
		}

		/**
		 * @return string Stable group id for indexing (root alias).
		 */
		public function group_id($alias) {
			$alias = (string) $alias;
			if ($alias === '') {
				return '';
			}
			if (!isset($this->parent[ $alias ])) {
				$this->parent[ $alias ] = $alias;
			}

			return $this->find($alias);
		}

		/**
		 * Strongest-tier key for output (uuid > idx > name).
		 */
		public function best_key($alias) {
			$root = $this->group_id($alias);

			return $this->group_best_key[ $root ] ?? (string) $alias;
		}

		/**
		 * @return string uuid|idx|name
		 */
		public function best_tier($alias) {
			$root = $this->group_id($alias);
			$rank = $this->group_tier_rank[ $root ] ?? 1;
			if ($rank >= 3) {
				return 'uuid';
			}
			if ($rank >= 2) {
				return 'idx';
			}

			return 'name';
		}

		private function note_group_key($anchor_alias, $key, $tier) {
			$root = $this->group_id($anchor_alias);
			$rank = intersoccer_roster_report_player_key_tier_rank($tier);
			if (!isset($this->group_tier_rank[ $root ]) || $rank > $this->group_tier_rank[ $root ]) {
				$this->group_tier_rank[ $root ] = $rank;
				$this->group_best_key[ $root ] = $key;
			} elseif ($rank === ($this->group_tier_rank[ $root ] ?? 0)) {
				$current = $this->group_best_key[ $root ] ?? '';
				if ($current === '' || strlen($key) < strlen($current)) {
					$this->group_best_key[ $root ] = $key;
				}
			}
		}

		private function find($alias) {
			if (!isset($this->parent[ $alias ])) {
				$this->parent[ $alias ] = $alias;
			}
			if ($this->parent[ $alias ] !== $alias) {
				$this->parent[ $alias ] = $this->find($this->parent[ $alias ]);
			}

			return $this->parent[ $alias ];
		}

		private function union($a, $b) {
			$root_a = $this->find($a);
			$root_b = $this->find($b);
			if ($root_a === $root_b) {
				return;
			}
			$rank_a = $this->group_tier_rank[ $root_a ] ?? 1;
			$rank_b = $this->group_tier_rank[ $root_b ] ?? 1;
			if ($rank_a >= $rank_b) {
				$this->parent[ $root_b ] = $root_a;
				$this->merge_group_meta($root_a, $root_b);
			} else {
				$this->parent[ $root_a ] = $root_b;
				$this->merge_group_meta($root_b, $root_a);
			}
		}

		private function merge_group_meta($keep, $drop) {
			if (!isset($this->group_tier_rank[ $drop ])) {
				return;
			}
			$keep_rank = $this->group_tier_rank[ $keep ] ?? 1;
			$drop_rank = $this->group_tier_rank[ $drop ];
			if ($drop_rank > $keep_rank) {
				$this->group_tier_rank[ $keep ] = $drop_rank;
				$this->group_best_key[ $keep ] = $this->group_best_key[ $drop ] ?? $this->group_best_key[ $keep ];
			}
			unset($this->group_tier_rank[ $drop ], $this->group_best_key[ $drop ]);
		}
	}
}

if (!function_exists('intersoccer_roster_report_has_real_date')) {
	/**
	 * @param string|null $date Y-m-d.
	 */
	function intersoccer_roster_report_has_real_date($date) {
		$date = trim((string) $date);
		if ($date === '' || $date === '1970-01-01') {
			return false;
		}
		if (function_exists('intersoccer_roster_has_real_date')) {
			return intersoccer_roster_has_real_date($date);
		}

		return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
	}
}
