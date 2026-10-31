<?php
/**
 * On-hold orders get roster rows but must never have status changed by OrderProcessor.
 */

namespace InterSoccer\ReportsRosters\Tests\WooCommerce;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;
use Mockery;

class OrderProcessorOnHoldStatusTest extends TestCase {

    public function test_on_hold_order_builds_rosters_without_status_or_save(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(8801);
        $order->shouldReceive('get_status')->andReturn('on-hold');
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('save')->never();

        $rosters = Mockery::mock(RostersCollection::class);
        $rosters->shouldReceive('count')->andReturn(1);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with(8801, Mockery::type('array'))
            ->andReturn($rosters);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(false);

        $repo = Mockery::mock(RosterRepository::class);
        $logger = Mockery::spy(Logger::class);

        $result = (new OrderProcessor($logger, $repo, $builder))->processOrder($order);

        $this->assertTrue($result);
    }
}
