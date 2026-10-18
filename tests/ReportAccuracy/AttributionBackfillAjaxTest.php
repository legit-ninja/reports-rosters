<?php
/**
 * Regression: Advanced-page AJAX financial attribution backfill dry-run gate.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy {

use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService as Attr;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/ReportAccuracyFixtures.php';

class AttributionBackfillAjaxTest extends TestCase {

    /** @var string|false|null */
    private $errorLogIniBeforeAdapterLoad;

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,bool>|null */
    private $capsBackup;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        unset($GLOBALS['intersoccer_test_attribution_service_override'], $GLOBALS['intersoccer_test_json_error_throws']);
        $this->postBackup = $_POST;
        $this->capsBackup = $GLOBALS['intersoccer_test_current_user_caps'] ?? null;
        $this->errorLogIniBeforeAdapterLoad = ini_set('error_log', '/dev/null');
        self::ensureOopAdapterLoaded();
    }

    protected function tearDown(): void {
        $_POST = $this->postBackup;
        if ($this->capsBackup === null) {
            unset($GLOBALS['intersoccer_test_current_user_caps']);
        } else {
            $GLOBALS['intersoccer_test_current_user_caps'] = $this->capsBackup;
        }
        unset($GLOBALS['intersoccer_test_attribution_service_override'], $GLOBALS['intersoccer_test_json_error_throws']);
        if ($this->errorLogIniBeforeAdapterLoad !== null) {
            ini_set('error_log', (string) $this->errorLogIniBeforeAdapterLoad);
            $this->errorLogIniBeforeAdapterLoad = null;
        }
        parent::tearDown();
    }

    private static function ensureOopAdapterLoaded(): void {
        if (function_exists('intersoccer_oop_backfill_financial_attribution_ajax')) {
            return;
        }

        if (!defined('INTERSOCCER_OOP_ACTIVE')) {
            define('INTERSOCCER_OOP_ACTIVE', true);
        }

        require_once dirname(__DIR__, 2) . '/includes/oop-adapter.php';
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function invokeBackfillAjax(array $post): array {
        $_POST = array_merge([
            'nonce' => 'test_nonce',
            'start_date' => '2025-01-01',
            'batch_size' => '100',
        ], $post);

        ob_start();
        intersoccer_oop_backfill_financial_attribution_ajax();
        $raw = (string) ob_get_clean();

        $json = $raw;
        if (preg_match('/\{"success".*\}\s*$/s', $raw, $matches)) {
            $json = $matches[0];
        }

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'expected JSON response from backfill AJAX; output: ' . substr($raw, 0, 500));

        return $decoded;
    }

    /**
     * @param array<string,mixed> $post
     * @dataProvider dryRunPostProvider
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ajax_backfill_dry_run_never_calls_apply_path(array $post): void {
        $service = $this->createMock(Attr::class);
        $service->expects($this->never())->method('backfillBatch');
        $service->expects($this->never())->method('applyMissingAttributionInDateRange');
        $service->expects($this->once())->method('summarizeMissingAttributionInDateRange')
            ->willReturn(['order_count' => 2, 'gross_chf' => 400.0, 'net_chf' => 360.0]);
        $GLOBALS['intersoccer_test_attribution_service_override'] = $service;

        $response = $this->invokeBackfillAjax($post);

        $this->assertTrue($response['success']);
        $this->assertTrue($response['data']['dry_run']);
        $this->assertSame(2, $response['data']['order_count']);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public function dryRunPostProvider(): array {
        return [
            'no apply' => [[]],
            'apply false' => [['apply' => 'false']],
            'apply zero' => [['apply' => '0']],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ajax_backfill_apply_without_confirm_stays_dry_run(): void {
        $service = $this->createMock(Attr::class);
        $service->expects($this->never())->method('backfillBatch');
        $service->expects($this->once())->method('summarizeMissingAttributionInDateRange')
            ->willReturn(['order_count' => 0, 'gross_chf' => 0.0, 'net_chf' => 0.0]);
        $GLOBALS['intersoccer_test_attribution_service_override'] = $service;

        $response = $this->invokeBackfillAjax(['apply' => 'true']);

        $this->assertTrue($response['data']['dry_run']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ajax_backfill_apply_with_confirm_calls_backfill_batch_once(): void {
        $service = $this->createMock(Attr::class);
        $service->expects($this->never())->method('summarizeMissingAttributionInDateRange');
        $service->expects($this->never())->method('applyMissingAttributionInDateRange');
        $service->expects($this->once())->method('backfillBatch')
            ->with('2025-01-01', 100)
            ->willReturn([
                'migrated_count' => 5,
                'remaining_count' => 0,
                'more_remaining' => false,
            ]);
        $GLOBALS['intersoccer_test_attribution_service_override'] = $service;

        $response = $this->invokeBackfillAjax(['apply' => 'true', 'confirm' => 'true']);

        $this->assertTrue($response['success']);
        $this->assertFalse($response['data']['dry_run']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_ajax_backfill_requires_manage_options(): void {
        $GLOBALS['intersoccer_test_current_user_caps'] = [];
        $GLOBALS['intersoccer_test_json_error_throws'] = true;

        $service = $this->createMock(Attr::class);
        $service->expects($this->never())->method('backfillBatch');
        $service->expects($this->never())->method('summarizeMissingAttributionInDateRange');
        $GLOBALS['intersoccer_test_attribution_service_override'] = $service;

        $_POST = ['nonce' => 'test_nonce', 'start_date' => '2025-01-01', 'batch_size' => '100'];
        ob_start();
        try {
            intersoccer_oop_backfill_financial_attribution_ajax();
            ob_end_clean();
            $this->fail('expected permission denied JSON error');
        } catch (\RuntimeException $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $this->assertStringContainsString('Permission denied', $e->getMessage());
        }
    }
}

}

namespace {

    if (!function_exists('intersoccer_oop_register_roster_ajax_handlers')) {
        function intersoccer_oop_register_roster_ajax_handlers() {
        }
    }

}
