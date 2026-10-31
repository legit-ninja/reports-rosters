<?php
/**
 * Completed-order fast path requires a roster row for every eligible line item.
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

class OrderProcessorCompletedFastPathTest extends TestCase {

    private static function existingRowsCollection(int $order_id, int $order_item_id): RostersCollection {
        $row = new class($order_id, $order_item_id) {
            private $order_id;
            private $order_item_id;

            public function __construct(int $order_id, int $order_item_id) {
                $this->order_id = $order_id;
                $this->order_item_id = $order_item_id;
            }

            /** @return array<string,int> */
            public function toArray(): array {
                return [
                    'order_id' => $this->order_id,
                    'order_item_id' => $this->order_item_id,
                    'event_signature' => 'fast-path-test-signature',
                    'first_name' => 'Test',
                    'last_name' => 'Player',
                    'player_first_name' => 'Test',
                    'player_last_name' => 'Player',
                ];
            }
        };
        $collection = new RostersCollection();
        $prop = new \ReflectionProperty(AbstractCollection::class, 'items');
        $prop->setAccessible(true);
        $prop->setValue($collection, [$row]);

        return $collection;
    }

    public function test_all_eligible_lines_present_skips_build(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(7701);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('save')->never();

        $existing = self::existingRowsCollection(7701, 77011);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('where')->once()->with(['order_id' => 7701])->andReturn($existing);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('getEligibleOrderLineItemIds')->once()->with($order)->andReturn([77011]);
        $builder->shouldReceive('buildRosterFromOrder')->never();
        $builder->shouldReceive('hadOrderItemWriteFailures')->never();

        $logger = Mockery::spy(Logger::class);
        $processor = new OrderProcessor($logger, $repo, $builder);

        $this->assertTrue($processor->processOrder($order));
        $this->assertSame(1, $processor->getLastProcessedRosters()->count());
    }

    public function test_missing_eligible_line_runs_build(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(7702);
        $order->shouldReceive('get_status')->andReturn('completed', 'completed');
        $order->shouldReceive('update_status')->never();

        $existing = self::existingRowsCollection(7702, 77021);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('where')->once()->with(['order_id' => 7702])->andReturn($existing);

        $built = Mockery::mock(RostersCollection::class);
        $built->shouldReceive('count')->andReturn(2);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('getEligibleOrderLineItemIds')->once()->with($order)->andReturn([77021, 77022]);
        $builder->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with(7702, Mockery::type('array'))
            ->andReturn($built);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(false);

        $logger = Mockery::spy(Logger::class);
        $processor = new OrderProcessor($logger, $repo, $builder);

        $this->assertTrue($processor->processOrder($order));
        $this->assertSame($built, $processor->getLastProcessedRosters());
    }

    public function test_guest_completed_all_eligible_lines_skips_build(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(7703);
        $order->shouldReceive('get_customer_id')->andReturn(0);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('save')->never();

        $existing = self::existingRowsCollection(7703, 77031);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('where')->once()->with(['order_id' => 7703])->andReturn($existing);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('getEligibleOrderLineItemIds')->once()->with($order)->andReturn([77031]);
        $builder->shouldReceive('buildRosterFromOrder')->never();

        $processor = new OrderProcessor(Mockery::spy(Logger::class), $repo, $builder);

        $this->assertTrue($processor->processOrder($order));
        $this->assertSame(1, $processor->getLastProcessedRosters()->count());
    }

    public function test_guest_completed_missing_eligible_line_runs_build(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(7704);
        $order->shouldReceive('get_customer_id')->andReturn(0);
        $order->shouldReceive('get_status')->andReturn('completed', 'completed');
        $order->shouldReceive('update_status')->never();

        $existing = self::existingRowsCollection(7704, 77041);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('where')->once()->with(['order_id' => 7704])->andReturn($existing);

        $built = Mockery::mock(RostersCollection::class);
        $built->shouldReceive('count')->andReturn(2);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('getEligibleOrderLineItemIds')->once()->with($order)->andReturn([77041, 77042]);
        $builder->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with(7704, Mockery::type('array'))
            ->andReturn($built);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(false);

        $processor = new OrderProcessor(Mockery::spy(Logger::class), $repo, $builder);

        $this->assertTrue($processor->processOrder($order));
        $this->assertSame($built, $processor->getLastProcessedRosters());
    }
}
