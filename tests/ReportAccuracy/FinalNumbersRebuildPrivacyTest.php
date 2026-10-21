<?php
/**
 * Regression: Final Numbers / rebuild / export failure logs must not leak order or line-item identifiers.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FinalNumbersRebuildPrivacyTest extends TestCase {

    public function test_roster_export_dob_parse_failure_is_ungated_and_id_free(): void {
        $path = dirname(__DIR__, 2) . '/includes/roster-export.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $this->assertStringContainsString(
            "error_log('InterSoccer Export: Could not parse date of birth for a roster row');",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Could not parse date of birth[^\\n]*order_item_id/',
            $source
        );
        $pos = (int) strpos($source, 'Could not parse date of birth for a roster row');
        $this->assertGreaterThan(0, $pos);
        $context = substr($source, max(0, $pos - 160), 160);
        $this->assertStringNotContainsString('WP_DEBUG', $context);
    }

    public function test_rebuild_event_signature_missing_roster_entry_is_ungated_and_id_free(): void {
        $path = dirname(__DIR__, 2) . '/includes/utils.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $this->assertStringContainsString(
            "error_log('InterSoccer: No roster entry found when rebuilding event signature.');",
            $source
        );
        $this->assertStringNotContainsString('No roster entry found for order item', $source);
    }

    public function test_final_numbers_thin_line_logs_are_wp_debug_gated_without_order_item_id(): void {
        foreach (['final-reports-aggregation.php', 'reports-data.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/' . $file);
            $this->assertNotEmpty($source);
            $this->assertStringContainsString("if (defined('WP_DEBUG') && WP_DEBUG)", $source);
            $this->assertDoesNotMatchRegularExpression('/thin[^\\n]*order_item_id/', $source);
        }
    }

    public function test_db_signature_rebuild_trace_helper_is_wp_debug_gated(): void {
        $path = dirname(__DIR__, 2) . '/includes/db.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $this->assertStringContainsString('function intersoccer_db_event_signature_rebuild_debug_log', $source);
        $this->assertStringContainsString(
            "error_log('InterSoccer: No order item metadata found during signature rebuild');",
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/No order item metadata found[^\\n]*order_item_id/',
            $source
        );
    }

    public function test_deprecated_camp_terms_parse_log_omits_variation_id(): void {
        $path = dirname(__DIR__, 2) . '/includes/utils.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $this->assertStringContainsString(
            "'InterSoccer RR: DEPRECATED camp-terms date parse. Prefer _camp_start_date.'",
            $source
        );
        $this->assertStringNotContainsString('variation=%d', $source);
    }
}
