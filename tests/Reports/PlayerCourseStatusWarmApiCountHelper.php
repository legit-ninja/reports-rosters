<?php
/**
 * Opt-in WordPress API call counters for PCS warm/cold perf tests (bootstrap stubs).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

class PlayerCourseStatusWarmApiCountHelper {
	/** @var string[] */
	public const TRACKED_APIS = [
		'get_terms',
		'get_term',
		'get_the_title',
		'update_meta_cache',
		'update_object_term_cache',
		'_prime_post_caches',
		'wp_get_object_terms',
		'wp_get_post_parent_id',
		'get_transient',
		'delete_option',
		'delete_transient',
		'wc_get_order',
		'wc_get_orders',
		'wc_get_product',
		'get_post_meta',
		'wc_get_order_item_meta',
	];

	public static function enable(): void {
		$GLOBALS['intersoccer_rr_test_api_counting_enabled'] = true;
		$GLOBALS['intersoccer_rr_test_api_call_counts'] = [];
	}

	public static function disable(): void {
		unset($GLOBALS['intersoccer_rr_test_api_counting_enabled'], $GLOBALS['intersoccer_rr_test_api_call_counts']);
	}

	/**
	 * @return array<string,int>
	 */
	public static function counts(): array {
		$raw = $GLOBALS['intersoccer_rr_test_api_call_counts'] ?? [];
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach (self::TRACKED_APIS as $api) {
			$out[ $api ] = (int) ( $raw[ $api ] ?? 0 );
		}

		return $out;
	}

	public static function total(): int {
		return array_sum(self::counts());
	}
}
