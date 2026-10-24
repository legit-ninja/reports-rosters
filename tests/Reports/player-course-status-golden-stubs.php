<?php
/**
 * PV product-type stub for player course status golden tests.
 */

if (!function_exists('intersoccer_get_product_type')) {
	function intersoccer_get_product_type($post_id) {
		$post_id = (int) $post_id;
		$map = $GLOBALS['intersoccer_test_golden_product_type_by_id'] ?? [];
		if (isset($map[ $post_id ])) {
			return $map[ $post_id ];
		}

		return '';
	}
}
