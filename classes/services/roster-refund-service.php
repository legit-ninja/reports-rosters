<?php
/**
 * Roster refund / cancellation flags on denormalized roster rows.
 *
 * @package InterSoccer\ReportsRosters\Services
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Models\Roster;

defined('ABSPATH') or die('Restricted access');

class RosterRefundService {

    /**
     * Roster row order_status values that hide the row from listings / details.
     *
     * @var string[]
     */
    public const FLAGGED_ROW_STATUSES = [
        'cancelled',
        'refunded',
        'wc-cancelled',
        'wc-refunded',
    ];

    /**
     * Incoming rebuild statuses that are not active Woo order states.
     *
     * @var string[]
     */
    public const INACTIVE_REBUILD_ORDER_STATUSES = [
        'cancelled',
        'refunded',
        'failed',
        'wc-cancelled',
        'wc-refunded',
        'wc-failed',
    ];

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @return string[]
     */
    public static function getFlaggedRowStatuses(): array {
        return self::FLAGGED_ROW_STATUSES;
    }

    public static function isFlaggedRowStatus(string $status): bool {
        return in_array($status, self::FLAGGED_ROW_STATUSES, true);
    }

    public static function isInactiveRebuildOrderStatus(string $status): bool {
        return in_array($status, self::INACTIVE_REBUILD_ORDER_STATUSES, true);
    }

    public static function isRefundedRowStatus(string $status): bool {
        return in_array($status, [Roster::STATUS_REFUNDED, 'wc-refunded'], true);
    }

    public static function isCancelledRowStatus(string $status): bool {
        return in_array($status, [Roster::STATUS_CANCELLED, 'wc-cancelled'], true);
    }

    /**
     * Woo order statuses that should restore flagged roster rows to an active row status.
     *
     * @return string[]
     */
    public static function getReinstateRosterOrderStatuses(): array {
        return [
            Roster::STATUS_COMPLETED,
            Roster::STATUS_PROCESSING,
        ];
    }

    public static function normalizeWooOrderStatus(string $status): string {
        $status = strtolower(trim($status));
        if (str_starts_with($status, 'wc-')) {
            $status = substr($status, 3);
        }
        return $status;
    }

    public static function isReinstateTargetOrderStatus(string $new_status): bool {
        return in_array(self::normalizeWooOrderStatus($new_status), self::getReinstateRosterOrderStatuses(), true);
    }

    /**
     * @deprecated Use isReinstateTargetOrderStatus(); reinstate also requires flagged roster rows on the order.
     */
    public static function shouldReinstateRosterRowsForStatusChange(string $old_status, string $new_status): bool {
        return self::isReinstateTargetOrderStatus($new_status);
    }

    /**
     * SQL fragment + bound values to exclude flagged roster rows (NULL order_status kept).
     *
     * @return array{clause:string,params:string[]}
     */
    public static function getSqlExcludeFlaggedRowStatuses(string $column = 'order_status'): array {
        $statuses = self::getFlaggedRowStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        return [
            'clause' => "({$column} IS NULL OR {$column} NOT IN ({$placeholders}))",
            'params' => $statuses,
        ];
    }

    /**
     * @param string[] $where
     * @param array<int,mixed> $params
     */
    public static function appendSqlExcludeFlaggedRowStatuses(array &$where, array &$params, string $column = 'order_status'): void {
        $fragment = self::getSqlExcludeFlaggedRowStatuses($column);
        $where[] = $fragment['clause'];
        $params = array_merge($params, $fragment['params']);
    }

    public function __construct(Logger $logger = null) {
        $this->logger = $logger ?: new Logger();
    }

    /**
     * Mark every roster row for the order as cancelled (idempotent).
     */
    public function handleOrderCancelled(int $order_id): void {
        if ($order_id <= 0) {
            return;
        }
        $this->updateOrderRosterStatus($order_id, Roster::STATUS_CANCELLED);
    }

    /**
     * Mark every roster row for the order as refunded (full order refund).
     */
    public function handleOrderRefundedStatus(int $order_id): void {
        if ($order_id <= 0) {
            return;
        }
        $this->updateOrderRosterStatus($order_id, Roster::STATUS_REFUNDED);
    }

    /**
     * When a cancelled or refunded order returns to an active Woo status, restore roster rows.
     */
    public function handleOrderStatusChanged(int $order_id, string $old_status, string $new_status): void {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || !self::isReinstateTargetOrderStatus($new_status)) {
            return;
        }
        if (!$this->orderHasFlaggedRosterRows($order_id)) {
            return;
        }

        $active_status = self::normalizeWooOrderStatus($new_status);
        $this->reactivateFlaggedOrderRosterRows($order_id, $active_status);
    }

    /**
     * React to a WooCommerce refund: full order or per-line when the line is fully refunded.
     *
     * @param int $order_id  Parent order ID.
     * @param int $refund_id Refund order ID.
     */
    public function handleOrderRefundCreated(int $order_id, int $refund_id): void {
        $order_id = (int) $order_id;
        $refund_id = (int) $refund_id;
        if ($order_id <= 0 || $refund_id <= 0 || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || !is_callable([$order, 'get_status'])) {
            return;
        }

        if ($order->get_status() === Roster::STATUS_REFUNDED) {
            $this->updateOrderRosterStatus($order_id, Roster::STATUS_REFUNDED);
            return;
        }

        $refund = wc_get_order($refund_id);
        if (!$refund || !is_callable([$refund, 'get_items'])) {
            return;
        }

        $original_item_ids = [];
        foreach ($refund->get_items('line_item') as $refund_item) {
            if (!is_object($refund_item) || !is_callable([$refund_item, 'get_meta'])) {
                continue;
            }
            $original_id = (int) $refund_item->get_meta('_refunded_item_id', true);
            if ($original_id > 0) {
                $original_item_ids[$original_id] = true;
            }
        }

        foreach (array_keys($original_item_ids) as $original_item_id) {
            if ($this->isOrderItemFullyRefunded($order, (int) $original_item_id)) {
                $this->updateOrderItemRosterStatus($order_id, (int) $original_item_id, Roster::STATUS_REFUNDED);
            }
        }
    }

    /**
     * Whether cumulative refunds cover the entire order line (qty or amount).
     *
     * @param \WC_Order|\WC_Order_Refund $order
     */
    public function isOrderItemFullyRefunded($order, int $item_id): bool {
        if ($item_id <= 0 || !is_object($order) || !is_callable([$order, 'get_item'])) {
            return false;
        }

        $item = $order->get_item($item_id);
        if (!$item || !is_callable([$item, 'get_quantity'])) {
            return false;
        }

        $item_qty = (float) $item->get_quantity();
        $qty_refunded = 0.0;
        if (is_callable([$order, 'get_qty_refunded_for_item'])) {
            $qty_refunded = abs((float) $order->get_qty_refunded_for_item($item_id));
        }

        if ($item_qty > 0 && $qty_refunded >= $item_qty) {
            return true;
        }

        if ($qty_refunded > 0) {
            return false;
        }

        $line_total = (float) $item->get_total();
        if ($line_total <= 0 && is_callable([$item, 'get_subtotal'])) {
            $line_total = (float) $item->get_subtotal();
        }
        if ($line_total <= 0) {
            return false;
        }

        $refunded_total = $this->getRefundedLineTotal($order, $item_id);
        return $refunded_total + 0.0001 >= $line_total;
    }

    /**
     * Sum refunded line totals for an original order item across refund orders.
     *
     * @param \WC_Order $order
     */
    private function getRefundedLineTotal($order, int $item_id): float {
        if (!is_callable([$order, 'get_refunds'])) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($order->get_refunds() as $refund) {
            if (!is_object($refund) || !is_callable([$refund, 'get_items'])) {
                continue;
            }
            foreach ($refund->get_items('line_item') as $refund_item) {
                if (!is_object($refund_item) || !is_callable([$refund_item, 'get_meta'])) {
                    continue;
                }
                if ((int) $refund_item->get_meta('_refunded_item_id', true) !== $item_id) {
                    continue;
                }
                if (is_callable([$refund_item, 'get_total'])) {
                    $total += abs((float) $refund_item->get_total());
                }
            }
        }

        return $total;
    }

    /**
     * @return int Rows updated (0 if none).
     */
    private function orderHasFlaggedRosterRows(int $order_id): bool {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || $order_id <= 0) {
            return false;
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $flagged_statuses = self::getFlaggedRowStatuses();
        $placeholders = implode(',', array_fill(0, count($flagged_statuses), '%s'));
        $params = array_merge([$order_id], $flagged_statuses);

        $sql = "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND order_status IN ({$placeholders})";
        $count = $wpdb->get_var($wpdb->prepare($sql, ...$params));

        return (int) $count > 0;
    }

    /**
     * @return array<int,array{order_item_id:int,order_status:string}>
     */
    private function fetchFlaggedRosterRowsForOrder(int $order_id): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || $order_id <= 0) {
            return [];
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $flagged_statuses = self::getFlaggedRowStatuses();
        $placeholders = implode(',', array_fill(0, count($flagged_statuses), '%s'));
        $params = array_merge([$order_id], $flagged_statuses);

        $sql = "SELECT order_item_id, order_status FROM {$table} WHERE order_id = %d AND order_status IN ({$placeholders})";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    private function reactivateFlaggedOrderRosterRows(int $order_id, string $active_status): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || $order_id <= 0 || $active_status === '') {
            return 0;
        }

        $rows = $this->fetchFlaggedRosterRowsForOrder($order_id);
        if ($rows === []) {
            return 0;
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $order = null;
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }

        $updated = 0;
        foreach ($rows as $row) {
            $order_item_id = (int) ($row['order_item_id'] ?? 0);
            if ($order_item_id <= 0) {
                continue;
            }
            if ($order && $this->isOrderItemFullyRefunded($order, $order_item_id)) {
                continue;
            }

            $row_updated = $wpdb->update(
                $table,
                ['order_status' => $active_status],
                [
                    'order_id' => $order_id,
                    'order_item_id' => $order_item_id,
                ],
                ['%s'],
                ['%d', '%d']
            );

            if ($row_updated === false) {
                $this->logger->warning('RosterRefundService: failed to reactivate flagged roster row', [
                    'order_id' => $order_id,
                    'order_item_id' => $order_item_id,
                    'order_status' => $active_status,
                    'db_error' => $wpdb->last_error ?? '',
                ]);
                continue;
            }

            $updated += (int) $row_updated;
        }

        if ($updated > 0) {
            $this->logger->info('RosterRefundService: reactivated flagged roster rows for order', [
                'order_id' => $order_id,
                'order_status' => $active_status,
                'rows' => $updated,
            ]);
        }

        return $updated;
    }

    /**
     * @return int Rows updated (0 if none).
     */
    private function updateOrderRosterStatus(int $order_id, string $status): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return 0;
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $updated = $wpdb->update(
            $table,
            ['order_status' => $status],
            ['order_id' => $order_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            $this->logger->warning('RosterRefundService: failed to update roster order_status by order_id', [
                'order_id' => $order_id,
                'status' => $status,
                'db_error' => $wpdb->last_error ?? '',
            ]);
            return 0;
        }

        if ((int) $updated > 0) {
            $this->logger->info('RosterRefundService: flagged roster rows for order', [
                'order_id' => $order_id,
                'status' => $status,
                'rows' => (int) $updated,
            ]);
        }

        return (int) $updated;
    }

    /**
     * @return int Rows updated (0 if none).
     */
    private function updateOrderItemRosterStatus(int $order_id, int $order_item_id, string $status): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return 0;
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $updated = $wpdb->update(
            $table,
            ['order_status' => $status],
            [
                'order_id' => $order_id,
                'order_item_id' => $order_item_id,
            ],
            ['%s'],
            ['%d', '%d']
        );

        if ($updated === false) {
            $this->logger->warning('RosterRefundService: failed to update roster order_status by order_item_id', [
                'order_id' => $order_id,
                'order_item_id' => $order_item_id,
                'status' => $status,
                'db_error' => $wpdb->last_error ?? '',
            ]);
            return 0;
        }

        if ((int) $updated > 0) {
            $this->logger->debug('RosterRefundService: flagged roster row for refunded line item', [
                'order_id' => $order_id,
                'order_item_id' => $order_item_id,
                'status' => $status,
                'rows' => (int) $updated,
            ]);
        }

        return (int) $updated;
    }
}
