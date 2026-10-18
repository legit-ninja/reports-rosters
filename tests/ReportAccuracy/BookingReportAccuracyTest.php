<?php
/**
 * Repro (failing tests): Booking Report (FinancialReportService) accuracy.
 *
 * Synthetic data only. Each failing test states the expected value and the value the report produces.
 * Scenario names follow the report-accuracy recount (R6 sections).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\FinancialReportService;
use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService as Attr;
use InterSoccer\ReportsRosters\Services\ProductTypeClassifierService;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/ReportAccuracyFixtures.php';

class BookingReportAccuracyTest extends TestCase {

    private const FROM = '2025-01-01';
    private const TO = '2026-10-12';

    /** @var FakeBookingWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        $this->wpdb = new FakeBookingWpdb();
    }

    private function report(string $from = self::FROM, string $to = self::TO): array {
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);
        $classifier = $this->createMock(ProductTypeClassifierService::class);
        $classifier->method('classifyOrderItem')->willReturn(ProductTypeClassifierService::TYPE_CAMP);
        $service = new FinancialReportService($this->createMock(Logger::class), $database, $classifier);
        $result = $service->getFinancialBookingReport($from, $to);
        $by_ref = [];
        foreach ($result['data'] as $row) {
            $by_ref[$row['ref']] = $row;
        }
        $result['by_ref'] = $by_ref;
        return $result;
    }

    /** Already-attributed order (attribution_at set), so the lazy write path is skipped. */
    private function attributedOrder(int $id, string $status = 'completed', string $date = '2026-07-01 10:00:00'): FakeOrder {
        return (new FakeOrder($id, $status, $date))->setMeta(Attr::META_ORDER_ATTRIBUTION_AT, '2026-07-01 10:00:05');
    }

    private static function money($v): float {
        return (float) str_replace(',', '', (string) $v);
    }

    // ---------------------------------------------------------------- S01a / S01b / S01c (sibling double-subtract, gross)

    /**
     * S01a: PV already lowered _line_subtotal by the sibling discount (subtotal = total = 420 after a 105 sibling
     * discount on a 525 camp). Attribution recorded the same 105. The report subtracts it again.
     */
    public function test_S01a_sibling_discount_not_subtracted_twice_from_final(): void {
        $this->markTestIncomplete('Pending fix B: sibling gross/final (#113 family).');
        $order = $this->attributedOrder(101)->addLine(new FakeLineItem(1011, ['subtotal' => 420.0, 'total' => 420.0], [
            'Discount' => 'Sibling Discount',
            'Discount Amount' => '105.00',
            Attr::META_ITEM_DISCOUNT_TOTAL => '105',
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['101-1011'];

        $this->assertSame(420.0, self::money($row['final_price']),
            'S01a: expected final 420.00 (line total paid); report final ' . $row['final_price']);
    }

    /**
     * S01b: same as S01a plus a 63 coupon on the line (subtotal 420, total 357). Attribution = 105 + 63 = 168.
     * Expected final 357; report 252 (#113 does not change this line: subtotal != total).
     */
    public function test_S01b_sibling_plus_coupon_final_equals_line_total(): void {
        $this->markTestIncomplete('Pending fix B: sibling gross/final (#113 family).');
        $order = $this->attributedOrder(102)->addLine(new FakeLineItem(1021, ['subtotal' => 420.0, 'total' => 357.0], [
            'Discount' => 'Sibling Discount',
            'Discount Amount' => '105.00',
            Attr::META_ITEM_DISCOUNT_TOTAL => '168',
        ]))->addCoupon(new FakeCoupon('synthetic15', 63.0));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['102-1021'];

        $this->assertSame(357.0, self::money($row['final_price']),
            'S01b: expected final 357.00; report final ' . $row['final_price']);
    }

    /**
     * S01c: gross (base price) should be the pre-sibling price (subtotal 420 + PV line discount 105 = 525).
     * The report uses the already-discounted _line_subtotal.
     */
    public function test_S01c_gross_includes_pv_sibling_discount(): void {
        $this->markTestIncomplete('Pending fix B: sibling gross/final (#113 family).');
        $order = $this->attributedOrder(103)->addLine(new FakeLineItem(1031, ['subtotal' => 420.0, 'total' => 420.0], [
            'Discount' => 'Sibling Discount',
            'Discount Amount' => '105.00',
            Attr::META_ITEM_DISCOUNT_TOTAL => '105',
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['103-1031'];

        $this->assertSame(525.0, self::money($row['base_price']),
            'S01c: expected gross 525.00; report gross ' . $row['base_price']);
    }

    // ---------------------------------------------------------------- #113 guard (points/referral fee, no PV meta)

    /**
     * #113 guard: subtotal = total = 525, a cart fee "Referral Credits Discount" of -15 attributed to the line.
     * Customer paid 510. Master reports 510 (PASSES). PR #113 (intersoccer_financial_line_final_price returns the
     * line total when subtotal ~= total) reports 525: run this test with the #113 files to see it fail.
     */
    public function test_PR113_guard_points_fee_on_subtotal_equals_total_line_keeps_discount(): void {
        $order = $this->attributedOrder(104)->addLine(new FakeLineItem(1041, ['subtotal' => 525.0, 'total' => 525.0], [
            Attr::META_ITEM_DISCOUNT_TOTAL => '15',
            Attr::META_ITEM_DISCOUNTS => [[
                'name' => 'Referral Credits Discount', 'type' => 'referral_points', 'amount' => 15.0,
                'source' => 'order_fee', 'allocation_method' => 'proportional',
            ]],
        ]))->addFee(new FakeFee('Referral Credits Discount', -15.0));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['104-1041'];

        $this->assertSame(510.0, self::money($row['final_price']),
            '#113 guard: expected final 510.00 (525 - 15 points fee); report final ' . $row['final_price']);
    }

    // ---------------------------------------------------------------- S02 (HTML discount amount read directly)

    /**
     * S02 (direct read): line without attribution item meta, legacy 'Discount Amount' stored as HTML entities.
     */
    public function test_S02_legacy_html_discount_amount_read_by_report(): void {
        $this->markTestIncomplete('Pending fix D: legacy HTML discount amount on Booking Report.');
        $order = $this->attributedOrder(105)->addLine(new FakeLineItem(1051, ['subtotal' => 420.0, 'total' => 420.0], [
            'Discount' => 'Sibling Discount',
            'Discount Amount' => '&#67;&#72;&#70;105.00',
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['105-1051'];

        $this->assertSame(105.0, self::money($row['discount_amount']),
            'S02: expected discount 105.00; report discount ' . $row['discount_amount']);
    }

    // ---------------------------------------------------------------- S03 (end date excludes its own day)

    public function test_S03_order_on_end_date_is_included(): void {
        $this->markTestIncomplete('Pending fix D: inclusive Booking Report end date.');
        ReportAccuracyStore::addOrder($this->attributedOrder(106, 'completed', '2026-10-12 09:38:00')
            ->addLine(new FakeLineItem(1061, ['subtotal' => 525.0, 'total' => 525.0])));

        $result = $this->report(self::FROM, self::TO);

        $this->assertSame(1, $result['totals']['bookings'],
            'S03: expected 1 booking for an order placed on the end date (2026-10-12 09:38); report bookings '
            . $result['totals']['bookings']);
    }

    // ---------------------------------------------------------------- S05b / S06b (refunds not in reimbursement)

    /**
     * S05b: order already attributed (attribution_at set) and refunded 65 later, but no _intersoccer_item_refund.
     * The report reads reimbursement only from that meta, and the lazy path skips attributed orders.
     */
    public function test_S05b_refund_without_item_refund_meta_shows_in_reimbursement(): void {
        $this->markTestIncomplete('Pending fix C: idempotent refund reimbursement.');
        $order = $this->attributedOrder(107)->addLine(new FakeLineItem(1071, ['subtotal' => 195.0, 'total' => 195.0]));
        $order->addRefund(new FakeRefund(1079, 65.0, [new FakeRefundLine(1071, -65.0)]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['107-1071'];

        $this->assertSame(65.0, self::money($row['reimbursement']),
            'S05b: expected reimbursement 65.00; report reimbursement ' . $row['reimbursement']);
    }

    /**
     * S06b: refunded-status order (fully refunded 500) with no item refund meta still nets to 500 in the Booking
     * Report (final 500, reimbursement 0). Expected net 0.
     */
    public function test_S06b_refunded_status_order_nets_to_zero(): void {
        $this->markTestIncomplete('Pending fix C: refunded-status order net revenue.');
        $order = $this->attributedOrder(108, 'refunded')->addLine(new FakeLineItem(1081, ['subtotal' => 500.0, 'total' => 500.0]));
        $order->addRefund(new FakeRefund(1089, 500.0, [new FakeRefundLine(1081, -500.0)]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['108-1081'];
        $net = self::money($row['final_price']) - self::money($row['reimbursement']);

        $this->assertSame(0.0, $net,
            'S06b: expected net 0.00 for a fully refunded order; report final ' . $row['final_price']
            . ' - reimbursement ' . $row['reimbursement'] . ' = ' . number_format($net, 2));
    }

    // ---------------------------------------------------------------- S06a / S06c (documented behaviour, PASS)

    /** S06a (by design, PASSES): processing / on-hold lines are in Booking (Final Numbers excludes them). */
    public function test_S06a_processing_and_on_hold_are_in_booking_by_design(): void {
        ReportAccuracyStore::addOrder($this->attributedOrder(109, 'processing')->addLine(new FakeLineItem(1091, ['subtotal' => 150.0, 'total' => 150.0])));
        ReportAccuracyStore::addOrder($this->attributedOrder(110, 'on-hold')->addLine(new FakeLineItem(1101, ['subtotal' => 220.0, 'total' => 165.0])));

        $this->assertSame(2, $this->report()['totals']['bookings']);
    }

    /** S06c (guard, PASSES): cancelled orders are excluded. */
    public function test_S06c_cancelled_excluded(): void {
        ReportAccuracyStore::addOrder($this->attributedOrder(111, 'cancelled')->addLine(new FakeLineItem(1111, ['subtotal' => 480.0, 'total' => 408.0])));

        $this->assertSame(0, $this->report()['totals']['bookings']);
    }

    // ---------------------------------------------------------------- S11 (qty > 1)

    public function test_S11_multi_place_line_counts_each_place(): void {
        $this->markTestIncomplete('Pending fix E: qty>1 booking and roster places.');
        ReportAccuracyStore::addOrder($this->attributedOrder(112)->addLine(
            new FakeLineItem(1121, ['subtotal' => 480.0, 'total' => 480.0, 'quantity' => 2])
        ));

        $result = $this->report();

        $this->assertSame(2, $result['totals']['bookings'],
            'S11: expected 2 bookings for a qty-2 line; report bookings ' . $result['totals']['bookings']);
    }

    // ---------------------------------------------------------------- S15a (localized label)

    /**
     * S15a: two lines for the same event, one bought on the FR translation. The label is the order_item_name,
     * so the same event shows under two names. Expected both under the default-language (EN) name.
     */
    public function test_S15a_translated_line_labelled_with_canonical_product_name(): void {
        $this->markTestIncomplete('Pending fix E: WPML-canonical Booking Report labels.');
        ReportAccuracyStore::addOrder($this->attributedOrder(113)->addLine(
            new FakeLineItem(1131, ['subtotal' => 250.0, 'total' => 250.0, 'product_id' => 2000, 'name' => 'Venue A Autumn Camps 2026'])
        ));
        ReportAccuracyStore::addOrder($this->attributedOrder(114)->addLine(
            new FakeLineItem(1141, ['subtotal' => 250.0, 'total' => 250.0, 'product_id' => 2001, 'name' => "Camps d'automne Venue A 2026"])
        ));
        // 2001 is the FR translation of 2000 (WPML).
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) {
            if ($hook === 'wpml_object_id' && (int) $value === 2001) {
                return 2000;
            }
            return $value;
        };

        try {
            $rows = $this->report()['by_ref'];
        } finally {
            unset($GLOBALS['intersoccer_test_apply_filters_callback']);
        }

        $this->assertSame('Venue A Autumn Camps 2026', $rows['114-1141']['class_name'],
            'S15a: expected canonical label "Venue A Autumn Camps 2026"; report label "' . $rows['114-1141']['class_name'] . '"');
    }

    // ---------------------------------------------------------------- S16b (BuyClub definition mismatch)

    /**
     * S16b: subtotal > 0, total 0, no "BuyClub" billing company. Final Numbers classifies it as BuyClub
     * (reports-data.php:766-770: subtotal > 0 && total == 0). The Booking Report only skips billing company
     * "buyclub" and keeps it as a CHF 0.00 booking. Expected: the same rule in both reports (excluded here).
     */
    public function test_S16b_zero_total_line_follows_final_numbers_buyclub_rule(): void {
        $this->markTestIncomplete('Pending fix E: shared BuyClub rule with Final Numbers.');
        ReportAccuracyStore::addOrder($this->attributedOrder(115)->addLine(new FakeLineItem(1151, ['subtotal' => 495.0, 'total' => 0.0])));
        ReportAccuracyStore::addOrder($this->attributedOrder(116)->addLine(new FakeLineItem(1161, ['subtotal' => 495.0, 'total' => 0.0])));
        \InterSoccerRRTestMeta::$data[116]['_billing_company'] = 'BuyClub';

        $result = $this->report();

        $this->assertArrayHasKey('115-1151', $result['by_ref'], 'precondition: no-company zero-total line is in Booking');
        $this->assertArrayNotHasKey('116-1161', $result['by_ref'], 'precondition: company=BuyClub line excluded');
        $this->assertSame(0, $result['totals']['bookings'],
            'S16b: expected 0 Booking rows (Final Numbers counts both lines as BuyClub); report bookings '
            . $result['totals']['bookings'] . ' (no-company line kept at CHF ' . $result['by_ref']['115-1151']['final_price'] . ')');
    }

    // ---------------------------------------------------------------- S19 (report writes on view)

    /**
     * S19: viewing the report must be read-only. An order without attribution_at gets discount/attribution meta
     * written on the first view, and the second view shows different figures.
     */
    public function test_S19_viewing_report_writes_nothing(): void {
        ReportAccuracyStore::addOrder((new FakeOrder(117))
            ->addLine(new FakeLineItem(1171, ['subtotal' => 200.0, 'total' => 200.0]))
            ->addFee(new FakeFee('Referral Credits Discount', -20.0)));

        $this->report();
        $writes = ReportAccuracyStore::writtenKeys();

        $this->assertSame([], $writes, 'S19: expected 0 writes on view; report wrote ' . count(ReportAccuracyStore::$writes)
            . ' times: ' . implode(', ', $writes));
    }

    /**
     * S19 parity: unattributed line with subtotal/total gap uses subtotal-minus-total fallback on first view
     * (same figures as after persisted attribution would delete empty breakdown meta).
     */
    public function test_S19_unattributed_line_uses_subtotal_minus_total_fallback_on_view(): void {
        ReportAccuracyStore::addOrder((new FakeOrder(118))
            ->addLine(new FakeLineItem(1181, ['subtotal' => 300.0, 'total' => 250.0])));

        $row = $this->report()['by_ref']['118-1181'];

        $this->assertSame(50.0, self::money($row['discount_amount']),
            'S19 parity: expected discount 50.00 from subtotal-total fallback; report discount ' . $row['discount_amount']);
        $this->assertSame(250.0, self::money($row['final_price']),
            'S19 parity: expected final 250.00; report final ' . $row['final_price']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    /**
     * S19 parity: refund-only in-memory attribution still uses subtotal-minus-total for discount on first view.
     */
    public function test_S19_unattributed_line_with_refund_uses_discount_fallback_and_in_memory_reimbursement(): void {
        $order = (new FakeOrder(119))
            ->addLine(new FakeLineItem(1191, ['subtotal' => 300.0, 'total' => 250.0]));
        $order->addRefund(new FakeRefund(1199, 50.0, [new FakeRefundLine(1191, -50.0)]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['119-1191'];

        $this->assertSame(50.0, self::money($row['discount_amount']),
            'S19 parity: expected discount 50.00 from subtotal-total fallback; report discount ' . $row['discount_amount']);
        $this->assertSame(250.0, self::money($row['final_price']),
            'S19 parity: expected final 250.00; report final ' . $row['final_price']);
        $this->assertSame(50.0, self::money($row['reimbursement']),
            'S19 parity: expected reimbursement 50.00; report reimbursement ' . $row['reimbursement']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    // ---------------------------------------------------------------- S09 (persisted stale FR/DE double count on Booking Report)

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function staleLocalizedFeePlusReferralMetaBreakdown(
        string $fee_label,
        string $meta_label,
        string $meta_type,
        float $amount
    ): array {
        return [
            [
                'name' => $fee_label,
                'type' => 'other',
                'amount' => $amount,
                'source' => 'order_fee',
                'allocation_method' => 'proportional',
            ],
            [
                'name' => $meta_label,
                'type' => $meta_type,
                'amount' => $amount,
                'source' => 'referral_order_meta',
                'allocation_method' => 'proportional',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function staleFeeOnlyOtherBreakdown(string $fee_label, float $amount): array {
        return [[
            'name' => $fee_label,
            'type' => 'other',
            'amount' => $amount,
            'source' => 'order_fee',
            'allocation_method' => 'proportional',
        ]];
    }

    /** S09a: FR points fee-only persisted as other — reclassify to referral_points at read, amount unchanged. */
    public function test_S09a_booking_report_fr_fee_only_other_reclassified_to_points(): void {
        $amount = 34.0;
        $order = $this->attributedOrder(125)->addLine(new FakeLineItem(1251, ['subtotal' => 405.0, 'total' => 371.0], [
            Attr::META_ITEM_DISCOUNT_TOTAL => (string) $amount,
            Attr::META_ITEM_DISCOUNTS => self::staleFeeOnlyOtherBreakdown('Réduction de points', $amount),
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['125-1251'];

        $this->assertSame($amount, self::money($row['discount_amount']));
        $this->assertSame(371.0, self::money($row['final_price']));
        $this->assertSame('referral_points', $row['discount_type']);
        $this->assertSame('referral_points', $row['discount_breakdown'][0]['type']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    /** S09a: DE points fee-only persisted as other — reclassify to referral_points at read. */
    public function test_S09a_booking_report_de_fee_only_other_reclassified_to_points(): void {
        $amount = 34.0;
        $order = $this->attributedOrder(126)->addLine(new FakeLineItem(1261, ['subtotal' => 405.0, 'total' => 371.0], [
            Attr::META_ITEM_DISCOUNT_TOTAL => (string) $amount,
            Attr::META_ITEM_DISCOUNTS => self::staleFeeOnlyOtherBreakdown('Punkte-Rabatt', $amount),
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['126-1261'];

        $this->assertSame($amount, self::money($row['discount_amount']));
        $this->assertSame('referral_points', $row['discount_type']);
        $this->assertNotSame('referral_first_order', $row['discount_type']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    /** S09a: FR first-order referral fee-only as other — referral_first_order, not points. */
    public function test_S09a_booking_report_fee_only_referral_reclassified_not_points(): void {
        $amount = 31.0;
        $order = $this->attributedOrder(127)->addLine(new FakeLineItem(1271, ['subtotal' => 310.0, 'total' => 279.0], [
            Attr::META_ITEM_DISCOUNT_TOTAL => (string) $amount,
            Attr::META_ITEM_DISCOUNTS => self::staleFeeOnlyOtherBreakdown('Réduction de parrainage', $amount),
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['127-1271'];

        $this->assertSame($amount, self::money($row['discount_amount']));
        $this->assertSame('referral_first_order', $row['discount_type']);
        $this->assertNotSame('referral_points', $row['discount_type']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    /** S09: FR points fee persisted as "other" plus duplicate referral_order_meta — count once on read. */
    public function test_S09_booking_report_fr_stale_persisted_points_counted_once(): void {
        $amount = 34.0;
        $order = $this->attributedOrder(120)->addLine(new FakeLineItem(1201, ['subtotal' => 405.0, 'total' => 371.0], [
            Attr::META_ITEM_DISCOUNT_TOTAL => (string) ($amount * 2),
            Attr::META_ITEM_DISCOUNTS => self::staleLocalizedFeePlusReferralMetaBreakdown(
                'Réduction de crédits de parrainage',
                'Referral Credits Discount',
                'referral_points',
                $amount
            ),
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['120-1201'];

        $this->assertSame($amount, self::money($row['discount_amount']));
        $this->assertSame(371.0, self::money($row['final_price']));
        $this->assertSame('referral_points', $row['discount_type']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    /** S09: DE points fee with stale persisted double breakdown. */
    public function test_S09_booking_report_de_stale_persisted_points_counted_once(): void {
        $amount = 34.0;
        $order = $this->attributedOrder(121)->addLine(new FakeLineItem(1211, ['subtotal' => 405.0, 'total' => 371.0], [
            Attr::META_ITEM_DISCOUNT_TOTAL => (string) ($amount * 2),
            Attr::META_ITEM_DISCOUNTS => self::staleLocalizedFeePlusReferralMetaBreakdown(
                'Empfehlungscredits-Rabatt',
                'Referral Credits Discount',
                'referral_points',
                $amount
            ),
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['121-1211'];

        $this->assertSame($amount, self::money($row['discount_amount']));
        $this->assertSame(371.0, self::money($row['final_price']));
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    /**
     * S09: FR/DE first-order referral fee with stale persisted duplicate order-meta slice.
     *
     * @dataProvider staleFirstOrderReferralFeeLabelProvider
     */
    public function test_S09_booking_report_stale_persisted_first_order_referral_counted_once(string $fee_label): void {
        $amount = 31.0;
        $order = $this->attributedOrder(122)->addLine(new FakeLineItem(1221, ['subtotal' => 310.0, 'total' => 279.0], [
            Attr::META_ITEM_DISCOUNT_TOTAL => (string) ($amount * 2),
            Attr::META_ITEM_DISCOUNTS => self::staleLocalizedFeePlusReferralMetaBreakdown(
                $fee_label,
                'Referral Discount',
                'referral_first_order',
                $amount
            ),
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['122-1221'];

        $this->assertSame($amount, self::money($row['discount_amount']));
        $this->assertSame(279.0, self::money($row['final_price']));
        $this->assertSame('referral_first_order', $row['discount_type']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    /** @return array<string,array{0:string}> */
    public static function staleFirstOrderReferralFeeLabelProvider(): array {
        return [
            'FR' => ['Réduction de parrainage'],
            'DE' => ['Empfehlungsrabatt'],
        ];
    }

    /** S09 control: EN fee already classified — persisted breakdown unchanged by reconciliation. */
    public function test_S09_booking_report_en_persisted_points_unchanged(): void {
        $amount = 34.0;
        $breakdown = [[
            'name' => 'Referral Credits Discount',
            'type' => 'referral_points',
            'amount' => $amount,
            'source' => 'order_fee',
            'allocation_method' => 'proportional',
        ]];
        $order = $this->attributedOrder(123)->addLine(new FakeLineItem(1231, ['subtotal' => 525.0, 'total' => 491.0], [
            Attr::META_ITEM_DISCOUNT_TOTAL => (string) $amount,
            Attr::META_ITEM_DISCOUNTS => $breakdown,
        ]));
        ReportAccuracyStore::addOrder($order);

        $row = $this->report()['by_ref']['123-1231'];

        $this->assertSame('34.00', $row['discount_amount']);
        $this->assertSame('491.00', $row['final_price']);
        $this->assertSame('referral_points', $row['discount_type']);
        $this->assertSame($breakdown, $row['discount_breakdown']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }

    /** S09: correcting stale persisted meta on view must not write item or order meta. */
    public function test_S09_booking_report_stale_persisted_reconciliation_writes_nothing(): void {
        $amount = 34.0;
        $order = $this->attributedOrder(124)->addLine(new FakeLineItem(1241, ['subtotal' => 405.0, 'total' => 371.0], [
            Attr::META_ITEM_DISCOUNT_TOTAL => (string) ($amount * 2),
            Attr::META_ITEM_DISCOUNTS => self::staleLocalizedFeePlusReferralMetaBreakdown(
                'Réduction de crédits de parrainage',
                'Referral Credits Discount',
                'referral_points',
                $amount
            ),
        ]));
        ReportAccuracyStore::addOrder($order);

        $this->report();

        $this->assertSame([], ReportAccuracyStore::writtenKeys());
        $this->assertSame((string) ($amount * 2), ReportAccuracyStore::getItemMeta(1241, Attr::META_ITEM_DISCOUNT_TOTAL));
    }
}
