<?php
/**
 * Roster move WooCommerce stock adjustments.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersStockAdjustTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private const ORDER_ID = 93001;
    private const ITEM_ID = 93011;
    private const PARENT_ID = 93000;
    private const SOURCE_VAR = 93010;
    private const TARGET_VAR = 93020;

    protected function setUp(): void {
        parent::setUp();
        $this->resetMovePlayersTestGlobals();
        $GLOBALS['intersoccer_test_wc_stock_qty'] = [
            self::SOURCE_VAR => 2,
            self::TARGET_VAR => 3,
        ];
        $GLOBALS['intersoccer_test_wc_stock_calls'] = [];

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

        global $intersoccer_test_wc_update_stock_callback;
        $intersoccer_test_wc_update_stock_callback = static function ($product, $amount, $mode) {
            $id = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
            $GLOBALS['intersoccer_test_wc_stock_calls'][] = [
                'variation_id' => $id,
                'amount' => (int) $amount,
                'mode' => (string) $mode,
            ];
            if ($id <= 0 || !isset($GLOBALS['intersoccer_test_wc_stock_qty'][$id])) {
                return true;
            }
            $qty = (int) $GLOBALS['intersoccer_test_wc_stock_qty'][$id];
            if ($mode === 'increase') {
                $qty += (int) $amount;
            } elseif ($mode === 'decrease') {
                $qty -= (int) $amount;
            } else {
                $qty = (int) $amount;
            }
            $GLOBALS['intersoccer_test_wc_stock_qty'][$id] = $qty;

            return $qty;
        };
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_wc_stock_qty'],
            $GLOBALS['intersoccer_test_wc_stock_calls'],
            $GLOBALS['intersoccer_test_wc_update_stock_callback']
        );
        $this->resetMovePlayersTestGlobals();
        parent::tearDown();
    }

    private function makeManagedVariation(int $variation_id, bool $backorders = false): Mockery\MockInterface {
        $variation = Mockery::mock(\WC_Product_Variation::class);
        $variation->shouldReceive('get_id')->andReturn($variation_id);
        $variation->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
        $variation->shouldReceive('managing_stock')->andReturn(true);
        $variation->shouldReceive('backorders_allowed')->andReturn($backorders);
        $variation->shouldReceive('get_stock_quantity')->andReturnUsing(static function () use ($variation_id) {
            return (int) ($GLOBALS['intersoccer_test_wc_stock_qty'][$variation_id] ?? 0);
        });
        $variation->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_attributes')->andReturn([]);
        $variation->shouldReceive('get_name')->andReturn('Variation ' . $variation_id);
        $variation->shouldReceive('get_price')->andReturn('100');
        $variation->shouldReceive('is_purchasable')->andReturn(true);
        $variation->shouldReceive('is_type')->with('variation')->andReturn(true);
        $variation->shouldReceive('get_stock_status')->andReturn('instock');

        return $variation;
    }

    private function bindProducts(bool $source_backorders = false, bool $target_backorders = false): void {
        global $intersoccer_test_wc_get_product_callback;
        $source = $this->makeManagedVariation(self::SOURCE_VAR, $source_backorders);
        $target = $this->makeManagedVariation(self::TARGET_VAR, $target_backorders);
        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);
        $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $parent->shouldReceive('get_name')->andReturn('Parent');

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
    }

    public function test_normal_move_increases_source_and_decreases_destination(): void {
        $this->bindProducts();
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [];

        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::SOURCE_VAR,
            self::TARGET_VAR
        ));
        $this->assertSame(3, (int) $GLOBALS['intersoccer_test_wc_stock_qty'][self::SOURCE_VAR]);
        $this->assertSame(2, (int) $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR]);
        $delta = intersoccer_roster_move_read_stock_delta(self::ITEM_ID);
        $this->assertSame(self::SOURCE_VAR, (int) ($delta['from_variation_id'] ?? 0));
        $this->assertSame(self::TARGET_VAR, (int) ($delta['to_variation_id'] ?? 0));
        $this->assertSame(1, (int) ($delta['applied_from'] ?? -1));
        $this->assertSame(-1, (int) ($delta['applied_to'] ?? 0));
    }

    public function test_move_back_restores_both_variations_to_baseline(): void {
        $this->bindProducts();
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [];
        $baseline = $GLOBALS['intersoccer_test_wc_stock_qty'];

        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::SOURCE_VAR,
            self::TARGET_VAR
        ));
        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::TARGET_VAR,
            self::SOURCE_VAR
        ));
        $this->assertSame($baseline[self::SOURCE_VAR], $GLOBALS['intersoccer_test_wc_stock_qty'][self::SOURCE_VAR]);
        $this->assertSame($baseline[self::TARGET_VAR], $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR]);
        $this->assertNull(intersoccer_roster_move_read_stock_delta(self::ITEM_ID));
    }

    public function test_move_anyway_into_full_destination_records_zero_decrease(): void {
        $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR] = 0;
        $this->bindProducts(false, false);
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [];

        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::SOURCE_VAR,
            self::TARGET_VAR
        ));
        $this->assertSame(0, (int) $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR]);
        $delta = intersoccer_roster_move_read_stock_delta(self::ITEM_ID);
        $this->assertSame(0, (int) ($delta['applied_to'] ?? -1));
        $this->assertSame(1, (int) ($delta['applied_from'] ?? 0));

        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::TARGET_VAR,
            self::SOURCE_VAR
        ));
        $this->assertSame(2, (int) $GLOBALS['intersoccer_test_wc_stock_qty'][self::SOURCE_VAR]);
        $this->assertSame(0, (int) $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR]);
    }

    public function test_move_anyway_with_backorders_decreases_below_zero_and_reverses(): void {
        $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR] = 0;
        $this->bindProducts(false, true);
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [];

        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::SOURCE_VAR,
            self::TARGET_VAR
        ));
        $this->assertSame(-1, (int) $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR]);
        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::TARGET_VAR,
            self::SOURCE_VAR
        ));
        $this->assertSame(0, (int) $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR]);
    }

    public function test_unmanaged_variation_does_not_call_wc_update_product_stock(): void {
        global $intersoccer_test_wc_get_product_callback;
        $source = Mockery::mock(\WC_Product_Variation::class);
        $source->shouldReceive('get_id')->andReturn(self::SOURCE_VAR);
        $source->shouldReceive('managing_stock')->andReturn(false);
        $target = $this->makeManagedVariation(self::TARGET_VAR);
        $intersoccer_test_wc_get_product_callback = static function ($id) use ($source, $target) {
            $id = (int) $id;
            if ($id === self::SOURCE_VAR) {
                return $source;
            }
            if ($id === self::TARGET_VAR) {
                return $target;
            }

            return false;
        };
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [];
        $calls_before = count($GLOBALS['intersoccer_test_wc_stock_calls']);

        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::SOURCE_VAR,
            self::TARGET_VAR
        ));
        $delta = intersoccer_roster_move_read_stock_delta(self::ITEM_ID);
        $this->assertSame(0, (int) ($delta['applied_from'] ?? -1));
        $this->assertSame(-1, (int) ($delta['applied_to'] ?? 0));
        $this->assertSame(1, count($GLOBALS['intersoccer_test_wc_stock_calls']) - $calls_before);
    }

    public function test_stock_delta_record_contains_only_ids_and_integers(): void {
        $this->bindProducts();
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [];
        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::SOURCE_VAR,
            self::TARGET_VAR
        ));
        $raw = wc_get_order_item_meta(self::ITEM_ID, intersoccer_roster_move_stock_delta_meta_key(), true);
        $decoded = json_decode((string) $raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            ['from_variation_id', 'to_variation_id', 'applied_from', 'applied_to'],
            array_keys($decoded)
        );
        foreach ($decoded as $value) {
            $this->assertIsInt($value);
        }
    }

    public function test_capacity_unchanged_after_normal_managed_stock_move(): void {
        $this->bindProducts();
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return (string) $query;
        });
        $wpdb->shouldReceive('get_var')->andReturn('4');

        $target = wc_get_product(self::TARGET_VAR);
        $this->assertInstanceOf(\WC_Product_Variation::class, $target);
        $taken_before = 4;
        $before = intersoccer_roster_move_resolve_variation_capacity($target, $taken_before);

        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [];
        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::SOURCE_VAR,
            self::TARGET_VAR
        ));

        $after = intersoccer_roster_move_resolve_variation_capacity($target, $taken_before + 1);
        $this->assertSame($before, $after);
    }

    /**
     * @return array<string,mixed>
     */
    private function invokeMoveAjax(array $post): array {
        require_once dirname(__DIR__, 2) . '/includes/roster-move-audit.php';
        require_once dirname(__DIR__, 2) . '/includes/advanced.php';
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

    public function test_rejected_needs_confirm_move_does_not_adjust_stock(): void {
        $this->bindProducts();
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_get_order_callback;
        $GLOBALS['intersoccer_test_roster_row_filter_map'] = [self::ITEM_ID => true];
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = ['Activity Type' => 'Course'];
        $GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID] = [
            'product_id' => self::PARENT_ID,
            'variation_id' => self::SOURCE_VAR,
            'subtotal' => 100,
            'total' => 100,
            'name' => 'Line',
        ];
        $item = new \WC_Order_Item_Product(self::ITEM_ID);
        $order = new \WC_Order(self::ORDER_ID);
        $order->set_items([self::ITEM_ID => $item]);
        $intersoccer_test_wc_get_order_callback = static function ($id) use ($order) {
            return (int) $id === self::ORDER_ID ? $order : false;
        };

        $target = wc_get_product(self::TARGET_VAR);
        if ($target instanceof Mockery\MockInterface) {
            $target->shouldReceive('is_purchasable')->andReturn(false);
            $target->shouldReceive('get_price')->andReturn('');
            $target->shouldReceive('get_stock_status')->andReturn('instock');
        }

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($q) => (string) $q);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->shouldReceive('query')->andReturn(true);
        $wpdb->shouldReceive('get_row')->andReturn(['id' => 1, 'variation_id' => self::SOURCE_VAR]);
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) {
            if (is_string($sql) && strpos($sql, 'woocommerce_order_items') !== false) {
                return (string) self::ORDER_ID;
            }
            if (is_string($sql) && strpos($sql, 'COUNT(*)') !== false) {
                return '5';
            }

            return '1';
        });

        $calls_before = count($GLOBALS['intersoccer_test_wc_stock_calls']);
        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '0',
        ]);
        $this->assertTrue($response['data']['needs_confirm'] ?? false);
        $this->assertSame($calls_before, count($GLOBALS['intersoccer_test_wc_stock_calls']));
    }

    public function test_partial_batch_adjusts_stock_only_for_moved_order_items(): void {
        $this->bindProducts();
        global $intersoccer_test_wc_order_item_meta;
        $item_moved = 93012;
        $item_blocked = 93013;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [];
        $intersoccer_test_wc_order_item_meta[$item_moved] = [];
        $this->assertTrue(intersoccer_roster_move_apply_stock_for_completed_move(
            self::ITEM_ID,
            self::SOURCE_VAR,
            self::TARGET_VAR
        ));
        $calls_after_first = count($GLOBALS['intersoccer_test_wc_stock_calls']);
        $this->assertGreaterThan(0, $calls_after_first);
        $this->assertNull(intersoccer_roster_move_read_stock_delta($item_blocked));
        $this->assertNotNull(intersoccer_roster_move_read_stock_delta(self::ITEM_ID));
    }
}
