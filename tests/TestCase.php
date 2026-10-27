<?php
/**
 * Base TestCase for InterSoccer Reports & Rosters Tests
 * 
 * Provides common testing utilities and setup/teardown
 */

namespace InterSoccer\ReportsRosters\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Mockery;

abstract class TestCase extends PHPUnitTestCase {
    
    /**
     * Setup before each test
     */
    protected function setUp(): void {
        parent::setUp();
        
        // Setup global mocks
        $this->setupGlobalMocks();
    }
    
    /**
     * Teardown after each test
     */
    protected function tearDown(): void {
        global $intersoccer_test_product_type_safe_callback;
        global $intersoccer_test_get_product_type_callback;
        global $intersoccer_test_wc_get_product_callback;
        global $intersoccer_test_post_status_map;
        global $intersoccer_test_wc_order_item_meta;
        global $intersoccer_test_order_notes;
        global $intersoccer_test_userdata_map;
        $intersoccer_test_product_type_safe_callback = null;
        $intersoccer_test_get_product_type_callback = null;
        $intersoccer_test_wc_get_product_callback = null;
        $intersoccer_test_post_status_map = [];
        $intersoccer_test_wc_order_item_meta = [];
        $intersoccer_test_order_notes = [];
        $intersoccer_test_userdata_map = [];

        unset(
            $GLOBALS['intersoccer_test_wc_get_order_callback'],
            $GLOBALS['intersoccer_test_live_order_status_map'],
            $GLOBALS['intersoccer_test_live_order_status_batch_load_calls'],
            $GLOBALS['intersoccer_test_get_userdata'],
            $GLOBALS['intersoccer_test_wc_order_item_data'],
            $GLOBALS['intersoccer_migrated_items'],
            $GLOBALS['intersoccer_rr_product_type_safe_cache'],
            $GLOBALS['intersoccer_test_json_success_throws']
        );
        if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
            intersoccer_roster_report_reset_live_order_status_memo();
        }

        Mockery::close();

        global $wpdb;
        if (class_exists('InterSoccerTestWpdb', false)) {
            $wpdb = new \InterSoccerTestWpdb();
        }

        parent::tearDown();
    }
    
    /**
     * Setup global WordPress and database mocks
     */
    protected function setupGlobalMocks(): void {
        global $wpdb;
        
        if (!isset($wpdb) || !is_object($wpdb)) {
            $wpdb = Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';
            $wpdb->shouldReceive('prepare')->andReturnUsing(function($query) {
                $args = func_get_args();
                array_shift($args);
                return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
            });
        }
    }
    
    /**
     * Create a mock wpdb instance
     * 
     * @return \Mockery\MockInterface
     */
    protected function createWpdbMock(): \Mockery\MockInterface {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function($query) {
            $args = func_get_args();
            array_shift($args);
            return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
        });
        return $wpdb;
    }
    
    /**
     * Assert array has keys
     * 
     * @param array $keys Expected keys
     * @param array $array Array to check
     * @param string $message Optional message
     */
    protected function assertArrayHasKeys(array $keys, array $array, string $message = ''): void {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Array missing expected key: {$key}");
        }
    }
    
    /**
     * Assert value is between range
     * 
     * @param mixed $value Value to check
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     * @param string $message Optional message
     */
    protected function assertBetween($value, $min, $max, string $message = ''): void {
        $this->assertGreaterThanOrEqual($min, $value, $message);
        $this->assertLessThanOrEqual($max, $value, $message);
    }
}

