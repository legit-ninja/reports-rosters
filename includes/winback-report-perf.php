<?php
/**
 * Win-back report performance helpers (scoped memos, runtime cache drops).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_rr_product_type_memo_scope_active')) {
	function intersoccer_rr_product_type_memo_scope_active() {
		return !empty($GLOBALS['intersoccer_rr_product_type_memo_depth']);
	}
}

if (!function_exists('intersoccer_rr_product_type_memo_reset_storage')) {
	function intersoccer_rr_product_type_memo_reset_storage() {
		$GLOBALS['intersoccer_rr_pv_product_type_by_id'] = [];
		$GLOBALS['intersoccer_rr_product_type_safe_cache'] = [];
		unset($GLOBALS['intersoccer_rr_winback_row_kind_memo']);
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
		if ($post_id <= 0 || !function_exists('intersoccer_get_product_type')) {
			return '';
		}
		if (!intersoccer_rr_product_type_memo_scope_active()) {
			return intersoccer_get_product_type($post_id);
		}
		if (!isset($GLOBALS['intersoccer_rr_pv_product_type_by_id'])
			|| !is_array($GLOBALS['intersoccer_rr_pv_product_type_by_id'])) {
			$GLOBALS['intersoccer_rr_pv_product_type_by_id'] = [];
		}
		$by_id = &$GLOBALS['intersoccer_rr_pv_product_type_by_id'];
		if (!array_key_exists($post_id, $by_id)) {
			$by_id[ $post_id ] = intersoccer_get_product_type($post_id);
		}

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
		if (!intersoccer_rr_product_type_memo_scope_active()
			|| !function_exists('intersoccer_get_product_type_safe')) {
			return;
		}
		$pairs = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$product_id = (int) ( $row['product_id'] ?? 0 );
			if ($product_id <= 0) {
				continue;
			}
			$variation_id = (int) ( $row['variation_id'] ?? 0 );
			$key = $product_id . ':' . ( $variation_id > 0 ? (string) $variation_id : '0' );
			$pairs[ $key ] = [ $product_id, $variation_id > 0 ? $variation_id : null ];
		}
		foreach ($pairs as $pair) {
			intersoccer_get_product_type_safe($pair[0], $pair[1]);
		}
	}
}

if (!function_exists('intersoccer_winback_report_engine_runtime_cache_flush')) {
	/**
	 * Drop WP/WC runtime object cache entries (memos in $GLOBALS are unaffected).
	 */
	function intersoccer_winback_report_engine_runtime_cache_flush() {
		if (function_exists('wp_cache_flush_runtime')) {
			wp_cache_flush_runtime();
		}
	}
}
