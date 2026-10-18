<?php
/**
 * Regression: attribution backfill dry-run (counts and CHF totals, no writes).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService as Attr;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/ReportAccuracyFixtures.php';

class AttributionBackfillTest extends TestCase {

    /** @var Attr */
    private $service;

    /** @var object|null */
    private $previous_wpdb;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        $this->service = new Attr($this->createMock(Logger::class));
        global $wpdb;
        $this->previous_wpdb = $wpdb ?? null;
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previous_wpdb;
        unset($GLOBALS['intersoccer_test_attribution_service_override']);
        parent::tearDown();
    }

    public function test_backfill_dry_run_summary_counts_and_chf_without_writes(): void {
        ReportAccuracyStore::addOrder((new FakeOrder(601))
            ->addLine(new FakeLineItem(6011, ['subtotal' => 400.0, 'total' => 360.0])));
        ReportAccuracyStore::addOrder((new FakeOrder(602))
            ->addLine(new FakeLineItem(6021, ['subtotal' => 100.0, 'total' => 100.0]))
            ->setMeta(Attr::META_ORDER_ATTRIBUTION_AT, '2026-07-01 10:00:05'));

        global $wpdb;
        $wpdb = new BackfillMissingAttributionWpdb([601]);

        $summary = $this->service->summarizeMissingAttributionInDateRange('2025-01-01', '2026-12-31');

        $this->assertSame(1, $summary['order_count']);
        $this->assertSame(400.0, $summary['gross_chf']);
        $this->assertSame(360.0, $summary['net_chf']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    /**
     * @param array<string,mixed> $assoc_args
     * @dataProvider dryRunAssocArgsProvider
     */
    public function test_attribution_backfill_cli_dry_run_never_calls_apply(array $assoc_args): void {
        $this->bootAttributionBackfillCli();

        $service = $this->createMock(Attr::class);
        $service->expects($this->never())->method('applyMissingAttributionInDateRange');
        $service->method('summarizeMissingAttributionInDateRange')->willReturn([
            'order_count' => 0,
            'gross_chf' => 0.0,
            'net_chf' => 0.0,
        ]);
        $GLOBALS['intersoccer_test_attribution_service_override'] = $service;

        $cli = new \Intersoccer_Rr_Wp_Cli();
        $cli->attribution_backfill([], $assoc_args);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public function dryRunAssocArgsProvider(): array {
        return [
            'default' => [[]],
            'no-apply flag' => [['no-apply' => true]],
            'apply false' => [['apply' => false]],
            'yes without apply' => [['yes' => true]],
        ];
    }

    private function bootAttributionBackfillCli(): void {
        if (!defined('WP_CLI')) {
            define('WP_CLI', true);
        }
        if (!class_exists('WP_CLI')) {
            eval(<<<'PHP'
class WP_CLI {
    public static function log($msg) {}
    public static function success($msg) {}
    public static function error($msg) { throw new \RuntimeException((string) $msg); }
    public static function confirm($msg) { throw new \RuntimeException('confirm should not run in dry-run test'); }
    public static function add_command($name, $callable) {}
}
PHP
            );
        }
        if (!function_exists('WP_CLI\\Utils\\get_flag_value')) {
            eval(<<<'PHP'
namespace WP_CLI\Utils {
    function get_flag_value($assoc_args, $flag, $default = false) {
        if (!isset($assoc_args[$flag])) {
            return $default;
        }
        return $assoc_args[$flag];
    }
}
PHP
            );
        }
        if (!class_exists('Intersoccer_Rr_Wp_Cli', false)) {
            require_once dirname(__DIR__, 2) . '/includes/wp-cli/roster-cli-commands.php';
        }
    }
}

/**
 * Minimal wpdb stub: findMissingAttributionOrderIds() only needs get_col() after prepare().
 */
class BackfillMissingAttributionWpdb {
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $prefix = 'wp_';

    /** @var array<int,int> */
    private $order_ids;

    /** @param array<int,int> $order_ids */
    public function __construct(array $order_ids) {
        $this->order_ids = $order_ids;
    }

    public function prepare($query, ...$args) {
        return 'BACKFILL_MISSING_ATTRIBUTION';
    }

    public function get_col($query) {
        if ($query === 'BACKFILL_MISSING_ATTRIBUTION') {
            return array_map('strval', $this->order_ids);
        }
        return [];
    }
}
