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
            $GLOBALS['intersoccer_test_roster_row_filter_map']
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
    private function invokeMoveAjax(): array {
        $_POST = [
            'action' => 'intersoccer_move_players',
            'nonce' => wp_create_nonce('intersoccer_move_nonce'),
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_ID],
        ];
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
                'attribute_pa_booking-type' => 'semaine-complete',
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
                'attribute_pa_booking-type' => 'ganze-woche',
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

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['_intersoccer_canonical_booking_type']);
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

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['_intersoccer_canonical_booking_type']);
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

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('geneva', $meta['_intersoccer_canonical_canton']);
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

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['_intersoccer_canonical_booking_type']);
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

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('single-days', $meta['_intersoccer_canonical_booking_type']);
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
}
