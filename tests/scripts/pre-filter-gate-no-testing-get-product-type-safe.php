<?php

$root = dirname(__DIR__, 2);
require $root . '/tests/scripts/pre-filter-gate-wp-stubs.php';
require $root . '/includes/utils.php';

add_filter(
    'intersoccer_pre_get_product_type_safe',
    static function ($pre, $product_id, $variation_id) {
        return 'camp';
    },
    10,
    3
);

$type = intersoccer_get_product_type_safe(1, null);
$ok = $type === 'unknown';
fwrite(STDOUT, $ok ? "OK\n" : "FAIL\n");
exit($ok ? 0 : 1);
