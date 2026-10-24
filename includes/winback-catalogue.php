<?php
/**
 * Upcoming camp/course catalogue enumeration for win-back matching.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

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
		if (function_exists('intersoccer_roster_row_matches_listing_kind')) {
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
			list($parsed_start,,, $source) = intersoccer_reports_resolve_camp_schedule(
				$variation_id,
				(int) ($row['order_item_id'] ?? 0),
				(string) ($row['camp_terms'] ?? ''),
				(string) ($row['season'] ?? '')
			);
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

		$order_id = (int) ($row['order_id'] ?? 0);
		if ($order_id > 0) {
			$order_date = '';
			if (function_exists('wc_get_order')) {
				$order = wc_get_order($order_id);
				if ($order && $order->get_date_created()) {
					$order_date = $order->get_date_created()->date('Y-m-d');
				}
			}
			if ($order_date === '') {
				global $wpdb;
				$order_date = (string) $wpdb->get_var(
					$wpdb->prepare("SELECT post_date FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $order_id)
				);
				if ($order_date !== '') {
					$order_date = substr($order_date, 0, 10);
				}
			}
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
		$product = function_exists('wc_get_product') ? wc_get_product($variation_id) : null;
		$venue = '';
		$age = '';
		$course_day = '';
		$times = '';
		if ($product) {
			$venue = (string) $product->get_attribute('pa_intersoccer-venues');
			$age = (string) $product->get_attribute('pa_age-group');
			if ($kind === 'course') {
				$course_day = (string) $product->get_attribute('pa_course-day');
				$times = (string) $product->get_attribute('pa_course-times');
			}
		}
		$girls_only = 0;
		if ($product && method_exists($product, 'get_parent_id')) {
			$parent_id = (int) $product->get_parent_id();
			if ($parent_id > 0 && function_exists('intersoccer_resolve_roster_girls_only_product_attribute')) {
				$girls_only = intersoccer_resolve_roster_girls_only_product_attribute($parent_id) ? 1 : 0;
			}
		}

		return [
			'venue_slug' => intersoccer_winback_normalize_facet_slug($venue, 'pa_intersoccer-venues', 'venue'),
			'age_group_slug' => intersoccer_winback_normalize_facet_slug($age, 'pa_age-group', 'age_group'),
			'course_day' => $course_day,
			'times' => $times,
			'girls_only' => $girls_only,
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
		if (!function_exists('wc_get_product')) {
			return false;
		}
		$product = wc_get_product((int) $variation_id);
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

		$default_lang = function_exists('wpml_get_default_language') ? (string) wpml_get_default_language() : 'en';
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

		foreach ((array) $parent_ids as $parent_id) {
			$parent_id = (int) $parent_id;
			if ($parent_id <= 0) {
				continue;
			}
			$ptype = get_post_meta($parent_id, '_intersoccer_product_type', true);
			$kind = strtolower((string) $ptype) === 'course' ? 'course' : 'camp';
			if (function_exists('intersoccer_get_default_language_product_id')) {
				$parent_id = (int) intersoccer_get_default_language_product_id($parent_id);
			} elseif (function_exists('apply_filters') && function_exists('wpml_get_default_language')) {
				$mapped = (int) apply_filters('wpml_object_id', $parent_id, 'product', true, $default_lang);
				if ($mapped > 0) {
					$parent_id = $mapped;
				}
			}
			if (get_post_status($parent_id) !== 'publish') {
				continue;
			}

			$children = get_posts([
				'post_type' => 'product_variation',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'fields' => 'ids',
			]);

			foreach ((array) $children as $variation_id) {
				$variation_id = (int) $variation_id;
				$source_id = $variation_id;
				if (function_exists('intersoccer_get_default_language_variation_id')) {
					$source_id = (int) intersoccer_get_default_language_variation_id($variation_id);
				}
				if ($source_id <= 0) {
					continue;
				}
				if (get_post_status($source_id) !== 'publish') {
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
					'product_name' => get_the_title($parent_id),
					'venue_slug' => $facets['venue_slug'],
					'age_group_slug' => $facets['age_group_slug'],
					'course_day' => $facets['course_day'],
					'times' => $facets['times'],
					'girls_only' => (int) $facets['girls_only'],
					'full' => intersoccer_winback_is_variation_full($source_id),
				];
			}
		}

		$deduped = intersoccer_winback_dedupe_catalogue_variations($raw_variations);
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
