<?php

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/includes/financial-attribution-helpers.php';

if (!function_exists('wc_update_order_item_meta')) {
    function wc_update_order_item_meta($item_id, $key, $value) {
        $GLOBALS['intersoccer_test_item_meta'][(int) $item_id][$key] = $value;
        return true;
    }
}

if (!function_exists('wc_get_order_item_meta')) {
    function wc_get_order_item_meta($item_id, $key, $single = true) {
        return $GLOBALS['intersoccer_test_item_meta'][(int) $item_id][$key] ?? '';
    }
}

if (!function_exists('wc_delete_order_item_meta')) {
    function wc_delete_order_item_meta($item_id, $key) {
        unset($GLOBALS['intersoccer_test_item_meta'][(int) $item_id][$key]);
        return true;
    }
}

class OrderFinancialAttributionServiceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['intersoccer_test_item_meta'] = [];
    }

    public function test_referral_discount_is_allocated_proportionally(): void {
        $order = $this->createOrderWithItems([
            101 => 100.0,
            102 => 300.0,
        ], [
            ['name' => 'Referral Discount', 'total' => -40.0],
        ]);

        $service = new OrderFinancialAttributionService();
        $service->attributeOrderDiscounts($order, true);

        $this->assertEquals(10.0, (float) wc_get_order_item_meta(101, OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL));
        $this->assertEquals(30.0, (float) wc_get_order_item_meta(102, OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL));

        $breakdown = wc_get_order_item_meta(102, OrderFinancialAttributionService::META_ITEM_DISCOUNTS);
        $this->assertIsArray($breakdown);
        $this->assertSame('referral_first_order', $breakdown[0]['type']);
    }

    public function test_points_redemption_is_typed_as_referral_points(): void {
        $order = $this->createOrderWithItems([201 => 200.0], [
            ['name' => 'Referral Credits Discount', 'total' => -25.0],
        ]);

        $service = new OrderFinancialAttributionService();
        $service->attributeOrderDiscounts($order, true);

        $breakdown = wc_get_order_item_meta(201, OrderFinancialAttributionService::META_ITEM_DISCOUNTS);
        $this->assertSame('referral_points', $breakdown[0]['type']);
        $this->assertEquals(25.0, (float) wc_get_order_item_meta(201, OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL));
    }

    public function test_coupon_discount_is_allocated_per_line(): void {
        $order = $this->createOrderWithItems([
            301 => 50.0,
            302 => 50.0,
        ], [], ['SAVE10' => 20.0]);

        $service = new OrderFinancialAttributionService();
        $service->attributeOrderDiscounts($order, true);

        $this->assertEquals(10.0, (float) wc_get_order_item_meta(301, OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL));
        $this->assertEquals(10.0, (float) wc_get_order_item_meta(302, OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL));

        $breakdown = wc_get_order_item_meta(301, OrderFinancialAttributionService::META_ITEM_DISCOUNTS);
        $this->assertSame('coupon', $breakdown[0]['type']);
        $this->assertSame('SAVE10', $breakdown[0]['name']);
    }

    public function test_partial_refund_allocates_to_specific_line_item(): void {
        $order = $this->createOrderWithItems([401 => 100.0, 402 => 100.0]);
        $refund = $this->createRefundWithLineItems([
            ['refunded_item_id' => 401, 'total' => -40.0],
        ]);

        $service = new OrderFinancialAttributionService();
        $service->attributeOrderRefunds($order, $refund);

        $this->assertEquals(40.0, (float) wc_get_order_item_meta(401, OrderFinancialAttributionService::META_ITEM_REFUND));
        $this->assertSame('', wc_get_order_item_meta(402, OrderFinancialAttributionService::META_ITEM_REFUND));
    }

    public function test_order_level_refund_is_split_across_lines_not_multiplied(): void {
        $order = $this->createOrderWithItems([
            501 => 100.0,
            502 => 100.0,
            503 => 100.0,
        ]);
        $refund = $this->createRefundWithLineItems([], -90.0);

        $service = new OrderFinancialAttributionService();
        $service->attributeOrderRefunds($order, $refund);

        $total_refund = (float) wc_get_order_item_meta(501, OrderFinancialAttributionService::META_ITEM_REFUND)
            + (float) wc_get_order_item_meta(502, OrderFinancialAttributionService::META_ITEM_REFUND)
            + (float) wc_get_order_item_meta(503, OrderFinancialAttributionService::META_ITEM_REFUND);

        $this->assertEquals(90.0, $total_refund);
    }

    public function test_legacy_item_discount_merges_with_order_fee(): void {
        $order = $this->createOrderWithItems([601 => 200.0], [
            ['name' => '20% Sibling Camp Discount', 'total' => -40.0],
        ]);

        $GLOBALS['intersoccer_test_item_meta'][601]['Discount'] = '20% Sibling Discount';
        $GLOBALS['intersoccer_test_item_meta'][601]['Discount Amount'] = '20.00';

        $service = new OrderFinancialAttributionService();
        $service->attributeOrderDiscounts($order, true);

        $total_discount = (float) wc_get_order_item_meta(601, OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL);
        $this->assertEquals(60.0, $total_discount);

        $breakdown = wc_get_order_item_meta(601, OrderFinancialAttributionService::META_ITEM_DISCOUNTS);
        $this->assertCount(2, $breakdown);
    }

    public function test_classify_discount_fee_maps_referral_labels(): void {
        $this->assertSame('referral_first_order', intersoccer_classify_discount_fee('Referral Discount'));
        $this->assertSame('referral_points', intersoccer_classify_discount_fee('Referral Credits Discount'));
    }

    /**
     * @param array<int,float>                    $items
     * @param array<int,array{name:string,total:float}> $fees
     * @param array<string,float>                 $coupons
     */
    private function createOrderWithItems(array $items, array $fees = [], array $coupons = []) {
        $line_items = [];
        foreach ($items as $item_id => $subtotal) {
            $line_items[$item_id] = new TestOrderItemProduct($item_id, $subtotal);
        }

        $fee_items = [];
        foreach ($fees as $index => $fee) {
            $fee_items['fee_' . $index] = new TestOrderItemFee($fee['name'], $fee['total']);
        }

        return new TestWCOrder($line_items, $fee_items, $coupons);
    }

    /**
     * @param array<int,array{refunded_item_id:int,total:float}> $line_items
     */
    private function createRefundWithLineItems(array $line_items, float $order_total = 0.0) {
        $items = [];
        foreach ($line_items as $index => $line) {
            $items['refund_' . $index] = new TestRefundLineItem($line['refunded_item_id'], $line['total']);
        }

        return new WC_Order_Refund($items, $order_total);
    }
}

class TestOrderItemProduct {
    private $id;
    private $subtotal;

    public function __construct(int $id, float $subtotal) {
        $this->id = $id;
        $this->subtotal = $subtotal;
    }

    public function get_product_id() {
        return 1;
    }

    public function get_subtotal() {
        return $this->subtotal;
    }

    public function get_total() {
        return $this->subtotal;
    }

    public function get_meta($key, $single = true) {
        return wc_get_order_item_meta($this->id, $key, $single);
    }
}

class TestOrderItemCoupon {
    private $code;
    private $discount;

    public function __construct(string $code, float $discount) {
        $this->code = $code;
        $this->discount = $discount;
    }

    public function get_code() {
        return $this->code;
    }

    public function get_name() {
        return $this->code;
    }

    public function get_discount() {
        return $this->discount;
    }
}

class TestOrderItemFee {
    private $name;
    private $total;

    public function __construct(string $name, float $total) {
        $this->name = $name;
        $this->total = $total;
    }

    public function get_name() {
        return $this->name;
    }

    public function get_total() {
        return $this->total;
    }
}

class TestRefundLineItem {
    private $refunded_item_id;
    private $total;

    public function __construct(int $refunded_item_id, float $total) {
        $this->refunded_item_id = $refunded_item_id;
        $this->total = $total;
    }

    public function get_meta($key, $single = true) {
        if ($key === '_refunded_item_id') {
            return $this->refunded_item_id;
        }
        return '';
    }

    public function get_total() {
        return $this->total;
    }
}

class TestWCOrder {
    private $line_items;
    private $fee_items;
    private $coupons;
    private $meta = [];

    public function __construct(array $line_items, array $fee_items = [], array $coupons = []) {
        $this->line_items = $line_items;
        $this->fee_items = $fee_items;
        $this->coupons = $coupons;
    }

    public function get_id() {
        return 9001;
    }

    public function get_items($type = 'line_item') {
        if ($type === 'fee') {
            return $this->fee_items;
        }
        if ($type === 'coupon') {
            $coupon_items = [];
            foreach ($this->coupons as $code => $amount) {
                $coupon_items['coupon_' . $code] = new TestOrderItemCoupon((string) $code, (float) $amount);
            }
            return $coupon_items;
        }
        return $this->line_items;
    }

    public function get_coupon_codes() {
        return array_keys($this->coupons);
    }

    public function get_discount_amount($code) {
        return $this->coupons[$code] ?? 0.0;
    }

    public function get_meta($key, $single = true) {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data($key, $value) {
        $this->meta[$key] = $value;
    }

    public function save() {
        return true;
    }

    public function get_refunds() {
        return [];
    }

    public function get_total() {
        return 0.0;
    }
}

if (!class_exists('WC_Order_Refund')) {
    class WC_Order_Refund extends TestWCOrder {
        private $refund_total;

        public function __construct(array $line_items, float $refund_total = 0.0) {
            parent::__construct($line_items);
            $this->refund_total = $refund_total;
        }

        public function get_total() {
            return $this->refund_total;
        }
    }
}
