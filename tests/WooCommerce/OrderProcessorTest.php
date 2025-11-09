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
    
    public function test_processor_initialization() {
        $this->assertInstanceOf(OrderProcessor::class, $this->processor);
    }
    
    public function test_process_processing_order_marks_complete_and_records_rosters() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->twice()->andReturn('processing', 'processing');
        $order->shouldReceive('update_status')
            ->once()
            ->with('completed', Mockery::type('string'));
        
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
        $orderSuccess->shouldReceive('get_status')->twice()->andReturn('processing', 'processing');
        $orderSuccess->shouldReceive('update_status')
            ->once()
            ->with('completed', Mockery::type('string'));
        
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

