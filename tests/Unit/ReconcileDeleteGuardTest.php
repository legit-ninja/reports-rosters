<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReconcileDeleteGuardTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_wc_get_order_callback']);
        parent::tearDown();
    }

    public function test_wc_order_query_args_include_suppress_filters(): void {
        $args = intersoccer_reconcile_wc_order_query_args(['limit' => 10]);
        $this->assertTrue($args['suppress_filters']);
        $this->assertSame('shop_order', $args['type']);
    }

    public function test_may_not_delete_when_order_still_eligible_even_if_missing_from_filtered_list(): void {
        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public function prepare($query, ...$args) {
                return $query;
            }
            public function get_var($query) {
                return 9001;
            }
        };

        $order = new class {
            public function get_status() {
                return 'completed';
            }
        };
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = static function () use ($order) {
            return $order;
        };

        $this->assertFalse(
            intersoccer_reconcile_may_delete_roster_for_order_item(555, intersoccer_reconcile_default_order_statuses())
        );
    }

    public function test_may_delete_when_order_status_ineligible(): void {
        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public function prepare($query, ...$args) {
                return $query;
            }
            public function get_var($query) {
                return 9002;
            }
        };

        $order = new class {
            public function get_status() {
                return 'cancelled';
            }
        };
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = static function () use ($order) {
            return $order;
        };

        $this->assertTrue(
            intersoccer_reconcile_may_delete_roster_for_order_item(556, intersoccer_reconcile_default_order_statuses())
        );
    }

    public function test_may_delete_when_line_item_missing(): void {
        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public function prepare($query, ...$args) {
                return $query;
            }
            public function get_var($query) {
                return null;
            }
        };

        $this->assertTrue(
            intersoccer_reconcile_may_delete_roster_for_order_item(557, intersoccer_reconcile_default_order_statuses())
        );
    }
}
