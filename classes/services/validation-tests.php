<?php
/**
 * InterSoccer Services Validation Test
 * 
 * This script validates our assumptions about the PricingCalculator and CacheManager
 * by testing with sample data similar to what we'll encounter in production.
 * 
 * Run this script in a WordPress environment to validate the Services layer.
 * 
 * @package InterSoccer_Reports_Rosters
 * @subpackage Tests
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('This script must be run within WordPress');
}

/**
 * Test PricingCalculator service
 */
function test_pricing_calculator() {
    echo "<h2>Testing PricingCalculator Service</h2>\n";
    
    // Mock logger for testing
    $logger = new class {
        public function info($msg, $context = []) { echo "INFO: $msg\n"; }
        public function debug($msg, $context = []) { echo "DEBUG: $msg\n"; }
        public function warning($msg, $context = []) { echo "WARNING: $msg\n"; }
        public function error($msg, $context = []) { echo "ERROR: $msg\n"; }
    };
    
    $calculator = new InterSoccer\Services\PricingCalculator($logger);
    
    // Test Case 1: Camp combo discount (2 children)
    echo "<h3>Test 1: Camp Combo Discount (2 children)</h3>\n";
    $cart_items_1 = [
        [
            'product_id' => 1001,
            'variation_id' => 28074,
            'quantity' => 1,
            'price' => 500.00,
            'assigned_player' => 0, // First child (Aaron)
            'assigned_attendee' => 'Aaron Litton',
            'season' => 'Summer 2025',
            'age_group' => '5-13y (Full Day)',
            'booking_type' => 'Full Week',
            'venue' => 'Geneva - Stade de Varembé (Nations)',
            'activity_type' => 'Camp',
            'variation_data' => ['Activity Type' => 'Camp']
        ],
        [
            'product_id' => 1001,
            'variation_id' => 28086,
            'quantity' => 1,
            'price' => 500.00,
            'assigned_player' => 1, // Second child (Zach)
            'assigned_attendee' => 'Zach Litton',
            'season' => 'Summer 2025',
            'age_group' => '5-13y (Full Day)',
            'booking_type' => 'Single Day(s)',
            'venue' => 'Geneva - Stade Chênois, Thonex',
            'activity_type' => 'Camp',
            'variation_data' => ['Activity Type' => 'Camp']
        ]
    ];
    
    try {
        $result_1 = $calculator->calculate_cart_pricing($cart_items_1, 123);
        echo "Original Total: CHF " . number_format($result_1['original_total'], 2) . "\n";
        echo "Final Total: CHF " . number_format($result_1['final_total'], 2) . "\n";
        echo "Total Savings: CHF " . number_format($result_1['total_savings'], 2) . "\n";
        echo "Expected: 20% discount on 2nd camp = CHF 100 savings\n";
        echo "Test " . ($result_1['total_savings'] == 100 ? "PASSED" : "FAILED") . "\n\n";
    } catch (Exception $e) {
        echo "Test FAILED: " . $e->getMessage() . "\n\n";
    }
    
    // Test Case 2: Course combo with same season discount
    echo "<h3>Test 2: Course Same Season Discount</h3>\n";
    $cart_items_2 = [
        [
            'product_id' => 2001,
            'variation_id' => 25641,
            'quantity' => 1,
            'price' => 800.00,
            'assigned_player' => 0, // Same child
            'assigned_attendee' => 'Vibu Karthick',
            'season' => 'Autumn 2025',
            'age_group' => '3-12y',
            'booking_type' => 'Full Term',
            'venue' => 'Basel - Stadion Rankhof',
            'start_date' => '2025-09-08',
            'end_date' => '2025-12-15',
            'activity_type' => 'Course',
            'variation_data' => ['Activity Type' => 'Course']
        ],
        [
            'product_id' => 2002,
            'variation_id' => 25642,
            'quantity' => 1,
            'price' => 700.00,
            'assigned_player' => 0, // Same child, same season
            'assigned_attendee' => 'Vibu Karthick',
            'season' => 'Autumn 2025',
            'age_group' => '5-8y',
            'booking_type' => 'Full Term',
            'venue' => 'Basel - Stadion Rankhof',
            'start_date' => '2025-09-08',
            'end_date' => '2025-12-15',
            'activity_type' => 'Course',
            'variation_data' => ['Activity Type' => 'Course']
        ]
    ];
    
    try {
        $result_2 = $calculator->calculate_cart_pricing($cart_items_2, 456);
        echo "Original Total: CHF " . number_format($result_2['original_total'], 2) . "\n";
        echo "Final Total: CHF " . number_format($result_2['final_total'], 2) . "\n";
        echo "Total Savings: CHF " . number_format($result_2['total_savings'], 2) . "\n";
        echo "Expected: 50% discount on 2nd course (lower price) = CHF 350 savings\n";
        echo "Test " . ($result_2['total_savings'] == 350 ? "PASSED" : "FAILED") . "\n\n";
    } catch (Exception $e) {
        echo "Test FAILED: " . $e->getMessage() . "\n\n";
    }
    
    // Test Case 3: Pro-rated pricing for late course start
    echo "<h3>Test 3: Pro-rated Course Pricing</h3>\n";
    // Simulate a course that started 4 weeks ago out of 16 weeks total
    $course_start = date('Y-m-d', strtotime('-4 weeks'));
    $course_end = date('Y-m-d', strtotime('+12 weeks'));
    
    $cart_items_3 = [
        [
            'product_id' => 3001,
            'variation_id' => 30001,
            'quantity' => 1,
            'price' => 800.00,
            'assigned_player' => 0,
            'assigned_attendee' => 'Late Joiner',
            'season' => 'Autumn 2025',
            'age_group' => '6-10y',
            'booking_type' => 'Full Term',
            'venue' => 'Zurich - Stadium',
            'start_date' => $course_start,
            'end_date' => $course_end,
            'activity_type' => 'Course',
            'variation_data' => ['Activity Type' => 'Course']
        ]];
    // Test Case 3: Pro-rated pricing for late course start
    echo "<h3>Test 3: Pro-rated Course Pricing</h3>\n";
    // Simulate a course that started 4 weeks ago out of 16 weeks total
    $course_start = date('Y-m-d', strtotime('-4 weeks'));
    $course_end = date('Y-m-d', strtotime('+12 weeks'));
    
    try {
        $result_3 = $calculator->calculate_cart_pricing($cart_items_3, 789);
        echo "Original Total: CHF " . number_format($result_3['original_total'], 2) . "\n";
        echo "Final Total: CHF " . number_format($result_3['final_total'], 2) . "\n";
        echo "Total Savings: CHF " . number_format($result_3['total_savings'], 2) . "\n";
        echo "Expected: ~25% discount for 4 weeks missed out of 16 = CHF 200 savings\n";
        echo "Test " . (abs($result_3['total_savings'] - 200) < 50 ? "PASSED" : "FAILED") . "\n\n";
    } catch (Exception $e) {
        echo "Test FAILED: " . $e->getMessage() . "\n\n";
    }
    
    // Test Case 4: Complex scenario - Multiple discounts
    echo "<h3>Test 4: Complex Multi-Discount Scenario</h3>\n";
    $cart_items_4 = [
        // Two camps for different children (combo discount)
        [
            'product_id' => 1001,
            'variation_id' => 28074,
            'quantity' => 1,
            'price' => 500.00,
            'assigned_player' => 0,
            'assigned_attendee' => 'Child One',
            'season' => 'Summer 2025',
            'age_group' => '5-13y (Full Day)',
            'booking_type' => 'Full Week',
            'venue' => 'Geneva - Stade de Varembé',
            'activity_type' => 'Camp',
            'variation_data' => ['Activity Type' => 'Camp']
        ],
        [
            'product_id' => 1001,
            'variation_id' => 28074,
            'quantity' => 1,
            'price' => 400.00, // Different price (half-day vs full-day)
            'assigned_player' => 1,
            'assigned_attendee' => 'Child Two',
            'season' => 'Summer 2025',
            'age_group' => '3-5y (Half Day)',
            'booking_type' => 'Full Week',
            'venue' => 'Geneva - Stade de Varembé',
            'activity_type' => 'Camp',
            'variation_data' => ['Activity Type' => 'Camp']
        ],
        // Two courses for different children (combo discount)
        [
            'product_id' => 2001,
            'variation_id' => 25641,
            'quantity' => 1,
            'price' => 800.00,
            'assigned_player' => 0,
            'assigned_attendee' => 'Child One',
            'season' => 'Autumn 2025',
            'age_group' => '3-12y',
            'booking_type' => 'Full Term',
            'venue' => 'Basel - Stadion Rankhof',
            'start_date' => '2025-09-08',
            'end_date' => '2025-12-15',
            'activity_type' => 'Course',
            'variation_data' => ['Activity Type' => 'Course']
        ],
        [
            'product_id' => 2002,
            'variation_id' => 25642,
            'quantity' => 1,
            'price' => 750.00,
            'assigned_player' => 1,
            'assigned_attendee' => 'Child Two',
            'season' => 'Autumn 2025',
            'age_group' => '5-8y',
            'booking_type' => 'Full Term',
            'venue' => 'Basel - Stadion Rankhof',
            'start_date' => '2025-09-08',
            'end_date' => '2025-12-15',
            'activity_type' => 'Course',
            'variation_data' => ['Activity Type' => 'Course']
        ]
    ];
    
    try {
        $result_4 = $calculator->calculate_cart_pricing($cart_items_4, 999);
        $summary = $calculator->get_pricing_summary($result_4);
        
        echo "Original Total: CHF " . $summary['subtotal'] . "\n";
        echo "Final Total: CHF " . $summary['total'] . "\n";
        echo "Total Savings: CHF " . $summary['discounts'] . " ({$summary['savings_percentage']}%)\n";
        echo "Discount Details:\n";
        foreach ($summary['discount_details'] as $detail) {
            echo "  - {$detail['description']}: CHF {$detail['amount']}\n";
        }
        
        $expected_savings = 80 + 150; // 20% camp combo + 20% course combo
        echo "Expected minimum savings: CHF " . number_format($expected_savings, 2) . "\n";
        echo "Test " . ($result_4['total_savings'] >= $expected_savings ? "PASSED" : "FAILED") . "\n\n";
    } catch (Exception $e) {
        echo "Test FAILED: " . $e->getMessage() . "\n\n";
    }
}

/**
 * Test CacheManager service
 */
function test_cache_manager() {
    echo "<h2>Testing CacheManager Service</h2>\n";
    
    // Mock logger for testing
    $logger = new class {
        public function info($msg, $context = []) { echo "INFO: $msg\n"; }
        public function debug($msg, $context = []) { echo "DEBUG: $msg\n"; }
        public function warning($msg, $context = []) { echo "WARNING: $msg\n"; }
        public function error($msg, $context = []) { echo "ERROR: $msg\n"; }
    };
    
    $cache = new InterSoccer\Services\CacheManager($logger);
    
    // Test Case 1: Basic set/get operations
    echo "<h3>Test 1: Basic Cache Operations</h3>\n";
    $test_data = [
        'roster_id' => 'camp_summer_2025_week_9',
        'venue' => 'Geneva - Stade de Varembé',
        'attendees' => [
            ['name' => 'Aaron Litton', 'age_group' => '5-13y'],
            ['name' => 'Zach Litton', 'age_group' => '5-13y']
        ],
        'generated_at' => time()
    ];
    
    $cache_key = 'test_roster_' . uniqid();
    
    // Test SET
    $set_result = $cache->set($cache_key, $test_data, 'roster_data');
    echo "Cache SET: " . ($set_result ? "SUCCESS" : "FAILED") . "\n";
    
    // Test GET
    $retrieved_data = $cache->get($cache_key, 'roster_data');
    $get_result = ($retrieved_data !== null && $retrieved_data['roster_id'] === $test_data['roster_id']);
    echo "Cache GET: " . ($get_result ? "SUCCESS" : "FAILED") . "\n";
    
    // Test DEFAULT value
    $default_data = $cache->get('non_existent_key', 'roster_data', 'default_value');
    echo "Cache GET with default: " . ($default_data === 'default_value' ? "SUCCESS" : "FAILED") . "\n";
    
    echo "\n";
    
    // Test Case 2: Cache remember functionality
    echo "<h3>Test 2: Cache Remember Function</h3>\n";
    
    $expensive_calculation_calls = 0;
    $expensive_calculation = function() use (&$expensive_calculation_calls) {
        $expensive_calculation_calls++;
        // Simulate expensive operation
        usleep(100000); // 100ms delay
        return [
            'calculated_at' => time(),
            'expensive_result' => 'This took a long time to calculate',
            'call_number' => $expensive_calculation_calls
        ];
    };
    
    $remember_key = 'expensive_calc_' . uniqid();
    
    // First call - should execute callback
    $start_time = microtime(true);
    $result_1 = $cache->remember($remember_key, $expensive_calculation, 'report_data');
    $time_1 = microtime(true) - $start_time;
    
    // Second call - should use cache
    $start_time = microtime(true);
    $result_2 = $cache->remember($remember_key, $expensive_calculation, 'report_data');
    $time_2 = microtime(true) - $start_time;
    
    echo "First call (cache miss): {$time_1}s, calls: {$expensive_calculation_calls}\n";
    echo "Second call (cache hit): {$time_2}s, calls: {$expensive_calculation_calls}\n";
    echo "Cache remember test: " . ($time_2 < $time_1 && $expensive_calculation_calls === 1 ? "SUCCESS" : "FAILED") . "\n\n";
    
    // Test Case 3: Roster-specific caching
    echo "<h3>Test 3: Roster-Specific Caching</h3>\n";
    
    $roster_data = [
        'event_type' => 'camp',
        'season' => 'Summer 2025',
        'week' => 9,
        'venue' => 'Geneva - Stade de Varembé',
        'attendees_count' => 15,
        'coaches' => ['Coach A', 'Coach B'],
        'last_updated' => time()
    ];
    
    $roster_key = 'summer_2025_week_9_geneva';
    
    // Cache roster
    $roster_cache_result = $cache->cache_roster($roster_key, $roster_data);
    echo "Roster cache: " . ($roster_cache_result ? "SUCCESS" : "FAILED") . "\n";
    
    // Retrieve roster
    $cached_roster = $cache->get_roster($roster_key);
    $roster_retrieve_result = ($cached_roster !== null && $cached_roster['attendees_count'] === 15);
    echo "Roster retrieve: " . ($roster_retrieve_result ? "SUCCESS" : "FAILED") . "\n";
    
    // Test cache invalidation
    $cache->invalidate_roster_cache();
    $invalidated_roster = $cache->get_roster($roster_key);
    echo "Roster invalidation: " . ($invalidated_roster === null ? "SUCCESS" : "FAILED") . "\n\n";
    
    // Test Case 4: Cache statistics and cleanup
    echo "<h3>Test 4: Cache Statistics</h3>\n";
    
    // Add some test data
    for ($i = 0; $i < 5; $i++) {
        $cache->set("test_key_$i", "test_data_$i", 'test_group', 30);
    }
    
    $stats = $cache->get_stats();
    echo "Cache backend: {$stats['backend']}\n";
    echo "Total keys: {$stats['total_keys']}\n";
    echo "Total size: " . number_format($stats['total_size']) . " bytes\n";
    
    // Test group clearing
    $clear_result = $cache->clear_group('test_group');
    echo "Group clear: " . ($clear_result ? "SUCCESS" : "FAILED") . "\n";
    
    // Verify group was cleared
    $after_clear = $cache->get('test_key_0', 'test_group');
    echo "After clear verification: " . ($after_clear === null ? "SUCCESS" : "FAILED") . "\n\n";
    
    // Test Case 5: Performance test with large data
    echo "<h3>Test 5: Performance Test with Large Dataset</h3>\n";
    
    // Generate large roster data
    $large_roster = [
        'event_id' => 'large_test_event',
        'attendees' => []
    ];
    
    for ($i = 0; $i < 1000; $i++) {
        $large_roster['attendees'][] = [
            'id' => $i,
            'name' => "Test Attendee $i",
            'age_group' => ($i % 3 === 0) ? '3-5y' : (($i % 3 === 1) ? '5-8y' : '8-12y'),
            'venue' => "Venue " . ($i % 10),
            'medical_conditions' => $i % 20 === 0 ? 'Allergies to peanuts' : 'None',
            'emergency_contact' => "Contact $i",
            'registered_at' => time() - ($i * 3600)
        ];
    }
    
    $large_key = 'large_roster_test';
    
    // Test caching large dataset
    $start_time = microtime(true);
    $large_cache_result = $cache->set($large_key, $large_roster, 'roster_data');
    $cache_time = microtime(true) - $start_time;
    
    // Test retrieving large dataset
    $start_time = microtime(true);
    $retrieved_large = $cache->get($large_key, 'roster_data');
    $retrieve_time = microtime(true) - $start_time;
    
    echo "Large data cache (1000 attendees): " . ($large_cache_result ? "SUCCESS" : "FAILED") . " in {$cache_time}s\n";
    echo "Large data retrieve: " . (count($retrieved_large['attendees']) === 1000 ? "SUCCESS" : "FAILED") . " in {$retrieve_time}s\n";
    
    // Cleanup
    $cache->delete($large_key, 'roster_data');
    echo "\n";
}

/**
 * Test integration between PricingCalculator and CacheManager
 */
function test_services_integration() {
    echo "<h2>Testing Services Integration</h2>\n";
    
    // Mock logger
    $logger = new class {
        public function info($msg, $context = []) { echo "INFO: $msg\n"; }
        public function debug($msg, $context = []) { echo "DEBUG: $msg\n"; }
        public function warning($msg, $context = []) { echo "WARNING: $msg\n"; }
        public function error($msg, $context = []) { echo "ERROR: $msg\n"; }
    };
    
    $calculator = new InterSoccer\Services\PricingCalculator($logger);
    $cache = new InterSoccer\Services\CacheManager($logger);
    
    echo "<h3>Integration Test: Cached Pricing Calculations</h3>\n";
    
    // Sample cart for testing
    $sample_cart = [
        [
            'product_id' => 1001,
            'variation_id' => 28074,
            'quantity' => 1,
            'price' => 500.00,
            'assigned_player' => 0,
            'assigned_attendee' => 'Integration Test Child',
            'season' => 'Summer 2025',
            'activity_type' => 'Camp',
            'variation_data' => ['Activity Type' => 'Camp']
        ]
    ];
    
    $user_id = 12345;
    $cache_key = "pricing_calc_user_{$user_id}_" . md5(serialize($sample_cart));
    
    // Create a cached pricing calculation function
    $cached_pricing_calc = function() use ($calculator, $sample_cart, $user_id) {
        return $calculator->calculate_cart_pricing($sample_cart, $user_id);
    };
    
    // First call - should calculate and cache
    $start_time = microtime(true);
    $result_1 = $cache->remember($cache_key, $cached_pricing_calc, 'pricing_data', 1800);
    $time_1 = microtime(true) - $start_time;
    
    // Second call - should use cache
    $start_time = microtime(true);
    $result_2 = $cache->remember($cache_key, $cached_pricing_calc, 'pricing_data', 1800);
    $time_2 = microtime(true) - $start_time;
    
    echo "First pricing calculation: {$time_1}s\n";
    echo "Cached pricing retrieval: {$time_2}s\n";
    echo "Results match: " . ($result_1['final_total'] === $result_2['final_total'] ? "SUCCESS" : "FAILED") . "\n";
    echo "Cache performance improvement: " . round((($time_1 - $time_2) / $time_1) * 100, 1) . "%\n\n";
    
    // Test cache invalidation when order data changes
    echo "<h3>Integration Test: Cache Invalidation on Order Changes</h3>\n";
    
    // Simulate order update scenario
    $order_id = 999;
    
    // Cache some order-related data
    $cache->set("order_{$order_id}", ['status' => 'completed'], 'order_queries');
    $cache->cache_roster("roster_order_{$order_id}", ['attendees' => 10]);
    
    // Verify data is cached
    $cached_order = $cache->get("order_{$order_id}", 'order_queries');
    $cached_roster = $cache->get_roster("roster_order_{$order_id}");
    
    echo "Data cached: " . ($cached_order && $cached_roster ? "SUCCESS" : "FAILED") . "\n";
    
    // Simulate order change and invalidate related caches
    $cache->invalidate_roster_cache($order_id);
    
    // Verify caches were invalidated
    $after_invalidation_order = $cache->get("order_{$order_id}", 'order_queries');
    $after_invalidation_roster = $cache->get_roster("roster_order_{$order_id}");
    
    echo "Cache invalidated: " . (!$after_invalidation_order && !$after_invalidation_roster ? "SUCCESS" : "FAILED") . "\n\n";
}

/**
 * Run all validation tests
 */
function run_services_validation_tests() {
    echo "<h1>InterSoccer Services Validation Tests</h1>\n";
    echo "<p>Testing PricingCalculator and CacheManager services...</p>\n";
    
    try {
        // Test individual services
        test_pricing_calculator();
        test_cache_manager();
        test_services_integration();
        
        echo "<h2>Test Summary</h2>\n";
        echo "<p>All validation tests completed. Check the output above for individual test results.</p>\n";
        echo "<p><strong>Next Steps:</strong></p>\n";
        echo "<ul>\n";
        echo "<li>Review any FAILED tests and adjust the service implementations</li>\n";
        echo "<li>Add debug logging where needed based on test results</li>\n";
        echo "<li>Proceed with UI layer implementation once Services are validated</li>\n";
        echo "<li>Create unit tests for production deployment</li>\n";
        echo "</ul>\n";
        
    } catch (Exception $e) {
        echo "<h2>CRITICAL ERROR</h2>\n";
        echo "<p>Test execution failed: " . $e->getMessage() . "</p>\n";
        echo "<pre>" . $e->getTraceAsString() . "</pre>\n";
    }
}

// Run tests if this file is accessed directly with proper WordPress context
if (function_exists('wp_loaded') || defined('WP_DEBUG')) {
    run_services_validation_tests();
} else {
    echo "This test script must be run within a WordPress environment.\n";
    echo "Include this file in a WordPress page or run via WP-CLI.\n";
}