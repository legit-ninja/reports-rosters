<?php
/**
 * Repro (failing tests): OrderFinancialAttributionService + discount helpers feeding the Booking Report.
 *
 * Synthetic data only. Scenario names follow the report-accuracy recount (R6 sections).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService as Attr;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/ReportAccuracyFixtures.php';

class FinancialAttributionAccuracyTest extends TestCase {

    /** @var Attr */
    private $service;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        $this->service = new Attr($this->createMock(Logger::class));
    }

    private static function itemTotal(int $item_id): float {
        return (float) ReportAccuracyStore::getItemMeta($item_id, Attr::META_ITEM_DISCOUNT_TOTAL);
    }

    // ---------------------------------------------------------------- S02 (parser)

    /** S02: the legacy parser takes the first digit run, which is the "67" of the CHF entity. */
    public function test_S02_parse_legacy_discount_amount_with_html_entity_currency(): void {
        $this->markTestIncomplete('Pending fix C: legacy HTML discount amount parsing.');
        $parsed = intersoccer_parse_legacy_discount_amount('&#67;&#72;&#70;105.00');
        $this->assertSame(105.0, $parsed, 'S02: expected 105.00; parser returns ' . number_format($parsed, 2));
    }

    public function test_S02_parse_legacy_discount_amount_with_wc_price_markup(): void {
        $this->markTestIncomplete('Pending fix C: legacy HTML discount amount parsing.');
        $html = '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#67;&#72;&#70;</span>&nbsp;105.00</bdi></span>';
        $parsed = intersoccer_parse_legacy_discount_amount($html);
        $this->assertSame(105.0, $parsed, 'S02: expected 105.00; parser returns ' . number_format($parsed, 2));
    }

    // ---------------------------------------------------------------- S18 (attributed discount != line discount)

    /** S18 (a): attribution copies the misparsed legacy amount into _intersoccer_total_item_discount. */
    public function test_S18_attribution_copies_legacy_html_amount_correctly(): void {
        $this->markTestIncomplete('Pending fix C: attribution after legacy discount parse fix.');
        $order = (new FakeOrder(201))->addLine(new FakeLineItem(2011, ['subtotal' => 420.0, 'total' => 420.0], [
            'Discount' => 'Sibling Discount',
            'Discount Amount' => '&#67;&#72;&#70;105.00',
        ]));
        ReportAccuracyStore::addOrder($order);

        $this->service->attributeOrderDiscounts($order);

        $this->assertSame(105.0, self::itemTotal(2011),
            'S18: expected attributed discount 105.00; attribution wrote ' . number_format(self::itemTotal(2011), 2));
    }

    /**
     * S18 (b): a coupon that only discounted line A (A: 200 -> 150, B: 200 -> 200) is spread by subtotal 25/25.
     * Expected per-line attribution = the line's own subtotal - total (A 50, B 0).
     */
    public function test_S18_coupon_attributed_to_the_line_it_discounted(): void {
        $this->markTestIncomplete('Pending fix C: per-line coupon attribution.');
        $order = (new FakeOrder(202))
            ->addLine(new FakeLineItem(2021, ['subtotal' => 200.0, 'total' => 150.0]))
            ->addLine(new FakeLineItem(2022, ['subtotal' => 200.0, 'total' => 200.0]))
            ->addCoupon(new FakeCoupon('synthetic-line-coupon', 50.0));
        ReportAccuracyStore::addOrder($order);

        $this->service->attributeOrderDiscounts($order);

        $this->assertSame([50.0, 0.0], [self::itemTotal(2021), self::itemTotal(2022)],
            'S18: expected A 50.00 / B 0.00; attribution wrote A ' . number_format(self::itemTotal(2021), 2)
            . ' / B ' . number_format(self::itemTotal(2022), 2));
    }

    // ---------------------------------------------------------------- S05a (refund doubled)

    /**
     * S05a: attributeOrderRefunds adds to the stored value (existing + amount). A second run for the same refund
     * (re-fired hook, retry, or any second caller) doubles the reimbursement.
     */
    public function test_S05a_refund_attribution_is_idempotent_per_refund(): void {
        $this->markTestIncomplete('Pending fix D: idempotent refund reimbursement.');
        $order = (new FakeOrder(203))->addLine(new FakeLineItem(2031, ['subtotal' => 530.0, 'total' => 450.5]));
        $refund = new FakeRefund(2039, 90.0, [new FakeRefundLine(2031, -90.0)]);
        $order->addRefund($refund);
        ReportAccuracyStore::addOrder($order);

        $this->service->attributeOrderRefunds($order, $refund);
        $this->service->attributeOrderRefunds($order, $refund);

        $stored = (float) ReportAccuracyStore::getItemMeta(2031, Attr::META_ITEM_REFUND);
        $this->assertSame(90.0, $stored, 'S05a: expected item refund 90.00; stored ' . number_format($stored, 2));
    }

    // ---------------------------------------------------------------- S09a / S09b (FR/DE fee counted twice)

    public function test_S09_localized_points_and_referral_fee_labels_classified(): void {
        $got = [
            intersoccer_classify_discount_fee('Réduction de crédits de parrainage'),
            intersoccer_classify_discount_fee('Empfehlungscredits-Rabatt'),
            intersoccer_classify_discount_fee('Réduction de parrainage'),
        ];
        $this->assertSame(['referral_points', 'referral_points', 'referral_first_order'], $got,
            'S09: expected referral_points/referral_points/referral_first_order; classifier returns ' . implode('/', $got));
    }

    /**
     * CRS cart fee labels (EN msgid -> FR/DE msgstr from customer-referral-system languages/*.po).
     *
     * @dataProvider crsCartFeeLabelProvider
     */
    public function test_crs_cart_fee_label_maps_to_canonical_type(string $label, string $expected): void {
        $this->assertSame($expected, intersoccer_classify_discount_fee($label));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function crsCartFeeLabelProvider(): array {
        return [
            'EN Referral Credits Discount' => ['Referral Credits Discount', 'referral_points'],
            'FR Referral Credits Discount' => ['Réduction de crédits de parrainage', 'referral_points'],
            'DE Referral Credits Discount' => ['Empfehlungscredits-Rabatt', 'referral_points'],
            'EN Referral Discount' => ['Referral Discount', 'referral_first_order'],
            'FR Referral Discount' => ['Réduction de parrainage', 'referral_first_order'],
            'DE Referral Discount' => ['Empfehlungsrabatt', 'referral_first_order'],
            'EN Points Discount' => ['Points Discount', 'referral_points'],
            'FR Points Discount' => ['Réduction de points', 'referral_points'],
            'DE Points Discount' => ['Punkte-Rabatt', 'referral_points'],
        ];
    }

    /**
     * S09a: FR points fee (-34) plus _intersoccer_points_redeemed = 34. The FR label is classified "other", so the
     * order-meta points are added again: 68 instead of 34 on (re-)attribution.
     */
    public function test_S09a_localized_points_fee_not_counted_twice(): void {
        $order = (new FakeOrder(204))
            ->addLine(new FakeLineItem(2041, ['subtotal' => 405.0, 'total' => 405.0]))
            ->addFee(new FakeFee('Réduction de crédits de parrainage', -34.0))
            ->setMeta('_intersoccer_points_redeemed', '34');
        ReportAccuracyStore::addOrder($order);

        $this->service->attributeOrderDiscounts($order, true);

        $this->assertSame(34.0, self::itemTotal(2041),
            'S09a: expected attributed discount 34.00; attribution wrote ' . number_format(self::itemTotal(2041), 2));
    }

    /** S09b: FR referral fee (-31) plus _intersoccer_first_order_discount_amount = 31 -> 62. */
    public function test_S09b_localized_referral_fee_not_counted_twice(): void {
        $order = (new FakeOrder(205))
            ->addLine(new FakeLineItem(2051, ['subtotal' => 310.0, 'total' => 310.0]))
            ->addFee(new FakeFee('Réduction de parrainage', -31.0))
            ->setMeta('_intersoccer_first_order_discount_amount', '31');
        ReportAccuracyStore::addOrder($order);

        $this->service->attributeOrderDiscounts($order);

        $this->assertSame(31.0, self::itemTotal(2051),
            'S09b: expected attributed discount 31.00; attribution wrote ' . number_format(self::itemTotal(2051), 2));
    }

    /** Store API / classic checkout: a second attribution pass must not change line totals. */
    public function test_S10d_checkout_attribution_is_idempotent(): void {
        $order = (new FakeOrder(207))
            ->addLine(new FakeLineItem(2071, ['subtotal' => 405.0, 'total' => 371.0]))
            ->addFee(new FakeFee('Referral Credits Discount', -34.0))
            ->setMeta('_intersoccer_points_redeemed', '34');
        ReportAccuracyStore::addOrder($order);

        $this->service->attributeOrderDiscounts($order);
        $first_total = self::itemTotal(2071);
        $this->service->attributeOrderDiscounts($order);
        $second_total = self::itemTotal(2071);

        $this->assertSame(34.0, $first_total);
        $this->assertSame($first_total, $second_total);
    }

    /** Control (PASSES): the EN label is recognised, so the order meta is not added again. */
    public function test_S09_control_english_points_fee_counted_once(): void {
        $order = (new FakeOrder(206))
            ->addLine(new FakeLineItem(2061, ['subtotal' => 405.0, 'total' => 405.0]))
            ->addFee(new FakeFee('Referral Credits Discount', -34.0))
            ->setMeta('_intersoccer_points_redeemed', '34');
        ReportAccuracyStore::addOrder($order);

        $this->service->attributeOrderDiscounts($order, true);

        $this->assertSame(34.0, self::itemTotal(2061));
    }
}
