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
}
