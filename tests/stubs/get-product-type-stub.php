<?php

if (!function_exists('intersoccer_get_product_type')) {
    /**
     * Test-only PV shim: tests set $intersoccer_test_get_product_type_callback; when unset under
     * INTERSOCCER_TESTING, return '' when no callback is set (avoid polluting unrelated tests).
     */
    function intersoccer_get_product_type($product_id = 0) {
        global $intersoccer_test_get_product_type_callback;
        if (defined('INTERSOCCER_TESTING') && INTERSOCCER_TESTING
            && is_callable($intersoccer_test_get_product_type_callback)
        ) {
            return (string) call_user_func($intersoccer_test_get_product_type_callback, $product_id);
        }

        return '';
    }
}
