<?php

$root = dirname(__DIR__, 2);
require $root . '/tests/scripts/pre-filter-gate-wp-stubs.php';
require $root . '/includes/roster-move-admin-target.php';

add_filter(
    'intersoccer_pre_roster_move_post_status',
    static function ($pre, $post_id) {
        return 'trash';
    },
    10,
    2
);

function get_post_status($post_id) {
    return 'publish';
}

$ok = intersoccer_roster_move_get_post_status(99) === 'publish';
fwrite(STDOUT, $ok ? "OK\n" : "FAIL\n");
exit($ok ? 0 : 1);
