<?php
/**
 * RR #104 — existing `_intersoccer_canonical_*` rows follow destination variation on roster move.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersCanonicalOrderMetaMoveTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private static bool $rosterRowFilterRegistered = false;

    private const ORDER_ID = 96201;
    private const ITEM_ID = 96211;
    private const PARENT_ID = 96200;
    private const SOURCE_VAR = 96210;
    private const TARGET_VAR = 96220;

    private const OLD_VENUE_SLUG = 'geneva-college-varembe';
    private const NEW_VENUE_SLUG = 'stade-de-vessy';

    protected function setUp(): void {
        parent::setUp();
        $this->resetMovePlayersTestGlobals();
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;

        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_get_product_type_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'camp';
        };
        $intersoccer_test_get_product_type_callback = static function () {
            return 'camp';
        };

        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-item-renormalize-suppress.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__) . '/stubs/get-product-type-stub.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-reason-messages.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-audit.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
        require_once dirname(__DIR__, 2) . '/includes/advanced.php';

        if (!self::$rosterRowFilterRegistered) {
            add_filter(
                'intersoccer_roster_move_order_item_has_roster_row',
                static function ($has, $order_item_id) {
                    if ($has !== null) {
                        return $has;
                    }
                    $map = $GLOBALS['intersoccer_test_roster_row_filter_map'] ?? null;
                    if (!is_array($map) || !array_key_exists((int) $order_item_id, $map)) {
                        return null;
                    }

                    return (bool) $map[(int) $order_item_id];
                },
                10,
                2
            );
            self::$rosterRowFilterRegistered = true;
        }
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_emit_wp_hooks'],
            $GLOBALS['intersoccer_test_roster_row_filter_map'],
            $GLOBALS['intersoccer_test_roster_store'],
            $GLOBALS['intersoccer_test_wc_order_item_meta'],
            $GLOBALS['intersoccer_test_wc_order_item_data'],
            $GLOBALS['intersoccer_test_current_user']
        );
        $this->resetMovePlayersTestGlobals();
        $_POST = [];
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $options
     */
    private function installHandlerWorld(array $options = []): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_post_status_map;
        global $intersoccer_test_wc_get_product_callback;

        $source_venue = (string) ($options['source_venue_slug'] ?? self::OLD_VENUE_SLUG);
        $target_venue = (string) ($options['target_venue_slug'] ?? self::NEW_VENUE_SLUG);
        $initial_canonical_venue = $options['initial_canonical_venue'] ?? self::OLD_VENUE_SLUG;

        $GLOBALS['intersoccer_test_roster_row_filter_map'] = [self::ITEM_ID => true];
        $GLOBALS['intersoccer_test_roster_store'] = [
            self::ITEM_ID => [
                'id' => 862,
                'order_item_id' => self::ITEM_ID,
                'order_id' => self::ORDER_ID,
                'variation_id' => self::SOURCE_VAR,
                'product_id' => self::PARENT_ID,
                'event_signature' => 'canonical-venue-sig',
                'booking_type' => 'Full Week',
                'venue' => 'Varembe display',
                'activity_type' => 'Camp',
                'girls_only' => 0,
            ],
        ];

        $GLOBALS['intersoccer_test_wc_stock_qty'] = [
            self::SOURCE_VAR => 2,
            self::TARGET_VAR => 3,
        ];
        $GLOBALS['intersoccer_test_wc_stock_calls'] = [];
        $GLOBALS['intersoccer_test_wc_update_stock_callback'] = static function ($product, $amount, $mode) {
            $id = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
            if ($id <= 0 || !isset($GLOBALS['intersoccer_test_wc_stock_qty'][$id])) {
                return true;
            }
            $qty = (int) $GLOBALS['intersoccer_test_wc_stock_qty'][$id];
            if ($mode === 'increase') {
                $qty += (int) $amount;
            } elseif ($mode === 'decrease') {
                $qty -= (int) $amount;
            }
            $GLOBALS['intersoccer_test_wc_stock_qty'][$id] = $qty;

            return $qty;
        };

        $intersoccer_test_post_status_map = [
            self::SOURCE_VAR => 'publish',
            self::TARGET_VAR => 'publish',
            self::PARENT_ID => 'publish',
        ];

        $item_meta = [
            'Activity Type' => 'Camp',
            'Assigned Attendee' => 'Player Canonical Venue',
            'Sites InterSoccer' => 'Varembe display',
        ];
        if ($initial_canonical_venue !== null && $initial_canonical_venue !== false) {
            $item_meta['_intersoccer_canonical_venue'] = (string) $initial_canonical_venue;
        }

        $intersoccer_test_wc_order_item_meta = [
            self::ITEM_ID => $item_meta,
        ];
        $intersoccer_test_wc_order_item_data[self::ITEM_ID] = [
            'product_id' => self::PARENT_ID,
            'variation_id' => self::SOURCE_VAR,
            'subtotal' => 100.00,
            'total' => 100.00,
            'name' => 'Camp line',
        ];

        $item = new \WC_Order_Item_Product(self::ITEM_ID);
        $order = new \WC_Order(self::ORDER_ID);
        $order->set_items([self::ITEM_ID => $item]);
        $intersoccer_test_wc_get_order_callback = static function ($id) use ($order) {
            return (int) $id === self::ORDER_ID ? $order : false;
        };

        $make_variation = static function (int $vid, string $venue_slug) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
            $v->shouldReceive('managing_stock')->andReturn(true);
            $v->shouldReceive('backorders_allowed')->andReturn(false);
            $v->shouldReceive('get_stock_quantity')->andReturnUsing(static function () use ($vid) {
                return (int) ($GLOBALS['intersoccer_test_wc_stock_qty'][$vid] ?? 0);
            });
            $v->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
            $attrs = [
                'attribute_pa_intersoccer-venues' => $venue_slug,
                'attribute_pa_girls-only' => 'no',
            ];
            $v->shouldReceive('get_variation_attributes')->andReturn($attrs);
            $v->shouldReceive('get_attribute')->andReturnUsing(static function ($name) use ($venue_slug) {
                if ($name === 'pa_intersoccer-venues' || $name === 'intersoccer-venues') {
                    return $venue_slug;
                }
                if ($name === 'pa_girls-only' || $name === 'girls-only') {
                    return 'no';
                }

                return '';
            });
            $v->shouldReceive('get_attributes')->andReturn([]);
            $v->shouldReceive('get_name')->andReturn('Variation ' . $vid);
            $v->shouldReceive('get_price')->andReturn('100');
            $v->shouldReceive('is_purchasable')->andReturn(true);
            $v->shouldReceive('is_type')->with('variation')->andReturn(true);
            $v->shouldReceive('get_stock_status')->andReturn('instock');
            $v->shouldReceive('set_stock_quantity')->never();

            return $v;
        };

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent->shouldReceive('get_name')->andReturn('Camp parent');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($make_variation, $parent, $source_venue, $target_venue) {
            $id = (int) $id;
            if ($id === self::PARENT_ID) {
                return $parent;
            }
            if ($id === self::SOURCE_VAR) {
                return $make_variation($id, $source_venue);
            }
            if ($id === self::TARGET_VAR) {
                return $make_variation($id, $target_venue);
            }

            return false;
        };

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query, ...$args) {
            $flat_args = [];
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    $flat_args = array_merge($flat_args, $arg);
                } else {
                    $flat_args[] = $arg;
                }
            }
            $safe_args = array_map(static function ($v) {
                return is_string($v) ? addslashes($v) : $v;
            }, $flat_args);

            return @vsprintf(str_replace(['%s', '%d', '%f'], ["'%s'", '%d', '%f'], (string) $query), $safe_args) ?: (string) $query;
        });
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('query')->andReturn(true);
        $wpdb->shouldReceive('get_row')->andReturnUsing(static function ($sql) {
            if (!is_string($sql) || strpos($sql, 'intersoccer_rosters') === false) {
                return null;
            }
            if (strpos($sql, 'order_item_id') !== false) {
                foreach ($GLOBALS['intersoccer_test_roster_store'] as $store_row) {
                    $oid = (int) ($store_row['order_item_id'] ?? 0);
                    if ($oid > 0 && strpos($sql, (string) $oid) !== false) {
                        return $store_row;
                    }
                }
            }
            if (strpos($sql, ' WHERE id = ') !== false || strpos($sql, 'WHERE id =') !== false) {
                return $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID] ?? null;
            }

            return null;
        });
        $wpdb->shouldReceive('update')->andReturnUsing(static function ($table, $data, $where) {
            if (!is_array($data) || !is_array($where)) {
                return false;
            }
            $store = &$GLOBALS['intersoccer_test_roster_store'];
            foreach ($store as $key => $row) {
                if (isset($where['order_item_id']) && (int) $row['order_item_id'] === (int) $where['order_item_id']) {
                    $store[$key] = array_merge($row, $data);

                    return 1;
                }
            }

            return 1;
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) {
            if (!is_string($sql)) {
                return null;
            }
            if (strpos($sql, 'woocommerce_order_items') !== false) {
                return (string) self::ORDER_ID;
            }
            if (strpos($sql, 'intersoccer_rosters') !== false && strpos($sql, 'order_item_id') !== false) {
                return '862';
            }
            if (strpos($sql, 'event_completed') !== false || strpos($sql, 'COUNT(*)') !== false) {
                return '0';
            }

            return '0';
        });

        $GLOBALS['intersoccer_test_current_user'] = (object) ['ID' => 1, 'roles' => ['administrator']];
    }

    private function assertSingleMetaValue(int $order_item_id, string $key, string $expected): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->assertArrayHasKey($key, $intersoccer_test_wc_order_item_meta[$order_item_id] ?? []);
        $this->assertSame($expected, (string) $intersoccer_test_wc_order_item_meta[$order_item_id][$key]);
        $this->assertSame($expected, (string) wc_get_order_item_meta($order_item_id, $key, true));
    }

    private function assertMetaKeyAbsent(int $order_item_id, string $key): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->assertArrayNotHasKey($key, $intersoccer_test_wc_order_item_meta[$order_item_id] ?? []);
        $this->assertSame('', (string) wc_get_order_item_meta($order_item_id, $key, true));
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

    public function test_move_updates_existing_canonical_venue_to_destination(): void {
        $this->installHandlerWorld();

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
        ]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
        $this->assertSingleMetaValue(self::ITEM_ID, '_intersoccer_canonical_venue', self::NEW_VENUE_SLUG);
    }

    public function test_move_does_not_add_canonical_venue_when_line_had_none(): void {
        $this->installHandlerWorld(['initial_canonical_venue' => false]);

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
        ]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
        $this->assertMetaKeyAbsent(self::ITEM_ID, '_intersoccer_canonical_venue');
    }

    public function test_reconcile_reader_after_move_prefers_updated_canonical_venue(): void {
        $this->installHandlerWorld();

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
        ]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
        $this->assertSingleMetaValue(self::ITEM_ID, '_intersoccer_canonical_venue', self::NEW_VENUE_SLUG);

        global $intersoccer_test_wc_order_item_meta;
        unset(
            $intersoccer_test_wc_order_item_meta[self::ITEM_ID]['attribute_pa_intersoccer-venues'],
            $intersoccer_test_wc_order_item_meta[self::ITEM_ID]['pa_intersoccer-venues']
        );

        $data = ['venue' => self::OLD_VENUE_SLUG];
        $item = new \WC_Order_Item_Product(self::ITEM_ID);
        $overlay = intersoccer_apply_order_item_attribute_meta_to_data($data, $item);
        $this->assertSame(self::NEW_VENUE_SLUG, $overlay['venue']);

        $meta_map = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame(
            self::NEW_VENUE_SLUG,
            intersoccer_get_order_item_meta_field_value($meta_map, 'venue')
        );
    }
}
