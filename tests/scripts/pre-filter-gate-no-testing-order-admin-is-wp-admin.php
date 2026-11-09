<?php

$root = dirname(__DIR__, 2);
require $root . '/tests/scripts/pre-filter-gate-wp-stubs.php';
require $root . '/includes/order-admin-line-item-meta-display.php';

add_filter(
    'intersoccer_pre_order_admin_is_wp_admin_request',
    static function ($pre) {
        return true;
    },
    10,
    1
);

function is_admin() {
    return false;
}

$ok = !intersoccer_order_admin_is_wp_admin_request();
fwrite(STDOUT, $ok ? "OK\n" : "FAIL\n");
exit($ok ? 0 : 1);
