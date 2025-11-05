<?php
/**
 * ProductVariationHandler Test
 */

namespace InterSoccer\ReportsRosters\Tests\WooCommerce;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\WooCommerce\ProductVariationHandler;
use Mockery;

class ProductVariationHandlerTest extends TestCase {
    private $handler;
    
    protected function setUp(): void {
        parent::setUp();
        $this->handler = new ProductVariationHandler();
    }
    
    public function test_handler_initialization() {
        $this->assertInstanceOf(ProductVariationHandler::class, $this->handler);
    }
    
    public function test_extract_activity_type() {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_attribute')->with('pa_activity-type')->andReturn('Camp');
        
        $result = $this->handler->extractActivityType($variation);
        
        $this->assertEquals('Camp', $result);
    }
    
    public function test_extract_venue() {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_attribute')->with('pa_venue')->andReturn('Zurich');
        
        $result = $this->handler->extractVenue($variation);
        
        $this->assertEquals('Zurich', $result);
    }
}

