<?php
/**
 * Order Model
 * 
 * Data model for WooCommerce order records in InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Data\Models
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\Data\Models;

defined('ABSPATH') or die('Restricted access');

class Order {
    
    public $id;
    public $order_id;
    public $order_date;
    public $customer_id;
    public $customer_email;
    public $customer_name;
    public $total;
    public $status;
    public $payment_method;
    public $payment_status;
    public $billing_address;
    public $shipping_address;
    public $items;
    public $meta_data;
    public $discount_total;
    public $discount_codes;
    
    /**
     * Constructor
     * 
     * @param array|WC_Order $data Order data or WooCommerce order object
     */
    public function __construct($data = []) {
        if ($data instanceof \WC_Order) {
            $this->load_from_wc_order($data);
        } else {
            foreach ($data as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }
    
    /**
     * Load data from WooCommerce order object
     * 
     * @param WC_Order $wc_order
     */
    private function load_from_wc_order($wc_order) {
        $this->id = $wc_order->get_id();
        $this->order_id = $wc_order->get_id();
        $this->order_date = $wc_order->get_date_created()->format('Y-m-d H:i:s');
        $this->customer_id = $wc_order->get_customer_id();
        $this->customer_email = $wc_order->get_billing_email();
        $this->customer_name = $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name();
        $this->total = $wc_order->get_total();
        $this->status = $wc_order->get_status();
        $this->payment_method = $wc_order->get_payment_method();
        $this->payment_status = $wc_order->is_paid() ? 'paid' : 'pending';
        $this->discount_total = $wc_order->get_total_discount();
        
        // Get billing address
        $this->billing_address = [
            'first_name' => $wc_order->get_billing_first_name(),
            'last_name' => $wc_order->get_billing_last_name(),
            'company' => $wc_order->get_billing_company(),
            'address_1' => $wc_order->get_billing_address_1(),
            'address_2' => $wc_order->get_billing_address_2(),
            'city' => $wc_order->get_billing_city(),
            'postcode' => $wc_order->get_billing_postcode(),
            'country' => $wc_order->get_billing_country(),
            'phone' => $wc_order->get_billing_phone(),
            'email' => $wc_order->get_billing_email()
        ];
        
        // Get order items
        $this->items = [];
        foreach ($wc_order->get_items() as $item_id => $item) {
            $this->items[] = [
                'id' => $item_id,
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'subtotal' => $item->get_subtotal(),
                'total' => $item->get_total(),
                'meta_data' => $item->get_meta_data()
            ];
        }
        
        // Get meta data
        $this->meta_data = $wc_order->get_meta_data();
        
        // Get discount codes
        $this->discount_codes = $wc_order->get_coupon_codes();
    }
    
    /**
     * Get formatted total
     * 
     * @return string
     */
    public function get_formatted_total() {
        return number_format($this->total, 2) . ' CHF';
    }
    
    /**
     * Check if order has discount
     * 
     * @return bool
     */
    public function has_discount() {
        return $this->discount_total > 0 || !empty($this->discount_codes);
    }
    
    /**
     * Get discount percentage
     * 
     * @return float
     */
    public function get_discount_percentage() {
        if ($this->total <= 0 || $this->discount_total <= 0) {
            return 0;
        }
        
        $original_total = $this->total + $this->discount_total;
        return ($this->discount_total / $original_total) * 100;
    }
    
    /**
     * Convert to array
     * 
     * @return array
     */
    public function to_array() {
        return get_object_vars($this);
    }
}