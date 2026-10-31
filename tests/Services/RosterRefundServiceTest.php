<?php
/**
 * Roster refund / cancellation flagging and listing exclusion.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Services\RosterDetailsService;
use InterSoccer\ReportsRosters\Services\RosterListingService;
use InterSoccer\ReportsRosters\Services\RosterRefundService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\WooCommerce\HooksManager;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;
use Mockery;
use ReflectionMethod;

class RosterRefundServiceTest extends TestCase {

    /**
     * @var array<int,array<string,mixed>>
     */
    private $wpdb_updates = [];

    /**
     * @var array<int,string>
     */
    private $wpdb_queries = [];

    /**
     * @var array<int,array<string,mixed>>|null
     */
    private $wpdb_get_results_override = null;

    /**
     * @var mixed
     */
    private $wpdb_get_var_override = null;

    /**
     * @var object|null
     */
    private $previous_wpdb = null;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->previous_wpdb = isset($wpdb) ? $wpdb : null;
        $this->wpdb_updates = [];
        $this->wpdb_queries = [];
        $this->wpdb_get_results_override = null;
        $this->wpdb_get_var_override = null;
        $this->installWpdbStub();

        if (!function_exists('get_post_status')) {
            eval('function get_post_status($post_id) { return "wc-completed"; }');
        }
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previous_wpdb;
        $this->previous_wpdb = null;
        parent::tearDown();
    }

    private function installWpdbStub(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query) {
            $args = func_get_args();
            array_shift($args);
            if ($args === []) {
                return $query;
            }
            return vsprintf(str_replace(['%s', '%d', '%f'], ["'%s'", '%d', '%f'], $query), $args);
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(function () {
            return $this->wpdb_get_var_override ?? null;
        });
        $wpdb->shouldReceive('get_results')->andReturnUsing(function () {
            return $this->wpdb_get_results_override ?? [];
        });
        $wpdb->shouldReceive('get_col')->andReturn([]);
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) {
            $this->wpdb_queries[] = (string) $sql;
            return 1;
        });
        $wpdb->shouldReceive('update')->andReturnUsing(function ($table, $data, $where) {
            $this->wpdb_updates[] = [
                'table' => $table,
                'data' => $data,
                'where' => $where,
            ];
            return 1;
        });
    }

    public function test_full_refund_flags_all_rows_refunded(): void {
        $service = new RosterRefundService(new Logger());
        $service->handleOrderRefundedStatus(501);

        $this->assertCount(1, $this->wpdb_updates);
        $this->assertSame('wp_intersoccer_rosters', $this->wpdb_updates[0]['table']);
        $this->assertSame(Roster::STATUS_REFUNDED, $this->wpdb_updates[0]['data']['order_status']);
        $this->assertSame(501, $this->wpdb_updates[0]['where']['order_id']);
    }

    public function test_cancellation_flags_all_rows_cancelled(): void {
        $service = new RosterRefundService(new Logger());
        $service->handleOrderCancelled(902);

        $this->assertCount(1, $this->wpdb_updates);
        $this->assertSame(Roster::STATUS_CANCELLED, $this->wpdb_updates[0]['data']['order_status']);
        $this->assertSame(902, $this->wpdb_updates[0]['where']['order_id']);
    }

    public function test_partial_refund_of_one_line_flags_only_that_line(): void {
        global $intersoccer_test_wc_get_order_callback;

        $line_a = Mockery::mock('WC_Order_Item_Product');
        $line_a->shouldReceive('get_quantity')->andReturn(1.0);
        $line_a->shouldReceive('get_total')->andReturn(100.0);
        $line_a->shouldReceive('get_subtotal')->andReturn(100.0);

        $line_b = Mockery::mock('WC_Order_Item_Product');
        $line_b->shouldReceive('get_quantity')->andReturn(1.0);
        $line_b->shouldReceive('get_total')->andReturn(50.0);
        $line_b->shouldReceive('get_subtotal')->andReturn(50.0);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_item')->with(10)->andReturn($line_a);
        $order->shouldReceive('get_item')->with(20)->andReturn($line_b);
        $order->shouldReceive('get_qty_refunded_for_item')->with(10)->andReturn(-1.0);
        $order->shouldReceive('get_qty_refunded_for_item')->with(20)->andReturn(0.0);
        $order->shouldReceive('get_refunds')->andReturn([]);

        $refund_line = Mockery::mock('WC_Order_Item_Product');
        $refund_line->shouldReceive('get_meta')->with('_refunded_item_id', true)->andReturn('10');

        $refund = Mockery::mock('WC_Order');
        $refund->shouldReceive('get_items')->with('line_item')->andReturn([$refund_line]);

        $intersoccer_test_wc_get_order_callback = function ($id) use ($order, $refund) {
            if ((int) $id === 100) {
                return $order;
            }
            if ((int) $id === 200) {
                return $refund;
            }
            return null;
        };

        $service = new RosterRefundService(new Logger());
        $service->handleOrderRefundCreated(100, 200);

        $this->assertCount(1, $this->wpdb_updates);
        $this->assertSame(Roster::STATUS_REFUNDED, $this->wpdb_updates[0]['data']['order_status']);
        $this->assertSame(100, $this->wpdb_updates[0]['where']['order_id']);
        $this->assertSame(10, $this->wpdb_updates[0]['where']['order_item_id']);

        $intersoccer_test_wc_get_order_callback = null;
    }

    public function test_partial_quantity_refund_leaves_line_unflagged(): void {
        global $intersoccer_test_wc_get_order_callback;

        $line = Mockery::mock('WC_Order_Item_Product');
        $line->shouldReceive('get_quantity')->andReturn(2.0);
        $line->shouldReceive('get_total')->andReturn(200.0);
        $line->shouldReceive('get_subtotal')->andReturn(200.0);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_item')->with(30)->andReturn($line);
        $order->shouldReceive('get_qty_refunded_for_item')->with(30)->andReturn(-1.0);
        $order->shouldReceive('get_refunds')->andReturn([]);

        $refund_line = Mockery::mock('WC_Order_Item_Product');
        $refund_line->shouldReceive('get_meta')->with('_refunded_item_id', true)->andReturn('30');

        $refund = Mockery::mock('WC_Order');
        $refund->shouldReceive('get_items')->with('line_item')->andReturn([$refund_line]);

        $intersoccer_test_wc_get_order_callback = function ($id) use ($order, $refund) {
            if ((int) $id === 300) {
                return $order;
            }
            if ((int) $id === 301) {
                return $refund;
            }
            return null;
        };

        $service = new RosterRefundService(new Logger());
        $service->handleOrderRefundCreated(300, 301);

        $this->assertSame([], $this->wpdb_updates);

        $intersoccer_test_wc_get_order_callback = null;
    }

    public function test_repeated_hooks_are_idempotent(): void {
        $service = new RosterRefundService(new Logger());
        $service->handleOrderRefundedStatus(44);
        $service->handleOrderRefundedStatus(44);

        $this->assertCount(2, $this->wpdb_updates);
        $this->assertSame($this->wpdb_updates[0], $this->wpdb_updates[1]);
    }

    public function test_hooks_manager_delegates_to_refund_service(): void {
        $refund_service = Mockery::mock(RosterRefundService::class);
        $refund_service->shouldReceive('handleOrderCancelled')->once()->with(77);
        $refund_service->shouldReceive('handleOrderRefundedStatus')->once()->with(88);
        $refund_service->shouldReceive('handleOrderRefundCreated')->once()->with(99, 1000);
        $refund_service->shouldReceive('handleOrderStatusChanged')->once()->with(55, 'cancelled', 'completed');

        $processor = Mockery::mock(OrderProcessor::class);
        $hooks = new HooksManager(new Logger(), $processor, $refund_service);

        $hooks->handle_order_cancelled(77);
        $hooks->handle_order_status_refunded(88);
        $hooks->handle_order_refunded(99, 1000);
        $hooks->handle_order_status_changed(55, 'cancelled', 'completed', null);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_completed_after_cancelled_reactivates_only_partly_refunded_line(): void {
        global $intersoccer_test_wc_get_order_callback;

        $order_id = 701;
        $order = $this->createOrderMockWithRefundState($order_id, [10], [20]);
        $intersoccer_test_wc_get_order_callback = function ($id) use ($order, $order_id) {
            return (int) $id === $order_id ? $order : null;
        };

        $this->wpdb_get_var_override = '2';
        $this->wpdb_get_results_override = [
            ['order_item_id' => 10, 'order_status' => Roster::STATUS_CANCELLED],
            ['order_item_id' => 20, 'order_status' => Roster::STATUS_CANCELLED],
        ];

        $service = new RosterRefundService(new Logger());
        $service->handleOrderStatusChanged($order_id, 'cancelled', 'completed');

        $this->assertCount(1, $this->wpdb_updates);
        $this->assertSame(20, (int) $this->wpdb_updates[0]['where']['order_item_id']);
        $this->assertSame(Roster::STATUS_COMPLETED, $this->wpdb_updates[0]['data']['order_status']);

        $intersoccer_test_wc_get_order_callback = null;
    }

    public function test_refunded_then_cancelled_then_completed_keeps_fully_refunded_line_flagged(): void {
        global $intersoccer_test_wc_get_order_callback;

        $order_id = 702;
        $order = $this->createOrderMockWithRefundState($order_id, [11], []);
        $intersoccer_test_wc_get_order_callback = function ($id) use ($order, $order_id) {
            return (int) $id === $order_id ? $order : null;
        };

        $service = new RosterRefundService(new Logger());
        $service->handleOrderRefundedStatus($order_id);
        $service->handleOrderCancelled($order_id);
        $this->wpdb_updates = [];

        $this->wpdb_get_var_override = '1';
        $this->wpdb_get_results_override = [
            ['order_item_id' => 11, 'order_status' => Roster::STATUS_CANCELLED],
        ];

        $service->handleOrderStatusChanged($order_id, 'cancelled', 'completed');

        $this->assertSame([], $this->wpdb_updates);

        $intersoccer_test_wc_get_order_callback = null;
    }

    public function test_on_hold_then_processing_reactivates_flagged_rows_after_cancelled_hop(): void {
        global $intersoccer_test_wc_get_order_callback;

        $order_id = 703;
        $order = $this->createOrderMockWithRefundState($order_id, [], [30]);
        $intersoccer_test_wc_get_order_callback = function ($id) use ($order, $order_id) {
            return (int) $id === $order_id ? $order : null;
        };

        $this->wpdb_get_var_override = '1';
        $this->wpdb_get_results_override = [
            ['order_item_id' => 30, 'order_status' => Roster::STATUS_CANCELLED],
        ];

        $service = new RosterRefundService(new Logger());
        $service->handleOrderStatusChanged($order_id, 'cancelled', 'on-hold');
        $this->assertSame([], $this->wpdb_updates);

        $service->handleOrderStatusChanged($order_id, 'on-hold', 'processing');
        $this->assertCount(1, $this->wpdb_updates);
        $this->assertSame(Roster::STATUS_PROCESSING, $this->wpdb_updates[0]['data']['order_status']);
        $this->assertSame(30, (int) $this->wpdb_updates[0]['where']['order_item_id']);

        $intersoccer_test_wc_get_order_callback = null;
    }

    public function test_failed_to_completed_does_not_reactivate_without_flagged_roster_rows(): void {
        $this->wpdb_get_var_override = '0';

        $service = new RosterRefundService(new Logger());
        $service->handleOrderStatusChanged(704, 'failed', 'completed');

        $this->assertSame([], $this->wpdb_updates);
    }

    public function test_hooks_manager_init_registers_order_status_changed_with_four_arguments(): void {
        $GLOBALS['intersoccer_test_capture_action_registrations'] = true;
        $GLOBALS['intersoccer_test_action_registrations'] = [];

        $processor = Mockery::mock(OrderProcessor::class);
        $hooks = new HooksManager(new Logger(), $processor, new RosterRefundService(new Logger()));
        $hooks->init();

        $matched = array_values(array_filter(
            $GLOBALS['intersoccer_test_action_registrations'],
            static function (array $registration): bool {
                return ($registration['hook'] ?? '') === 'woocommerce_order_status_changed';
            }
        ));

        $GLOBALS['intersoccer_test_capture_action_registrations'] = false;
        unset($GLOBALS['intersoccer_test_action_registrations']);

        $this->assertCount(1, $matched);
        $this->assertSame(4, $matched[0]['accepted_args']);
    }

    /**
     * @param int[] $fully_refunded_item_ids
     * @param int[] $partly_refunded_item_ids
     */
    private function createOrderMockWithRefundState(int $order_id, array $fully_refunded_item_ids, array $partly_refunded_item_ids): object {
        $lines = [];
        foreach ($fully_refunded_item_ids as $item_id) {
            $lines[(int) $item_id] = 1.0;
        }
        foreach ($partly_refunded_item_ids as $item_id) {
            $lines[(int) $item_id] = 0.5;
        }

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_refunds')->andReturn([]);

        foreach ($lines as $item_id => $qty_refunded) {
            $line = Mockery::mock('WC_Order_Item_Product');
            $line->shouldReceive('get_quantity')->andReturn(1.0);
            $line->shouldReceive('get_total')->andReturn(100.0);
            $line->shouldReceive('get_subtotal')->andReturn(100.0);
            $order->shouldReceive('get_item')->with($item_id)->andReturn($line);
            $order->shouldReceive('get_qty_refunded_for_item')->with($item_id)->andReturn(-$qty_refunded);
        }

        return $order;
    }

    public function test_listing_prepare_excludes_refunded_roster_rows(): void {
        $collection = new RostersCollection([
            new Roster([
                'order_id' => 1,
                'order_item_id' => 11,
                'order_status' => 'completed',
                'is_placeholder' => 0,
                'activity_type' => 'Camp',
                'venue' => 'Geneva',
            ]),
            new Roster([
                'order_id' => 1,
                'order_item_id' => 12,
                'order_status' => 'refunded',
                'is_placeholder' => 0,
                'activity_type' => 'Camp',
                'venue' => 'Geneva',
            ]),
        ]);

        $service = new RosterListingService();
        $method = new ReflectionMethod(RosterListingService::class, 'prepareRosterArray');
        $method->setAccessible(true);
        $rows = $method->invoke($service, $collection);

        $this->assertCount(1, $rows);
        $this->assertSame(11, (int) $rows[0]['order_item_id']);
    }

    public function test_details_filter_excludes_refunded_roster_rows(): void {
        $collection = new RostersCollection([
            new Roster([
                'order_id' => 5,
                'order_item_id' => 51,
                'order_status' => 'refunded',
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 5,
                'order_item_id' => 52,
                'order_status' => 'completed',
                'is_placeholder' => 0,
            ]),
        ]);

        $service = new RosterDetailsService();
        $method = new ReflectionMethod(RosterDetailsService::class, 'filterByValidOrderStatus');
        $method->setAccessible(true);
        $models = $method->invoke($service, $collection);

        $this->assertCount(1, $models);
        $this->assertSame(52, (int) $models[0]->order_item_id);
    }

    public function test_preserve_existing_roster_fields_keeps_refunded_status_on_rebuild(): void {
        global $intersoccer_test_wc_get_order_callback;

        $line = Mockery::mock('WC_Order_Item_Product');
        $line->shouldReceive('get_quantity')->andReturn(1.0);
        $line->shouldReceive('get_total')->andReturn(100.0);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_item')->with(10)->andReturn($line);
        $order->shouldReceive('get_qty_refunded_for_item')->with(10)->andReturn(-1.0);
        $order->shouldReceive('get_refunds')->andReturn([]);

        $intersoccer_test_wc_get_order_callback = function ($id) use ($order) {
            return (int) $id === 1 ? $order : null;
        };

        $existing = new Roster([
            'order_id' => 1,
            'order_item_id' => 10,
            'order_status' => Roster::STATUS_REFUNDED,
            'product_id' => 100,
        ]);

        $builder = new RosterBuilder(new Logger());
        $method = new ReflectionMethod(RosterBuilder::class, 'preserveExistingRosterFields');
        $method->setAccessible(true);
        $merged = $method->invoke($builder, $existing, [
            'order_id' => 1,
            'order_item_id' => 10,
            'order_status' => 'completed',
        ]);

        $this->assertSame(Roster::STATUS_REFUNDED, $merged['order_status']);

        $intersoccer_test_wc_get_order_callback = null;
    }

    public function test_reinstated_cancelled_order_rebuild_restores_active_status(): void {
        $existing = new Roster([
            'order_id' => 2,
            'order_item_id' => 20,
            'order_status' => Roster::STATUS_CANCELLED,
            'product_id' => 100,
        ]);

        $builder = new RosterBuilder(new Logger());
        $method = new ReflectionMethod(RosterBuilder::class, 'preserveExistingRosterFields');
        $method->setAccessible(true);
        $merged = $method->invoke($builder, $existing, ['order_status' => 'completed']);

        $this->assertSame('completed', $merged['order_status']);
    }

    public function test_reinstated_cancelled_order_keeps_refunded_when_line_still_fully_refunded(): void {
        global $intersoccer_test_wc_get_order_callback;

        $line = Mockery::mock('WC_Order_Item_Product');
        $line->shouldReceive('get_quantity')->andReturn(1.0);
        $line->shouldReceive('get_total')->andReturn(120.0);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_item')->with(21)->andReturn($line);
        $order->shouldReceive('get_qty_refunded_for_item')->with(21)->andReturn(-1.0);
        $order->shouldReceive('get_refunds')->andReturn([]);

        $intersoccer_test_wc_get_order_callback = function ($id) use ($order) {
            return (int) $id === 4 ? $order : null;
        };

        $refund_service = Mockery::mock(RosterRefundService::class)->makePartial();
        $refund_service->shouldReceive('isOrderItemFullyRefunded')->once()->andReturn(true);

        $existing = new Roster([
            'order_id' => 4,
            'order_item_id' => 21,
            'order_status' => Roster::STATUS_CANCELLED,
            'product_id' => 100,
        ]);

        $builder = new RosterBuilder(new Logger(), null, null, null, null, null, null, $refund_service);
        $method = new ReflectionMethod(RosterBuilder::class, 'preserveExistingRosterFields');
        $method->setAccessible(true);
        $merged = $method->invoke($builder, $existing, [
            'order_id' => 4,
            'order_item_id' => 21,
            'order_status' => 'completed',
        ]);

        $this->assertSame(Roster::STATUS_REFUNDED, $merged['order_status']);

        $intersoccer_test_wc_get_order_callback = null;
    }

    public function test_partially_refunded_line_keeps_refunded_on_active_order_rebuild(): void {
        global $intersoccer_test_wc_get_order_callback;

        $line = Mockery::mock('WC_Order_Item_Product');
        $line->shouldReceive('get_quantity')->andReturn(1.0);
        $line->shouldReceive('get_total')->andReturn(80.0);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_item')->with(30)->andReturn($line);
        $order->shouldReceive('get_qty_refunded_for_item')->with(30)->andReturn(-1.0);
        $order->shouldReceive('get_refunds')->andReturn([]);

        $intersoccer_test_wc_get_order_callback = function ($id) use ($order) {
            return (int) $id === 3 ? $order : null;
        };

        $existing = new Roster([
            'order_id' => 3,
            'order_item_id' => 30,
            'order_status' => Roster::STATUS_REFUNDED,
            'product_id' => 100,
        ]);

        $builder = new RosterBuilder(new Logger());
        $method = new ReflectionMethod(RosterBuilder::class, 'preserveExistingRosterFields');
        $method->setAccessible(true);
        $merged = $method->invoke($builder, $existing, [
            'order_id' => 3,
            'order_item_id' => 30,
            'order_status' => 'processing',
        ]);

        $this->assertSame(Roster::STATUS_REFUNDED, $merged['order_status']);

        $intersoccer_test_wc_get_order_callback = null;
    }

    public function test_listing_prepare_excludes_wc_prefixed_flagged_rows(): void {
        $collection = new RostersCollection([
            new Roster([
                'order_id' => 8,
                'order_item_id' => 81,
                'order_status' => 'wc-refunded',
                'is_placeholder' => 0,
                'activity_type' => 'Camp',
                'venue' => 'Geneva',
            ]),
            new Roster([
                'order_id' => 8,
                'order_item_id' => 82,
                'order_status' => 'completed',
                'is_placeholder' => 0,
                'activity_type' => 'Camp',
                'venue' => 'Geneva',
            ]),
        ]);

        $service = new RosterListingService();
        $method = new ReflectionMethod(RosterListingService::class, 'prepareRosterArray');
        $method->setAccessible(true);
        $rows = $method->invoke($service, $collection);

        $this->assertCount(1, $rows);
        $this->assertSame(82, (int) $rows[0]['order_item_id']);
    }

    public function test_listing_sql_does_not_exclude_null_order_status_rows(): void {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $captured_sql = '';
        $captured_values = [];

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('query')
            ->with(Mockery::pattern('/group_concat_max_len/'))
            ->once()
            ->andReturn(true);
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function ($sql, ...$args) use (&$captured_sql, &$captured_values) {
                $captured_sql = (string) $sql;
                $captured_values = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;
                return $sql;
            });
        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        $db = new Database($logger);
        $db->get_roster_listing_aggregates(['is_placeholder' => 0]);

        $this->assertStringContainsString('r.order_status IS NULL OR r.order_status NOT IN', $captured_sql);
        foreach (RosterRefundService::FLAGGED_ROW_STATUSES as $status) {
            $this->assertContains($status, $captured_values);
        }
    }

    public function test_prime_order_posts_once_per_summary_build(): void {
        $GLOBALS['intersoccer_rr_test_api_counting_enabled'] = true;
        $GLOBALS['intersoccer_rr_test_api_call_counts'] = [];
        $GLOBALS['intersoccer_test_post_status'] = [
            101 => 'wc-completed',
            102 => 'wc-cancelled',
        ];

        $collection = new RostersCollection([
            new Roster([
                'order_id' => 101,
                'order_item_id' => 1001,
                'variation_id' => 501,
                'activity_type' => 'Course',
                'girls_only' => 0,
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 102,
                'order_item_id' => 1002,
                'variation_id' => 502,
                'activity_type' => 'Course',
                'girls_only' => 0,
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 101,
                'order_item_id' => 1003,
                'variation_id' => 503,
                'activity_type' => 'Course',
                'girls_only' => 0,
                'is_placeholder' => 0,
            ]),
        ]);

        $repository = Mockery::mock(\InterSoccer\ReportsRosters\Data\Repositories\RosterRepository::class);
        $repository->shouldReceive('where')->once()->andReturn($collection);

        $service = new RosterDetailsService(
            Mockery::mock(Logger::class)->shouldIgnoreMissing(),
            $repository,
            Mockery::mock(Database::class)->shouldIgnoreMissing()
        );

        $prime = new ReflectionMethod(RosterDetailsService::class, 'primeOrderPostsForSummaries');
        $prime->setAccessible(true);
        $prime->invoke($service, [101, 102, 101]);
        $this->assertSame(1, (int) ($GLOBALS['intersoccer_rr_test_api_call_counts']['_prime_post_caches'] ?? 0));

        $build = new ReflectionMethod(RosterDetailsService::class, 'buildRosterSummaries');
        $build->setAccessible(true);
        $build->invoke($service, (object) [
            'activity_type' => 'Course',
            'girls_only' => 0,
            'product_id' => 900,
        ], []);
        $this->assertSame(2, (int) ($GLOBALS['intersoccer_rr_test_api_call_counts']['_prime_post_caches'] ?? 0));

        unset($GLOBALS['intersoccer_test_post_status'], $GLOBALS['intersoccer_rr_test_api_counting_enabled'], $GLOBALS['intersoccer_rr_test_api_call_counts']);
    }

    public function test_destination_summaries_keep_order_status_filtering_with_primed_posts(): void {
        $GLOBALS['intersoccer_test_post_status'] = [
            201 => 'wc-completed',
            202 => 'wc-processing',
            203 => 'wc-cancelled',
            204 => 'wc-refunded',
            205 => 'trash',
            206 => 'wc-completed',
        ];

        $collection = new RostersCollection([
            new Roster([
                'order_id' => 201,
                'order_item_id' => 2001,
                'variation_id' => 701,
                'product_id' => 900,
                'product_name' => 'A',
                'activity_type' => 'Course',
                'venue' => 'Geneva',
                'course_day' => 'Monday',
                'girls_only' => 0,
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 202,
                'order_item_id' => 2002,
                'variation_id' => 702,
                'product_id' => 900,
                'product_name' => 'B',
                'activity_type' => 'Course',
                'venue' => 'Geneva',
                'course_day' => 'Tuesday',
                'girls_only' => 0,
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 203,
                'order_item_id' => 2003,
                'variation_id' => 703,
                'product_id' => 900,
                'activity_type' => 'Course',
                'girls_only' => 0,
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 204,
                'order_item_id' => 2004,
                'variation_id' => 704,
                'product_id' => 900,
                'activity_type' => 'Course',
                'girls_only' => 0,
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 205,
                'order_item_id' => 2005,
                'variation_id' => 705,
                'product_id' => 900,
                'activity_type' => 'Course',
                'girls_only' => 0,
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 0,
                'order_item_id' => 2006,
                'variation_id' => 706,
                'product_id' => 900,
                'activity_type' => 'Course',
                'girls_only' => 0,
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 206,
                'order_item_id' => 2007,
                'variation_id' => 801,
                'product_id' => 900,
                'product_name' => 'Girls',
                'activity_type' => 'Course',
                'venue' => 'Lausanne',
                'girls_only' => 1,
                'is_placeholder' => 0,
            ]),
            new Roster([
                'order_id' => 201,
                'order_item_id' => 0,
                'variation_id' => 707,
                'product_id' => 900,
                'activity_type' => 'Course',
                'girls_only' => 0,
                'is_placeholder' => 1,
            ]),
        ]);

        $baseRoster = (object) [
            'activity_type' => 'Course',
            'girls_only' => 0,
            'product_id' => 900,
        ];

        $expected = $this->referenceLegacySummaryMaps($collection, $baseRoster);

        $repository = Mockery::mock(\InterSoccer\ReportsRosters\Data\Repositories\RosterRepository::class);
        $repository->shouldReceive('where')->once()->andReturn($collection);

        $service = new RosterDetailsService(
            Mockery::mock(Logger::class)->shouldIgnoreMissing(),
            $repository,
            Mockery::mock(Database::class)->shouldIgnoreMissing()
        );

        $build = new ReflectionMethod(RosterDetailsService::class, 'buildRosterSummaries');
        $build->setAccessible(true);
        $actual = $build->invoke($service, $baseRoster, []);

        // #104 builds one destination list and splits it by variation girls-only state afterwards,
        // so compare the variations that survive status filtering, across both legacy buckets.
        $this->assertSame(
            $this->summaryVariationSignature(array_merge($expected['same_gender'], $expected['cross_gender'])),
            $this->summaryVariationSignature($actual)
        );

        unset($GLOBALS['intersoccer_test_post_status']);
    }

    /**
     * @return array{same_gender:object[],cross_gender:object[]}
     */
    private function referenceLegacySummaryMaps(RostersCollection $collection, object $baseRoster): array {
        $allowed = ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'];
        $sameGenderSummaries = [];
        $crossGenderSummaries = [];
        $baseGirlsOnly = (int) ($baseRoster->girls_only ?? 0) === 1;

        foreach ($collection as $model) {
            if ((int) $model->is_placeholder === 1) {
                continue;
            }

            if (!$model->order_id) {
                continue;
            }

            $status = get_post_status($model->order_id);
            if (!$status || !in_array($status, $allowed, true)) {
                continue;
            }

            $isGirlsOnly = (int) $model->girls_only === 1;
            if ($isGirlsOnly !== $baseGirlsOnly) {
                $targetSummaries = &$crossGenderSummaries;
            } else {
                $targetSummaries = &$sameGenderSummaries;
            }

            $keyParts = [
                (int) $model->product_id,
                (int) $model->variation_id,
                (string) $model->product_name,
                (string) $model->venue,
                (string) $model->age_group,
                (string) $model->activity_type,
                (string) $model->camp_terms,
                (string) $model->course_day,
                (string) $model->times,
                (string) $model->season,
                $isGirlsOnly ? '1' : '0',
            ];
            $key = implode('|', $keyParts);

            if (!isset($targetSummaries[$key])) {
                $targetSummaries[$key] = (object) [
                    'variation_id' => (int) $model->variation_id,
                    'current_players' => 0,
                ];
            }

            if ($model->order_item_id) {
                $targetSummaries[$key]->current_players++;
            }
            unset($targetSummaries);
        }

        return [
            'same_gender' => array_values($sameGenderSummaries),
            'cross_gender' => array_values($crossGenderSummaries),
        ];
    }

    /**
     * @param object[] $summaries
     */
    private function summaryVariationSignature(array $summaries): string {
        $ids = [];
        foreach ($summaries as $summary) {
            $ids[] = (int) $summary->variation_id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return implode(',', $ids);
    }
}
