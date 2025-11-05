<?php
/**
 * WooCommerce Mock for Testing
 * 
 * Basic WooCommerce class and function mocks for testing
 */

if (!class_exists('WooCommerce')) {
    class WooCommerce {
        public $version = '8.0.0';
        
        public function __construct() {
            // Mock initialization
        }
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id) {
        return Mockery::mock('WC_Order');
    }
}

if (!function_exists('wc_get_orders')) {
    function wc_get_orders($args = []) {
        return [];
    }
}

if (!function_exists('wc_get_product')) {
    function wc_get_product($product_id) {
        return Mockery::mock('WC_Product');
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        public function get_id() { return 1; }
        public function get_status() { return 'completed'; }
        public function get_customer_id() { return 1; }
        public function get_billing_email() { return 'test@example.com'; }
        public function get_billing_phone() { return '+41 12 345 67 89'; }
        public function get_billing_first_name() { return 'John'; }
        public function get_billing_last_name() { return 'Doe'; }
        public function get_items() { return []; }
    }
}

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        public function get_product_id() { return 1; }
        public function get_variation_id() { return 0; }
        public function get_quantity() { return 1; }
        public function get_product() { return null; }
        public function get_meta_data() { return []; }
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product {
        public function get_id() { return 1; }
        public function get_name() { return 'Test Product'; }
        public function get_price() { return 100.00; }
        public function get_attributes() { return []; }
    }
}

if (!class_exists('WC_Product_Variation')) {
    class WC_Product_Variation extends WC_Product {
        public function get_attributes() { return []; }
        public function get_variation_attributes() { return []; }
    }
}

