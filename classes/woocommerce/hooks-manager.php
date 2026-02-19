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

