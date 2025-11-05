<?php
/**
 * PricingCalculator Test
 * 
 * Tests for the PricingCalculator service
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\PricingCalculator;
use InterSoccer\ReportsRosters\Core\Logger;
use Mockery;

class PricingCalculatorTest extends TestCase {
    
    private $calculator;
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->logger = Mockery::mock(Logger::class);
        $this->logger->shouldReceive('debug')->andReturn(null);
        $this->logger->shouldReceive('info')->andReturn(null);
        $this->logger->shouldReceive('warning')->andReturn(null);
        $this->logger->shouldReceive('error')->andReturn(null);
        
        $this->calculator = new PricingCalculator($this->logger);
    }
    
    public function test_calculator_initialization() {
        $this->assertInstanceOf(PricingCalculator::class, $this->calculator);
    }
    
    public function test_calculate_cart_pricing_with_single_camp() {
        $cart_items = [
            [
                'product_id' => 1,
                'variation_id' => 10,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 1,
                'activity_type' => 'Camp',
                'variation_data' => ['Activity Type' => 'Camp']
            ]
        ];
        
        $result = $this->calculator->calculate_cart_pricing($cart_items, 1);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('original_total', $result);
        $this->assertArrayHasKey('final_total', $result);
        $this->assertArrayHasKey('total_savings', $result);
        $this->assertEquals(100.00, $result['original_total']);
        $this->assertEquals(100.00, $result['final_total']); // No discounts for single child
    }
    
    public function test_camp_combo_discount_second_child() {
        $cart_items = [
            [
                'product_id' => 1,
                'variation_id' => 10,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 1, // First child
                'activity_type' => 'Camp',
                'variation_data' => ['Activity Type' => 'Camp']
            ],
            [
                'product_id' => 1,
                'variation_id' => 10,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 2, // Second child
                'activity_type' => 'Camp',
                'variation_data' => ['Activity Type' => 'Camp']
            ]
        ];
        
        $result = $this->calculator->calculate_cart_pricing($cart_items, 1);
        
        // Second child should get 20% discount
        $this->assertEquals(200.00, $result['original_total']);
        $this->assertEquals(180.00, $result['final_total']); // 100 + 80
        $this->assertEquals(20.00, $result['total_savings']);
    }
    
    public function test_camp_combo_discount_third_child() {
        $cart_items = [
            [
                'product_id' => 1,
                'variation_id' => 10,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 1,
                'activity_type' => 'Camp',
                'variation_data' => ['Activity Type' => 'Camp']
            ],
            [
                'product_id' => 1,
                'variation_id' => 10,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 2,
                'activity_type' => 'Camp',
                'variation_data' => ['Activity Type' => 'Camp']
            ],
            [
                'product_id' => 1,
                'variation_id' => 10,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 3,
                'activity_type' => 'Camp',
                'variation_data' => ['Activity Type' => 'Camp']
            ]
        ];
        
        $result = $this->calculator->calculate_cart_pricing($cart_items, 1);
        
        // Second child: 20% off, Third child: 25% off
        $this->assertEquals(300.00, $result['original_total']);
        $this->assertEquals(255.00, $result['final_total']); // 100 + 80 + 75
        $this->assertEquals(45.00, $result['total_savings']);
    }
    
    public function test_course_combo_discount_second_child() {
        $cart_items = [
            [
                'product_id' => 2,
                'variation_id' => 20,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 1,
                'activity_type' => 'Course',
                'season' => 'Autumn 2024',
                'variation_data' => ['Activity Type' => 'Course']
            ],
            [
                'product_id' => 2,
                'variation_id' => 20,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 2,
                'activity_type' => 'Course',
                'season' => 'Autumn 2024',
                'variation_data' => ['Activity Type' => 'Course']
            ]
        ];
        
        $result = $this->calculator->calculate_cart_pricing($cart_items, 1);
        
        // Second child course: 20% off
        $this->assertEquals(200.00, $result['original_total']);
        $this->assertEquals(180.00, $result['final_total']);
        $this->assertEquals(20.00, $result['total_savings']);
    }
    
    public function test_course_same_season_discount() {
        $cart_items = [
            [
                'product_id' => 2,
                'variation_id' => 20,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 1,
                'activity_type' => 'Course',
                'season' => 'Autumn 2024',
                'variation_data' => ['Activity Type' => 'Course']
            ],
            [
                'product_id' => 3,
                'variation_id' => 30,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 1, // Same child, same season
                'activity_type' => 'Course',
                'season' => 'Autumn 2024',
                'variation_data' => ['Activity Type' => 'Course']
            ]
        ];
        
        $result = $this->calculator->calculate_cart_pricing($cart_items, 1);
        
        // Second course in same season for same child: 50% off
        $this->assertEquals(200.00, $result['original_total']);
        $this->assertEquals(150.00, $result['final_total']); // 100 + 50
        $this->assertEquals(50.00, $result['total_savings']);
    }
    
    public function test_sibling_discount() {
        $cart_items = [
            [
                'product_id' => 1,
                'variation_id' => 10,
                'quantity' => 1,
                'price' => 120.00,
                'assigned_player' => 1,
                'activity_type' => 'Camp',
                'variation_data' => ['Activity Type' => 'Camp']
            ],
            [
                'product_id' => 1,
                'variation_id' => 10,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 2,
                'activity_type' => 'Camp',
                'variation_data' => ['Activity Type' => 'Camp']
            ]
        ];
        
        $result = $this->calculator->calculate_cart_pricing($cart_items, 1);
        
        // Should apply sibling discount to lesser amount
        $this->assertLessThan(220.00, $result['final_total']);
    }
    
    public function test_prorated_pricing_for_mid_season_course() {
        $start_date = date('Y-m-d', strtotime('-4 weeks'));
        $end_date = date('Y-m-d', strtotime('+4 weeks'));
        
        $cart_items = [
            [
                'product_id' => 2,
                'variation_id' => 20,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 1,
                'activity_type' => 'Course',
                'start_date' => $start_date,
                'end_date' => $end_date,
                'variation_data' => ['Activity Type' => 'Course']
            ]
        ];
        
        $result = $this->calculator->calculate_cart_pricing($cart_items, 1);
        
        // Should have pro-rated discount since course is already in progress
        $this->assertLessThan(100.00, $result['final_total']);
        $this->assertGreaterThan(0, $result['total_savings']);
    }
    
    public function test_get_pricing_summary() {
        $pricing_result = [
            'original_total' => 300.00,
            'final_total' => 255.00,
            'total_savings' => 45.00,
            'discounts_applied' => [
                ['type' => 'camp_combo', 'amount' => 20.00],
                ['type' => 'camp_combo', 'amount' => 25.00]
            ]
        ];
        
        $summary = $this->calculator->get_pricing_summary($pricing_result);
        
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('subtotal', $summary);
        $this->assertArrayHasKey('discounts', $summary);
        $this->assertArrayHasKey('total', $summary);
        $this->assertArrayHasKey('savings_percentage', $summary);
        $this->assertEquals('300.00', $summary['subtotal']);
        $this->assertEquals('45.00', $summary['discounts']);
        $this->assertEquals('255.00', $summary['total']);
        $this->assertEquals(15.0, $summary['savings_percentage']);
    }
    
    public function test_edge_case_zero_price() {
        $cart_items = [
            [
                'product_id' => 1,
                'variation_id' => 10,
                'quantity' => 1,
                'price' => 0.00,
                'assigned_player' => 1,
                'activity_type' => 'Camp',
                'variation_data' => ['Activity Type' => 'Camp']
            ]
        ];
        
        $result = $this->calculator->calculate_cart_pricing($cart_items, 1);
        
        $this->assertEquals(0.00, $result['original_total']);
        $this->assertEquals(0.00, $result['final_total']);
    }
    
    public function test_edge_case_empty_cart() {
        $this->expectException(\Exception::class);
        
        $this->calculator->calculate_cart_pricing([], 1);
    }
    
    public function test_invalid_user_id() {
        $cart_items = [
            [
                'product_id' => 1,
                'price' => 100.00,
                'activity_type' => 'Camp'
            ]
        ];
        
        $this->expectException(\Exception::class);
        
        $this->calculator->calculate_cart_pricing($cart_items, -1);
    }
    
    public function test_multiple_discount_stacking() {
        // Test that discounts stack correctly
        $cart_items = [
            [
                'product_id' => 2,
                'variation_id' => 20,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 1,
                'activity_type' => 'Course',
                'season' => 'Autumn 2024',
                'start_date' => date('Y-m-d', strtotime('-2 weeks')),
                'end_date' => date('Y-m-d', strtotime('+10 weeks')),
                'variation_data' => ['Activity Type' => 'Course']
            ],
            [
                'product_id' => 3,
                'variation_id' => 30,
                'quantity' => 1,
                'price' => 100.00,
                'assigned_player' => 1,
                'activity_type' => 'Course',
                'season' => 'Autumn 2024',
                'start_date' => date('Y-m-d', strtotime('-2 weeks')),
                'end_date' => date('Y-m-d', strtotime('+10 weeks')),
                'variation_data' => ['Activity Type' => 'Course']
            ]
        ];
        
        $result = $this->calculator->calculate_cart_pricing($cart_items, 1);
        
        // Should have both same-season discount AND pro-rated discount
        $this->assertGreaterThan(0, $result['total_savings']);
        $this->assertLessThan(200.00, $result['final_total']);
    }
}

