<?php
/**
 * Regression: Final Numbers / course date-parse debug logs must not leak order or line-item ids.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class ParseDateUnifiedDebugPrivacyTest extends TestCase {

    public function test_reports_data_course_context_excludes_order_item_id(): void {
        $path = dirname(__DIR__, 2) . '/includes/reports-data.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $this->assertStringContainsString("\$context = 'Final Reports Course';", $source);
        $this->assertDoesNotMatchRegularExpression(
            "/Final Reports Course \\(order_item_id:/",
            $source
        );
    }

    public function test_parse_date_unified_debug_helper_is_wp_debug_gated(): void {
        $path = dirname(__DIR__, 2) . '/includes/utils.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $this->assertStringContainsString('function intersoccer_parse_date_unified_debug_log', $source);
        $this->assertStringContainsString(
            "if (\$context === '' || !defined('WP_DEBUG') || !WP_DEBUG)",
            $source
        );

        $fn_start = strpos($source, 'function intersoccer_parse_date_unified(');
        $this->assertNotFalse($fn_start);
        $fn_body = substr($source, $fn_start, 12000);
        $this->assertStringContainsString('intersoccer_parse_date_unified_debug_log', $fn_body);
        $this->assertDoesNotMatchRegularExpression('/error_log\\([^)]*\\$context/', $fn_body);
    }

    public function test_db_rebuild_meta_debug_logs_exclude_order_and_item_ids(): void {
        $path = dirname(__DIR__, 2) . '/includes/db.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $this->assertStringContainsString(
            "error_log('InterSoccer: Order item meta keys: ' . implode(', ', \$meta_keys));",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Order item meta keys for order \\$/',
            $source
        );
    }

    public function test_rosters_course_day_debug_logs_exclude_order_item_id_and_meta_values(): void {
        $path = dirname(__DIR__, 2) . '/includes/rosters.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $this->assertStringContainsString(
            "error_log(\"InterSoccer: Retrieved course_day '\$course_day' using meta_key '\$meta_key'\");",
            $source
        );
        $this->assertStringContainsString(
            "error_log('InterSoccer: Could not find course_day. Meta keys present: ' . implode(', ', \$meta_key_names));",
            $source
        );
        $this->assertStringNotContainsString('json_encode($all_meta)', $source);
    }

    public function test_event_completed_preserve_log_is_wp_debug_gated_without_order_item_id(): void {
        $path = dirname(__DIR__, 2) . '/includes/utils.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $this->assertStringContainsString(
            "if (defined('WP_DEBUG') && WP_DEBUG) {\n            error_log('InterSoccer: Preserving event_completed=1 for roster entry (event_signature: '",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Preserving event_completed=1[^\\n]*order_item_id/',
            $source
        );
    }

    public function test_parse_date_unified_runtime_log_excludes_order_item_id_in_context(): void {
        if (!function_exists('intersoccer_parse_date_unified')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }

        $log_file = tempnam(sys_get_temp_dir(), 'rr_parse_date_log_');
        $this->assertNotFalse($log_file);
        $previous_log = ini_set('error_log', $log_file);

        try {
            $result = intersoccer_parse_date_unified('August 17, 2025', 'Final Reports Course (start)');
            $this->assertSame('2025-08-17', $result);
            $log = (string) file_get_contents($log_file);
            $this->assertStringContainsString('Final Reports Course (start)', $log);
            $this->assertStringNotContainsString('order_item_id', $log);
            $this->assertDoesNotMatchRegularExpression('/order[_ ]id/i', $log);
        } finally {
            ini_set('error_log', (string) $previous_log);
            if (is_string($log_file) && file_exists($log_file)) {
                unlink($log_file);
            }
        }
    }
}
