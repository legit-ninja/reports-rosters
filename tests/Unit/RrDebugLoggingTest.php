<?php
/**
 * Tests for gated Reports & Rosters debug logging helpers.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RrDebugLoggingTest extends TestCase {
    private ?string $previousErrorLog = null;

    private ?string $tempErrorLog = null;

    protected function setUp(): void {
        parent::setUp();

        if (!\function_exists('intersoccer_rr_debug_enabled')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }
        $this->assertTrue(\function_exists('intersoccer_rr_debug_enabled'));

        $this->previousErrorLog = ini_get('error_log');
        $this->tempErrorLog = tempnam(sys_get_temp_dir(), 'rr-debug-log-');
        ini_set('error_log', $this->tempErrorLog);
        file_put_contents($this->tempErrorLog, '');
    }

    protected function tearDown(): void {
        if ($this->previousErrorLog !== null) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if ($this->tempErrorLog !== null && is_file($this->tempErrorLog)) {
            unlink($this->tempErrorLog);
        }
        parent::tearDown();
    }

    private function capturedLog(): string {
        return is_readable($this->tempErrorLog) ? (string) file_get_contents($this->tempErrorLog) : '';
    }

    public function test_debug_helper_false_without_explicit_constant(): void {
        $this->assertFalse(defined('INTERSOCCER_RR_DEBUG_LOG'));
        $this->assertTrue(defined('WP_DEBUG') && WP_DEBUG);
        $this->assertFalse(\intersoccer_rr_debug_enabled());
    }

    public function test_debug_log_produces_no_output_by_default(): void {
        \intersoccer_rr_debug_log('RR debug probe order_id=42 player_name=ShouldNotAppear');

        $log = $this->capturedLog();
        $this->assertSame('', $log);
    }

    public function test_allowlisted_request_fields_omit_sensitive_form_values(): void {
        $fields = \intersoccer_rr_debug_allowlisted_request_fields([
            'order_id' => 42,
            'order_item_ids' => ['10', '11'],
            'target_variation_id' => 99,
            'assigned_attendee' => 'Sensitive Player',
            'player_email' => 'secret@example.com',
            'medical_conditions' => 'asthma',
        ]);

        $encoded = \wp_json_encode($fields);
        $this->assertStringContainsString('"order_id":42', $encoded);
        $this->assertStringContainsString('"order_item_ids":[10,11]', $encoded);
        $this->assertStringNotContainsString('Sensitive', $encoded);
        $this->assertStringNotContainsString('secret@example.com', $encoded);
        $this->assertStringNotContainsString('asthma', $encoded);
    }

    public function test_player_meta_summary_contains_ids_not_pii(): void {
        $summary = \intersoccer_rr_debug_summarize_players_meta([
            [
                'id' => 'player-abc',
                'first_name' => 'Sample',
                'last_name' => 'Child',
                'dob' => '2012-05-01',
                'email' => 'child@example.com',
                'medical_conditions' => 'none',
            ],
        ]);

        $encoded = \wp_json_encode($summary);
        $this->assertStringContainsString('player-abc', $encoded);
        $this->assertStringNotContainsString('Sample', $encoded);
        $this->assertStringNotContainsString('2012-05-01', $encoded);
        $this->assertStringNotContainsString('child@example.com', $encoded);
    }

    public function test_order_item_meta_summary_lists_keys_without_sensitive_values(): void {
        $summary = \intersoccer_rr_debug_summarize_order_item_meta([
            'Assigned Attendee' => ['Jane Example'],
            'assigned_player_id' => 'player-99',
            'Medical Conditions' => ['asthma'],
            'Player Email' => ['secret@example.com'],
            'Variation ID' => ['12345'],
        ]);

        $encoded = \wp_json_encode($summary);
        $this->assertStringContainsString('Assigned Attendee', $encoded);
        $this->assertStringContainsString('player-99', $encoded);
        $this->assertStringContainsString('12345', $encoded);
        $this->assertStringNotContainsString('Jane', $encoded);
        $this->assertStringNotContainsString('asthma', $encoded);
        $this->assertStringNotContainsString('secret@example.com', $encoded);
    }

    public function test_debug_payload_includes_order_id_not_attendee_when_serialized(): void {
        $payload = \wp_json_encode(\intersoccer_rr_debug_allowlisted_request_fields([
            'order_id' => 7,
            'order_item_id' => 55,
            'assigned_attendee' => 'Hidden Name',
            'dob' => '2010-01-01',
        ]));

        $this->assertStringContainsString('"order_id":7', $payload);
        $this->assertStringContainsString('"order_item_id":55', $payload);
        $this->assertStringNotContainsString('Hidden Name', $payload);
        $this->assertStringNotContainsString('2010-01-01', $payload);
    }
}
