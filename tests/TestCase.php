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

        // Cache gates treat a leftover TRUE as enabled for every later test.
        // Opt in again after this setup when a test really wants the cache.
        unset(
            $GLOBALS['intersoccer_test_pcs_cache_force_enabled'],
            $GLOBALS['intersoccer_test_winback_cache_force_enabled']
        );

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
            $GLOBALS['intersoccer_test_json_success_throws'],
            $GLOBALS['intersoccer_test_wpml_current_language'],
            $GLOBALS['intersoccer_test_simulate_wc_order_save_pa_rewrite'],
            $GLOBALS['intersoccer_test_simulate_wc_order_save_moved_item_id'],
            $GLOBALS['intersoccer_test_simulate_wc_order_save_add_sibling_meta'],
            $GLOBALS['intersoccer_test_wc_update_order_item_meta_call_counts'],
            $GLOBALS['intersoccer_test_wc_delete_order_item_meta_call_counts']
        );
        if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
            intersoccer_roster_report_reset_live_order_status_memo();
        }

        try {
            Mockery::close();
        } finally {
            // close() throws on an unmet count. The next test still needs the
            // redefinitions, Brain Monkey registry, database, and PHPUnit
            // teardown cleared, and the unmet count must keep failing this test.
            if (function_exists('Patchwork\\restoreAll')) {
                \Patchwork\restoreAll();
            }
            if (class_exists(\Brain\Monkey\Container::class, false)) {
                \Brain\Monkey\Container::instance()->reset();
            }

            global $wpdb;
            if (class_exists('InterSoccerTestWpdb', false)) {
                $wpdb = new \InterSoccerTestWpdb();
            }

            parent::tearDown();
        }
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

