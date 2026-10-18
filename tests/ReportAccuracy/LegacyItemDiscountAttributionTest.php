<?php
/**
 * Legacy PV line discount meta must survive forced attribution (refunded-order backfill).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService as Attr;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/ReportAccuracyFixtures.php';

class LegacyItemDiscountAttributionTest extends TestCase {

    /** @var Attr */
    private $service;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        $this->service = new Attr($this->createMock(Logger::class));
    }

    public function test_forced_attribution_seeds_legacy_sibling_discount_without_order_fee(): void {
        $order = (new FakeOrder(701, 'refunded'))
            ->addLine(new FakeLineItem(7011, ['subtotal' => 200.0, 'total' => 133.0], [
                'Discount' => 'Sibling Discount',
                'Discount Amount' => '67.00',
            ]));
        $order->total_refunded = 50.0;
        ReportAccuracyStore::addOrder($order);

        $this->assertTrue($this->service->reattributeFullOrder($order));

        $breakdown = ReportAccuracyStore::getItemMeta(7011, Attr::META_ITEM_DISCOUNTS);
        $this->assertIsArray($breakdown);
        $this->assertSame(67.0, (float) ReportAccuracyStore::getItemMeta(7011, Attr::META_ITEM_DISCOUNT_TOTAL));
        $this->assertSame('legacy_item_meta', $breakdown[0]['source']);

        $summary = $order->get_meta(Attr::META_ORDER_DISCOUNT_SUMMARY, true);
        $this->assertIsArray($summary);
        $this->assertSame(67.0, (float) ($summary['sibling'] ?? 0.0));
    }

    public function test_same_type_fee_equal_to_legacy_line_discount_is_not_double_counted(): void {
        $order = (new FakeOrder(702))
            ->addLine(new FakeLineItem(7021, ['subtotal' => 200.0, 'total' => 133.0], [
                'Discount' => 'Sibling Discount',
                'Discount Amount' => '67.00',
            ]))
            ->addFee(new FakeFee('20% Sibling Camp Discount', -67.0));
        ReportAccuracyStore::addOrder($order);

        $this->assertTrue($this->service->attributeOrderDiscounts($order, true));

        $this->assertSame(67.0, (float) ReportAccuracyStore::getItemMeta(7021, Attr::META_ITEM_DISCOUNT_TOTAL));
        $breakdown = ReportAccuracyStore::getItemMeta(7021, Attr::META_ITEM_DISCOUNTS);
        $this->assertCount(1, $breakdown);
    }

    public function test_legacy_item_discount_merges_with_distinct_same_type_order_fee(): void {
        $order = (new FakeOrder(703))
            ->addLine(new FakeLineItem(7031, ['subtotal' => 200.0, 'total' => 140.0], [
                'Discount' => '20% Sibling Discount',
                'Discount Amount' => '20.00',
            ]))
            ->addFee(new FakeFee('20% Sibling Camp Discount', -40.0));
        ReportAccuracyStore::addOrder($order);

        $this->assertTrue($this->service->attributeOrderDiscounts($order, true));

        $this->assertSame(60.0, (float) ReportAccuracyStore::getItemMeta(7031, Attr::META_ITEM_DISCOUNT_TOTAL));
        $breakdown = ReportAccuracyStore::getItemMeta(7031, Attr::META_ITEM_DISCOUNTS);
        $this->assertCount(2, $breakdown);
    }
}
