<?php
/**
 * Win-back report performance helpers (scoped memos, runtime cache drops).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

$__intersoccer_rr_product_type_batch = dirname(__FILE__) . '/product-type-batch.php';
if (file_exists($__intersoccer_rr_product_type_batch)) {
	require_once $__intersoccer_rr_product_type_batch;
}

if (!function_exists('intersoccer_rr_product_type_memo_scope_active')) {
	function intersoccer_rr_product_type_memo_scope_active() {
		return !empty($GLOBALS['intersoccer_rr_product_type_memo_depth']);
	}
}

if (!function_exists('intersoccer_rr_product_type_memo_reset_storage')) {
	function intersoccer_rr_product_type_memo_reset_storage() {
		$GLOBALS['intersoccer_rr_pv_product_type_by_id'] = [];
		$GLOBALS['intersoccer_rr_product_type_safe_cache'] = [];
		unset($GLOBALS['intersoccer_rr_winback_row_kind_memo'], $GLOBALS['intersoccer_rr_product_type_batch_id_set']);
		if (function_exists('intersoccer_rr_product_type_batch_reset')) {
			intersoccer_rr_product_type_batch_reset();
		}
		if (function_exists('intersoccer_roster_report_reset_wc_order_object_cache')) {
			intersoccer_roster_report_reset_wc_order_object_cache();
		}
	}
}

if (!function_exists('intersoccer_rr_product_type_memo_reset')) {
	/**
	 * Clear product-type memo storage (engine run start / tests).
	 */
	function intersoccer_rr_product_type_memo_reset() {
		intersoccer_rr_product_type_memo_reset_storage();
		if (isset($GLOBALS['intersoccer_rr_product_type_memo_depth'])) {
			$GLOBALS['intersoccer_rr_product_type_memo_depth'] = 0;
		}
	}
}

if (!function_exists('intersoccer_rr_product_type_memo_begin')) {
	function intersoccer_rr_product_type_memo_begin() {
		if (!isset($GLOBALS['intersoccer_rr_product_type_memo_depth'])) {
			$GLOBALS['intersoccer_rr_product_type_memo_depth'] = 0;
		}
		if ((int) $GLOBALS['intersoccer_rr_product_type_memo_depth'] === 0) {
			intersoccer_rr_product_type_memo_reset_storage();
		}
		$GLOBALS['intersoccer_rr_product_type_memo_depth'] = (int) $GLOBALS['intersoccer_rr_product_type_memo_depth'] + 1;
	}
}

if (!function_exists('intersoccer_rr_product_type_memo_end')) {
	function intersoccer_rr_product_type_memo_end() {
		if (empty($GLOBALS['intersoccer_rr_product_type_memo_depth'])) {
			return;
		}
		$GLOBALS['intersoccer_rr_product_type_memo_depth'] = (int) $GLOBALS['intersoccer_rr_product_type_memo_depth'] - 1;
		if ((int) $GLOBALS['intersoccer_rr_product_type_memo_depth'] <= 0) {
			$GLOBALS['intersoccer_rr_product_type_memo_depth'] = 0;
			intersoccer_rr_product_type_memo_reset_storage();
		}
	}
}

if (!function_exists('intersoccer_rr_memoized_get_product_type')) {
	/**
	 * PV product type lookup with per-run memo (including empty) when win-back scope is active.
	 *
	 * @param int $post_id Product or variation ID.
	 * @return mixed
	 */
	function intersoccer_rr_memoized_get_product_type($post_id) {
		$post_id = (int) $post_id;
		if ($post_id <= 0) {
			return '';
		}
		if (!intersoccer_rr_product_type_memo_scope_active()) {
			if (!function_exists('intersoccer_get_product_type')) {
				return '';
			}

			return intersoccer_get_product_type($post_id);
		}
		if (!isset($GLOBALS['intersoccer_rr_pv_product_type_by_id'])
			|| !is_array($GLOBALS['intersoccer_rr_pv_product_type_by_id'])) {
			$GLOBALS['intersoccer_rr_pv_product_type_by_id'] = [];
		}
		$by_id = &$GLOBALS['intersoccer_rr_pv_product_type_by_id'];
		if (array_key_exists($post_id, $by_id)) {
			return $by_id[ $post_id ];
		}
		if (!function_exists('intersoccer_get_product_type')) {
			$by_id[ $post_id ] = '';

			return '';
		}
		$by_id[ $post_id ] = intersoccer_get_product_type($post_id);

		return $by_id[ $post_id ];
	}
}

if (!function_exists('intersoccer_rr_winback_compute_row_listing_kind')) {
	/**
	 * @param array<string,mixed> $row
	 */
	function intersoccer_rr_winback_compute_row_listing_kind(array $row) {
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
}

if (!function_exists('intersoccer_rr_winback_get_row_listing_kind')) {
	/**
	 * Row camp/course kind for win-back (memoized once per order line during engine scope).
	 *
	 * @param array<string,mixed> $row
	 */
	function intersoccer_rr_winback_get_row_listing_kind(array $row) {
		if (!intersoccer_rr_product_type_memo_scope_active()) {
			return intersoccer_rr_winback_compute_row_listing_kind($row);
		}
		$memo_key = (int) ( $row['order_item_id'] ?? 0 );
		if ($memo_key <= 0) {
			$memo_key = (int) ( $row['id'] ?? 0 );
		}
		if ($memo_key <= 0) {
			return intersoccer_rr_winback_compute_row_listing_kind($row);
		}
		if (!isset($GLOBALS['intersoccer_rr_winback_row_kind_memo'])
			|| !is_array($GLOBALS['intersoccer_rr_winback_row_kind_memo'])) {
			$GLOBALS['intersoccer_rr_winback_row_kind_memo'] = [];
		}
		if (!array_key_exists($memo_key, $GLOBALS['intersoccer_rr_winback_row_kind_memo'])) {
			$GLOBALS['intersoccer_rr_winback_row_kind_memo'][ $memo_key ]
				= intersoccer_rr_winback_compute_row_listing_kind($row);
		}

		return (string) $GLOBALS['intersoccer_rr_winback_row_kind_memo'][ $memo_key ];
	}
}

if (!function_exists('intersoccer_rr_winback_prime_product_types_from_rows')) {
	/**
	 * Warm product-type memo for distinct product/variation IDs on a row set.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	function intersoccer_rr_winback_prime_product_types_from_rows(array $rows) {
		if (!intersoccer_rr_product_type_memo_scope_active()) {
			return;
		}
		if (!function_exists('intersoccer_rr_product_type_batch_collect_post_ids_from_rows')
			|| !function_exists('intersoccer_rr_product_type_batch_load_for_post_ids')) {
			return;
		}
		$post_ids = intersoccer_rr_product_type_batch_collect_post_ids_from_rows($rows);
		if (empty($post_ids)) {
			return;
		}
		intersoccer_rr_product_type_batch_load_for_post_ids($post_ids);
		if (function_exists('intersoccer_rr_product_type_batch_prime_safe_cache_from_rows')) {
			intersoccer_rr_product_type_batch_prime_safe_cache_from_rows($rows);
		}
	}
}

if (!function_exists('intersoccer_winback_report_engine_history_row_bulk_column_names')) {
	/**
	 * Bulky roster columns stripped after SELECT * on win-back history load.
	 *
	 * Not read by identity map, facet/event-date resolution, aggregation, J8, or output
	 * for rows on the win-back history path. Unknown or new columns are kept.
	 *
	 * @return string[]
	 */
	function intersoccer_winback_report_engine_history_row_bulk_column_names() {
		return [
			'medical_conditions',
			'player_medical',
			'player_dietary',
			'emergency_contact',
			'shirt_size',
			'shorts_size',
			'discount_codes',
			'discount_amount',
			'reimbursement',
			'base_price',
			'final_price',
			'late_pickup',
			'late_pickup_days',
			'registration_timestamp',
			'created_at',
			'updated_at',
			'day_presence',
		];
	}
}

if (!function_exists('intersoccer_winback_report_engine_project_history_row')) {
	/**
	 * Drop bulky unused columns from a loaded history row (SELECT * → in-memory deny-list).
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	function intersoccer_winback_report_engine_project_history_row(array $row) {
		foreach (intersoccer_winback_report_engine_history_row_bulk_column_names() as $column) {
			unset($row[ $column ]);
		}

		return $row;
	}
}

if (!function_exists('intersoccer_winback_report_engine_aggregate_history_entry')) {
	/**
	 * Retain latest history entry per group|kind and increment lookback event counter.
	 *
	 * @param array<string,array<string,mixed>> $by_player_kind
	 * @param array<string,int>                 $event_counts  group_id|kind => count
	 * @param array<string,mixed>               $entry         Aggregation entry (row + metadata).
	 */
	function intersoccer_winback_report_engine_aggregate_history_entry(
		array &$by_player_kind,
		array &$event_counts,
		array $entry,
		$group_id,
		$kind
	) {
		$group_id = (string) $group_id;
		$kind = (string) $kind;
		$pk = $group_id . '|' . $kind;
		$event_counts[ $pk ] = (int) ( $event_counts[ $pk ] ?? 0 ) + 1;
		if (!isset($by_player_kind[ $pk ])
			|| intersoccer_winback_report_engine_is_later_history($entry, $by_player_kind[ $pk ])) {
			$by_player_kind[ $pk ] = $entry;
		}
	}
}

if (!function_exists('intersoccer_winback_report_engine_history_events_in_lookback')) {
	/**
	 * Event count for a player group and kind (counter-based aggregation path).
	 *
	 * @param array<string,int> $event_counts group_id|kind => count
	 */
	function intersoccer_winback_report_engine_history_events_in_lookback($group_id, $kind, array $event_counts) {
		$group_id = (string) $group_id;
		$kind = (string) $kind;
		if ($group_id === '') {
			return 0;
		}
		$pk = $group_id . '|' . $kind;

		return (int) ( $event_counts[ $pk ] ?? 0 );
	}
}

if (!function_exists('intersoccer_winback_report_engine_runtime_cache_flush')) {
	/**
	 * Drop WP/WC runtime object cache entries (memos in $GLOBALS are unaffected).
	 */
	function intersoccer_winback_report_engine_runtime_cache_flush() {
		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			$GLOBALS['intersoccer_test_runtime_cache_flush_call_count'] = (int) ( $GLOBALS['intersoccer_test_runtime_cache_flush_call_count'] ?? 0 ) + 1;
		}
		if (!function_exists('wp_cache_flush_runtime')) {
			return;
		}
		if (!function_exists('wp_cache_supports') || !wp_cache_supports('flush_runtime')) {
			return;
		}
		wp_cache_flush_runtime();
	}
}
