<?php
/**
 * RR #104 — FR/DE QA: EN canonical slugs on move, blank-key roster fill, admin repair booking meta.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersFrDeQaFixesTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private static bool $rosterRowFilterRegistered = false;

    private const ORDER_ID = 97201;
    private const ITEM_ID = 97211;
    private const PARENT_ID = 97200;
    private const SOURCE_VAR = 97210;
    private const TARGET_VAR = 97220;

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
        require_once dirname(__DIR__) . '/stubs/camp-schedule-meta-stub.php';
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
            $GLOBALS['intersoccer_test_apply_filters_callback'],
            $GLOBALS['intersoccer_test_wpml_term_map'],
            $GLOBALS['intersoccer_test_terms_by_id'],
            $GLOBALS['intersoccer_test_terms_by_slug'],
            $GLOBALS['intersoccer_test_terms_by_name'],
            $GLOBALS['intersoccer_test_roster_store'],
            $GLOBALS['intersoccer_test_wc_order_item_meta'],
            $GLOBALS['intersoccer_test_roster_row_filter_map'],
            $GLOBALS['intersoccer_test_camp_schedule_meta_stub'],
            $GLOBALS['intersoccer_test_course_meta_by_variation'],
            $GLOBALS['intersoccer_test_parent_product_attributes_by_variation']
        );
        $this->resetMovePlayersTestGlobals();
        parent::tearDown();
    }

    /**
     * @param array<int,int> $term_map FR/DE term_id => EN term_id
     */
    private function stubWpmlEnTerms(array $term_map): void {
        $GLOBALS['intersoccer_test_wpml_term_map'] = $term_map;
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id') {
                $id = (int) $value;
                $map = $GLOBALS['intersoccer_test_wpml_term_map'] ?? [];

                return $map[$id] ?? $id;
            }

            return $value;
        };
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

    /**
     * @param array<string,mixed> $options
     */
    private function installMoveWorld(array $options = []): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_post_status_map;
        global $intersoccer_test_wc_get_product_callback;

        $target_attrs = (array) ($options['target_attrs'] ?? [
            'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
            'attribute_pa_age-group'          => '5-13y-full-day',
            'attribute_pa_booking-type'     => 'full-week',
            'attribute_pa_canton-region'    => 'geneva',
            'attribute_pa_girls-only'       => 'no',
        ]);

        $item_meta = (array) ($options['item_meta'] ?? [
            'Activity Type' => 'Camp',
            'Assigned Attendee' => 'Player FR DE QA',
            '_intersoccer_canonical_venue' => 'geneva-college-varembe',
            '_intersoccer_canonical_age_group' => '5-13y-full-day',
            '_intersoccer_canonical_booking_type' => 'single-days',
            '_intersoccer_canonical_canton' => 'geneva',
        ]);

        $GLOBALS['intersoccer_test_roster_row_filter_map'] = [self::ITEM_ID => true];
        $GLOBALS['intersoccer_test_roster_store'] = [
            self::ITEM_ID => array_merge([
                'id' => 972,
                'order_item_id' => self::ITEM_ID,
                'order_id' => self::ORDER_ID,
                'variation_id' => self::SOURCE_VAR,
                'product_id' => self::PARENT_ID,
                'event_signature' => 'fr-de-qa-sig',
                'booking_type' => 'Single Day(s)',
                'city' => '',
                'venue' => 'Old venue',
                'activity_type' => 'Camp',
                'girls_only' => 0,
            ], (array) ($options['roster_row'] ?? [])),
        ];

        $GLOBALS['intersoccer_test_wc_stock_qty'] = [self::SOURCE_VAR => 2, self::TARGET_VAR => 3];
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

        $intersoccer_test_wc_order_item_meta = [self::ITEM_ID => $item_meta];
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

        $make_variation = static function (int $vid, array $attrs) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
            $v->shouldReceive('managing_stock')->andReturn(true);
            $v->shouldReceive('backorders_allowed')->andReturn(false);
            $v->shouldReceive('get_stock_quantity')->andReturnUsing(static function () use ($vid) {
                return (int) ($GLOBALS['intersoccer_test_wc_stock_qty'][$vid] ?? 0);
            });
            $v->shouldReceive('get_variation_attributes')->andReturn($attrs);
            $v->shouldReceive('get_attribute')->andReturnUsing(static function ($name) use ($attrs) {
                $key = 'attribute_' . ltrim((string) $name, 'attribute_');
                if (isset($attrs[$key])) {
                    return $attrs[$key];
                }
                if (isset($attrs[$name])) {
                    return $attrs[$name];
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
            $v->shouldReceive('get_meta')->andReturn('');

            return $v;
        };

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent->shouldReceive('get_name')->andReturn('Camp parent');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

        $source_attrs = (array) ($options['source_attrs'] ?? [
            'attribute_pa_intersoccer-venues' => 'geneva-college-varembe',
            'attribute_pa_girls-only' => 'no',
        ]);

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($make_variation, $parent, $source_attrs, $target_attrs) {
            $id = (int) $id;
            if ($id === self::PARENT_ID) {
                return $parent;
            }
            if ($id === self::SOURCE_VAR) {
                return $make_variation($id, $source_attrs);
            }
            if ($id === self::TARGET_VAR) {
                return $make_variation($id, $target_attrs);
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
                if (isset($where['id']) && (int) ($row['id'] ?? 0) === (int) $where['id']) {
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
                return '972';
            }

            return '0';
        });

        $GLOBALS['intersoccer_test_current_user'] = (object) ['ID' => 1, 'roles' => ['administrator']];
    }

    /**
     * @return array<string,mixed>
     */
    private function invokeMoveAjax(?int $target_variation_id = null, array $post_overrides = []): array {
        $_POST = array_merge([
            'action' => 'intersoccer_move_players',
            'nonce' => wp_create_nonce('intersoccer_move_nonce'),
            'target_variation_id' => $target_variation_id ?? self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
        ], $post_overrides);
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

    public function test_fr_move_writes_en_canonical_slugs_not_localized_labels(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'geneve-stade-vessy' => (object) ['term_id' => 7101, 'slug' => 'geneve-stade-vessy', 'name' => 'Geneva - Stade de Vessy (Champel)'],
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_age-group' => [
                    '5-13-ans-journee-complete' => (object) ['term_id' => 7201, 'slug' => '5-13-ans-journee-complete', 'name' => '5-13 ans'],
                    '5-13y-full-day' => (object) ['term_id' => 7200, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                ],
                'pa_booking-type' => [
                    'semaine-complete' => (object) ['term_id' => 7301, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
                    'full-week' => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
                'pa_canton-region' => [
                    'geneve' => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                    'geneva' => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
            ],
            [
                7101 => (object) ['term_id' => 7101, 'slug' => 'geneve-stade-vessy', 'name' => 'Geneva - Stade de Vessy (Champel)'],
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                7201 => (object) ['term_id' => 7201, 'slug' => '5-13-ans-journee-complete', 'name' => '5-13 ans'],
                7200 => (object) ['term_id' => 7200, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                7301 => (object) ['term_id' => 7301, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
                7300 => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
                7401 => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                7400 => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
            ]
        );
        $this->stubWpmlEnTerms([
            7101 => 7100,
            7201 => 7200,
            7301 => 7300,
            7401 => 7400,
        ]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'geneve-stade-vessy',
                'attribute_pa_age-group' => '5-13-ans-journee-complete',
                'attribute_pa_booking-type' => 'semaine-complete',
                'attribute_pa_canton-region' => 'geneve',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('stade-de-vessy', $meta['_intersoccer_canonical_venue']);
        $this->assertSame('5-13y-full-day', $meta['_intersoccer_canonical_age_group']);
        $this->assertSame('full-week', $meta['_intersoccer_canonical_booking_type']);
        $this->assertSame('geneva', $meta['_intersoccer_canonical_canton']);
    }

    public function test_de_move_writes_en_canonical_slugs(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'ganze-woche' => (object) ['term_id' => 8301, 'slug' => 'ganze-woche', 'name' => 'Ganze Woche'],
                    'full-week' => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
                'pa_age-group' => [
                    '5-13-jahre-ganzer-tag' => (object) ['term_id' => 8201, 'slug' => '5-13-jahre-ganzer-tag', 'name' => '5-13 Jahre'],
                    '5-13y-full-day' => (object) ['term_id' => 8200, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                ],
            ],
            [
                8301 => (object) ['term_id' => 8301, 'slug' => 'ganze-woche', 'name' => 'Ganze Woche'],
                8300 => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
                8201 => (object) ['term_id' => 8201, 'slug' => '5-13-jahre-ganzer-tag', 'name' => '5-13 Jahre'],
                8200 => (object) ['term_id' => 8200, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
            ]
        );
        $this->stubWpmlEnTerms([8301 => 8300, 8201 => 8200]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_age-group' => '5-13-jahre-ganzer-tag',
                'attribute_pa_booking-type' => 'ganze-woche',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Player DE QA',
                '_intersoccer_canonical_booking_type' => 'single-days',
                '_intersoccer_canonical_age_group' => '5-13y-full-day',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('full-week', $meta['_intersoccer_canonical_booking_type']);
        $this->assertSame('5-13y-full-day', $meta['_intersoccer_canonical_age_group']);
    }

    public function test_blank_key_move_fills_roster_booking_and_city_without_adding_order_event_meta(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
                'pa_city' => [
                    'geneva' => (object) ['term_id' => 9400, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
            ],
            [
                9300 => (object) ['term_id' => 9300, 'slug' => 'full-week', 'name' => 'Full Week'],
                9400 => (object) ['term_id' => 9400, 'slug' => 'geneva', 'name' => 'Geneva'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Player blank keys',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_city' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'roster_row' => [
                'booking_type' => 'Unknown',
                'city' => '',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_roster_store;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertArrayNotHasKey('_intersoccer_canonical_venue', $meta);
        $this->assertArrayNotHasKey('_intersoccer_canonical_booking_type', $meta);
        $this->assertArrayNotHasKey('Booking Type', $meta);

        $row = $intersoccer_test_roster_store[self::ITEM_ID];
        $this->assertSame('Full Week', $row['booking_type']);
        $this->assertSame('Geneva', $row['city']);
    }

    public function test_renormalize_order_item_booking_meta_updates_duplicate_rows_in_place(): void {
        global $intersoccer_test_wc_order_item_meta;

        $item_id = 88120;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            '_meta_rows' => [
                ['id' => 6001, 'key' => 'Buchungstyp', 'value' => 'Ganze Woche'],
                ['id' => 6002, 'key' => 'Buchungstyp', 'value' => 'Ganze Woche'],
                ['id' => 6003, 'key' => 'Type de réservation', 'value' => 'Semaine complète'],
            ],
            'Buchungstyp' => 'Ganze Woche',
            'Type de réservation' => 'Semaine complète',
        ];

        $this->assertTrue(intersoccer_renormalize_order_item_booking_meta($item_id, [
            'booking_type' => 'Full Week',
        ]));

        $rows = $intersoccer_test_wc_order_item_meta[$item_id]['_meta_rows'];
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            if (($row['key'] ?? '') === 'Buchungstyp' || ($row['key'] ?? '') === 'Type de réservation') {
                $this->assertSame('Full Week', $row['value']);
            }
        }
        $this->assertArrayNotHasKey('Booking Type', $intersoccer_test_wc_order_item_meta[$item_id]);
    }

    public function test_renormalize_order_item_booking_meta_does_not_add_key_to_line_without_booking_meta(): void {
        global $intersoccer_test_wc_order_item_meta;

        $item_id = 88121;
        $before = [
            "Type d'activité" => 'Camp',
            'Assigned Attendee' => 'Child',
        ];
        $intersoccer_test_wc_order_item_meta[$item_id] = $before;

        $this->assertFalse(intersoccer_renormalize_order_item_booking_meta($item_id, [
            'booking_type' => 'Full Week',
        ]));
        $this->assertSame($before, $intersoccer_test_wc_order_item_meta[$item_id]);
    }

    public function test_renormalize_order_item_booking_meta_reports_no_write_when_already_english(): void {
        global $intersoccer_test_wc_order_item_meta;

        $item_id = 88122;
        $before = [
            'Booking Type' => 'Full Week',
        ];
        $intersoccer_test_wc_order_item_meta[$item_id] = $before;

        $this->assertFalse(intersoccer_renormalize_order_item_booking_meta($item_id, [
            'booking_type' => 'Full Week',
        ]));
        $this->assertSame($before, $intersoccer_test_wc_order_item_meta[$item_id]);
    }

    public function test_renormalize_roster_row_language_sets_order_meta_written_when_booking_meta_changes(): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta;

        $item_id = 88123;
        $roster_id = 881230;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            '_meta_rows' => [
                ['id' => 7001, 'key' => 'Buchungstyp', 'value' => 'Ganze Woche'],
            ],
            'Buchungstyp' => 'Ganze Woche',
            "Type d'activité" => 'Camp',
            'Lieux InterSoccer' => 'Centre',
        ];

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $row = [
            'id' => $roster_id,
            'order_item_id' => $item_id,
            'variation_id' => 0,
            'activity_type' => 'Camp',
            'booking_type' => 'Unknown',
            'venue' => 'Centre',
            'product_id' => 1,
            'product_name' => 'Camp',
        ];
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_row')->andReturn($row);
        $wpdb->shouldReceive('update')->andReturn(1);

        $order_meta_written = false;
        $this->assertTrue(intersoccer_renormalize_roster_row_language($roster_id, $order_meta_written));
        $this->assertTrue($order_meta_written);
        $this->assertSame('Full Week', $intersoccer_test_wc_order_item_meta[$item_id]['Buchungstyp']);
    }

    public function test_migration_default_language_term_slug_maps_canton_to_en_geneva(): void {
        $this->registerTerms(
            [
                'pa_canton-region' => [
                    'geneve' => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                    'geneva' => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
            ],
            [
                7401 => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                7400 => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
            ]
        );
        $this->stubWpmlEnTerms([7401 => 7400]);

        $this->assertSame('geneva', intersoccer_migration_default_language_term_slug('pa_canton-region', 'geneve'));
        $this->assertSame('geneva', intersoccer_migration_default_language_term_slug('pa_canton-region', 'Genève'));
    }

    /**
     * @param array<string,object> $by_slug
     * @param array<int,object>    $by_id
     * @param array<int,int>       $wpml_map
     */
    private function registerGenevaCantonTerms(array $wpml_map = [7401 => 7400, 7501 => 7400]): void {
        $this->registerTerms(
            [
                'pa_canton-region' => [
                    'geneve' => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                    'genf' => (object) ['term_id' => 7501, 'slug' => 'genf', 'name' => 'Genf'],
                    'geneva' => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
            ],
            [
                7401 => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                7501 => (object) ['term_id' => 7501, 'slug' => 'genf', 'name' => 'Genf'],
                7400 => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
            ]
        );
        $this->stubWpmlEnTerms($wpml_map);
    }

    /**
     * @return array<int,string>
     */
    private function moveBookkeepingOrderMetaKeys(): array {
        $keys = function_exists('intersoccer_roster_move_audit_all_meta_keys')
            ? intersoccer_roster_move_audit_all_meta_keys()
            : [];
        if (function_exists('intersoccer_roster_history_meta_key')) {
            $keys[] = intersoccer_roster_history_meta_key();
        }
        if (function_exists('intersoccer_roster_move_stock_delta_meta_key')) {
            $keys[] = intersoccer_roster_move_stock_delta_meta_key();
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<int,string> $keysBefore
     */
    private function assertMoveOrderMetaKeysOnlyBookkeepingAdded(array $keysBefore): void {
        global $intersoccer_test_wc_order_item_meta;
        $after = array_keys($intersoccer_test_wc_order_item_meta[self::ITEM_ID]);
        $allowed = array_merge($keysBefore, $this->moveBookkeepingOrderMetaKeys());
        $this->assertEqualsCanonicalizing($allowed, $after);
        foreach ($after as $key) {
            if (in_array($key, $keysBefore, true) || in_array($key, $this->moveBookkeepingOrderMetaKeys(), true)) {
                continue;
            }
            $this->fail('Unexpected order item meta key after move: ' . $key);
        }
    }

    public function test_normalize_roster_facets_for_storage_stores_canton_en_term_name_for_fr_and_de(): void {
        $this->registerGenevaCantonTerms();

        $fr = intersoccer_normalize_roster_facets_for_storage([
            'canton_region' => 'geneve',
            'activity_type' => 'Camp',
            'product_id' => 1,
        ]);
        $this->assertSame('Geneva', $fr['canton_region']);

        $de = intersoccer_normalize_roster_facets_for_storage([
            'canton_region' => 'genf',
            'activity_type' => 'Camp',
            'product_id' => 1,
        ]);
        $this->assertSame('Geneva', $de['canton_region']);

        $en = intersoccer_normalize_roster_facets_for_storage([
            'canton_region' => 'geneva',
            'activity_type' => 'Camp',
            'product_id' => 1,
        ]);
        $this->assertSame('Geneva', $en['canton_region']);
    }

    public function test_migration_supplement_roster_normalized_from_variation_fills_canton_en_term_name(): void {
        $this->registerGenevaCantonTerms();

        $variation = Mockery::mock(\WC_Product_Variation::class);
        $variation->shouldReceive('get_id')->andReturn(99001);
        $variation->shouldReceive('get_parent_id')->andReturn(1);
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_canton-region' => 'geneve',
        ]);
        $variation->shouldReceive('get_attribute')->with('pa_canton-region')->andReturn('geneve');
        $variation->shouldReceive('get_attributes')->andReturn([]);

        $normalized = intersoccer_migration_supplement_roster_normalized_from_variation(
            [],
            ['canton_region' => ''],
            $variation
        );
        $this->assertSame('Geneva', $normalized['canton_region']);
    }

    public function test_blank_key_move_fills_roster_canton_en_term_name(): void {
        $this->registerTerms(
            [
                'pa_canton-region' => [
                    'geneve' => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                    'genf' => (object) ['term_id' => 7501, 'slug' => 'genf', 'name' => 'Genf'],
                    'geneva' => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                7401 => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                7501 => (object) ['term_id' => 7501, 'slug' => 'genf', 'name' => 'Genf'],
                7400 => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
                9300 => (object) ['term_id' => 9300, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([7401 => 7400, 7501 => 7400]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Player blank canton',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'roster_row' => [
                'booking_type' => 'Unknown',
                'canton_region' => '',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_roster_store;
        $row = $intersoccer_test_roster_store[self::ITEM_ID];
        $this->assertSame('Geneva', $row['canton_region']);
    }

    public function test_admin_repair_batch_sets_roster_canton_geneva_for_fr_line(): void {
        $this->registerGenevaCantonTerms();
        $this->assertAdminRepairBatchSetsRosterCantonGeneva(
            88201,
            882011,
            ['Canton / Région' => 'Genève']
        );
    }

    public function test_admin_repair_batch_sets_roster_canton_geneva_for_de_line(): void {
        $this->registerGenevaCantonTerms();
        $this->assertAdminRepairBatchSetsRosterCantonGeneva(
            88202,
            882021,
            ['Canton / Region' => 'Genf']
        );
    }

    /**
     * @param array<string,string> $item_meta
     */
    private function assertAdminRepairBatchSetsRosterCantonGeneva(int $item_id, int $roster_id, array $item_meta): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta;

        $intersoccer_test_wc_order_item_meta[$item_id] = array_merge(
            [
                "Type d'activité" => 'Camp',
                'Lieux InterSoccer' => 'Centre',
            ],
            $item_meta
        );

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $row = [
            'id' => $roster_id,
            'order_item_id' => $item_id,
            'variation_id' => 0,
            'activity_type' => 'Camp',
            'venue' => 'Centre',
            'canton_region' => '',
            'product_id' => 1,
            'product_name' => 'Camp',
            'booking_type' => 'Full Week',
        ];

        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => $roster_id, 'order_item_id' => $item_id, 'variation_id' => 0],
        ]);
        $wpdb->shouldReceive('get_row')->andReturn($row);
        $captured_canton = null;
        $wpdb->shouldReceive('update')->andReturnUsing(static function ($table, $data, $where) use (&$captured_canton, $rosters_table) {
            if ($table === $rosters_table && isset($data['canton_region'])) {
                $captured_canton = $data['canton_region'];
            }

            return 1;
        });

        $batch = intersoccer_renormalize_roster_language_batch(0, 40, 'orders');
        $this->assertSame(1, (int) ($batch['updated'] ?? 0));
        $this->assertSame('Geneva', $captured_canton);
    }

    public function test_renormalize_language_batch_meta_updated_counts_order_meta_writes_only(): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta;

        $booking_only_item = 88301;
        $unchanged_item = 88302;
        $booking_only_roster = 883011;
        $unchanged_roster = 883021;

        $intersoccer_test_wc_order_item_meta[$booking_only_item] = [
            '_meta_rows' => [
                ['id' => 8001, 'key' => 'Buchungstyp', 'value' => 'Ganze Woche'],
            ],
            'Buchungstyp' => 'Ganze Woche',
            "Type d'activité" => 'Camp',
            'Lieux InterSoccer' => 'Centre',
        ];
        $intersoccer_test_wc_order_item_meta[$unchanged_item] = [
            'Activity Type' => 'Camp',
            'Booking Type' => 'Full Week',
        ];

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

        $rows_by_id = [
            $booking_only_roster => [
                'id' => $booking_only_roster,
                'order_item_id' => $booking_only_item,
                'variation_id' => 0,
                'activity_type' => 'Camp',
                'venue' => 'Centre',
                'canton_region' => 'Geneva',
                'booking_type' => 'Unknown',
                'product_id' => 1,
                'product_name' => 'Camp',
            ],
            $unchanged_roster => [
                'id' => $unchanged_roster,
                'order_item_id' => $unchanged_item,
                'variation_id' => 0,
                'activity_type' => 'Camp',
                'venue' => 'Centre',
                'canton_region' => 'Geneva',
                'booking_type' => 'Full Week',
                'product_id' => 1,
                'product_name' => 'Camp',
            ],
        ];

        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query, ...$args) {
            if ($args !== []) {
                $escaped = array_map(
                    static function ($arg) {
                        return is_numeric($arg) ? (string) (int) $arg : "'" . (string) $arg . "'";
                    },
                    $args
                );

                return vsprintf(str_replace(['%d', '%s'], '%s', $query), $escaped);
            }

            return $query;
        });
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => $booking_only_roster, 'order_item_id' => $booking_only_item, 'variation_id' => 0],
        ]);
        $get_row_calls = 0;
        $get_row_sequence = [
            $rows_by_id[$booking_only_roster],
            $rows_by_id[$booking_only_roster],
        ];
        $wpdb->shouldReceive('get_row')->andReturnUsing(static function () use (&$get_row_calls, $get_row_sequence) {
            $index = min($get_row_calls, count($get_row_sequence) - 1);
            $get_row_calls++;

            return $get_row_sequence[$index];
        });
        $wpdb->shouldReceive('update')->andReturnUsing(static function ($table, $data, $where) use ($rosters_table, &$rows_by_id) {
            if ($table !== $rosters_table || !isset($where['id'])) {
                return 1;
            }
            $id = (int) $where['id'];
            if (isset($rows_by_id[$id])) {
                $rows_by_id[$id] = array_merge($rows_by_id[$id], $data);
            }

            return 1;
        });

        $batch = intersoccer_renormalize_roster_language_batch(0, 40, 'orders');
        $this->assertSame(1, (int) ($batch['meta_updated'] ?? 0));
        $this->assertSame(1, (int) ($batch['updated'] ?? 0));
        $this->assertSame(0, (int) ($batch['errors'] ?? 0));
        $this->assertSame('Full Week', $intersoccer_test_wc_order_item_meta[$booking_only_item]['Buchungstyp']);
    }

    public function test_renormalize_language_batch_meta_updated_zero_when_order_meta_unchanged(): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta;

        $unchanged_item = 88302;
        $unchanged_roster = 883021;

        $intersoccer_test_wc_order_item_meta[$unchanged_item] = [
            'Activity Type' => 'Camp',
            'Booking Type' => 'Full Week',
        ];

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $row = [
            'id' => $unchanged_roster,
            'order_item_id' => $unchanged_item,
            'variation_id' => 0,
            'activity_type' => 'Camp',
            'venue' => 'Centre',
            'canton_region' => 'Geneva',
            'booking_type' => 'Full Week',
            'product_id' => 1,
            'product_name' => 'Camp',
        ];

        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query, ...$args) {
            if ($args !== []) {
                $escaped = array_map(
                    static function ($arg) {
                        return is_numeric($arg) ? (string) (int) $arg : "'" . (string) $arg . "'";
                    },
                    $args
                );

                return vsprintf(str_replace(['%d', '%s'], '%s', $query), $escaped);
            }

            return $query;
        });
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => $unchanged_roster, 'order_item_id' => $unchanged_item, 'variation_id' => 0],
        ]);
        $wpdb->shouldReceive('get_row')->andReturn($row);
        $wpdb->shouldReceive('update')->andReturnUsing(static function ($table, $data, $where) use ($rosters_table, &$row) {
            if ($table === $rosters_table && isset($where['id'])) {
                $row = array_merge($row, $data);
            }

            return 1;
        });

        $batch = intersoccer_renormalize_roster_language_batch(0, 40, 'orders');
        $this->assertSame(0, (int) ($batch['meta_updated'] ?? 0));
        $this->assertSame(1, (int) ($batch['updated'] ?? 0));
        $this->assertSame(0, (int) ($batch['errors'] ?? 0));
    }

    public function test_move_repair_keeps_single_booking_type_row_with_destination_value_after_restore_wipe(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9600, 'slug' => 'full-week', 'name' => 'Full Week'],
                    'single-days' => (object) ['term_id' => 9601, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                9600 => (object) ['term_id' => 9600, 'slug' => 'full-week', 'name' => 'Full Week'],
                9601 => (object) ['term_id' => 9601, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Booking repair player',
                'Booking Type' => '',
                '_intersoccer_canonical_booking_type' => 'single-days',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $booking_rows = [];
        if (isset($meta['_meta_rows']) && is_array($meta['_meta_rows'])) {
            foreach ($meta['_meta_rows'] as $row) {
                if (($row['key'] ?? '') === 'Booking Type') {
                    $booking_rows[] = $row;
                }
            }
        } elseif (isset($meta['Booking Type'])) {
            $booking_rows[] = ['key' => 'Booking Type', 'value' => $meta['Booking Type']];
        }
        $this->assertCount(1, $booking_rows);
        $this->assertSame('Full Week', $booking_rows[0]['value']);
    }

    public function test_move_does_not_add_booking_type_when_line_had_no_booking_key(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9700, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9700 => (object) ['term_id' => 9700, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'No booking key',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertArrayNotHasKey('Booking Type', $meta);
        if (isset($meta['_meta_rows']) && is_array($meta['_meta_rows'])) {
            foreach ($meta['_meta_rows'] as $row) {
                $this->assertNotSame('Booking Type', $row['key'] ?? '');
            }
        }
    }

    public function test_fr_single_days_move_keeps_booking_type_and_selected_days(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'jours-selectionnes' => (object) ['term_id' => 9801, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                    'single-days' => (object) ['term_id' => 9800, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                    'full-week' => (object) ['term_id' => 9802, 'slug' => 'full-week', 'name' => 'Full Week'],
                    'semaine-complete' => (object) ['term_id' => 9803, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
                ],
            ],
            [
                9801 => (object) ['term_id' => 9801, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                9800 => (object) ['term_id' => 9800, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                9802 => (object) ['term_id' => 9802, 'slug' => 'full-week', 'name' => 'Full Week'],
                9803 => (object) ['term_id' => 9803, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
            ]
        );
        $this->stubWpmlEnTerms([9801 => 9800, 9803 => 9802]);

        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_intersoccer-venues' => 'geneva-college-varembe',
                'attribute_pa_booking-type' => 'jours-selectionnes',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'jours-selectionnes',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR single days',
                'Type de réservation' => 'Jours sélectionnés',
                'Jours sélectionnés' => 'Monday, Wednesday',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('Jours sélectionnés', $meta['Type de réservation']);
        $this->assertSame('Monday, Wednesday', $meta['Jours sélectionnés']);
    }

    public function test_de_single_days_move_keeps_booking_type_and_selected_days(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'einzelne-tage' => (object) ['term_id' => 9901, 'slug' => 'einzelne-tage', 'name' => 'Einzelne Tage'],
                    'single-days' => (object) ['term_id' => 9900, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                    'full-week' => (object) ['term_id' => 9902, 'slug' => 'full-week', 'name' => 'Full Week'],
                    'ganze-woche' => (object) ['term_id' => 9903, 'slug' => 'ganze-woche', 'name' => 'Ganze Woche'],
                ],
            ],
            [
                9901 => (object) ['term_id' => 9901, 'slug' => 'einzelne-tage', 'name' => 'Einzelne Tage'],
                9900 => (object) ['term_id' => 9900, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                9902 => (object) ['term_id' => 9902, 'slug' => 'full-week', 'name' => 'Full Week'],
                9903 => (object) ['term_id' => 9903, 'slug' => 'ganze-woche', 'name' => 'Ganze Woche'],
            ]
        );
        $this->stubWpmlEnTerms([9901 => 9900, 9903 => 9902]);

        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_intersoccer-venues' => 'geneva-college-varembe',
                'attribute_pa_booking-type' => 'einzelne-tage',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'einzelne-tage',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE single days',
                'Buchungstyp' => 'Einzelne Tage',
                'Ausgewählte Tage' => 'Monday, Wednesday',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('Einzelne Tage', $meta['Buchungstyp']);
        $this->assertSame('Monday, Wednesday', $meta['Ausgewählte Tage']);
    }

    public function test_en_single_days_to_full_week_move_does_not_blank_days_selected(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 9950, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                    'full-week' => (object) ['term_id' => 9951, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9950 => (object) ['term_id' => 9950, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                9951 => (object) ['term_id' => 9951, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_intersoccer-venues' => 'geneva-college-varembe',
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'EN single to full week',
                'Booking Type' => 'Single Day(s)',
                'Days Selected' => 'Monday, Wednesday',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('Monday, Wednesday', $meta['Days Selected']);
    }

    public function test_migration_default_language_term_slug_invents_slug_from_unresolved_label(): void {
        $label = 'Semaine complète';
        $invented = intersoccer_migration_default_language_term_slug('pa_booking-type', $label);
        $expected_loose = function_exists('sanitize_title')
            ? sanitize_title($label)
            : strtolower(str_replace(' ', '-', $label));
        $this->assertSame($expected_loose, $invented);
        $this->assertNotSame('', $invented);
        $this->assertNull(
            intersoccer_migration_default_language_term_slug_strict('pa_booking-type', $label)
        );
    }

    public function test_fr_move_keeps_canonical_booking_type_when_destination_label_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                7300 => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Semaine complète',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR unresolved booking label',
                '_intersoccer_canonical_venue' => 'geneva-college-varembe',
                '_intersoccer_canonical_booking_type' => 'single-days',
            ],
        ]);

        global $intersoccer_test_wc_order_item_meta;
        $keys_before = array_keys($intersoccer_test_wc_order_item_meta[self::ITEM_ID]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['_intersoccer_canonical_booking_type']);
        $this->assertMoveOrderMetaKeysOnlyBookkeepingAdded($keys_before);
    }

    public function test_de_move_keeps_canonical_booking_type_when_destination_label_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                8300 => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Ganze Woche',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE unresolved booking label',
                '_intersoccer_canonical_venue' => 'geneva-college-varembe',
                '_intersoccer_canonical_booking_type' => 'single-days',
            ],
        ]);

        global $intersoccer_test_wc_order_item_meta;
        $keys_before = array_keys($intersoccer_test_wc_order_item_meta[self::ITEM_ID]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['_intersoccer_canonical_booking_type']);
        $this->assertMoveOrderMetaKeysOnlyBookkeepingAdded($keys_before);
    }

    public function test_fr_move_resolved_booking_label_still_writes_en_canonical_slug(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'semaine-complete' => (object) ['term_id' => 7301, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
                    'full-week' => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                7301 => (object) ['term_id' => 7301, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
                7300 => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([7301 => 7300]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Semaine complète',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR resolved booking label',
                '_intersoccer_canonical_booking_type' => 'single-days',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('full-week', $meta['_intersoccer_canonical_booking_type']);
        if (isset($meta['attribute_pa_booking-type'])) {
            $this->assertSame('full-week', $meta['attribute_pa_booking-type']);
        }
    }

    public function test_migration_default_language_term_slug_strict_resolves_registered_term_slug(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 7310, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                7310 => (object) ['term_id' => 7310, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->assertSame(
            'full-week',
            intersoccer_migration_default_language_term_slug_strict('pa_booking-type', 'full-week')
        );
    }

    public function test_de_move_keeps_canonical_canton_when_destination_genf_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
                'pa_canton-region' => [
                    'geneva' => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                7300 => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
                7400 => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'Genf',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE unresolved canton label',
                '_intersoccer_canonical_venue' => 'geneva-college-varembe',
                '_intersoccer_canonical_canton' => 'geneva',
            ],
        ]);

        global $intersoccer_test_wc_order_item_meta;
        $keys_before = array_keys($intersoccer_test_wc_order_item_meta[self::ITEM_ID]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('geneva', $meta['_intersoccer_canonical_canton']);
        $this->assertMoveOrderMetaKeysOnlyBookkeepingAdded($keys_before);
    }

    public function test_de_move_keeps_canonical_booking_type_when_destination_ganztags_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                8300 => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Ganztags',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE unresolved Ganztags booking label',
                '_intersoccer_canonical_venue' => 'geneva-college-varembe',
                '_intersoccer_canonical_booking_type' => 'single-days',
            ],
        ]);

        global $intersoccer_test_wc_order_item_meta;
        $keys_before = array_keys($intersoccer_test_wc_order_item_meta[self::ITEM_ID]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['_intersoccer_canonical_booking_type']);
        $this->assertMoveOrderMetaKeysOnlyBookkeepingAdded($keys_before);
    }

    public function test_fr_move_keeps_canonical_booking_type_when_destination_matin_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                7300 => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Matin',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR unresolved Matin booking label',
                '_intersoccer_canonical_venue' => 'geneva-college-varembe',
                '_intersoccer_canonical_booking_type' => 'single-days',
            ],
        ]);

        global $intersoccer_test_wc_order_item_meta;
        $keys_before = array_keys($intersoccer_test_wc_order_item_meta[self::ITEM_ID]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['_intersoccer_canonical_booking_type']);
        $this->assertMoveOrderMetaKeysOnlyBookkeepingAdded($keys_before);
    }

    public function test_fr_move_keeps_existing_pa_booking_meta_when_destination_matin_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 7302, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                7302 => (object) ['term_id' => 7302, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Matin',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR pa booking keep',
                'attribute_pa_booking-type' => 'single-days',
                'pa_booking-type' => 'single-days',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['attribute_pa_booking-type']);
        $this->assertSame('single-days', $meta['pa_booking-type']);
    }

    public function test_de_move_keeps_existing_pa_booking_meta_when_destination_ganztags_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 8302, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                8302 => (object) ['term_id' => 8302, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Ganztags',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE pa booking keep',
                'attribute_pa_booking-type' => 'single-days',
                'pa_booking-type' => 'single-days',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['attribute_pa_booking-type']);
        $this->assertSame('single-days', $meta['pa_booking-type']);
    }

    public function test_fr_move_does_not_add_pa_booking_meta_when_line_had_no_key_and_destination_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Matin',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR no pa booking key',
            ],
        ]);

        global $intersoccer_test_wc_order_item_meta;
        $keys_before = array_keys($intersoccer_test_wc_order_item_meta[self::ITEM_ID]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertArrayNotHasKey('attribute_pa_booking-type', $meta);
        $this->assertArrayNotHasKey('pa_booking-type', $meta);
        $this->assertMoveOrderMetaKeysOnlyBookkeepingAdded($keys_before);
    }

    public function test_de_move_does_not_add_pa_booking_meta_when_line_had_no_key_and_destination_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Ganztags',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE no pa booking key',
            ],
        ]);

        global $intersoccer_test_wc_order_item_meta;
        $keys_before = array_keys($intersoccer_test_wc_order_item_meta[self::ITEM_ID]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertArrayNotHasKey('attribute_pa_booking-type', $meta);
        $this->assertMoveOrderMetaKeysOnlyBookkeepingAdded($keys_before);
    }

    public function test_fr_move_blank_roster_canton_stays_blank_when_destination_canton_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                9300 => (object) ['term_id' => 9300, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'Région Inconnue',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR blank canton unresolved',
            ],
            'roster_row' => [
                'canton_region' => '',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_roster_store;
        $this->assertSame('', $intersoccer_test_roster_store[self::ITEM_ID]['canton_region']);
    }

    public function test_de_move_blank_roster_canton_stays_blank_when_destination_canton_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                9300 => (object) ['term_id' => 9300, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'Unbekannte Region',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE blank canton unresolved',
            ],
            'roster_row' => [
                'canton_region' => '',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_roster_store;
        $this->assertSame('', $intersoccer_test_roster_store[self::ITEM_ID]['canton_region']);
    }

    public function test_fr_admin_repair_leaves_unresolved_canton_meta_and_roster_unchanged(): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta;

        $item_id = 88301;
        $roster_id = 883011;
        $meta_before = [
            "Type d'activité" => 'Camp',
            'Canton / Région' => 'Région Inconnue',
        ];
        $intersoccer_test_wc_order_item_meta[$item_id] = $meta_before;

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $row = [
            'id' => $roster_id,
            'order_item_id' => $item_id,
            'variation_id' => 0,
            'activity_type' => 'Camp',
            'venue' => 'Centre',
            'canton_region' => 'Région Inconnue',
            'product_id' => 1,
            'product_name' => 'Camp',
            'booking_type' => 'Full Week',
        ];

        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => $roster_id, 'order_item_id' => $item_id, 'variation_id' => 0],
        ]);
        $wpdb->shouldReceive('get_row')->andReturn($row);
        $captured_canton = null;
        $wpdb->shouldReceive('update')->andReturnUsing(static function ($table, $data, $where) use (&$captured_canton, $rosters_table) {
            if ($table === $rosters_table && isset($data['canton_region'])) {
                $captured_canton = $data['canton_region'];
            }

            return 1;
        });

        intersoccer_renormalize_roster_language_batch(0, 40, 'orders');
        $this->assertSame('Région Inconnue', $intersoccer_test_wc_order_item_meta[$item_id]['Canton / Région']);
        if ($captured_canton !== null) {
            $this->assertSame('Région Inconnue', $captured_canton);
        }
    }

    public function test_de_admin_repair_leaves_unresolved_canton_meta_and_roster_unchanged(): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta;

        $item_id = 88302;
        $roster_id = 883021;
        $meta_before = [
            'Aktivitätstyp' => 'Camp',
            'Canton / Region' => 'Unbekannte Region',
        ];
        $intersoccer_test_wc_order_item_meta[$item_id] = $meta_before;

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $row = [
            'id' => $roster_id,
            'order_item_id' => $item_id,
            'variation_id' => 0,
            'activity_type' => 'Camp',
            'venue' => 'Centre',
            'canton_region' => 'Unbekannte Region',
            'product_id' => 1,
            'product_name' => 'Camp',
            'booking_type' => 'Full Week',
        ];

        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $wpdb->shouldReceive('get_results')->andReturn([
            ['id' => $roster_id, 'order_item_id' => $item_id, 'variation_id' => 0],
        ]);
        $wpdb->shouldReceive('get_row')->andReturn($row);
        $captured_canton = null;
        $wpdb->shouldReceive('update')->andReturnUsing(static function ($table, $data, $where) use (&$captured_canton, $rosters_table) {
            if ($table === $rosters_table && isset($data['canton_region'])) {
                $captured_canton = $data['canton_region'];
            }

            return 1;
        });

        intersoccer_renormalize_roster_language_batch(0, 40, 'orders');
        $this->assertSame('Unbekannte Region', $intersoccer_test_wc_order_item_meta[$item_id]['Canton / Region']);
        if ($captured_canton !== null) {
            $this->assertSame('Unbekannte Region', $captured_canton);
        }
    }

    public function test_fr_blank_key_move_fills_roster_canton_from_registered_geneve_term(): void {
        $this->registerGenevaCantonTerms();

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR blank canton resolved',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'geneve',
                'attribute_pa_girls-only' => 'no',
            ],
            'roster_row' => [
                'canton_region' => '',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_roster_store;
        $this->assertSame('Geneva', $intersoccer_test_roster_store[self::ITEM_ID]['canton_region']);
    }

    /**
     * @param array<string,string> $meta_key_to_value
     */
    private function assertRenormalizeEmptySelectedDaysLeavesRowUntouched(array $meta_key_to_value): void {
        global $intersoccer_test_wc_order_item_meta;

        static $next_id = 88130;
        $item_id = $next_id++;
        $intersoccer_test_wc_order_item_meta[$item_id] = $meta_key_to_value;

        $this->assertFalse(intersoccer_renormalize_order_item_booking_meta($item_id, [
            'selected_days' => '',
        ]));

        foreach ($meta_key_to_value as $key => $expected) {
            $this->assertSame($expected, $intersoccer_test_wc_order_item_meta[$item_id][$key]);
        }
    }

    public function test_renormalize_order_item_booking_meta_empty_selected_days_leaves_en_row_unchanged(): void {
        $this->assertRenormalizeEmptySelectedDaysLeavesRowUntouched([
            'Days Selected' => 'Monday, Wednesday',
        ]);
    }

    public function test_renormalize_order_item_booking_meta_empty_selected_days_leaves_fr_row_unchanged(): void {
        $this->assertRenormalizeEmptySelectedDaysLeavesRowUntouched([
            'Jours sélectionnés' => 'Lundi, Mercredi',
        ]);
    }

    public function test_renormalize_order_item_booking_meta_empty_selected_days_leaves_de_row_unchanged(): void {
        $this->assertRenormalizeEmptySelectedDaysLeavesRowUntouched([
            'Ausgewählte Tage' => 'Montag, Mittwoch',
        ]);
    }

    // Regression: RR #104 — event signature canton normalization unchanged from af6cd65 (fallback, not raw label).
    public function test_signature_normalize_unresolved_genf_canton_matches_term_fallback(): void {
        if (!function_exists('intersoccer_normalize_event_data_for_signature')
            || !function_exists('intersoccer_normalize_term_fallback')
        ) {
            $this->markTestSkipped('Signature normalization helpers not available');
        }

        $normalized = intersoccer_normalize_event_data_for_signature([
            'canton_region' => 'Genf',
            'product_id' => 1,
            'activity_type' => 'Camp',
        ]);
        $expected = intersoccer_normalize_term_fallback('Genf');

        $this->assertSame($expected, $normalized['canton_region'] ?? '');
    }

    // Regression: RR #104 — visible canton label follows destination display when pa slug is unresolved.
    public function test_move_writes_destination_canton_display_when_pa_slug_unresolved(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
                'pa_canton-region' => [
                    'geneva' => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
            ],
            [
                7100 => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                7300 => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week'],
                7400 => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'Genf',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Visible canton label move',
                'Canton / Region' => 'Geneva',
                '_intersoccer_canonical_canton' => 'geneva',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('Genf', $meta['Canton / Region'] ?? '');
        $this->assertSame('geneva', $meta['_intersoccer_canonical_canton']);
    }

    // Regression: RR #104 — resolved FR/DE move still writes EN pa_* slug bytes on the line (af6cd65 contract).
    public function test_rr104_move_resolved_fr_and_de_terms_write_en_pa_slugs_on_line(): void {
        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
                ],
                'pa_booking-type' => [
                    'semaine-complete' => (object) ['term_id' => 7301, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
                    'ganze-woche' => (object) ['term_id' => 8301, 'slug' => 'ganze-woche', 'name' => 'Ganze Woche'],
                    'full-week' => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
                'pa_canton-region' => [
                    'geneve' => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                    'genf' => (object) ['term_id' => 7501, 'slug' => 'genf', 'name' => 'Genf'],
                    'geneva' => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
            ],
            [
                7301 => (object) ['term_id' => 7301, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
                8301 => (object) ['term_id' => 8301, 'slug' => 'ganze-woche', 'name' => 'Ganze Woche'],
                8300 => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
                7401 => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève'],
                7501 => (object) ['term_id' => 7501, 'slug' => 'genf', 'name' => 'Genf'],
                7400 => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva'],
            ]
        );
        $this->stubWpmlEnTerms([7301 => 8300, 7401 => 7400, 7501 => 7400]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Semaine complète',
                'attribute_pa_canton-region' => 'geneve',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta_fr = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('full-week', $meta_fr['_intersoccer_canonical_booking_type'] ?? '');
        $this->assertSame('geneva', $meta_fr['_intersoccer_canonical_canton'] ?? '');
        if (isset($meta_fr['attribute_pa_booking-type'])) {
            $this->assertSame('full-week', $meta_fr['attribute_pa_booking-type']);
        }

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'Ganze Woche',
                'attribute_pa_canton-region' => 'genf',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response_de = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response_de['data']['moved_count'] ?? 0));

        $meta_de = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('full-week', $meta_de['_intersoccer_canonical_booking_type'] ?? '');
        $this->assertSame('geneva', $meta_de['_intersoccer_canonical_canton'] ?? '');
        if (isset($meta_de['attribute_pa_booking-type'])) {
            $this->assertSame('full-week', $meta_de['attribute_pa_booking-type']);
        }
    }

    // Regression: RR #104 — admin repair maps resolved FR/DE canton labels to English roster facet (af6cd65).
    public function test_rr104_admin_repair_resolved_fr_de_canton_labels_write_geneva_roster(): void {
        $this->registerGenevaCantonTerms();
        $this->assertAdminRepairBatchSetsRosterCantonGeneva(
            88391,
            883911,
            ['Canton / Région' => 'Genève']
        );
        $this->assertAdminRepairBatchSetsRosterCantonGeneva(
            88392,
            883921,
            ['Canton / Region' => 'Genf']
        );
    }

    public function test_fr_move_stores_en_venue_slug_in_pa_intersoccer_venues_not_term_name(): void {
        $en_venue_slug = 'geneva-stade-de-vessy-champel';
        $en_venue_name = 'Geneva - Stade de Vessy (Champel)';
        $fr_old_display = 'Geneva - Stade de Varembé (Nations)';

        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    $en_venue_slug => (object) ['term_id' => 8100, 'slug' => $en_venue_slug, 'name' => $en_venue_name],
                    'geneva-stade-varember-nations' => (object) ['term_id' => 8101, 'slug' => 'geneva-stade-varember-nations', 'name' => $fr_old_display],
                ],
                'pa_age-group' => [
                    '5-13-ans-journee-complete' => (object) ['term_id' => 8201, 'slug' => '5-13-ans-journee-complete', 'name' => '5-13 ans'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
                'pa_canton-region' => [
                    'geneva' => (object) ['term_id' => 8400, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
            ],
            [
                8100 => (object) ['term_id' => 8100, 'slug' => $en_venue_slug, 'name' => $en_venue_name],
                8101 => (object) ['term_id' => 8101, 'slug' => 'geneva-stade-varember-nations', 'name' => $fr_old_display],
                8201 => (object) ['term_id' => 8201, 'slug' => '5-13-ans-journee-complete', 'name' => '5-13 ans'],
                8300 => (object) ['term_id' => 8300, 'slug' => 'full-week', 'name' => 'Full Week'],
                8400 => (object) ['term_id' => 8400, 'slug' => 'geneva', 'name' => 'Geneva'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Player FR venue slug',
                'pa_intersoccer-venues' => $fr_old_display,
                'attribute_pa_intersoccer-venues' => $fr_old_display,
                'Sites InterSoccer' => $fr_old_display,
                'pa_age-group' => '5-13-ans-journee-complete',
                'attribute_pa_age-group' => '5-13-ans-journee-complete',
                'pa_booking-type' => 'full-week',
                'attribute_pa_booking-type' => 'full-week',
                'pa_canton-region' => 'geneva',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
                'pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => $en_venue_slug,
                'attribute_pa_age-group' => '5-13-ans-journee-complete',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($en_venue_slug, $meta['pa_intersoccer-venues']);
        $this->assertSame($en_venue_slug, $meta['attribute_pa_intersoccer-venues']);
        $this->assertNotSame($en_venue_name, $meta['pa_intersoccer-venues']);
        $this->assertSame('5-13-ans-journee-complete', $meta['pa_age-group']);
    }

    public function test_de_move_stores_en_venue_slug_in_pa_intersoccer_venues_not_term_name(): void {
        $en_venue_slug = 'geneva-stade-de-vessy-champel';
        $en_venue_name = 'Geneva - Stade de Vessy (Champel)';
        $de_old_display = 'Genf - Stade de Varembé';

        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    $en_venue_slug => (object) ['term_id' => 8150, 'slug' => $en_venue_slug, 'name' => $en_venue_name],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 8151, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                8150 => (object) ['term_id' => 8150, 'slug' => $en_venue_slug, 'name' => $en_venue_name],
                8151 => (object) ['term_id' => 8151, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Player DE venue slug',
                'Aktivitätstyp' => 'Camp',
                'pa_intersoccer-venues' => $de_old_display,
                'attribute_pa_intersoccer-venues' => $de_old_display,
                'InterSoccer-Standorte' => $de_old_display,
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => $en_venue_slug,
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($en_venue_slug, $meta['pa_intersoccer-venues']);
        $this->assertSame($en_venue_slug, $meta['attribute_pa_intersoccer-venues']);
        $this->assertNotSame($en_venue_name, $meta['pa_intersoccer-venues']);
    }

    public function test_de_camp_move_updates_de_schedule_keys_without_adding_en_fr_labels(): void {
        \InterSoccerRRTestMeta::$data[self::TARGET_VAR] = [
            '_camp_start_date' => '2027-04-05',
            '_camp_end_date' => '2027-04-09',
            '_camp_week_index' => '2',
        ];

        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 9170, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                9170 => (object) ['term_id' => 9170, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $before = [
            'Activity Type' => 'Camp',
            'Aktivitätstyp' => 'Camp',
            'Assigned Attendee' => 'DE camp schedule',
            'Camp-Startdatum' => '2027-02-15',
            'Camp-Enddatum' => '2027-02-19',
            '_camp_start_date' => '2027-02-15',
            '_camp_end_date' => '2027-02-19',
            '_camp_week_index' => '1',
        ];

        $this->installMoveWorld([
            'item_meta' => $before,
            'target_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('2027-04-05', $meta['Camp-Startdatum']);
        $this->assertSame('2027-04-09', $meta['Camp-Enddatum']);
        $this->assertSame('2', $meta['_camp_week_index']);
        $this->assertArrayNotHasKey('Camp Start Date', $meta);
        $this->assertArrayNotHasKey('Date de début du camp', $meta);
    }

    public function test_camp_same_week_move_leaves_schedule_keys_unchanged(): void {
        $schedule = [
            '_camp_start_date' => '2027-04-05',
            '_camp_end_date' => '2027-04-09',
            '_camp_week_index' => '2',
        ];
        \InterSoccerRRTestMeta::$data[self::TARGET_VAR] = $schedule;
        \InterSoccerRRTestMeta::$data[self::SOURCE_VAR] = $schedule;

        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 9180, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                9180 => (object) ['term_id' => 9180, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $item_meta = [
            'Activity Type' => 'Camp',
            'Assigned Attendee' => 'Same week camp',
            'Camp Start Date' => '2027-04-05',
            'Camp End Date' => '2027-04-09',
            'Camp Week Index' => '2',
            '_camp_start_date' => '2027-04-05',
            '_camp_end_date' => '2027-04-09',
            '_camp_week_index' => '2',
        ];

        $this->installMoveWorld([
            'item_meta' => $item_meta,
            'target_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($item_meta['Camp Start Date'], $meta['Camp Start Date']);
        $this->assertSame($item_meta['Camp End Date'], $meta['Camp End Date']);
        $this->assertSame($item_meta['Camp Week Index'], $meta['Camp Week Index']);
        $this->assertSame($item_meta['_camp_start_date'], $meta['_camp_start_date']);
        $this->assertArrayNotHasKey('Camp-Startdatum', $meta);
    }

    public function test_camp_move_replaces_existing_schedule_keys_with_destination_dates(): void {
        \InterSoccerRRTestMeta::$data[self::TARGET_VAR] = [
            '_camp_start_date' => '2027-04-05',
            '_camp_end_date' => '2027-04-09',
            '_camp_week_index' => '2',
        ];
        \InterSoccerRRTestMeta::$data[self::SOURCE_VAR] = [
            '_camp_start_date' => '2027-02-15',
            '_camp_end_date' => '2027-02-19',
            '_camp_week_index' => '1',
        ];

        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 9100, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
                'pa_canton-region' => [
                    'geneva' => (object) ['term_id' => 9200, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
            ],
            [
                9100 => (object) ['term_id' => 9100, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                9200 => (object) ['term_id' => 9200, 'slug' => 'geneva', 'name' => 'Geneva'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Camp schedule move',
                'Booking Type' => 'Single Day(s)',
                'Days Selected' => 'Monday',
                'Camp Start Date' => '2027-02-15',
                'Camp End Date' => '2027-02-19',
                'Camp Week Index' => '1',
                '_camp_start_date' => '2027-02-15',
                '_camp_end_date' => '2027-02-19',
                '_camp_week_index' => '1',
                'Date de début du camp' => '2027-02-15',
                'Date de fin du camp' => '2027-02-19',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_canton-region' => 'geneva',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_roster_store;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        foreach ([
            'Camp Start Date',
            'Camp End Date',
            'Camp Week Index',
            '_camp_start_date',
            '_camp_end_date',
            '_camp_week_index',
            'Date de début du camp',
            'Date de fin du camp',
        ] as $key) {
            $this->assertArrayHasKey($key, $meta, $key);
        }
        $this->assertSame('2027-04-05', $meta['Camp Start Date']);
        $this->assertSame('2027-04-09', $meta['Camp End Date']);
        $this->assertSame('2', $meta['Camp Week Index']);
        $this->assertSame('2027-04-05', $meta['_camp_start_date']);
        $this->assertSame('2027-04-09', $meta['_camp_end_date']);
        $this->assertSame('2', $meta['_camp_week_index']);
        $this->assertSame('Monday', $meta['Days Selected']);
        $this->assertArrayNotHasKey('Camp-Startdatum', $meta);

        $row = $intersoccer_test_roster_store[self::ITEM_ID];
        $this->assertSame('2027-04-05', $row['start_date'] ?? '');
        $this->assertSame('2027-04-09', $row['end_date'] ?? '');
    }

    public function test_camp_move_without_destination_schedule_keeps_existing_line_dates(): void {
        unset(\InterSoccerRRTestMeta::$data[self::TARGET_VAR]);

        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 9300, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                9300 => (object) ['term_id' => 9300, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Camp no schedule target',
                'Camp Start Date' => '2027-02-15',
                'Camp End Date' => '2027-02-19',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('2027-02-15', $meta['Camp Start Date']);
        $this->assertSame('2027-02-19', $meta['Camp End Date']);
    }

    // Regression: RR #104 QA — PV intersoccer_get_camp_schedule_meta returns `week`, not week_index (camp-schedule.php ~55-69).
    public function test_camp_schedule_read_uses_week_key_from_pv_camp_schedule_meta(): void {
        $GLOBALS['intersoccer_test_camp_schedule_meta_stub'] = [
            self::TARGET_VAR => [
                'start' => '2027-04-05',
                'end'   => '2027-04-09',
                'week'  => 3,
            ],
        ];

        $schedule = intersoccer_migration_read_variation_camp_schedule_ymd(self::TARGET_VAR);
        $this->assertSame('3', $schedule['week_index']);

        $meta = intersoccer_migration_camp_schedule_other_meta_for_move(self::TARGET_VAR);
        $this->assertSame('3', $meta['Camp Week Index']);
        $this->assertSame('3', $meta['_camp_week_index']);
    }

    public function test_camp_schedule_read_week_null_keeps_existing_week_keys_on_move(): void {
        $GLOBALS['intersoccer_test_camp_schedule_meta_stub'] = [
            self::TARGET_VAR => [
                'start' => '2027-04-05',
                'end'   => '2027-04-09',
                'week'  => null,
            ],
        ];

        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 9400, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                9400 => (object) ['term_id' => 9400, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $before = [
            'Activity Type' => 'Camp',
            'Assigned Attendee' => 'Week null target',
            'Camp Week Index' => '2',
            '_camp_week_index' => '2',
            'Camp Start Date' => '2027-02-15',
            'Camp End Date' => '2027-02-19',
            '_camp_start_date' => '2027-02-15',
            '_camp_end_date' => '2027-02-19',
        ];

        $this->installMoveWorld([
            'item_meta' => $before,
            'target_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('2', $meta['Camp Week Index']);
        $this->assertSame('2', $meta['_camp_week_index']);
        $this->assertSame('2027-04-05', $meta['Camp Start Date']);
    }

    public function test_camp_schedule_read_legacy_week_index_shape_still_works(): void {
        $GLOBALS['intersoccer_test_camp_schedule_meta_stub'] = [
            self::TARGET_VAR => [
                'start'      => '2027-04-05',
                'end'        => '2027-04-09',
                'week_index' => '4',
            ],
        ];

        $schedule = intersoccer_migration_read_variation_camp_schedule_ymd(self::TARGET_VAR);
        $this->assertSame('4', $schedule['week_index']);
    }

    public function test_camp_move_does_not_add_week_keys_when_line_had_none(): void {
        $GLOBALS['intersoccer_test_camp_schedule_meta_stub'] = [
            self::TARGET_VAR => [
                'start' => '2027-04-05',
                'end'   => '2027-04-09',
                'week'  => 3,
            ],
        ];

        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 9500, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                9500 => (object) ['term_id' => 9500, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'No week keys',
                'Camp Start Date' => '2027-02-15',
                'Camp End Date' => '2027-02-19',
                '_camp_start_date' => '2027-02-15',
                '_camp_end_date' => '2027-02-19',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertArrayNotHasKey('Camp Week Index', $meta);
        $this->assertArrayNotHasKey('_camp_week_index', $meta);
    }

    public function test_fr_move_keeps_localized_facets_when_en_slugs_unchanged(): void {
        $fr_age = '5-13 ans (Journée complète)';
        $fr_canton = 'Genève';
        $fr_city = 'Genève';
        $fr_venue = 'Genève - Stade de Varembé (Nations)';
        $fr_camp_terms = 'Semaine complète';
        $fr_booking = 'Semaine complète';
        $venue_slug = 'geneve-stade-de-varembe-nations';
        $age_slug = '5-13-ans-journee-complete';
        $camp_term_slug = 'winter-week-1-february-15-19-5-days';

        $item_meta_before = [
            'Activity Type' => 'Camp',
            'Assigned Attendee' => 'FR unchanged QA line',
            'Age Group' => $fr_age,
            'Canton / Region' => $fr_canton,
            'City' => $fr_city,
            'Sites InterSoccer' => $fr_venue,
            'Camp Terms' => $fr_camp_terms,
            'Booking Type' => $fr_booking,
            '_intersoccer_canonical_venue' => 'geneva-stade-de-varembe-nations',
            '_intersoccer_canonical_age_group' => '5-13y-full-day',
            '_intersoccer_canonical_canton' => 'geneve',
            '_intersoccer_canonical_camp_terms' => $camp_term_slug,
            'pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'pa_age-group' => $age_slug,
            'attribute_pa_age-group' => $age_slug,
            'pa_camp-terms' => $camp_term_slug,
            'attribute_pa_camp-terms' => $camp_term_slug,
        ];

        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    $venue_slug => (object) ['term_id' => 9610, 'slug' => $venue_slug, 'name' => $fr_venue],
                    'geneva-stade-de-varembe-nations' => (object) ['term_id' => 9611, 'slug' => 'geneva-stade-de-varembe-nations', 'name' => 'Geneva - Stade de Varembé (Nations)'],
                ],
                'pa_age-group' => [
                    $age_slug => (object) ['term_id' => 9612, 'slug' => $age_slug, 'name' => $fr_age],
                    '5-13y-full-day' => (object) ['term_id' => 9613, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                ],
                'pa_canton-region' => [
                    'geneve' => (object) ['term_id' => 9614, 'slug' => 'geneve', 'name' => $fr_canton],
                    'geneva' => (object) ['term_id' => 9615, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
                'pa_city' => [
                    'geneve' => (object) ['term_id' => 9616, 'slug' => 'geneve', 'name' => $fr_city],
                    'geneva' => (object) ['term_id' => 9617, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
                'pa_camp-terms' => [
                    $camp_term_slug => (object) ['term_id' => 9618, 'slug' => $camp_term_slug, 'name' => 'Winter Week 1 February 15-19 5 days'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9619, 'slug' => 'full-week', 'name' => 'Full Week'],
                    'semaine-complete' => (object) ['term_id' => 9620, 'slug' => 'semaine-complete', 'name' => $fr_booking],
                ],
            ],
            [
                9610 => (object) ['term_id' => 9610, 'slug' => $venue_slug, 'name' => $fr_venue],
                9611 => (object) ['term_id' => 9611, 'slug' => 'geneva-stade-de-varembe-nations', 'name' => 'Geneva - Stade de Varembé (Nations)'],
                9612 => (object) ['term_id' => 9612, 'slug' => $age_slug, 'name' => $fr_age],
                9613 => (object) ['term_id' => 9613, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                9614 => (object) ['term_id' => 9614, 'slug' => 'geneve', 'name' => $fr_canton],
                9615 => (object) ['term_id' => 9615, 'slug' => 'geneva', 'name' => 'Geneva'],
                9616 => (object) ['term_id' => 9616, 'slug' => 'geneve', 'name' => $fr_city],
                9617 => (object) ['term_id' => 9617, 'slug' => 'geneva', 'name' => 'Geneva'],
                9618 => (object) ['term_id' => 9618, 'slug' => $camp_term_slug, 'name' => 'Winter Week 1 February 15-19 5 days'],
                9619 => (object) ['term_id' => 9619, 'slug' => 'full-week', 'name' => 'Full Week'],
                9620 => (object) ['term_id' => 9620, 'slug' => 'semaine-complete', 'name' => $fr_booking],
            ]
        );
        $this->stubWpmlEnTerms([9610 => 9611, 9612 => 9613, 9614 => 9615, 9616 => 9617, 9620 => 9619]);

        $this->installMoveWorld([
            'item_meta' => $item_meta_before,
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => $venue_slug,
                'attribute_pa_age-group' => $age_slug,
                'attribute_pa_camp-terms' => $camp_term_slug,
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        foreach ($item_meta_before as $key => $expected) {
            if (strpos($key, '_intersoccer_canonical_') === 0) {
                continue;
            }
            $this->assertSame($expected, $meta[$key] ?? null, 'meta key ' . $key);
        }
    }

    public function test_de_move_keeps_localized_facets_when_en_slugs_unchanged(): void {
        $de_age = '5-13 Jahre (Ganztägig)';
        $de_canton = 'Genf';
        $de_city = 'Genf';
        $de_venue = 'Genf - Stade de Varembé (Nations)';
        $venue_slug = 'geneve-stade-de-varembe-nations';
        $age_slug = '5-13-jahre-ganzer-tag';
        $camp_term_slug = 'winter-week-1-february-15-19-5-days';

        $item_meta_before = [
            'Activity Type' => 'Camp',
            'Assigned Attendee' => 'DE unchanged QA line',
            'Altersgruppe' => $de_age,
            'Kanton / Region' => $de_canton,
            'Stadt' => $de_city,
            'InterSoccer-Standorte' => $de_venue,
            'Camp Terms' => 'Winterwoche 1',
            'pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'pa_age-group' => $age_slug,
            'attribute_pa_age-group' => $age_slug,
            'pa_camp-terms' => $camp_term_slug,
            'attribute_pa_camp-terms' => $camp_term_slug,
            '_intersoccer_canonical_venue' => 'geneva-stade-de-varembe-nations',
            '_intersoccer_canonical_age_group' => '5-13y-full-day',
            '_intersoccer_canonical_canton' => 'geneve',
            '_intersoccer_canonical_camp_terms' => $camp_term_slug,
        ];

        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    $venue_slug => (object) ['term_id' => 9630, 'slug' => $venue_slug, 'name' => $de_venue],
                    'geneva-stade-de-varembe-nations' => (object) ['term_id' => 9631, 'slug' => 'geneva-stade-de-varembe-nations', 'name' => 'Geneva - Stade de Varembé (Nations)'],
                ],
                'pa_age-group' => [
                    $age_slug => (object) ['term_id' => 9632, 'slug' => $age_slug, 'name' => $de_age],
                    '5-13y-full-day' => (object) ['term_id' => 9633, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                ],
                'pa_canton-region' => [
                    'geneve' => (object) ['term_id' => 9634, 'slug' => 'geneve', 'name' => $de_canton],
                    'geneva' => (object) ['term_id' => 9635, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
                'pa_city' => [
                    'geneve' => (object) ['term_id' => 9636, 'slug' => 'geneve', 'name' => $de_city],
                    'geneva' => (object) ['term_id' => 9637, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
                'pa_camp-terms' => [
                    $camp_term_slug => (object) ['term_id' => 9638, 'slug' => $camp_term_slug, 'name' => 'Winter Week 1 February 15-19 5 days'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9639, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9630 => (object) ['term_id' => 9630, 'slug' => $venue_slug, 'name' => $de_venue],
                9631 => (object) ['term_id' => 9631, 'slug' => 'geneva-stade-de-varembe-nations', 'name' => 'Geneva - Stade de Varembé (Nations)'],
                9632 => (object) ['term_id' => 9632, 'slug' => $age_slug, 'name' => $de_age],
                9633 => (object) ['term_id' => 9633, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                9634 => (object) ['term_id' => 9634, 'slug' => 'geneve', 'name' => $de_canton],
                9635 => (object) ['term_id' => 9635, 'slug' => 'geneva', 'name' => 'Geneva'],
                9636 => (object) ['term_id' => 9636, 'slug' => 'geneve', 'name' => $de_city],
                9637 => (object) ['term_id' => 9637, 'slug' => 'geneva', 'name' => 'Geneva'],
                9638 => (object) ['term_id' => 9638, 'slug' => $camp_term_slug, 'name' => 'Winter Week 1 February 15-19 5 days'],
                9639 => (object) ['term_id' => 9639, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([9630 => 9631, 9632 => 9633, 9634 => 9635, 9636 => 9637]);

        $this->installMoveWorld([
            'item_meta' => $item_meta_before,
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => $venue_slug,
                'attribute_pa_age-group' => $age_slug,
                'attribute_pa_camp-terms' => $camp_term_slug,
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($de_age, $meta['Altersgruppe']);
        $this->assertSame($de_canton, $meta['Kanton / Region']);
        $this->assertSame($de_city, $meta['Stadt']);
        $this->assertSame($de_venue, $meta['InterSoccer-Standorte']);
    }

    public function test_fr_move_writes_en_only_for_changed_facets(): void {
        $fr_age = '5-13 ans (Journée complète)';
        $new_en_venue = 'Geneva - Stade de Vessy (Champel)';

        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'geneva-stade-vessy-champel' => (object) ['term_id' => 9700, 'slug' => 'geneva-stade-vessy-champel', 'name' => $new_en_venue],
                    'stade-de-vessy' => (object) ['term_id' => 9701, 'slug' => 'stade-de-vessy', 'name' => 'Sites InterSoccer'],
                ],
                'pa_age-group' => [
                    '5-13-ans-journee-complete' => (object) ['term_id' => 9702, 'slug' => '5-13-ans-journee-complete', 'name' => $fr_age],
                    '5-13y-full-day' => (object) ['term_id' => 9703, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9704, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9700 => (object) ['term_id' => 9700, 'slug' => 'geneva-stade-vessy-champel', 'name' => $new_en_venue],
                9701 => (object) ['term_id' => 9701, 'slug' => 'stade-de-vessy', 'name' => 'Sites InterSoccer'],
                9702 => (object) ['term_id' => 9702, 'slug' => '5-13-ans-journee-complete', 'name' => $fr_age],
                9703 => (object) ['term_id' => 9703, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                9704 => (object) ['term_id' => 9704, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([9701 => 9700, 9702 => 9703]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR changed venue only',
                "Groupe d'âge" => $fr_age,
                'Lieux InterSoccer' => 'Sites InterSoccer',
                '_intersoccer_canonical_venue' => 'stade-de-vessy',
                '_intersoccer_canonical_age_group' => '5-13y-full-day',
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_age-group' => '5-13-ans-journee-complete',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'geneva-stade-vessy-champel',
                'attribute_pa_age-group' => '5-13-ans-journee-complete',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($fr_age, $meta["Groupe d'âge"]);
        $this->assertSame('geneva-stade-vessy-champel', $meta['_intersoccer_canonical_venue']);
    }

    public function test_de_move_writes_en_only_for_changed_facets(): void {
        $de_age = '5-13 Jahre (Ganztägig)';
        $de_canton = 'Genf';
        $de_city = 'Genf';
        $old_de_venue = 'Genf - Stade de Varembé (Nations)';
        $new_en_venue = 'Geneva - Stade de Vessy (Champel)';

        $this->registerTerms(
            [
                'pa_intersoccer-venues' => [
                    'geneva-stade-vessy-champel' => (object) ['term_id' => 9750, 'slug' => 'geneva-stade-vessy-champel', 'name' => $new_en_venue],
                    'geneve-stade-de-varembe-nations' => (object) ['term_id' => 9751, 'slug' => 'geneve-stade-de-varembe-nations', 'name' => $old_de_venue],
                ],
                'pa_age-group' => [
                    '5-13-jahre-ganzer-tag' => (object) ['term_id' => 9752, 'slug' => '5-13-jahre-ganzer-tag', 'name' => $de_age],
                    '5-13y-full-day' => (object) ['term_id' => 9753, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                ],
                'pa_canton-region' => [
                    'geneve' => (object) ['term_id' => 9754, 'slug' => 'geneve', 'name' => $de_canton],
                    'geneva' => (object) ['term_id' => 9755, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
                'pa_city' => [
                    'geneve' => (object) ['term_id' => 9756, 'slug' => 'geneve', 'name' => $de_city],
                    'geneva' => (object) ['term_id' => 9757, 'slug' => 'geneva', 'name' => 'Geneva'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9758, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9750 => (object) ['term_id' => 9750, 'slug' => 'geneva-stade-vessy-champel', 'name' => $new_en_venue],
                9751 => (object) ['term_id' => 9751, 'slug' => 'geneve-stade-de-varembe-nations', 'name' => $old_de_venue],
                9752 => (object) ['term_id' => 9752, 'slug' => '5-13-jahre-ganzer-tag', 'name' => $de_age],
                9753 => (object) ['term_id' => 9753, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                9754 => (object) ['term_id' => 9754, 'slug' => 'geneve', 'name' => $de_canton],
                9755 => (object) ['term_id' => 9755, 'slug' => 'geneva', 'name' => 'Geneva'],
                9756 => (object) ['term_id' => 9756, 'slug' => 'geneve', 'name' => $de_city],
                9757 => (object) ['term_id' => 9757, 'slug' => 'geneva', 'name' => 'Geneva'],
                9758 => (object) ['term_id' => 9758, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([9751 => 9750, 9752 => 9753, 9754 => 9755, 9756 => 9757]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE changed venue only',
                'Altersgruppe' => $de_age,
                'Kanton / Region' => $de_canton,
                'Stadt' => $de_city,
                'InterSoccer-Standorte' => $old_de_venue,
                '_intersoccer_canonical_venue' => 'geneva-stade-de-varembe-nations',
                '_intersoccer_canonical_age_group' => '5-13y-full-day',
                '_intersoccer_canonical_canton' => 'geneve',
                'pa_intersoccer-venues' => 'geneve-stade-de-varembe-nations',
                'attribute_pa_intersoccer-venues' => 'geneve-stade-de-varembe-nations',
                'pa_age-group' => '5-13-jahre-ganzer-tag',
                'attribute_pa_age-group' => '5-13-jahre-ganzer-tag',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'geneva-stade-vessy-champel',
                'attribute_pa_age-group' => '5-13-jahre-ganzer-tag',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($de_age, $meta['Altersgruppe']);
        $this->assertSame($de_canton, $meta['Kanton / Region']);
        $this->assertSame($de_city, $meta['Stadt']);
        $this->assertSame('geneva-stade-vessy-champel', $meta['_intersoccer_canonical_venue']);
    }

    public function test_fr_move_to_girls_only_writes_activity_type_from_destination_not_preserve(): void {
        $this->registerTerms(
            [
                'pa_girls-only' => [
                    'yes' => (object) ['term_id' => 9760, 'slug' => 'yes', 'name' => 'Yes'],
                    'no' => (object) ['term_id' => 9761, 'slug' => 'no', 'name' => 'No'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9762, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9760 => (object) ['term_id' => 9760, 'slug' => 'yes', 'name' => 'Yes'],
                9761 => (object) ['term_id' => 9761, 'slug' => 'no', 'name' => 'No'],
                9762 => (object) ['term_id' => 9762, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR mixed to girls only',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'yes',
            ],
        ]);

        $response = $this->invokeMoveAjax(null, ['allow_cross_gender' => '1']);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $expected = intersoccer_migration_activity_type_for_girls_only_tristate('Camp', 'yes');
        $this->assertSame($expected, $meta['Activity Type']);
        $this->assertNotSame('Camp', $meta['Activity Type']);
    }

    public function test_de_move_keeps_localized_age_group_when_slug_unchanged(): void {
        $de_age = '5-13 Jahre (Ganztägig)';

        $this->registerTerms(
            [
                'pa_age-group' => [
                    '5-13-jahre-ganzer-tag' => (object) ['term_id' => 9800, 'slug' => '5-13-jahre-ganzer-tag', 'name' => $de_age],
                    '5-13y-full-day' => (object) ['term_id' => 9801, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9802, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9800 => (object) ['term_id' => 9800, 'slug' => '5-13-jahre-ganzer-tag', 'name' => $de_age],
                9801 => (object) ['term_id' => 9801, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
                9802 => (object) ['term_id' => 9802, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([9800 => 9801]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE unchanged age',
                'Altersgruppe' => $de_age,
                '_intersoccer_canonical_age_group' => '5-13y-full-day',
                'attribute_pa_age-group' => '5-13-jahre-ganzer-tag',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_age-group' => '5-13-jahre-ganzer-tag',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($de_age, $meta['Altersgruppe']);
    }

    public function test_fr_course_move_keeps_age_group_when_unchanged(): void {
        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_get_product_type_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };

        $fr_age = '3-12 ans';

        $this->registerTerms(
            [
                'pa_age-group' => [
                    '3-12-ans' => (object) ['term_id' => 9900, 'slug' => '3-12-ans', 'name' => $fr_age],
                    '3-12y' => (object) ['term_id' => 9901, 'slug' => '3-12y', 'name' => '3-12y'],
                ],
                'pa_booking-type' => [
                    'full-term' => (object) ['term_id' => 9902, 'slug' => 'full-term', 'name' => 'Full Term'],
                ],
            ],
            [
                9900 => (object) ['term_id' => 9900, 'slug' => '3-12-ans', 'name' => $fr_age],
                9901 => (object) ['term_id' => 9901, 'slug' => '3-12y', 'name' => '3-12y'],
                9902 => (object) ['term_id' => 9902, 'slug' => 'full-term', 'name' => 'Full Term'],
            ]
        );
        $this->stubWpmlEnTerms([9900 => 9901]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Course',
                'Assigned Attendee' => 'FR course age',
                "Groupe d'âge" => $fr_age,
                '_intersoccer_canonical_age_group' => '3-12y',
                'attribute_pa_age-group' => '3-12-ans',
            ],
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_age-group' => '3-12-ans',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($fr_age, $meta["Groupe d'âge"]);
    }

    public function test_move_ajax_gender_block_without_allow_cross_gender(): void {
        $this->registerTerms(
            [
                'pa_girls-only' => [
                    'yes' => (object) ['term_id' => 9910, 'slug' => 'yes', 'name' => 'Yes'],
                    'no' => (object) ['term_id' => 9911, 'slug' => 'no', 'name' => 'No'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9912, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9910 => (object) ['term_id' => 9910, 'slug' => 'yes', 'name' => 'Yes'],
                9911 => (object) ['term_id' => 9911, 'slug' => 'no', 'name' => 'No'],
                9912 => (object) ['term_id' => 9912, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'yes',
            ],
        ]);

        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data, $intersoccer_test_roster_store;
        $meta_before = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $item_data_before = $intersoccer_test_wc_order_item_data[self::ITEM_ID];
        $roster_before = $intersoccer_test_roster_store[self::ITEM_ID];

        $response = $this->invokeMoveAjax(null, ['allow_cross_gender' => '0']);
        $this->assertTrue(!empty($response['data']['gender_block']));
        $this->assertSame(0, (int) ($response['data']['moved_count'] ?? 0));
        $this->assertSame($meta_before, $intersoccer_test_wc_order_item_meta[self::ITEM_ID]);
        $this->assertSame((int) $item_data_before['variation_id'], (int) $intersoccer_test_wc_order_item_data[self::ITEM_ID]['variation_id']);
        $this->assertSame((int) $item_data_before['product_id'], (int) $intersoccer_test_wc_order_item_data[self::ITEM_ID]['product_id']);
        $this->assertSame($roster_before, $intersoccer_test_roster_store[self::ITEM_ID]);
    }

    public function test_move_ajax_proceeds_with_allow_cross_gender(): void {
        $this->registerTerms(
            [
                'pa_girls-only' => [
                    'yes' => (object) ['term_id' => 9920, 'slug' => 'yes', 'name' => 'Yes'],
                    'no' => (object) ['term_id' => 9921, 'slug' => 'no', 'name' => 'No'],
                ],
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9922, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9920 => (object) ['term_id' => 9920, 'slug' => 'yes', 'name' => 'Yes'],
                9921 => (object) ['term_id' => 9921, 'slug' => 'no', 'name' => 'No'],
                9922 => (object) ['term_id' => 9922, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);

        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'yes',
            ],
        ]);

        $response = $this->invokeMoveAjax(null, ['allow_cross_gender' => '1']);
        $this->assertEmpty($response['data']['gender_block'] ?? null);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
    }

    private function ensureCourseMetaStub(): void {
        if (!function_exists('intersoccer_get_course_meta')) {
            eval(
                'function intersoccer_get_course_meta($variation_id, $key, $default = null) {
                    $map = $GLOBALS["intersoccer_test_course_meta_by_variation"][(int) $variation_id] ?? [];
                    return array_key_exists($key, $map) ? $map[$key] : $default;
                }'
            );
        }
    }

    private function ensureParentProductAttributesStub(): void {
        if (!function_exists('intersoccer_get_parent_product_attributes')) {
            eval(
                'function intersoccer_get_parent_product_attributes($parent_id, $variation_id) {
                    return $GLOBALS["intersoccer_test_parent_product_attributes_by_variation"][(int) $variation_id] ?? [];
                }'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function invokeMoveAjaxBlocked(array $post_overrides = []): array {
        $GLOBALS['intersoccer_test_json_error_throws'] = true;
        try {
            return $this->invokeMoveAjax(null, $post_overrides);
        } catch (\RuntimeException $e) {
            $decoded = json_decode($e->getMessage(), true);

            return is_array($decoded) ? $decoded : [];
        } finally {
            unset($GLOBALS['intersoccer_test_json_error_throws']);
        }
    }

    /**
     * @param array<string,mixed> $meta_before
     * @param array<string,mixed> $item_data_before
     * @param array<string,mixed> $roster_before
     * @param array<string,int>   $stock_before
     */
    private function assertMoveFullyUnchanged(
        array $meta_before,
        array $item_data_before,
        array $roster_before,
        array $stock_before
    ): void {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data, $intersoccer_test_roster_store;
        global $intersoccer_test_wc_stock_qty;
        $this->assertSame($meta_before, $intersoccer_test_wc_order_item_meta[self::ITEM_ID]);
        $this->assertSame((int) $item_data_before['variation_id'], (int) $intersoccer_test_wc_order_item_data[self::ITEM_ID]['variation_id']);
        $this->assertSame((int) $item_data_before['product_id'], (int) $intersoccer_test_wc_order_item_data[self::ITEM_ID]['product_id']);
        $this->assertSame($roster_before, $intersoccer_test_roster_store[self::ITEM_ID]);
        $this->assertSame($stock_before, $intersoccer_test_wc_stock_qty);
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,mixed>, 3: array<string,int>}
     */
    private function captureMoveStateBefore(): array {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data, $intersoccer_test_roster_store, $intersoccer_test_wc_stock_qty;

        return [
            $intersoccer_test_wc_order_item_meta[self::ITEM_ID],
            $intersoccer_test_wc_order_item_data[self::ITEM_ID],
            $intersoccer_test_roster_store[self::ITEM_ID],
            $intersoccer_test_wc_stock_qty,
        ];
    }

    private function seedRosterHistoryMetaForBlockTest(): void {
        global $intersoccer_test_wc_order_item_meta;
        $history_key = function_exists('intersoccer_roster_history_meta_key')
            ? intersoccer_roster_history_meta_key()
            : '_intersoccer_roster_history';
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$history_key] = '[{"type":"origin","at":"2027-01-01T00:00:00+00:00"}]';
    }

    /**
     * @param array<string,string> $post_overrides
     */
    private function assertBookingTypeMismatchHardBlockNoWrites(array $post_overrides = []): void {
        [$meta_before, $item_before, $roster_before, $stock_before] = $this->captureMoveStateBefore();
        $response = $this->invokeMoveAjaxBlocked($post_overrides);
        $this->assertFalse($response['success'] ?? true);
        $this->assertTrue($response['data']['hard_block'] ?? false);
        $this->assertSame(
            intersoccer_roster_move_ui_string('booking_type_mismatch'),
            $response['data']['message'] ?? ''
        );
        $this->assertMoveFullyUnchanged($meta_before, $item_before, $roster_before, $stock_before);
        $this->assertEmpty($GLOBALS['intersoccer_test_order_notes'][self::ORDER_ID] ?? []);
    }

    private function registerCourseMoveTerms(): void {
        $this->registerTerms(
            [
                'pa_course-times' => [
                    '1115-1245' => (object) ['term_id' => 8801, 'slug' => '1115-1245', 'name' => '11h15-12h45'],
                    '1400-1530' => (object) ['term_id' => 8802, 'slug' => '1400-1530', 'name' => '14h00-15h30'],
                ],
                'pa_course-day' => [
                    'saturday' => (object) ['term_id' => 8803, 'slug' => 'saturday', 'name' => 'Saturday'],
                ],
                'pa_booking-type' => [
                    'full-term' => (object) ['term_id' => 8804, 'slug' => 'full-term', 'name' => 'Full Term'],
                ],
                'pa_intersoccer-venues' => [
                    'geneva-site' => (object) ['term_id' => 8805, 'slug' => 'geneva-site', 'name' => 'Sites InterSoccer FR'],
                ],
                'pa_age-group' => [
                    '5-8y' => (object) ['term_id' => 8806, 'slug' => '5-8y', 'name' => '5-8y FR'],
                ],
            ],
            [
                8801 => (object) ['term_id' => 8801, 'slug' => '1115-1245', 'name' => '11h15-12h45'],
                8802 => (object) ['term_id' => 8802, 'slug' => '1400-1530', 'name' => '14h00-15h30'],
                8803 => (object) ['term_id' => 8803, 'slug' => 'saturday', 'name' => 'Saturday'],
                8804 => (object) ['term_id' => 8804, 'slug' => 'full-term', 'name' => 'Full Term'],
                8805 => (object) ['term_id' => 8805, 'slug' => 'geneva-site', 'name' => 'Sites InterSoccer FR'],
                8806 => (object) ['term_id' => 8806, 'slug' => '5-8y', 'name' => '5-8y FR'],
            ]
        );
        $this->stubWpmlEnTerms([]);
    }

    private function installCourseMoveWorld(array $options): void {
        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_get_product_type_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };
        $this->ensureCourseMetaStub();
        $this->ensureParentProductAttributesStub();
        $this->installMoveWorld($options);
    }

    // Regression: RR #104 QA — time-only FR course move keeps localized schedule + Note bytes.
    public function test_fr_course_time_only_move_preserves_localized_schedule_note_and_parent_fields(): void {
        $this->registerCourseMoveTerms();
        $shared_course_meta = [
            '_course_start_date' => '2027-01-10',
            '_end_date' => '2027-06-13',
            '_course_holiday_dates' => ['2027-02-14', '2027-03-28', '2027-04-04'],
        ];
        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            self::SOURCE_VAR => $shared_course_meta,
            self::TARGET_VAR => $shared_course_meta,
        ];
        $note_en = 'Join anytime – prices are pro-rated if you start late.';
        $GLOBALS['intersoccer_test_parent_product_attributes_by_variation'] = [
            self::SOURCE_VAR => ['Note' => $note_en],
            self::TARGET_VAR => ['Note' => $note_en],
        ];
        $fr_note = 'Inscription possible à tout moment – les tarifs sont calculés au prorata si vous…';
        $fr_start = 'janvier 10, 2027';
        $fr_end = 'juin 13, 2027';
        $fr_holidays = 'février 14, 2027, mars 28, 2027, avril 4, 2027';

        $this->installCourseMoveWorld([
            'source_attrs' => [
                'attribute_pa_course-times' => '1115-1245',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_intersoccer-venues' => 'geneva-site',
                'attribute_pa_age-group' => '5-8y',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_course-times' => '1400-1530',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_intersoccer-venues' => 'geneva-site',
                'attribute_pa_age-group' => '5-8y',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Course',
                'Assigned Attendee' => 'FR course QA',
                'Start Date' => $fr_start,
                'End Date' => $fr_end,
                'Holidays' => $fr_holidays,
                'Note' => $fr_note,
                'Course Times' => '11h15-12h45',
                'pa_course-times' => '1115-1245',
                'attribute_pa_course-times' => '1115-1245',
                '_intersoccer_canonical_booking_type' => 'full-term',
            ],
            'roster_row' => [
                'activity_type' => 'Course',
                'booking_type' => 'Full Term',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($fr_note, $meta['Note']);
        $this->assertSame($fr_start, $meta['Start Date']);
        $this->assertSame($fr_end, $meta['End Date']);
        $this->assertSame($fr_holidays, $meta['Holidays']);
        $this->assertSame('14h00-15h30', $meta['Course Times'] ?? '');
        $this->assertSame('1400-1530', $meta['pa_course-times'] ?? '');
    }

    public function test_de_course_time_only_move_preserves_localized_schedule_and_note(): void {
        $this->registerCourseMoveTerms();
        $shared_course_meta = [
            '_course_start_date' => '2027-01-10',
            '_end_date' => '2027-06-13',
            '_course_holiday_dates' => ['2027-02-14', '2027-03-28', '2027-04-04'],
        ];
        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            self::SOURCE_VAR => $shared_course_meta,
            self::TARGET_VAR => $shared_course_meta,
        ];
        $note_en = 'Join anytime – prices are pro-rated if you start late.';
        $GLOBALS['intersoccer_test_parent_product_attributes_by_variation'] = [
            self::SOURCE_VAR => ['Note' => $note_en],
            self::TARGET_VAR => ['Note' => $note_en],
        ];
        $de_note = 'Jederzeit einsteigen – Preise werden anteilig berechnet, wenn Sie später starten.';
        $de_start = '10. Januar 2027';
        $de_end = '13. Juni 2027';
        $de_holidays = '14. Februar 2027, 28. März 2027, 4. April 2027';

        $this->installCourseMoveWorld([
            'source_attrs' => [
                'attribute_pa_course-times' => '1115-1245',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_course-times' => '1400-1530',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Course',
                'Assigned Attendee' => 'DE course QA',
                'Start Date' => $de_start,
                'End Date' => $de_end,
                'Holidays' => $de_holidays,
                'Note' => $de_note,
                'Course Times' => '11h15-12h45',
                'pa_course-times' => '1115-1245',
                'attribute_pa_course-times' => '1115-1245',
                '_intersoccer_canonical_booking_type' => 'full-term',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($de_note, $meta['Note']);
        $this->assertSame($de_start, $meta['Start Date']);
        $this->assertSame($de_end, $meta['End Date']);
        $this->assertSame($de_holidays, $meta['Holidays']);
        $this->assertSame('1400-1530', $meta['pa_course-times'] ?? '');
    }

    public function test_course_move_with_different_destination_dates_rewrites_start_and_end(): void {
        $this->registerCourseMoveTerms();
        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            self::SOURCE_VAR => [
                '_course_start_date' => '2027-01-10',
                '_end_date' => '2027-06-13',
                '_course_holiday_dates' => ['2027-02-14'],
            ],
            self::TARGET_VAR => [
                '_course_start_date' => '2027-02-01',
                '_end_date' => '2027-07-01',
                '_course_holiday_dates' => ['2027-03-01'],
            ],
        ];
        $this->installCourseMoveWorld([
            'source_attrs' => [
                'attribute_pa_course-times' => '1115-1245',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_course-times' => '1115-1245',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Course',
                'Assigned Attendee' => 'Course date change',
                'Start Date' => 'janvier 10, 2027',
                'End Date' => 'juin 13, 2027',
                'Holidays' => 'février 14, 2027',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('February 1, 2027', $meta['Start Date'] ?? '');
        $this->assertSame('July 1, 2027', $meta['End Date'] ?? '');
        $this->assertSame('March 1, 2027', $meta['Holidays'] ?? '');
    }

    public function test_camp_single_days_to_full_week_move_is_hard_blocked_with_no_writes(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 9700, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                    'full-week' => (object) ['term_id' => 9701, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9700 => (object) ['term_id' => 9700, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                9701 => (object) ['term_id' => 9701, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);
        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Booking block QA',
                '_intersoccer_canonical_booking_type' => 'single-days',
            ],
        ]);

        $this->seedRosterHistoryMetaForBlockTest();
        $this->assertBookingTypeMismatchHardBlockNoWrites(['confirm_warnings' => '1']);
    }

    public function test_camp_full_week_to_single_days_move_is_hard_blocked(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'single-days' => (object) ['term_id' => 9710, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                    'full-week' => (object) ['term_id' => 9711, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9710 => (object) ['term_id' => 9710, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                9711 => (object) ['term_id' => 9711, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([]);
        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Full week block QA',
                '_intersoccer_canonical_booking_type' => 'full-week',
            ],
        ]);

        $this->seedRosterHistoryMetaForBlockTest();
        $this->assertBookingTypeMismatchHardBlockNoWrites([
            'confirm_warnings' => '1',
            'allow_cross_gender' => '1',
        ]);
    }

    public function test_course_full_term_to_single_days_move_is_hard_blocked(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'full-term' => (object) ['term_id' => 9720, 'slug' => 'full-term', 'name' => 'Full Term'],
                    'single-days' => (object) ['term_id' => 9721, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                9720 => (object) ['term_id' => 9720, 'slug' => 'full-term', 'name' => 'Full Term'],
                9721 => (object) ['term_id' => 9721, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([]);
        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_get_product_type_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };
        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Course',
                '_intersoccer_canonical_booking_type' => 'full-term',
            ],
            'roster_row' => ['activity_type' => 'Course', 'booking_type' => 'Full Term'],
        ]);

        $this->seedRosterHistoryMetaForBlockTest();
        $this->assertBookingTypeMismatchHardBlockNoWrites([
            'confirm_warnings' => '1',
            'allow_cross_gender' => '1',
        ]);
    }

    public function test_fr_camp_line_without_canonical_blocks_on_cross_booking_type_en_target(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'jours-selectionnes' => (object) ['term_id' => 9740, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                    'single-days' => (object) ['term_id' => 9741, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                    'semaine-complete' => (object) ['term_id' => 9742, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
                    'full-week' => (object) ['term_id' => 9743, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9740 => (object) ['term_id' => 9740, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                9741 => (object) ['term_id' => 9741, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                9742 => (object) ['term_id' => 9742, 'slug' => 'semaine-complete', 'name' => 'Semaine complète'],
                9743 => (object) ['term_id' => 9743, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([9740 => 9741, 9742 => 9743]);

        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_booking-type' => 'semaine-complete',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'single-days',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR slug booking block',
                'Type de réservation' => 'Semaine complète',
                'pa_booking-type' => 'semaine-complete',
                'attribute_pa_booking-type' => 'semaine-complete',
            ],
        ]);

        $this->seedRosterHistoryMetaForBlockTest();
        $this->assertBookingTypeMismatchHardBlockNoWrites(['confirm_warnings' => '1']);
    }

    public function test_de_camp_line_without_canonical_blocks_on_cross_booking_type_en_target(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'einzelne-tage' => (object) ['term_id' => 9750, 'slug' => 'einzelne-tage', 'name' => 'Einzelne Tage'],
                    'single-days' => (object) ['term_id' => 9751, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                    'ganze-woche' => (object) ['term_id' => 9752, 'slug' => 'ganze-woche', 'name' => 'Ganze Woche'],
                    'full-week' => (object) ['term_id' => 9753, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [
                9750 => (object) ['term_id' => 9750, 'slug' => 'einzelne-tage', 'name' => 'Einzelne Tage'],
                9751 => (object) ['term_id' => 9751, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                9752 => (object) ['term_id' => 9752, 'slug' => 'ganze-woche', 'name' => 'Ganze Woche'],
                9753 => (object) ['term_id' => 9753, 'slug' => 'full-week', 'name' => 'Full Week'],
            ]
        );
        $this->stubWpmlEnTerms([9750 => 9751, 9752 => 9753]);

        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_booking-type' => 'einzelne-tage',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'DE slug booking block',
                'Buchungstyp' => 'Einzelne Tage',
                'pa_booking-type' => 'einzelne-tage',
                'attribute_pa_booking-type' => 'einzelne-tage',
            ],
        ]);

        $this->seedRosterHistoryMetaForBlockTest();
        $this->assertBookingTypeMismatchHardBlockNoWrites(['confirm_warnings' => '1']);
    }

    public function test_fr_camp_line_without_canonical_proceeds_on_same_booking_type_target(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'jours-selectionnes' => (object) ['term_id' => 9760, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                    'single-days' => (object) ['term_id' => 9761, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                9760 => (object) ['term_id' => 9760, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                9761 => (object) ['term_id' => 9761, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([9760 => 9761]);

        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_booking-type' => 'jours-selectionnes',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'jours-selectionnes',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR same booking type',
                'Type de réservation' => 'Jours sélectionnés',
                'Jours sélectionnés' => 'Tuesday',
                'pa_booking-type' => 'jours-selectionnes',
                'attribute_pa_booking-type' => 'jours-selectionnes',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
    }

    public function test_move_proceeds_when_line_booking_type_unresolvable(): void {
        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'full-week' => (object) ['term_id' => 9730, 'slug' => 'full-week', 'name' => 'Full Week'],
                ],
            ],
            [9730 => (object) ['term_id' => 9730, 'slug' => 'full-week', 'name' => 'Full Week']]
        );
        $this->stubWpmlEnTerms([]);
        $this->installMoveWorld([
            'target_attrs' => [
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Unknown booking',
            ],
            'roster_row' => ['booking_type' => 'Unknown'],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
    }

    public function test_move_proceeds_when_destination_booking_type_unresolvable(): void {
        $this->registerTerms([], []);
        $this->stubWpmlEnTerms([]);
        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Resolvable line only',
                '_intersoccer_canonical_booking_type' => 'full-week',
                'pa_booking-type' => 'full-week',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
    }

    public function test_course_move_keeps_line_note_when_destination_parent_has_no_note(): void {
        $this->registerCourseMoveTerms();
        $shared_course_meta = [
            '_course_start_date' => '2027-01-10',
            '_end_date' => '2027-06-13',
            '_course_holiday_dates' => ['2027-02-14'],
        ];
        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            self::SOURCE_VAR => $shared_course_meta,
            self::TARGET_VAR => $shared_course_meta,
        ];
        $note_en = 'Join anytime – prices are pro-rated if you start late.';
        $GLOBALS['intersoccer_test_parent_product_attributes_by_variation'] = [
            self::SOURCE_VAR => ['Note' => $note_en],
            self::TARGET_VAR => [],
        ];
        $line_note = 'Inscription possible à tout moment – les tarifs sont calculés au prorata si vous…';

        $this->installCourseMoveWorld([
            'source_attrs' => [
                'attribute_pa_course-times' => '1115-1245',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_course-times' => '1115-1245',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Course',
                'Assigned Attendee' => 'Course note preserve',
                'Note' => $line_note,
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $this->assertSame($line_note, $intersoccer_test_wc_order_item_meta[self::ITEM_ID]['Note']);
    }

    public function test_course_move_writes_destination_en_note_when_parent_notes_differ(): void {
        $this->registerCourseMoveTerms();
        $shared_course_meta = [
            '_course_start_date' => '2027-01-10',
            '_end_date' => '2027-06-13',
            '_course_holiday_dates' => ['2027-02-14'],
        ];
        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            self::SOURCE_VAR => $shared_course_meta,
            self::TARGET_VAR => $shared_course_meta,
        ];
        $source_note = 'Source parent note for move QA.';
        $dest_note = 'Destination parent note replaces line.';
        $GLOBALS['intersoccer_test_parent_product_attributes_by_variation'] = [
            self::SOURCE_VAR => ['Note' => $source_note],
            self::TARGET_VAR => ['Note' => $dest_note],
        ];
        $line_note = 'Localized line note should not survive parent mismatch.';

        $this->installCourseMoveWorld([
            'source_attrs' => [
                'attribute_pa_course-times' => '1115-1245',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_course-times' => '1115-1245',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Course',
                'Assigned Attendee' => 'Course note replace',
                'Note' => $line_note,
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $this->assertSame($dest_note, $intersoccer_test_wc_order_item_meta[self::ITEM_ID]['Note']);
    }

    public function test_en_course_time_only_move_preserves_note_and_schedule_bytes(): void {
        $this->registerCourseMoveTerms();
        $shared_course_meta = [
            '_course_start_date' => '2027-01-10',
            '_end_date' => '2027-06-13',
            '_course_holiday_dates' => ['2027-02-14', '2027-03-28', '2027-04-04'],
        ];
        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            self::SOURCE_VAR => $shared_course_meta,
            self::TARGET_VAR => $shared_course_meta,
        ];
        $note_en = 'Join anytime – prices are pro-rated if you start late.';
        $GLOBALS['intersoccer_test_parent_product_attributes_by_variation'] = [
            self::SOURCE_VAR => ['Note' => $note_en],
            self::TARGET_VAR => ['Note' => $note_en],
        ];
        $start_en = 'January 10, 2027';
        $end_en = 'June 13, 2027';
        $holidays_en = 'February 14, 2027, March 28, 2027, April 4, 2027';

        $this->installCourseMoveWorld([
            'source_attrs' => [
                'attribute_pa_course-times' => '1115-1245',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_course-times' => '1400-1530',
                'attribute_pa_course-day' => 'saturday',
                'attribute_pa_booking-type' => 'full-term',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Course',
                'Assigned Attendee' => 'EN course time-only',
                'Start Date' => $start_en,
                'End Date' => $end_en,
                'Holidays' => $holidays_en,
                'Note' => $note_en,
                'Course Times' => '11:15 AM-12:45 PM',
                'pa_course-times' => '1115-1245',
                'attribute_pa_course-times' => '1115-1245',
                '_intersoccer_canonical_booking_type' => 'full-term',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($note_en, $meta['Note']);
        $this->assertSame($start_en, $meta['Start Date']);
        $this->assertSame($end_en, $meta['End Date']);
        $this->assertSame($holidays_en, $meta['Holidays']);
        $this->assertSame('1400-1530', $meta['pa_course-times'] ?? '');
    }

    public function test_fr_camp_same_week_different_variation_keeps_fr_schedule_label_bytes(): void {
        $schedule = [
            '_camp_start_date' => '2027-04-05',
            '_camp_end_date' => '2027-04-09',
            '_camp_week_index' => '2',
        ];
        \InterSoccerRRTestMeta::$data[self::TARGET_VAR] = $schedule;
        \InterSoccerRRTestMeta::$data[self::SOURCE_VAR] = $schedule;

        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'jours-selectionnes' => (object) ['term_id' => 9770, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                    'single-days' => (object) ['term_id' => 9771, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
                'pa_age-group' => [
                    '5-8y' => (object) ['term_id' => 9772, 'slug' => '5-8y', 'name' => '5-8y'],
                    '9-13y' => (object) ['term_id' => 9773, 'slug' => '9-13y', 'name' => '9-13y'],
                ],
            ],
            [
                9770 => (object) ['term_id' => 9770, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                9771 => (object) ['term_id' => 9771, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                9772 => (object) ['term_id' => 9772, 'slug' => '5-8y', 'name' => '5-8y'],
                9773 => (object) ['term_id' => 9773, 'slug' => '9-13y', 'name' => '9-13y'],
            ]
        );
        $this->stubWpmlEnTerms([9770 => 9771]);

        $fr_start = '2027-04-05';
        $fr_end = '2027-04-09';
        $item_meta = [
            'Activity Type' => 'Camp',
            'Assigned Attendee' => 'FR same week different slot',
            'Date de début du camp' => $fr_start,
            'Date de fin du camp' => $fr_end,
            '_camp_start_date' => $fr_start,
            '_camp_end_date' => $fr_end,
            '_camp_week_index' => '2',
        ];

        $this->installMoveWorld([
            'source_attrs' => [
                'attribute_pa_booking-type' => 'jours-selectionnes',
                'attribute_pa_age-group' => '5-8y',
                'attribute_pa_girls-only' => 'no',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'jours-selectionnes',
                'attribute_pa_age-group' => '9-13y',
                'attribute_pa_girls-only' => 'no',
            ],
            'item_meta' => $item_meta,
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame($fr_start, $meta['Date de début du camp']);
        $this->assertSame($fr_end, $meta['Date de fin du camp']);
        $this->assertSame('2', $meta['_camp_week_index']);
    }

    public function test_fr_camp_move_different_week_updates_fr_schedule_labels_from_destination(): void {
        \InterSoccerRRTestMeta::$data[self::TARGET_VAR] = [
            '_camp_start_date' => '2027-04-05',
            '_camp_end_date' => '2027-04-09',
            '_camp_week_index' => '2',
        ];
        \InterSoccerRRTestMeta::$data[self::SOURCE_VAR] = [
            '_camp_start_date' => '2027-02-15',
            '_camp_end_date' => '2027-02-19',
            '_camp_week_index' => '1',
        ];

        $this->registerTerms(
            [
                'pa_booking-type' => [
                    'jours-selectionnes' => (object) ['term_id' => 9780, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                    'single-days' => (object) ['term_id' => 9781, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
                ],
            ],
            [
                9780 => (object) ['term_id' => 9780, 'slug' => 'jours-selectionnes', 'name' => 'Jours sélectionnés'],
                9781 => (object) ['term_id' => 9781, 'slug' => 'single-days', 'name' => 'Single Day(s)'],
            ]
        );
        $this->stubWpmlEnTerms([9780 => 9781]);

        $this->installMoveWorld([
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'FR camp week change',
                'Date de début du camp' => '2027-02-15',
                'Date de fin du camp' => '2027-02-19',
                '_camp_start_date' => '2027-02-15',
                '_camp_end_date' => '2027-02-19',
                '_camp_week_index' => '1',
            ],
            'target_attrs' => [
                'attribute_pa_booking-type' => 'jours-selectionnes',
                'attribute_pa_girls-only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('2027-04-05', $meta['Date de début du camp']);
        $this->assertSame('2027-04-09', $meta['Date de fin du camp']);
        $this->assertSame('2', $meta['_camp_week_index']);
    }
}
