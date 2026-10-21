<?php
/**
 * Regression: roster export phone normalization logs must not write full phone numbers.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterExportPhonePrivacyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_redact_phone_for_log')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }
        if (!function_exists('intersoccer_normalize_phone_number')) {
            require_once dirname(__DIR__, 2) . '/includes/roster-export.php';
        }
    }

    public function test_redact_phone_for_log_returns_empty_for_blank_input(): void {
        $this->assertSame('empty', intersoccer_redact_phone_for_log(''));
        $this->assertSame('empty', intersoccer_redact_phone_for_log('   '));
        $this->assertSame('empty', intersoccer_redact_phone_for_log('(+)- '));
    }

    public function test_redact_phone_for_log_returns_length_and_last_two_digits_only(): void {
        $this->assertSame('len=11 ..71', intersoccer_redact_phone_for_log('+41 78 402 70 71'));
        $this->assertSame('len=10 ..99', intersoccer_redact_phone_for_log('0784027099'));
        $this->assertStringNotContainsString('784027099', intersoccer_redact_phone_for_log('0784027099'));
    }

    public function test_normalize_phone_runtime_log_excludes_full_number_when_wp_debug(): void {
        $secret = '892345678';
        $log_file = tempnam(sys_get_temp_dir(), 'rr_phone_log_');
        $this->assertNotFalse($log_file);
        $previous_log = ini_set('error_log', $log_file);

        try {
            $normalized = intersoccer_normalize_phone_number($secret);
            $this->assertIsString($normalized);
            $log = (string) file_get_contents($log_file);
            $this->assertStringContainsString('Normalized phone number', $log);
            $this->assertStringNotContainsString($secret, $log);
            $this->assertStringNotContainsString('892345678', $log);
            $this->assertStringContainsString('len=', $log);
            $this->assertStringContainsString('..78', $log);
        } finally {
            ini_set('error_log', (string) $previous_log);
            if (is_string($log_file) && file_exists($log_file)) {
                unlink($log_file);
            }
        }
    }

    public function test_normalize_phone_invalid_input_logs_redacted_not_raw_when_wp_debug(): void {
        $secret = '0791234567';
        $log_file = tempnam(sys_get_temp_dir(), 'rr_phone_invalid_log_');
        $this->assertNotFalse($log_file);
        $previous_log = ini_set('error_log', $log_file);

        try {
            intersoccer_normalize_phone_number('12');
            $log = (string) file_get_contents($log_file);
            $this->assertStringContainsString('Invalid phone number format', $log);
            $this->assertStringNotContainsString($secret, $log);
            $this->assertStringContainsString('len=2 ..12', $log);
        } finally {
            ini_set('error_log', (string) $previous_log);
            if (is_string($log_file) && file_exists($log_file)) {
                unlink($log_file);
            }
        }
    }

    public function test_normalize_phone_error_logs_are_wp_debug_gated_in_source(): void {
        $path = dirname(__DIR__, 2) . '/includes/roster-export.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        $start = strpos($source, 'function intersoccer_normalize_phone_number');
        $this->assertNotFalse($start);
        $end = strpos($source, '/**', $start + 1);
        $this->assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString('intersoccer_redact_phone_for_log', $body);
        $this->assertDoesNotMatchRegularExpression('/error_log\\([^\\n]*\\$phone/', $body);
        $this->assertDoesNotMatchRegularExpression('/error_log\\([^\\n]*\\$normalized/', $body);

        $offset = 0;
        $log_count = 0;
        while (($pos = strpos($body, 'error_log(', $offset)) !== false) {
            $window = substr($body, max(0, $pos - 500), 500);
            $this->assertStringContainsString(
                "defined('WP_DEBUG') && WP_DEBUG",
                $window,
                'Expected WP_DEBUG guard before phone normalization error_log at offset ' . $pos
            );
            $log_count++;
            $offset = $pos + 10;
        }
        $this->assertGreaterThan(0, $log_count);
    }
}
