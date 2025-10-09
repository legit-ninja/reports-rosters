<?php
/**
 * InterSoccer Orders Collection
 * 
 * Collection class for managing groups of WooCommerce orders.
 * Provides filtering, sorting, and aggregation functionality for order data.
 * 
 * @package InterSoccer_Reports_Rosters
 * @subpackage Data\Collections
 * @version 1.0.0
 */

namespace InterSoccer\ReportsRosters\Data\Collections;

use InterSoccer\ReportsRosters\Data\Models\Order;
use InterSoccer\ReportsRosters\Utils\DateHelper;
use InterSoccer\ReportsRosters\Utils\ValidationHelper;

if (!defined('ABSPATH')) {
    exit;
}

class OrdersCollection extends AbstractCollection {

    /**
     * Add order to collection
     * 
     * @param Order $order Order model
     * 
     * @return self
     */
    public function add_order(Order $order) {
        return $this->add($order);
    }

    /**
     * Get order by ID
     * 
     * @param int $order_id Order ID
     * 
     * @return Order|null Order model or null if not found
     */
    public function get_order($order_id) {
        return $this->firstWhere('id', $order_id);
    }

    /**
     * Check if order exists in collection
     * 
     * @param int $order_id Order ID
     * 
     * @return bool True if order exists
     */
    public function has_order($order_id) {
        return $this->contains(function($order) use ($order_id) {
            return $order->get_id() === $order_id;
        });
    }

    /**
     * Filter orders by status
     * 
     * @param string|array $status Order status(es)
     * 
     * @return static New filtered collection
     */
    public function filter_by_status($status) {
        $statuses = is_array($status) ? $status : [$status];
        
        return $this->filter(function($order) use ($statuses) {
            return in_array($order->get_status(), $statuses);
        });
    }

    /**
     * Filter orders by customer
     * 
     * @param int $customer_id Customer user ID
     * 
     * @return static New filtered collection
     */
    public function filter_by_customer($customer_id) {
        return $this->where('customer_id', $customer_id);
    }

    /**
     * Filter orders by date range
     * 
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date   End date (Y-m-d format)
     * 
     * @return static New filtered collection
     */
    public function filter_by_date_range($start_date, $end_date) {
        return $this->filter(function($order) use ($start_date, $end_date) {
            $order_date = $order->get_date_created()->format('Y-m-d');
            return $order_date >= $start_date && $order_date <= $end_date;
        });
    }

    /**
     * Filter orders by activity type
     * 
     * @param string $activity_type Activity type (Camp, Course, etc.)
     * 
     * @return static New filtered collection
     */
    public function filter_by_activity_type($activity_type) {
        return $this->filter(function($order) use ($activity_type) {
            return $order->has_activity_type($activity_type);
        });
    }

    /**
     * Filter orders by season
     * 
     * @param string $season Season name
     * 
     * @return static New filtered collection
     */
    public function filter_by_season($season) {
        return $this->filter(function($order) use ($season) {
            return $order->has_season($season);
        });
    }

    /**
     * Filter orders by venue
     * 
     * @param string $venue Venue name
     * 
     * @return static New filtered collection
     */
    public function filter_by_venue($venue) {
        return $this->filter(function($order) use ($venue) {
            return $order->has_venue($venue);
        });
    }

    /**
     * Filter orders containing specific product
     * 
     * @param int $product_id Product ID
     * 
     * @return static New filtered collection
     */
    public function filter_by_product($product_id) {
        return $this->filter(function($order) use ($product_id) {
            return $order->contains_product($product_id);
        });
    }

    /**
     * Filter orders with assigned players
     * 
     * @return static New filtered collection
     */
    public function filter_with_players() {
        return $this->filter(function($order) {
            return $order->has_assigned_players();
        });
    }

    /**
     * Filter orders with medical conditions
     * 
     * @return static New filtered collection
     */
    public function filter_with_medical_conditions() {
        return $this->filter(function($order) {
            return $order->has_medical_conditions();
        });
    }

    /**
     * Sort orders by date created
     * 
     * @param string $direction Sort direction (ASC|DESC)
     * 
     * @return static New sorted collection
     */
    public function sort_by_date($direction = 'DESC') {
        return $this->sortBy(function($order) {
            return $order->get_date_created()->getTimestamp();
        }, SORT_NUMERIC, $direction === 'DESC');
    }

    /**
     * Sort orders by total amount
     * 
     * @param string $direction Sort direction (ASC|DESC)
     * 
     * @return static New sorted collection
     */
    public function sort_by_total($direction = 'DESC') {
        return $this->sortBy(function($order) {
            return $order->get_total();
        }, SORT_NUMERIC, $direction === 'DESC');
    }

    /**
     * Sort orders by customer name
     * 
     * @param string $direction Sort direction (ASC|DESC)
     * 
     * @return static New sorted collection
     */
    public function sort_by_customer($direction = 'ASC') {
        return $this->sortBy(function($order) {
            return $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        }, SORT_STRING, $direction === 'DESC');
    }

    /**
     * Get total revenue from all orders
     * 
     * @return float Total revenue
     */
    public function get_total_revenue() {
        return $this->sum(function($order) {
            return $order->get_total();
        });
    }

    /**
     * Get average order value
     * 
     * @return float Average order value
     */
    public function get_average_order_value() {
        if ($this->isEmpty()) {
            return 0.0;
        }
        
        return $this->get_total_revenue() / $this->count();
    }

    /**
     * Get unique customers count
     * 
     * @return int Number of unique customers
     */
    public function get_unique_customers_count() {
        return $this->unique(function($order) {
            return $order->get_customer_id();
        })->count();
    }

    /**
     * Get orders with pending status
     * 
     * @return static Pending orders
     */
    public function get_pending_orders() {
        return $this->filter_by_status(['pending', 'on-hold']);
    }

    /**
     * Get completed orders
     * 
     * @return static Completed orders
     */
    public function get_completed_orders() {
        return $this->filter_by_status(['completed', 'processing']);
    }

    /**
     * Get orders from last N days
     * 
     * @param int $days Number of days
     * 
     * @return static Recent orders
     */
    public function get_recent_orders($days = 7) {
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d');
        
        return $this->filter_by_date_range($start_date, $end_date);
    }

    /**
     * Get orders for current month
     * 
     * @return static Current month orders
     */
    public function get_current_month_orders() {
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        
        return $this->filter_by_date_range($start_date, $end_date);
    }

    /**
     * Get orders containing camps
     * 
     * @return static Camp orders
     */
    public function get_camp_orders() {
        return $this->filter_by_activity_type('Camp');
    }

    /**
     * Get orders containing courses
     * 
     * @return static Course orders
     */
    public function get_course_orders() {
        return $this->filter_by_activity_type('Course');
    }

    /**
     * Get high-value orders (above threshold)
     * 
     * @param float $threshold Minimum order value
     * 
     * @return static High-value orders
     */
    public function get_high_value_orders($threshold = 1000.0) {
        return $this->filter(function($order) use ($threshold) {
            return $order->get_total() >= $threshold;
        });
    }

    /**
     * Get statistics summary
     * 
     * @return array Statistics array
     */
    public function get_statistics() {
        $total_count = $this->count();
        
        if ($total_count === 0) {
            return [
                'total_orders' => 0,
                'total_revenue' => 0.0,
                'average_order_value' => 0.0,
                'unique_customers' => 0,
                'pending_orders' => 0,
                'completed_orders' => 0,
                'monthly_breakdown' => [],
                'status_breakdown' => []
            ];
        }
        
        $status_groups = $this->groupBy(function($order) {
            return $order->get_status();
        });
        
        $monthly_groups = $this->groupBy(function($order) {
            return $order->get_date_created()->format('Y-m');
        });
        
        return [
            'total_orders' => $total_count,
            'total_revenue' => $this->get_total_revenue(),
            'average_order_value' => $this->get_average_order_value(),
            'unique_customers' => $this->get_unique_customers_count(),
            'pending_orders' => $this->get_pending_orders()->count(),
            'completed_orders' => $this->get_completed_orders()->count(),
            'monthly_breakdown' => array_map(function($collection) {
                return [
                    'count' => $collection->count(),
                    'revenue' => $collection->get_total_revenue()
                ];
            }, $monthly_groups),
            'status_breakdown' => array_map(function($collection) {
                return [
                    'count' => $collection->count(),
                    'revenue' => $collection->get_total_revenue()
                ];
            }, $status_groups)
        ];
    }

    /**
     * Export collection data to array
     * 
     * @param array $fields Fields to include in export
     * 
     * @return array Export data
     */
    public function to_export_array($fields = []) {
        $default_fields = [
            'id', 'status', 'date_created', 'customer_id', 'customer_name', 
            'customer_email', 'total', 'currency', 'items_count'
        ];
        
        $export_fields = empty($fields) ? $default_fields : $fields;
        
        return $this->map(function($order) use ($export_fields) {
            $row = [];
            
            foreach ($export_fields as $field) {
                switch ($field) {
                    case 'id':
                        $row[$field] = $order->get_id();
                        break;
                    case 'status':
                        $row[$field] = $order->get_status();
                        break;
                    case 'date_created':
                        $row[$field] = $order->get_date_created()->format('Y-m-d H:i:s');
                        break;
                    case 'customer_id':
                        $row[$field] = $order->get_customer_id();
                        break;
                    case 'customer_name':
                        $row[$field] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                        break;
                    case 'customer_email':
                        $row[$field] = $order->get_billing_email();
                        break;
                    case 'total':
                        $row[$field] = $order->get_total();
                        break;
                    case 'currency':
                        $row[$field] = $order->get_currency();
                        break;
                    case 'items_count':
                        $row[$field] = count($order->get_items());
                        break;
                    default:
                        if (method_exists($order, 'get_' . $field)) {
                            $row[$field] = call_user_func([$order, 'get_' . $field]);
                        } else {
                            $row[$field] = '';
                        }
                }
            }
            
            return $row;
        })->toArray();
    }

    /**
     * Search orders by criteria
     * 
     * @param array $criteria Search criteria
     * 
     * @return static Matching orders
     */
    public function search($criteria) {
        $filtered = $this;
        
        // Search by customer name/email
        if (!empty($criteria['customer_search'])) {
            $search_term = strtolower($criteria['customer_search']);
            $filtered = $filtered->filter(function($order) use ($search_term) {
                $customer_name = strtolower($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                $customer_email = strtolower($order->get_billing_email());
                
                return strpos($customer_name, $search_term) !== false || 
                       strpos($customer_email, $search_term) !== false;
            });
        }
        
        // Search by order ID
        if (!empty($criteria['order_id'])) {
            $order_id = intval($criteria['order_id']);
            $filtered = $filtered->where('id', $order_id);
        }
        
        // Search by product name
        if (!empty($criteria['product_search'])) {
            $product_search = strtolower($criteria['product_search']);
            $filtered = $filtered->filter(function($order) use ($product_search) {
                return $order->contains_product_with_name($product_search);
            });
        }
        
        return $filtered;
    }

    /**
     * Get collection summary for logging/debugging
     * 
     * @return string Collection summary
     */
    public function get_summary() {
        $total_count = $this->count();
        $total_revenue = $this->get_total_revenue();
        $unique_customers = $this->get_unique_customers_count();
        
        return sprintf(
            'OrdersCollection: %d orders, %d customers, CHF %.2f revenue',
            $total_count,
            $unique_customers,
            $total_revenue
        );
    }
    
}