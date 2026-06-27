<?php
/**
 * Allocates order-level discounts and refunds to line items for financial reporting.
 *
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;

defined('ABSPATH') or die('Restricted access');

class OrderFinancialAttributionService {

    public const META_ITEM_DISCOUNTS = '_intersoccer_item_discounts';
    public const META_ITEM_DISCOUNT_TOTAL = '_intersoccer_total_item_discount';
    public const META_ITEM_REFUND = '_intersoccer_item_refund';
    public const META_ITEM_VERSION = '_intersoccer_financial_attribution_version';
    public const META_ORDER_ATTRIBUTION_AT = '_intersoccer_financial_attribution_at';
    public const META_ORDER_DISCOUNT_SUMMARY = '_intersoccer_order_discount_summary';

    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    /**
     * Attribute all discounts on an order to its product line items.
     *
     * @param \WC_Order|int $order
     * @param bool          $force Rewrite existing attributed discounts.
     */
    public function attributeOrderDiscounts($order, bool $force = false): bool {
        $order = $this->resolveOrder($order);
        if (!$order) {
            return false;
        }

        $line_items = $this->getProductLineItems($order);
        if (empty($line_items)) {
            return false;
        }

        if (!$force && $this->orderHasCurrentAttribution($order)) {
            return true;
        }

        $item_discounts = [];
        foreach ($line_items as $item_id => $item) {
            $item_discounts[$item_id] = $force ? [] : $this->readLegacyItemDiscounts($item);
        }

        foreach ($this->collectOrderDiscountSources($order) as $source) {
            $this->allocateAmountToItems(
                $item_discounts,
                $line_items,
                (float) $source['amount'],
                (string) $source['name'],
                (string) $source['type'],
                (string) $source['source']
            );
        }

        $summary = $this->persistItemDiscounts($order, $item_discounts);
        $order->update_meta_data(self::META_ORDER_DISCOUNT_SUMMARY, $summary);
        $order->update_meta_data(self::META_ORDER_ATTRIBUTION_AT, current_time('mysql'));
        $order->save();

        $this->logReconciliation($order, $line_items);

        return true;
    }

    /**
     * Attribute a refund to original product line items.
     *
     * @param \WC_Order|\WC_Order_Refund|int $order
     * @param \WC_Order_Refund|int             $refund
     */
    public function attributeOrderRefunds($order, $refund): bool {
        $order = $this->resolveOrder($order);
        $refund = $this->resolveOrder($refund);

        if (!$order || !$refund || !is_a($refund, 'WC_Order_Refund')) {
            return false;
        }

        $line_items = $this->getProductLineItems($order);
        if (empty($line_items)) {
            return false;
        }

        $allocations = [];
        $refund_items = $refund->get_items('line_item');

        if (!empty($refund_items)) {
            foreach ($refund_items as $refund_item) {
                if (!is_object($refund_item) || !method_exists($refund_item, 'get_meta')) {
                    continue;
                }

                $original_item_id = (int) $refund_item->get_meta('_refunded_item_id', true);
                if ($original_item_id <= 0 || !isset($line_items[$original_item_id])) {
                    continue;
                }

                $amount = abs((float) $refund_item->get_total());
                if ($amount <= 0) {
                    continue;
                }

                if (!isset($allocations[$original_item_id])) {
                    $allocations[$original_item_id] = 0.0;
                }
                $allocations[$original_item_id] += $amount;
            }
        }

        if (empty($allocations)) {
            $refund_total = abs((float) $refund->get_total());
            if ($refund_total > 0) {
                $allocations = $this->buildProportionalAllocations($line_items, $refund_total, 'line_total');
            }
        }

        foreach ($allocations as $item_id => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $existing = (float) wc_get_order_item_meta($item_id, self::META_ITEM_REFUND, true);
            wc_update_order_item_meta($item_id, self::META_ITEM_REFUND, round($existing + $amount, 2));
            wc_update_order_item_meta($item_id, self::META_ITEM_VERSION, intersoccer_financial_attribution_version());
        }

        $order->update_meta_data(self::META_ORDER_ATTRIBUTION_AT, current_time('mysql'));
        $order->save();

        return true;
    }

    /**
     * Re-attribute discounts and all refunds on an order (used by backfill).
     *
     * @param \WC_Order|int $order
     */
    public function reattributeFullOrder($order): bool {
        $order = $this->resolveOrder($order);
        if (!$order) {
            return false;
        }

        foreach ($this->getProductLineItems($order) as $item_id => $item) {
            wc_delete_order_item_meta($item_id, self::META_ITEM_REFUND);
        }

        $this->attributeOrderDiscounts($order, true);

        foreach ($order->get_refunds() as $refund) {
            $this->attributeOrderRefunds($order, $refund);
        }

        return true;
    }

    /**
     * Backfill orders missing attribution metadata.
     *
     * @return array{migrated_count:int,remaining_count:int,more_remaining:bool,errors:array<int,string>}
     */
    public function backfillBatch(string $start_date = '2024-01-01', int $batch_size = 100): array {
        global $wpdb;

        $batch_size = max(1, min(500, $batch_size));
        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
             AND p.post_date >= %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             ORDER BY p.post_date DESC
             LIMIT %d",
            self::META_ORDER_ATTRIBUTION_AT,
            $start_date . ' 00:00:00',
            $batch_size
        ));

        $migrated_count = 0;
        $errors = [];

        foreach ((array) $order_ids as $order_id) {
            try {
                if ($this->reattributeFullOrder((int) $order_id)) {
                    $migrated_count++;
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf('Order %d: %s', (int) $order_id, $e->getMessage());
                $this->logger->error('OrderFinancialAttributionService backfill error', [
                    'order_id' => (int) $order_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $remaining_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
             AND p.post_date >= %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')",
            self::META_ORDER_ATTRIBUTION_AT,
            $start_date . ' 00:00:00'
        ));

        return [
            'migrated_count' => $migrated_count,
            'remaining_count' => $remaining_count,
            'more_remaining' => $remaining_count > 0,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<int,\WC_Order_Item_Product>
     */
    private function getProductLineItems(\WC_Order $order): array {
        $items = [];

        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }

            if ((int) $item->get_product_id() <= 0) {
                continue;
            }

            $items[(int) $item_id] = $item;
        }

        return $items;
    }

    /**
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function readLegacyItemDiscounts($item): array {
        $discounts = [];
        $discount_name = (string) $item->get_meta('Discount', true);
        $discount_amount = intersoccer_parse_legacy_discount_amount($item->get_meta('Discount Amount', true));

        if ($discount_amount > 0.01) {
            $discounts[] = [
                'name' => $discount_name !== '' ? $discount_name : 'Line Item Discount',
                'type' => intersoccer_determine_discount_type_from_name($discount_name),
                'amount' => round($discount_amount, 2),
                'source' => 'legacy_item_meta',
            ];
        }

        return $discounts;
    }

    /**
     * @return array<int,array{name:string,type:string,amount:float,source:string}>
     */
    private function collectOrderDiscountSources(\WC_Order $order): array {
        $sources = [];
        $seen_amounts = [];

        foreach ($order->get_items('fee') as $fee) {
            if (!is_object($fee) || !method_exists($fee, 'get_total')) {
                continue;
            }

            $amount = abs((float) $fee->get_total());
            if ($amount <= 0.01) {
                continue;
            }

            $name = method_exists($fee, 'get_name') ? (string) $fee->get_name() : 'Order Fee Discount';
            $key = strtolower($name) . ':' . $amount;
            if (isset($seen_amounts[$key])) {
                continue;
            }
            $seen_amounts[$key] = true;

            $sources[] = [
                'name' => $name,
                'type' => intersoccer_classify_discount_fee($name),
                'amount' => $amount,
                'source' => 'order_fee',
            ];
        }

        foreach ($order->get_items('coupon') as $coupon_item) {
            if (!is_object($coupon_item)) {
                continue;
            }

            $amount = 0.0;
            if (method_exists($coupon_item, 'get_discount')) {
                $amount = abs((float) $coupon_item->get_discount());
            }

            if ($amount <= 0.01) {
                continue;
            }

            $coupon_code = method_exists($coupon_item, 'get_code')
                ? (string) $coupon_item->get_code()
                : (method_exists($coupon_item, 'get_name') ? (string) $coupon_item->get_name() : 'Coupon');

            $sources[] = [
                'name' => $coupon_code,
                'type' => 'coupon',
                'amount' => $amount,
                'source' => 'woocommerce_coupon',
            ];
        }

        $referral_discount = (float) $order->get_meta('_intersoccer_first_order_discount_amount', true);
        if ($referral_discount > 0.01 && !$this->sourcesContainType($sources, 'referral_first_order')) {
            $sources[] = [
                'name' => 'Referral Discount',
                'type' => 'referral_first_order',
                'amount' => $referral_discount,
                'source' => 'referral_order_meta',
            ];
        }

        $points_redeemed = (float) $order->get_meta('_intersoccer_points_redeemed', true);
        if ($points_redeemed > 0.01 && !$this->sourcesContainType($sources, 'referral_points')) {
            $sources[] = [
                'name' => 'Referral Credits Discount',
                'type' => 'referral_points',
                'amount' => $points_redeemed,
                'source' => 'referral_order_meta',
            ];
        }

        return $sources;
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $item_discounts
     * @param array<int,\WC_Order_Item_Product>        $line_items
     */
    private function allocateAmountToItems(
        array &$item_discounts,
        array $line_items,
        float $amount,
        string $name,
        string $type,
        string $source
    ): void {
        if ($amount <= 0.01) {
            return;
        }

        $weights = [];
        $total_weight = 0.0;

        foreach ($line_items as $item_id => $item) {
            $weight = max(0.0, (float) $item->get_subtotal());
            if ($weight <= 0.0) {
                $weight = max(0.0, (float) $item->get_total());
            }
            $weights[$item_id] = $weight;
            $total_weight += $weight;
        }

        if ($total_weight <= 0.0) {
            return;
        }

        $allocated_total = 0.0;
        $item_ids = array_keys($line_items);
        $last_item_id = (int) end($item_ids);

        foreach ($line_items as $item_id => $item) {
            $share = ($weights[$item_id] / $total_weight) * $amount;
            if ((int) $item_id === $last_item_id) {
                $share = $amount - $allocated_total;
            } else {
                $share = round($share, 2);
                $allocated_total += $share;
            }

            if ($share <= 0.0) {
                continue;
            }

            if (!isset($item_discounts[$item_id])) {
                $item_discounts[$item_id] = [];
            }

            $item_discounts[$item_id][] = [
                'name' => $name,
                'type' => $type,
                'amount' => round($share, 2),
                'source' => $source,
                'allocation_method' => 'proportional',
            ];
        }
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $item_discounts
     * @return array<string,float>
     */
    private function persistItemDiscounts(\WC_Order $order, array $item_discounts): array {
        $summary = [
            'sibling' => 0.0,
            'same_season' => 0.0,
            'coupon' => 0.0,
            'referral_first_order' => 0.0,
            'referral_points' => 0.0,
            'other' => 0.0,
        ];

        foreach ($item_discounts as $item_id => $discounts) {
            $normalized = [];
            $total = 0.0;

            foreach ((array) $discounts as $discount) {
                if (!is_array($discount) || empty($discount['amount'])) {
                    continue;
                }

                $amount = round((float) $discount['amount'], 2);
                if ($amount <= 0.0) {
                    continue;
                }

                $type = isset($discount['type']) ? (string) $discount['type'] : 'other';
                $normalized[] = [
                    'name' => isset($discount['name']) ? (string) $discount['name'] : 'Discount',
                    'type' => $type,
                    'amount' => $amount,
                    'source' => isset($discount['source']) ? (string) $discount['source'] : 'attribution',
                    'allocation_method' => isset($discount['allocation_method']) ? (string) $discount['allocation_method'] : 'direct',
                ];
                $total += $amount;

                $bucket = intersoccer_map_discount_type_to_report_bucket($type);
                if (!isset($summary[$bucket])) {
                    $summary[$bucket] = 0.0;
                }
                $summary[$bucket] += $amount;
            }

            $total = round($total, 2);

            if (!empty($normalized)) {
                wc_update_order_item_meta((int) $item_id, self::META_ITEM_DISCOUNTS, $normalized);
                wc_update_order_item_meta((int) $item_id, self::META_ITEM_DISCOUNT_TOTAL, $total);
            } else {
                wc_delete_order_item_meta((int) $item_id, self::META_ITEM_DISCOUNTS);
                wc_delete_order_item_meta((int) $item_id, self::META_ITEM_DISCOUNT_TOTAL);
            }

            wc_update_order_item_meta((int) $item_id, self::META_ITEM_VERSION, intersoccer_financial_attribution_version());
        }

        foreach ($summary as $bucket => $value) {
            $summary[$bucket] = round($value, 2);
        }

        return $summary;
    }

    /**
     * @param array<int,\WC_Order_Item_Product> $line_items
     * @param string                             $weight_field line_subtotal|line_total
     * @return array<int,float>
     */
    private function buildProportionalAllocations(array $line_items, float $amount, string $weight_field = 'line_subtotal'): array {
        $weights = [];
        $total_weight = 0.0;

        foreach ($line_items as $item_id => $item) {
            $weight = $weight_field === 'line_total'
                ? max(0.0, (float) $item->get_total())
                : max(0.0, (float) $item->get_subtotal());

            if ($weight <= 0.0) {
                $weight = max(0.0, (float) $item->get_total());
            }

            $weights[$item_id] = $weight;
            $total_weight += $weight;
        }

        if ($total_weight <= 0.0) {
            return [];
        }

        $allocations = [];
        $allocated_total = 0.0;
        $item_ids = array_keys($line_items);
        $last_item_id = (int) end($item_ids);

        foreach ($line_items as $item_id => $item) {
            $share = ($weights[$item_id] / $total_weight) * $amount;
            if ((int) $item_id === $last_item_id) {
                $share = $amount - $allocated_total;
            } else {
                $share = round($share, 2);
                $allocated_total += $share;
            }

            if ($share > 0.0) {
                $allocations[(int) $item_id] = round($share, 2);
            }
        }

        return $allocations;
    }

    private function orderHasCurrentAttribution(\WC_Order $order): bool {
        $attributed_at = $order->get_meta(self::META_ORDER_ATTRIBUTION_AT, true);
        return !empty($attributed_at);
    }

    /**
     * @param array<int,array{name:string,type:string,amount:float,source:string}> $sources
     */
    private function sourcesContainType(array $sources, string $type): bool {
        foreach ($sources as $source) {
            if (($source['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,\WC_Order_Item_Product> $line_items
     */
    private function logReconciliation(\WC_Order $order, array $line_items): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $attributed_net = 0.0;
        foreach ($line_items as $item_id => $item) {
            $base = (float) $item->get_subtotal();
            $discount = (float) wc_get_order_item_meta((int) $item_id, self::META_ITEM_DISCOUNT_TOTAL, true);
            $refund = (float) wc_get_order_item_meta((int) $item_id, self::META_ITEM_REFUND, true);
            $attributed_net += $base - $discount - $refund;
        }

        $order_total = (float) $order->get_total();
        $delta = abs($attributed_net - $order_total);
        if ($delta > 0.05) {
            $this->logger->debug('OrderFinancialAttributionService reconciliation delta', [
                'order_id' => $order->get_id(),
                'attributed_net' => round($attributed_net, 2),
                'order_total' => round($order_total, 2),
                'delta' => round($delta, 2),
            ]);
        }
    }

    /**
     * @param \WC_Order|\WC_Order_Refund|int|null $order
     * @return \WC_Order|\WC_Order_Refund|null
     */
    private function resolveOrder($order) {
        if (is_numeric($order)) {
            return function_exists('wc_get_order') ? wc_get_order((int) $order) : null;
        }

        return is_object($order) ? $order : null;
    }
}
