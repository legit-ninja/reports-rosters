<?php
/**
 * DiscountCalculator Test
 */

namespace InterSoccer\ReportsRosters\Tests\WooCommerce;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\WooCommerce\DiscountCalculator;
use Brain\Monkey\Functions;
use Mockery;

class DiscountCalculatorTest extends TestCase {
    private $calculator;
    
    protected function setUp(): void {
        parent::setUp();
        $this->calculator = new DiscountCalculator();
    }
    
    public function test_calculator_initialization() {
        $this->assertInstanceOf(DiscountCalculator::class, $this->calculator);
    }
    
    public function test_calculate_cart_discount() {
        $cart = Mockery::mock('WC_Cart');
        $cart->shouldReceive('get_cart')->andReturn([]);
        
        Functions\when('WC')->justReturn((object)['cart' => $cart]);
        
        $discount = $this->calculator->calculateCartDiscount($cart);
        
        $this->assertIsFloat($discount);
    }
    
    public function test_apply_camp_discount() {
        $result = $this->calculator->applyCampDiscount(100.00, 2);
        $this->assertEquals(80.00, $result); // 20% off for 2nd child
    }
    
    public function test_apply_course_discount() {
        $result = $this->calculator->applyCourseDiscount(100.00, 2);
        $this->assertEquals(80.00, $result); // 20% off for 2nd child
    }
}

