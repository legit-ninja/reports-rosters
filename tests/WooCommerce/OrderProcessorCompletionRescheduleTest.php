<?php
/**
 * Completion-check cron is only rescheduled for processing orders that did not complete.
 */

namespace InterSoccer\ReportsRosters\Tests\WooCommerce;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\AbstractCollection;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;
use Mockery;

class OrderProcessorCompletionRescheduleTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        global $intersoccer_test_wc_get_order_callback;
        global $intersoccer_test_schedule_completion_callback;

        $intersoccer_test_wc_get_order_callback = [OrderProcessorTest::class, 'getReloadedOrder'];
        $intersoccer_test_schedule_completion_callback = [OrderProcessorTest::class, 'recordScheduledOrder'];

        $ref = new \ReflectionClass(OrderProcessorTest::class);
        $prop = $ref->getProperty('scheduledOrders');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    protected function tearDown(): void {
        global $intersoccer_test_wc_get_order_callback;
        global $intersoccer_test_schedule_completion_callback;

        $intersoccer_test_wc_get_order_callback = null;
        $intersoccer_test_schedule_completion_callback = null;
        OrderProcessorTest::setReloadedOrder(0, null);
        parent::tearDown();
    }

    /** @param array<int, int> $order_item_ids */
    private static function rosterRowsForItems(int $order_id, array $order_item_ids): RostersCollection {
        $items = [];
        foreach ($order_item_ids as $order_item_id) {
            $row = new class($order_id, $order_item_id) {
                private $order_id;
                private $order_item_id;

                public function __construct(int $order_id, int $order_item_id) {
                    $this->order_id = $order_id;
                    $this->order_item_id = $order_item_id;
                }

                /** @return array<string, int> */
                public function toArray(): array {
                    return [
                        'order_id' => $this->order_id,
                        'order_item_id' => $this->order_item_id,
                    ];
                }
            };
            $items[] = $row;
        }

        $collection = new RostersCollection();
        $prop = new \ReflectionProperty(AbstractCollection::class, 'items');
        $prop->setAccessible(true);
        $prop->setValue($collection, $items);

        return $collection;
    }

    public function test_on_hold_build_does_not_schedule_completion_check(): void {
        $order_id = 11401;

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('on-hold');
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('save')->never();

        $built = Mockery::mock(RostersCollection::class);
        $built->shouldReceive('count')->andReturn(1);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')->once()->andReturn($built);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(false);

        $repo = Mockery::mock(RosterRepository::class);

        $processor = new OrderProcessor(Mockery::spy(Logger::class), $repo, $builder);

        $this->assertTrue($processor->processOrder($order));
        $this->assertSame([], OrderProcessorTest::getScheduledOrders());
    }

    public function test_processing_missing_eligible_line_schedules_completion_check(): void {
        $order_id = 11402;
        $line_ok = 114021;
        $line_missing = 114022;

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('processing');
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('save')->never();

        $built = Mockery::mock(RostersCollection::class);
        $built->shouldReceive('count')->andReturn(1);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')->once()->andReturn($built);
        $builder->shouldReceive('getEligibleOrderLineItemIds')->with($order)->andReturn([$line_ok, $line_missing]);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(false);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('where')
            ->once()
            ->with(['order_id' => $order_id])
            ->andReturn(self::rosterRowsForItems($order_id, [$line_ok]));

        $processor = new OrderProcessor(Mockery::spy(Logger::class), $repo, $builder);

        $this->assertFalse($processor->processOrder($order));
        $this->assertCount(1, OrderProcessorTest::getScheduledOrders());
        $this->assertSame($order_id, OrderProcessorTest::getScheduledOrders()[0]['order_id']);
    }

    public function test_processing_successfully_completed_does_not_schedule(): void {
        $order_id = 11403;
        $line = 114031;

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('processing', 'processing', 'processing');
        $order->shouldReceive('update_status')->once()->with('completed', Mockery::type('string'));
        $order->shouldReceive('save')->once();

        $reloaded = Mockery::mock('WC_Order');
        $reloaded->shouldReceive('get_status')->andReturn('completed');
        OrderProcessorTest::setReloadedOrder($order_id, $reloaded);

        $built = Mockery::mock(RostersCollection::class);
        $built->shouldReceive('count')->andReturn(1);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')->once()->andReturn($built);
        $builder->shouldReceive('getEligibleOrderLineItemIds')->with($order)->andReturn([$line]);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(false);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('where')
            ->once()
            ->with(['order_id' => $order_id])
            ->andReturn(self::rosterRowsForItems($order_id, [$line]));

        $processor = new OrderProcessor(Mockery::spy(Logger::class), $repo, $builder);

        $this->assertTrue($processor->processOrder($order));
        $this->assertTrue($processor->wasLastOrderCompleted());
        $this->assertSame([], OrderProcessorTest::getScheduledOrders());
    }
}
