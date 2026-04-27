<?php
/**
 * WooCommerce Hooks Manager
 *
 * Registers WooCommerce hooks and cron callbacks for roster population and
 * order auto-completion using OOP services only.
 *
 * @package InterSoccer\ReportsRosters\WooCommerce
 */

namespace InterSoccer\ReportsRosters\WooCommerce;

use InterSoccer\ReportsRosters\Core\Logger;

defined('ABSPATH') or die('Restricted access');

class HooksManager {
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var OrderProcessor
     */
    private $order_processor;

    public function __construct(Logger $logger = null, OrderProcessor $order_processor = null) {
        $this->logger = $logger ?: new Logger();
        if ($order_processor !== null) {
            $this->order_processor = $order_processor;
        } elseif (function_exists('intersoccer_oop_get_order_processor')) {
            $this->order_processor = intersoccer_oop_get_order_processor();
        } else {
            $this->order_processor = new OrderProcessor($this->logger);
        }
    }

    public function init(): void {
        // Order status hooks: process once when order is completed (reduces duplicate runs).
        // Filter to re-enable processing on 'processing' status: add_filter('intersoccer_process_order_on_processing_status', '__return_true');
        add_action('woocommerce_order_status_completed', [$this, 'handle_order_status'], 10, 1);
        if (apply_filters('intersoccer_process_order_on_processing_status', false)) {
            add_action('woocommerce_order_status_processing', [$this, 'handle_order_status'], 10, 1);
        }

        // Cron schedule + recurring job
        add_filter('cron_schedules', [$this, 'register_cron_schedule']);
        add_action('init', [$this, 'schedule_recurring_auto_complete'], 5);

        if (defined('INTERSOCCER_ORDER_AUTO_COMPLETE_CRON_HOOK')) {
            add_action(INTERSOCCER_ORDER_AUTO_COMPLETE_CRON_HOOK, [$this, 'auto_complete_processing_orders']);
        }

        if (defined('INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK')) {
            add_action(INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK, [$this, 'auto_complete_single_order'], 10, 1);
        }

        // Woo order edit screen: per-line-item roster sync controls.
        add_action('woocommerce_after_order_itemmeta', [$this, 'render_order_item_sync_controls'], 20, 3);
    }

    public function handle_order_status($order_id): void {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return;
        }
        $this->order_processor->processOrder($order_id);
    }

    public function register_cron_schedule(array $schedules): array {
        $interval = (int) apply_filters('intersoccer_auto_complete_orders_interval_seconds', 300);
        if ($interval < 60) {
            $interval = 60;
        }

        if (defined('INTERSOCCER_ORDER_AUTO_COMPLETE_RECURRENCE')) {
            $schedules[INTERSOCCER_ORDER_AUTO_COMPLETE_RECURRENCE] = [
                'interval' => $interval,
                'display'  => sprintf(
                    __('InterSoccer auto-complete orders every %d seconds', 'intersoccer-reports-rosters'),
                    $interval
                ),
            ];
        }

        return $schedules;
    }

    public function schedule_recurring_auto_complete(): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (!defined('INTERSOCCER_ORDER_AUTO_COMPLETE_CRON_HOOK') || !defined('INTERSOCCER_ORDER_AUTO_COMPLETE_RECURRENCE')) {
            return;
        }

        $scheduled = wp_next_scheduled(INTERSOCCER_ORDER_AUTO_COMPLETE_CRON_HOOK);
        if ($scheduled) {
            return;
        }

        $interval = (int) apply_filters('intersoccer_auto_complete_orders_interval_seconds', 300);
        if ($interval < 60) {
            $interval = 60;
        }

        wp_schedule_event(time() + $interval, INTERSOCCER_ORDER_AUTO_COMPLETE_RECURRENCE, INTERSOCCER_ORDER_AUTO_COMPLETE_CRON_HOOK);
    }

    public function auto_complete_processing_orders(): void {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $limit = (int) apply_filters('intersoccer_auto_complete_orders_batch_limit', 25);
        if ($limit <= 0) {
            $limit = 25;
        }

        $orders = wc_get_orders([
            'status' => ['wc-processing'],
            'limit'  => $limit,
            'orderby' => 'date',
            'order' => 'ASC',
            'return' => 'ids',
        ]);

        if (empty($orders)) {
            return;
        }

        foreach ($orders as $order_id) {
            $this->order_processor->processOrder((int) $order_id);
        }
    }

    public function auto_complete_single_order($order_id): void {
        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return;
        }

        $this->order_processor->processOrder($order_id);
    }

    /**
     * Render native-looking sync controls under each line item in Woo order admin.
     *
     * @param int   $item_id
     * @param mixed $item
     * @param mixed $product
     */
    public function render_order_item_sync_controls($item_id, $item, $product): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $item_id = (int) $item_id;
        if ($item_id <= 0) {
            return;
        }

        $item_type = '';
        if (is_object($item) && method_exists($item, 'get_type')) {
            $item_type = (string) $item->get_type();
        } elseif (is_object($item) && isset($item->order_item_type)) {
            $item_type = (string) $item->order_item_type;
        } elseif (is_array($item) && isset($item['order_item_type'])) {
            $item_type = (string) $item['order_item_type'];
        }

        if ($item_type !== '' && $item_type !== 'line_item') {
            return;
        }

        global $wpdb;
        $rosters_count = 0;
        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix)) {
            $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
            $rosters_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$rosters_table} WHERE order_item_id = %d",
                $item_id
            ));
        }

        // #region agent log
        error_log(
            'DEBUG21E376 H1 render_order_item_sync_controls ' .
            wp_json_encode([
                'order_item_id' => $item_id,
                'item_type' => $item_type,
                'default_badge' => 'Unchecked',
                'rosters_count' => $rosters_count,
            ])
        );
        // #endregion

        echo '<div class="intersoccer-order-item-sync-controls" data-order-item-id="' . esc_attr((string) $item_id) . '" style="margin-top:8px;">';
        echo '  <span class="intersoccer-sync-badge intersoccer-sync-badge-unchecked">' . esc_html__('Unchecked', 'intersoccer-reports-rosters') . '</span>';
        echo '  <button type="button" class="button button-small intersoccer-check-sync">' . esc_html__('Check Sync', 'intersoccer-reports-rosters') . '</button> ';
        echo '  <button type="button" class="button button-small intersoccer-fix-sync" style="margin-left:6px;">' . esc_html__('Fix Sync', 'intersoccer-reports-rosters') . '</button>';
        echo '  <span class="spinner intersoccer-sync-spinner" style="float:none; margin:0 0 0 8px;"></span>';
        echo '  <div class="intersoccer-order-item-sync-result" style="margin-top:8px;"></div>';
        echo '</div>';
    }

    /**
     * Schedule a follow-up check for a single order.
     *
     * Kept as a method so the OrderProcessor can optionally delegate via a
     * thin global wrapper for backward compatibility.
     */
    public function schedule_single_order_check(int $order_id, ?int $delay = null): void {
        if (!function_exists('wp_schedule_single_event') || !defined('INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK')) {
            return;
        }

        if ($delay === null) {
            $delay = (int) apply_filters('intersoccer_auto_complete_single_delay_seconds', 180, $order_id);
        }
        if ($delay < 60) {
            $delay = 60;
        }

        $timestamp = time() + $delay;

        if (!wp_next_scheduled(INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK, [$order_id])) {
            wp_schedule_single_event($timestamp, INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK, [$order_id]);
        }
    }
}

