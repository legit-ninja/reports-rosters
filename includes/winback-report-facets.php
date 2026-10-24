<?php
/**
 * Win-back facet normalization (EN slugs, venue/age aliases).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_winback_facet_alias_maps')) {
	/**
	 * Known roster / catalogue strings → canonical EN attribute slugs.
	 *
	 * @return array{venue:array<string,string>,age_group:array<string,string>}
	 */
	function intersoccer_winback_facet_alias_maps() {
		static $maps = null;
		if ($maps !== null) {
			return $maps;
		}
		$maps = [
			'venue' => [
				'lausanne - centre sportif de rochettaz, pully' => 'lausanne-stade-de-rochettaz-pully',
				'lausanne- stade de rochettaz, pully' => 'lausanne-stade-de-rochettaz-pully',
			],
			'age_group' => [
				'5-12' => '5-12y',
				'5-12-jahre' => '5-12y',
				'6-12-jahre' => '6-12y',
				'pre-k to g5' => '',
			],
		];

		$maps['age_group'] = apply_filters('intersoccer_winback_age_group_aliases', $maps['age_group']);

		return $maps;
	}
}

if (!function_exists('intersoccer_winback_apply_age_group_slug_alias')) {
	/**
	 * Map DE catalogue slugs (e.g. 5-12-jahre) to EN equivalents when known.
	 *
	 * @param string $slug Lowercase slug-like token.
	 */
	function intersoccer_winback_apply_age_group_slug_alias($slug, $skip_wpml = false) {
		$slug = strtolower(trim((string) $slug));
		if ($slug === '') {
			return '';
		}
		$slug = preg_replace('/-and-/', '-ans-', $slug);
		$maps = intersoccer_winback_facet_alias_maps();
		if (isset($maps['age_group'][ $slug ])) {
			return (string) $maps['age_group'][ $slug ];
		}
		if (preg_match('/^(\d+)-(\d+)-jahre-halber-tag$/', $slug, $m)) {
			return $m[1] . '-' . $m[2] . 'y-half-day';
		}
		if (preg_match('/^(\d+)-(\d+)-jahre-ganzer-tag$/', $slug, $m)) {
			return $m[1] . '-' . $m[2] . 'y-full-day';
		}
		if (preg_match('/^(\d+)-(\d+)-jahre$/', $slug, $m)) {
			return $m[1] . '-' . $m[2] . 'y';
		}
		if (preg_match('/^(\d+-\d+y)-demi-journee$/', $slug, $m)) {
			return $m[1] . '-half-day';
		}
		if (preg_match('/^(\d+-\d+y)-journee-complete$/', $slug, $m)) {
			return $m[1] . '-full-day';
		}
		if (preg_match('/^(\d+-\d+y)-journee-entiere$/', $slug, $m)) {
			return $m[1] . '-full-day';
		}
		if (preg_match('/^(\d+)-(\d+)-ans-demi-journee$/', $slug, $m)) {
			return $m[1] . '-' . $m[2] . 'y-half-day';
		}
		if (preg_match('/^(\d+)-(\d+)-ans-journee-complete$/', $slug, $m)) {
			return $m[1] . '-' . $m[2] . 'y-full-day';
		}
		if (preg_match('/^(\d+)-(\d+)-ans-journee-entiere$/', $slug, $m)) {
			return $m[1] . '-' . $m[2] . 'y-full-day';
		}
		if (preg_match('/^(\d+)-(\d+)-ans$/', $slug, $m)) {
			return $m[1] . '-' . $m[2] . 'y';
		}

		if (!$skip_wpml) {
			$wpml_slug = intersoccer_winback_apply_wpml_en_age_group_slug($slug);
			if ($wpml_slug !== '' && $wpml_slug !== $slug) {
				return intersoccer_winback_apply_age_group_slug_alias($wpml_slug, true);
			}
		}

		return $slug;
	}
}

if (!function_exists('intersoccer_winback_apply_wpml_en_age_group_slug')) {
	/**
	 * When WPML is active, map a slug to the default-language pa_age-group slug.
	 *
	 * @param string $slug Lowercase slug-like token.
	 */
	function intersoccer_winback_apply_wpml_en_age_group_slug($slug) {
		$slug = strtolower(trim((string) $slug));
		if ($slug === '' || !function_exists('get_term_by') || !function_exists('apply_filters')) {
			return '';
		}
		if (!isset($GLOBALS['intersoccer_winback_wpml_en_age_slug_cache'])
			|| !is_array($GLOBALS['intersoccer_winback_wpml_en_age_slug_cache'])) {
			$GLOBALS['intersoccer_winback_wpml_en_age_slug_cache'] = [];
		}
		if (array_key_exists($slug, $GLOBALS['intersoccer_winback_wpml_en_age_slug_cache'])) {
			return (string) $GLOBALS['intersoccer_winback_wpml_en_age_slug_cache'][ $slug ];
		}
		$term = get_term_by('slug', $slug, 'pa_age-group');
		if (!$term || is_wp_error($term)) {
			return '';
		}
		$default_lang = apply_filters('wpml_default_language', null);
		if (empty($default_lang)) {
			return '';
		}
		$en_term_id = (int) apply_filters('wpml_object_id', (int) $term->term_id, 'pa_age-group', true, $default_lang);
		if ($en_term_id <= 0) {
			return '';
		}
		$en_term = get_term($en_term_id, 'pa_age-group');
		if (!$en_term || is_wp_error($en_term) || empty($en_term->slug)) {
			$GLOBALS['intersoccer_winback_wpml_en_age_slug_cache'][ $slug ] = '';

			return '';
		}

		$mapped = strtolower((string) $en_term->slug);
		$GLOBALS['intersoccer_winback_wpml_en_age_slug_cache'][ $slug ] = $mapped;

		return $mapped;
	}
}

if (!function_exists('intersoccer_winback_apply_wpml_en_venue_slug')) {
	/**
	 * When WPML is active, map a slug to the default-language pa_intersoccer-venues slug.
	 *
	 * @param string $slug Lowercase slug-like token.
	 */
	function intersoccer_winback_apply_wpml_en_venue_slug($slug) {
		$slug = strtolower(trim((string) $slug));
		if ($slug === '' || !function_exists('get_term_by') || !function_exists('apply_filters')) {
			return '';
		}
		if (!isset($GLOBALS['intersoccer_winback_wpml_en_venue_slug_cache'])
			|| !is_array($GLOBALS['intersoccer_winback_wpml_en_venue_slug_cache'])) {
			$GLOBALS['intersoccer_winback_wpml_en_venue_slug_cache'] = [];
		}
		if (array_key_exists($slug, $GLOBALS['intersoccer_winback_wpml_en_venue_slug_cache'])) {
			return (string) $GLOBALS['intersoccer_winback_wpml_en_venue_slug_cache'][ $slug ];
		}
		$term = get_term_by('slug', $slug, 'pa_intersoccer-venues');
		if (!$term || is_wp_error($term)) {
			return '';
		}
		$default_lang = apply_filters('wpml_default_language', null);
		if (empty($default_lang)) {
			return '';
		}
		$en_term_id = (int) apply_filters('wpml_object_id', (int) $term->term_id, 'pa_intersoccer-venues', true, $default_lang);
		if ($en_term_id <= 0) {
			return '';
		}
		$en_term = get_term($en_term_id, 'pa_intersoccer-venues');
		if (!$en_term || is_wp_error($en_term) || empty($en_term->slug)) {
			$GLOBALS['intersoccer_winback_wpml_en_venue_slug_cache'][ $slug ] = '';

			return '';
		}

		$mapped = strtolower((string) $en_term->slug);
		$GLOBALS['intersoccer_winback_wpml_en_venue_slug_cache'][ $slug ] = $mapped;

		return $mapped;
	}
}

if (!function_exists('intersoccer_winback_facet_resolution_cache_store')) {
	/**
	 * @return array<string,string>
	 */
	function &intersoccer_winback_facet_resolution_cache_store() {
		static $cache = [];
		return $cache;
	}

	function intersoccer_winback_reset_facet_resolution_cache() {
		$cache = &intersoccer_winback_facet_resolution_cache_store();
		$cache = [];
		unset($GLOBALS['intersoccer_winback_facet_term_slug_cache']);
		unset($GLOBALS['intersoccer_winback_facet_normalize_cache']);
		unset($GLOBALS['intersoccer_winback_wpml_en_age_slug_cache']);
	}
}

if (!function_exists('intersoccer_winback_facet_term_slug_is_known')) {
	/**
	 * Memoized get_term_by slug check per taxonomy.
	 */
	function intersoccer_winback_facet_term_slug_is_known($slug, $taxonomy) {
		$slug = strtolower(trim((string) $slug));
		if ($slug === '') {
			return false;
		}
		if (!isset($GLOBALS['intersoccer_winback_facet_term_slug_cache'])
			|| !is_array($GLOBALS['intersoccer_winback_facet_term_slug_cache'])) {
			$GLOBALS['intersoccer_winback_facet_term_slug_cache'] = [];
		}
		$cache_key = (string) $taxonomy . '|' . $slug;
		if (array_key_exists($cache_key, $GLOBALS['intersoccer_winback_facet_term_slug_cache'])) {
			return (bool) $GLOBALS['intersoccer_winback_facet_term_slug_cache'][ $cache_key ];
		}
		$known = intersoccer_winback_facet_slug_is_known_term($slug, $taxonomy);
		$GLOBALS['intersoccer_winback_facet_term_slug_cache'][ $cache_key ] = $known;

		return $known;
	}
}

if (!function_exists('intersoccer_winback_facet_runtime_term_lookup_available')) {
	function intersoccer_winback_facet_runtime_term_lookup_available() {
		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			return isset($GLOBALS['intersoccer_test_winback_term_slugs']) && is_array($GLOBALS['intersoccer_test_winback_term_slugs']);
		}

		return function_exists('get_term_by');
	}
}

if (!function_exists('intersoccer_winback_facet_slug_is_known_term')) {
	/**
	 * @param string $taxonomy pa_intersoccer-venues|pa_age-group
	 */
	function intersoccer_winback_facet_slug_is_known_term($slug, $taxonomy) {
		$slug = strtolower(trim((string) $slug));
		if ($slug === '') {
			return false;
		}
		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING
			&& isset($GLOBALS['intersoccer_test_winback_term_slugs'][ $taxonomy ])
			&& is_array($GLOBALS['intersoccer_test_winback_term_slugs'][ $taxonomy ])) {
			return in_array($slug, $GLOBALS['intersoccer_test_winback_term_slugs'][ $taxonomy ], true);
		}
		if (!function_exists('get_term_by')) {
			return true;
		}
		$term = get_term_by('slug', $slug, $taxonomy);
		return $term && !is_wp_error($term);
	}
}

if (!function_exists('intersoccer_winback_facet_is_multi_value')) {
	/**
	 * Detect roster facet values that concatenate multiple catalogue terms.
	 *
	 * @param string $raw  Raw roster column value.
	 * @param string $kind venue|age_group
	 */
	function intersoccer_winback_facet_is_multi_value($raw, $kind) {
		$raw = trim((string) $raw);
		if ($raw === '' || $raw === 'N/A') {
			return false;
		}
		$lower = strtolower($raw);
		if ($kind === 'venue') {
			if (strpos($lower, '---') !== false) {
				return true;
			}
		}
		if ($kind === 'age_group') {
			if (preg_match_all('/\d+-\d+y/', $lower, $bands) && count($bands[0]) >= 2) {
				return true;
			}
		}
		if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $lower)) {
			return false;
		}
		if (!intersoccer_winback_facet_runtime_term_lookup_available()) {
			return false;
		}
		$taxonomy = $kind === 'age_group' ? 'pa_age-group' : 'pa_intersoccer-venues';

		return !intersoccer_winback_facet_term_slug_is_known($lower, $taxonomy);
	}
}

if (!function_exists('intersoccer_winback_resolve_roster_facet_raw')) {
	/**
	 * Resolve a single facet value from variation / order line attributes (not roster denorm).
	 *
	 * @param array<string,mixed> $row  Roster row with variation_id and/or order_item_id.
	 * @param string              $kind venue|age_group
	 */
	function intersoccer_winback_resolve_roster_facet_raw(array $row, $kind) {
		$kind = $kind === 'age_group' ? 'age_group' : 'venue';
		$order_item_id = (int) ( $row['order_item_id'] ?? 0 );
		$variation_id = (int) ( $row['variation_id'] ?? 0 );
		$cache_key = $kind . '|' . $order_item_id . '|' . $variation_id;
		$cache = &intersoccer_winback_facet_resolution_cache_store();
		if (array_key_exists($cache_key, $cache)) {
			return (string) $cache[ $cache_key ];
		}

		$resolved = '';
		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			if (isset($GLOBALS['intersoccer_test_winback_facet_resolution_callback'])
				&& is_callable($GLOBALS['intersoccer_test_winback_facet_resolution_callback'])) {
				$resolved = (string) call_user_func($GLOBALS['intersoccer_test_winback_facet_resolution_callback'], $row, $kind);
				$cache[ $cache_key ] = $resolved;

				return $resolved;
			}
			if ($order_item_id > 0
				&& isset($GLOBALS['intersoccer_test_winback_facet_resolution'][ $order_item_id ][ $kind ])) {
				$resolved = (string) $GLOBALS['intersoccer_test_winback_facet_resolution'][ $order_item_id ][ $kind ];
				$cache[ $cache_key ] = $resolved;

				return $resolved;
			}
		}

		$taxonomy = $kind === 'age_group' ? 'pa_age-group' : 'pa_intersoccer-venues';
		$attr_post_key = 'attribute_' . $taxonomy;
		$canonical_key = $kind === 'age_group' ? '_intersoccer_canonical_age_group' : '_intersoccer_canonical_venue';
		$meta_keys = [
			$taxonomy,
			$attr_post_key,
			$canonical_key,
		];
		if ($kind === 'venue') {
			$meta_keys[] = 'attribute_pa_intersoccer_venues';
			$meta_keys[] = 'pa_intersoccer_venues';
		}

		if ($variation_id > 0 && function_exists('intersoccer_get_default_language_variation_id')) {
			$variation_id = (int) intersoccer_get_default_language_variation_id($variation_id);
		}

		if ($variation_id > 0 && function_exists('get_post_meta')) {
			$from_variation = (string) get_post_meta($variation_id, $attr_post_key, true);
			if ($from_variation !== '') {
				$resolved = $from_variation;
			}
		}

		if ($resolved === '' && $order_item_id > 0 && function_exists('wc_get_order_item_meta')) {
			foreach ($meta_keys as $meta_key) {
				$val = (string) wc_get_order_item_meta($order_item_id, $meta_key, true);
				if ($val !== '') {
					$resolved = $val;
					break;
				}
			}
		}

		if ($resolved === '' && $variation_id > 0 && function_exists('wc_get_product')) {
			$product = wc_get_product($variation_id);
			if ($product && method_exists($product, 'get_attribute')) {
				$resolved = (string) $product->get_attribute($taxonomy);
			}
		}

		$cache[ $cache_key ] = $resolved;

		return $resolved;
	}
}

if (!function_exists('intersoccer_winback_prime_facet_resolution_from_rows')) {
	/**
	 * Warm facet resolution cache for many roster rows (one pass, shared keys).
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param string[]                       $kinds venue|age_group
	 */
	function intersoccer_winback_prime_facet_resolution_from_rows(array $rows, array $kinds = ['venue', 'age_group']) {
		$variation_ids = [];
		$order_item_ids = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$variation_id = (int) ( $row['variation_id'] ?? 0 );
			if ($variation_id > 0) {
				$variation_ids[ $variation_id ] = $variation_id;
			}
			$order_item_id = (int) ( $row['order_item_id'] ?? 0 );
			if ($order_item_id > 0) {
				$order_item_ids[ $order_item_id ] = $order_item_id;
			}
		}
		if (!empty($variation_ids) && function_exists('update_meta_cache')) {
			update_meta_cache('post', array_values($variation_ids));
		}
		if (!empty($order_item_ids) && function_exists('intersoccer_winback_batch_prime_order_item_facet_meta')) {
			intersoccer_winback_batch_prime_order_item_facet_meta(array_values($order_item_ids));
		}
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			foreach ($kinds as $kind) {
				if ($kind !== 'venue' && $kind !== 'age_group') {
					continue;
				}
				$field = $kind === 'age_group' ? 'age_group' : 'venue';
				$raw = trim((string) ( $row[ $field ] ?? '' ));
				if ($raw === '' || !intersoccer_winback_facet_is_multi_value($raw, $kind)) {
					continue;
				}
				intersoccer_winback_resolve_roster_facet_raw($row, $kind);
			}
		}
	}
}

if (!function_exists('intersoccer_winback_batch_prime_order_item_facet_meta')) {
	/**
	 * Preload order-item facet meta into the facet resolution cache (one query per chunk).
	 *
	 * @param int[] $order_item_ids
	 */
	function intersoccer_winback_batch_prime_order_item_facet_meta(array $order_item_ids) {
		$order_item_ids = array_values(array_unique(array_filter(array_map('intval', $order_item_ids))));
		if (empty($order_item_ids)) {
			return;
		}
		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			return;
		}
		global $wpdb;
		if (!is_object($wpdb) || !isset($wpdb->prefix)) {
			return;
		}
		$meta_keys = [
			'pa_intersoccer-venues',
			'attribute_pa_intersoccer-venues',
			'_intersoccer_canonical_venue',
			'attribute_pa_intersoccer_venues',
			'pa_intersoccer_venues',
			'pa_age-group',
			'attribute_pa_age-group',
			'_intersoccer_canonical_age_group',
		];
		$cache = &intersoccer_winback_facet_resolution_cache_store();
		foreach (array_chunk($order_item_ids, 500) as $chunk) {
			$placeholders = implode(',', array_fill(0, count($chunk), '%d'));
			$key_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
			$sql = "SELECT order_item_id, meta_key, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta
				WHERE order_item_id IN ({$placeholders}) AND meta_key IN ({$key_placeholders})";
			$params = array_merge($chunk, $meta_keys);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
			$by_item = [];
			foreach ((array) $results as $result) {
				if (!is_array($result)) {
					continue;
				}
				$item_id = (int) ( $result['order_item_id'] ?? 0 );
				$key = (string) ( $result['meta_key'] ?? '' );
				$val = (string) ( $result['meta_value'] ?? '' );
				if ($item_id <= 0 || $key === '' || $val === '') {
					continue;
				}
				if (!isset($by_item[ $item_id ])) {
					$by_item[ $item_id ] = [];
				}
				$by_item[ $item_id ][ $key ] = $val;
			}
			foreach ($by_item as $item_id => $meta_map) {
				foreach (['venue', 'age_group'] as $kind) {
					$taxonomy = $kind === 'age_group' ? 'pa_age-group' : 'pa_intersoccer-venues';
					$attr_post_key = 'attribute_' . $taxonomy;
					$canonical_key = $kind === 'age_group' ? '_intersoccer_canonical_age_group' : '_intersoccer_canonical_venue';
					$keys = [$taxonomy, $attr_post_key, $canonical_key];
					if ($kind === 'venue') {
						$keys[] = 'attribute_pa_intersoccer_venues';
						$keys[] = 'pa_intersoccer_venues';
					}
					$resolved = '';
					foreach ($keys as $meta_key) {
						if (!empty($meta_map[ $meta_key ])) {
							$resolved = (string) $meta_map[ $meta_key ];
							break;
						}
					}
					$variation_id = 0;
					$cache_key = $kind . '|' . (int) $item_id . '|' . $variation_id;
					if (!array_key_exists($cache_key, $cache)) {
						$cache[ $cache_key ] = $resolved;
					}
				}
			}
		}
	}
}

if (!function_exists('intersoccer_winback_normalize_roster_facet_slug')) {
	/**
	 * Normalize a roster facet, resolving multi-value denorm strings from the order line when needed.
	 *
	 * @param string              $raw       Roster column value.
	 * @param string              $taxonomy  Attribute taxonomy.
	 * @param string              $kind      venue|age_group.
	 * @param array<string,mixed> $row       Optional roster row for order/variation lookup.
	 */
	function intersoccer_winback_normalize_roster_facet_slug($raw, $taxonomy, $kind = 'venue', array $row = []) {
		$raw = trim((string) $raw);
		if ($raw === '' || $raw === 'N/A') {
			return '';
		}
		if (intersoccer_winback_facet_is_multi_value($raw, $kind)) {
			$resolved_raw = intersoccer_winback_resolve_roster_facet_raw($row, $kind);
			if ($resolved_raw === '' || intersoccer_winback_facet_is_multi_value($resolved_raw, $kind)) {
				intersoccer_winback_register_unmapped_facet($kind, $raw);

				return '';
			}

			return intersoccer_winback_normalize_facet_slug($resolved_raw, $taxonomy, $kind);
		}

		return intersoccer_winback_normalize_facet_slug($raw, $taxonomy, $kind);
	}
}

if (!function_exists('intersoccer_winback_register_unmapped_facet')) {
	/**
	 * @internal Tests reset via intersoccer_winback_reset_unmapped_facets().
	 */
	function &intersoccer_winback_unmapped_facet_store() {
		static $store = [];
		return $store;
	}

	function intersoccer_winback_reset_unmapped_facets() {
		$store = &intersoccer_winback_unmapped_facet_store();
		$store = [];
	}

	/**
	 * @param string $kind venue|age_group
	 * @param string $raw  Raw roster or attribute value.
	 */
	function intersoccer_winback_register_unmapped_facet($kind, $raw) {
		$raw = trim((string) $raw);
		if ($raw === '') {
			return;
		}
		$store = &intersoccer_winback_unmapped_facet_store();
		$kind = $kind === 'age_group' ? 'age_group' : 'venue';
		if (!isset($store[$kind][$raw])) {
			$store[$kind][$raw] = 0;
		}
		$store[$kind][$raw]++;
	}

	/**
	 * @return array{venue:array<string,int>,age_group:array<string,int>}
	 */
	function intersoccer_winback_get_unmapped_facets() {
		$store = intersoccer_winback_unmapped_facet_store();
		return [
			'venue' => $store['venue'] ?? [],
			'age_group' => $store['age_group'] ?? [],
		];
	}
}

if (!function_exists('intersoccer_winback_age_group_slug_is_known_catalogue_band')) {
	/**
	 * Whether a normalized EN age slug exists in the upcoming catalogue or as pa_age-group.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $catalogue_index
	 */
	function intersoccer_winback_age_group_slug_is_known_catalogue_band($slug, array $catalogue_index, $kind, $venue_slug) {
		$slug = trim((string) $slug);
		if ($slug === '') {
			return true;
		}
		$venue_slug = (string) $venue_slug;
		if ($venue_slug !== '' && function_exists('intersoccer_winback_catalogue_venue_cache_bucket')) {
			$bucket = intersoccer_winback_catalogue_venue_cache_bucket($catalogue_index, $kind, $venue_slug);
			if (in_array($slug, $bucket['available_ages'], true)) {
				return true;
			}
		} else {
			$prefix = strtolower((string) $kind) . '|';
			foreach ($catalogue_index as $key => $list) {
				if (strpos((string) $key, $prefix) !== 0) {
					continue;
				}
				foreach ((array) $list as $row) {
					if (is_array($row) && (string) ( $row['age_group_slug'] ?? '' ) === $slug) {
						return true;
					}
				}
			}
		}
		if (function_exists('get_term_by')) {
			$term = get_term_by('slug', $slug, 'pa_age-group');
			if ($term && !is_wp_error($term)) {
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('intersoccer_winback_register_unmapped_age_if_not_catalogue_band')) {
	/**
	 * Count alias-resolved age slugs that have no matching catalogue band (admin notice).
	 *
	 * Registers the normalized EN slug (not the raw roster value).
	 *
	 * @param array<string,array<int,array<string,mixed>>> $catalogue_index
	 */
	function intersoccer_winback_register_unmapped_age_if_not_catalogue_band($age_slug, array $catalogue_index, $kind, $venue_slug) {
		$age_slug = trim((string) $age_slug);
		if ($age_slug === '') {
			return;
		}
		if (!intersoccer_winback_age_group_slug_is_known_catalogue_band($age_slug, $catalogue_index, $kind, $venue_slug)) {
			intersoccer_winback_register_unmapped_facet('age_group', $age_slug);
		}
	}
}

if (!function_exists('intersoccer_winback_parse_age_group_bounds')) {
	/**
	 * Parse min/max child age from a label or slug. Unparseable bands return null.
	 *
	 * @param string $label Age group display name or slug.
	 * @return array{min:int,max:int}|null
	 */
	function intersoccer_winback_parse_age_group_bounds($label) {
		$label = trim((string) $label);
		if ($label === '') {
			return null;
		}
		$lower = strtolower($label);
		if (strpos($lower, 'pre-k') !== false || strpos($lower, 'g5') !== false) {
			return null;
		}
		if (preg_match('/(\d+)\s*-\s*(\d+)\s*y/i', $label, $m)) {
			return ['min' => (int) $m[1], 'max' => (int) $m[2]];
		}
		if (preg_match('/(\d+)\s*-\s*(\d+)(?:\s|$)/', $label, $m)) {
			return ['min' => (int) $m[1], 'max' => (int) $m[2]];
		}
		if (preg_match('/u(\d+)/i', $label, $m) || preg_match('/under\s*(\d+)/i', $label, $m)) {
			return ['min' => 0, 'max' => (int) $m[1]];
		}

		return null;
	}
}

if (!function_exists('intersoccer_winback_compute_age_at_date')) {
	/**
	 * @param string $dob     Y-m-d.
	 * @param string $on_date Y-m-d.
	 */
	function intersoccer_winback_compute_age_at_date($dob, $on_date) {
		$dob = trim((string) $dob);
		$on_date = trim((string) $on_date);
		if (!intersoccer_roster_report_has_real_date($dob) || !intersoccer_roster_report_has_real_date($on_date)) {
			return null;
		}
		try {
			$born = new \DateTimeImmutable($dob);
			$at = new \DateTimeImmutable($on_date);

			return (int) $born->diff($at)->y;
		} catch (\Exception $e) {
			return null;
		}
	}
}

if (!function_exists('intersoccer_winback_normalize_facet_slug')) {
	/**
	 * Normalize a roster facet value to an EN taxonomy slug (pure path for tests).
	 *
	 * @param string $raw       Roster or attribute value.
	 * @param string $taxonomy  pa_intersoccer-venues|pa_age-group|...
	 * @param string $kind      venue|age_group for alias + unmapped tracking.
	 * @param callable|null     $slug_resolver Optional ($name,$taxonomy) => slug.
	 */
	function intersoccer_winback_normalize_facet_slug($raw, $taxonomy, $kind = 'venue', $slug_resolver = null) {
		$raw = trim((string) $raw);
		if ($raw === '' || $raw === 'N/A') {
			return '';
		}
		$maps = intersoccer_winback_facet_alias_maps();
		$alias_key = strtolower(preg_replace('/\s+/', ' ', $raw));
		if (isset($maps[$kind][$alias_key])) {
			return (string) $maps[$kind][$alias_key];
		}
		if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', strtolower($raw))) {
			$slug = strtolower($raw);
			if ($kind === 'age_group') {
				return intersoccer_winback_apply_age_group_slug_alias($slug);
			}

			return $slug;
		}
		if (is_callable($slug_resolver)) {
			$slug = (string) $slug_resolver($raw, $taxonomy);
			if ($slug !== '') {
				return $slug;
			}
		} elseif (function_exists('intersoccer_get_term_slug_by_name')) {
			$slug = (string) intersoccer_get_term_slug_by_name($raw, $taxonomy);
			if ($slug !== '') {
				return $slug;
			}
		}
		intersoccer_winback_register_unmapped_facet($kind, $raw);

		return '';
	}
}

if (!function_exists('intersoccer_winback_reset_en_unpublished_variations')) {
	/**
	 * @internal Tests reset via intersoccer_winback_reset_en_unpublished_variations().
	 */
	function intersoccer_winback_reset_en_unpublished_variations() {
		$GLOBALS['intersoccer_winback_en_unpublished_variations'] = [];
	}

	/**
	 * @param array{
	 *   en_variation_id:int,
	 *   en_variation_status:string,
	 *   en_parent_status:string,
	 *   reason:'variation'|'parent',
	 *   translation_variation_id:int,
	 *   product:string,
	 *   start_date:string,
	 *   venue:string,
	 *   kind:string
	 * } $entry
	 */
	function intersoccer_winback_register_en_unpublished_variation(array $entry) {
		$en_id = (int) ( $entry['en_variation_id'] ?? 0 );
		if ($en_id <= 0) {
			return;
		}
		if (!isset($GLOBALS['intersoccer_winback_en_unpublished_variations'])
			|| !is_array($GLOBALS['intersoccer_winback_en_unpublished_variations'])) {
			$GLOBALS['intersoccer_winback_en_unpublished_variations'] = [];
		}
		$store = &$GLOBALS['intersoccer_winback_en_unpublished_variations'];
		$translation_id = (int) ( $entry['translation_variation_id'] ?? 0 );
		$reason = (string) ( $entry['reason'] ?? '' );
		if ($reason !== 'parent' && $reason !== 'variation') {
			$reason = 'variation';
		}
		if (!isset($store[ $en_id ])) {
			$store[ $en_id ] = [
				'en_variation_id' => $en_id,
				'en_variation_status' => (string) ( $entry['en_variation_status'] ?? '' ),
				'en_parent_status' => (string) ( $entry['en_parent_status'] ?? '' ),
				'reason' => $reason,
				'translation_variation_ids' => [],
				'product' => (string) ( $entry['product'] ?? '' ),
				'start_date' => (string) ( $entry['start_date'] ?? '' ),
				'venue' => (string) ( $entry['venue'] ?? '' ),
				'kind' => (string) ( $entry['kind'] ?? '' ),
			];
		} else {
			if ($reason === 'parent' || (string) ( $store[ $en_id ]['reason'] ?? '' ) === 'parent') {
				$store[ $en_id ]['reason'] = 'parent';
			}
			if (isset($entry['en_variation_status'])) {
				$store[ $en_id ]['en_variation_status'] = (string) $entry['en_variation_status'];
			}
			if (isset($entry['en_parent_status'])) {
				$store[ $en_id ]['en_parent_status'] = (string) $entry['en_parent_status'];
			}
		}
		if ($translation_id > 0 && $translation_id !== $en_id) {
			$store[ $en_id ]['translation_variation_ids'][ $translation_id ] = $translation_id;
		}
	}

	/**
	 * @return array<int,array{
	 *   en_variation_id:int,
	 *   en_variation_status:string,
	 *   en_parent_status:string,
	 *   reason:'variation'|'parent',
	 *   translation_variation_ids:int[],
	 *   product:string,
	 *   start_date:string,
	 *   venue:string,
	 *   kind:string
	 * }>
	 */
	function intersoccer_winback_get_en_unpublished_variations() {
		$store = $GLOBALS['intersoccer_winback_en_unpublished_variations'] ?? [];
		if (!is_array($store) || empty($store)) {
			return [];
		}
		$list = array_values($store);
		foreach ($list as &$row) {
			if (!is_array($row)) {
				continue;
			}
			$ids = array_values(array_unique(array_filter(array_map('intval', (array) ( $row['translation_variation_ids'] ?? [] )))));
			sort($ids, SORT_NUMERIC);
			$row['translation_variation_ids'] = $ids;
		}
		unset($row);
		usort($list, static function ($a, $b) {
			$cmp = strcmp((string) ( $a['start_date'] ?? '' ), (string) ( $b['start_date'] ?? '' ));
			if ($cmp !== 0) {
				return $cmp;
			}

			return (int) ( $a['en_variation_id'] ?? 0 ) <=> (int) ( $b['en_variation_id'] ?? 0 );
		});

		return $list;
	}
}

if (!function_exists('intersoccer_winback_decode_catalogue_product_title')) {
	function intersoccer_winback_decode_catalogue_product_title($title) {
		$title = (string) $title;
		if ($title === '') {
			return '';
		}
		if (function_exists('wp_specialchars_decode')) {
			$title = wp_specialchars_decode($title, ENT_QUOTES);
		}

		return html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}

if (!function_exists('intersoccer_winback_dedupe_catalogue_variations')) {
	/**
	 * Dedupe WPML translation groups by default-language variation id.
	 *
	 * @param array<int,array<string,mixed>> $variations Each must include variation_id and source_variation_id.
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_winback_dedupe_catalogue_variations(array $variations) {
		$by_source = [];
		foreach ($variations as $row) {
			if (!is_array($row)) {
				continue;
			}
			$source_id = (int) ($row['source_variation_id'] ?? $row['variation_id'] ?? 0);
			if ($source_id <= 0) {
				continue;
			}
			$start = (string) ($row['start_date'] ?? '');
			if (!isset($by_source[$source_id]) || $start < (string) ($by_source[$source_id]['start_date'] ?? '')) {
				$by_source[$source_id] = $row;
				continue;
			}
			if ($start === (string) ($by_source[$source_id]['start_date'] ?? '')) {
				$by_source[$source_id] = $row;
			}
		}

		return array_values($by_source);
	}
}

if (!function_exists('intersoccer_winback_detect_rebooked_flags')) {
	/**
	 * J2b: upcoming roster rows for the same player/kind.
	 *
	 * @param array<int,array<string,mixed>> $upcoming Rows with player_key, kind, venue_slug.
	 * @return array{same_venue:bool,elsewhere:bool}
	 */
	function intersoccer_winback_detect_rebooked_flags($player_key, $kind, $history_venue_slug, array $upcoming, $identity_map = null) {
		$same_venue = false;
		$elsewhere = false;
		foreach ($upcoming as $row) {
			if (!is_array($row)) {
				continue;
			}
			if ((string) ($row['kind'] ?? '') !== (string) $kind) {
				continue;
			}
			$upcoming_key = (string) ($row['player_key'] ?? '');
			$same_player = $upcoming_key === (string) $player_key;
			if (!$same_player && $identity_map instanceof \Intersoccer_Roster_Report_Player_Identity_Map) {
				$same_player = $identity_map->same_player((string) $player_key, $upcoming_key);
			}
			if (!$same_player) {
				continue;
			}
			$row_venue = (string) ($row['venue_slug'] ?? '');
			if ($history_venue_slug !== '' && $row_venue !== '' && $row_venue === $history_venue_slug) {
				$same_venue = true;
			} else {
				$elsewhere = true;
			}
		}

		return ['same_venue' => $same_venue, 'elsewhere' => $elsewhere];
	}
}

if (!function_exists('intersoccer_winback_girls_only_compatible')) {
	/**
	 * J13: girls-only history can match mixed upcoming and vice versa.
	 *
	 * @param int $history_girls_only 0|1
	 * @param int $catalogue_girls_only 0|1
	 */
	function intersoccer_winback_girls_only_compatible($history_girls_only, $catalogue_girls_only) {
		return true;
	}
}

if (!function_exists('intersoccer_winback_pick_target_age_slug')) {
	/**
	 * Choose target age slug for catalogue lookup (J1 aged-up uses eligible slug when known).
	 *
	 * @param string               $booked_age_slug Last booked EN slug.
	 * @param string|null          $dob
	 * @param string               $target_start    Y-m-d catalogue start.
	 * @param array<int,string>    $available_slugs Slugs offered at venue in catalogue.
	 */
	function intersoccer_winback_pick_target_age_slug($booked_age_slug, $dob, $target_start, array $available_slugs) {
		$booked_age_slug = trim((string) $booked_age_slug);
		$bounds = intersoccer_winback_parse_age_group_bounds($booked_age_slug);
		$age = intersoccer_winback_compute_age_at_date($dob, $target_start);
		$aged_up = false;
		if ($bounds !== null && $age !== null && $age > $bounds['max']) {
			$aged_up = true;
			$candidates = [];
			foreach ($available_slugs as $slug) {
				$b = intersoccer_winback_parse_age_group_bounds($slug);
				if ($b === null) {
					continue;
				}
				if ($age >= $b['min'] && $age <= $b['max']) {
					$candidates[] = $slug;
				}
			}
			if (!empty($candidates)) {
				usort($candidates, static function ($a, $b) {
					$ba = intersoccer_winback_parse_age_group_bounds($a);
					$bb = intersoccer_winback_parse_age_group_bounds($b);
					return ($ba['min'] ?? 0) <=> ($bb['min'] ?? 0);
				});

				return ['slug' => $candidates[0], 'aged_up' => true];
			}
		}

		return ['slug' => $booked_age_slug, 'aged_up' => $aged_up];
	}
}
