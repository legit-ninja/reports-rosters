<?php
/**
 * Frozen read-only PV product-type decision path (InterSoccer Product Variations 2.10.16).
 * No transient/meta writes; no wc_get_product — uses primed meta + term fixtures.
 */

if (!class_exists('InterSoccer_PV_Product_Types_Reference_2_10_16')) {
	class InterSoccer_PV_Product_Types_Reference_2_10_16 {

		public static function get_product_type($product_id) {
			$product_id = (int) $product_id;
			if ($product_id <= 0) {
				return null;
			}

			$product_type = self::read_meta($product_id, '_intersoccer_product_type');
			if ($product_type && in_array($product_type, ['camp', 'course', 'birthday', 'tournament'], true)) {
				if (in_array($product_type, ['camp', 'course'], true)
					&& self::product_has_birthday_signals($product_id)) {
					return 'birthday';
				}

				return $product_type;
			}

			$product_type = self::detect_type_from_attribute($product_id);
			if ($product_type) {
				return $product_type;
			}

			$product_type = self::detect_type_from_category($product_id);
			if ($product_type) {
				return $product_type;
			}

			if (function_exists('apply_filters')) {
				$default_lang = apply_filters('wpml_default_language', null);
				if ($default_lang) {
					$original_id = (int) apply_filters('wpml_object_id', $product_id, 'product', true, $default_lang);
					if ($original_id > 0 && $original_id !== $product_id) {
						$from_original_meta = self::read_meta($original_id, '_intersoccer_product_type');
						if ($from_original_meta && in_array($from_original_meta, ['camp', 'course', 'birthday', 'tournament'], true)) {
							return $from_original_meta;
						}
						$from_orig = self::detect_type_from_attribute($original_id);
						if (!$from_orig) {
							$from_orig = self::detect_type_from_category($original_id);
						}
						if ($from_orig) {
							return $from_orig;
						}
					}
				}
			}

			return null;
		}

		private static function read_meta($post_id, $key) {
			if (!function_exists('get_post_meta')) {
				return '';
			}
			$value = get_post_meta((int) $post_id, $key, true);

			return is_string($value) ? $value : ( is_scalar($value) ? (string) $value : '' );
		}

		private static function activity_terms_for_product($product_id) {
			$fixture = $GLOBALS['intersoccer_test_wp_get_object_terms'][ (int) $product_id ]['pa_activity-type'] ?? [];
			if (!is_array($fixture)) {
				return [];
			}

			return array_values($fixture);
		}

		private static function category_slugs_for_product($product_id) {
			$fixture = $GLOBALS['intersoccer_test_wp_get_object_terms'][ (int) $product_id ]['product_cat'] ?? [];
			$slugs = [];
			if (!is_array($fixture)) {
				return $slugs;
			}
			foreach ($fixture as $term) {
				if (is_object($term) && isset($term->slug)) {
					$slugs[] = (string) $term->slug;
				}
			}

			return $slugs;
		}

		private static function unserialize_attributes($product_id) {
			$raw = self::read_meta($product_id, '_product_attributes');
			if ($raw === '') {
				return [];
			}
			$attrs = maybe_unserialize($raw);

			return is_array($attrs) ? $attrs : [];
		}

		private static function detect_type_from_attribute($product_id) {
			$terms = self::activity_terms_for_product($product_id);
			if (!empty($terms)) {
				foreach ($terms as $term) {
					$slug = self::normalize_activity_slug($term, 'pa_activity-type');
					if ($slug) {
						return $slug;
					}
				}
			}

			$attrs = self::unserialize_attributes($product_id);
			if (!isset($attrs['pa_activity-type']) || !is_array($attrs['pa_activity-type'])) {
				return null;
			}
			$value = $attrs['pa_activity-type']['value'] ?? '';
			if (!is_string($value) || $value === '') {
				return null;
			}
			$term_ids = array_filter(array_map('intval', explode('|', $value)));
			$by_id = $GLOBALS['intersoccer_test_terms_by_id'] ?? [];
			if (!is_array($by_id)) {
				return null;
			}
			foreach ($term_ids as $term_id) {
				if (!isset($by_id[ $term_id ])) {
					continue;
				}
				$slug = self::normalize_activity_slug($by_id[ $term_id ], 'pa_activity-type');
				if ($slug) {
					return $slug;
				}
			}

			return null;
		}

		private static function detect_type_from_category($product_id) {
			$categories = self::category_slugs_for_product($product_id);
			if (in_array('camps', $categories, true)) {
				return 'camp';
			}
			if (in_array('courses', $categories, true)) {
				return 'course';
			}
			if (in_array('birthdays', $categories, true)) {
				return 'birthday';
			}
			if (in_array('tournaments', $categories, true)) {
				return 'tournament';
			}

			return null;
		}

		public static function product_has_birthday_signals($product_id) {
			$attrs = self::unserialize_attributes($product_id);
			if (isset($attrs['pa_length-of-party'])) {
				return true;
			}
			if (function_exists('taxonomy_exists') && taxonomy_exists('pa_activity-type')) {
				foreach (self::activity_terms_for_product($product_id) as $term) {
					$slug = isset($term->slug) ? strtolower((string) $term->slug) : '';
					$name = isset($term->name) ? strtolower(trim((string) $term->name)) : '';
					if (strpos($slug, 'birthday') !== false || strpos($name, 'birthday') !== false
						|| strpos($name, 'anniversaire') !== false || strpos($name, 'geburtstag') !== false) {
						return true;
					}
				}
			}

			return self::detect_type_from_category($product_id) === 'birthday';
		}

		private static function normalize_activity_slug($term, $taxonomy) {
			if (!$term || !isset($term->slug)) {
				return null;
			}
			if (function_exists('intersoccer_rr_product_type_batch_normalize_activity_slug')) {
				return intersoccer_rr_product_type_batch_normalize_activity_slug($term, $taxonomy);
			}

			return strtolower((string) $term->slug);
		}
	}
}
