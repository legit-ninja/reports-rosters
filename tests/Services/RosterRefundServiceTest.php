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
     * @var object|null
     */
    private $previous_wpdb = null;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->previous_wpdb = isset($wpdb) ? $wpdb : null;
        $this->wpdb_updates = [];
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
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_col')->andReturn([]);
        $wpdb->shouldReceive('query')->andReturn(0);
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

        $processor = Mockery::mock(OrderProcessor::class);
        $hooks = new HooksManager(new Logger(), $processor, $refund_service);

        $hooks->handle_order_cancelled(77);
        $hooks->handle_order_status_refunded(88);
        $hooks->handle_order_refunded(99, 1000);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
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
}
