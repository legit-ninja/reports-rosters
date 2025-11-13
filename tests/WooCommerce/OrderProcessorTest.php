<?php
/**
 * OrderProcessor Test
 */

namespace InterSoccer\ReportsRosters\Tests\WooCommerce;

use Mockery;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;

class OrderProcessorTest extends TestCase {
    private static $reloadedOrders = [];
    private static $scheduledOrders = [];
    private $processor;
    private $logger;
    private $rosterRepository;
    private $rosterBuilder;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->logger = Mockery::spy(Logger::class);
        $this->rosterRepository = Mockery::mock(RosterRepository::class);
        $this->rosterBuilder = Mockery::mock(RosterBuilder::class);
        
        $this->processor = new OrderProcessor(
            $this->logger,
            $this->rosterRepository,
            $this->rosterBuilder
        );
    }

    protected function tearDown(): void {
        self::$reloadedOrders = [];
        self::$scheduledOrders = [];
        parent::tearDown();
    }

    public static function setReloadedOrder($orderId, $order) {
        self::$reloadedOrders[$orderId] = $order;
    }

    public static function getReloadedOrder($orderId) {
        return self::$reloadedOrders[$orderId] ?? null;
    }

    public static function recordScheduledOrder($orderId, $delay) {
        self::$scheduledOrders[] = ['order_id' => $orderId, 'delay' => $delay];
    }

    public static function getScheduledOrders() {
        return self::$scheduledOrders;
    }
    
    public function test_processor_initialization() {
        $this->assertInstanceOf(OrderProcessor::class, $this->processor);
    }
    
    public function test_process_processing_order_marks_complete_and_records_rosters() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('processing', 'processing', 'completed');
        $order->shouldReceive('update_status')
            ->once()
            ->with('completed', Mockery::type('string'));
        $order->shouldReceive('save')->once();

        $reloaded = Mockery::mock('WC_Order');
        $reloaded->shouldReceive('get_status')->andReturn('completed');
        self::setReloadedOrder(123, $reloaded);
        
        $rosters = Mockery::mock(RostersCollection::class);
        $rosters->shouldReceive('count')->andReturn(2);
        
        $this->rosterBuilder
            ->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with(123, Mockery::type('array'))
            ->andReturn($rosters);
        
        $result = $this->processor->processOrder($order);
        
        $this->assertTrue($result, 'Order should process successfully');
        $this->assertTrue($this->processor->wasLastOrderCompleted(), 'Order should be marked as completed');
        $this->assertSame($rosters, $this->processor->getLastProcessedRosters(), 'Last rosters should match builder output');
        $this->assertSame(2, $this->processor->getLastProcessedRosters()->count(), 'Roster count should be persisted');
        $this->assertSame([], self::getScheduledOrders(), 'No fallback scheduling expected for successful completion');
    }
    
    public function test_process_completed_order_does_not_recomplete() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(55);
        $order->shouldReceive('get_status')->twice()->andReturn('completed', 'completed');
        $order->shouldReceive('update_status')->never();
        
        $rosters = Mockery::mock(RostersCollection::class);
        $rosters->shouldReceive('count')->andReturn(1);
        
        $this->rosterBuilder
            ->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with(55, Mockery::type('array'))
            ->andReturn($rosters);
        
        $result = $this->processor->processOrder($order);
        
        $this->assertTrue($result, 'Completed orders should still process');
        $this->assertFalse($this->processor->wasLastOrderCompleted(), 'Existing completed orders should not be recompleted');
    }
    
    public function test_process_order_with_pending_status_is_skipped() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(77);
        $order->shouldReceive('get_status')->twice()->andReturn('pending', 'pending');
        $order->shouldReceive('update_status')->never();
        
        $this->rosterBuilder->shouldReceive('buildRosterFromOrder')->never();
        
        $result = $this->processor->processOrder($order);
        
        $this->assertFalse($result, 'Pending orders should be skipped');
        $this->assertFalse($this->processor->wasLastOrderCompleted());
    }
    
    public function test_process_batch_returns_summary_with_failures() {
        $orderSuccess = Mockery::mock('WC_Order');
        $orderSuccess->shouldReceive('get_id')->andReturn(10);
        $orderSuccess->shouldReceive('get_status')->andReturn('processing', 'processing', 'completed');
        $orderSuccess->shouldReceive('update_status')
            ->once()
            ->with('completed', Mockery::type('string'));
        $orderSuccess->shouldReceive('save')->once();

        $reloaded = Mockery::mock('WC_Order');
        $reloaded->shouldReceive('get_status')->andReturn('completed');
        self::setReloadedOrder(10, $reloaded);
        
        $rosters = Mockery::mock(RostersCollection::class);
        $rosters->shouldReceive('count')->andReturn(3);
        
        $this->rosterBuilder
            ->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with(10, Mockery::type('array'))
            ->andReturn($rosters);
        
        $orderFailure = Mockery::mock('WC_Order');
        $orderFailure->shouldReceive('get_status')->twice()->andReturn('pending', 'pending');
        $orderFailure->shouldReceive('update_status')->never();
        $orderFailure->shouldReceive('get_id')->andReturn(20);
        
        $summary = $this->processor->process_batch([$orderSuccess, $orderFailure]);
        
        $this->assertFalse($summary['success'], 'Summary should be unsuccessful due to failed orders');
        $this->assertSame(1, $summary['processed_orders']);
        $this->assertSame(3, $summary['roster_entries']);
        $this->assertSame(1, $summary['completed_orders']);
        $this->assertSame([20], $summary['failed_orders']);
        $this->assertNotEmpty($summary['message']);
    }

    public function test_process_order_schedules_fallback_when_status_not_persisted() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(999);
        $order->shouldReceive('get_status')->andReturn('processing', 'processing', 'processing');
        $order->shouldReceive('update_status')
            ->once()
            ->with('completed', Mockery::type('string'));
        $order->shouldReceive('save')->once();

        $reloaded = Mockery::mock('WC_Order');
        $reloaded->shouldReceive('get_status')->andReturn('processing');
        self::setReloadedOrder(999, $reloaded);

        $rosters = Mockery::mock(RostersCollection::class);
        $rosters->shouldReceive('count')->andReturn(1);

        $this->rosterBuilder
            ->shouldReceive('buildRosterFromOrder')
            ->once()
            ->with(999, Mockery::type('array'))
            ->andReturn($rosters);

        $result = $this->processor->processOrder($order);

        $this->assertTrue($result, 'Order processing should still return true when status fails to persist');
        $this->assertFalse($this->processor->wasLastOrderCompleted(), 'Status mismatch should not mark order as completed');
        $this->assertCount(1, self::getScheduledOrders(), 'Fallback scheduling should be triggered');
        $scheduled = self::getScheduledOrders()[0];
        $this->assertSame(999, $scheduled['order_id']);
    }
    
    public function test_should_process_processing_status() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_status')->andReturn('processing');
        
        $this->assertTrue($this->processor->shouldProcess($order));
    }
    
    public function test_skip_pending_orders() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_status')->andReturn('pending');
        
        $result = $this->processor->shouldProcess($order);
        
        $this->assertFalse($result);
    }
}

namespace {
    function wc_get_order($order_id) {
        return \InterSoccer\ReportsRosters\Tests\WooCommerce\OrderProcessorTest::getReloadedOrder($order_id);
    }

    function intersoccer_schedule_order_completion_check($order_id, $delay = null) {
        \InterSoccer\ReportsRosters\Tests\WooCommerce\OrderProcessorTest::recordScheduledOrder($order_id, $delay);
    }
}

