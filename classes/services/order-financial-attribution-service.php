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
    public const META_ATTRIBUTION_SKIPPED = '_intersoccer_attribution_skipped';

    public const SKIP_REASON_NO_LINE_ITEMS = 'no_line_items';
    public const SKIP_REASON_NO_ATTRIBUTABLE_ITEMS = 'no_attributable_items';

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
            $item_discounts[$item_id] = $this->readLegacyItemDiscounts($item);
        }

        $sources = $this->collectOrderDiscountSources($order);
        $sources = $this->filterOrderDiscountSourcesAgainstLegacyBuckets($order, $item_discounts, $sources);

        foreach ($sources as $source) {
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
        $this->clearAttributionSkippedMarker($order);
        $order->save_meta_data();

        $this->logReconciliation($order, $line_items);

        return true;
    }

    /**
     * Compute per-line discount attribution without persisting (read-only report path).
     *
     * @param \WC_Order|int $order
     * @return array<int,array{discount_total:float,breakdown:array<int,array<string,mixed>>}>
     */
    public function computeItemDiscountsForOrder($order): array {
        $order = $this->resolveOrder($order);
        if (!$order) {
            return [];
        }

        $line_items = $this->getProductLineItems($order);
        if (empty($line_items)) {
            return [];
        }

        $item_discounts = [];
        foreach ($line_items as $item_id => $item) {
            $item_discounts[$item_id] = $this->readLegacyItemDiscounts($item);
        }

        $sources = $this->collectOrderDiscountSources($order);
        $sources = $this->filterOrderDiscountSourcesAgainstLegacyBuckets($order, $item_discounts, $sources);

        foreach ($sources as $source) {
            $this->allocateAmountToItems(
                $item_discounts,
                $line_items,
                (float) $source['amount'],
                (string) $source['name'],
                (string) $source['type'],
                (string) $source['source']
            );
        }

        return $this->normalizeItemDiscounts($item_discounts);
    }

    /**
     * Compute per-line refund attribution without persisting (read-only report path).
     *
     * @param \WC_Order|int $order
     * @return array<int,float>
     */
    public function computeItemRefundsForOrder($order): array {
        $order = $this->resolveOrder($order);
        if (!$order) {
            return [];
        }

        $line_items = $this->getProductLineItems($order);
        if (empty($line_items)) {
            return [];
        }

        $allocations = [];

        foreach ($order->get_refunds() as $refund) {
            if (!is_object($refund)) {
                continue;
            }

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
                continue;
            }

            $refund_total = abs((float) $refund->get_total());
            if ($refund_total <= 0) {
                continue;
            }

            foreach ($this->buildProportionalAllocations($line_items, $refund_total, 'line_total') as $item_id => $amount) {
                if (!isset($allocations[$item_id])) {
                    $allocations[$item_id] = 0.0;
                }
                $allocations[$item_id] += $amount;
            }
        }

        foreach ($allocations as $item_id => $amount) {
            $allocations[$item_id] = round($amount, 2);
        }

        return $allocations;
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
        $this->clearAttributionSkippedMarker($order);
        $order->save_meta_data();

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

        if ($this->getAttributionSkipReason($order) !== null) {
            return false;
        }

        foreach ($this->getProductLineItems($order) as $item_id => $item) {
            wc_delete_order_item_meta($item_id, self::META_ITEM_REFUND);
        }

        if (!$this->attributeOrderDiscounts($order, true)) {
            return false;
        }

        foreach ($order->get_refunds() as $refund) {
            $this->attributeOrderRefunds($order, $refund);
        }

        return true;
    }

    /**
     * Orders in a date range that lack financial attribution metadata.
     *
     * @return array<int,int>
     */
    public function findMissingAttributionOrderIds(string $start_date, string $end_date): array {
        global $wpdb;

        $start = $start_date . (strpos($start_date, ' ') !== false ? '' : ' 00:00:00');
        $end = $end_date . (strpos($end_date, ' ') !== false ? '' : ' 23:59:59');

        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} pm_skip ON p.ID = pm_skip.post_id AND pm_skip.meta_key = %s
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
             AND p.post_date >= %s
             AND p.post_date <= %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             AND (pm_skip.meta_id IS NULL OR pm_skip.meta_value IS NULL OR pm_skip.meta_value = '')
             ORDER BY p.post_date ASC",
            self::META_ORDER_ATTRIBUTION_AT,
            self::META_ATTRIBUTION_SKIPPED,
            $start,
            $end
        ));

        return array_map('intval', (array) $order_ids);
    }

    /**
     * Orders in a date range marked skipped but still lacking attribution metadata.
     *
     * @return array<int,int>
     */
    public function findSkippedAttributionOrderIds(string $start_date, string $end_date): array {
        global $wpdb;

        $start = $start_date . (strpos($start_date, ' ') !== false ? '' : ' 00:00:00');
        $end = $end_date . (strpos($end_date, ' ') !== false ? '' : ' 23:59:59');

        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_skip ON p.ID = pm_skip.post_id AND pm_skip.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
             AND p.post_date >= %s
             AND p.post_date <= %s
             AND pm_skip.meta_value IS NOT NULL
             AND pm_skip.meta_value != ''
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             ORDER BY p.post_date ASC",
            self::META_ATTRIBUTION_SKIPPED,
            self::META_ORDER_ATTRIBUTION_AT,
            $start,
            $end
        ));

        return array_map('intval', (array) $order_ids);
    }

    /**
     * @return string|null self::SKIP_REASON_* when the order cannot be attributed.
     */
    public function getAttributionSkipReason(\WC_Order $order): ?string {
        $line_items = $order->get_items('line_item');
        if (empty($line_items)) {
            return self::SKIP_REASON_NO_LINE_ITEMS;
        }

        if (empty($this->getProductLineItems($order))) {
            return self::SKIP_REASON_NO_ATTRIBUTABLE_ITEMS;
        }

        return null;
    }

    /**
     * Aggregate counts and CHF totals for orders missing attribution (no PII).
     *
     * @return array{
     *   order_count:int,
     *   applied_count:int,
     *   skipped_count:int,
     *   previously_skipped_count:int,
     *   failed_count:int,
     *   gross_chf:float,
     *   net_chf:float,
     *   skipped_reasons:array<string,int>
     * }
     */
    public function summarizeMissingAttributionInDateRange(string $start_date, string $end_date): array {
        $order_ids = $this->findMissingAttributionOrderIds($start_date, $end_date);
        $split = $this->splitPendingOrdersByAttributability($order_ids);

        $gross = 0.0;
        $net = 0.0;
        foreach ($split['applied_ids'] as $order_id) {
            if (!function_exists('wc_get_order')) {
                break;
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            $gross += (float) $order->get_subtotal();
            $net += (float) $order->get_total();
        }

        return [
            'order_count' => count($order_ids),
            'applied_count' => count($split['applied_ids']),
            'skipped_count' => count($split['skipped']),
            'previously_skipped_count' => $this->countPreviouslySkippedAttributionInDateRange($start_date, $end_date),
            'failed_count' => 0,
            'gross_chf' => round($gross, 2),
            'net_chf' => round($net, 2),
            'skipped_reasons' => $split['skipped_reason_counts'],
        ];
    }

    /**
     * Apply attribution to orders missing metadata in a date range (idempotent).
     *
     * @return array{
     *   applied_count:int,
     *   skipped_count:int,
     *   failed_count:int,
     *   skipped_reasons:array<string,int>,
     *   processed_count:int,
     *   error_count:int
     * }
     */
    public function applyMissingAttributionInDateRange(string $start_date, string $end_date): array {
        $applied_count = 0;
        $skipped_count = 0;
        $failed_count = 0;
        $skipped_reasons = [];

        foreach ($this->findMissingAttributionOrderIds($start_date, $end_date) as $order_id) {
            try {
                $outcome = $this->applyAttributionBackfillToOrderId((int) $order_id);
                if ($outcome['status'] === 'applied') {
                    $applied_count++;
                } elseif ($outcome['status'] === 'skipped') {
                    $skipped_count++;
                    $reason = (string) $outcome['reason'];
                    $skipped_reasons[$reason] = ($skipped_reasons[$reason] ?? 0) + 1;
                } else {
                    $failed_count++;
                }
            } catch (\Throwable $e) {
                $failed_count++;
                $this->logger->error('OrderFinancialAttributionService apply backfill error', [
                    'order_id' => $order_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'applied_count' => $applied_count,
            'skipped_count' => $skipped_count,
            'failed_count' => $failed_count,
            'skipped_reasons' => $skipped_reasons,
            'processed_count' => $applied_count,
            'error_count' => $failed_count,
        ];
    }

    /**
     * Dry-run summary for orders previously marked skipped (re-evaluates live skip reasons).
     *
     * @return array{
     *   order_count:int,
     *   applied_count:int,
     *   skipped_count:int,
     *   previously_skipped_count:int,
     *   failed_count:int,
     *   gross_chf:float,
     *   net_chf:float,
     *   skipped_reasons:array<string,int>,
     *   retry_skipped_orders:array<int,array{order_id:int,stored_reason:string,live_reason:?string}>,
     *   stored_reason_totals:array<string,int>,
     *   live_reason_totals:array<string,int>
     * }
     */
    public function summarizeRetrySkippedInDateRange(string $start_date, string $end_date): array {
        $order_ids = $this->findSkippedAttributionOrderIds($start_date, $end_date);
        $split = $this->splitPendingOrdersByAttributability($order_ids);

        $gross = 0.0;
        $net = 0.0;
        foreach ($split['applied_ids'] as $order_id) {
            if (!function_exists('wc_get_order')) {
                break;
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            $gross += (float) $order->get_subtotal();
            $net += (float) $order->get_total();
        }

        $retry_skipped_orders = [];
        $stored_reason_totals = [];
        $live_reason_totals = [];

        foreach ($order_ids as $order_id) {
            if (!function_exists('wc_get_order')) {
                break;
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            $stored_reason = (string) $order->get_meta(self::META_ATTRIBUTION_SKIPPED, true);
            $live_reason = $this->getAttributionSkipReason($order);

            $retry_skipped_orders[] = [
                'order_id' => (int) $order_id,
                'stored_reason' => $stored_reason,
                'live_reason' => $live_reason,
            ];

            if ($stored_reason !== '') {
                $stored_reason_totals[$stored_reason] = ($stored_reason_totals[$stored_reason] ?? 0) + 1;
            }

            $live_key = $live_reason ?? 'eligible';
            $live_reason_totals[$live_key] = ($live_reason_totals[$live_key] ?? 0) + 1;
        }

        return [
            'order_count' => count($order_ids),
            'applied_count' => count($split['applied_ids']),
            'skipped_count' => count($split['skipped']),
            'previously_skipped_count' => count($order_ids),
            'failed_count' => 0,
            'gross_chf' => round($gross, 2),
            'net_chf' => round($net, 2),
            'skipped_reasons' => $split['skipped_reason_counts'],
            'retry_skipped_orders' => $retry_skipped_orders,
            'stored_reason_totals' => $stored_reason_totals,
            'live_reason_totals' => $live_reason_totals,
        ];
    }

    /**
     * Re-evaluate skipped orders: clear marker and attribute when now eligible.
     *
     * @return array{
     *   applied_count:int,
     *   skipped_count:int,
     *   failed_count:int,
     *   skipped_reasons:array<string,int>,
     *   processed_count:int,
     *   error_count:int
     * }
     */
    public function applyRetrySkippedInDateRange(string $start_date, string $end_date): array {
        $applied_count = 0;
        $skipped_count = 0;
        $failed_count = 0;
        $skipped_reasons = [];

        foreach ($this->findSkippedAttributionOrderIds($start_date, $end_date) as $order_id) {
            try {
                $outcome = $this->applyRetrySkippedToOrderId((int) $order_id);
                if ($outcome['status'] === 'applied') {
                    $applied_count++;
                } elseif ($outcome['status'] === 'skipped') {
                    $skipped_count++;
                    $reason = (string) $outcome['reason'];
                    $skipped_reasons[$reason] = ($skipped_reasons[$reason] ?? 0) + 1;
                } else {
                    $failed_count++;
                }
            } catch (\Throwable $e) {
                $failed_count++;
                $this->logger->error('OrderFinancialAttributionService retry-skipped error', [
                    'order_id' => $order_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'applied_count' => $applied_count,
            'skipped_count' => $skipped_count,
            'failed_count' => $failed_count,
            'skipped_reasons' => $skipped_reasons,
            'processed_count' => $applied_count,
            'error_count' => $failed_count,
        ];
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
             LEFT JOIN {$wpdb->postmeta} pm_skip ON p.ID = pm_skip.post_id AND pm_skip.meta_key = %s
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
             AND p.post_date >= %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             AND (pm_skip.meta_id IS NULL OR pm_skip.meta_value IS NULL OR pm_skip.meta_value = '')
             ORDER BY p.post_date DESC
             LIMIT %d",
            self::META_ORDER_ATTRIBUTION_AT,
            self::META_ATTRIBUTION_SKIPPED,
            $start_date . ' 00:00:00',
            $batch_size
        ));

        $applied_count = 0;
        $skipped_count = 0;
        $failed_count = 0;
        $skipped_reasons = [];
        $errors = [];

        foreach ((array) $order_ids as $order_id) {
            try {
                $outcome = $this->applyAttributionBackfillToOrderId((int) $order_id);
                if ($outcome['status'] === 'applied') {
                    $applied_count++;
                } elseif ($outcome['status'] === 'skipped') {
                    $skipped_count++;
                    $reason = (string) $outcome['reason'];
                    $skipped_reasons[$reason] = ($skipped_reasons[$reason] ?? 0) + 1;
                } else {
                    $failed_count++;
                    $errors[] = sprintf('Order %d: attribution failed', (int) $order_id);
                }
            } catch (\Throwable $e) {
                $failed_count++;
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
             LEFT JOIN {$wpdb->postmeta} pm_skip ON p.ID = pm_skip.post_id AND pm_skip.meta_key = %s
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
             AND p.post_date >= %s
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             AND (pm_skip.meta_id IS NULL OR pm_skip.meta_value IS NULL OR pm_skip.meta_value = '')",
            self::META_ORDER_ATTRIBUTION_AT,
            self::META_ATTRIBUTION_SKIPPED,
            $start_date . ' 00:00:00'
        ));

        return [
            'migrated_count' => $applied_count,
            'applied_count' => $applied_count,
            'skipped_count' => $skipped_count,
            'failed_count' => $failed_count,
            'skipped_reasons' => $skipped_reasons,
            'remaining_count' => $remaining_count,
            'more_remaining' => $remaining_count > 0,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<int,int> $order_ids
     * @return array{
     *   applied_ids:array<int,int>,
     *   skipped:array<int,string>,
     *   skipped_reason_counts:array<string,int>
     * }
     */
    private function splitPendingOrdersByAttributability(array $order_ids): array {
        $applied_ids = [];
        $skipped = [];
        $skipped_reason_counts = [];

        foreach ($order_ids as $order_id) {
            if (!function_exists('wc_get_order')) {
                break;
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            $reason = $this->getAttributionSkipReason($order);
            if ($reason !== null) {
                $skipped[(int) $order_id] = $reason;
                $skipped_reason_counts[$reason] = ($skipped_reason_counts[$reason] ?? 0) + 1;
            } else {
                $applied_ids[] = (int) $order_id;
            }
        }

        return [
            'applied_ids' => $applied_ids,
            'skipped' => $skipped,
            'skipped_reason_counts' => $skipped_reason_counts,
        ];
    }

    /**
     * @return array{status:string,reason?:string}
     */
    private function applyAttributionBackfillToOrderId(int $order_id): array {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            return ['status' => 'failed'];
        }

        $skip_reason = $this->getAttributionSkipReason($order);
        if ($skip_reason !== null) {
            $this->markOrderAttributionSkipped($order, $skip_reason);
            return ['status' => 'skipped', 'reason' => $skip_reason];
        }

        $had_refunds = (float) $order->get_total_refunded() > 0.01;
        $ok = $had_refunds
            ? $this->reattributeFullOrder($order)
            : $this->attributeOrderDiscounts($order);

        return $ok ? ['status' => 'applied'] : ['status' => 'failed'];
    }

    /**
     * @return array{status:string,reason?:string}
     */
    private function applyRetrySkippedToOrderId(int $order_id): array {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            return ['status' => 'failed'];
        }

        $skip_reason = $this->getAttributionSkipReason($order);
        if ($skip_reason !== null) {
            $this->markOrderAttributionSkipped($order, $skip_reason);
            return ['status' => 'skipped', 'reason' => $skip_reason];
        }

        $had_refunds = (float) $order->get_total_refunded() > 0.01;
        $ok = $had_refunds
            ? $this->reattributeFullOrder($order)
            : $this->attributeOrderDiscounts($order);

        return $ok ? ['status' => 'applied'] : ['status' => 'failed'];
    }

    private function clearAttributionSkippedMarker(\WC_Order $order): void {
        if (method_exists($order, 'meta_exists') && $order->meta_exists(self::META_ATTRIBUTION_SKIPPED)) {
            $order->delete_meta_data(self::META_ATTRIBUTION_SKIPPED);
            return;
        }

        $existing = $order->get_meta(self::META_ATTRIBUTION_SKIPPED, true);
        if ($existing !== '' && $existing !== null) {
            $order->delete_meta_data(self::META_ATTRIBUTION_SKIPPED);
        }
    }

    private function markOrderAttributionSkipped(\WC_Order $order, string $reason): void {
        $existing = (string) $order->get_meta(self::META_ATTRIBUTION_SKIPPED, true);
        if ($existing === $reason) {
            return;
        }

        $order->update_meta_data(self::META_ATTRIBUTION_SKIPPED, $reason);
        $order->save_meta_data();
    }

    private function countPreviouslySkippedAttributionInDateRange(string $start_date, string $end_date): int {
        global $wpdb;

        $start = $start_date . (strpos($start_date, ' ') !== false ? '' : ' 00:00:00');
        $end = $end_date . (strpos($end_date, ' ') !== false ? '' : ' 23:59:59');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_skip ON p.ID = pm_skip.post_id AND pm_skip.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
             AND p.post_date >= %s
             AND p.post_date <= %s
             AND pm_skip.meta_value IS NOT NULL
             AND pm_skip.meta_value != ''
             AND (pm.meta_value IS NULL OR pm.meta_value = '')",
            self::META_ATTRIBUTION_SKIPPED,
            self::META_ORDER_ATTRIBUTION_AT,
            $start,
            $end
        ));
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
     * Skip order-level fee allocation when legacy line meta matches bucket fee totals (coupons always allocate).
     *
     * @param array<int,array<int,array<string,mixed>>> $item_discounts
     * @param array<int,array{name:string,type:string,amount:float,source:string}> $sources
     * @return array<int,array{name:string,type:string,amount:float,source:string}>
     */
    private function filterOrderDiscountSourcesAgainstLegacyBuckets(\WC_Order $order, array $item_discounts, array $sources): array {
        $order_id = (int) $order->get_id();
        $legacy_sum_by_bucket = [];
        $legacy_line_count_by_bucket = [];

        foreach ($item_discounts as $discounts) {
            foreach ((array) $discounts as $entry) {
                if (!is_array($entry) || (string) ($entry['source'] ?? '') !== 'legacy_item_meta') {
                    continue;
                }

                $amount = round((float) ($entry['amount'] ?? 0.0), 2);
                if ($amount <= 0.0) {
                    continue;
                }

                $bucket = intersoccer_map_discount_type_to_report_bucket((string) ($entry['type'] ?? ''));
                $legacy_sum_by_bucket[$bucket] = ($legacy_sum_by_bucket[$bucket] ?? 0.0) + $amount;
                $legacy_line_count_by_bucket[$bucket] = ($legacy_line_count_by_bucket[$bucket] ?? 0) + 1;
            }
        }

        /** @var array<int,array<int,float>> $fee_amount_by_index_by_bucket */
        $fee_amount_by_index_by_bucket = [];
        foreach ($sources as $index => $source) {
            if ((string) ($source['source'] ?? '') !== 'order_fee') {
                continue;
            }
            $bucket = intersoccer_map_discount_type_to_report_bucket((string) ($source['type'] ?? ''));
            if ($bucket === 'other') {
                continue;
            }
            $fee_amount_by_index_by_bucket[$bucket][$index] = round((float) ($source['amount'] ?? 0.0), 2);
        }

        $skip_fee_indices = [];
        $warned_buckets = [];

        foreach ($fee_amount_by_index_by_bucket as $bucket => $fees_by_index) {
            $legacy_sum = (float) ($legacy_sum_by_bucket[$bucket] ?? 0.0);
            if ($legacy_sum <= 0.01 || $fees_by_index === []) {
                continue;
            }

            $legacy_line_count = (int) ($legacy_line_count_by_bucket[$bucket] ?? 0);
            $tolerance = 0.01 * max(1, $legacy_line_count);
            $fee_sum = 0.0;
            foreach ($fees_by_index as $amount) {
                $fee_sum += $amount;
            }

            if (abs($fee_sum - $legacy_sum) <= $tolerance) {
                foreach (array_keys($fees_by_index) as $index) {
                    $skip_fee_indices[$index] = true;
                }
                continue;
            }

            foreach ($fees_by_index as $index => $amount) {
                if (abs($amount - $legacy_sum) <= $tolerance) {
                    $skip_fee_indices[$index] = true;
                    break;
                }
            }

            if (!isset($warned_buckets[$bucket])) {
                $warned_buckets[$bucket] = true;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $this->logger->warning('OrderFinancialAttributionService legacy bucket mismatch', [
                        'bucket' => $bucket,
                        'legacy_line_count' => $legacy_line_count,
                        'fee_count' => count($fees_by_index),
                    ]);
                }
            }
        }

        $filtered = [];
        foreach ($sources as $index => $source) {
            if ((string) ($source['source'] ?? '') === 'order_fee' && isset($skip_fee_indices[$index])) {
                continue;
            }
            $filtered[] = $source;
        }

        return $filtered;
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
     * @return array<int,array{discount_total:float,breakdown:array<int,array<string,mixed>>}>
     */
    private function normalizeItemDiscounts(array $item_discounts): array {
        $normalized_by_item = [];

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
            }

            $normalized_by_item[(int) $item_id] = [
                'discount_total' => round($total, 2),
                'breakdown' => $normalized,
            ];
        }

        return $normalized_by_item;
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

        $normalized_by_item = $this->normalizeItemDiscounts($item_discounts);

        foreach ($normalized_by_item as $item_id => $payload) {
            $normalized = $payload['breakdown'];
            $total = $payload['discount_total'];

            foreach ($normalized as $discount) {
                $bucket = intersoccer_map_discount_type_to_report_bucket((string) $discount['type']);
                if (!isset($summary[$bucket])) {
                    $summary[$bucket] = 0.0;
                }
                $summary[$bucket] += (float) $discount['amount'];
            }

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
            $line_total = (float) $item->get_total();
            $discount = (float) wc_get_order_item_meta((int) $item_id, self::META_ITEM_DISCOUNT_TOTAL, true);
            $refund = (float) wc_get_order_item_meta((int) $item_id, self::META_ITEM_REFUND, true);
            $final = intersoccer_financial_line_final_price($base, $line_total, $discount);
            $attributed_net += $final - $refund;
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
