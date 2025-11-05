<?php
/**
 * WooCommerce Test Helper
 * 
 * Helper methods for creating test WooCommerce data
 */

namespace InterSoccer\ReportsRosters\Tests\Helpers;

use Mockery;

class WooCommerceTestHelper {
    
    /**
     * Create a mock WooCommerce order
     * 
     * @param array $data Order data
     * @return \Mockery\MockInterface
     */
    public static function createMockOrder(array $data = []) {
        $defaults = [
            'id' => 1,
            'status' => 'completed',
            'customer_id' => 1,
            'billing_email' => 'test@example.com',
            'billing_phone' => '+41 12 345 67 89',
            'billing_first_name' => 'John',
            'billing_last_name' => 'Doe',
            'items' => []
        ];
        
        $data = array_merge($defaults, $data);
        
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($data['id']);
        $order->shouldReceive('get_status')->andReturn($data['status']);
        $order->shouldReceive('get_customer_id')->andReturn($data['customer_id']);
        $order->shouldReceive('get_billing_email')->andReturn($data['billing_email']);
        $order->shouldReceive('get_billing_phone')->andReturn($data['billing_phone']);
        $order->shouldReceive('get_billing_first_name')->andReturn($data['billing_first_name']);
        $order->shouldReceive('get_billing_last_name')->andReturn($data['billing_last_name']);
        $order->shouldReceive('get_items')->andReturn($data['items']);
        
        return $order;
    }
    
    /**
     * Create a mock order item
     * 
     * @param array $data Item data
     * @return \Mockery\MockInterface
     */
    public static function createMockOrderItem(array $data = []) {
        $defaults = [
            'product_id' => 1,
            'variation_id' => 0,
            'quantity' => 1,
            'meta_data' => []
        ];
        
        $data = array_merge($defaults, $data);
        
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product_id')->andReturn($data['product_id']);
        $item->shouldReceive('get_variation_id')->andReturn($data['variation_id']);
        $item->shouldReceive('get_quantity')->andReturn($data['quantity']);
        $item->shouldReceive('get_meta_data')->andReturn($data['meta_data']);
        $item->shouldReceive('get_product')->andReturn(null);
        
        return $item;
    }
    
    /**
     * Create a mock product
     * 
     * @param array $data Product data
     * @return \Mockery\MockInterface
     */
    public static function createMockProduct(array $data = []) {
        $defaults = [
            'id' => 1,
            'name' => 'Test Product',
            'price' => 100.00,
            'attributes' => []
        ];
        
        $data = array_merge($defaults, $data);
        
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('get_id')->andReturn($data['id']);
        $product->shouldReceive('get_name')->andReturn($data['name']);
        $product->shouldReceive('get_price')->andReturn($data['price']);
        $product->shouldReceive('get_attributes')->andReturn($data['attributes']);
        
        return $product;
    }
    
    /**
     * Create a mock product variation
     * 
     * @param array $data Variation data
     * @return \Mockery\MockInterface
     */
    public static function createMockVariation(array $data = []) {
        $defaults = [
            'id' => 10,
            'attributes' => [],
            'price' => 100.00
        ];
        
        $data = array_merge($defaults, $data);
        
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($data['id']);
        $variation->shouldReceive('get_attributes')->andReturn($data['attributes']);
        $variation->shouldReceive('get_price')->andReturn($data['price']);
        $variation->shouldReceive('get_variation_attributes')->andReturn($data['attributes']);
        
        return $variation;
    }
}

