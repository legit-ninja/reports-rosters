<?php

if (!function_exists('intersoccer_get_product_type_safe')) {
    function intersoccer_get_product_type_safe($product_id, $variation_id = null) {
        global $intersoccer_test_product_type_safe_callback;
        if (is_callable($intersoccer_test_product_type_safe_callback)) {
            return call_user_func($intersoccer_test_product_type_safe_callback, $product_id, $variation_id);
        }
        return 'course';
    }
}
