<?php
/**
 * OrderProcessor Test
 */

namespace InterSoccer\ReportsRosters\Tests\WooCommerce;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;
use Brain\Monkey\Functions;
use Mockery;

class OrderProcessorTest extends TestCase {
    private $processor;
    
    protected function setUp(): void {
        parent::setUp();
        $this->processor = new OrderProcessor();
    }
    
    public function test_processor_initialization() {
        $this->assertInstanceOf(OrderProcessor::class, $this->processor);
    }
    
    public function test_process_completed_order() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1);
        $order->shouldReceive('get_status')->andReturn('completed');
        
        Functions\expect('wc_get_order')->andReturn($order);
        
        $result = $this->processor->processOrder(1);
        
        $this->assertTrue($result);
    }
    
    public function test_skip_pending_orders() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_status')->andReturn('pending');
        
        $result = $this->processor->shouldProcess($order);
        
        $this->assertFalse($result);
    }
}

