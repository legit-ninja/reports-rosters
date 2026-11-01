<?php
/**
 * Append-only roster move history (order item meta + admin read-only view).
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersHistoryTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private static bool $rosterRowFilterRegistered = false;

    private const ORDER_ID = 94001;
    private const ITEM_ID = 94011;
    private const ITEM_BATCH_OK = 94012;
    private const ITEM_BLOCK = 94013;
    private const PARENT_ID = 94000;
    private const SOURCE_VAR = 94010;
    private const TARGET_VAR = 94020;
    private const ORIGINAL_VAR = 94015;
    private const PEER_ITEM_ID = 94099;

    protected function setUp(): void {
        parent::setUp();
        $this->resetMovePlayersTestGlobals();
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;

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

        if (!function_exists('intersoccer_user_can_view_roster_admin')) {
            eval('function intersoccer_user_can_view_roster_admin() { return true; }');
        }
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_emit_wp_hooks'],
            $GLOBALS['intersoccer_test_roster_row_filter_map'],
            $GLOBALS['intersoccer_test_roster_store'],
            $GLOBALS['intersoccer_test_roster_peer_store'],
            $GLOBALS['intersoccer_test_roster_peer_queries'],
            $GLOBALS['intersoccer_test_userdata_map'],
            $GLOBALS['intersoccer_test_current_user']
        );
        $this->resetMovePlayersTestGlobals();
        $_POST = [];
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function baselineRosterRow(int $variation_id = self::SOURCE_VAR): array {
        return [
            'id' => 701,
            'order_item_id' => self::ITEM_ID,
            'order_id' => self::ORDER_ID,
            'variation_id' => $variation_id,
            'product_id' => self::PARENT_ID,
            'event_signature' => 'hist-sig-abc',
            'booking_type' => 'Full Term',
            'selected_days' => '',
            'days_selected' => '',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-15',
            'camp_terms' => '',
            'course_day' => 'Saturday',
            'product_name' => 'History Course EN',
            'venue' => 'Geneva Centre',
            'age_group' => '6-8',
            'times' => 'Morning',
            'season' => 'Autumn 2026',
            'order_date' => '2026-08-20 10:00:00',
            'city' => 'geneva',
            'canton_region' => 'Geneva',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function originalPeerRosterRow(): array {
        return [
            'id' => 710,
            'order_item_id' => self::PEER_ITEM_ID,
            'order_id' => self::ORDER_ID,
            'variation_id' => self::ORIGINAL_VAR,
            'product_id' => self::PARENT_ID,
            'event_signature' => 'hist-sig-original',
            'booking_type' => 'Full Term',
            'selected_days' => '',
            'days_selected' => '',
            'start_date' => '2026-08-01',
            'end_date' => '2026-11-30',
            'camp_terms' => '',
            'course_day' => 'Friday',
            'product_name' => 'History Course Original EN',
            'venue' => 'Lausanne Centre',
            'age_group' => '9-11',
            'times' => 'Afternoon',
            'season' => 'Autumn 2026',
            'order_date' => '2026-07-15 10:00:00',
            'city' => 'lausanne',
            'canton_region' => 'Vaud',
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    private function installHandlerWorld(array $options = []): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_post_status_map;
        global $intersoccer_test_wc_get_product_callback;

        $item_ids = $options['order_item_ids'] ?? [self::ITEM_ID];
        $roster_map = $options['roster_row_map'] ?? [self::ITEM_ID => true];
        $GLOBALS['intersoccer_test_roster_row_filter_map'] = $roster_map;

        $baseline = $this->baselineRosterRow();
        $GLOBALS['intersoccer_test_roster_store'] = $options['roster_store'] ?? [
            self::ITEM_ID => $baseline,
        ];
        $GLOBALS['intersoccer_test_roster_peer_store'] = $options['roster_peer_store'] ?? [];
        $GLOBALS['intersoccer_test_roster_peer_queries'] = 0;

        $GLOBALS['intersoccer_test_wc_stock_qty'] = [
            self::SOURCE_VAR => 2,
            self::TARGET_VAR => $options['target_stock_quantity'] ?? 3,
        ];
        $GLOBALS['intersoccer_test_wc_stock_calls'] = [];
        $GLOBALS['intersoccer_test_wc_update_stock_callback'] = static function ($product, $amount, $mode) {
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
            }
            $GLOBALS['intersoccer_test_wc_stock_qty'][$id] = $qty;

            return $qty;
        };

        $intersoccer_test_post_status_map = [
            self::SOURCE_VAR => 'publish',
            self::TARGET_VAR => 'publish',
            self::ORIGINAL_VAR => 'publish',
            self::PARENT_ID => 'publish',
        ];

        $intersoccer_test_wc_order_item_meta = [];
        $intersoccer_test_wc_order_item_data = [];
        foreach ($item_ids as $item_id) {
            $item_id = (int) $item_id;
            $intersoccer_test_wc_order_item_meta[$item_id] = [
                'Activity Type' => 'Course',
                'Assigned Attendee' => 'Player ' . $item_id,
            ];
            $intersoccer_test_wc_order_item_data[$item_id] = [
                'product_id' => self::PARENT_ID,
                'variation_id' => self::SOURCE_VAR,
                'subtotal' => 100.00,
                'total' => 100.00,
                'name' => 'Course line',
            ];
        }

        $items = [];
        foreach ($item_ids as $item_id) {
            $items[(int) $item_id] = new \WC_Order_Item_Product((int) $item_id);
        }
        $order = new \WC_Order(self::ORDER_ID);
        $order->set_items($items);
        $intersoccer_test_wc_get_order_callback = static function ($id) use ($order) {
            return (int) $id === self::ORDER_ID ? $order : false;
        };

        $make_variation = static function (int $vid, array $overrides = []) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
            $v->shouldReceive('managing_stock')->andReturn($overrides['managing_stock'] ?? true);
            $v->shouldReceive('backorders_allowed')->andReturn($overrides['backorders_allowed'] ?? false);
            $v->shouldReceive('get_stock_quantity')->andReturnUsing(static function () use ($vid) {
                return (int) ($GLOBALS['intersoccer_test_wc_stock_qty'][$vid] ?? 0);
            });
            $roster_meta = is_array($overrides['roster_meta'] ?? null) ? $overrides['roster_meta'] : [];
            foreach ($roster_meta as $meta_key => $meta_value) {
                $v->shouldReceive('get_meta')->with($meta_key, true)->andReturn((string) $meta_value);
            }
            $v->shouldReceive('get_meta')->with('capacity', true)->andReturn($overrides['capacity'] ?? '');
            $v->shouldReceive('get_meta')->andReturn('');
            $v->shouldReceive('get_variation_attributes')->andReturn($overrides['variation_attributes'] ?? []);
            $attribute_map = is_array($overrides['variation_attribute_map'] ?? null) ? $overrides['variation_attribute_map'] : [];
            foreach ($attribute_map as $taxonomy => $attribute_value) {
                $v->shouldReceive('get_attribute')->with($taxonomy)->andReturn((string) $attribute_value);
            }
            $v->shouldReceive('get_attribute')->andReturn('');
            $v->shouldReceive('get_attributes')->andReturn([]);
            $v->shouldReceive('get_name')->andReturn($overrides['variation_name'] ?? ('Variation ' . $vid));
            $v->shouldReceive('get_price')->andReturn($overrides['price'] ?? '100');
            $v->shouldReceive('is_purchasable')->andReturn($overrides['is_purchasable'] ?? true);
            $v->shouldReceive('is_type')->with('variation')->andReturn(true);
            $v->shouldReceive('get_stock_status')->andReturn('instock');
            $v->shouldReceive('set_stock_quantity')->never();
            $v->shouldReceive('set_stock_status')->never();
            $v->shouldReceive('save')->never();

            return $v;
        };

        $source = $make_variation(self::SOURCE_VAR, $options['source_overrides'] ?? []);
        $target = $make_variation(self::TARGET_VAR, $options['target_overrides'] ?? []);
        $original = $make_variation(self::ORIGINAL_VAR, array_merge(
            $this->catalogOriginalVariationOverrides(),
            $options['original_overrides'] ?? []
        ));

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent->shouldReceive('get_name')->andReturn('Parent');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($source, $target, $original, $parent) {
            $id = (int) $id;
            if ($id === self::SOURCE_VAR) {
                return $source;
            }
            if ($id === self::TARGET_VAR) {
                return $target;
            }
            if ($id === self::ORIGINAL_VAR) {
                return $original;
            }
            if ($id === self::PARENT_ID) {
                return $parent;
            }

            return false;
        };

        $target_capacity_count = (string) ($options['target_capacity_count'] ?? '0');

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query, ...$args) {
            if ($args === []) {
                return (string) $query;
            }
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
            if (strpos($sql, 'variation_id') !== false && strpos($sql, 'order_item_id !=') !== false) {
                $GLOBALS['intersoccer_test_roster_peer_queries'] = (int) ($GLOBALS['intersoccer_test_roster_peer_queries'] ?? 0) + 1;

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
            foreach ($store as $key => $row) {
                if (isset($where['order_item_id']) && (int) $row['order_item_id'] === (int) $where['order_item_id']) {
                    $store[$key] = array_merge($row, $data);

                    return 1;
                }
            }

            return 1;
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) use ($target_capacity_count) {
            if (!is_string($sql)) {
                return null;
            }
            if (strpos($sql, 'woocommerce_order_items') !== false) {
                return (string) self::ORDER_ID;
            }
            if (strpos($sql, 'intersoccer_rosters') !== false && strpos($sql, 'order_item_id') !== false) {
                return '701';
            }
            if (strpos($sql, 'event_completed') !== false) {
                return '0';
            }
            if (strpos($sql, 'COUNT(*)') !== false) {
                return $target_capacity_count;
            }

            return '0';
        });

        $GLOBALS['intersoccer_test_userdata_map'] = [
            55 => (object) ['ID' => 55, 'display_name' => 'History Admin'],
        ];
        $GLOBALS['intersoccer_test_current_user'] = (object) ['ID' => 55, 'roles' => ['administrator']];
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

    public function test_first_move_writes_origin_with_required_fields_and_not_backfilled(): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->installHandlerWorld();
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $entries = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertCount(2, $entries);
        $origin = $entries[0];
        $this->assertSame('origin', $origin['type']);
        $this->assertFalse($origin['backfilled']);
        $this->assertSame(self::ORDER_ID, (int) $origin['order_id']);
        $this->assertSame(self::ITEM_ID, (int) $origin['order_item_id']);
        $this->assertSame(self::SOURCE_VAR, (int) $origin['variation_id']);
        $this->assertSame('hist-sig-abc', $origin['event_signature']);
        $this->assertSame('Full Term', $origin['booking_type']);
        $this->assertNotSame('', (string) ($origin['order_date'] ?? ''));
        $this->assertIsArray($origin['label']);
        $this->assertStringContainsString('History Course EN', (string) $origin['label']['product']);
        $this->assertNotSame('', trim((string) ($origin['note'] ?? '')));
        $this->assertStringContainsString('History Course EN', (string) ($origin['note'] ?? ''));
    }

    public function test_move_through_handler_appends_full_move_record(): void {
        $this->installHandlerWorld();

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $entries = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertCount(2, $entries);
        $move = $entries[1];
        $this->assertSame('move', $move['type']);
        $this->assertSame(self::SOURCE_VAR, (int) ($move['from']['variation_id'] ?? 0));
        $this->assertSame(self::TARGET_VAR, (int) ($move['to']['variation_id'] ?? 0));
        $this->assertSame(55, (int) ($move['actor_user_id'] ?? 0));
        $this->assertSame('History Admin', (string) ($move['actor_display_name'] ?? ''));
        $this->assertNotSame('', (string) ($move['at'] ?? ''));
        $this->assertFalse($move['move_anyway']);
        $this->assertSame([], $move['overridden_reasons']);
        $this->assertSame(1, (int) ($move['stock']['applied_from'] ?? 0));
        $this->assertSame(-1, (int) ($move['stock']['applied_to'] ?? 0));
        $this->assertStringContainsString('History Course EN', (string) ($move['from']['label']['product'] ?? ''));
    }

    public function test_move_back_appends_second_move_without_changing_origin(): void {
        $this->installHandlerWorld();

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $origin_after_first = intersoccer_roster_history_get_entries(self::ITEM_ID)[0];

        $this->invokeMoveAjax([
            'target_variation_id' => self::SOURCE_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);

        $entries = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertCount(3, $entries);
        $this->assertSame(1, (int) ($entries[0]['seq'] ?? 0));
        $this->assertSame(2, (int) ($entries[1]['seq'] ?? 0));
        $this->assertSame(3, (int) ($entries[2]['seq'] ?? 0));
        $this->assertSame($origin_after_first, $entries[0]);
        $first_move = $entries[1];
        $this->assertSame(1, (int) ($first_move['stock']['applied_from'] ?? 0));
        $this->assertSame(-1, (int) ($first_move['stock']['applied_to'] ?? 0));
        $back_move = $entries[2];
        $this->assertSame('move', $back_move['type']);
        $this->assertSame(1, (int) ($back_move['stock']['applied_from'] ?? 0));
        $this->assertSame(-1, (int) ($back_move['stock']['applied_to'] ?? 0));
    }

    public function test_forward_move_with_destination_stock_one_records_applied_decrease(): void {
        $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR] = 1;
        $this->installHandlerWorld(['target_stock_quantity' => 1]);

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
        $move = intersoccer_roster_history_get_entries(self::ITEM_ID)[1];
        $this->assertSame(1, (int) ($move['stock']['applied_from'] ?? 0));
        $this->assertSame(-1, (int) ($move['stock']['applied_to'] ?? 0));
    }

    public function test_move_back_after_capped_move_anyway_records_reversal_stock(): void {
        $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR] = 0;
        $this->installHandlerWorld([
            'target_stock_quantity' => 0,
            'target_overrides' => [
                'price' => '',
                'is_purchasable' => false,
                'capacity' => '2',
            ],
            'target_capacity_count' => '2',
        ]);

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $entries = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertSame(1, (int) ($entries[1]['stock']['applied_from'] ?? 0));
        $this->assertSame(0, (int) ($entries[1]['stock']['applied_to'] ?? 0));

        $this->invokeMoveAjax([
            'target_variation_id' => self::SOURCE_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $entries = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertSame(0, (int) ($entries[2]['stock']['applied_from'] ?? -1));
        $this->assertSame(-1, (int) ($entries[2]['stock']['applied_to'] ?? 0));
    }

    public function test_unmanaged_stock_move_records_zero_in_history(): void {
        $this->installHandlerWorld([
            'target_overrides' => ['managing_stock' => false],
            'source_overrides' => ['managing_stock' => false],
        ]);

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
        $move = intersoccer_roster_history_get_entries(self::ITEM_ID)[1];
        $this->assertSame(0, (int) ($move['stock']['applied_from'] ?? -1));
        $this->assertSame(0, (int) ($move['stock']['applied_to'] ?? -1));
    }

    public function test_move_anyway_records_overridden_reasons_and_capped_stock(): void {
        $GLOBALS['intersoccer_test_wc_stock_qty'][self::TARGET_VAR] = 0;
        $this->installHandlerWorld([
            'target_stock_quantity' => 0,
            'target_overrides' => [
                'price' => '',
                'is_purchasable' => false,
                'capacity' => '2',
            ],
            'target_capacity_count' => '2',
        ]);

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
        $move = intersoccer_roster_history_get_entries(self::ITEM_ID)[1];
        $this->assertTrue($move['move_anyway']);
        $this->assertNotSame([], $move['overridden_reasons']);
        $this->assertSame(0, (int) ($move['stock']['applied_to'] ?? -1));
    }

    /** Items with no persisted history meta: first move writes origin once and re-read is idempotent. */
    public function test_no_history_meta_first_move_is_idempotent_and_not_backfilled(): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->installHandlerWorld();
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);

        $before_read = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertSame([], $before_read);
        $this->assertSame('', intersoccer_roster_history_read_raw(self::ITEM_ID));

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);

        $first = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertCount(2, $first);
        $this->assertFalse($first[0]['backfilled']);
        $this->assertSame(self::SOURCE_VAR, (int) ($first[0]['variation_id'] ?? 0));
        $this->assertSame('hist-sig-abc', $first[0]['event_signature'] ?? '');
        $this->assertStringContainsString('History Course EN', (string) ($first[0]['label']['product'] ?? ''));

        $second = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertCount(2, $second);
        $this->assertSame($first[0]['seq'], $second[0]['seq']);
    }

    /** Items with no persisted history meta: origin matches the pre-move roster side. */
    public function test_no_history_meta_first_move_origin_uses_pre_move_roster_side(): void {
        $this->installHandlerWorld();
        global $intersoccer_test_wc_order_item_meta;
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $entries = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertCount(2, $entries);
        $origin = $entries[0];
        $move = $entries[1];
        $this->assertSame('origin', $origin['type']);
        $this->assertFalse($origin['backfilled']);
        $this->assertSame(self::SOURCE_VAR, (int) ($origin['variation_id'] ?? 0));
        $this->assertSame(self::SOURCE_VAR, (int) ($move['from']['variation_id'] ?? 0));
        $this->assertSame(self::TARGET_VAR, (int) ($move['to']['variation_id'] ?? 0));
        $this->assertSame($move['from']['label'], $origin['label']);
        $this->assertSame($move['from']['event_signature'], $origin['event_signature']);
        $this->assertSame($move['from']['product_id'], $origin['product_id']);
        $note = trim((string) ($origin['note'] ?? ''));
        $this->assertNotSame('', $note);
        $this->assertStringContainsString('History Course EN', $note);
        $this->assertStringContainsString('first roster move', strtolower($note));
    }

    /** Items with no persisted history meta: origin note names the original roster. */
    public function test_no_history_meta_first_move_origin_note_names_original_roster(): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->installHandlerWorld();
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);

        $origin = intersoccer_roster_history_get_entries(self::ITEM_ID)[0];
        $note = trim((string) ($origin['note'] ?? ''));
        $this->assertNotSame('', $note);
        $this->assertStringContainsString('History Course EN', $note);
        $this->assertSame('move', intersoccer_roster_history_get_entries(self::ITEM_ID)[1]['type']);
    }

    /**
     * @return array<string,mixed>
     */
    private function catalogOriginalVariationOverrides(): array {
        return [
            'variation_name' => 'History Course Original EN',
            'variation_attribute_map' => [
                'pa_intersoccer-venues' => 'Catalog Original Venue',
                'pa_age-group' => '9-11',
                'pa_course-times' => 'Afternoon',
                'pa_course-day' => 'Friday',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $origin
     */
    private function assertInferredOriginFromEarlierMove(array $origin): void {
        $this->assertTrue($origin['backfilled']);
        $this->assertSame(self::ORIGINAL_VAR, (int) ($origin['variation_id'] ?? 0));
        $this->assertSame('', (string) ($origin['event_signature'] ?? ''));
        $this->assertSame('', (string) ($origin['start_date'] ?? ''));
        $this->assertSame('', (string) ($origin['end_date'] ?? ''));
        $this->assertStringContainsString('History Course Original EN', (string) ($origin['label']['product'] ?? ''));
        $this->assertStringContainsString('Catalog Original Venue', (string) ($origin['label']['variation'] ?? ''));
        $this->assertStringNotContainsString('Lausanne Centre', (string) ($origin['label']['variation'] ?? ''));
        $this->assertStringNotContainsString('hist-sig-original', (string) ($origin['event_signature'] ?? ''));
        $note = trim((string) ($origin['note'] ?? ''));
        $this->assertNotSame('', $note);
        $this->assertStringContainsString('Original booking inferred from an earlier move:', $note);
        $this->assertStringContainsString('History Course Original EN', $note);
        $this->assertStringNotContainsString('audit meta', strtolower($note));
        $this->assertSame(0, (int) ($GLOBALS['intersoccer_test_roster_peer_queries'] ?? -1));
    }

    private function seedLatestAuditFromVariation(int $variation_id): void {
        global $intersoccer_test_wc_order_item_meta;
        $keys = intersoccer_roster_move_audit_latest_meta_keys();
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$keys['from_variation_id']] = (string) $variation_id;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$keys['to_variation_id']] = (string) self::TARGET_VAR;
        $this->syncOrderItemMetaFromGlobal(self::ITEM_ID);
    }

    private function seedAuditHistoryFromVariation(int $variation_id): void {
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_move_audit_history_meta_key()] = wp_json_encode([
            intersoccer_roster_move_audit_build_entry($variation_id, self::TARGET_VAR, 55, '2026-09-01T10:00:00Z'),
        ]);
        $this->syncOrderItemMetaFromGlobal(self::ITEM_ID);
    }

    private function syncOrderItemMetaFromGlobal(int $item_id): void {
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_wc_order_item_meta;
        if (!is_callable($intersoccer_test_wc_get_order_callback ?? null)) {
            return;
        }
        $order = $intersoccer_test_wc_get_order_callback(self::ORDER_ID);
        if (!$order || !method_exists($order, 'get_items')) {
            return;
        }
        $meta = $intersoccer_test_wc_order_item_meta[$item_id] ?? [];
        if (!is_array($meta)) {
            return;
        }
        foreach ($order->get_items() as $item) {
            if ((int) $item->get_id() !== $item_id) {
                continue;
            }
            foreach ($meta as $key => $value) {
                $item->update_meta_data($key, $value);
            }
        }
    }

    public function test_first_move_with_latest_audit_meta_infers_original_variation_side(): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->installHandlerWorld([
            'roster_peer_store' => [$this->originalPeerRosterRow()],
        ]);
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);
        $this->seedLatestAuditFromVariation(self::ORIGINAL_VAR);

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);

        $origin = intersoccer_roster_history_get_entries(self::ITEM_ID)[0];
        $this->assertInferredOriginFromEarlierMove($origin);
    }

    public function test_first_move_with_audit_history_meta_infers_original_variation_side(): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->installHandlerWorld([
            'roster_peer_store' => [$this->originalPeerRosterRow()],
        ]);
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);
        $this->seedAuditHistoryFromVariation(self::ORIGINAL_VAR);

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);

        $origin = intersoccer_roster_history_get_entries(self::ITEM_ID)[0];
        $this->assertInferredOriginFromEarlierMove($origin);
    }

    public function test_first_move_when_audit_from_matches_current_variation_marks_backfilled_without_rebuild(): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->installHandlerWorld();
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);
        $this->seedLatestAuditFromVariation(self::SOURCE_VAR);

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);

        $origin = intersoccer_roster_history_get_entries(self::ITEM_ID)[0];
        $this->assertTrue($origin['backfilled']);
        $this->assertSame(self::SOURCE_VAR, (int) ($origin['variation_id'] ?? 0));
        $this->assertSame('hist-sig-abc', $origin['event_signature'] ?? '');
        $this->assertStringContainsString('History Course EN', (string) ($origin['label']['product'] ?? ''));
        $note = trim((string) ($origin['note'] ?? ''));
        $this->assertStringContainsString('Original booking inferred from an earlier move:', $note);
        $this->assertStringContainsString('History Course EN', $note);
    }

    public function test_first_move_when_earlier_variation_unresolved_keeps_from_side(): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->installHandlerWorld();
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);
        $this->seedLatestAuditFromVariation(94077);

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);

        $origin = intersoccer_roster_history_get_entries(self::ITEM_ID)[0];
        $this->assertTrue($origin['backfilled']);
        $this->assertSame(94077, (int) ($origin['variation_id'] ?? 0));
        $this->assertSame('', (string) ($origin['event_signature'] ?? ''));
        $this->assertSame('', trim((string) ($origin['label']['product'] ?? '')));
        $this->assertSame('', trim((string) ($origin['label']['variation'] ?? '')));
        $this->assertSame('', trim((string) ($origin['label']['dates'] ?? '')));
        $note = trim((string) ($origin['note'] ?? ''));
        $from_side = intersoccer_roster_history_roster_side_from_row($this->baselineRosterRow());
        $label_line = intersoccer_roster_history_format_label_line($from_side);
        $this->assertSame(
            'Original booking could not be identified. Roster before this move: ' . $label_line,
            $note
        );
        $this->assertStringNotContainsString('Original booking inferred from an earlier move:', $note);
        $this->assertSame(0, (int) ($GLOBALS['intersoccer_test_roster_peer_queries'] ?? -1));
    }

    public function test_first_move_inferred_origin_without_peer_row_does_not_leak_destination_roster(): void {
        global $intersoccer_test_wc_order_item_meta;
        $baseline = array_merge($this->baselineRosterRow(), [
            'booking_type' => 'Individual Days',
            'selected_days' => 'Mon, Tue',
            'days_selected' => 'Mon, Tue',
        ]);
        $this->installHandlerWorld([
            'roster_store' => [self::ITEM_ID => $baseline],
            'roster_peer_store' => [],
            'original_overrides' => [
                'variation_name' => 'Inferred Original Product EN',
                'variation_attribute_map' => [
                    'pa_intersoccer-venues' => 'Lausanne Variation Venue',
                    'pa_age-group' => '9-11',
                    'pa_course-times' => 'Afternoon',
                    'pa_course-day' => 'Friday',
                ],
            ],
        ]);
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);
        $this->seedLatestAuditFromVariation(self::ORIGINAL_VAR);

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);

        $origin = intersoccer_roster_history_get_entries(self::ITEM_ID)[0];
        $this->assertSame(self::ORIGINAL_VAR, (int) ($origin['variation_id'] ?? 0));
        $this->assertSame('', (string) ($origin['event_signature'] ?? ''));
        $this->assertSame('', (string) ($origin['start_date'] ?? ''));
        $this->assertSame('', (string) ($origin['end_date'] ?? ''));
        $this->assertSame('Individual Days', $origin['booking_type'] ?? '');
        $this->assertSame('Mon, Tue', $origin['selected_days'] ?? '');
        $this->assertSame('Mon, Tue', $origin['days_selected'] ?? '');
        $this->assertStringContainsString('Inferred Original Product EN', (string) ($origin['label']['product'] ?? ''));
        $this->assertStringContainsString('Lausanne Variation Venue', (string) ($origin['label']['variation'] ?? ''));
        $this->assertStringNotContainsString('hist-sig-abc', (string) ($origin['event_signature'] ?? ''));
        $this->assertStringNotContainsString('Geneva Centre', (string) ($origin['label']['variation'] ?? ''));
        $this->assertSame(0, (int) ($GLOBALS['intersoccer_test_roster_peer_queries'] ?? -1));
    }

    public function test_first_move_inferred_origin_keeps_player_booking_fields_from_pre_move_side(): void {
        global $intersoccer_test_wc_order_item_meta;
        $baseline = array_merge($this->baselineRosterRow(), [
            'booking_type' => 'Individual Days',
            'selected_days' => 'Mon, Wed',
            'days_selected' => 'Mon, Wed',
        ]);
        $peer = $this->originalPeerRosterRow();
        $peer['booking_type'] = 'Full Term';
        $peer['selected_days'] = '';
        $peer['days_selected'] = '';
        $this->installHandlerWorld([
            'roster_store' => [self::ITEM_ID => $baseline],
            'roster_peer_store' => [$peer],
        ]);
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_history_meta_key()]);
        $this->seedLatestAuditFromVariation(self::ORIGINAL_VAR);

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);

        $origin = intersoccer_roster_history_get_entries(self::ITEM_ID)[0];
        $this->assertSame('Individual Days', $origin['booking_type'] ?? '');
        $this->assertSame('Mon, Wed', $origin['selected_days'] ?? '');
        $this->assertSame('Mon, Wed', $origin['days_selected'] ?? '');
        $this->assertSame('', (string) ($origin['event_signature'] ?? ''));
        $this->assertStringNotContainsString('Lausanne Centre', (string) ($origin['label']['variation'] ?? ''));
        $this->assertStringContainsString('Catalog Original Venue', (string) ($origin['label']['variation'] ?? ''));
        $this->assertSame(0, (int) ($GLOBALS['intersoccer_test_roster_peer_queries'] ?? -1));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotOrderItemMetaStore(): array {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;

        return [
            'meta' => is_array($intersoccer_test_wc_order_item_meta)
                ? json_decode(json_encode($intersoccer_test_wc_order_item_meta), true)
                : [],
            'data' => is_array($intersoccer_test_wc_order_item_data)
                ? json_decode(json_encode($intersoccer_test_wc_order_item_data), true)
                : [],
        ];
    }

    public function test_admin_view_does_not_persist_history_for_items_without_history_meta(): void {
        global $intersoccer_test_wc_order_item_meta;
        $store = [
            self::ITEM_ID => $this->baselineRosterRow(),
            self::ITEM_BATCH_OK => array_merge($this->baselineRosterRow(), [
                'order_item_id' => self::ITEM_BATCH_OK,
                'id' => 702,
                'product_name' => 'History Course B',
            ]),
        ];
        $this->installHandlerWorld([
            'order_item_ids' => [self::ITEM_ID, self::ITEM_BATCH_OK],
            'roster_row_map' => [self::ITEM_ID => true, self::ITEM_BATCH_OK => true],
            'roster_store' => $store,
        ]);
        foreach ([self::ITEM_ID, self::ITEM_BATCH_OK] as $item_id) {
            unset($intersoccer_test_wc_order_item_meta[$item_id][intersoccer_roster_history_meta_key()]);
        }

        $before = $this->snapshotOrderItemMetaStore();

        $html_one = intersoccer_roster_history_render_admin_cell(self::ITEM_ID);
        $html_two = intersoccer_roster_history_render_admin_cell(self::ITEM_BATCH_OK);
        $this->assertStringContainsString('History', $html_one);
        $this->assertStringContainsString('History Course EN', $html_one);
        $this->assertStringNotContainsString('Backfilled', $html_one);
        $this->assertStringContainsString('History', $html_two);

        $after = $this->snapshotOrderItemMetaStore();
        $this->assertSame($before, $after);
        $this->assertSame('', intersoccer_roster_history_read_raw(self::ITEM_ID));
        $this->assertSame('', intersoccer_roster_history_read_raw(self::ITEM_BATCH_OK));
    }

    public function test_rejected_move_appends_nothing(): void {
        $this->installHandlerWorld([
            'roster_row_map' => [self::ITEM_ID => false],
        ]);
        $before = intersoccer_roster_history_get_entries(self::ITEM_ID);

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertSame(0, (int) ($response['data']['moved_count'] ?? -1));

        $after = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertCount(count($before), $after);
    }

    public function test_partial_batch_appends_history_only_for_moved_item(): void {
        $store = [
            self::ITEM_BATCH_OK => array_merge($this->baselineRosterRow(), ['order_item_id' => self::ITEM_BATCH_OK, 'id' => 702]),
            self::ITEM_BLOCK => array_merge($this->baselineRosterRow(), ['order_item_id' => self::ITEM_BLOCK, 'id' => 703]),
        ];
        $this->installHandlerWorld([
            'order_item_ids' => [self::ITEM_BATCH_OK, self::ITEM_BLOCK],
            'roster_row_map' => [self::ITEM_BATCH_OK => true, self::ITEM_BLOCK => false],
            'roster_store' => $store,
        ]);

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_BATCH_OK, self::ITEM_BLOCK],
            'confirm_warnings' => '1',
        ]);

        $this->assertCount(2, intersoccer_roster_history_get_entries(self::ITEM_BATCH_OK));
        $this->assertSame([], intersoccer_roster_history_decode_entries(
            intersoccer_roster_history_read_raw(self::ITEM_BLOCK)
        ));
    }

    public function test_history_meta_survives_second_move_migration(): void {
        $this->installHandlerWorld();

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $raw_after_first = intersoccer_roster_history_read_raw(self::ITEM_ID);
        $this->assertNotSame('', $raw_after_first);

        $this->invokeMoveAjax([
            'target_variation_id' => self::SOURCE_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $entries = intersoccer_roster_history_get_entries(self::ITEM_ID);
        $this->assertCount(3, $entries);
        $this->assertStringContainsString('"type":"move"', $raw_after_first);
    }

    public function test_history_entries_contain_no_personal_fields(): void {
        $this->installHandlerWorld();
        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $json = intersoccer_roster_history_read_raw(self::ITEM_ID);
        $this->assertStringNotContainsString('player_name', strtolower($json));
        $this->assertStringNotContainsString('parent_email', strtolower($json));
        $this->assertStringNotContainsString('assigned_attendee', strtolower($json));
    }

    public function test_admin_view_renders_frozen_labels_without_edit_controls(): void {
        $this->installHandlerWorld();
        $html = intersoccer_roster_history_render_admin_cell(self::ITEM_ID);
        $this->assertStringContainsString('History', $html);
        $this->assertStringContainsString('History Course EN', $html);
        $this->assertStringNotContainsString('Backfilled', $html);
        $this->assertStringNotContainsString('<input', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('<button', $html);

        global $intersoccer_test_wc_get_product_callback;
        $intersoccer_test_wc_get_product_callback = static function () {
            return false;
        };
        $html_after_delete = intersoccer_roster_history_render_admin_cell(self::ITEM_ID);
        $this->assertStringContainsString('History Course EN', $html_after_delete);
    }
}
