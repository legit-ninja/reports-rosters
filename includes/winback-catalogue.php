<?php
/**
 * Upcoming camp/course catalogue enumeration for win-back matching.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_winback_reset_order_created_date_cache')) {
	/**
	 * Clear batched order created-date cache (call before a new engine run).
	 */
	function intersoccer_winback_reset_order_created_date_cache() {
		$GLOBALS['intersoccer_winback_order_created_date_cache'] = [];
	}
}

if (!function_exists('intersoccer_winback_prime_order_created_dates_from_rows')) {
	/**
	 * Batch-load order created dates for roster rows (one query per chunk).
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	function intersoccer_winback_prime_order_created_dates_from_rows(array $rows) {
		if (!function_exists('intersoccer_roster_report_batch_load_order_created_dates')
			|| !function_exists('intersoccer_roster_report_collect_order_ids_from_rows')) {
			return;
		}
		$ids = intersoccer_roster_report_collect_order_ids_from_rows($rows);
		if (empty($ids)) {
			return;
		}
		$loaded = intersoccer_roster_report_batch_load_order_created_dates($ids);
		if (!isset($GLOBALS['intersoccer_winback_order_created_date_cache'])
			|| !is_array($GLOBALS['intersoccer_winback_order_created_date_cache'])) {
			$GLOBALS['intersoccer_winback_order_created_date_cache'] = [];
		}
		foreach ($loaded as $order_id => $ymd) {
			$GLOBALS['intersoccer_winback_order_created_date_cache'][ (int) $order_id ] = (string) $ymd;
		}
	}
}

if (!function_exists('intersoccer_winback_get_order_created_ymd')) {
	/**
	 * @param int $order_id
	 */
	function intersoccer_winback_get_order_created_ymd($order_id) {
		$order_id = (int) $order_id;
		if ($order_id <= 0) {
			return '';
		}
		$cache = $GLOBALS['intersoccer_winback_order_created_date_cache'] ?? null;
		if (!is_array($cache)) {
			$cache = [];
			$GLOBALS['intersoccer_winback_order_created_date_cache'] = &$cache;
		}
		if (array_key_exists($order_id, $cache)) {
			$cached = (string) $cache[ $order_id ];
			if ($cached !== '') {
				return substr($cached, 0, 10);
			}
		}
		if (function_exists('intersoccer_roster_report_batch_load_order_created_dates')) {
			$loaded = intersoccer_roster_report_batch_load_order_created_dates([$order_id]);
			if (isset($loaded[ $order_id ]) && (string) $loaded[ $order_id ] !== '') {
				$cache[ $order_id ] = (string) $loaded[ $order_id ];

				return substr((string) $cache[ $order_id ], 0, 10);
			}
		}

		if (function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);
			if ($order && $order->get_date_created()) {
				$ymd = $order->get_date_created()->date('Y-m-d');
				$cache[ $order_id ] = $ymd;

				return $ymd;
			}
		}
		global $wpdb;
		if (is_object($wpdb) && isset($wpdb->prefix)) {
			$raw = (string) $wpdb->get_var(
				$wpdb->prepare("SELECT post_date FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $order_id)
			);
			if ($raw !== '') {
				$ymd = substr($raw, 0, 10);
				$cache[ $order_id ] = $ymd;

				return $ymd;
			}
		}

		$cache[ $order_id ] = '';

		return '';
	}
}

if (!function_exists('intersoccer_winback_reset_catalogue_target_cache')) {
	function intersoccer_winback_reset_catalogue_target_cache() {
		unset($GLOBALS['intersoccer_winback_catalogue_venue_cache']);
		unset($GLOBALS['intersoccer_winback_catalogue_target_cache']);
	}
}

if (!function_exists('intersoccer_winback_reset_catalogue_enumeration_memo')) {
	function intersoccer_winback_reset_catalogue_enumeration_memo() {
		unset(
			$GLOBALS['intersoccer_winback_memo_wpml_product'],
			$GLOBALS['intersoccer_winback_memo_wpml_variation']
		);
	}
}

if (!function_exists('intersoccer_winback_memo_default_language_product_id')) {
	function intersoccer_winback_memo_default_language_product_id($product_id) {
		$product_id = (int) $product_id;
		if ($product_id <= 0) {
			return 0;
		}
		if (!isset($GLOBALS['intersoccer_winback_memo_wpml_product'])
			|| !is_array($GLOBALS['intersoccer_winback_memo_wpml_product'])) {
			$GLOBALS['intersoccer_winback_memo_wpml_product'] = [];
		}
		if (array_key_exists($product_id, $GLOBALS['intersoccer_winback_memo_wpml_product'])) {
			return (int) $GLOBALS['intersoccer_winback_memo_wpml_product'][ $product_id ];
		}
		$resolved = $product_id;
		if (function_exists('intersoccer_get_default_language_product_id')) {
			$resolved = (int) intersoccer_get_default_language_product_id($product_id);
		} elseif (function_exists('apply_filters') && function_exists('wpml_get_default_language')) {
			$default_lang = (string) wpml_get_default_language();
			$mapped = (int) apply_filters('wpml_object_id', $product_id, 'product', true, $default_lang);
			if ($mapped > 0) {
				$resolved = $mapped;
			}
		}
		$GLOBALS['intersoccer_winback_memo_wpml_product'][ $product_id ] = $resolved;

		return $resolved;
	}
}

if (!function_exists('intersoccer_winback_memo_default_language_variation_id')) {
	function intersoccer_winback_memo_default_language_variation_id($variation_id) {
		$variation_id = (int) $variation_id;
		if ($variation_id <= 0) {
			return 0;
		}
		if (!isset($GLOBALS['intersoccer_winback_memo_wpml_variation'])
			|| !is_array($GLOBALS['intersoccer_winback_memo_wpml_variation'])) {
			$GLOBALS['intersoccer_winback_memo_wpml_variation'] = [];
		}
		if (array_key_exists($variation_id, $GLOBALS['intersoccer_winback_memo_wpml_variation'])) {
			return (int) $GLOBALS['intersoccer_winback_memo_wpml_variation'][ $variation_id ];
		}
		$resolved = $variation_id;
		if (function_exists('intersoccer_get_default_language_variation_id')) {
			$resolved = (int) intersoccer_get_default_language_variation_id($variation_id);
		}
		$GLOBALS['intersoccer_winback_memo_wpml_variation'][ $variation_id ] = $resolved;

		return $resolved;
	}
}

if (!function_exists('intersoccer_winback_catalogue_batch_variation_posts')) {
	/**
	 * @param int[] $parent_ids
	 * @return array<int,array{ID:int,post_parent:int,post_status:string}>
	 */
	function intersoccer_winback_catalogue_batch_variation_posts(array $parent_ids) {
		$parent_ids = array_values(array_unique(array_filter(array_map('intval', $parent_ids))));
		if (empty($parent_ids)) {
			return [];
		}
		global $wpdb;
		if (!is_object($wpdb) || !isset($wpdb->prefix)) {
			return [];
		}
		$chunk_size = function_exists('intersoccer_roster_report_batch_chunk_size')
			? intersoccer_roster_report_batch_chunk_size()
			: 500;
		$rows = [];
		foreach (array_chunk($parent_ids, $chunk_size) as $chunk) {
			$placeholders = implode(',', array_fill(0, count($chunk), '%d'));
			$sql = "SELECT ID, post_parent, post_status FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status = 'publish' AND post_parent IN ({$placeholders})";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);
			foreach ((array) $results as $result) {
				if (!is_array($result)) {
					continue;
				}
				$id = (int) ( $result['ID'] ?? 0 );
				if ($id <= 0) {
					continue;
				}
				$rows[] = [
					'ID' => $id,
					'post_parent' => (int) ( $result['post_parent'] ?? 0 ),
					'post_status' => (string) ( $result['post_status'] ?? '' ),
				];
			}
		}

		return $rows;
	}
}

if (!function_exists('intersoccer_winback_catalogue_read_pa_attribute_from_meta')) {
	function intersoccer_winback_catalogue_read_pa_attribute_from_meta($variation_id, $taxonomy) {
		$variation_id = (int) $variation_id;
		$taxonomy = (string) $taxonomy;
		if ($variation_id <= 0 || $taxonomy === '') {
			return '';
		}
		$meta_key = 'attribute_' . $taxonomy;
		$val = get_post_meta($variation_id, $meta_key, true);
		if (is_string($val) && $val !== '') {
			return $val;
		}

		return '';
	}
}

if (!function_exists('intersoccer_winback_catalogue_booking_type_slug_from_meta')) {
	function intersoccer_winback_catalogue_booking_type_slug_from_meta($variation_id) {
		$raw = intersoccer_winback_catalogue_read_pa_attribute_from_meta($variation_id, 'pa_booking-type');
		if ($raw === '') {
			return 'other';
		}
		if (function_exists('intersoccer_normalize_booking_type_slug_for_reports')) {
			return (string) intersoccer_normalize_booking_type_slug_for_reports($raw);
		}

		return 'other';
	}
}

if (!function_exists('intersoccer_winback_catalogue_product_week_key')) {
	function intersoccer_winback_catalogue_product_week_key(array $row) {
		$product_id = (int) ( $row['product_id'] ?? 0 );
		$start = (string) ( $row['start_date'] ?? '' );

		return $product_id . '|' . $start;
	}
}

if (!function_exists('intersoccer_winback_catalogue_booking_type_pref_rank')) {
	/**
	 * Prefer full-week catalogue rows over single-day when deduping the same product/week.
	 */
	function intersoccer_winback_catalogue_booking_type_pref_rank(array $row) {
		$slug = (string) ( $row['booking_type_slug'] ?? '' );
		if ($slug === 'full-week') {
			return 2;
		}
		if ($slug === 'single-days') {
			return 1;
		}

		return 0;
	}
}

if (!function_exists('intersoccer_winback_catalogue_pick_preferred_catalogue_row')) {
	function intersoccer_winback_catalogue_pick_preferred_catalogue_row(array $a, array $b) {
		$ra = intersoccer_winback_catalogue_booking_type_pref_rank($a);
		$rb = intersoccer_winback_catalogue_booking_type_pref_rank($b);
		if ($ra !== $rb) {
			return $ra > $rb ? $a : $b;
		}
		$va = (int) ( $a['source_variation_id'] ?? $a['variation_id'] ?? 0 );
		$vb = (int) ( $b['source_variation_id'] ?? $b['variation_id'] ?? 0 );

		return $va <= $vb ? $a : $b;
	}
}

if (!function_exists('intersoccer_winback_catalogue_dedupe_rows_by_product_week')) {
	/**
	 * One catalogue row per (product_id, start_date); full-week beats single-day.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_winback_catalogue_dedupe_rows_by_product_week(array $rows) {
		$by_key = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$key = intersoccer_winback_catalogue_product_week_key($row);
			if (!isset($by_key[ $key ])) {
				$by_key[ $key ] = $row;
				continue;
			}
			$by_key[ $key ] = intersoccer_winback_catalogue_pick_preferred_catalogue_row($by_key[ $key ], $row);
		}
		$out = array_values($by_key);
		usort($out, static function ($a, $b) {
			return strcmp((string) ( $a['start_date'] ?? '' ), (string) ( $b['start_date'] ?? '' ));
		});

		return $out;
	}
}

if (!function_exists('intersoccer_winback_catalogue_venue_cache_bucket')) {
	/**
	 * @return array{available_ages:array<int,string>,entries:array<int,array<string,mixed>>}
	 */
	function intersoccer_winback_catalogue_venue_cache_bucket(array $catalogue_index, $kind, $venue_slug) {
		if (!isset($GLOBALS['intersoccer_winback_catalogue_venue_cache'])
			|| !is_array($GLOBALS['intersoccer_winback_catalogue_venue_cache'])) {
			$GLOBALS['intersoccer_winback_catalogue_venue_cache'] = [];
		}
		$cache_key = strtolower((string) $kind) . '|' . (string) $venue_slug;
		if (isset($GLOBALS['intersoccer_winback_catalogue_venue_cache'][ $cache_key ])) {
			return $GLOBALS['intersoccer_winback_catalogue_venue_cache'][ $cache_key ];
		}
		$available_ages = [];
		foreach ($catalogue_index as $key => $list) {
			if (strpos((string) $key, strtolower((string) $kind) . '|' . $venue_slug . '|') !== 0) {
				continue;
			}
			foreach ((array) $list as $row) {
				$available_ages[] = (string) ($row['age_group_slug'] ?? '');
			}
		}
		$available_ages = array_values(array_unique(array_filter($available_ages)));
		$entries = intersoccer_winback_catalogue_rows_for_venue($catalogue_index, $kind, $venue_slug);
		$bucket = [
			'available_ages' => $available_ages,
			'entries' => $entries,
		];
		$GLOBALS['intersoccer_winback_catalogue_venue_cache'][ $cache_key ] = $bucket;

		return $bucket;
	}
}

if (!function_exists('intersoccer_winback_camp_terms_has_explicit_calendar_year')) {
	/**
	 * @param string $camp_terms
	 * @param string $season
	 */
	function intersoccer_winback_camp_terms_has_explicit_calendar_year($camp_terms, $season) {
		if (preg_match('/\b(19|20)\d{2}\b/', (string) $season)) {
			return true;
		}
		if (preg_match('/\b(19|20)\d{2}\b/', (string) $camp_terms)) {
			return true;
		}

		return false;
	}
}

if (!function_exists('intersoccer_winback_camp_terms_year_from_order_created')) {
	/**
	 * Calendar year for yearless camp_terms parse from order created date + parsed camp month.
	 *
	 * @param string $order_created_ymd Y-m-d
	 * @param string $parsed_start_ymd  Y-m-d (month/day from slug; year may be wrong)
	 */
	function intersoccer_winback_camp_terms_year_from_order_created($order_created_ymd, $parsed_start_ymd) {
		$order_created_ymd = substr(trim((string) $order_created_ymd), 0, 10);
		$parsed_start_ymd = substr(trim((string) $parsed_start_ymd), 0, 10);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_created_ymd)
			|| !preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed_start_ymd)) {
			return null;
		}
		$order_year = (int) substr($order_created_ymd, 0, 4);
		$order_month = (int) substr($order_created_ymd, 5, 2);
		$parsed_month = (int) substr($parsed_start_ymd, 5, 2);
		$year = $order_year;
		if ($order_month - $parsed_month > 6) {
			$year = $order_year + 1;
		}

		return $year;
	}
}

if (!function_exists('intersoccer_winback_apply_camp_terms_order_year')) {
	/**
	 * Re-parse yearless camp_terms using order-year rules (win-back only).
	 *
	 * @param string              $camp_terms
	 * @param string              $season
	 * @param string              $parsed_start From resolve_camp_schedule (terms_parse)
	 * @param array<string,mixed> $row
	 * @return string|null Adjusted start Y-m-d, or null to fall through the chain
	 */
	function intersoccer_winback_apply_camp_terms_order_year($camp_terms, $season, $parsed_start, array $row) {
		if (intersoccer_winback_camp_terms_has_explicit_calendar_year($camp_terms, $season)) {
			return $parsed_start;
		}
		$order_id = (int) ( $row['order_id'] ?? 0 );
		$order_ymd = $order_id > 0 ? intersoccer_winback_get_order_created_ymd($order_id) : '';
		if ($order_ymd === '') {
			return null;
		}
		$year = intersoccer_winback_camp_terms_year_from_order_created($order_ymd, $parsed_start);
		if ($year === null || !function_exists('intersoccer_parse_camp_dates_fixed')) {
			return null;
		}
		if (function_exists('intersoccer_reports_normalize_camp_terms_for_dates')) {
			$camp_terms = intersoccer_reports_normalize_camp_terms_for_dates($camp_terms);
		}
		list($start,,) = intersoccer_parse_camp_dates_fixed($camp_terms, $season, $year);
		if (!is_string($start) || $start === '' || $start === '1970-01-01') {
			return null;
		}

		return $start;
	}
}

if (!function_exists('intersoccer_winback_resolve_row_event_date')) {
	/**
	 * Resolve event start date for lookback / history (J9 camp fallbacks).
	 *
	 * @param array<string,mixed> $row Roster row.
	 * @return array{date:?string,source:string}
	 */
	function intersoccer_winback_resolve_row_event_date(array $row) {
		$start = trim((string) ($row['start_date'] ?? ''));
		if (intersoccer_roster_report_has_real_date($start)) {
			return ['date' => $start, 'source' => 'roster_start_date'];
		}

		$kind = '';
		if (function_exists('intersoccer_rr_winback_get_row_listing_kind')) {
			$kind = intersoccer_rr_winback_get_row_listing_kind($row);
		} elseif (function_exists('intersoccer_roster_row_matches_listing_kind')) {
			if (intersoccer_roster_row_matches_listing_kind($row, 'course')) {
				$kind = 'course';
			} elseif (intersoccer_roster_row_matches_listing_kind($row, 'camp')) {
				$kind = 'camp';
			}
		}

		$variation_id = (int) ($row['variation_id'] ?? 0);
		if ($variation_id > 0 && function_exists('intersoccer_get_default_language_variation_id')) {
			$variation_id = (int) intersoccer_get_default_language_variation_id($variation_id);
		}

		if ($kind === 'camp' && $variation_id > 0 && function_exists('intersoccer_reports_resolve_camp_schedule')) {
			$camp_terms = (string) ( $row['camp_terms'] ?? '' );
			$season = (string) ( $row['season'] ?? '' );
			list($parsed_start,,, $source) = intersoccer_reports_resolve_camp_schedule(
				$variation_id,
				(int) ( $row['order_item_id'] ?? 0 ),
				$camp_terms,
				$season
			);
			if ($source === 'terms_parse' && intersoccer_roster_report_has_real_date($parsed_start)) {
				$adjusted = intersoccer_winback_apply_camp_terms_order_year($camp_terms, $season, $parsed_start, $row);
				$parsed_start = $adjusted;
			}
			if (intersoccer_roster_report_has_real_date($parsed_start)) {
				$map = [
					'variation_meta' => 'variation_camp_start',
					'order_item_meta' => 'order_item_camp_start',
					'terms_parse' => 'camp_terms_parse',
				];

				return ['date' => $parsed_start, 'source' => $map[$source] ?? 'variation_camp_start'];
			}
		}

		if ($kind === 'course' && $variation_id > 0) {
			$meta = get_post_meta($variation_id, '_course_start_date', true);
			if (is_string($meta) && intersoccer_roster_report_has_real_date(trim($meta))) {
				return ['date' => trim($meta), 'source' => 'variation_course_start'];
			}
		}

		$order_id = (int) ( $row['order_id'] ?? 0 );
		if ($order_id > 0) {
			$order_date = intersoccer_winback_get_order_created_ymd($order_id);
			if (intersoccer_roster_report_has_real_date($order_date)) {
				return ['date' => $order_date, 'source' => 'order_date'];
			}
		}

		return ['date' => null, 'source' => 'unknown'];
	}
}

if (!function_exists('intersoccer_winback_catalogue_read_variation_facets')) {
	/**
	 * Read normalized EN slugs from a default-language variation.
	 *
	 * @param int    $variation_id Source (default language) variation ID.
	 * @param string $kind         camp|course.
	 * @return array<string,mixed>
	 */
	function intersoccer_winback_catalogue_read_variation_facets($variation_id, $kind) {
		$variation_id = (int) $variation_id;
		$venue = intersoccer_winback_catalogue_read_pa_attribute_from_meta($variation_id, 'pa_intersoccer-venues');
		$age = intersoccer_winback_catalogue_read_pa_attribute_from_meta($variation_id, 'pa_age-group');
		$course_day = '';
		$times = '';
		if ($kind === 'course') {
			$course_day = intersoccer_winback_catalogue_read_pa_attribute_from_meta($variation_id, 'pa_course-day');
			$times = intersoccer_winback_catalogue_read_pa_attribute_from_meta($variation_id, 'pa_course-times');
		}
		$girls_only = 0;
		$parent_id = (int) wp_get_post_parent_id($variation_id);
		if ($parent_id > 0 && function_exists('intersoccer_resolve_roster_girls_only_product_attribute')) {
			$girls_only = intersoccer_resolve_roster_girls_only_product_attribute($parent_id) ? 1 : 0;
		}
		if ($venue === '' || $age === '') {
			$product = function_exists('wc_get_product') ? wc_get_product($variation_id) : null;
			if ($product) {
				if ($venue === '') {
					$venue = (string) $product->get_attribute('pa_intersoccer-venues');
				}
				if ($age === '') {
					$age = (string) $product->get_attribute('pa_age-group');
				}
				if ($kind === 'course') {
					if ($course_day === '') {
						$course_day = (string) $product->get_attribute('pa_course-day');
					}
					if ($times === '') {
						$times = (string) $product->get_attribute('pa_course-times');
					}
				}
				if ($parent_id <= 0 && method_exists($product, 'get_parent_id')) {
					$parent_id = (int) $product->get_parent_id();
					if ($parent_id > 0 && function_exists('intersoccer_resolve_roster_girls_only_product_attribute')) {
						$girls_only = intersoccer_resolve_roster_girls_only_product_attribute($parent_id) ? 1 : 0;
					}
				}
			}
		}

		return [
			'venue_slug' => intersoccer_winback_normalize_facet_slug($venue, 'pa_intersoccer-venues', 'venue'),
			'age_group_slug' => intersoccer_winback_normalize_facet_slug($age, 'pa_age-group', 'age_group'),
			'course_day' => $course_day,
			'times' => $times,
			'girls_only' => $girls_only,
			'booking_type_slug' => intersoccer_winback_catalogue_booking_type_slug_from_meta($variation_id),
		];
	}
}

if (!function_exists('intersoccer_winback_is_variation_full')) {
	/**
	 * J12: out-of-stock / zero qty still counts as upcoming but flagged full.
	 *
	 * @param int $variation_id
	 */
	function intersoccer_winback_is_variation_full($variation_id) {
		$variation_id = (int) $variation_id;
		if ($variation_id <= 0) {
			return false;
		}
		$status = get_post_meta($variation_id, '_stock_status', true);
		if ($status === 'outofstock') {
			return true;
		}
		$manage = get_post_meta($variation_id, '_manage_stock', true);
		if ($manage === 'yes') {
			$qty = get_post_meta($variation_id, '_stock', true);
			if ($qty !== '' && $qty !== null && (int) $qty <= 0) {
				return true;
			}
		}
		if (!function_exists('wc_get_product')) {
			return false;
		}
		$product = wc_get_product($variation_id);
		if (!$product) {
			return false;
		}
		if (!$product->is_in_stock()) {
			return true;
		}
		if ($product->managing_stock()) {
			$qty = $product->get_stock_quantity();
			if ($qty !== null && (int) $qty <= 0) {
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('intersoccer_winback_enumerate_upcoming_catalogue')) {
	/**
	 * Load upcoming published catalogue events indexed by kind + facets.
	 *
	 * @param string $today Y-m-d reference date.
	 * @return array<string,array<int,array<string,mixed>>> Indexed rows keyed by composite key.
	 */
	function intersoccer_winback_enumerate_upcoming_catalogue($today) {
		$today = trim((string) $today);
		if ($today === '') {
			$today = function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d');
		}

		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING
			&& isset($GLOBALS['intersoccer_test_winback_upcoming_catalogue'])
			&& is_array($GLOBALS['intersoccer_test_winback_upcoming_catalogue'])) {
			intersoccer_winback_reset_catalogue_enumeration_memo();

			return $GLOBALS['intersoccer_test_winback_upcoming_catalogue'];
		}

		intersoccer_winback_reset_catalogue_enumeration_memo();
		$raw_variations = [];

		$parent_ids = get_posts([
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_query' => [
				[
					'key' => '_intersoccer_product_type',
					'value' => ['camp', 'course'],
					'compare' => 'IN',
				],
			],
		]);

		$parent_ids = array_values(array_filter(array_map('intval', (array) $parent_ids)));
		if (empty($parent_ids)) {
			return [];
		}

		if (function_exists('update_meta_cache')) {
			update_meta_cache('post', $parent_ids);
		}

		$parent_kind_map = [];
		$resolved_parent_ids = [];
		foreach ($parent_ids as $original_parent_id) {
			$resolved_parent_id = intersoccer_winback_memo_default_language_product_id($original_parent_id);
			if ($resolved_parent_id <= 0 || get_post_status($resolved_parent_id) !== 'publish') {
				continue;
			}
			$ptype = get_post_meta($resolved_parent_id, '_intersoccer_product_type', true);
			$kind = strtolower((string) $ptype) === 'course' ? 'course' : 'camp';
			$parent_kind_map[ $original_parent_id ] = [
				'resolved_parent_id' => $resolved_parent_id,
				'kind' => $kind,
			];
			$resolved_parent_ids[ $resolved_parent_id ] = $resolved_parent_id;
		}

		if (empty($parent_kind_map)) {
			return [];
		}

		$variation_posts = intersoccer_winback_catalogue_batch_variation_posts(array_keys($parent_kind_map));
		$prime_ids = array_keys($resolved_parent_ids);
		foreach ($variation_posts as $vp) {
			$prime_ids[] = (int) $vp['ID'];
		}
		$prime_ids = array_values(array_unique(array_filter($prime_ids)));
		if (function_exists('update_meta_cache')) {
			update_meta_cache('post', $prime_ids);
		}
		if (function_exists('_prime_post_caches')) {
			_prime_post_caches($prime_ids, true, false);
		}
		if (function_exists('update_object_term_cache') && !empty($resolved_parent_ids)) {
			update_object_term_cache(array_values($resolved_parent_ids), 'product');
		}

		$variations_by_parent = [];
		foreach ($variation_posts as $vp) {
			$parent = (int) $vp['post_parent'];
			if ($parent <= 0) {
				continue;
			}
			if (!isset($variations_by_parent[ $parent ])) {
				$variations_by_parent[ $parent ] = [];
			}
			$variations_by_parent[ $parent ][] = (int) $vp['ID'];
		}

		foreach ($parent_kind_map as $original_parent_id => $parent_bits) {
			$parent_id = (int) $parent_bits['resolved_parent_id'];
			$kind = (string) $parent_bits['kind'];
			$children = $variations_by_parent[ $original_parent_id ] ?? [];
			if (empty($children)) {
				continue;
			}
			$product_name = get_the_title($parent_id);

			foreach ($children as $variation_id) {
				$source_id = intersoccer_winback_memo_default_language_variation_id($variation_id);
				if ($source_id <= 0 || get_post_status($source_id) !== 'publish') {
					continue;
				}

				$meta_key = $kind === 'course' ? '_course_start_date' : '_camp_start_date';
				$start = get_post_meta($source_id, $meta_key, true);
				if (!is_string($start) || !intersoccer_roster_report_has_real_date(trim($start))) {
					continue;
				}
				$start = trim($start);
				if ($start <= $today) {
					continue;
				}

				$facets = intersoccer_winback_catalogue_read_variation_facets($source_id, $kind);
				if ($facets['venue_slug'] === '' || $facets['age_group_slug'] === '') {
					continue;
				}

				$raw_variations[] = [
					'kind' => $kind,
					'variation_id' => $variation_id,
					'source_variation_id' => $source_id,
					'product_id' => $parent_id,
					'start_date' => $start,
					'product_name' => $product_name,
					'venue_slug' => $facets['venue_slug'],
					'age_group_slug' => $facets['age_group_slug'],
					'course_day' => $facets['course_day'],
					'times' => $facets['times'],
					'girls_only' => (int) $facets['girls_only'],
					'booking_type_slug' => (string) ( $facets['booking_type_slug'] ?? 'other' ),
					'full' => intersoccer_winback_is_variation_full($source_id),
				];
			}
		}

		unset($variation_posts, $variations_by_parent, $parent_kind_map, $resolved_parent_ids);

		$deduped = intersoccer_winback_dedupe_catalogue_variations($raw_variations);
		unset($raw_variations);
		$index = [];
		foreach ($deduped as $row) {
			$key = intersoccer_winback_catalogue_index_key(
				(string) $row['kind'],
				(string) $row['venue_slug'],
				(string) $row['age_group_slug']
			);
			if (!isset($index[$key])) {
				$index[$key] = [];
			}
			$index[$key][] = $row;
		}
		unset($deduped);
		foreach ($index as &$list) {
			usort($list, static function ($a, $b) {
				return strcmp((string) $a['start_date'], (string) $b['start_date']);
			});
		}
		unset($list);

		return $index;
	}
}

if (!function_exists('intersoccer_winback_catalogue_index_key')) {
	function intersoccer_winback_catalogue_index_key($kind, $venue_slug, $age_group_slug) {
		return strtolower((string) $kind) . '|' . (string) $venue_slug . '|' . (string) $age_group_slug;
	}
}

if (!function_exists('intersoccer_winback_find_earliest_catalogue_match')) {
	/**
	 * @param array<string,array<int,array<string,mixed>>> $catalogue_index
	 * @param string                                       $kind
	 * @param string                                       $venue_slug
	 * @param string                                       $age_group_slug
	 * @return array<string,mixed>|null
	 */
	function intersoccer_winback_find_earliest_catalogue_match(array $catalogue_index, $kind, $venue_slug, $age_group_slug) {
		$key = intersoccer_winback_catalogue_index_key($kind, $venue_slug, $age_group_slug);
		if (empty($catalogue_index[$key])) {
			return null;
		}

		return $catalogue_index[$key][0];
	}
}

if (!function_exists('intersoccer_winback_catalogue_rows_for_venue')) {
	/**
	 * Flatten catalogue index rows for one kind + venue, sorted by start_date.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $catalogue_index
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_winback_catalogue_rows_for_venue(array $catalogue_index, $kind, $venue_slug) {
		$prefix = strtolower((string) $kind) . '|' . (string) $venue_slug . '|';
		$rows = [];
		foreach ($catalogue_index as $key => $list) {
			if (strpos((string) $key, $prefix) !== 0) {
				continue;
			}
			foreach ((array) $list as $row) {
				if (is_array($row)) {
					$rows[] = $row;
				}
			}
		}
		usort($rows, static function ($a, $b) {
			return strcmp((string) ($a['start_date'] ?? ''), (string) ($b['start_date'] ?? ''));
		});

		return $rows;
	}
}

if (!function_exists('intersoccer_winback_catalogue_row_to_target_shape')) {
	/**
	 * @param array<string,mixed> $catalogue_row
	 * @return array{product:string,venue:string,age_group:string,start_date:string}
	 */
	function intersoccer_winback_catalogue_row_to_target_shape(array $catalogue_row, $venue_slug, $age_group_slug) {
		return [
			'product' => (string) ($catalogue_row['product_name'] ?? ''),
			'venue' => (string) $venue_slug,
			'age_group' => (string) $age_group_slug,
			'start_date' => (string) ($catalogue_row['start_date'] ?? ''),
		];
	}
}

if (!function_exists('intersoccer_winback_resolve_catalogue_target_and_more')) {
	/**
	 * Earliest upcoming catalogue match using DOB at each candidate's start date (J1), plus further targets.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $catalogue_index
	 * @return array{
	 *   target:array{product:string,venue:string,age_group:string,start_date:string}|null,
	 *   more_targets:array<int,array{product:string,venue:string,age_group:string,start_date:string}>,
	 *   aged_up:bool,
	 *   full:bool,
	 *   target_catalogue_row:array<string,mixed>|null
	 * }
	 */
	function intersoccer_winback_resolve_catalogue_target_and_more(
		array $catalogue_index,
		$kind,
		$venue_slug,
		$booked_age_slug,
		$dob
	) {
		$venue_slug = (string) $venue_slug;
		$booked_age_slug = trim((string) $booked_age_slug);
		$dob = trim((string) $dob);
		$empty = [
			'target' => null,
			'more_targets' => [],
			'aged_up' => false,
			'full' => false,
			'target_catalogue_row' => null,
		];
		if ($venue_slug === '' || $booked_age_slug === '') {
			return $empty;
		}

		if (!isset($GLOBALS['intersoccer_winback_catalogue_target_cache'])
			|| !is_array($GLOBALS['intersoccer_winback_catalogue_target_cache'])) {
			$GLOBALS['intersoccer_winback_catalogue_target_cache'] = [];
		}
		$result_cache_key = strtolower((string) $kind) . '|' . $venue_slug . '|' . $booked_age_slug . '|' . $dob;
		if (array_key_exists($result_cache_key, $GLOBALS['intersoccer_winback_catalogue_target_cache'])) {
			return $GLOBALS['intersoccer_winback_catalogue_target_cache'][ $result_cache_key ];
		}

		$venue_bucket = intersoccer_winback_catalogue_venue_cache_bucket($catalogue_index, $kind, $venue_slug);
		$available_ages = $venue_bucket['available_ages'];
		if (empty($available_ages)) {
			$GLOBALS['intersoccer_winback_catalogue_target_cache'][ $result_cache_key ] = $empty;

			return $empty;
		}

		$entries = intersoccer_winback_catalogue_dedupe_rows_by_product_week($venue_bucket['entries']);
		$target_row = null;
		$target_pick = null;
		foreach ($entries as $row) {
			$start = (string) ($row['start_date'] ?? '');
			if ($start === '') {
				continue;
			}
			$pick = intersoccer_winback_pick_target_age_slug($booked_age_slug, $dob, $start, $available_ages);
			$row_age = (string) ($row['age_group_slug'] ?? '');
			if ($row_age !== '' && $row_age === $pick['slug']) {
				$target_row = $row;
				$target_pick = $pick;
				break;
			}
		}

		if ($target_row === null || $target_pick === null) {
			$GLOBALS['intersoccer_winback_catalogue_target_cache'][ $result_cache_key ] = $empty;

			return $empty;
		}

		$target_age = (string) $target_pick['slug'];
		$target = intersoccer_winback_catalogue_row_to_target_shape($target_row, $venue_slug, $target_age);
		$target_start = (string) ($target_row['start_date'] ?? '');

		$more_targets = [];
		$seen_product_week = [];
		$target_week_key = intersoccer_winback_catalogue_product_week_key($target_row);
		$seen_product_week[ $target_week_key ] = true;
		foreach ($entries as $row) {
			if ((string) ($row['age_group_slug'] ?? '') !== $target_age) {
				continue;
			}
			$start = (string) ($row['start_date'] ?? '');
			if ($start === '' || $start <= $target_start) {
				continue;
			}
			$week_key = intersoccer_winback_catalogue_product_week_key($row);
			if (isset($seen_product_week[ $week_key ])) {
				continue;
			}
			$seen_product_week[ $week_key ] = true;
			$more_targets[] = intersoccer_winback_catalogue_row_to_target_shape($row, $venue_slug, $target_age);
			if (count($more_targets) >= 3) {
				break;
			}
		}

		$result = [
			'target' => $target,
			'more_targets' => $more_targets,
			'aged_up' => (bool) $target_pick['aged_up'],
			'full' => !empty($target_row['full']),
			'target_catalogue_row' => $target_row,
		];
		$GLOBALS['intersoccer_winback_catalogue_target_cache'][ $result_cache_key ] = $result;

		return $result;
	}
}
