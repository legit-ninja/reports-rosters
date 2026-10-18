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

    /** Order-level sibling bucket: legacy 67 on one line + sibling fee −67 → 67 total (not 134). */
    public function test_order_level_sibling_bucket_skips_matching_fee_allocation(): void {
        $order = (new FakeOrder(704))
            ->addLine(new FakeLineItem(7041, ['subtotal' => 335.0, 'total' => 335.0]))
            ->addLine(new FakeLineItem(7042, ['subtotal' => 268.0, 'total' => 201.0], [
                'Discount' => 'Sibling Discount',
                'Discount Amount' => '67.00',
            ]))
            ->addFee(new FakeFee('20% Sibling Camp Discount', -67.0));
        ReportAccuracyStore::addOrder($order);

        $this->assertTrue($this->service->attributeOrderDiscounts($order, true));

        $this->assertSame(0.0, (float) ReportAccuracyStore::getItemMeta(7041, Attr::META_ITEM_DISCOUNT_TOTAL));
        $this->assertSame(67.0, (float) ReportAccuracyStore::getItemMeta(7042, Attr::META_ITEM_DISCOUNT_TOTAL));
        $this->assertCount(1, ReportAccuracyStore::getItemMeta(7042, Attr::META_ITEM_DISCOUNTS));

        $summary = $order->get_meta(Attr::META_ORDER_DISCOUNT_SUMMARY, true);
        $this->assertSame(67.0, (float) ($summary['sibling'] ?? 0.0));
    }

    /** Non-matching sibling fee still allocates (67 legacy + 50 fee → 117). */
    public function test_non_matching_sibling_fee_adds_to_legacy_total(): void {
        $order = (new FakeOrder(705))
            ->addLine(new FakeLineItem(7051, ['subtotal' => 335.0, 'total' => 335.0]))
            ->addLine(new FakeLineItem(7052, ['subtotal' => 268.0, 'total' => 201.0], [
                'Discount' => 'Sibling Discount',
                'Discount Amount' => '67.00',
            ]))
            ->addFee(new FakeFee('20% Sibling Camp Discount', -67.0))
            ->addFee(new FakeFee('Sibling goodwill', -50.0));
        ReportAccuracyStore::addOrder($order);

        $this->assertTrue($this->service->attributeOrderDiscounts($order, true));

        $total = (float) ReportAccuracyStore::getItemMeta(7051, Attr::META_ITEM_DISCOUNT_TOTAL)
            + (float) ReportAccuracyStore::getItemMeta(7052, Attr::META_ITEM_DISCOUNT_TOTAL);
        $this->assertSame(117.0, round($total, 2));

        $summary = $order->get_meta(Attr::META_ORDER_DISCOUNT_SUMMARY, true);
        $this->assertSame(117.0, (float) ($summary['sibling'] ?? 0.0));
    }

    /** `other` bucket is never deduped — equal legacy and fee amounts both persist. */
    public function test_other_bucket_keeps_equal_legacy_and_fee(): void {
        $order = (new FakeOrder(706))
            ->addLine(new FakeLineItem(7061, ['subtotal' => 100.0, 'total' => 75.0], [
                'Discount' => 'Staff adjustment',
                'Discount Amount' => '25.00',
            ]))
            ->addFee(new FakeFee('Goodwill credit', -25.0));
        ReportAccuracyStore::addOrder($order);

        $this->assertTrue($this->service->attributeOrderDiscounts($order, true));

        $breakdown = ReportAccuracyStore::getItemMeta(7061, Attr::META_ITEM_DISCOUNTS);
        $this->assertCount(2, $breakdown);
        $this->assertSame(50.0, (float) ReportAccuracyStore::getItemMeta(7061, Attr::META_ITEM_DISCOUNT_TOTAL));
    }

    public function test_attribution_is_idempotent_for_legacy_bucket_dedupe(): void {
        $order = (new FakeOrder(707))
            ->addLine(new FakeLineItem(7071, ['subtotal' => 335.0, 'total' => 335.0]))
            ->addLine(new FakeLineItem(7072, ['subtotal' => 268.0, 'total' => 201.0], [
                'Discount' => 'Sibling Discount',
                'Discount Amount' => '67.00',
            ]))
            ->addFee(new FakeFee('20% Sibling Camp Discount', -67.0));
        ReportAccuracyStore::addOrder($order);

        $this->assertTrue($this->service->attributeOrderDiscounts($order, true));
        $first_discounts = ReportAccuracyStore::$item_meta[7072][Attr::META_ITEM_DISCOUNTS] ?? null;
        $first_total = ReportAccuracyStore::getItemMeta(7072, Attr::META_ITEM_DISCOUNT_TOTAL);

        $this->assertTrue($this->service->attributeOrderDiscounts($order, true));
        $second_discounts = ReportAccuracyStore::$item_meta[7072][Attr::META_ITEM_DISCOUNTS] ?? null;
        $second_total = ReportAccuracyStore::getItemMeta(7072, Attr::META_ITEM_DISCOUNT_TOTAL);

        $this->assertSame($first_total, $second_total);
        $this->assertSame($first_discounts, $second_discounts);
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
