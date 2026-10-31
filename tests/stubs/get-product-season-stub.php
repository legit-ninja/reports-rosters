<?php
/**
 * Test stub for parent product season (production: Product Variations / shared helper).
 */

if (!function_exists('intersoccer_get_product_season')) {
    /**
     * @param int $parent_id
     * @return string
     */
    function intersoccer_get_product_season($parent_id) {
        global $intersoccer_test_get_product_season_callback;
        if (is_callable($intersoccer_test_get_product_season_callback)) {
            return (string) call_user_func($intersoccer_test_get_product_season_callback, (int) $parent_id);
        }

        return '';
    }
}
