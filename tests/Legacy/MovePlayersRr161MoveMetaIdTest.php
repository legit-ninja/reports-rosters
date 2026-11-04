<?php
/**
 * Regression: RR #161 — roster move updates order line meta in place (meta_id stable).
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersRr161MoveMetaIdTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private static bool $rosterRowFilterRegistered = false;

    private const ORDER_ID = 97100;
    private const ITEM_ID = 97111;
    private const PARENT_ID = 97101;
    private const SOURCE_VAR = 97110;
    private const TARGET_VAR = 97120;

    private const META_ID_ASSIGNED_ATTENDEE = 971001;
    private const META_ID_ATTENDEE_DOB = 971002;
    private const META_ID_MEDICAL = 971003;
    private const META_ID_ACTIVITY_TYPE = 971004;
    private const META_ID_AGE_GROUP = 971005;
    private const META_ID_PA_AGE = 971006;
    private const META_ID_ATTR_PA_AGE = 971007;
    private const META_ID_DISCOUNT_SEASON = 971008;
    private const META_ID_NOTE = 971009;

    private const SOURCE_AGE_SLUG = '5-8y';
    private const TARGET_AGE_SLUG = '5-12';
    private const DISCOUNT_SEASON_VALUE = 'fixture-autumn-2026';

    protected function setUp(): void {
        parent::setUp();
        $this->resetMovePlayersTestGlobals();
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        $GLOBALS['intersoccer_test_wc_order_item_meta_next_id'] = 979000;

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
            $GLOBALS['intersoccer_test_terms_by_id'],
            $GLOBALS['intersoccer_test_terms_by_slug'],
            $GLOBALS['intersoccer_test_terms_by_name'],
            $GLOBALS['intersoccer_test_roster_row_filter_map'],
            $GLOBALS['intersoccer_test_roster_store'],
            $GLOBALS['intersoccer_test_wc_order_item_meta'],
            $GLOBALS['intersoccer_test_wc_order_item_data'],
            $GLOBALS['intersoccer_test_wc_order_item_meta_next_id'],
            $GLOBALS['intersoccer_test_wc_update_order_item_meta_call_counts']
        );
        $this->resetMovePlayersTestGlobals();
        $_POST = [];
        parent::tearDown();
    }

    /**
     * @param array<string,array<string,object>> $by_slug
     * @param array<int,object>                $by_id
     */
    private function registerTerms(array $by_slug, array $by_id): void {
        $GLOBALS['intersoccer_test_terms_by_slug'] = $by_slug;
        $GLOBALS['intersoccer_test_terms_by_id'] = $by_id;
        $by_name = [];
        foreach ($by_slug as $taxonomy => $terms) {
            foreach ($terms as $row) {
                $obj = is_object($row) ? $row : (object) $row;
                if (!empty($obj->name)) {
                    $key = function_exists('mb_strtolower') ? mb_strtolower((string) $obj->name) : strtolower((string) $obj->name);
                    $by_name[$taxonomy][$key] = $obj;
                }
            }
        }
        $GLOBALS['intersoccer_test_terms_by_name'] = $by_name;
    }

    private function registerRr161MovePaTerms(): void {
        $this->registerTerms(
            [
                'pa_age-group' => [
                    '5-8y' => (object) ['term_id' => 971801, 'slug' => '5-8y', 'name' => '5-8y'],
                    '5-12' => (object) ['term_id' => 971802, 'slug' => '5-12', 'name' => '5-12'],
                ],
                'pa_activity-type' => [
                    'course' => (object) ['term_id' => 971803, 'slug' => 'course', 'name' => 'Course'],
                ],
            ],
            [
                971801 => (object) ['term_id' => 971801, 'slug' => '5-8y', 'name' => '5-8y'],
                971802 => (object) ['term_id' => 971802, 'slug' => '5-12', 'name' => '5-12'],
                971803 => (object) ['term_id' => 971803, 'slug' => 'course', 'name' => 'Course'],
            ]
        );
    }

    /**
     * @return array<string,int>
     */
    private function metaIdMapForItem(int $item_id): array {
        $item = new \WC_Order_Item_Product($item_id);
        $map = [];
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $map[(string) $data['key']] = (int) $data['id'];
        }

        return $map;
    }

    /**
     * @return list<int>
     */
    private function metaRowIdDisplayOrder(int $item_id): array {
        global $intersoccer_test_wc_order_item_meta;
        $store = $intersoccer_test_wc_order_item_meta[$item_id] ?? [];
        if (!isset($store['_meta_rows']) || !is_array($store['_meta_rows'])) {
            return [];
        }

        return array_map(static function ($row) {
            return (int) ($row['id'] ?? 0);
        }, $store['_meta_rows']);
    }

    private function seedOrderItemMetaWithRowIds(): void {
        global $intersoccer_test_wc_order_item_meta;

        $rows = [
            ['id' => self::META_ID_ASSIGNED_ATTENDEE, 'key' => 'Assigned Attendee', 'value' => 'Meta Id Player'],
            ['id' => self::META_ID_ATTENDEE_DOB, 'key' => 'Attendee DOB', 'value' => '2014-05-05'],
            ['id' => self::META_ID_MEDICAL, 'key' => 'Medical Conditions', 'value' => 'None known'],
            ['id' => self::META_ID_ACTIVITY_TYPE, 'key' => 'Activity Type', 'value' => 'Course'],
            ['id' => self::META_ID_AGE_GROUP, 'key' => 'Age Group', 'value' => self::SOURCE_AGE_SLUG],
            ['id' => self::META_ID_PA_AGE, 'key' => 'pa_age-group', 'value' => self::SOURCE_AGE_SLUG],
            ['id' => self::META_ID_ATTR_PA_AGE, 'key' => 'attribute_pa_age-group', 'value' => self::SOURCE_AGE_SLUG],
            [
                'id' => self::META_ID_DISCOUNT_SEASON,
                'key' => '_intersoccer_discount_season_key',
                'value' => self::DISCOUNT_SEASON_VALUE,
            ],
            ['id' => self::META_ID_NOTE, 'key' => 'Note', 'value' => 'source-only-note'],
        ];

        $flat = ['_meta_rows' => $rows];
        foreach ($rows as $row) {
            $flat[(string) $row['key']] = $row['value'];
        }

        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = $flat;
    }

    private function installHandlerWorld(): void {
        global $wpdb, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_post_status_map;
        global $intersoccer_test_wc_get_product_callback;

        $this->registerRr161MovePaTerms();
        $this->seedOrderItemMetaWithRowIds();
        $GLOBALS['intersoccer_test_roster_row_filter_map'] = [self::ITEM_ID => true];
        $GLOBALS['intersoccer_test_roster_store'] = [
            self::ITEM_ID => [
                'id' => 771,
                'order_item_id' => self::ITEM_ID,
                'order_id' => self::ORDER_ID,
                'variation_id' => self::SOURCE_VAR,
                'product_id' => self::PARENT_ID,
                'event_signature' => 'rr161-meta-id-sig',
                'booking_type' => 'Full Term',
                'activity_type' => 'Course',
                'age_group' => '5-8',
            ],
        ];

        $GLOBALS['intersoccer_test_wc_stock_qty'] = [
            self::SOURCE_VAR => 2,
            self::TARGET_VAR => 3,
        ];
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

        $make_variation = static function (int $vid, string $age_slug) {
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
                'attribute_pa_age-group' => $age_slug,
                'attribute_pa_activity-type' => 'course',
            ];
            $v->shouldReceive('get_variation_attributes')->andReturn($attrs);
            $v->shouldReceive('get_attribute')->andReturnUsing(static function ($name) use ($age_slug) {
                if ($name === 'pa_age-group' || $name === 'age-group') {
                    return $age_slug;
                }
                if ($name === 'pa_activity-type' || $name === 'activity-type') {
                    return 'course';
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
            $v->shouldReceive('set_stock_status')->never();
            $v->shouldReceive('save')->never();

            return $v;
        };

        $source = $make_variation(self::SOURCE_VAR, self::SOURCE_AGE_SLUG);
        $target = $make_variation(self::TARGET_VAR, self::TARGET_AGE_SLUG);

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
                return $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID] ?? null;
            }

            return $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID] ?? null;
        });
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) {
            if (!is_string($sql)) {
                return null;
            }
            if (strpos($sql, 'woocommerce_order_items') !== false) {
                return (string) self::ORDER_ID;
            }
            if (strpos($sql, 'intersoccer_rosters') !== false && strpos($sql, 'order_item_id') !== false) {
                return '771';
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
     * Regression: RR #161 — move must not delete/re-add unchanged line meta (Woo meta_id order).
     */
    public function test_rr161_move_keeps_meta_ids_for_unchanged_and_changed_keys(): void {
        $this->installHandlerWorld();

        $before_ids = $this->metaIdMapForItem(self::ITEM_ID);
        $before_display_order = $this->metaRowIdDisplayOrder(self::ITEM_ID);
        $this->assertSame(
            [
                self::META_ID_ASSIGNED_ATTENDEE,
                self::META_ID_ATTENDEE_DOB,
                self::META_ID_MEDICAL,
                self::META_ID_ACTIVITY_TYPE,
                self::META_ID_AGE_GROUP,
                self::META_ID_PA_AGE,
                self::META_ID_ATTR_PA_AGE,
                self::META_ID_DISCOUNT_SEASON,
                self::META_ID_NOTE,
            ],
            $before_display_order
        );

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ]);
        $this->assertTrue($response['success'] ?? false);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $after_ids = $this->metaIdMapForItem(self::ITEM_ID);

        foreach (
            [
                'Assigned Attendee' => self::META_ID_ASSIGNED_ATTENDEE,
                'Attendee DOB' => self::META_ID_ATTENDEE_DOB,
                'Medical Conditions' => self::META_ID_MEDICAL,
                '_intersoccer_discount_season_key' => self::META_ID_DISCOUNT_SEASON,
                'Note' => self::META_ID_NOTE,
            ] as $key => $expected_id
        ) {
            $this->assertSame($expected_id, $after_ids[$key] ?? 0, $key . ' meta_id must stay stable');
        }

        $this->assertSame(self::META_ID_AGE_GROUP, $after_ids['Age Group'] ?? 0);
        $expected_age_label = function_exists('intersoccer_format_attribute_for_storage')
            ? intersoccer_format_attribute_for_storage('pa_age-group', self::TARGET_AGE_SLUG)
            : self::TARGET_AGE_SLUG;
        $this->assertSame($expected_age_label, (string) wc_get_order_item_meta(self::ITEM_ID, 'Age Group', true));
        $this->assertSame(self::META_ID_PA_AGE, $after_ids['pa_age-group'] ?? 0);
        $this->assertSame(self::TARGET_AGE_SLUG, (string) wc_get_order_item_meta(self::ITEM_ID, 'pa_age-group', true));

        $after_display_order = $this->metaRowIdDisplayOrder(self::ITEM_ID);
        $stable_prefix = [
            self::META_ID_ASSIGNED_ATTENDEE,
            self::META_ID_ATTENDEE_DOB,
            self::META_ID_MEDICAL,
            self::META_ID_ACTIVITY_TYPE,
            self::META_ID_AGE_GROUP,
            self::META_ID_PA_AGE,
            self::META_ID_ATTR_PA_AGE,
            self::META_ID_DISCOUNT_SEASON,
        ];
        $this->assertSame($stable_prefix, array_slice($after_display_order, 0, count($stable_prefix)));
    }

    /**
     * Regression: RR #161 bootstrap — flat fixtures must not gain a _meta_rows store key on save().
     */
    public function test_rr161_flat_line_save_does_not_persist_meta_rows_key(): void {
        global $intersoccer_test_wc_order_item_meta;

        $item_id = 97112;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'Activity Type' => 'Course',
            'Age Group' => self::SOURCE_AGE_SLUG,
        ];

        $item = new \WC_Order_Item_Product($item_id);
        $item->update_meta_data('Age Group', self::TARGET_AGE_SLUG);
        $item->save();

        $this->assertArrayNotHasKey('_meta_rows', $intersoccer_test_wc_order_item_meta[$item_id]);
        $this->assertSame(self::TARGET_AGE_SLUG, $intersoccer_test_wc_order_item_meta[$item_id]['Age Group']);
    }
}
