<?php
/**
 * Regression: backfill apply/skip counts, skip markers, and meta-only writes.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService as Attr;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/ReportAccuracyFixtures.php';

class AttributionBackfillApplyTest extends TestCase {

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
        unset($GLOBALS['intersoccer_test_order_save_hook_fires']);
        parent::tearDown();
    }

    public function test_mixed_batch_dry_run_and_apply_report_applied_and_skipped(): void {
        $this->seedMixedPendingBatch();
        global $wpdb;
        $wpdb = new \BackfillMissingAttributionWpdb();

        $dry = $this->service->summarizeMissingAttributionInDateRange('2025-01-01', '2026-12-31');
        $this->assertSame(1, $dry['applied_count']);
        $this->assertSame(2, $dry['skipped_count']);
        $this->assertSame(0, $dry['previously_skipped_count']);

        $apply = $this->service->applyMissingAttributionInDateRange('2025-01-01', '2026-12-31');
        $this->assertSame(1, $apply['applied_count']);
        $this->assertSame(2, $apply['skipped_count']);
        $this->assertSame(0, $apply['failed_count']);

        $this->assertNotSame('', ReportAccuracyStore::$orders[801]->get_meta(Attr::META_ORDER_ATTRIBUTION_AT));
        $this->assertSame(Attr::SKIP_REASON_NO_ATTRIBUTABLE_ITEMS, ReportAccuracyStore::$orders[802]->get_meta(Attr::META_ATTRIBUTION_SKIPPED));
        $this->assertSame(Attr::SKIP_REASON_NO_LINE_ITEMS, ReportAccuracyStore::$orders[803]->get_meta(Attr::META_ATTRIBUTION_SKIPPED));
    }

    public function test_second_apply_finds_zero_pending_for_skipped_orders(): void {
        $this->seedMixedPendingBatch();
        global $wpdb;
        $wpdb = new \BackfillMissingAttributionWpdb();
        $this->service->applyMissingAttributionInDateRange('2025-01-01', '2026-12-31');

        $summary = $this->service->summarizeMissingAttributionInDateRange('2025-01-01', '2026-12-31');
        $this->assertSame(0, $summary['order_count']);
        $this->assertSame(0, $summary['applied_count']);
        $this->assertSame(0, $summary['skipped_count']);
    }

    public function test_apply_does_not_call_fake_order_save_method(): void {
        ReportAccuracyStore::addOrder((new FakeOrder(811))
            ->addLine(new FakeLineItem(8111, ['subtotal' => 120.0, 'total' => 120.0])));
        global $wpdb;
        $wpdb = new \BackfillMissingAttributionWpdb();

        $fires = 0;
        $GLOBALS['intersoccer_test_order_save_hook_fires'] = &$fires;
        add_action('woocommerce_before_order_object_save', static function () use (&$fires) {
            $fires++;
        }, 10, 0);
        add_action('woocommerce_update_order', static function () use (&$fires) {
            $fires++;
        }, 10, 0);
        add_action('save_post_shop_order', static function () use (&$fires) {
            $fires++;
        }, 10, 0);

        $this->service->applyMissingAttributionInDateRange('2025-01-01', '2026-12-31');

        $this->assertSame(0, $fires);
        $this->assertFalse($this->orderWritesIncludeFullSave(811));
    }

    public function test_apply_touches_only_attribution_and_skip_meta_keys(): void {
        ReportAccuracyStore::addOrder((new FakeOrder(821))
            ->addLine(new FakeLineItem(8211, ['subtotal' => 90.0, 'total' => 90.0], ['Discount' => 'Promo'])));
        global $wpdb;
        $wpdb = new \BackfillMissingAttributionWpdb();

        $order_meta_before = ReportAccuracyStore::$orders[821]->metaKeys();
        $item_meta_before = array_keys(ReportAccuracyStore::$item_meta[8211] ?? []);

        $this->service->applyMissingAttributionInDateRange('2025-01-01', '2026-12-31');

        $this->assertNotSame('', ReportAccuracyStore::$orders[821]->get_meta(Attr::META_ORDER_ATTRIBUTION_AT));

        $allowed_order = [
            Attr::META_ORDER_ATTRIBUTION_AT,
            Attr::META_ORDER_DISCOUNT_SUMMARY,
            Attr::META_ATTRIBUTION_SKIPPED,
        ];
        foreach (ReportAccuracyStore::$orders[821]->metaKeys() as $key) {
            if (!in_array($key, $order_meta_before, true) && !in_array($key, $allowed_order, true)) {
                $this->fail('unexpected order meta key written: ' . $key);
            }
        }

        $allowed_item = [
            Attr::META_ITEM_DISCOUNTS,
            Attr::META_ITEM_DISCOUNT_TOTAL,
            Attr::META_ITEM_VERSION,
            Attr::META_ITEM_REFUND,
            '_product_id',
            '_variation_id',
            '_qty',
            '_line_subtotal',
            '_line_total',
            'Discount',
        ];
        foreach (array_keys(ReportAccuracyStore::$item_meta[8211] ?? []) as $key) {
            if (!in_array($key, $item_meta_before, true) && !in_array($key, $allowed_item, true)) {
                $this->fail('unexpected item meta key written: ' . $key);
            }
        }
    }

    public function test_findMissingAttributionOrderIds_sql_excludes_attributed_and_skipped_orders(): void {
        ReportAccuracyStore::addOrder((new FakeOrder(901))
            ->addLine(new FakeLineItem(9011, ['subtotal' => 10.0, 'total' => 10.0])));
        ReportAccuracyStore::addOrder((new FakeOrder(902))
            ->addLine(new FakeLineItem(9021, ['subtotal' => 20.0, 'total' => 20.0]))
            ->setMeta(Attr::META_ORDER_ATTRIBUTION_AT, '2026-07-01 10:00:00'));
        ReportAccuracyStore::addOrder((new FakeOrder(903))
            ->setMeta(Attr::META_ATTRIBUTION_SKIPPED, Attr::SKIP_REASON_NO_LINE_ITEMS));

        global $wpdb;
        $wpdb = new \BackfillMissingAttributionWpdb();

        $ids = $this->service->findMissingAttributionOrderIds('2025-01-01', '2026-12-31');
        $this->assertSame([901], $ids);

        $pending_sql = '';
        foreach ($wpdb->preparedQueries() as $entry) {
            if (strpos($entry['query'], 'pm_skip.meta_id IS NULL') !== false) {
                $pending_sql = $entry['query'];
                break;
            }
        }
        $this->assertStringContainsString('pm_skip.meta_id IS NULL', $pending_sql);
        $this->assertStringContainsString('pm_skip.meta_value IS NULL', $pending_sql);
    }

    public function test_successful_attribution_clears_skip_marker(): void {
        $order = (new FakeOrder(905))
            ->addLine(new FakeLineItem(9051, ['subtotal' => 40.0, 'total' => 40.0]))
            ->setMeta(Attr::META_ATTRIBUTION_SKIPPED, Attr::SKIP_REASON_NO_ATTRIBUTABLE_ITEMS);
        ReportAccuracyStore::addOrder($order);

        $this->assertTrue($this->service->attributeOrderDiscounts($order, true));
        $this->assertNotSame('', $order->get_meta(Attr::META_ORDER_ATTRIBUTION_AT));
        $this->assertFalse($order->meta_exists(Attr::META_ATTRIBUTION_SKIPPED));
    }

    public function test_stale_skip_marker_excluded_from_pending_backfill_query(): void {
        ReportAccuracyStore::addOrder((new FakeOrder(907))
            ->addLine(new FakeLineItem(9071, ['subtotal' => 15.0, 'total' => 15.0]))
            ->setMeta(Attr::META_ATTRIBUTION_SKIPPED, Attr::SKIP_REASON_NO_ATTRIBUTABLE_ITEMS));
        global $wpdb;
        $wpdb = new \BackfillMissingAttributionWpdb();

        $this->assertSame([], $this->service->findMissingAttributionOrderIds('2025-01-01', '2026-12-31'));
    }

    public function test_retry_skipped_apply_clears_marker_when_order_becomes_attributable(): void {
        $order = (new FakeOrder(906))
            ->setMeta(Attr::META_ATTRIBUTION_SKIPPED, Attr::SKIP_REASON_NO_LINE_ITEMS);
        ReportAccuracyStore::addOrder($order);
        $order->addLine(new FakeLineItem(9061, ['subtotal' => 55.0, 'total' => 55.0]));

        global $wpdb;
        $wpdb = new \BackfillMissingAttributionWpdb();

        $dry = $this->service->summarizeRetrySkippedInDateRange('2025-01-01', '2026-12-31');
        $this->assertSame(1, $dry['order_count']);
        $this->assertSame(1, $dry['applied_count']);

        $apply = $this->service->applyRetrySkippedInDateRange('2025-01-01', '2026-12-31');
        $this->assertSame(1, $apply['applied_count']);
        $this->assertFalse(ReportAccuracyStore::$orders[906]->meta_exists(Attr::META_ATTRIBUTION_SKIPPED));
        $this->assertNotSame('', ReportAccuracyStore::$orders[906]->get_meta(Attr::META_ORDER_ATTRIBUTION_AT));
    }

    private function seedMixedPendingBatch(): void {
        ReportAccuracyStore::addOrder((new FakeOrder(801))
            ->addLine(new FakeLineItem(8011, ['subtotal' => 200.0, 'total' => 200.0])));
        ReportAccuracyStore::addOrder((new FakeOrder(802))
            ->addLine(new FakeLineItem(8021, ['product_id' => 0, 'subtotal' => 50.0, 'total' => 50.0])));
        ReportAccuracyStore::addOrder(new FakeOrder(803));
    }

    private function orderWritesIncludeFullSave(int $order_id): bool {
        foreach (ReportAccuracyStore::$writes as $write) {
            if ($write['object'] === 'order' && $write['id'] === $order_id && $write['key'] === '(save)') {
                return true;
            }
        }
        return false;
    }
}
