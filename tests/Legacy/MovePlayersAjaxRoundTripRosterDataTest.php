<?php
/**
 * Roster move AJAX round trip — handler path with stateful roster store.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersAjaxRoundTripRosterDataTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private static bool $rosterRowFilterRegistered = false;

    private const ORDER_ID = 92101;
    private const ITEM_ID = 92111;
    private const SIBLING_ITEM_ID = 92112;
    private const PARENT_ID = 92100;
    private const SOURCE_VAR = 92110;
    private const SOURCE_VAR_FR = 92115;
    private const TARGET_VAR = 92120;

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
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
            $GLOBALS['intersoccer_test_apply_filters_callback'],
            $GLOBALS['intersoccer_test_wc_stock_calls'],
            $GLOBALS['intersoccer_test_wc_update_stock_callback']
        );
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

    /**
     * @return array<string,mixed>
     */
    private function baselineRow(): array {
        return [
            'id' => 601,
            'order_item_id' => self::ITEM_ID,
            'order_id' => self::ORDER_ID,
            'variation_id' => self::SOURCE_VAR_FR,
            'product_id' => self::PARENT_ID,
            'activity_type' => 'Course',
            'booking_type' => 'Full Term',
            'selected_days' => '',
            'days_selected' => '',
            'day_presence' => '',
            'city' => 'geneva',
            'canton_region' => 'Geneva',
            'event_signature' => 'ajax-roundtrip-shared-sig',
            'product_name' => 'Course FR',
            'venue' => 'Venue A',
            'age_group' => '6-8',
            'girls_only' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $baseline
     */
    private function installAjaxRoundTripWorld(array $baseline): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_wc_get_product_callback;
        global $intersoccer_test_post_status_map;

        $shared_sig = (string) ($baseline['event_signature'] ?? '');

        $GLOBALS['intersoccer_test_roster_row_filter_map'] = [
            self::ITEM_ID => true,
        ];
        $GLOBALS['intersoccer_test_roster_store'] = [
            self::ITEM_ID => $baseline,
            self::SIBLING_ITEM_ID => [
                'id' => 602,
                'order_item_id' => self::SIBLING_ITEM_ID,
                'variation_id' => self::SOURCE_VAR,
                'event_signature' => $shared_sig,
            ],
        ];

        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) {
            if ($hook === 'wpml_object_id'
                && isset($args[0])
                && $args[0] === 'product_variation'
                && (int) $value === self::SOURCE_VAR_FR) {
                return self::SOURCE_VAR;
            }

            return $value;
        };

        $GLOBALS['intersoccer_test_wc_stock_calls'] = [];
        $GLOBALS['intersoccer_test_wc_update_stock_callback'] = static function ($product, $amount, $mode) {
            $id = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
            $GLOBALS['intersoccer_test_wc_stock_calls'][] = [
                'variation_id' => $id,
                'amount' => (int) $amount,
                'mode' => (string) $mode,
            ];

            return true;
        };

        $intersoccer_test_post_status_map = [
            self::SOURCE_VAR => 'publish',
            self::SOURCE_VAR_FR => 'publish',
            self::TARGET_VAR => 'publish',
            self::PARENT_ID => 'publish',
        ];

        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [
            'Activity Type' => 'Course',
            'Assigned Attendee' => 'Test Player',
            'City' => 'Geneva',
            'Canton' => 'Geneva',
            'Type de réservation' => 'Trimestre complet',
            '_intersoccer_canonical_booking_type' => 'full-term',
        ];
        $intersoccer_test_wc_order_item_data[self::ITEM_ID] = [
            'product_id' => self::PARENT_ID,
            'variation_id' => self::SOURCE_VAR_FR,
            'subtotal' => 120.50,
            'total' => 100.00,
            'name' => 'Source course FR',
        ];

        $item = new \WC_Order_Item_Product(self::ITEM_ID);
        $order = new \WC_Order(self::ORDER_ID);
        $order->set_items([self::ITEM_ID => $item]);

        $intersoccer_test_wc_get_order_callback = static function ($id) use ($order) {
            return (int) $id === self::ORDER_ID ? $order : false;
        };

        $make_variation = static function (int $vid, array $overrides = []) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
            $v->shouldReceive('get_variation_attributes')->andReturn([]);
            $v->shouldReceive('get_name')->andReturn('Variation ' . $vid);
            $v->shouldReceive('get_attribute')->andReturn('');
            $v->shouldReceive('get_attributes')->andReturn([]);
            $v->shouldReceive('get_meta')->with('capacity', true)->andReturn($overrides['capacity'] ?? '');
            $v->shouldReceive('get_price')->andReturn($overrides['price'] ?? '100');
            $v->shouldReceive('is_purchasable')->andReturn($overrides['is_purchasable'] ?? true);
            $v->shouldReceive('is_type')->with('variation')->andReturn(true);
            $v->shouldReceive('managing_stock')->andReturn($overrides['managing_stock'] ?? false);
            $v->shouldReceive('backorders_allowed')->andReturn($overrides['backorders_allowed'] ?? false);
            $v->shouldReceive('get_stock_quantity')->andReturn($overrides['stock_quantity'] ?? 5);
            $v->shouldReceive('get_stock_status')->andReturn($overrides['stock_status'] ?? 'instock');
            $v->shouldReceive('set_stock_quantity')->never();
            $v->shouldReceive('set_stock_status')->never();
            $v->shouldReceive('save')->never();

            return $v;
        };

        $source_fr = $make_variation(self::SOURCE_VAR_FR);
        $source_en = $make_variation(self::SOURCE_VAR);
        $target = $make_variation(self::TARGET_VAR, ['is_purchasable' => true]);

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent->shouldReceive('get_name')->andReturn('Parent course');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($source_fr, $source_en, $target, $parent) {
            $id = (int) $id;
            if ($id === self::SOURCE_VAR_FR) {
                return $source_fr;
            }
            if ($id === self::SOURCE_VAR) {
                return $source_en;
            }
            if ($id === self::TARGET_VAR) {
                return $target;
            }
            if ($id === self::PARENT_ID) {
                return $parent;
            }

            return false;
        };

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return (string) $query;
        });
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('query')->andReturn(true);
        $wpdb->shouldReceive('get_row')->andReturnUsing(static function ($sql) {
            if (!is_string($sql) || strpos($sql, 'intersoccer_rosters') === false) {
                return null;
            }
            if (strpos($sql, 'order_item_id') !== false) {
                foreach ($GLOBALS['intersoccer_test_roster_store'] as $row) {
                    if ((int) ($row['order_item_id'] ?? 0) === self::ITEM_ID) {
                        return $row;
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
                $match = true;
                foreach ($where as $field => $value) {
                    if ((string) ($row[$field] ?? '') !== (string) $value) {
                        $match = false;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
                $store[$key] = array_merge($row, $data);

                return 1;
            }

            return 0;
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) use ($shared_sig) {
            if (!is_string($sql)) {
                return null;
            }
            if (strpos($sql, 'event_signature') !== false && strpos($sql, 'variation_id') !== false) {
                return $shared_sig;
            }
            if (strpos($sql, 'woocommerce_order_items') !== false) {
                return (string) self::ORDER_ID;
            }
            if (strpos($sql, 'intersoccer_rosters') !== false && strpos($sql, 'order_item_id') !== false) {
                return '601';
            }
            if (strpos($sql, 'event_completed') !== false) {
                return '0';
            }
            if (strpos($sql, 'COUNT(*)') !== false) {
                return '0';
            }

            return '0';
        });
    }

    public function test_ajax_round_trip_from_translated_source_restores_roster_columns_and_default_variation_id(): void {
        $baseline = $this->baselineRow();
        $this->installAjaxRoundTripWorld($baseline);

        $to_target = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
            'allow_cross_gender' => '0',
        ]);
        $this->assertTrue($to_target['success'] ?? false);
        $this->assertSame(1, (int) ($to_target['data']['moved_count'] ?? 0));
        $this->assertSame(
            self::TARGET_VAR,
            (int) ($GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID]['variation_id'] ?? 0)
        );

        $to_source = $this->invokeMoveAjax([
            'target_variation_id' => self::SOURCE_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
            'allow_cross_gender' => '0',
        ]);
        $this->assertTrue($to_source['success'] ?? false);
        $this->assertSame(1, (int) ($to_source['data']['moved_count'] ?? 0));

        $after = $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID];
        foreach (['booking_type', 'selected_days', 'days_selected', 'day_presence', 'city', 'canton_region', 'event_signature'] as $field) {
            $this->assertSame($baseline[$field], $after[$field], $field);
        }
        $this->assertSame(self::SOURCE_VAR, (int) $after['variation_id']);
        $this->assertSame(
            self::SOURCE_VAR,
            (int) ($GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_ID]['variation_id'] ?? 0)
        );
    }
}
