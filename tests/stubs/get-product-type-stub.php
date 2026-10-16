<?php

if (!function_exists('intersoccer_get_product_type')) {
    /**
     * Test-only PV shim: only answers when a test sets $intersoccer_test_get_product_type_callback.
     * Must not default to "course" — that leaks into later tests via function_exists() forever.
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
