<?php
/**
 * Acceptance criteria 7, 12, 13 — admin move AJAX handler (real migration path).
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersAjaxAcceptanceTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private const ORDER_ID = 88001;
    private const ITEM_ID = 88011;
    private const PARENT_ID = 88000;
    private const SOURCE_VAR = 88010;
    private const TARGET_VAR = 88020;

    protected function setUp(): void {
        parent::setUp();
        $this->resetMovePlayersTestGlobals();

        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_get_product_type_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };

        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-item-renormalize-suppress.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__) . '/stubs/get-product-type-stub.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-reason-messages.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-audit.php';
        require_once dirname(__DIR__, 2) . '/includes/advanced.php';

    }

    protected function tearDown(): void {
        $this->resetMovePlayersTestGlobals();
        $_POST = [];
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function invokeMoveAjax(array $post): array {
        $_POST = array_merge(
            [
                'action' => 'intersoccer_move_players',
                'nonce' => wp_create_nonce('intersoccer_move_nonce'),
            ],
            $post
        );
        $GLOBALS['intersoccer_test_json_success_throws'] = true;
        try {
            ob_start();
            intersoccer_move_players_ajax();
            $json = ob_get_clean();
        } catch (\RuntimeException $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $json = $e->getMessage();
        } finally {
            unset($GLOBALS['intersoccer_test_json_success_throws']);
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function installWorld(bool $target_full = true): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_wc_get_product_callback;
        global $intersoccer_test_post_status_map;

        $intersoccer_test_post_status_map = [
            self::SOURCE_VAR => 'publish',
            self::TARGET_VAR => 'publish',
            self::PARENT_ID => 'publish',
        ];

        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [
            'Activity Type' => 'Course',
            'Assigned Attendee' => 'Test Player',
        ];
        $intersoccer_test_wc_order_item_data[self::ITEM_ID] = [
            'product_id' => self::PARENT_ID,
            'variation_id' => self::SOURCE_VAR,
            'subtotal' => 120.50,
            'total' => 100.00,
            'name' => 'Source course',
        ];

        $item = new \WC_Order_Item_Product(self::ITEM_ID);
        $order = new \WC_Order(self::ORDER_ID);
        $order->set_items([self::ITEM_ID => $item]);

        $intersoccer_test_wc_get_order_callback = static function ($id) use ($order) {
            return (int) $id === self::ORDER_ID ? $order : false;
        };

        $source = Mockery::mock(\WC_Product_Variation::class);
        $source->shouldReceive('get_id')->andReturn(self::SOURCE_VAR);
        $source->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
        $source->shouldReceive('get_variation_attributes')->andReturn([]);
        $source->shouldReceive('get_name')->andReturn('Source variation');
        $source->shouldReceive('get_attribute')->andReturn('');
        $source->shouldReceive('get_attributes')->andReturn([]);
        $source->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $source->shouldReceive('get_price')->andReturn('100');
        $source->shouldReceive('is_purchasable')->andReturn(true);
        $source->shouldReceive('is_type')->with('variation')->andReturn(true);
        $source->shouldReceive('managing_stock')->andReturn(false);
        $source->shouldReceive('get_stock_quantity')->andReturn(5);
        $source->shouldReceive('get_stock_status')->andReturn('instock');
        $source->shouldReceive('set_stock_quantity')->never();
        $source->shouldReceive('set_stock_status')->never();
        $source->shouldReceive('save')->never();

        $target = Mockery::mock(\WC_Product_Variation::class);
        $target->shouldReceive('get_id')->andReturn(self::TARGET_VAR);
        $target->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
        $target->shouldReceive('get_variation_attributes')->andReturn([]);
        $target->shouldReceive('get_name')->andReturn('Target variation');
        $target->shouldReceive('get_attribute')->andReturn('');
        $target->shouldReceive('get_attributes')->andReturn([]);
        $target->shouldReceive('get_meta')->with('capacity', true)->andReturn($target_full ? '2' : '');
        $target->shouldReceive('get_price')->andReturn('');
        $target->shouldReceive('is_purchasable')->andReturn(false);
        $target->shouldReceive('is_type')->with('variation')->andReturn(true);
        $target->shouldReceive('managing_stock')->andReturn(false);
        $target->shouldReceive('get_stock_quantity')->andReturn(0);
        $target->shouldReceive('get_stock_status')->andReturn('outofstock');
        $target->shouldReceive('set_stock_quantity')->never();
        $target->shouldReceive('set_stock_status')->never();
        $target->shouldReceive('save')->never();

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent->shouldReceive('get_name')->andReturn('Parent course');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($source, $target, $parent) {
            $id = (int) $id;
            if ($id === self::SOURCE_VAR) {
                return $source;
            }
            if ($id === self::TARGET_VAR) {
                return $target;
            }
            if ($id === self::PARENT_ID) {
                return $parent;
            }

            return false;
        };

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return (string) $query;
        });
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) use ($target_full) {
            if (!is_string($sql)) {
                return null;
            }
            if (strpos($sql, 'woocommerce_order_items') !== false) {
                return (string) self::ORDER_ID;
            }
            if (strpos($sql, 'intersoccer_rosters') !== false && strpos($sql, 'order_item_id') !== false) {
                return '99';
            }
            if (strpos($sql, 'event_completed') !== false) {
                return '0';
            }
            if (strpos($sql, 'COUNT(*)') !== false && $target_full) {
                return '2';
            }

            return '0';
        });
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->shouldReceive('query')->andReturn(true);
        $wpdb->shouldReceive('get_row')->andReturn(null);
    }

    public function test_criterion_7_unconfirmed_non_purchasable_returns_needs_confirm_without_mutation(): void {
        $this->installWorld(true);

        $before_variation = (int) ($GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID]['variation_id'] ?? 0);
        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '0',
            'allow_cross_gender' => '0',
        ]);

        $this->assertTrue($response['success'] ?? false);
        $this->assertTrue($response['data']['needs_confirm'] ?? false);
        $after_variation = (int) ($GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID]['variation_id'] ?? 0);
        $this->assertSame($before_variation, $after_variation);
        $this->assertSame(self::SOURCE_VAR, $after_variation);
        $this->assertEmpty($GLOBALS['intersoccer_test_order_notes'][self::ORDER_ID] ?? []);
    }

    public function test_criterion_7_confirmed_move_proceeds(): void {
        $this->installWorld(true);

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
            'allow_cross_gender' => '0',
        ]);

        $this->assertTrue($response['success'] ?? false);
        $this->assertFalse($response['data']['needs_confirm'] ?? false);
        $this->assertGreaterThan(0, (int) ($response['data']['moved_count'] ?? 0));
        $this->assertSame(
            self::TARGET_VAR,
            (int) ($GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID]['variation_id'] ?? 0)
        );
    }

    public function test_criterion_12_order_line_totals_unchanged_after_confirmed_full_target_move(): void {
        $this->installWorld(true);
        $order = wc_get_order(self::ORDER_ID);
        $before_total = $order->get_total();
        $before_tax = $order->get_total_tax();
        $before_status = $order->get_status();
        $before_payment = $order->get_payment_method();
        $before_subtotal = $GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID]['subtotal'];
        $before_line_total = $GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID]['total'];

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
            'allow_cross_gender' => '0',
        ]);

        $this->assertSame($before_total, $order->get_total());
        $this->assertSame($before_tax, $order->get_total_tax());
        $this->assertSame($before_status, $order->get_status());
        $this->assertSame($before_payment, $order->get_payment_method());
        $this->assertSame($before_subtotal, $GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID]['subtotal']);
        $this->assertSame($before_line_total, $GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID]['total']);
    }

    public function test_criterion_13_no_stock_writes_on_source_or_target_variations(): void {
        $this->installWorld(true);

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
            'allow_cross_gender' => '0',
        ]);

        $this->assertTrue($response['success'] ?? false);
        $this->assertGreaterThan(0, (int) ($response['data']['moved_count'] ?? 0));
    }
}
