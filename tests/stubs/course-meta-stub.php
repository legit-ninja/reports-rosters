<?php

if (!function_exists('intersoccer_get_course_meta')) {
    /**
     * Test shim for course schedule meta on variations.
     */
    function intersoccer_get_course_meta($variation_id, $key, $default = null) {
        $cb = $GLOBALS['intersoccer_test_get_course_meta_callback'] ?? null;
        if (defined('INTERSOCCER_TESTING') && INTERSOCCER_TESTING && is_callable($cb)) {
            return call_user_func($cb, $variation_id, $key, $default);
        }

        return $default;
    }
}

if (!function_exists('intersoccer_get_product_season')) {
    /**
     * Test shim for parent product season label.
     */
    function intersoccer_get_product_season($parent_id = 0) {
        $cb = $GLOBALS['intersoccer_test_get_product_season_callback'] ?? null;
        if (defined('INTERSOCCER_TESTING') && INTERSOCCER_TESTING && is_callable($cb)) {
            return (string) call_user_func($cb, $parent_id);
        }

        return '';
    }
}
