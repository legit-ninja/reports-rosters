<?php
/**
 * Batched product-type resolution for PCS / win-back cold builds (mirrors PV precedence without per-row PV calls).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_rr_product_type_batch_scope_active')) {
	function intersoccer_rr_product_type_batch_scope_active() {
		return function_exists('intersoccer_rr_product_type_memo_scope_active')
			&& intersoccer_rr_product_type_memo_scope_active();
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_reset')) {
	function intersoccer_rr_product_type_batch_reset() {
		unset($GLOBALS['intersoccer_rr_product_type_batch_loaded'], $GLOBALS['intersoccer_rr_product_type_batch_id_set']);
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_id_in_set')) {
	function intersoccer_rr_product_type_batch_id_in_set($post_id) {
		$post_id = (int) $post_id;

		return $post_id > 0
			&& !empty($GLOBALS['intersoccer_rr_product_type_batch_id_set'][ $post_id ]);
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_wpml_object_id')) {
	function intersoccer_rr_product_type_batch_wpml_object_id(
		$element_id,
		$element_type = 'post',
		$return_original_if_missing = false,
		$language_code = null
	) {
		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			$GLOBALS['intersoccer_rr_test_wpml_object_id_calls'] = (int) ( $GLOBALS['intersoccer_rr_test_wpml_object_id_calls'] ?? 0 ) + 1;
		}
		if (!function_exists('apply_filters')) {
			return (int) $element_id;
		}

		return (int) apply_filters('wpml_object_id', (int) $element_id, $element_type, $return_original_if_missing, $language_code);
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_parent_id_from_cache')) {
	function intersoccer_rr_product_type_batch_parent_id_from_cache($post_id) {
		$post_id = (int) $post_id;
		if ($post_id <= 0) {
			return 0;
		}
		if (function_exists('wp_cache_get')) {
			$post = wp_cache_get($post_id, 'posts');
			if (is_object($post) && isset($post->post_parent)) {
				return (int) $post->post_parent;
			}
		}
		if (isset($GLOBALS['intersoccer_test_post_parents'][ $post_id ])) {
			return (int) $GLOBALS['intersoccer_test_post_parents'][ $post_id ];
		}
		if (function_exists('intersoccer_rr_test_api_call_count')) {
			intersoccer_rr_test_api_call_count('wp_get_post_parent_id');
		}
		if (function_exists('wp_get_post_parent_id')) {
			return (int) wp_get_post_parent_id($post_id);
		}

		return 0;
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_activity_translation_map')) {
	/**
	 * @return array<string,string[]>
	 */
	function intersoccer_rr_product_type_batch_activity_translation_map() {
		return [
			'camp' => [
				'camp',
				'camps',
				'camp de vacances',
				'lager',
				'campeggio',
				'event',
				'events',
				'evenement',
				'événement',
				'eveil',
				'eveil football',
				'girls-only',
				'girls_only',
				'girlsonly',
				'filles',
				'madchen',
				'mädchen',
			],
			'course' => ['course', 'cours', 'kurs', 'corso', 'stage'],
			'birthday' => [
				'birthday',
				'birthday-party',
				'birthday party',
				'anniversaire',
				'geburtstag',
				'compleanno',
			],
			'tournament' => ['tournament', 'tournoi', 'turnier', 'torneo'],
		];
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_normalize_activity_slug')) {
	/**
	 * Mirror PV InterSoccer_Product_Types::normalize_activity_slug (read-only).
	 *
	 * @param object $term WP_Term-like object.
	 * @param string $taxonomy
	 * @return string|null
	 */
	function intersoccer_rr_product_type_batch_normalize_activity_slug($term, $taxonomy) {
		if (!$term || !isset($term->slug)) {
			return null;
		}

		$canonical = ['camp', 'course', 'birthday', 'tournament'];
		$slug = strtolower((string) $term->slug);
		$name = isset($term->name) ? strtolower(trim((string) $term->name)) : '';

		if (in_array($slug, $canonical, true)) {
			return $slug;
		}

		$translation_map = intersoccer_rr_product_type_batch_activity_translation_map();
		foreach ($translation_map as $canonical_type => $variants) {
			foreach ($variants as $variant) {
				if ($slug === $variant || $name === $variant
					|| strpos($slug, $variant) !== false || strpos($name, $variant) !== false) {
					return $canonical_type;
				}
			}
		}

		if (function_exists('apply_filters')) {
			$default_lang = apply_filters('wpml_default_language', null);
			if ($default_lang && isset($term->term_id)) {
				$default_term_id = apply_filters('wpml_object_id', (int) $term->term_id, $taxonomy, false, $default_lang);
				if ($default_term_id && function_exists('get_term')) {
					$default_term = get_term((int) $default_term_id, $taxonomy);
					if ($default_term && !is_wp_error($default_term)) {
						$default_slug = strtolower((string) $default_term->slug);
						$default_name = isset($default_term->name) ? strtolower(trim((string) $default_term->name)) : '';
						if (in_array($default_slug, $canonical, true)) {
							return $default_slug;
						}
						foreach ($translation_map as $canonical_type => $variants) {
							foreach ($variants as $variant) {
								if ($default_slug === $variant || $default_name === $variant
									|| strpos($default_slug, $variant) !== false || strpos($default_name, $variant) !== false) {
									return $canonical_type;
								}
							}
						}
					}
				}
			}
		}

		return null;
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_detect_from_activity_terms')) {
	/**
	 * @param array<int,object> $terms
	 * @return string|null
	 */
	function intersoccer_rr_product_type_batch_detect_from_activity_terms(array $terms) {
		foreach ($terms as $term) {
			$slug = intersoccer_rr_product_type_batch_normalize_activity_slug($term, 'pa_activity-type');
			if ($slug) {
				return $slug;
			}
		}

		return null;
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_detect_from_category_slugs')) {
	/**
	 * @param string[] $slugs
	 * @return string|null
	 */
	function intersoccer_rr_product_type_batch_detect_from_category_slugs(array $slugs) {
		if (in_array('camps', $slugs, true)) {
			return 'camp';
		}
		if (in_array('courses', $slugs, true)) {
			return 'course';
		}
		if (in_array('birthdays', $slugs, true)) {
			return 'birthday';
		}
		if (in_array('tournaments', $slugs, true)) {
			return 'tournament';
		}

		return null;
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_product_has_birthday_signals')) {
	/**
	 * @param int               $product_id
	 * @param array<int,object> $activity_terms
	 * @param string[]          $category_slugs
	 * @param array<string,mixed> $product_attributes_meta Unserialized _product_attributes when available.
	 */
	function intersoccer_rr_product_type_batch_product_has_birthday_signals(
		$product_id,
		array $activity_terms,
		array $category_slugs,
		array $product_attributes_meta = []
	) {
		$product_id = (int) $product_id;
		if ($product_id <= 0) {
			return false;
		}

		if (isset($product_attributes_meta['pa_length-of-party'])) {
			return true;
		}

		if (function_exists('taxonomy_exists') && taxonomy_exists('pa_activity-type')) {
			foreach ($activity_terms as $term) {
				$slug = isset($term->slug) ? strtolower((string) $term->slug) : '';
				$name = isset($term->name) ? strtolower(trim((string) $term->name)) : '';
				if (strpos($slug, 'birthday') !== false || strpos($name, 'birthday') !== false
					|| strpos($name, 'anniversaire') !== false || strpos($name, 'geburtstag') !== false) {
					return true;
				}
			}
		}

		return intersoccer_rr_product_type_batch_detect_from_category_slugs($category_slugs) === 'birthday';
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_read_meta_string')) {
	function intersoccer_rr_product_type_batch_read_meta_string($post_id, $key) {
		if (!function_exists('get_post_meta')) {
			return '';
		}
		$value = get_post_meta((int) $post_id, $key, true);

		return is_string($value) ? $value : ( is_scalar($value) ? (string) $value : '' );
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_unserialize_product_attributes')) {
	/**
	 * @return array<string,mixed>
	 */
	function intersoccer_rr_product_type_batch_unserialize_product_attributes($post_id) {
		$raw = intersoccer_rr_product_type_batch_read_meta_string($post_id, '_product_attributes');
		if ($raw === '') {
			return [];
		}
		$attrs = maybe_unserialize($raw);
		if (!is_array($attrs)) {
			return [];
		}

		return $attrs;
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_detect_from_attributes_meta')) {
	/**
	 * Fallback when term relationships are empty (attribute options stored on parent).
	 *
	 * @param array<string,mixed> $product_attributes_meta
	 * @param array<int,object>   $terms_by_id Preloaded term objects keyed by term_id.
	 */
	function intersoccer_rr_product_type_batch_detect_from_attributes_meta(array $product_attributes_meta, array $terms_by_id) {
		if (!isset($product_attributes_meta['pa_activity-type'])) {
			return null;
		}
		$attr = $product_attributes_meta['pa_activity-type'];
		$term_ids = [];
		if (is_array($attr) && isset($attr['value'])) {
			$value = $attr['value'];
			if (is_string($value)) {
				$term_ids = array_filter(array_map('intval', explode('|', $value)));
			}
		}
		$terms = [];
		foreach ($term_ids as $term_id) {
			if (isset($terms_by_id[ $term_id ])) {
				$terms[] = $terms_by_id[ $term_id ];
			}
		}

		return intersoccer_rr_product_type_batch_detect_from_activity_terms($terms);
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_collect_post_ids_from_rows')) {
	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return int[]
	 */
	function intersoccer_rr_product_type_batch_collect_post_ids_from_rows(array $rows) {
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

		return array_values($post_ids);
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_expand_with_parents')) {
	/**
	 * Prime row IDs, then add parent product IDs from the primed post cache (no per-ID parent queries).
	 *
	 * @param int[] $post_ids
	 * @return int[]
	 */
	function intersoccer_rr_product_type_batch_expand_with_parents(array $post_ids) {
		$post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
		$set = [];
		foreach ($post_ids as $id) {
			if ($id > 0) {
				$set[ $id ] = $id;
			}
		}
		if (empty($set)) {
			return [];
		}
		intersoccer_rr_product_type_batch_prime_post_caches(array_values($set));
		$parents = [];
		foreach (array_keys($set) as $id) {
			$parent = intersoccer_rr_product_type_batch_parent_id_from_cache($id);
			if ($parent > 0 && !isset($set[ $parent ])) {
				$parents[ $parent ] = $parent;
			}
		}
		if (!empty($parents)) {
			intersoccer_rr_product_type_batch_prime_post_caches(array_values($parents));
			foreach ($parents as $parent) {
				$set[ $parent ] = $parent;
			}
		}

		return array_values($set);
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_collect_activity_term_ids_from_attributes')) {
	/**
	 * @param int[] $post_ids
	 * @return int[]
	 */
	function intersoccer_rr_product_type_batch_collect_activity_term_ids_from_attributes(array $post_ids) {
		$term_ids = [];
		foreach ($post_ids as $post_id) {
			$attrs = intersoccer_rr_product_type_batch_unserialize_product_attributes((int) $post_id);
			if (!isset($attrs['pa_activity-type']) || !is_array($attrs['pa_activity-type'])) {
				continue;
			}
			$value = $attrs['pa_activity-type']['value'] ?? '';
			if (!is_string($value) || $value === '') {
				continue;
			}
			foreach (array_filter(array_map('intval', explode('|', $value))) as $term_id) {
				$term_ids[ $term_id ] = $term_id;
			}
		}

		return array_values($term_ids);
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_fetch_activity_terms_by_id')) {
	/**
	 * @param int[] $term_ids
	 * @return array<int,object>
	 */
	function intersoccer_rr_product_type_batch_fetch_activity_terms_by_id(array $term_ids) {
		$term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
		if (empty($term_ids) || !function_exists('get_terms')) {
			return [];
		}
		if (function_exists('intersoccer_rr_test_api_call_count')) {
			intersoccer_rr_test_api_call_count('get_terms');
		}
		$terms = get_terms([
			'taxonomy' => 'pa_activity-type',
			'include' => $term_ids,
			'hide_empty' => false,
		]);
		if (is_wp_error($terms) || empty($terms)) {
			return [];
		}
		$by_id = [];
		foreach ($terms as $term) {
			if (is_object($term) && isset($term->term_id)) {
				$by_id[ (int) $term->term_id ] = $term;
			}
		}

		return $by_id;
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_lazy_prime_wpml_originals')) {
	/**
	 * @param int[]                                              $unresolved_ids
	 * @param array<int,array<string,array<int,object>>>         $terms_by_object
	 * @param string[]                                           $taxonomies
	 * @return int[] newly added original IDs
	 */
	function intersoccer_rr_product_type_batch_lazy_prime_wpml_originals(
		array $unresolved_ids,
		array &$terms_by_object,
		array $taxonomies
	) {
		$unresolved_ids = array_values(array_unique(array_filter(array_map('intval', $unresolved_ids))));
		if (empty($unresolved_ids) || !function_exists('apply_filters')) {
			return [];
		}
		$default_lang = apply_filters('wpml_default_language', null);
		if (!$default_lang) {
			return [];
		}
		$originals = [];
		foreach ($unresolved_ids as $id) {
			$original_id = intersoccer_rr_product_type_batch_wpml_object_id($id, 'product', true, $default_lang);
			if ($original_id > 0 && $original_id !== $id) {
				$originals[ $original_id ] = $original_id;
			}
		}
		if (empty($originals)) {
			return [];
		}
		intersoccer_rr_product_type_batch_prime_post_caches(array_values($originals));
		$extra_terms = intersoccer_rr_product_type_batch_fetch_object_terms(array_values($originals), $taxonomies);
		foreach ($extra_terms as $oid => $tax_map) {
			if (!isset($terms_by_object[ $oid ])) {
				$terms_by_object[ $oid ] = [];
			}
			foreach ($tax_map as $tax => $terms) {
				if (!isset($terms_by_object[ $oid ][ $tax ])) {
					$terms_by_object[ $oid ][ $tax ] = [];
				}
				foreach ($terms as $term_id => $term) {
					$terms_by_object[ $oid ][ $tax ][ $term_id ] = $term;
				}
			}
		}

		return array_values($originals);
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_prime_post_caches')) {
	/**
	 * @param int[] $post_ids
	 */
	function intersoccer_rr_product_type_batch_prime_post_caches(array $post_ids) {
		if (empty($post_ids)) {
			return;
		}
		$chunk_size = function_exists('intersoccer_roster_report_batch_chunk_size')
			? intersoccer_roster_report_batch_chunk_size()
			: 500;
		foreach (array_chunk($post_ids, $chunk_size) as $chunk) {
			if (function_exists('_prime_post_caches')) {
				_prime_post_caches($chunk, true, true);
			} elseif (function_exists('update_meta_cache')) {
				update_meta_cache('post', $chunk);
			}
			if (function_exists('update_object_term_cache')) {
				update_object_term_cache($chunk, 'product');
			}
		}
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_fetch_object_terms')) {
	/**
	 * One batched term read per cold build (chunked only when ID count exceeds batch chunk size).
	 *
	 * @param int[]    $post_ids
	 * @param string[] $taxonomies
	 * @return array<int,array<string,array<int,object>>>
	 */
	function intersoccer_rr_product_type_batch_fetch_object_terms(array $post_ids, array $taxonomies) {
		$by_object = [];
		if (empty($post_ids) || empty($taxonomies)) {
			return $by_object;
		}
		$chunk_size = function_exists('intersoccer_roster_report_batch_chunk_size')
			? intersoccer_roster_report_batch_chunk_size()
			: 500;
		foreach (array_chunk($post_ids, $chunk_size) as $chunk) {
			if (!function_exists('wp_get_object_terms')) {
				continue;
			}
			if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
				$GLOBALS['intersoccer_rr_product_type_batch_term_read_calls'] = (int) ( $GLOBALS['intersoccer_rr_product_type_batch_term_read_calls'] ?? 0 ) + 1;
			}
			if (function_exists('intersoccer_rr_test_api_call_count')) {
				intersoccer_rr_test_api_call_count('wp_get_object_terms');
			}
			$terms = wp_get_object_terms(
				$chunk,
				$taxonomies,
				[
					'fields' => 'all_with_object_id',
				]
			);
			if (is_wp_error($terms) || empty($terms)) {
				continue;
			}
			foreach ($terms as $term) {
				if (!is_object($term) || !isset($term->object_id, $term->taxonomy)) {
					continue;
				}
				$oid = (int) $term->object_id;
				$tax = (string) $term->taxonomy;
				if (!isset($by_object[ $oid ])) {
					$by_object[ $oid ] = [];
				}
				if (!isset($by_object[ $oid ][ $tax ])) {
					$by_object[ $oid ][ $tax ] = [];
				}
				$by_object[ $oid ][ $tax ][ (int) $term->term_id ] = $term;
			}
		}

		return $by_object;
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_resolve_for_post')) {
	/**
	 * Mirror PV get_product_type read path (no transients / writes / wc_get_product).
	 *
	 * @param int                                                        $post_id
	 * @param array<int,array<string,array<int,object>>>                 $terms_by_object
	 * @param array<int,array<string,array<int,object>>>                 $terms_by_object_for_original_lookup
	 * @return string|null
	 */
	function intersoccer_rr_product_type_batch_resolve_for_post(
		$post_id,
		array $terms_by_object,
		array $terms_by_object_for_original_lookup = [],
		array $activity_terms_by_id = [],
		$allow_wpml = true
	) {
		$post_id = (int) $post_id;
		if ($post_id <= 0) {
			return null;
		}

		$activity_terms = array_values($terms_by_object[ $post_id ]['pa_activity-type'] ?? []);
		$category_terms = array_values($terms_by_object[ $post_id ]['product_cat'] ?? []);
		$category_slugs = [];
		foreach ($category_terms as $term) {
			if (isset($term->slug)) {
				$category_slugs[] = (string) $term->slug;
			}
		}

		$attrs_meta = intersoccer_rr_product_type_batch_unserialize_product_attributes($post_id);

		$product_type = intersoccer_rr_product_type_batch_read_meta_string($post_id, '_intersoccer_product_type');
		if ($product_type !== '' && in_array($product_type, ['camp', 'course', 'birthday', 'tournament'], true)) {
			if (in_array($product_type, ['camp', 'course'], true)
				&& intersoccer_rr_product_type_batch_product_has_birthday_signals(
					$post_id,
					$activity_terms,
					$category_slugs,
					$attrs_meta
				)) {
				return 'birthday';
			}

			return $product_type;
		}

		$product_type = intersoccer_rr_product_type_batch_detect_from_activity_terms($activity_terms);
		if (!$product_type) {
			$product_type = intersoccer_rr_product_type_batch_detect_from_attributes_meta($attrs_meta, $activity_terms_by_id);
		}
		if ($product_type) {
			return $product_type;
		}

		$product_type = intersoccer_rr_product_type_batch_detect_from_category_slugs($category_slugs);
		if ($product_type) {
			return $product_type;
		}

		if ($allow_wpml && function_exists('apply_filters')) {
			$default_lang = apply_filters('wpml_default_language', null);
			if ($default_lang) {
				$original_id = intersoccer_rr_product_type_batch_wpml_object_id($post_id, 'product', true, $default_lang);
				if ($original_id > 0 && $original_id !== $post_id) {
					$from_original_meta = intersoccer_rr_product_type_batch_read_meta_string($original_id, '_intersoccer_product_type');
					if ($from_original_meta !== '' && in_array($from_original_meta, ['camp', 'course', 'birthday', 'tournament'], true)) {
						return $from_original_meta;
					}
					$orig_activity = array_values(
						$terms_by_object_for_original_lookup[ $original_id ]['pa_activity-type']
							?? $terms_by_object[ $original_id ]['pa_activity-type']
							?? []
					);
					$orig_cats = array_values(
						$terms_by_object_for_original_lookup[ $original_id ]['product_cat']
							?? $terms_by_object[ $original_id ]['product_cat']
							?? []
					);
					$orig_cat_slugs = [];
					foreach ($orig_cats as $term) {
						if (isset($term->slug)) {
							$orig_cat_slugs[] = (string) $term->slug;
						}
					}
					$from_orig = intersoccer_rr_product_type_batch_detect_from_activity_terms($orig_activity);
					if (!$from_orig) {
						$from_orig = intersoccer_rr_product_type_batch_detect_from_category_slugs($orig_cat_slugs);
					}
					if ($from_orig) {
						return $from_orig;
					}
				}
			}
		}

		return null;
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_load_for_post_ids')) {
	/**
	 * Resolve types for many post IDs with one priming pass and batched term reads.
	 *
	 * @param int[] $post_ids
	 * @return array<int,string|null> post_id => type slug or null
	 */
	function intersoccer_rr_product_type_batch_load_for_post_ids(array $post_ids) {
		$post_ids = array_values(array_unique(array_map('intval', $post_ids)));
		$post_ids = array_values(array_filter($post_ids, static function ($id) {
			return $id > 0;
		}));
		if (empty($post_ids)) {
			return [];
		}

		if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
			$GLOBALS['intersoccer_rr_product_type_batch_load_call_count'] = (int) ( $GLOBALS['intersoccer_rr_product_type_batch_load_call_count'] ?? 0 ) + 1;
		}

		$expanded = intersoccer_rr_product_type_batch_expand_with_parents($post_ids);

		$taxonomies = ['pa_activity-type', 'product_cat'];
		if (function_exists('taxonomy_exists') && taxonomy_exists('pa_length-of-party')) {
			$taxonomies[] = 'pa_length-of-party';
		}
		$terms_by_object = intersoccer_rr_product_type_batch_fetch_object_terms($expanded, $taxonomies);
		$activity_terms_by_id = intersoccer_rr_product_type_batch_fetch_activity_terms_by_id(
			intersoccer_rr_product_type_batch_collect_activity_term_ids_from_attributes($expanded)
		);

		$by_id = [];
		$unresolved = [];
		foreach ($expanded as $id) {
			$type = intersoccer_rr_product_type_batch_resolve_for_post(
				$id,
				$terms_by_object,
				$terms_by_object,
				$activity_terms_by_id,
				false
			);
			$by_id[ $id ] = $type;
			if ($type === null || $type === '') {
				$unresolved[ $id ] = $id;
			}
		}
		if (!empty($unresolved)) {
			$originals = intersoccer_rr_product_type_batch_lazy_prime_wpml_originals(
				array_values($unresolved),
				$terms_by_object,
				$taxonomies
			);
			if (!empty($originals)) {
				$extra_attr_ids = intersoccer_rr_product_type_batch_collect_activity_term_ids_from_attributes($originals);
				if (!empty($extra_attr_ids)) {
					$activity_terms_by_id += intersoccer_rr_product_type_batch_fetch_activity_terms_by_id($extra_attr_ids);
				}
			}
			foreach ($unresolved as $id) {
				$type = intersoccer_rr_product_type_batch_resolve_for_post(
					$id,
					$terms_by_object,
					$terms_by_object,
					$activity_terms_by_id,
					true
				);
				$by_id[ $id ] = $type;
			}
		}

		if (function_exists('apply_filters')) {
			$overrides = apply_filters('intersoccer_rr_product_type_batch_test_override_by_id', [], $expanded);
			if (is_array($overrides)) {
				foreach ($overrides as $id => $type) {
					$id = (int) $id;
					if ($id > 0 && array_key_exists($id, $by_id)) {
						$by_id[ $id ] = $type === '' ? null : $type;
					}
				}
			}
		}

		if (!isset($GLOBALS['intersoccer_rr_pv_product_type_by_id'])
			|| !is_array($GLOBALS['intersoccer_rr_pv_product_type_by_id'])) {
			$GLOBALS['intersoccer_rr_pv_product_type_by_id'] = [];
		}
		$GLOBALS['intersoccer_rr_product_type_batch_id_set'] = [];
		foreach ($by_id as $id => $type) {
			$GLOBALS['intersoccer_rr_product_type_batch_id_set'][ (int) $id ] = true;
			$GLOBALS['intersoccer_rr_pv_product_type_by_id'][ $id ] = $type ?? '';
		}
		$GLOBALS['intersoccer_rr_product_type_batch_loaded'] = true;

		return $by_id;
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_resolve_safe_pair')) {
	/**
	 * Same resolution order as intersoccer_get_product_type_safe without calling PV.
	 *
	 * @param int      $product_id
	 * @param int|null $variation_id
	 * @return string
	 */
	function intersoccer_rr_product_type_batch_resolve_safe_pair($product_id, $variation_id = null) {
		$product_id = (int) $product_id;
		$variation_id = $variation_id && $variation_id > 0 ? (int) $variation_id : null;
		$by_id = isset($GLOBALS['intersoccer_rr_pv_product_type_by_id'])
			&& is_array($GLOBALS['intersoccer_rr_pv_product_type_by_id'])
			? $GLOBALS['intersoccer_rr_pv_product_type_by_id']
			: [];

		if ($variation_id && $variation_id > 0 && intersoccer_rr_product_type_batch_id_in_set($variation_id)) {
			$type = (string) ( $by_id[ $variation_id ] ?? '' );
			if ($type !== '') {
				return $type;
			}
		}
		if ($product_id > 0 && intersoccer_rr_product_type_batch_id_in_set($product_id)) {
			$type = (string) ( $by_id[ $product_id ] ?? '' );
			if ($type !== '') {
				return $type;
			}
		}

		return '';
	}
}

if (!function_exists('intersoccer_rr_product_type_batch_prime_safe_cache_from_rows')) {
	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	function intersoccer_rr_product_type_batch_prime_safe_cache_from_rows(array $rows) {
		if (!isset($GLOBALS['intersoccer_rr_product_type_safe_cache'])
			|| !is_array($GLOBALS['intersoccer_rr_product_type_safe_cache'])) {
			$GLOBALS['intersoccer_rr_product_type_safe_cache'] = [];
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
		foreach ($pairs as $key => $pair) {
			if (function_exists('intersoccer_get_product_type_safe')) {
				$GLOBALS['intersoccer_rr_product_type_safe_cache'][ $key ]
					= intersoccer_get_product_type_safe($pair[0], $pair[1]);
			} else {
				$GLOBALS['intersoccer_rr_product_type_safe_cache'][ $key ]
					= intersoccer_rr_product_type_batch_resolve_safe_pair($pair[0], $pair[1]);
			}
		}
	}
}
