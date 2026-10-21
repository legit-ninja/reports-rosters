<?php
/**
 * Regression: manual product type detection logs and per-request cache (privacy #129/#131 follow-up).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class ManualProductTypePrivacyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_manual_product_type_detection')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }
        if (function_exists('intersoccer_reset_product_type_cache')) {
            intersoccer_reset_product_type_cache();
        }
        if (class_exists('InterSoccerRRTestMeta')) {
            \InterSoccerRRTestMeta::reset();
        }
        global $intersoccer_test_get_post_meta_call_count;
        $intersoccer_test_get_post_meta_call_count = 0;
    }

    public function test_manual_product_type_detection_logs_are_wp_debug_gated_without_numeric_ids(): void {
        $path = dirname(__DIR__, 2) . '/includes/utils.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $start = strpos($source, 'function intersoccer_manual_product_type_detection');
        $this->assertNotFalse($start);
        $end = strpos($source, '// 3. Update the intersoccer_update_roster_entry function', $start);
        $this->assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString('$debug = defined(\'WP_DEBUG\') && WP_DEBUG;', $body);
        $this->assertStringNotContainsString('Manual product type detection for ID:', $body);
        $this->assertStringNotContainsString('Could not determine product type manually for ID:', $body);
        $this->assertDoesNotMatchRegularExpression('/error_log\\([^\\n]*\\$check_id/', $body);
        $this->assertDoesNotMatchRegularExpression('/error_log\\([^\\n]*\\$cat_check_id/', $body);
        $this->assertStringContainsString('function intersoccer_reset_product_type_cache(): void', $source);
    }

    public function test_manual_product_type_lookup_uses_per_request_cache_including_unknown(): void {
        $this->assertSame('unknown', intersoccer_manual_product_type_detection(88001, null));
        $this->assertSame('unknown', intersoccer_manual_product_type_detection(88001, null));

        $cache = intersoccer_manual_product_type_lookup_cache();
        $this->assertArrayHasKey('88001:', $cache);
        $this->assertSame('unknown', $cache['88001:']);

        global $intersoccer_test_get_post_meta_call_count;
        $this->assertSame(1, $intersoccer_test_get_post_meta_call_count);
    }

    public function test_manual_product_type_cache_skips_repeat_meta_reads_for_positive_hits(): void {
        \InterSoccerRRTestMeta::$data[88002]['_intersoccer_product_type'] = 'camp';

        $this->assertSame('camp', intersoccer_manual_product_type_detection(88002, null));
        $this->assertSame('camp', intersoccer_manual_product_type_detection(88002, null));

        global $intersoccer_test_get_post_meta_call_count;
        $this->assertSame(1, $intersoccer_test_get_post_meta_call_count);
    }

    public function test_manual_product_type_cache_distinguishes_variation_only_from_parent_and_variation(): void {
        $variation_id = 88100;
        $parent_id = 88101;
        \InterSoccerRRTestMeta::$data[$parent_id]['_intersoccer_product_type'] = 'course';

        $this->assertSame('unknown', intersoccer_manual_product_type_detection($variation_id, null));
        global $intersoccer_test_get_post_meta_call_count;
        $this->assertSame(1, $intersoccer_test_get_post_meta_call_count);

        $this->assertSame('course', intersoccer_manual_product_type_detection($parent_id, $variation_id));
        $this->assertSame(3, $intersoccer_test_get_post_meta_call_count);

        $cache = intersoccer_manual_product_type_lookup_cache();
        $this->assertArrayHasKey($variation_id . ':', $cache);
        $this->assertSame('unknown', $cache[$variation_id . ':']);
        $this->assertArrayHasKey($parent_id . ':' . $variation_id, $cache);
        $this->assertSame('course', $cache[$parent_id . ':' . $variation_id]);
    }

    public function test_manual_product_type_runtime_log_excludes_numeric_ids_when_wp_debug(): void {
        $log_file = tempnam(sys_get_temp_dir(), 'rr_product_type_log_');
        $this->assertNotFalse($log_file);
        $previous_log = ini_set('error_log', $log_file);

        try {
            intersoccer_reset_product_type_cache();
            intersoccer_manual_product_type_detection(88003, null);
            $log = (string) file_get_contents($log_file);
            $this->assertStringContainsString('Manual product type detection', $log);
            $this->assertDoesNotMatchRegularExpression('/\b88003\b/', $log);
            $this->assertStringNotContainsString('for ID:', $log);
        } finally {
            ini_set('error_log', (string) $previous_log);
            if (is_string($log_file) && file_exists($log_file)) {
                unlink($log_file);
            }
        }
    }
}
