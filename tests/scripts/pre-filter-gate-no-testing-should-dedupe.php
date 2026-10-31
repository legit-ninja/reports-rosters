<?php

$root = dirname(__DIR__, 2);
require $root . '/tests/scripts/pre-filter-gate-wp-stubs.php';
require $root . '/includes/order-admin-line-item-meta-display.php';

add_filter(
    'intersoccer_pre_should_dedupe_admin_order_item_formatted_meta',
    static function ($pre) {
        return true;
    },
    10,
    1
);

function is_admin() {
    return false;
}

$ok = !intersoccer_should_dedupe_admin_order_item_formatted_meta();
fwrite(STDOUT, $ok ? "OK\n" : "FAIL\n");
exit($ok ? 0 : 1);
