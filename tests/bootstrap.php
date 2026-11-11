<?php
/**
 * PHPUnit bootstrap for InterSoccer Reports & Rosters.
 *
 * This file is included by PHPUnit before Patchwork's stream wrapper exists.
 * Patchwork can replace a function only when the file that defines it was
 * included through that wrapper. Function stubs therefore live in
 * tests/bootstrap-runtime.php, which this file includes after Patchwork.php.
 * The wrapper is removed again before PHPUnit loads test files so those files
 * stay on the normal include path. remove_accents() stays in its own shim,
 * also included after Patchwork, so Brain Monkey can replace it.
 */

// PHPUnit isolated processes are fed on stdin. They keep their own preload
// decision: the times-facet processes must not see the real resolver, and
// preloading here would also emit log lines into those processes.
$intersoccer_rr_script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
$intersoccer_rr_preload_skip = $intersoccer_rr_script === ''
    || $intersoccer_rr_script === 'Standard input code'
    || !is_file($intersoccer_rr_script);
unset($intersoccer_rr_script);

if (!defined('ABSPATH')) {
    // WordPress core files the plugin requires via ABSPATH (wp-admin/includes/upgrade.php)
    // resolve to stand-ins under tests/stubs/, so none of them sit in the plugin root.
    define('ABSPATH', __DIR__ . '/stubs/');
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
    define('WPINC', 'wp-includes');
    define('WP_DEBUG', true);
    define('INTERSOCCER_TESTING', true);
    define('INTERSOCCER_RR_TESTING', true);

    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
    define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
    define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);
    define('MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS);
    define('YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('OBJECT_K')) {
    define('OBJECT_K', 'OBJECT_K');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$intersoccer_rr_patchwork = dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';
if (is_file($intersoccer_rr_patchwork)) {
    require_once $intersoccer_rr_patchwork;
}

require_once __DIR__ . '/stubs/course-meta-stub.php';
require_once __DIR__ . '/shims/term-def-request-cache-test-shim.php';
require_once __DIR__ . '/shims/remove-accents.php';
require_once __DIR__ . '/bootstrap-runtime.php';

// Load product functions while Patchwork can still rewrite them. The two
// separate-process times tests must not see the real resolver. Their skip
// flag is computed before this wrapper exists, because reading the child
// script through the wrapper does not return its source.
if (!$intersoccer_rr_preload_skip) {
    foreach ([
        dirname(__DIR__) . '/includes/utils.php',
        dirname(__DIR__) . '/includes/roster-export.php',
        dirname(__DIR__) . '/wp-admin/includes/upgrade.php',
    ] as $intersoccer_rr_preload_file) {
        if (is_file($intersoccer_rr_preload_file)) {
            require_once $intersoccer_rr_preload_file;
        }
    }
}
unset($intersoccer_rr_preload_skip, $intersoccer_rr_preload_file);

if (class_exists(\Patchwork\CodeManipulation\Stream::class, false)) {
    \Patchwork\CodeManipulation\Stream::unwrap();
}
unset($intersoccer_rr_patchwork);

add_filter(
    'intersoccer_pre_roster_move_post_status',
    static function ($status, $post_id) {
        global $intersoccer_test_post_status_map;
        if (is_array($intersoccer_test_post_status_map)
            && array_key_exists((int) $post_id, $intersoccer_test_post_status_map)
        ) {
            return (string) $intersoccer_test_post_status_map[(int) $post_id];
        }

        return $status;
    },
    10,
    2
);

add_filter(
    'intersoccer_pre_get_product_type_safe',
    static function ($type, $product_id, $variation_id = null) {
        global $intersoccer_test_product_type_safe_callback;
        if (is_callable($intersoccer_test_product_type_safe_callback)) {
            return (string) call_user_func(
                $intersoccer_test_product_type_safe_callback,
                $product_id,
                $variation_id
            );
        }

        return $type;
    },
    10,
    3
);
