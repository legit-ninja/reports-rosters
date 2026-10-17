<?php
/**
 * Security-order tests for the legacy move-players AJAX handler.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class MovePlayersAjaxSecurityTest extends TestCase {
    private ?string $previousErrorLog = null;

    private ?string $tempErrorLog = null;

    protected function setUp(): void {
        parent::setUp();

        if (!\function_exists('intersoccer_move_players_ajax')) {
            require_once dirname(__DIR__, 2) . '/includes/advanced.php';
        }

        $this->previousErrorLog = ini_get('error_log');
        $this->tempErrorLog = tempnam(sys_get_temp_dir(), 'rr-move-players-log-');
        ini_set('error_log', $this->tempErrorLog);
        file_put_contents($this->tempErrorLog, '');

        $_POST = [
            'nonce' => 'bad-nonce',
            'target_variation_id' => 12345,
            'order_item_ids' => [999],
        ];

        $GLOBALS['intersoccer_test_json_error_throws'] = true;
        $GLOBALS['intersoccer_test_wc_get_product_forbidden'] = true;
        unset($GLOBALS['intersoccer_test_check_ajax_referer_callback'], $GLOBALS['intersoccer_test_current_user_caps']);
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_json_error_throws'],
            $GLOBALS['intersoccer_test_wc_get_product_forbidden'],
            $GLOBALS['intersoccer_test_check_ajax_referer_callback'],
            $GLOBALS['intersoccer_test_current_user_caps']
        );
        $_POST = [];

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

    public function test_bad_nonce_rejected_with_no_log_or_processing(): void {
        $GLOBALS['intersoccer_test_check_ajax_referer_callback'] = static function ($action, $query_arg, $die) {
            return $action === 'intersoccer_move_nonce' && $query_arg === 'nonce' && $die === false ? false : true;
        };

        try {
            \intersoccer_move_players_ajax();
            $this->fail('Expected wp_send_json_error to throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Security check failed', $e->getMessage());
        }

        $this->assertSame('', $this->capturedLog());
    }

    public function test_unauthenticated_user_rejected_with_no_log_or_processing(): void {
        $GLOBALS['intersoccer_test_check_ajax_referer_callback'] = static function () {
            return true;
        };
        $GLOBALS['intersoccer_test_current_user_caps'] = ['manage_options' => false];

        try {
            \intersoccer_move_players_ajax();
            $this->fail('Expected wp_send_json_error to throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Permission denied', $e->getMessage());
        }

        $this->assertSame('', $this->capturedLog());
    }
}
