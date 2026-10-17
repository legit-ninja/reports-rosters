<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReportsReadonlyModeTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
        if (function_exists('intersoccer_reports_set_readonly_mode')) {
            intersoccer_reports_set_readonly_mode(false);
        }
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_emit_wp_hooks']);
        if (function_exists('intersoccer_reports_set_readonly_mode')) {
            intersoccer_reports_set_readonly_mode(false);
        }
        parent::tearDown();
    }

    public function test_readonly_mode_flag_and_persist_transient_guard(): void {
        if (!function_exists('intersoccer_reports_persist_transient')) {
            $this->fail('Read-only helpers must be loaded');
        }

        intersoccer_reports_set_readonly_mode(true);
        $this->assertTrue(intersoccer_reports_is_readonly_mode());
        $this->assertFalse(intersoccer_reports_persist_transient('is_term_def_test', 42, HOUR_IN_SECONDS));

        intersoccer_reports_set_readonly_mode(false);
        $this->assertFalse(intersoccer_reports_is_readonly_mode());
        $this->assertTrue(intersoccer_reports_persist_transient('is_term_def_test', 42, HOUR_IN_SECONDS));
    }

    public function test_cached_term_resolves_under_readonly_without_persisting(): void {
        if (!function_exists('intersoccer_get_cached_term_in_default_language')) {
            $this->fail('intersoccer_get_cached_term_in_default_language must be loaded');
        }

        intersoccer_reports_set_readonly_mode(true);

        $first = intersoccer_get_cached_term_in_default_language('geneva-venue', 'pa_intersoccer-venues');
        $second = intersoccer_get_cached_term_in_default_language('geneva-venue', 'pa_intersoccer-venues');

        $this->assertSame($first, $second);
    }

    public function test_write_guards_count_add_option_update_option_and_setted_transient(): void {
        if (!function_exists('intersoccer_reports_register_readonly_write_guards')) {
            $this->fail('Read-only write guards must be loaded');
        }

        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        intersoccer_reports_register_readonly_write_guards();
        intersoccer_reports_set_readonly_mode(true);

        add_option('_transient_new_dryrun_probe', 'payload', 'no');
        set_transient('new_dryrun_transient_key', 'payload', HOUR_IN_SECONDS);
        update_option('intersoccer_dryrun_probe_option', 'next');

        $this->assertSame(3, intersoccer_reports_get_readonly_write_attempts());
    }
}
