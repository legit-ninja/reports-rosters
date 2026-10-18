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
