<?php
/**
 * Processing orders complete only when every eligible line has a roster row and the build had no write failures.
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

class OrderProcessorProcessingCompletionGateTest extends TestCase {

    /** @var array<int, array<string, mixed>> */
    private $errorLogEntries = [];

    protected function setUp(): void {
        parent::setUp();
        $this->errorLogEntries = [];

        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = [OrderProcessorTest::class, 'getReloadedOrder'];
    }

    protected function tearDown(): void {
        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = null;
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

    private function recordingLogger(): Logger {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning')->andReturn(null);
        $logger->shouldReceive('error')->andReturnUsing(function ($message, $context = []) {
            $this->errorLogEntries[] = [
                'message' => $message,
                'context' => is_array($context) ? $context : [],
            ];
            return null;
        });

        return $logger;
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function hasErrorMessage(array $entries, string $needle): bool {
        foreach ($entries as $entry) {
            if (isset($entry['message']) && str_contains((string) $entry['message'], $needle)) {
                return true;
            }
        }

        return false;
    }

    public function test_partial_line_write_failure_leaves_processing_without_order_writes(): void {
        $order_id = 11201;
        $line_ok = 112011;
        $line_fail = 112012;

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('processing');
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('save')->never();
        $order->shouldReceive('add_order_note')->never();
        $order->shouldReceive('update_meta_data')->never();

        $built = Mockery::mock(RostersCollection::class);
        $built->shouldReceive('count')->andReturn(1);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with($order_id, Mockery::type('array'))
            ->andReturnUsing(function () use ($built, $line_fail, $order_id) {
                $this->errorLogEntries[] = [
                    'message' => 'Order line roster write failed',
                    'context' => ['order_id' => $order_id, 'item_id' => $line_fail],
                ];
                return $built;
            });
        $builder->shouldReceive('getEligibleOrderLineItemIds')->with($order)->andReturn([$line_ok, $line_fail]);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(true);
        $builder->shouldReceive('getBuildStatistics')->andReturn(['item_write_failures' => 1]);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('where')
            ->once()
            ->with(['order_id' => $order_id])
            ->andReturn(self::rosterRowsForItems($order_id, [$line_ok]));

        $processor = new OrderProcessor($this->recordingLogger(), $repo, $builder);

        $this->assertFalse($processor->processOrder($order));
        $this->assertFalse($processor->wasLastOrderCompleted());
        $this->assertSame(1, $builder->getBuildStatistics()['item_write_failures']);
        $this->assertTrue($this->hasErrorMessage($this->errorLogEntries, 'Order line roster write failed'));
        $this->assertTrue($this->hasErrorMessage($this->errorLogEntries, 'missing roster rows'));
    }

    public function test_retry_after_all_lines_have_rows_completes_once(): void {
        $order_id = 11202;
        $line_a = 112021;
        $line_b = 112022;

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('processing', 'processing', 'processing');
        $order->shouldReceive('add_order_note')->never();
        $order->shouldReceive('update_meta_data')->never();

        $built = Mockery::mock(RostersCollection::class);
        $built->shouldReceive('count')->andReturn(2);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')
            ->twice()
            ->with($order_id, Mockery::type('array'))
            ->andReturn($built);
        $builder->shouldReceive('getEligibleOrderLineItemIds')->with($order)->andReturn([$line_a, $line_b]);
        $builder->shouldReceive('getBuildStatistics')->andReturn(['item_write_failures' => 0]);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(true, false);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('where')
            ->once()
            ->with(['order_id' => $order_id])
            ->andReturn(self::rosterRowsForItems($order_id, [$line_a]));
        $repo->shouldReceive('where')
            ->once()
            ->with(['order_id' => $order_id])
            ->andReturn(self::rosterRowsForItems($order_id, [$line_a, $line_b]));

        $order->shouldReceive('update_status')
            ->once()
            ->with('completed', Mockery::type('string'));
        $order->shouldReceive('save')->once();

        $reloaded = Mockery::mock('WC_Order');
        $reloaded->shouldReceive('get_status')->andReturn('completed');
        OrderProcessorTest::setReloadedOrder($order_id, $reloaded);

        $processor = new OrderProcessor(Mockery::spy(Logger::class), $repo, $builder);

        $this->assertFalse($processor->processOrder($order));
        $this->assertTrue($processor->processOrder($order));
        $this->assertTrue($processor->wasLastOrderCompleted());
    }

    public function test_on_hold_never_updates_status_even_when_all_lines_succeed(): void {
        $order_id = 11203;
        $line = 112031;

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('on-hold');
        $order->shouldReceive('update_status')->never();
        $order->shouldReceive('save')->never();

        $built = Mockery::mock(RostersCollection::class);
        $built->shouldReceive('count')->andReturn(1);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with($order_id, Mockery::type('array'))
            ->andReturn($built);
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(false);
        $builder->shouldReceive('getEligibleOrderLineItemIds')->never();

        $repo = Mockery::mock(RosterRepository::class);

        $processor = new OrderProcessor(Mockery::spy(Logger::class), $repo, $builder);

        $this->assertTrue($processor->processOrder($order));
        $this->assertFalse($processor->wasLastOrderCompleted());
    }
}
