<?php
/**
 * Roster move writes destination catalog data on the order in the order's WPML language;
 * roster rows remain English.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersOrderLanguageMoveTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private static bool $rosterRowFilterRegistered = false;

    private static bool $adminWpmlStubsRegistered = false;

    private const ORDER_ID = 97301;
    private const ITEM_ID = 97311;
    private const EN_PARENT = 97300;
    private const FR_PARENT = 97302;
    private const DE_PARENT = 97303;
    private const SOURCE_VAR = 97310;
    private const EN_TARGET = 97320;
    private const FR_TARGET = 97321;
    private const DE_TARGET = 97322;

    protected function setUp(): void {
        parent::setUp();
        $this->resetMovePlayersTestGlobals();
        $this->registerAdminWpmlLanguageStubs();
        unset($GLOBALS['intersoccer_test_current_user_caps']);
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
            $GLOBALS['intersoccer_test_wpml_object_map'],
            $GLOBALS['intersoccer_test_terms_by_id'],
            $GLOBALS['intersoccer_test_terms_by_slug'],
            $GLOBALS['intersoccer_test_terms_by_name'],
            $GLOBALS['intersoccer_test_roster_store'],
            $GLOBALS['intersoccer_test_wc_order_item_meta'],
            $GLOBALS['intersoccer_test_wc_order_item_data'],
            $GLOBALS['intersoccer_test_roster_row_filter_map'],
            $GLOBALS['intersoccer_test_roster_move_log'],
            $GLOBALS['intersoccer_test_get_english_product_name_callback'],
            $GLOBALS['intersoccer_test_wpml_current_language']
        );
        $this->resetMovePlayersTestGlobals();
        parent::tearDown();
    }

    private function registerAdminWpmlLanguageStubs(): void {
        if (self::$adminWpmlStubsRegistered) {
            return;
        }

        add_filter(
            'wpml_current_language',
            static function ($lang) {
                if (is_string($lang) && $lang !== '') {
                    return $lang;
                }

                return (string) ($GLOBALS['intersoccer_test_wpml_current_language'] ?? 'en');
            },
            0,
            1
        );

        add_action(
            'wpml_switch_language',
            static function ($language_code) {
                $GLOBALS['intersoccer_test_wpml_current_language'] = (string) $language_code;
            },
            0,
            1
        );

        self::$adminWpmlStubsRegistered = true;
    }

    private function registerCatalogTerms(): void {
        $GLOBALS['intersoccer_test_terms_by_slug'] = [
            'pa_canton-region' => [
                'geneva' => (object) ['term_id' => 7400, 'slug' => 'geneva', 'name' => 'Geneva', 'wpml_lang' => 'en'],
                'geneve' => (object) ['term_id' => 7401, 'slug' => 'geneve', 'name' => 'Genève', 'wpml_lang' => 'fr'],
                'vaud' => (object) ['term_id' => 7402, 'slug' => 'vaud', 'name' => 'Vaud', 'wpml_lang' => 'fr'],
                'zurich' => (object) ['term_id' => 7403, 'slug' => 'zurich', 'name' => 'Zürich', 'wpml_lang' => 'de'],
                'genf' => (object) ['term_id' => 7405, 'slug' => 'genf', 'name' => 'Genf', 'wpml_lang' => 'de'],
            ],
            'pa_city' => [
                'geneva' => (object) ['term_id' => 7500, 'slug' => 'geneva', 'name' => 'Geneva', 'wpml_lang' => 'en'],
                'geneve-ville' => (object) ['term_id' => 7501, 'slug' => 'geneve-ville', 'name' => 'Genève', 'wpml_lang' => 'fr'],
                'genf' => (object) ['term_id' => 7502, 'slug' => 'genf', 'name' => 'Genf', 'wpml_lang' => 'de'],
                'zuerich' => (object) ['term_id' => 7503, 'slug' => 'zuerich', 'name' => 'Zürich', 'wpml_lang' => 'de'],
                'lausanne-ville' => (object) ['term_id' => 7504, 'slug' => 'lausanne-ville', 'name' => 'Lausanne', 'wpml_lang' => 'fr'],
            ],
            'pa_camp-terms' => [
                'week-one' => (object) ['term_id' => 7600, 'slug' => 'week-one', 'name' => 'Week One', 'wpml_lang' => 'en'],
                'semaine-un' => (object) ['term_id' => 7601, 'slug' => 'semaine-un', 'name' => 'Semaine un', 'wpml_lang' => 'fr'],
                'woche-eins' => (object) ['term_id' => 7602, 'slug' => 'woche-eins', 'name' => 'Woche eins', 'wpml_lang' => 'de'],
                'semaine-deux' => (object) ['term_id' => 7603, 'slug' => 'semaine-deux', 'name' => 'Semaine deux', 'wpml_lang' => 'fr'],
                'woche-zwei' => (object) ['term_id' => 7604, 'slug' => 'woche-zwei', 'name' => 'Woche zwei', 'wpml_lang' => 'de'],
            ],
            'pa_age-group' => [
                '5-13y-full-day' => (object) ['term_id' => 7200, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)', 'wpml_lang' => 'en'],
                '5-13-ans-journee-complete' => (object) ['term_id' => 7201, 'slug' => '5-13-ans-journee-complete', 'name' => '5-13 ans (journée complète)', 'wpml_lang' => 'fr'],
                '5-13-jahre-ganzer-tag' => (object) ['term_id' => 7202, 'slug' => '5-13-jahre-ganzer-tag', 'name' => '5-13 Jahre (ganzer Tag)', 'wpml_lang' => 'de'],
                '5-8-ans' => (object) ['term_id' => 7203, 'slug' => '5-8-ans', 'name' => '5-8 ans', 'wpml_lang' => 'fr'],
                '5-8-jahre' => (object) ['term_id' => 7204, 'slug' => '5-8-jahre', 'name' => '5-8 Jahre', 'wpml_lang' => 'de'],
            ],
            'pa_intersoccer-venues' => [
                'stade-de-vessy' => (object) ['term_id' => 7100, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy', 'wpml_lang' => 'en'],
                'geneve-stade-vessy' => (object) ['term_id' => 7101, 'slug' => 'geneve-stade-vessy', 'name' => 'Genève – Stade de Vessy', 'wpml_lang' => 'fr'],
                'genf-stade-de-vessy' => (object) ['term_id' => 7103, 'slug' => 'genf-stade-de-vessy', 'name' => 'Genf – Stade de Vessy', 'wpml_lang' => 'de'],
                'lausanne-centre' => (object) ['term_id' => 7102, 'slug' => 'lausanne-centre', 'name' => 'Lausanne Centre', 'wpml_lang' => 'fr'],
            ],
            'pa_booking-type' => [
                'full-week' => (object) ['term_id' => 7300, 'slug' => 'full-week', 'name' => 'Full Week', 'wpml_lang' => 'en'],
                'semaine-complete' => (object) ['term_id' => 7301, 'slug' => 'semaine-complete', 'name' => 'Semaine complète', 'wpml_lang' => 'fr'],
                'ganze-woche' => (object) ['term_id' => 7302, 'slug' => 'ganze-woche', 'name' => 'Ganze Woche', 'wpml_lang' => 'de'],
            ],
        ];
        $by_id = [];
        foreach ($GLOBALS['intersoccer_test_terms_by_slug'] as $terms) {
            foreach ($terms as $term) {
                $by_id[(int) $term->term_id] = $term;
            }
        }
        $GLOBALS['intersoccer_test_terms_by_id'] = $by_id;
    }

    private function stubWpml(): void {
        $GLOBALS['intersoccer_test_wpml_term_map'] = [
            7101 => 7100,
            7102 => 7100,
            7201 => 7200,
            7202 => 7200,
            7203 => 7200,
            7204 => 7200,
            7301 => 7300,
            7302 => 7300,
            7401 => 7400,
            7402 => 7400,
            7403 => 7400,
            7501 => 7500,
            7502 => 7500,
            7503 => 7500,
            7504 => 7500,
            7601 => 7600,
            7602 => 7600,
            7603 => 7600,
            7604 => 7600,
        ];
        $GLOBALS['intersoccer_test_wpml_object_map'] = [
            'product_variation' => [
                'fr' => [self::EN_TARGET => self::FR_TARGET],
                'de' => [self::EN_TARGET => self::DE_TARGET],
            ],
            'product' => [
                'fr' => [self::EN_PARENT => self::FR_PARENT],
                'de' => [self::EN_PARENT => self::DE_PARENT],
            ],
        ];
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id') {
                $id = (int) $value;
                $element_type = isset($args[0]) ? (string) $args[0] : '';
                $lang = isset($args[2]) ? (string) $args[2] : '';
                if ($element_type !== '' && str_starts_with($element_type, 'pa_') && $lang !== '' && $lang !== 'en') {
                    if ($lang === 'fr') {
                        foreach ($GLOBALS['intersoccer_test_wpml_term_map'] ?? [] as $translated_id => $original_id) {
                            if ((int) $original_id === $id) {
                                return (int) $translated_id;
                            }
                        }
                    }

                    return $id;
                }
                $map = $GLOBALS['intersoccer_test_wpml_object_map'][$element_type][$lang] ?? [];

                return $map[$id] ?? $id;
            }
            if ($hook === 'wpml_object_id' && count($args) >= 4) {
                // WPML signature: ($id, $type, $return_original, $lang)
            }

            return $value;
        };
    }

    /**
     * @param array<string,mixed> $options
     */
    private function installMoveWorld(array $options = []): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_post_status_map;
        global $intersoccer_test_wc_get_product_callback;

        $order_lang = (string) ($options['order_lang'] ?? 'en');
        $skip_wpml_meta = !empty($options['omit_wpml_language_meta']);
        $girls_only_fr_slug = isset($options['girls_only_fr_slug']) ? (string) $options['girls_only_fr_slug'] : '';

        $en_target_attrs = [
            'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
            'attribute_pa_age-group'          => '5-13y-full-day',
            'attribute_pa_booking-type'       => 'full-week',
            'attribute_pa_canton-region'      => 'geneva',
            'attribute_pa_city'               => 'geneva',
            'attribute_pa_camp-terms'         => 'week-one',
            'attribute_pa_girls-only'         => $girls_only_fr_slug !== '' ? 'yes' : 'no',
        ];
        $fr_target_attrs = [
            'attribute_pa_intersoccer-venues' => 'geneve-stade-vessy',
            'attribute_pa_age-group'          => '5-13-ans-journee-complete',
            'attribute_pa_booking-type'       => 'semaine-complete',
            'attribute_pa_canton-region'      => 'geneve',
            'attribute_pa_city'               => 'geneve-ville',
            'attribute_pa_camp-terms'         => 'semaine-un',
            'attribute_pa_girls-only'         => $girls_only_fr_slug !== '' ? $girls_only_fr_slug : 'no',
        ];
        $de_target_attrs = (array) ($options['de_target_attrs'] ?? [
            'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
            'attribute_pa_age-group'          => '5-13-jahre-ganzer-tag',
            'attribute_pa_booking-type'       => 'ganze-woche',
            'attribute_pa_canton-region'      => 'geneva',
            'attribute_pa_city'               => 'genf',
            'attribute_pa_camp-terms'         => 'woche-eins',
            'attribute_pa_girls-only'         => 'no',
        ]);

        $item_meta = (array) ($options['item_meta'] ?? [
            'Activity Type' => 'Camp',
            'Assigned Attendee' => 'Order language move QA',
            'attribute_pa_booking-type' => 'full-week',
            'pa_booking-type' => 'full-week',
        ]);

        $GLOBALS['intersoccer_test_roster_row_filter_map'] = [self::ITEM_ID => true];
        $GLOBALS['intersoccer_test_roster_store'] = [
            self::ITEM_ID => [
                'id' => 973,
                'order_item_id' => self::ITEM_ID,
                'order_id' => self::ORDER_ID,
                'variation_id' => self::SOURCE_VAR,
                'product_id' => self::EN_PARENT,
                'event_signature' => 'order-lang-move-sig',
                'booking_type' => 'Full Week',
                'venue' => 'Old venue',
                'activity_type' => 'Camp',
                'girls_only' => 0,
                'product_name' => 'English Camp Title',
            ],
        ];

        $GLOBALS['intersoccer_test_wc_stock_qty'] = [
            self::SOURCE_VAR => 2,
            self::EN_TARGET => 3,
            self::FR_TARGET => 3,
            self::DE_TARGET => 3,
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
            self::EN_TARGET => 'publish',
            self::FR_TARGET => 'publish',
            self::DE_TARGET => 'publish',
            self::EN_PARENT => 'publish',
            self::FR_PARENT => 'publish',
            self::DE_PARENT => 'publish',
        ];

        $intersoccer_test_wc_order_item_meta = [self::ITEM_ID => $item_meta];
        $intersoccer_test_wc_order_item_data[self::ITEM_ID] = [
            'product_id' => self::EN_PARENT,
            'variation_id' => self::SOURCE_VAR,
            'subtotal' => 100.00,
            'total' => 100.00,
            'name' => 'Camp line',
        ];

        $make_variation = static function (int $vid, array $attrs, int $parent_id, string $name) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn($parent_id);
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
            $v->shouldReceive('get_name')->andReturn($name);
            $v->shouldReceive('get_price')->andReturn('100');
            $v->shouldReceive('is_purchasable')->andReturn(true);
            $v->shouldReceive('is_type')->with('variation')->andReturn(true);
            $v->shouldReceive('get_stock_status')->andReturn('instock');
            $v->shouldReceive('set_stock_quantity')->never();
            $v->shouldReceive('get_meta')->andReturn('');

            return $v;
        };

        $make_parent = static function (int $pid, string $name) {
            $parent = Mockery::mock('WC_Product');
            $parent->shouldReceive('get_id')->andReturn($pid);
            $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
            $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
            $parent->shouldReceive('get_name')->andReturn($name);
            $parent->shouldReceive('get_attribute')->andReturn('');
            $parent->shouldReceive('get_attributes')->andReturn([]);

            return $parent;
        };

        $intersoccer_test_wc_get_product_callback = static function ($id) use (
            $make_variation,
            $make_parent,
            $en_target_attrs,
            $fr_target_attrs,
            $de_target_attrs
        ) {
            $id = (int) $id;
            switch ($id) {
                case self::EN_PARENT:
                    return $make_parent($id, 'English Camp Title');
                case self::FR_PARENT:
                    return $make_parent($id, 'Camp titre FR');
                case self::DE_PARENT:
                    return $make_parent($id, 'Camp Titel DE');
                case self::SOURCE_VAR:
                    return $make_variation($id, [
                        'attribute_pa_booking-type' => 'full-week',
                        'attribute_pa_girls-only' => 'no',
                    ], self::EN_PARENT, 'Source variation EN');
                case self::EN_TARGET:
                    return $make_variation(
                        $id,
                        $en_target_attrs,
                        self::EN_PARENT,
                        'Destination EN line name'
                    );
                case self::FR_TARGET:
                    return $make_variation(
                        $id,
                        $fr_target_attrs,
                        self::FR_PARENT,
                        'Ligne destination FR'
                    );
                case self::DE_TARGET:
                    return $make_variation(
                        $id,
                        $de_target_attrs,
                        self::DE_PARENT,
                        'Zielzeile DE'
                    );
            }

            return false;
        };

        $item = new \WC_Order_Item_Product(self::ITEM_ID);
        $order = new \WC_Order(self::ORDER_ID);
        $GLOBALS['intersoccer_test_current_user'] = (object) ['ID' => 1, 'roles' => ['administrator']];
        unset($GLOBALS['intersoccer_test_current_user_caps']);
        if (!$skip_wpml_meta) {
            $order->update_meta_data('wpml_language', $order_lang);
        }
        $order->set_items([self::ITEM_ID => $item]);
        $intersoccer_test_wc_get_order_callback = static function ($oid) use ($order) {
            return (int) $oid === self::ORDER_ID ? $order : false;
        };
        $GLOBALS['intersoccer_test_wc_get_order_id_by_order_item_id_callback'] = static function ($item_id) {
            return (int) $item_id === self::ITEM_ID ? self::ORDER_ID : 0;
        };

        global $wpdb;
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
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) {
            if (is_string($sql) && strpos($sql, 'woocommerce_order_items') !== false) {
                return (string) self::ORDER_ID;
            }

            return null;
        });
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

            return $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID] ?? null;
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

            return 0;
        });

        $GLOBALS['intersoccer_test_get_english_product_name_callback'] = static function ($name, $product_id) {
            return 'English Camp Title';
        };
    }

    private function invokeMoveAjax(array $options = []): array {
        $_POST = [
            'action' => 'intersoccer_move_players',
            'nonce' => wp_create_nonce('intersoccer_move_nonce'),
            'target_variation_id' => self::EN_TARGET,
            'order_item_ids' => [self::ITEM_ID],
            'confirm_warnings' => '1',
        ];
        if (!empty($options['allow_cross_gender'])) {
            $_POST['allow_cross_gender'] = '1';
        }
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

    public function test_roster_move_order_language_reads_wpml_meta_and_default(): void {
        $order = new \WC_Order(1);
        $order->update_meta_data('wpml_language', 'fr');
        $this->assertSame('fr', intersoccer_roster_move_order_language($order));

        $order2 = new \WC_Order(2);
        $this->assertSame('en', intersoccer_roster_move_order_language($order2));

        $order3 = new \WC_Order(3);
        $prev = $GLOBALS['intersoccer_test_apply_filters_callback'] ?? null;
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value) {
            if ($hook === 'wpml_default_language') {
                return 'de';
            }

            return $value;
        };
        $this->assertSame('de', intersoccer_roster_move_order_language($order3));
        if ($prev !== null) {
            $GLOBALS['intersoccer_test_apply_filters_callback'] = $prev;
        } else {
            unset($GLOBALS['intersoccer_test_apply_filters_callback']);
        }
    }

    public function test_fr_order_move_writes_fr_catalog_ids_labels_and_en_roster(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld([
            'order_lang' => 'fr',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Order language move QA',
                'attribute_pa_booking-type' => 'full-week',
                'pa_booking-type' => 'full-week',
                'Type de réservation' => 'Semaine complète',
                '_intersoccer_canonical_booking_type' => 'single-days',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
        $this->assertEmpty($response['data']['warnings'] ?? []);

        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data, $intersoccer_test_roster_store;
        $data = $intersoccer_test_wc_order_item_data[self::ITEM_ID];
        $this->assertSame(self::FR_PARENT, (int) $data['product_id']);
        $this->assertSame(self::FR_TARGET, (int) $data['variation_id']);
        $this->assertSame('Ligne destination FR', $data['name']);

        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('semaine-complete', $meta['pa_booking-type'] ?? $meta['attribute_pa_booking-type'] ?? '');
        $this->assertSame('Semaine complète', $meta['Type de réservation'] ?? '');
        $this->assertSame('full-week', $meta['_intersoccer_canonical_booking_type'] ?? '');

        $row = $intersoccer_test_roster_store[self::ITEM_ID];
        $this->assertSame(self::EN_TARGET, (int) $row['variation_id']);
        $this->assertSame(self::EN_PARENT, (int) $row['product_id']);
        $this->assertSame('English Camp Title', $row['product_name'] ?? '');
        $this->assertSame('Stade de Vessy', $row['venue'] ?? '');
        $this->assertSame('5-13y (Full Day)', $row['age_group'] ?? '');
    }

    public function test_de_order_move_writes_de_catalog_and_en_roster(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld(['order_lang' => 'de']);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data, $intersoccer_test_roster_store;
        $data = $intersoccer_test_wc_order_item_data[self::ITEM_ID];
        $this->assertSame(self::DE_PARENT, (int) $data['product_id']);
        $this->assertSame(self::DE_TARGET, (int) $data['variation_id']);
        $this->assertSame('Zielzeile DE', $data['name']);

        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('ganze-woche', $meta['pa_booking-type'] ?? '');

        $row = $intersoccer_test_roster_store[self::ITEM_ID];
        $this->assertSame(self::EN_TARGET, (int) $row['variation_id']);
        $this->assertSame('English Camp Title', $row['product_name'] ?? '');
    }

    public function test_en_order_move_writes_en_catalog(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld(['order_lang' => 'en']);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_data;
        $data = $intersoccer_test_wc_order_item_data[self::ITEM_ID];
        $this->assertSame(self::EN_PARENT, (int) $data['product_id']);
        $this->assertSame(self::EN_TARGET, (int) $data['variation_id']);
        $this->assertSame('Destination EN line name', $data['name']);
    }

    public function test_en_order_move_does_not_add_pa_keys_absent_from_line(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld([
            'order_lang' => 'en',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Order language move QA',
                'attribute_pa_booking-type' => 'full-week',
                'pa_booking-type' => 'full-week',
                'Booking Type' => 'Full Week',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertArrayHasKey('pa_booking-type', $meta);
        $this->assertArrayNotHasKey('pa_intersoccer-venues', $meta);
        $this->assertArrayNotHasKey('attribute_pa_intersoccer-venues', $meta);
        $this->assertArrayNotHasKey('pa_age-group', $meta);
        $this->assertArrayNotHasKey('pa_canton-region', $meta);
    }

    public function test_admin_fr_language_moves_fr_and_en_orders_with_en_roster(): void {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld([
            'order_lang' => 'fr',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Order language move QA',
                'attribute_pa_booking-type' => 'full-week',
                'pa_booking-type' => 'full-week',
                'Type de réservation' => 'Semaine complète',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_data, $intersoccer_test_roster_store;
        $fr_data = $intersoccer_test_wc_order_item_data[self::ITEM_ID];
        $this->assertSame(self::FR_TARGET, (int) $fr_data['variation_id']);
        $this->assertSame('Ligne destination FR', $fr_data['name']);
        $fr_row = $intersoccer_test_roster_store[self::ITEM_ID];
        $this->assertSame('English Camp Title', $fr_row['product_name'] ?? '');
        $this->assertSame('Stade de Vessy', $fr_row['venue'] ?? '');
        $this->assertSame('5-13y (Full Day)', $fr_row['age_group'] ?? '');

        $this->resetMovePlayersTestGlobals();
        $this->registerAdminWpmlLanguageStubs();
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_get_product_type_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'camp';
        };
        $intersoccer_test_get_product_type_callback = static function () {
            return 'camp';
        };

        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld(['order_lang' => 'en']);

        $response_en = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response_en['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_data, $intersoccer_test_wc_order_item_meta;
        $en_data = $intersoccer_test_wc_order_item_data[self::ITEM_ID];
        $this->assertSame(self::EN_TARGET, (int) $en_data['variation_id']);
        $this->assertSame('Destination EN line name', $en_data['name']);
        $en_meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertArrayNotHasKey('pa_intersoccer-venues', $en_meta);

        $en_row = $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID];
        $this->assertSame(self::EN_TARGET, (int) $en_row['variation_id']);
        $this->assertSame('English Camp Title', $en_row['product_name'] ?? '');

        $this->assertSame('fr', apply_filters('wpml_current_language', null));
        if (function_exists('wpml_get_current_language')) {
            $this->assertSame('fr', wpml_get_current_language());
        }
    }

    public function test_missing_twin_falls_back_to_en_catalog_and_logs(): void {
        $this->registerCatalogTerms();
        $GLOBALS['intersoccer_test_wpml_term_map'] = [];
        $GLOBALS['intersoccer_test_wpml_object_map'] = [
            'product_variation' => ['fr' => []],
            'product' => ['fr' => []],
        ];
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id') {
                return $value;
            }

            return $value;
        };
        $GLOBALS['intersoccer_test_roster_move_log'] = [];
        add_action('intersoccer_roster_move_log', static function ($message) {
            $GLOBALS['intersoccer_test_roster_move_log'][] = $message;
        }, 10, 1);
        $this->installMoveWorld(['order_lang' => 'fr']);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_data;
        $data = $intersoccer_test_wc_order_item_data[self::ITEM_ID];
        $this->assertSame(self::EN_PARENT, (int) $data['product_id']);
        $this->assertSame(self::EN_TARGET, (int) $data['variation_id']);

        $logged = implode("\n", (array) ($GLOBALS['intersoccer_test_roster_move_log'] ?? []));
        $this->assertStringContainsString('no WPML catalog twin', $logged);

        $warnings = $response['data']['warnings'] ?? [];
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString((string) self::ITEM_ID, implode("\n", $warnings));
        $this->assertStringContainsString('no fr translation', strtolower(implode("\n", $warnings)));
    }

    public function test_fr_order_move_writes_localized_girls_only_slug_on_line_and_en_canonical_roster(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld([
            'order_lang' => 'fr',
            'girls_only_fr_slug' => 'filles-uniquement',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'Order language move QA',
                'attribute_pa_booking-type' => 'full-week',
                'pa_booking-type' => 'full-week',
                'attribute_pa_girls-only' => 'no',
                'pa_girls-only' => 'no',
                'Type de réservation' => 'Semaine complète',
                '_intersoccer_canonical_girls_only' => 'no',
            ],
        ]);

        $response = $this->invokeMoveAjax(['allow_cross_gender' => true]);
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));
        $this->assertEmpty($response['data']['warnings'] ?? []);

        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_roster_store;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('filles-uniquement', $meta['pa_girls-only'] ?? '');
        $this->assertSame('1', $meta['_intersoccer_canonical_girls_only'] ?? '');

        $row = $intersoccer_test_roster_store[self::ITEM_ID];
        $this->assertSame(1, (int) ($row['girls_only'] ?? 0));
    }

    // Regression: RR #175 — FR order-line course times: compact slugs become HHhMM; FR term names stay as stored.
    public function test_fr_order_line_build_localizes_course_times_for_order_language(): void {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $GLOBALS['intersoccer_test_terms_by_slug']['pa_course-times'] = [
            '1115-1245' => (object) ['term_id' => 97350, 'slug' => '1115-1245', 'name' => '11:15-12:45'],
            '1400-1530' => (object) ['term_id' => 97351, 'slug' => '1400-1530', 'name' => '1400-1530'],
        ];
        $GLOBALS['intersoccer_test_terms_by_id'] = [
            97350 => $GLOBALS['intersoccer_test_terms_by_slug']['pa_course-times']['1115-1245'],
            97351 => $GLOBALS['intersoccer_test_terms_by_slug']['pa_course-times']['1400-1530'],
        ];

        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_get_product_type_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };

        $variation = Mockery::mock(\WC_Product_Variation::class);
        $variation->shouldReceive('get_id')->andReturn(self::FR_TARGET);
        $variation->shouldReceive('get_parent_id')->andReturn(self::FR_PARENT);
        $variation->shouldReceive('get_name')->andReturn('Ligne cours FR');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_course-times' => '1400-1530',
            'attribute_pa_course-day' => 'saturday',
            'attribute_pa_booking-type' => 'full-term',
            'attribute_pa_girls-only' => 'no',
        ]);
        $variation->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            $map = [
                'pa_course-times' => '1400-1530',
                'pa_course-day' => 'saturday',
                'pa_booking-type' => 'full-term',
                'pa_girls-only' => 'no',
            ];

            return $map[$name] ?? '';
        });
        $variation->shouldReceive('get_attributes')->andReturn([]);

        $build = static function () use ($variation) {
            return intersoccer_build_migration_meta_updates($variation, true);
        };
        $payload = function_exists('intersoccer_with_wpml_language')
            ? intersoccer_with_wpml_language('fr', $build)
            : $build();

        $this->assertSame('14h00-15h30', $payload['human_meta']['course_times'] ?? '');
        $this->assertSame('1400-1530', $payload['slug_meta']['pa_course-times'] ?? '');
    }

    // Regression: RR #175 round 4 — order-line pa_* facets stay in order language; roster stays English.
    public function test_rr175_r4_fr_order_move_writes_order_language_pa_facets_and_en_roster(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld([
            'order_lang' => 'fr',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'RR175 R4 FR facets',
                "Groupe d'âge" => '5-8 ans',
                'Lieux InterSoccer' => 'Lausanne Centre',
                'Ville' => 'Lausanne',
                'Canton / région' => 'Vaud',
                'Semaine de camp' => 'Semaine deux',
                'attribute_pa_age-group' => '5-8-ans',
                'pa_age-group' => '5-8-ans',
                'attribute_pa_intersoccer-venues' => 'lausanne-centre',
                'pa_intersoccer-venues' => 'lausanne-centre',
                'attribute_pa_city' => 'lausanne-ville',
                'pa_city' => 'lausanne-ville',
                'attribute_pa_canton-region' => 'vaud',
                'pa_canton-region' => 'vaud',
                'attribute_pa_camp-terms' => 'semaine-deux',
                'pa_camp-terms' => 'semaine-deux',
                'attribute_pa_booking-type' => 'semaine-complete',
                'pa_booking-type' => 'semaine-complete',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_roster_store;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('5-13 ans (journée complète)', $meta["Groupe d'âge"] ?? '');
        $this->assertNotSame('5-8-ans', $meta['pa_age-group'] ?? '');
        $this->assertSame('5-13-ans-journee-complete', $meta['pa_age-group'] ?? '');
        $this->assertSame('Genève – Stade de Vessy', $meta['Lieux InterSoccer'] ?? '');
        $this->assertNotSame('lausanne-centre', $meta['pa_intersoccer-venues'] ?? '');
        $this->assertSame('geneve-stade-vessy', $meta['pa_intersoccer-venues'] ?? '');
        $this->assertSame('Genève', $meta['Ville'] ?? '');
        $this->assertNotSame('lausanne-ville', $meta['pa_city'] ?? '');
        $this->assertSame('geneve-ville', $meta['pa_city'] ?? '');
        $this->assertSame('Genève', $meta['Canton / région'] ?? '');
        $this->assertNotSame('vaud', $meta['pa_canton-region'] ?? '');
        $this->assertSame('geneve', $meta['pa_canton-region'] ?? '');
        $this->assertSame('Semaine un', $meta['Semaine de camp'] ?? '');
        $this->assertNotSame('semaine-deux', $meta['pa_camp-terms'] ?? '');
        $this->assertSame('semaine-un', $meta['pa_camp-terms'] ?? '');

        $row = $intersoccer_test_roster_store[self::ITEM_ID];
        $this->assertSame('5-13y (Full Day)', $row['age_group'] ?? '');
        $this->assertSame('Stade de Vessy', $row['venue'] ?? '');
        $this->assertSame('Geneva', $row['city'] ?? '');
        $this->assertSame('Geneva', $row['canton_region'] ?? '');
        $this->assertSame('Week One', $row['camp_terms'] ?? '');
    }

    public function test_rr175_r4_de_order_move_writes_order_language_pa_facets_and_en_roster(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld([
            'order_lang' => 'de',
            // EN catalog slugs on the DE variation — normalize/coerce leave English until apply runs.
            'de_target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
                'attribute_pa_age-group'          => '5-13y-full-day',
                'attribute_pa_booking-type'       => 'ganze-woche',
                'attribute_pa_canton-region'      => 'geneva',
                'attribute_pa_city'               => 'geneva',
                'attribute_pa_camp-terms'         => 'week-one',
                'attribute_pa_girls-only'         => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'RR175 R4 DE facets',
                'Altersgruppe' => '5-8 Jahre',
                'InterSoccer-Standorte' => 'Lausanne Centre',
                'Stadt' => 'Zürich',
                'Kanton / Region' => 'Zürich',
                'Camp-Woche' => 'Woche zwei',
                'attribute_pa_age-group' => '5-8-jahre',
                'pa_age-group' => '5-8-jahre',
                'attribute_pa_intersoccer-venues' => 'lausanne-centre',
                'pa_intersoccer-venues' => 'lausanne-centre',
                'attribute_pa_city' => 'zuerich',
                'pa_city' => 'zuerich',
                'attribute_pa_canton-region' => 'zurich',
                'pa_canton-region' => 'zurich',
                'attribute_pa_camp-terms' => 'woche-zwei',
                'pa_camp-terms' => 'woche-zwei',
                'attribute_pa_booking-type' => 'ganze-woche',
                'pa_booking-type' => 'ganze-woche',
            ],
        ]);

        $previous_apply_filters = $GLOBALS['intersoccer_test_apply_filters_callback'];
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) use ($previous_apply_filters) {
            if ($hook === 'wpml_object_id') {
                $id = (int) $value;
                $element_type = isset($args[0]) ? (string) $args[0] : '';
                $lang = isset($args[2]) ? (string) $args[2] : '';
                if ($element_type !== '' && str_starts_with($element_type, 'pa_') && $lang === 'de') {
                    $de_term_twins = [
                        7100 => 7103,
                        7200 => 7202,
                        7400 => 7405,
                        7500 => 7502,
                        7600 => 7602,
                    ];
                    if (isset($de_term_twins[$id])) {
                        return $de_term_twins[$id];
                    }
                }
            }

            return $previous_apply_filters($hook, $value, ...$args);
        };

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_roster_store;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('5-13 Jahre (ganzer Tag)', $meta['Altersgruppe'] ?? '');
        $this->assertNotSame('5-8-jahre', $meta['pa_age-group'] ?? '');
        $this->assertSame('5-13y-full-day', $meta['pa_age-group'] ?? '');
        $this->assertSame('Genf – Stade de Vessy', $meta['InterSoccer-Standorte'] ?? '');
        $this->assertNotSame('lausanne-centre', $meta['pa_intersoccer-venues'] ?? '');
        $this->assertSame('stade-de-vessy', $meta['pa_intersoccer-venues'] ?? '');
        $this->assertSame('Genf', $meta['Stadt'] ?? '');
        $this->assertNotSame('zuerich', $meta['pa_city'] ?? '');
        $this->assertSame('geneva', $meta['pa_city'] ?? '');
        $this->assertSame('Genf', $meta['Kanton / Region'] ?? '');
        $this->assertNotSame('zurich', $meta['pa_canton-region'] ?? '');
        $this->assertSame('geneva', $meta['pa_canton-region'] ?? '');
        $this->assertSame('Woche eins', $meta['Camp-Woche'] ?? '');
        $this->assertNotSame('woche-zwei', $meta['pa_camp-terms'] ?? '');
        $this->assertSame('week-one', $meta['pa_camp-terms'] ?? '');

        $row = $intersoccer_test_roster_store[self::ITEM_ID];
        $this->assertSame('5-13y (Full Day)', $row['age_group'] ?? '');
        $this->assertSame('Geneva', $row['city'] ?? '');
        $this->assertSame('Week One', $row['camp_terms'] ?? '');
    }

    public function test_rr175_r4_en_order_move_keeps_en_pa_labels_unchanged(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld([
            'order_lang' => 'en',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'RR175 R4 EN',
                'Age Group' => '5-8 ans',
                'InterSoccer Venues' => 'Lausanne Centre',
                'attribute_pa_age-group' => '5-8-ans',
                'pa_age-group' => '5-8-ans',
                'attribute_pa_intersoccer-venues' => 'lausanne-centre',
                'pa_intersoccer-venues' => 'lausanne-centre',
                'attribute_pa_booking-type' => 'full-week',
                'pa_booking-type' => 'full-week',
            ],
        ]);

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('5-13y (Full Day)', $meta['Age Group'] ?? '');
        $this->assertNotSame('5-8-ans', $meta['pa_age-group'] ?? '');
        $this->assertSame('5-13y-full-day', $meta['pa_age-group'] ?? '');
        $this->assertNotSame('lausanne-centre', $meta['pa_intersoccer-venues'] ?? '');
        $this->assertSame('stade-de-vessy', $meta['pa_intersoccer-venues'] ?? '');
    }

    public function test_rr175_r6_fr_de_human_label_keys_get_new_value(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();

        $this->installMoveWorld([
            'order_lang' => 'fr',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'RR175 R6 FR labels',
                'Canton / région' => 'Vaud',
                'Semaine de camp' => 'Semaine deux',
                'attribute_pa_canton-region' => 'vaud',
                'pa_canton-region' => 'vaud',
                'attribute_pa_camp-terms' => 'semaine-deux',
                'pa_camp-terms' => 'semaine-deux',
                'attribute_pa_booking-type' => 'semaine-complete',
                'pa_booking-type' => 'semaine-complete',
            ],
        ]);
        $this->invokeMoveAjax();
        $meta = $GLOBALS['intersoccer_test_wc_order_item_meta'][self::ITEM_ID];
        $this->assertSame('Genève', $meta['Canton / région'] ?? '');
        $this->assertSame('Semaine un', $meta['Semaine de camp'] ?? '');

        $this->installMoveWorld([
            'order_lang' => 'de',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'RR175 R6 DE labels',
                'Kanton / Region' => 'Zürich',
                'Camp-Woche' => 'Woche zwei',
                'attribute_pa_canton-region' => 'zurich',
                'pa_canton-region' => 'zurich',
                'attribute_pa_camp-terms' => 'woche-zwei',
                'pa_camp-terms' => 'woche-zwei',
                'attribute_pa_booking-type' => 'ganze-woche',
                'pa_booking-type' => 'ganze-woche',
            ],
        ]);
        $this->invokeMoveAjax();
        $meta = $GLOBALS['intersoccer_test_wc_order_item_meta'][self::ITEM_ID];
        $this->assertSame('Geneva', $meta['Kanton / Region'] ?? '');
        $this->assertSame('Woche eins', $meta['Camp-Woche'] ?? '');
    }

    public function test_rr175_r6_fr_variation_with_en_slug_writes_fr_term_on_order_line(): void {
        $this->registerCatalogTerms();
        $this->stubWpml();

        $this->installMoveWorld([
            'order_lang' => 'fr',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'RR175 R6 EN slug on FR variation',
                'Canton / région' => 'Vaud',
                'attribute_pa_canton-region' => 'vaud',
                'pa_canton-region' => 'vaud',
                'attribute_pa_booking-type' => 'semaine-complete',
                'pa_booking-type' => 'semaine-complete',
            ],
        ]);

        global $intersoccer_test_wc_get_product_callback;
        $fr_target_attrs = [
            'attribute_pa_intersoccer-venues' => 'geneve-stade-vessy',
            'attribute_pa_age-group' => '5-13-ans-journee-complete',
            'attribute_pa_booking-type' => 'semaine-complete',
            'attribute_pa_canton-region' => 'geneva',
            'attribute_pa_city' => 'geneva',
            'attribute_pa_camp-terms' => 'semaine-un',
            'attribute_pa_girls-only' => 'no',
        ];
        $make_variation = static function (int $vid, array $attrs, int $parent_id, string $name) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn($parent_id);
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
            $v->shouldReceive('get_name')->andReturn($name);
            $v->shouldReceive('get_price')->andReturn('100');
            $v->shouldReceive('is_purchasable')->andReturn(true);
            $v->shouldReceive('is_type')->with('variation')->andReturn(true);
            $v->shouldReceive('get_stock_status')->andReturn('instock');
            $v->shouldReceive('set_stock_quantity')->never();
            $v->shouldReceive('get_meta')->andReturn('');

            return $v;
        };
        $make_parent = static function (int $pid, string $name) {
            $parent = Mockery::mock('WC_Product');
            $parent->shouldReceive('get_id')->andReturn($pid);
            $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
            $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
            $parent->shouldReceive('get_name')->andReturn($name);
            $parent->shouldReceive('get_attribute')->andReturn('');
            $parent->shouldReceive('get_attributes')->andReturn([]);

            return $parent;
        };
        $en_target_attrs = [
            'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
            'attribute_pa_age-group' => '5-13y-full-day',
            'attribute_pa_booking-type' => 'full-week',
            'attribute_pa_canton-region' => 'geneva',
            'attribute_pa_city' => 'geneva',
            'attribute_pa_camp-terms' => 'week-one',
            'attribute_pa_girls-only' => 'no',
        ];
        $de_target_attrs = [
            'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
            'attribute_pa_age-group' => '5-13-jahre-ganzer-tag',
            'attribute_pa_booking-type' => 'ganze-woche',
            'attribute_pa_canton-region' => 'geneva',
            'attribute_pa_city' => 'genf',
            'attribute_pa_camp-terms' => 'woche-eins',
            'attribute_pa_girls-only' => 'no',
        ];
        $intersoccer_test_wc_get_product_callback = static function ($id) use (
            $make_variation,
            $make_parent,
            $en_target_attrs,
            $fr_target_attrs,
            $de_target_attrs
        ) {
            $id = (int) $id;
            switch ($id) {
                case self::EN_PARENT:
                    return $make_parent($id, 'English Camp Title');
                case self::FR_PARENT:
                    return $make_parent($id, 'Camp titre FR');
                case self::DE_PARENT:
                    return $make_parent($id, 'Camp Titel DE');
                case self::SOURCE_VAR:
                    return $make_variation($id, [
                        'attribute_pa_booking-type' => 'full-week',
                        'attribute_pa_girls-only' => 'no',
                    ], self::EN_PARENT, 'Source variation EN');
                case self::EN_TARGET:
                    return $make_variation(
                        $id,
                        $en_target_attrs,
                        self::EN_PARENT,
                        'Destination EN line name'
                    );
                case self::FR_TARGET:
                    return $make_variation(
                        $id,
                        $fr_target_attrs,
                        self::FR_PARENT,
                        'Ligne destination FR'
                    );
                case self::DE_TARGET:
                    return $make_variation(
                        $id,
                        $de_target_attrs,
                        self::DE_PARENT,
                        'Zielzeile DE'
                    );
            }

            return false;
        };

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $meta = $GLOBALS['intersoccer_test_wc_order_item_meta'][self::ITEM_ID];
        $this->assertSame('Genève', $meta['Canton / région'] ?? '');
        $this->assertNotSame('vaud', $meta['pa_canton-region'] ?? '');
        $this->assertContains($meta['pa_canton-region'] ?? '', ['geneve', 'geneva'], 'FR order line canton slug should reflect destination catalog');
    }

    // Regression: RR #175 round 7 — WPML "all" during slug lookup under EN admin for DE order.
    public function test_rr175_r7_de_order_de_slugs_under_en_admin_writes_de_terms(): void {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'en';
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld([
            'order_lang' => 'de',
            'de_target_attrs' => [
                'attribute_pa_intersoccer-venues' => 'genf-stade-de-vessy',
                'attribute_pa_age-group'          => '5-13-jahre-ganzer-tag',
                'attribute_pa_booking-type'       => 'ganze-woche',
                'attribute_pa_canton-region'      => 'genf',
                'attribute_pa_city'               => 'genf',
                'attribute_pa_camp-terms'         => 'woche-eins',
                'attribute_pa_girls-only'         => 'no',
            ],
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'RR175 R7 DE slugs EN admin',
                'Altersgruppe' => '5-8 Jahre',
                'InterSoccer-Standorte' => 'Lausanne Centre',
                'Stadt' => 'Zürich',
                'Kanton / Region' => 'Zürich',
                'Camp-Woche' => 'Woche zwei',
                'attribute_pa_age-group' => '5-8-jahre',
                'pa_age-group' => '5-8-jahre',
                'attribute_pa_intersoccer-venues' => 'lausanne-centre',
                'pa_intersoccer-venues' => 'lausanne-centre',
                'attribute_pa_city' => 'zuerich',
                'pa_city' => 'zuerich',
                'attribute_pa_canton-region' => 'zurich',
                'pa_canton-region' => 'zurich',
                'attribute_pa_camp-terms' => 'woche-zwei',
                'pa_camp-terms' => 'woche-zwei',
                'attribute_pa_booking-type' => 'ganze-woche',
                'pa_booking-type' => 'ganze-woche',
            ],
        ]);

        $previous_apply_filters = $GLOBALS['intersoccer_test_apply_filters_callback'];
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) use ($previous_apply_filters) {
            if ($hook === 'wpml_object_id') {
                $id = (int) $value;
                $element_type = isset($args[0]) ? (string) $args[0] : '';
                $lang = isset($args[2]) ? (string) $args[2] : '';
                if ($element_type !== '' && str_starts_with($element_type, 'pa_') && $lang === 'de') {
                    $de_term_twins = [
                        7100 => 7103,
                        7103 => 7103,
                        7200 => 7202,
                        7202 => 7202,
                        7400 => 7405,
                        7405 => 7405,
                        7500 => 7502,
                        7502 => 7502,
                        7600 => 7602,
                        7602 => 7602,
                    ];
                    if (isset($de_term_twins[$id])) {
                        return $de_term_twins[$id];
                    }
                }
            }

            return $previous_apply_filters($hook, $value, ...$args);
        };

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        global $intersoccer_test_wc_order_item_meta;
        $meta = $intersoccer_test_wc_order_item_meta[self::ITEM_ID];
        $this->assertSame('5-13 Jahre (ganzer Tag)', $meta['Altersgruppe'] ?? '');
        $this->assertNotSame('5-8-jahre', $meta['pa_age-group'] ?? '');
        $this->assertSame('5-13-jahre-ganzer-tag', $meta['pa_age-group'] ?? '');
        $this->assertSame('Genf – Stade de Vessy', $meta['InterSoccer-Standorte'] ?? '');
        $this->assertNotSame('lausanne-centre', $meta['pa_intersoccer-venues'] ?? '');
        $this->assertSame('genf-stade-de-vessy', $meta['pa_intersoccer-venues'] ?? '');
        $this->assertSame('Genf', $meta['Stadt'] ?? '');
        $this->assertNotSame('zuerich', $meta['pa_city'] ?? '');
        $this->assertSame('genf', $meta['pa_city'] ?? '');
        $this->assertSame('Genf', $meta['Kanton / Region'] ?? '');
        $this->assertNotSame('zurich', $meta['pa_canton-region'] ?? '');
        $this->assertSame('genf', $meta['pa_canton-region'] ?? '');
    }

    // Regression: RR #175 round 7 — EN slugs on FR variation resolved while admin UI is FR.
    public function test_rr175_r7_fr_order_en_slugs_under_fr_admin_writes_fr_terms(): void {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $this->registerCatalogTerms();
        $this->stubWpml();
        $this->installMoveWorld([
            'order_lang' => 'fr',
            'item_meta' => [
                'Activity Type' => 'Camp',
                'Assigned Attendee' => 'RR175 R7 EN slugs FR admin',
                "Groupe d'âge" => '5-8 ans',
                'Lieux InterSoccer' => 'Lausanne Centre',
                'Ville' => 'Lausanne',
                'Canton / région' => 'Vaud',
                'Semaine de camp' => 'Semaine deux',
                'attribute_pa_age-group' => '5-8-ans',
                'pa_age-group' => '5-8-ans',
                'attribute_pa_intersoccer-venues' => 'lausanne-centre',
                'pa_intersoccer-venues' => 'lausanne-centre',
                'attribute_pa_city' => 'lausanne-ville',
                'pa_city' => 'lausanne-ville',
                'attribute_pa_canton-region' => 'vaud',
                'pa_canton-region' => 'vaud',
                'attribute_pa_camp-terms' => 'semaine-deux',
                'pa_camp-terms' => 'semaine-deux',
                'attribute_pa_booking-type' => 'semaine-complete',
                'pa_booking-type' => 'semaine-complete',
            ],
        ]);

        global $intersoccer_test_wc_get_product_callback;
        $fr_target_attrs = [
            'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
            'attribute_pa_age-group' => '5-13y-full-day',
            'attribute_pa_booking-type' => 'semaine-complete',
            'attribute_pa_canton-region' => 'geneva',
            'attribute_pa_city' => 'geneva',
            'attribute_pa_camp-terms' => 'week-one',
            'attribute_pa_girls-only' => 'no',
        ];
        $make_variation = static function (int $vid, array $attrs, int $parent_id, string $name) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn($parent_id);
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
            $v->shouldReceive('get_name')->andReturn($name);
            $v->shouldReceive('get_price')->andReturn('100');
            $v->shouldReceive('is_purchasable')->andReturn(true);
            $v->shouldReceive('is_type')->with('variation')->andReturn(true);
            $v->shouldReceive('get_stock_status')->andReturn('instock');
            $v->shouldReceive('set_stock_quantity')->never();
            $v->shouldReceive('get_meta')->andReturn('');

            return $v;
        };
        $make_parent = static function (int $pid, string $name) {
            $parent = Mockery::mock('WC_Product');
            $parent->shouldReceive('get_id')->andReturn($pid);
            $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
            $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
            $parent->shouldReceive('get_name')->andReturn($name);
            $parent->shouldReceive('get_attribute')->andReturn('');
            $parent->shouldReceive('get_attributes')->andReturn([]);

            return $parent;
        };
        $en_target_attrs = [
            'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
            'attribute_pa_age-group' => '5-13y-full-day',
            'attribute_pa_booking-type' => 'full-week',
            'attribute_pa_canton-region' => 'geneva',
            'attribute_pa_city' => 'geneva',
            'attribute_pa_camp-terms' => 'week-one',
            'attribute_pa_girls-only' => 'no',
        ];
        $de_target_attrs = [
            'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
            'attribute_pa_age-group' => '5-13-jahre-ganzer-tag',
            'attribute_pa_booking-type' => 'ganze-woche',
            'attribute_pa_canton-region' => 'geneva',
            'attribute_pa_city' => 'genf',
            'attribute_pa_camp-terms' => 'woche-eins',
            'attribute_pa_girls-only' => 'no',
        ];
        $intersoccer_test_wc_get_product_callback = static function ($id) use (
            $make_variation,
            $make_parent,
            $en_target_attrs,
            $fr_target_attrs,
            $de_target_attrs
        ) {
            $id = (int) $id;
            switch ($id) {
                case self::EN_PARENT:
                    return $make_parent($id, 'English Camp Title');
                case self::FR_PARENT:
                    return $make_parent($id, 'Camp titre FR');
                case self::DE_PARENT:
                    return $make_parent($id, 'Camp Titel DE');
                case self::SOURCE_VAR:
                    return $make_variation($id, [
                        'attribute_pa_booking-type' => 'semaine-complete',
                        'attribute_pa_girls-only' => 'no',
                    ], self::EN_PARENT, 'Source variation EN');
                case self::EN_TARGET:
                    return $make_variation(
                        $id,
                        $en_target_attrs,
                        self::EN_PARENT,
                        'Destination EN line name'
                    );
                case self::FR_TARGET:
                    return $make_variation(
                        $id,
                        $fr_target_attrs,
                        self::FR_PARENT,
                        'Ligne destination FR'
                    );
                case self::DE_TARGET:
                    return $make_variation(
                        $id,
                        $de_target_attrs,
                        self::DE_PARENT,
                        'Zielzeile DE'
                    );
            }

            return false;
        };

        $response = $this->invokeMoveAjax();
        $this->assertSame(1, (int) ($response['data']['moved_count'] ?? 0));

        $meta = $GLOBALS['intersoccer_test_wc_order_item_meta'][self::ITEM_ID];
        $this->assertSame('5-13 ans (journée complète)', $meta["Groupe d'âge"] ?? '');
        $this->assertNotSame('5-8-ans', $meta['pa_age-group'] ?? '');
        $this->assertSame('5-13-ans-journee-complete', $meta['pa_age-group'] ?? '');
        $this->assertSame('Genève – Stade de Vessy', $meta['Lieux InterSoccer'] ?? '');
        $this->assertNotSame('lausanne-centre', $meta['pa_intersoccer-venues'] ?? '');
        $this->assertSame('geneve-stade-vessy', $meta['pa_intersoccer-venues'] ?? '');
        $this->assertSame('Genève', $meta['Ville'] ?? '');
        $this->assertNotSame('lausanne-ville', $meta['pa_city'] ?? '');
        $this->assertSame('geneve-ville', $meta['pa_city'] ?? '');
        $this->assertSame('Genève', $meta['Canton / région'] ?? '');
        $this->assertNotSame('vaud', $meta['pa_canton-region'] ?? '');
        $this->assertSame('geneve', $meta['pa_canton-region'] ?? '');
        $this->assertSame('Semaine un', $meta['Semaine de camp'] ?? '');
        $this->assertNotSame('semaine-deux', $meta['pa_camp-terms'] ?? '');
        $this->assertSame('semaine-un', $meta['pa_camp-terms'] ?? '');
    }
}
