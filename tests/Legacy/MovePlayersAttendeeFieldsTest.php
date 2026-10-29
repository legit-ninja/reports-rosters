<?php
/**
 * Handler regression: attendee DOB, gender and medical meta survive move A->B and move back B->A.
 *
 * Master (pre snapshot/restore): Step 2 removed every order-line meta key except Assigned Attendee,
 * assigned_player and Player Index, so Attendee DOB / Attendee Gender / Medical Conditions were
 * dropped on move; this test would fail there.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersAttendeeFieldsTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private static bool $rosterRowFilterRegistered = false;

    private const ORDER_ID = 95001;
    private const ITEM_ID = 95011;
    private const PARENT_ID = 95000;
    private const SOURCE_VAR = 95010;
    private const TARGET_VAR = 95020;

    private const ASSIGNED_ATTENDEE = 'Attendee Regression Player';
    private const ASSIGNED_PLAYER = 'player-uuid-attendee-950';
    private const PLAYER_INDEX = '2';
    private const ATTENDEE_DOB = '2015-03-01';
    private const ATTENDEE_GENDER = 'Female';
    private const MEDICAL_CONDITIONS = 'Asthma; carries inhaler';
    private const CANONICAL_GIRLS_ONLY = '1';

    /** @var list<string> */
    private const ATTENDEE_META_KEYS = [
        'Attendee DOB',
        'Attendee Gender',
        'Medical Conditions',
    ];

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
     * @return array<string,mixed>
     */
    private function baselineRosterRow(): array {
        return [
            'id' => 751,
            'order_item_id' => self::ITEM_ID,
            'order_id' => self::ORDER_ID,
            'variation_id' => self::SOURCE_VAR,
            'product_id' => self::PARENT_ID,
            'event_signature' => 'attendee-fields-sig',
            'booking_type' => 'Full Term',
            'selected_days' => '',
            'days_selected' => '',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-15',
            'camp_terms' => '',
            'course_day' => 'Saturday',
            'product_name' => 'Attendee Fields Course EN',
            'venue' => 'Geneva Centre',
            'age_group' => '6-8',
            'times' => 'Morning',
            'season' => 'Autumn 2026',
            'order_date' => '2026-08-20 10:00:00',
            'city' => 'geneva',
            'canton_region' => 'Geneva',
        ];
    }

    private function seedOrderItemMeta(): void {
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [
            'Activity Type' => 'Course',
            'Assigned Attendee' => self::ASSIGNED_ATTENDEE,
            'assigned_player' => self::ASSIGNED_PLAYER,
            'Player Index' => self::PLAYER_INDEX,
            'Attendee DOB' => self::ATTENDEE_DOB,
            'Attendee Gender' => self::ATTENDEE_GENDER,
            'Medical Conditions' => self::MEDICAL_CONDITIONS,
            '_intersoccer_canonical_girls_only' => self::CANONICAL_GIRLS_ONLY,
        ];
    }

    private function installHandlerWorld(): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_post_status_map;
        global $intersoccer_test_wc_get_product_callback;

        $this->seedOrderItemMeta();
        $GLOBALS['intersoccer_test_roster_row_filter_map'] = [self::ITEM_ID => true];
        $GLOBALS['intersoccer_test_roster_store'] = [
            self::ITEM_ID => $this->baselineRosterRow(),
        ];

        $GLOBALS['intersoccer_test_wc_stock_qty'] = [
            self::SOURCE_VAR => 2,
            self::TARGET_VAR => 3,
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
            self::PARENT_ID => 'publish',
        ];

        $intersoccer_test_wc_order_item_data[self::ITEM_ID] = [
            'product_id' => self::PARENT_ID,
            'variation_id' => self::SOURCE_VAR,
            'subtotal' => 100.00,
            'total' => 100.00,
            'name' => 'Course line',
        ];

        $item = new \WC_Order_Item_Product(self::ITEM_ID);
        $order = new \WC_Order(self::ORDER_ID);
        $order->set_items([self::ITEM_ID => $item]);
        $intersoccer_test_wc_get_order_callback = static function ($id) use ($order) {
            return (int) $id === self::ORDER_ID ? $order : false;
        };

        $make_variation = static function (int $vid) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
            $v->shouldReceive('managing_stock')->andReturn(true);
            $v->shouldReceive('backorders_allowed')->andReturn(false);
            $v->shouldReceive('get_stock_quantity')->andReturnUsing(static function () use ($vid) {
                return (int) ($GLOBALS['intersoccer_test_wc_stock_qty'][$vid] ?? 0);
            });
            $v->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
            $v->shouldReceive('get_variation_attributes')->andReturn([]);
            $v->shouldReceive('get_attribute')->andReturn('');
            $v->shouldReceive('get_attributes')->andReturn([]);
            $v->shouldReceive('get_name')->andReturn('Variation ' . $vid);
            $v->shouldReceive('get_price')->andReturn('100');
            $v->shouldReceive('is_purchasable')->andReturn(true);
            $v->shouldReceive('is_type')->with('variation')->andReturn(true);
            $v->shouldReceive('get_stock_status')->andReturn('instock');
            $v->shouldReceive('set_stock_quantity')->never();
            $v->shouldReceive('set_stock_status')->never();
            $v->shouldReceive('save')->never();

            return $v;
        };

        $source = $make_variation(self::SOURCE_VAR);
        $target = $make_variation(self::TARGET_VAR);

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent->shouldReceive('get_name')->andReturn('Parent');
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
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) {
            if (!is_string($sql)) {
                return null;
            }
            if (strpos($sql, 'woocommerce_order_items') !== false) {
                return (string) self::ORDER_ID;
            }
            if (strpos($sql, 'intersoccer_rosters') !== false && strpos($sql, 'order_item_id') !== false) {
                return '751';
            }
            if (strpos($sql, 'event_completed') !== false) {
                return '0';
            }
            if (strpos($sql, 'COUNT(*)') !== false) {
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
        $multi = wc_get_order_item_meta($order_item_id, $key, false);
        $this->assertIsArray($multi);
        $this->assertCount(1, $multi);
        $this->assertSame($expected, (string) $multi[0]);
    }

    private function assertAttendeeAndPlayerMetaPreserved(int $order_item_id): void {
        $this->assertSingleMetaValue($order_item_id, 'Attendee DOB', self::ATTENDEE_DOB);
        $this->assertSingleMetaValue($order_item_id, 'Attendee Gender', self::ATTENDEE_GENDER);
        $this->assertSingleMetaValue($order_item_id, 'Medical Conditions', self::MEDICAL_CONDITIONS);
        $this->assertSingleMetaValue($order_item_id, 'Assigned Attendee', self::ASSIGNED_ATTENDEE);
        $this->assertSingleMetaValue($order_item_id, 'assigned_player', self::ASSIGNED_PLAYER);
        $this->assertSingleMetaValue($order_item_id, 'Player Index', self::PLAYER_INDEX);
        $this->assertSingleMetaValue($order_item_id, '_intersoccer_canonical_girls_only', self::CANONICAL_GIRLS_ONLY);
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

    public function test_handler_move_and_move_back_preserves_attendee_dob_gender_and_medical(): void {
        $this->installHandlerWorld();

        $outbound = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertTrue($outbound['success'] ?? false);
        $this->assertSame(1, (int) ($outbound['data']['moved_count'] ?? 0));

        $item_after_out = new \WC_Order_Item_Product(self::ITEM_ID);
        $this->assertSame(self::TARGET_VAR, (int) $item_after_out->get_variation_id());
        $this->assertAttendeeAndPlayerMetaPreserved(self::ITEM_ID);

        $inbound = $this->invokeMoveAjax([
            'target_variation_id' => self::SOURCE_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertTrue($inbound['success'] ?? false);
        $this->assertSame(1, (int) ($inbound['data']['moved_count'] ?? 0));

        $item_after_back = new \WC_Order_Item_Product(self::ITEM_ID);
        $this->assertSame(self::SOURCE_VAR, (int) $item_after_back->get_variation_id());
        $this->assertAttendeeAndPlayerMetaPreserved(self::ITEM_ID);
    }
}
