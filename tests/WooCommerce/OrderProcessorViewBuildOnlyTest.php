<?php
/**
 * Roster detail view / on-demand build must populate rows without completing the order.
 */

namespace InterSoccer\ReportsRosters\Tests\WooCommerce;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;
use Mockery;

class OrderProcessorViewBuildOnlyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        global $intersoccer_test_schedule_completion_callback;
        $intersoccer_test_schedule_completion_callback = [OrderProcessorTest::class, 'recordScheduledOrder'];

        $ref = new \ReflectionClass(OrderProcessorTest::class);
        $prop = $ref->getProperty('scheduledOrders');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    protected function tearDown(): void {
        global $intersoccer_test_schedule_completion_callback;
        $intersoccer_test_schedule_completion_callback = null;

        $ref = new \ReflectionClass(OrderProcessorTest::class);
        $prop = $ref->getProperty('scheduledOrders');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
        parent::tearDown();
    }

    public function test_complete_order_false_builds_without_status_writes_or_reschedule(): void {
        $order_id = 11301;

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('processing');
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('save')->never();
        $order->shouldReceive('add_order_note')->never();
        $order->shouldReceive('update_meta_data')->never();

        $built = Mockery::mock(RostersCollection::class);
        $built->shouldReceive('count')->andReturn(2);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with($order_id, Mockery::type('array'))
            ->andReturn($built);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(false);
        $builder->shouldReceive('getEligibleOrderLineItemIds')->never();

        $repo = Mockery::mock(RosterRepository::class);

        $processor = new OrderProcessor(Mockery::spy(Logger::class), $repo, $builder);

        $this->assertTrue($processor->processOrder($order, ['complete_order' => false]));
        $this->assertFalse($processor->wasLastOrderCompleted());
        $this->assertSame([], OrderProcessorTest::getScheduledOrders());
    }
}
