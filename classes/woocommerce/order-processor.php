<?php
/**
 * Order Processor
 *
 * Thin orchestrator that delegates WooCommerce order ingestion to the
 * new OOP roster services while keeping backward compatibility with the
 * legacy procedural hooks.
 *
 * @package InterSoccer\ReportsRosters\WooCommerce
 */

namespace InterSoccer\ReportsRosters\WooCommerce;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterBuilder;

defined('ABSPATH') or die('Restricted access');

class OrderProcessor {

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var RosterRepository
     */
    private $roster_repository;

    /**
     * @var RosterBuilder
     */
    private $roster_builder;

    /**
     * @var RostersCollection
     */
    private $last_rosters;

    /**
     * @var bool
     */
    private $last_order_completed;

    public function __construct(
        Logger $logger = null,
        RosterRepository $roster_repository = null,
        RosterBuilder $roster_builder = null
    ) {
        $this->logger = $logger ?: new Logger();
        $this->roster_repository = $roster_repository ?: new RosterRepository($this->logger);
        $this->roster_builder = $roster_builder ?: new RosterBuilder(
            $this->logger,
            null,
            null,
            $this->roster_repository
        );

        $this->resetLastState();
    }

    /**
     * Process a single WooCommerce order.
     *
     * @param int|\WC_Order $order Order ID or object.
     * @return bool True on success.
     */
    public function processOrder($order) {
        try {
            $this->resetLastState();
            $wc_order = $this->resolveOrder($order);

            if (!$wc_order) {
                $this->logger->warning('OrderProcessor: Unable to resolve order for processing', [
                    'order' => $order,
                ]);
                return false;
            }

            if (!$this->shouldProcess($wc_order)) {
                $this->logger->debug('OrderProcessor: Order skipped due to status', [
                    'order_id' => $wc_order->get_id(),
                    'status'   => $wc_order->get_status(),
                ]);
                return false;
            }

            $this->last_rosters = $this->roster_builder->buildRosterFromOrder(
                $wc_order->get_id(),
                [
                    'validate_data'  => true,
                    'skip_duplicates' => true,
                    'update_existing' => true,
                ]
            );

            $roster_count = $this->last_rosters->count();
            $completed = false;

            if ($roster_count > 0) {
                $current_status = $wc_order->get_status();
                if (!in_array($current_status, ['completed', 'refunded', 'cancelled'], true)) {
                    $wc_order->update_status(
                        'completed',
                        \__('Completed via OOP OrderProcessor (rosters populated).', 'intersoccer-reports-rosters')
                    );
                    $completed = true;
                }
            }

            $this->last_order_completed = $completed;

            $this->logger->info('OrderProcessor: Order processed', [
                'order_id'        => $wc_order->get_id(),
                'rosters_created' => $roster_count,
                'completed'       => $completed,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('OrderProcessor: Failed to process order', [
                'order'   => is_object($order) && method_exists($order, 'get_id') ? $order->get_id() : $order,
                'message' => $e->getMessage(),
            ]);

            $this->resetLastState();
            return false;
        }
    }

    /**
     * Process a batch of orders.
     *
     * @param array $order_ids
     * @return array Summary statistics.
     */
    public function process_batch(array $order_ids) {
        $summary = [
            'success'          => true,
            'processed_orders' => 0,
            'failed_orders'    => [],
            'roster_entries'   => 0,
            'completed_orders' => 0,
        ];

        foreach ($order_ids as $order_id) {
            if ($this->processOrder($order_id)) {
                $summary['processed_orders']++;
                $summary['roster_entries'] += $this->getLastProcessedRosters()->count();

                if ($this->wasLastOrderCompleted()) {
                    $summary['completed_orders']++;
                }
            } else {
                $summary['failed_orders'][] = (is_object($order_id) && (method_exists($order_id, 'get_id') || is_callable([$order_id, 'get_id'])))
                    ? $order_id->get_id()
                    : $order_id;
                $summary['success'] = false;
            }
        }

        if ($summary['processed_orders'] === 0) {
            $summary['success'] = false;
        }

        $summary['message'] = sprintf(
            /* translators: 1: processed orders count, 2: roster entries count, 3: completed orders count */
            \__('Processed %1$d orders, populated %2$d roster entries, completed %3$d orders.', 'intersoccer-reports-rosters'),
            $summary['processed_orders'],
            $summary['roster_entries'],
            $summary['completed_orders']
        );

        return $summary;
    }

    /**
     * Legacy alias for process_batch.
     *
     * @param array $order_ids
     * @return array
     */
    public function process_orders(array $order_ids) {
        return $this->process_batch($order_ids);
    }

    /**
     * Determine whether an order should be processed.
     *
     * @param int|\WC_Order $order
     * @return bool
     */
    public function shouldProcess($order) {
        $wc_order = $this->resolveOrder($order);

        if (!$wc_order) {
            return false;
        }

        return in_array($wc_order->get_status(), ['completed', 'processing'], true);
    }

    /**
     * Get the roster collection generated by the last processed order.
     *
     * @return RostersCollection
     */
    public function getLastProcessedRosters(): RostersCollection {
        return $this->last_rosters;
    }

    /**
     * Whether the last processed order was marked as completed.
     *
     * @return bool
     */
    public function wasLastOrderCompleted(): bool {
        return $this->last_order_completed;
    }

    /**
     * Resolve an order input to a WC_Order instance.
     *
     * @param mixed $order
     * @return \WC_Order|null
     */
    private function resolveOrder($order) {
        if ($order instanceof \WC_Order) {
            return $order;
        }

        if (is_numeric($order)) {
            return wc_get_order((int) $order);
        }

        return null;
    }

    /**
     * Reset per-order state holders.
     *
     * @return void
     */
    private function resetLastState(): void {
        $this->last_rosters = new RostersCollection();
        $this->last_order_completed = false;
    }
}