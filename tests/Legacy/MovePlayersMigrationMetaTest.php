<?php
/**
 * Roster move: order-item meta preservation and activity-type rewrite.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersMigrationMetaTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    protected function setUp(): void {
        if (function_exists('\Patchwork\restoreAll')) {
            \Patchwork\restoreAll();
        }
        parent::setUp();
        global $intersoccer_test_get_product_type_callback, $intersoccer_test_product_type_safe_callback;
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-item-renormalize-suppress.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
        if (file_exists(dirname(__DIR__, 2) . '/includes/advanced.php')) {
            require_once dirname(__DIR__, 2) . '/includes/advanced.php';
        }

        $this->registerMigrationMetaPaTerms();
    }

    private function registerMigrationMetaPaTerms(): void {
        $entries = [
            ['pa_activity-type', 'course', 'Course'],
            ['pa_activity-type', 'course-girls-only', 'Course Girls Only'],
            ['pa_girls-only', 'no', 'No'],
            ['pa_girls-only', 'yes', 'Yes'],
            ['pa_program-season', 'autumn-2026', 'Autumn 2026'],
            ['pa_program-season', 'spring-summer-2026', 'Spring Summer 2026'],
            ['pa_intersoccer-venues', 'geneva-college-sismondi-nations', 'Geneva College Sismondi Nations'],
            ['pa_intersoccer-venues', 'geneva-stade-chenois-thonex', 'Geneva Stade Chenois Thonex'],
            ['pa_intersoccer-venues', 'geneva-stade-chenois-thonex', 'Geneva - Stade Chênois, Thonex'],
            ['pa_age-group', '5-8y', '5-8y'],
            ['pa_age-group', '5-12', '5-12'],
            ['pa_age-group', '3-12y', '3-12y'],
            ['pa_booking-type', 'full-term', 'Full Term'],
            ['pa_canton-region', 'geneva', 'Geneva'],
            ['pa_city', 'geneva', 'Geneva'],
            ['pa_course-day', 'sunday', 'Sunday'],
            ['pa_course-times', '1115-1245', '11:15-12:45'],
            ['pa_course-times', '1000-1130', '10:00-11:30'],
            ['pa_course-times', '0930-1100', '09:30-11:00'],
            ['pa_camp-times', '9-00am-4-00pm', '9:00am-4:00pm'],
            ['pa_program-season', 'autumn', 'Autumn'],
        ];

        $by_slug = [];
        $by_id = [];
        $by_name = [];
        $id = 88000;
        foreach ($entries as [$taxonomy, $slug, $name]) {
            $slug_key = strtolower($slug);
            $obj = (object) ['term_id' => $id, 'slug' => $slug_key, 'name' => $name];
            $by_slug[$taxonomy][$slug_key] = $obj;
            $by_id[$id] = $obj;
            $name_key = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
            $by_name[$taxonomy][$name_key] = $obj;
            ++$id;
        }

        $GLOBALS['intersoccer_test_terms_by_slug'] = $by_slug;
        $GLOBALS['intersoccer_test_terms_by_id'] = $by_id;
        $GLOBALS['intersoccer_test_terms_by_name'] = $by_name;
    }

    protected function tearDown(): void {
        $this->resetMovePlayersTestGlobals();
        parent::tearDown();
    }

    /**
     * @param array<string,string> $meta
     */
    private function makeOrderItemMock(array $meta): \Mockery\MockInterface {
        $item = Mockery::mock('WC_Order_Item_Product');
        $meta_objects = [];
        foreach ($meta as $key => $value) {
            $meta_objects[] = (object) [
                'get_data' => static function () use ($key, $value) {
                    return ['key' => $key, 'value' => $value];
                },
            ];
        }
        $item->shouldReceive('get_meta_data')->andReturn($meta_objects);

        return $item;
    }

    public function test_player_attendee_keys_are_not_variation_rewrite_keys(): void {
        $lookup = intersoccer_migration_build_lookup(intersoccer_migration_human_alias_map());
        $player_keys = [
            'assigned_player_id',
            'Attendee DOB',
            'Attendee Gender',
            'Medical Conditions',
            'Assigned Attendee',
            'assigned_player',
            'Player Index',
        ];
        foreach ($player_keys as $key) {
            $this->assertTrue(intersoccer_migration_is_player_attendee_meta_key($key), $key);
            $this->assertFalse(
                intersoccer_migration_is_variation_rewrite_meta_key($key, $lookup),
                'should not strip: ' . $key
            );
        }
    }

    public function test_remove_variation_meta_for_move_preserves_player_fields(): void {
        $lookup = intersoccer_migration_build_lookup(intersoccer_migration_human_alias_map());
        $meta = [
            'Activity Type' => 'Course, Girls Only',
            'pa_activity-type' => 'course',
            'assigned_player_id' => 'player-uuid-1',
            'Attendee DOB' => '2015-03-01',
            'Attendee Gender' => 'female',
            'Medical Conditions' => 'Asthma',
            'Assigned Attendee' => 'REPRO Child',
        ];

        $deleted = [];
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_meta_data')->andReturnUsing(static function () use ($meta) {
            $objects = [];
            foreach ($meta as $key => $value) {
                $objects[] = new class($key, $value) {
                    private $key;
                    private $value;

                    public function __construct($key, $value) {
                        $this->key = $key;
                        $this->value = $value;
                    }

                    public function get_data() {
                        return ['key' => $this->key, 'value' => $this->value];
                    }
                };
            }
            return $objects;
        });
        $item->shouldReceive('delete_meta_data')->andReturnUsing(static function ($key) use (&$deleted) {
            $deleted[] = $key;
        });

        intersoccer_migration_remove_variation_meta_for_move($item, $lookup);

        $this->assertContains('Activity Type', $deleted);
        $this->assertContains('pa_activity-type', $deleted);
        $this->assertNotContains('assigned_player_id', $deleted);
        $this->assertNotContains('Attendee DOB', $deleted);
        $this->assertNotContains('Attendee Gender', $deleted);
        $this->assertNotContains('Medical Conditions', $deleted);
        $this->assertNotContains('Assigned Attendee', $deleted);
    }

    public function test_snapshot_and_round_trip_restore_player_meta(): void {
        $meta = [
            'Activity Type' => 'Course, Girls Only',
            'assigned_player_id' => 'uuid-99',
            'Attendee DOB' => '2014-01-02',
        ];
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_meta_data')->andReturnUsing(static function () use ($meta) {
            $objects = [];
            foreach ($meta as $key => $value) {
                $objects[] = new class($key, $value) {
                    private $key;
                    private $value;

                    public function __construct($key, $value) {
                        $this->key = $key;
                        $this->value = $value;
                    }

                    public function get_data() {
                        return ['key' => $this->key, 'value' => $this->value];
                    }
                };
            }
            return $objects;
        });

        $snapshot = intersoccer_migration_snapshot_player_attendee_meta($item);
        $this->assertSame('uuid-99', $snapshot['assigned_player_id']);
        $this->assertSame('2014-01-02', $snapshot['Attendee DOB']);
        $this->assertArrayNotHasKey('Activity Type', $snapshot);
    }

    /**
     * @return \Mockery\MockInterface
     */
    private function makeParentGirlsOnlyVariationForMigration(int $variation_id = 53637, int $parent_id = 37430) {
        global $intersoccer_test_get_product_type_callback, $intersoccer_test_product_type_safe_callback;
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };

        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($variation_id);
        $variation->shouldReceive('get_parent_id')->andReturn($parent_id);
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_name')->andReturn('Plain Course Variation');
        $variation->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            if ($name === 'pa_girls-only' || $name === 'girls-only') {
                return 'yes';
            }
            return '';
        });
        $variation->shouldReceive('get_attributes')->andReturn([]);

        global $intersoccer_test_wc_get_product_callback;
        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn($parent_id);
        $parent->shouldReceive('get_name')->andReturn('Parent Course Product');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);
        $intersoccer_test_wc_get_product_callback = static function ($id) use ($variation, $variation_id, $parent, $parent_id) {
            $id = (int) $id;
            if ($id === $variation_id) {
                return $variation;
            }
            if ($id === $parent_id) {
                return $parent;
            }

            return false;
        };

        return $variation;
    }

    public function test_parent_only_girls_only_variation_feeds_resolver_for_migration_activity_type(): void {
        $variation = $this->makeParentGirlsOnlyVariationForMigration();
        $this->assertSame(1, intersoccer_roster_move_resolve_variation_girls_only_flag($variation));
        $this->assertSame(
            'Course, Girls Only',
            intersoccer_migration_activity_type_with_girls_only_flag('Course', 1)
        );
    }

    public function test_build_migration_meta_updates_keeps_girls_only_in_activity_type(): void {
        if (!function_exists('intersoccer_build_migration_meta_updates')) {
            $this->markTestSkipped('intersoccer_build_migration_meta_updates not available');
        }

        global $intersoccer_test_get_product_type_callback, $intersoccer_test_product_type_safe_callback;
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };

        $variation = $this->makeParentGirlsOnlyVariationForMigration();
        $payload = intersoccer_build_migration_meta_updates($variation);

        $this->assertStringContainsString(
            'Girls Only',
            (string) ($payload['human_meta']['activity_type'] ?? '')
        );
        $this->assertStringContainsString('Course', (string) ($payload['human_meta']['activity_type'] ?? ''));
    }

    public function test_round_trip_player_meta_survives_variation_strip_and_rebuild(): void {
        $lookup = intersoccer_migration_build_lookup(intersoccer_migration_human_alias_map());
        $meta = [
            'Activity Type' => 'Course, Girls Only',
            'pa_activity-type' => 'course',
            'pa_age-group' => '5-8y',
            'assigned_player_id' => 'player-uuid-roundtrip',
            'Attendee DOB' => '2013-06-15',
            'Attendee Gender' => 'female',
            'Medical Conditions' => 'None',
            'Assigned Attendee' => 'Round Trip Child',
        ];

        $stored = $meta;
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_meta_data')->andReturnUsing(static function () use (&$stored) {
            $objects = [];
            foreach ($stored as $key => $value) {
                $objects[] = new class($key, $value) {
                    private $key;
                    private $value;

                    public function __construct($key, $value) {
                        $this->key = $key;
                        $this->value = $value;
                    }

                    public function get_data() {
                        return ['key' => $this->key, 'value' => $this->value];
                    }
                };
            }
            return $objects;
        });
        $item->shouldReceive('delete_meta_data')->andReturnUsing(static function ($key) use (&$stored) {
            unset($stored[$key]);
        });

        $snapshot = intersoccer_migration_snapshot_player_attendee_meta($item);
        intersoccer_migration_remove_variation_meta_for_move($item, $lookup);

        $this->assertArrayHasKey('assigned_player_id', $snapshot);
        $this->assertArrayHasKey('Attendee DOB', $snapshot);
        $this->assertArrayNotHasKey('Activity Type', $snapshot);
        $this->assertArrayNotHasKey('pa_activity-type', $stored);
        $this->assertArrayHasKey('assigned_player_id', $stored);

        if (function_exists('intersoccer_build_migration_meta_updates')) {
            $variation = $this->makeParentGirlsOnlyVariationForMigration();
            $payload = intersoccer_build_migration_meta_updates($variation);
            $stored['Activity Type'] = $payload['human_meta']['activity_type'] ?? '';
            foreach ($payload['slug_meta'] ?? [] as $slug_key => $slug_val) {
                if ($slug_key !== '' && $slug_val !== '') {
                    $stored[$slug_key] = $slug_val;
                }
            }
        }

        foreach ($snapshot as $key => $value) {
            $stored[$key] = $value;
        }

        $this->assertSame('player-uuid-roundtrip', $stored['assigned_player_id']);
        $this->assertSame('2013-06-15', $stored['Attendee DOB']);
        $this->assertSame('female', $stored['Attendee Gender']);
        $this->assertSame('None', $stored['Medical Conditions']);
        $this->assertStringContainsString('Girls Only', (string) ($stored['Activity Type'] ?? ''));
    }

    public function test_slug_meta_includes_girls_only_from_parent_attribute(): void {
        if (!function_exists('intersoccer_build_migration_meta_updates')) {
            $this->markTestSkipped('intersoccer_build_migration_meta_updates not available');
        }

        $variation = $this->makeParentGirlsOnlyVariationForMigration();
        $payload = intersoccer_build_migration_meta_updates($variation);

        $this->assertNotEmpty($payload['slug_meta']['pa_girls-only'] ?? '');
        $this->assertSame(
            $payload['slug_meta']['pa_girls-only'],
            $payload['slug_meta']['attribute_pa_girls-only'] ?? ''
        );
    }

    public function test_round_trip_meta_unchanged_apart_from_audit_keys(): void {
        global $intersoccer_test_wc_order_item_meta;

        $item_id = 88150;
        $lookup = intersoccer_migration_build_lookup(intersoccer_migration_human_alias_map());
        $before = [
            'pa_activity-type' => 'course',
            'attribute_pa_activity-type' => 'course',
            'pa_girls-only' => 'yes',
            'attribute_pa_girls-only' => 'yes',
            'Activity Type' => 'Course, Girls Only',
            'assigned_player_id' => 'player-roundtrip',
            'Attendee DOB' => '2012-02-02',
        ];
        $intersoccer_test_wc_order_item_meta = [];

        $run_pipeline = function () use ($item_id, $lookup, &$intersoccer_test_wc_order_item_meta) {
            $stored = $intersoccer_test_wc_order_item_meta[$item_id] ?? [];
            $item = Mockery::mock('WC_Order_Item_Product');
            $item->shouldReceive('get_meta_data')->andReturnUsing(static function () use (&$stored) {
                $objects = [];
                foreach ($stored as $key => $value) {
                    $objects[] = new class($key, $value) {
                        private $key;
                        private $value;

                        public function __construct($key, $value) {
                            $this->key = $key;
                            $this->value = $value;
                        }

                        public function get_data() {
                            return ['key' => $this->key, 'value' => $this->value];
                        }
                    };
                }
                return $objects;
            });
            $item->shouldReceive('delete_meta_data')->andReturnUsing(static function ($key) use (&$stored) {
                unset($stored[$key]);
            });

            $existing_pa = array_keys(array_filter(
                $stored,
                static function ($key) {
                    return preg_match('/^(attribute_)?pa_/i', (string) $key);
                },
                ARRAY_FILTER_USE_KEY
            ));

            $snapshot = intersoccer_migration_snapshot_player_attendee_meta($item);
            intersoccer_migration_remove_variation_meta_for_move($item, $lookup);

            $variation = $this->makeParentGirlsOnlyVariationForMigration();
            $payload = intersoccer_build_migration_meta_updates($variation);
            foreach ($payload['human_meta'] as $canonical => $val) {
                $label = intersoccer_migration_english_meta_label($canonical);
                $stored[$label] = $val;
            }
            foreach ($payload['other_meta'] ?? [] as $key => $val) {
                if ($val !== '') {
                    $stored[$key] = $val;
                }
            }
            if (!empty($payload['slug_meta']) && function_exists('intersoccer_migration_apply_slug_meta_for_move')) {
                $slug_to_apply = function_exists('intersoccer_migration_filter_slug_meta_for_move')
                    ? intersoccer_migration_filter_slug_meta_for_move($payload['slug_meta'], $existing_pa, $variation)
                    : $payload['slug_meta'];
                $intersoccer_test_wc_order_item_meta[$item_id] = $stored;
                intersoccer_migration_apply_slug_meta_for_move($item_id, $slug_to_apply);
                $stored = $intersoccer_test_wc_order_item_meta[$item_id];
            }
            foreach ($snapshot as $key => $value) {
                $stored[$key] = $value;
            }
            $intersoccer_test_wc_order_item_meta[$item_id] = $stored;
        };

        $intersoccer_test_wc_order_item_meta[$item_id] = $before;
        $run_pipeline();
        $baseline = $intersoccer_test_wc_order_item_meta[$item_id];
        $this->assertNotEmpty($baseline['pa_girls-only'] ?? '');
        $this->assertStringContainsString('Girls Only', (string) ($baseline['Activity Type'] ?? ''));

        $run_pipeline();
        $after_round = $intersoccer_test_wc_order_item_meta[$item_id];

        $audit_keys = function_exists('intersoccer_roster_move_audit_all_meta_keys')
            ? intersoccer_roster_move_audit_all_meta_keys()
            : [];

        $normalize = static function (array $meta) use ($audit_keys) {
            foreach ($audit_keys as $audit_key) {
                unset($meta[$audit_key]);
            }
            ksort($meta);
            return $meta;
        };

        $this->assertSame($normalize($baseline), $normalize($after_round));
    }

    public function test_filter_slug_meta_does_not_add_keys_absent_from_line(): void {
        $variation = $this->makeParentGirlsOnlyVariationForMigration();
        $before_keys = [
            'pa_activity-type',
            'attribute_pa_activity-type',
            'pa_girls-only',
            'attribute_pa_girls-only',
        ];
        $bloated = [
            'pa_activity-type' => 'course',
            'attribute_pa_activity-type' => 'course',
            'pa_girls-only' => 'yes',
            'attribute_pa_girls-only' => 'yes',
            'pa_age-group' => '5-8y',
            'attribute_pa_age-group' => '5-8y',
            'pa_city' => 'geneva',
            'attribute_pa_city' => 'geneva',
        ];

        $filtered = intersoccer_migration_filter_slug_meta_for_move($bloated, $before_keys, $variation);

        $this->assertArrayHasKey('pa_girls-only', $filtered);
        $this->assertArrayNotHasKey('pa_age-group', $filtered);
        $this->assertArrayNotHasKey('pa_city', $filtered);
        $this->assertLessThanOrEqual(count($before_keys), count($filtered));
    }

    public function test_activity_type_from_tristate_canonicalizes_girls_only_label(): void {
        $label = intersoccer_migration_activity_type_for_girls_only_tristate(
            "Course, Girls' Only",
            'yes'
        );
        $this->assertSame('Course, Girls Only', $label);
    }

    /**
     * Meta key shape from QA order item 7884 (no customer / attendee values).
     *
     * @return array<string,string>
     */
    private function orderItem7884MetaShape(): array {
        return [
            'Activity Type' => 'Course',
            'Age Group' => '6-12y',
            'attribute_pa_activity-type' => 'course',
            'attribute_pa_age-group' => '5-8y',
            'attribute_pa_booking-type' => 'full-term',
            'attribute_pa_canton-region' => 'geneva',
            'attribute_pa_city' => 'geneva',
            'attribute_pa_course-day' => 'sunday',
            'attribute_pa_course-times' => '1115-1245',
            'attribute_pa_girls-only' => 'no',
            'attribute_pa_intersoccer-venues' => 'geneva-college-sismondi-nations',
            'attribute_pa_program-season' => 'autumn',
            'Booking Type' => 'Full Term',
            'Canton / Region' => 'Geneva',
            'City' => 'Geneva',
            'Course Day' => 'Sunday',
            'Course Times' => '1115-1245',
            'End Date' => 'December 20, 2026',
            'girls_only' => 'No',
            'Holidays' => 'October 25, 2026',
            'Note' => '',
            'pa_activity-type' => 'course',
            'pa_age-group' => '5-8y',
            'pa_booking-type' => 'full-term',
            'pa_canton-region' => 'geneva',
            'pa_city' => 'geneva',
            'pa_course-day' => 'sunday',
            'pa_course-times' => '1115-1245',
            'pa_girls-only' => 'no',
            'pa_intersoccer-venues' => 'geneva-college-sismondi-nations',
            'pa_program-season' => 'autumn',
            'Season' => 'Autumn',
            'Sites InterSoccer' => 'Geneva - Collège Sismondi (Nations)',
            'Start Date' => 'September 7, 2026',
            'Variation ID' => '30711',
            'Year' => '2026',
        ];
    }

    /**
     * Meta key shape from QA order item 7883 (no customer / attendee values).
     *
     * @return array<string,string>
     */
    private function orderItem7883MetaShape(): array {
        $shape = $this->orderItem7884MetaShape();
        $shape['Variation ID'] = '30712';
        $shape['attribute_pa_course-times'] = '0930-1100';
        $shape['pa_course-times'] = '0930-1100';
        $shape['Course Times'] = '0930-1100';
        $shape['Age Group'] = '5-12';
        $shape['attribute_pa_age-group'] = '5-12';
        $shape['pa_age-group'] = '5-12';
        unset($shape['pa_booking-type'], $shape['attribute_pa_booking-type'], $shape['Booking Type']);

        return $shape;
    }

    public function test_order_7884_shape_keeps_booking_slug_keys_when_target_exposes_parent_booking_type(): void {
        $line_meta = $this->orderItem7884MetaShape();
        $existing_pa = array_keys(array_filter(
            $line_meta,
            static function ($key) {
                return preg_match('/^(attribute_)?pa_/i', (string) $key);
            },
            ARRAY_FILTER_USE_KEY
        ));

        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_parent_id')->andReturn(25226);
        $variation->shouldReceive('get_attribute')->andReturnUsing(static function ($taxonomy) {
            if ($taxonomy === 'pa_booking-type') {
                return 'full-term';
            }
            return '';
        });

        $slug_meta = [
            'pa_booking-type' => 'full-term',
            'attribute_pa_booking-type' => 'full-term',
            'pa_age-group' => '5-12',
            'attribute_pa_age-group' => '5-12',
        ];

        $filtered = intersoccer_migration_filter_slug_meta_for_move($slug_meta, $existing_pa, $variation);

        $this->assertArrayHasKey('pa_booking-type', $filtered);
        $this->assertArrayHasKey('attribute_pa_booking-type', $filtered);
    }

    public function test_order_7883_shape_allowed_canonicals_include_year_and_girls_only(): void {
        $line_meta = $this->orderItem7883MetaShape();
        $alias_map = intersoccer_migration_human_alias_map();
        $lookup = intersoccer_migration_build_lookup($alias_map);
        $allowed = intersoccer_migration_allowed_canonicals_for_move(
            array_keys($line_meta),
            $alias_map,
            $lookup
        );

        $this->assertContains('program_year', $allowed);
        $this->assertContains('girls_only', $allowed);
    }

    public function test_order_7884_shape_pick_meta_label_prefers_lowercase_girls_only_key(): void {
        $alias_map = intersoccer_migration_human_alias_map();
        $line_keys = array_keys($this->orderItem7884MetaShape());

        $label = intersoccer_migration_pick_meta_label_for_canonical('girls_only', $alias_map, $line_keys);

        $this->assertSame('girls_only', $label);
    }

    public function test_order_7884_shape_girls_only_target_activity_type_label(): void {
        if (!function_exists('intersoccer_build_migration_meta_updates')) {
            $this->markTestSkipped('intersoccer_build_migration_meta_updates not available');
        }

        global $intersoccer_test_get_product_type_callback, $intersoccer_test_product_type_safe_callback;
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };

        $variation = $this->makeParentGirlsOnlyVariationForMigration();
        $payload = intersoccer_build_migration_meta_updates($variation);

        $this->assertSame('Course, Girls Only', $payload['human_meta']['activity_type'] ?? '');
    }

    // Regression: RR #104 — build_migration_meta_updates omits unresolved pa_* from slug_meta; resolved slugs stay EN.
    public function test_build_migration_meta_updates_omits_unresolved_pa_from_slug_meta(): void {
        if (!function_exists('intersoccer_build_migration_meta_updates')) {
            $this->markTestSkipped('intersoccer_build_migration_meta_updates not available');
        }

        global $intersoccer_test_get_product_type_callback, $intersoccer_test_product_type_safe_callback;
        $intersoccer_test_get_product_type_callback = static function () {
            return 'camp';
        };
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'camp';
        };

        $parent_id = 88050;
        $variation_id = 88051;
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($variation_id);
        $variation->shouldReceive('get_parent_id')->andReturn($parent_id);
        $variation->shouldReceive('get_name')->andReturn('Camp variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_booking-type' => 'full-term',
            'attribute_pa_canton-region' => 'Unknown Canton Label',
            'attribute_pa_girls-only' => 'no',
        ]);
        $variation->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            if ($name === 'pa_booking-type') {
                return 'Full Term';
            }
            if ($name === 'pa_canton-region') {
                return 'Unknown Canton Label';
            }
            if ($name === 'pa_girls-only') {
                return 'No';
            }

            return '';
        });
        $variation->shouldReceive('get_attributes')->andReturn([]);

        global $intersoccer_test_wc_get_product_callback;
        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn($parent_id);
        $parent->shouldReceive('get_name')->andReturn('Camp Parent');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);
        $intersoccer_test_wc_get_product_callback = static function ($id) use ($variation, $variation_id, $parent, $parent_id) {
            $id = (int) $id;
            if ($id === $variation_id) {
                return $variation;
            }
            if ($id === $parent_id) {
                return $parent;
            }

            return false;
        };

        $payload = intersoccer_build_migration_meta_updates($variation);
        $slug_meta = $payload['slug_meta'] ?? [];

        $this->assertSame('full-term', $slug_meta['pa_booking-type'] ?? '');
        $this->assertSame('full-term', $slug_meta['attribute_pa_booking-type'] ?? '');
        $this->assertArrayNotHasKey('pa_canton-region', $slug_meta);
        $this->assertArrayNotHasKey('attribute_pa_canton-region', $slug_meta);
        $this->assertSame('Unknown Canton Label', $payload['human_meta']['canton_region'] ?? '');
    }

    public function test_order_7883_shape_snapshot_maps_year_to_program_year_canonical(): void {
        global $intersoccer_test_wc_order_item_meta;

        $item_id = 88002;
        $intersoccer_test_wc_order_item_meta[$item_id] = $this->orderItem7883MetaShape();
        $alias_map = intersoccer_migration_human_alias_map();
        $lookup = intersoccer_migration_build_lookup($alias_map);
        $item = new \WC_Order_Item_Product($item_id);

        $snapshot = intersoccer_migration_snapshot_line_meta_values($item, $alias_map, $lookup);

        $this->assertSame('2026', $snapshot['human']['program_year'] ?? '');
    }

    public function test_item_renormalize_suppress_is_cleared_after_finally_on_failure(): void {
        \InterSoccer_Roster_Move_Item_Renormalize_Suppress::reset_for_tests();
        $item_id = 88088;

        \InterSoccer_Roster_Move_Item_Renormalize_Suppress::push($item_id);
        $this->assertTrue(\InterSoccer_Roster_Move_Item_Renormalize_Suppress::is_active($item_id));

        try {
            throw new \RuntimeException('Simulated move failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated move failure', $e->getMessage());
        } finally {
            \InterSoccer_Roster_Move_Item_Renormalize_Suppress::pop($item_id);
        }

        $this->assertFalse(\InterSoccer_Roster_Move_Item_Renormalize_Suppress::is_active($item_id));
    }

    public function test_coerce_pa_taxonomy_slug_maps_display_name_to_term_slug(): void {
        $this->assertSame('yes', intersoccer_migration_coerce_pa_taxonomy_slug('pa_girls-only', 'Yes'));
        $this->assertSame('no', intersoccer_migration_coerce_pa_taxonomy_slug('pa_girls-only', 'No'));
    }

    public function test_normalize_slug_meta_preserves_display_bytes_when_term_unchanged_on_line(): void {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_activity-type' => 'Course',
            'attribute_pa_girls-only' => 'No',
            'attribute_pa_program-season' => 'Autumn 2026',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $existing_line_pa = [
            'pa_activity-type' => 'Course',
            'attribute_pa_activity-type' => 'Course',
            'pa_girls-only' => 'No',
            'attribute_pa_girls-only' => 'No',
            'pa_program-season' => 'Autumn 2026',
            'attribute_pa_program-season' => 'Autumn 2026',
        ];

        $slug_meta = [
            'pa_activity-type' => 'course',
            'attribute_pa_activity-type' => 'course',
            'pa_girls-only' => 'no',
            'attribute_pa_girls-only' => 'no',
            'pa_program-season' => 'autumn-2026',
            'attribute_pa_program-season' => 'autumn-2026',
        ];

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation(
            $slug_meta,
            $variation,
            $existing_line_pa
        );

        $this->assertSame('Course', $normalized['pa_activity-type']);
        $this->assertSame('Course', $normalized['attribute_pa_activity-type']);
        $this->assertSame('No', $normalized['pa_girls-only']);
        $this->assertSame('Autumn 2026', $normalized['pa_program-season']);
    }

    public function test_normalize_slug_meta_writes_display_when_term_changes_from_composite_line_value(): void {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_activity-type' => 'course',
            'attribute_pa_girls-only' => 'no',
        ]);
        $variation->shouldReceive('get_attribute')->with('pa_activity-type')->andReturn('Course');
        $variation->shouldReceive('get_attribute')->with('pa_girls-only')->andReturn('No');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $existing_line_pa = [
            'pa_activity-type' => 'course-girls-only',
            'attribute_pa_activity-type' => 'course-girls-only',
            'pa_girls-only' => 'yes',
            'attribute_pa_girls-only' => 'yes',
        ];
        $slug_meta = [
            'pa_activity-type' => 'course',
            'attribute_pa_activity-type' => 'course',
            'pa_girls-only' => 'no',
            'attribute_pa_girls-only' => 'no',
        ];

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation(
            $slug_meta,
            $variation,
            $existing_line_pa
        );

        $this->assertSame('course', $normalized['pa_activity-type']);
        $this->assertSame('no', $normalized['pa_girls-only']);
        $this->assertNotSame('course-girls-only', $normalized['pa_activity-type']);
        $this->assertNotSame('yes', $normalized['pa_girls-only']);
    }

    public function test_normalize_slug_meta_writes_display_name_when_term_changes_and_line_used_display_form(): void {
        $target_slug = 'spring-summer-2026';
        $target_display = 'Spring Summer 2026';

        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_program-season' => $target_slug,
        ]);
        $variation->shouldReceive('get_attribute')->with('pa_program-season')->andReturn($target_display);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $existing_line_pa = [
            'pa_program-season' => 'Autumn 2026',
            'attribute_pa_program-season' => 'Autumn 2026',
        ];
        $slug_meta = [
            'pa_program-season' => $target_slug,
            'attribute_pa_program-season' => $target_slug,
        ];

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation(
            $slug_meta,
            $variation,
            $existing_line_pa
        );

        $this->assertSame($target_slug, $normalized['pa_program-season']);
        $this->assertSame($target_slug, $normalized['attribute_pa_program-season']);
        $this->assertNotSame($target_display, $normalized['pa_program-season']);
    }

    public function test_normalize_slug_meta_round_trip_slug_form_across_real_term_change(): void {
        $from_slug = 'geneva-college-sismondi-nations';
        $to_slug = 'geneva-stade-chenois-thonex';
        $to_display = 'Geneva - Stade Chênois, Thonex';

        $variation_out = Mockery::mock('WC_Product_Variation');
        $variation_out->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_intersoccer-venues' => $to_slug,
        ]);
        $variation_out->shouldReceive('get_attribute')->with('pa_intersoccer-venues')->andReturn($to_display);
        $variation_out->shouldReceive('get_attribute')->andReturn('');
        $variation_out->shouldReceive('get_parent_id')->andReturn(0);

        $variation_back = Mockery::mock('WC_Product_Variation');
        $variation_back->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_intersoccer-venues' => $from_slug,
        ]);
        $variation_back->shouldReceive('get_attribute')->with('pa_intersoccer-venues')->andReturn('Geneva - Collège Sismondi (Nations)');
        $variation_back->shouldReceive('get_attribute')->andReturn('');
        $variation_back->shouldReceive('get_parent_id')->andReturn(0);

        $baseline = [
            'pa_intersoccer-venues' => $from_slug,
            'attribute_pa_intersoccer-venues' => $from_slug,
        ];
        $slug_meta_out = [
            'pa_intersoccer-venues' => $to_slug,
            'attribute_pa_intersoccer-venues' => $to_slug,
        ];
        $after_out = intersoccer_migration_normalize_slug_meta_for_variation($slug_meta_out, $variation_out, $baseline);
        $this->assertSame($to_slug, $after_out['pa_intersoccer-venues']);

        $slug_meta_back = [
            'pa_intersoccer-venues' => $from_slug,
            'attribute_pa_intersoccer-venues' => $from_slug,
        ];
        $line_after_out = [
            'pa_intersoccer-venues' => $after_out['pa_intersoccer-venues'],
            'attribute_pa_intersoccer-venues' => $after_out['attribute_pa_intersoccer-venues'],
        ];
        $after_back = intersoccer_migration_normalize_slug_meta_for_variation($slug_meta_back, $variation_back, $line_after_out);
        $this->assertSame($baseline['pa_intersoccer-venues'], $after_back['pa_intersoccer-venues']);
        $this->assertSame($baseline['attribute_pa_intersoccer-venues'], $after_back['attribute_pa_intersoccer-venues']);
    }

    public function test_normalize_slug_meta_round_trip_display_form_across_real_term_change(): void {
        $autumn_display = 'Autumn 2026';
        $spring_slug = 'spring-summer-2026';
        $spring_display = 'Spring Summer 2026';

        $variation_out = Mockery::mock('WC_Product_Variation');
        $variation_out->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_program-season' => $spring_slug,
        ]);
        $variation_out->shouldReceive('get_attribute')->with('pa_program-season')->andReturn($spring_display);
        $variation_out->shouldReceive('get_attribute')->andReturn('');
        $variation_out->shouldReceive('get_parent_id')->andReturn(0);

        $variation_back = Mockery::mock('WC_Product_Variation');
        $variation_back->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_program-season' => 'autumn-2026',
        ]);
        $variation_back->shouldReceive('get_attribute')->with('pa_program-season')->andReturn($autumn_display);
        $variation_back->shouldReceive('get_attribute')->andReturn('');
        $variation_back->shouldReceive('get_parent_id')->andReturn(0);

        $baseline = [
            'pa_program-season' => $autumn_display,
            'attribute_pa_program-season' => $autumn_display,
        ];
        $after_out = intersoccer_migration_normalize_slug_meta_for_variation(
            [
                'pa_program-season' => $spring_slug,
                'attribute_pa_program-season' => $spring_slug,
            ],
            $variation_out,
            $baseline
        );
        $this->assertSame($spring_slug, $after_out['pa_program-season']);

        $line_after_out = [
            'pa_program-season' => $after_out['pa_program-season'],
            'attribute_pa_program-season' => $after_out['attribute_pa_program-season'],
        ];
        $after_back = intersoccer_migration_normalize_slug_meta_for_variation(
            [
                'pa_program-season' => 'autumn-2026',
                'attribute_pa_program-season' => 'autumn-2026',
            ],
            $variation_back,
            $line_after_out
        );
        $this->assertSame('autumn-2026', $after_back['pa_program-season']);
        $this->assertSame('autumn-2026', $after_back['attribute_pa_program-season']);
    }

    public function test_normalize_slug_meta_writes_venue_term_slug_when_name_differs_from_slug(): void {
        $venue_slug = 'geneva-stade-chenois-thonex';
        $venue_display = 'Geneva - Stade Chênois, Thonex';

        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_intersoccer-venues' => $venue_slug,
        ]);
        $variation->shouldReceive('get_attribute')->with('pa_intersoccer-venues')->andReturn($venue_display);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $existing_line_pa = [
            'pa_intersoccer-venues' => 'geneva-college-sismondi-nations',
            'attribute_pa_intersoccer-venues' => 'geneva-college-sismondi-nations',
        ];
        $existing_pa_keys = array_keys($existing_line_pa);
        $slug_meta = [
            'pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'pa_age-group' => '5-12',
            'attribute_pa_age-group' => '5-12',
        ];

        $filtered = intersoccer_migration_filter_slug_meta_for_move(
            $slug_meta,
            $existing_pa_keys,
            $variation,
            $existing_line_pa
        );

        $this->assertSame($venue_slug, $filtered['pa_intersoccer-venues']);
        $this->assertSame($venue_slug, $filtered['attribute_pa_intersoccer-venues']);
        $this->assertNotSame($venue_display, $filtered['pa_intersoccer-venues']);
        $this->assertArrayNotHasKey('pa_age-group', $filtered);
        $this->assertArrayNotHasKey('attribute_pa_age-group', $filtered);
    }

    public function test_filter_slug_meta_does_not_add_venue_pa_when_line_had_no_venue_key(): void {
        $venue_slug = 'geneva-stade-chenois-thonex';
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_age-group' => '5-12',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $existing_pa_keys = [
            'attribute_pa_age-group',
            'pa_age-group',
        ];
        $existing_line_pa = [
            'attribute_pa_age-group' => '5-8y',
            'pa_age-group' => '5-8y',
        ];
        $slug_meta = [
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_age-group' => '5-12',
            'pa_age-group' => '5-12',
        ];

        $filtered = intersoccer_migration_filter_slug_meta_for_move(
            $slug_meta,
            $existing_pa_keys,
            $variation,
            $existing_line_pa
        );

        $this->assertArrayNotHasKey('pa_intersoccer-venues', $filtered);
        $this->assertArrayNotHasKey('attribute_pa_intersoccer-venues', $filtered);
        $this->assertSame('5-12', $filtered['pa_age-group']);
    }

    public function test_normalize_slug_meta_writes_slug_when_key_missing_on_line(): void {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_city' => 'geneva',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $slug_meta = [
            'pa_city' => 'geneva',
            'attribute_pa_city' => 'geneva',
        ];

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation($slug_meta, $variation, []);

        $this->assertSame('geneva', $normalized['pa_city']);
        $this->assertSame('geneva', $normalized['attribute_pa_city']);
    }

    public function test_normalize_slug_meta_writes_target_slug_when_term_changes_or_key_missing(): void {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_age-group' => '5-12',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $existing_line_pa = [
            'pa_age-group' => '3-12y',
            'attribute_pa_age-group' => '3-12y',
        ];

        $slug_meta = [
            'pa_age-group' => '5-12',
            'attribute_pa_age-group' => '5-12',
            'pa_city' => 'geneva',
            'attribute_pa_city' => 'geneva',
        ];

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation(
            $slug_meta,
            $variation,
            $existing_line_pa
        );

        $this->assertSame('5-12', $normalized['pa_age-group']);
        $this->assertSame('geneva', $normalized['pa_city']);
    }

    public function test_normalize_slug_meta_keeps_girls_only_and_booking_slugs_lowercase(): void {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_girls-only' => 'yes',
            'attribute_pa_booking-type' => 'full-term',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);
        $variation->shouldReceive('get_id')->andReturn(30711);

        $slug_meta = [
            'pa_girls-only' => 'Yes',
            'attribute_pa_girls-only' => 'Yes',
            'pa_booking-type' => 'Full Term',
            'attribute_pa_booking-type' => 'Full Term',
        ];

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation($slug_meta, $variation);

        $this->assertSame('yes', $normalized['pa_girls-only']);
        $this->assertSame('yes', $normalized['attribute_pa_girls-only']);
        $this->assertSame('full-term', $normalized['pa_booking-type']);
        $this->assertSame('full-term', $normalized['attribute_pa_booking-type']);
    }

    public function test_order_7883_shape_attribute_only_pa_keys_do_not_gain_slug_siblings_on_filter(): void {
        $line_meta = $this->orderItem7883MetaShape();
        unset($line_meta['pa_age-group'], $line_meta['pa_course-times'], $line_meta['pa_intersoccer-venues']);
        $existing_pa = array_keys(array_filter(
            $line_meta,
            static function ($key) {
                return preg_match('/^(attribute_)?pa_/i', (string) $key);
            },
            ARRAY_FILTER_USE_KEY
        ));

        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_age-group' => '5-12',
            'attribute_pa_course-times' => '1000-1130',
            'attribute_pa_intersoccer-venues' => 'geneva-stade-chenois-thonex',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $slug_meta = [
            'attribute_pa_age-group' => '5-12',
            'attribute_pa_course-times' => '1000-1130',
            'attribute_pa_intersoccer-venues' => 'geneva-stade-chenois-thonex',
            'pa_age-group' => '5-12',
            'pa_course-times' => '1000-1130',
            'pa_intersoccer-venues' => 'geneva-stade-chenois-thonex',
        ];

        $existing_line_pa = [];
        foreach ($existing_pa as $pa_key) {
            $existing_line_pa[$pa_key] = $line_meta[$pa_key];
        }

        $filtered = intersoccer_migration_filter_slug_meta_for_move(
            $slug_meta,
            $existing_pa,
            $variation,
            $existing_line_pa
        );

        $this->assertSame('5-12', $filtered['attribute_pa_age-group']);
        $this->assertSame('1000-1130', $filtered['attribute_pa_course-times']);
        $this->assertSame('geneva-stade-chenois-thonex', $filtered['attribute_pa_intersoccer-venues']);
        $this->assertArrayNotHasKey('pa_age-group', $filtered);
        $this->assertArrayNotHasKey('pa_course-times', $filtered);
        $this->assertArrayNotHasKey('pa_intersoccer-venues', $filtered);
    }

    public function test_filter_slug_meta_updates_only_pa_slug_key_when_line_had_no_attribute_sibling(): void {
        $venue_slug = 'geneva-stade-chenois-thonex';
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_age-group' => '5-12',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $existing_pa_keys = ['pa_intersoccer-venues', 'pa_age-group'];
        $existing_line_pa = [
            'pa_intersoccer-venues' => 'geneva-college-sismondi-nations',
            'pa_age-group' => '5-8y',
        ];
        $slug_meta = [
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_age-group' => '5-12',
            'pa_age-group' => '5-12',
        ];

        $filtered = intersoccer_migration_filter_slug_meta_for_move(
            $slug_meta,
            $existing_pa_keys,
            $variation,
            $existing_line_pa
        );

        $this->assertSame($venue_slug, $filtered['pa_intersoccer-venues']);
        $this->assertSame('5-12', $filtered['pa_age-group']);
        $this->assertArrayNotHasKey('attribute_pa_intersoccer-venues', $filtered);
        $this->assertArrayNotHasKey('attribute_pa_age-group', $filtered);
    }

    public function test_filter_slug_meta_updates_only_attribute_slug_key_when_line_had_no_pa_sibling(): void {
        $venue_slug = 'geneva-stade-chenois-thonex';
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_age-group' => '5-12',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $existing_pa_keys = ['attribute_pa_intersoccer-venues', 'attribute_pa_age-group'];
        $existing_line_pa = [
            'attribute_pa_intersoccer-venues' => 'geneva-college-sismondi-nations',
            'attribute_pa_age-group' => '5-8y',
        ];
        $slug_meta = [
            'attribute_pa_intersoccer-venues' => $venue_slug,
            'pa_intersoccer-venues' => $venue_slug,
            'attribute_pa_age-group' => '5-12',
            'pa_age-group' => '5-12',
        ];

        $filtered = intersoccer_migration_filter_slug_meta_for_move(
            $slug_meta,
            $existing_pa_keys,
            $variation,
            $existing_line_pa
        );

        $this->assertSame($venue_slug, $filtered['attribute_pa_intersoccer-venues']);
        $this->assertSame('5-12', $filtered['attribute_pa_age-group']);
        $this->assertArrayNotHasKey('pa_intersoccer-venues', $filtered);
        $this->assertArrayNotHasKey('pa_age-group', $filtered);
    }

    public function test_prune_meta_rows_keeps_girls_only_label_and_lowercase_when_both_on_line(): void {
        global $wpdb;

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $rows = [
            (object) ['meta_id' => 101, 'meta_key' => 'Girls Only', 'meta_value' => 'Yes'],
            (object) ['meta_id' => 102, 'meta_key' => 'girls_only', 'meta_value' => 'Yes'],
        ];
        $wpdb->shouldReceive('prepare')->andReturn('prune-sql');
        $wpdb->shouldReceive('get_results')->andReturn($rows);
        $wpdb->shouldReceive('delete')->never();
        $wpdb->shouldReceive('update')->never();

        intersoccer_migration_prune_meta_rows(
            88088,
            intersoccer_migration_human_alias_map(),
            intersoccer_migration_build_lookup(intersoccer_migration_human_alias_map()),
            ['Girls Only', 'girls_only']
        );

        $this->addToAssertionCount(1);
    }

    public function test_prune_keeps_girls_only_alias_when_line_had_girls_only_label_key(): void {
        global $intersoccer_test_wc_order_item_meta;

        $item_id = 88002;
        $lookup = intersoccer_migration_build_lookup(intersoccer_migration_human_alias_map());
        $existing_keys = ['Activity Type', 'Girls Only', 'pa_girls-only', 'attribute_pa_girls-only'];

        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'Activity Type' => 'Course',
            'Girls Only' => 'Yes',
            'girls_only' => 'Yes',
            'pa_girls-only' => 'yes',
            'attribute_pa_girls-only' => 'yes',
        ];

        intersoccer_migration_prune_unexpected_move_meta_keys($item_id, $existing_keys, $lookup);

        $this->assertArrayHasKey('Girls Only', $intersoccer_test_wc_order_item_meta[$item_id]);
        $this->assertArrayHasKey('girls_only', $intersoccer_test_wc_order_item_meta[$item_id]);
    }

    public function test_prune_removes_girls_only_key_when_it_was_not_on_line_before_move(): void {
        global $intersoccer_test_wc_order_item_meta;

        $item_id = 88001;
        $lookup = intersoccer_migration_build_lookup(intersoccer_migration_human_alias_map());
        $existing_keys = array_values(array_filter(
            array_keys($this->orderItem7884MetaShape()),
            static function ($key) {
                return $key !== 'girls_only';
            }
        ));

        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'Activity Type' => 'Course',
            'pa_girls-only' => 'yes',
            'attribute_pa_girls-only' => 'yes',
            'girls_only' => 'Yes',
        ];

        intersoccer_migration_prune_unexpected_move_meta_keys($item_id, $existing_keys, $lookup);

        $this->assertArrayNotHasKey('girls_only', $intersoccer_test_wc_order_item_meta[$item_id]);
        $this->assertArrayHasKey('pa_girls-only', $intersoccer_test_wc_order_item_meta[$item_id]);
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

    private function makeCourseVariationForScheduleMeta(int $variation_id): \Mockery\MockInterface {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($variation_id);
        $variation->shouldReceive('get_parent_id')->andReturn(37420);
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_name')->andReturn('Course variation');
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_attributes')->andReturn([]);
        $variation->shouldReceive('is_type')->andReturn(true);

        return $variation;
    }

    public function test_course_schedule_meta_always_reflects_target_variation(): void {
        if (!function_exists('intersoccer_build_migration_meta_updates')) {
            $this->markTestSkipped('intersoccer_build_migration_meta_updates not available');
        }
        $this->ensureCourseMetaStub();
        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            37637 => [
                '_course_start_date' => '2026-04-20',
                '_end_date' => '2026-05-06',
                '_course_holiday_dates' => ['2026-05-25'],
            ],
        ];

        $variation = $this->makeCourseVariationForScheduleMeta(37637);
        $payload = intersoccer_build_migration_meta_updates($variation);

        $this->assertSame('April 20, 2026', $payload['other_meta']['Start Date'] ?? '');
        $this->assertSame('May 6, 2026', $payload['other_meta']['End Date'] ?? '');
        $this->assertSame('May 25, 2026', $payload['other_meta']['Holidays'] ?? '');
    }

    public function test_course_round_trip_meta_matches_variation_a_after_return_from_b(): void {
        if (!function_exists('intersoccer_build_migration_meta_updates')) {
            $this->markTestSkipped('intersoccer_build_migration_meta_updates not available');
        }
        $this->ensureCourseMetaStub();
        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            37636 => [
                '_course_start_date' => '2026-03-16',
                '_end_date' => '2026-06-22',
                '_course_holiday_dates' => ['2026-04-06', '2026-04-13', '2026-05-25'],
            ],
            37637 => [
                '_course_start_date' => '2026-04-20',
                '_end_date' => '2026-05-06',
                '_course_holiday_dates' => ['2026-05-25'],
            ],
        ];

        $variation_b = $this->makeCourseVariationForScheduleMeta(37637);
        $after_b = intersoccer_build_migration_meta_updates($variation_b)['other_meta'];

        $variation_a = $this->makeCourseVariationForScheduleMeta(37636);
        $after_round_trip = intersoccer_build_migration_meta_updates($variation_a)['other_meta'];

        $this->assertSame('March 16, 2026', $after_round_trip['Start Date'] ?? '');
        $this->assertSame('June 22, 2026', $after_round_trip['End Date'] ?? '');
        $this->assertSame('April 6, 2026, April 13, 2026, May 25, 2026', $after_round_trip['Holidays'] ?? '');
        $this->assertNotSame($after_b['Start Date'] ?? '', $after_round_trip['Start Date'] ?? '');
    }

    public function test_course_target_without_schedule_meta_clears_start_end_and_holidays(): void {
        if (!function_exists('intersoccer_build_migration_meta_updates')) {
            $this->markTestSkipped('intersoccer_build_migration_meta_updates not available');
        }
        $this->ensureCourseMetaStub();
        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            37637 => [
                '_course_start_date' => '',
                '_end_date' => '',
                '_course_holiday_dates' => [],
            ],
        ];

        $variation = $this->makeCourseVariationForScheduleMeta(37637);
        $payload = intersoccer_build_migration_meta_updates($variation);

        $this->assertSame('', $payload['other_meta']['Start Date'] ?? 'missing');
        $this->assertSame('', $payload['other_meta']['End Date'] ?? 'missing');
        $this->assertSame('', $payload['other_meta']['Holidays'] ?? 'missing');
    }

    /**
     * Regression: RR #104 — nested _intersoccer_item_discounts must not pass through trim/array_map.
     */
    public function test_snapshot_line_meta_values_preserves_nested_item_discounts(): void {
        $discounts = [
            [
                'code' => 'SIBLING10',
                'amount' => 12.5,
                'type' => 'percent',
            ],
            [
                'code' => 'EARLYBIRD',
                'amount' => 5.0,
                'type' => 'fixed_cart',
            ],
        ];
        $meta = [
            '_intersoccer_item_discounts' => $discounts,
            '_intersoccer_total_item_discount' => '17.50',
            'Activity Type' => 'Course',
        ];
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_meta_data')->andReturnUsing(static function () use ($meta) {
            $objects = [];
            foreach ($meta as $key => $value) {
                $objects[] = new class($key, $value) {
                    private $key;
                    private $value;

                    public function __construct($key, $value) {
                        $this->key = $key;
                        $this->value = $value;
                    }

                    public function get_data() {
                        return ['key' => $this->key, 'value' => $this->value];
                    }
                };
            }

            return $objects;
        });
        $alias_map = intersoccer_migration_human_alias_map();
        $lookup = intersoccer_migration_build_lookup($alias_map);

        $snapshot = intersoccer_migration_snapshot_line_meta_values($item, $alias_map, $lookup);

        $this->assertSame($discounts, $snapshot['other']['_intersoccer_item_discounts']);
        $this->assertSame('17.50', $snapshot['other']['_intersoccer_total_item_discount']);
        $this->assertSame('Course', $snapshot['human']['activity_type'] ?? '');
    }

    public function test_snapshot_line_meta_values_preserves_flat_scalar_arrays_in_other_bucket(): void {
        $tags = ['tag-a', 'tag-b'];
        $meta = [
            '_custom_tags' => $tags,
            'Activity Type' => 'Course',
        ];
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_meta_data')->andReturnUsing(static function () use ($meta) {
            $objects = [];
            foreach ($meta as $key => $value) {
                $objects[] = new class($key, $value) {
                    private $key;
                    private $value;

                    public function __construct($key, $value) {
                        $this->key = $key;
                        $this->value = $value;
                    }

                    public function get_data() {
                        return ['key' => $this->key, 'value' => $this->value];
                    }
                };
            }

            return $objects;
        });
        $alias_map = intersoccer_migration_human_alias_map();
        $lookup = intersoccer_migration_build_lookup($alias_map);

        $snapshot = intersoccer_migration_snapshot_line_meta_values($item, $alias_map, $lookup);

        $this->assertSame($tags, $snapshot['other']['_custom_tags']);
    }

    public function test_restore_canonical_meta_preserves_nested_item_discounts_in_other_bucket(): void {
        global $intersoccer_test_wc_order_item_meta;

        $item_id = 88104;
        $discounts = [
            ['code' => 'COUPON-A', 'amount' => 10.0, 'type' => 'fixed_product'],
            ['code' => 'COUPON-B', 'amount' => 2.5, 'type' => 'percent'],
        ];
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            '_intersoccer_item_discounts' => $discounts,
            'Activity Type' => 'Course',
        ];
        $alias_map = intersoccer_migration_human_alias_map();
        $lookup = intersoccer_migration_build_lookup($alias_map);
        $existing_keys = array_keys($intersoccer_test_wc_order_item_meta[$item_id]);

        intersoccer_migration_restore_canonical_meta(
            $item_id,
            $alias_map,
            $lookup,
            [],
            [
                '_intersoccer_item_discounts' => $discounts,
            ],
            $existing_keys
        );

        $this->assertSame($discounts, $intersoccer_test_wc_order_item_meta[$item_id]['_intersoccer_item_discounts']);
    }

    /**
     * @return array<string,mixed>
     */
    private function mockEmptyRosterRowForRenormalize(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_row')->andReturn([]);
    }

    /**
     * Regression: RR #104 — admin language repair rewrites FR event values to English on existing keys.
     */
    public function test_renormalize_order_item_event_meta_rewrites_french_values_on_existing_keys(): void {
        if (!function_exists('intersoccer_renormalize_order_item_event_meta')) {
            $this->markTestSkipped('intersoccer_renormalize_order_item_event_meta not found');
        }

        global $intersoccer_test_wc_order_item_meta;

        $this->mockEmptyRosterRowForRenormalize();

        $item_id = 88105;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            "Type d'activité" => 'Cours',
            "Groupe d'âge"    => '5-13y (Full Day)',
            'Lieux InterSoccer' => 'Geneva Centre',
            'Horaires du camp'  => '9:00am-4:00pm',
        ];
        $keys_before = array_keys($intersoccer_test_wc_order_item_meta[$item_id]);

        $this->assertTrue(intersoccer_renormalize_order_item_event_meta($item_id));

        $after = $intersoccer_test_wc_order_item_meta[$item_id];
        $this->assertEqualsCanonicalizing($keys_before, array_keys($after));
        $this->assertSame('Course', $after["Type d'activité"]);
        $this->assertSame('5-13y-full-day', $after["Groupe d'âge"]);
        $this->assertArrayNotHasKey('age_group', $after);
        $this->assertArrayNotHasKey('Age Group', $after);
    }

    /**
     * Regression: RR #104 — admin language repair rewrites DE event values on existing keys only.
     */
    public function test_renormalize_order_item_event_meta_rewrites_german_values_on_existing_keys(): void {
        if (!function_exists('intersoccer_renormalize_order_item_event_meta')) {
            $this->markTestSkipped('intersoccer_renormalize_order_item_event_meta not found');
        }

        global $intersoccer_test_wc_order_item_meta;

        $this->mockEmptyRosterRowForRenormalize();

        $item_id = 88106;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'Buchungstyp' => 'Einzelne Tage',
            'Jours sélectionnés' => 'Monday, Wednesday',
            "Type d'activité" => 'Camp',
        ];
        $keys_before = array_keys($intersoccer_test_wc_order_item_meta[$item_id]);

        $this->assertTrue(intersoccer_renormalize_order_item_event_meta($item_id));

        $after = $intersoccer_test_wc_order_item_meta[$item_id];
        $this->assertEqualsCanonicalizing($keys_before, array_keys($after));
        $this->assertSame('Camp', $after["Type d'activité"]);
        $this->assertSame('Single Day(s)', $after['Buchungstyp']);
    }

    /**
     * Regression: RR #104 — repair must not add internal keys the line never had.
     */
    public function test_renormalize_order_item_event_meta_never_adds_missing_internal_keys(): void {
        if (!function_exists('intersoccer_renormalize_order_item_event_meta')) {
            $this->markTestSkipped('intersoccer_renormalize_order_item_event_meta not found');
        }

        global $intersoccer_test_wc_order_item_meta;

        $this->mockEmptyRosterRowForRenormalize();

        $item_id = 88107;
        $before = [
            "Groupe d'âge" => '5-13y (Full Day)',
            'Lieux InterSoccer' => 'Geneva Centre',
            "Type d'activité" => 'Camp',
        ];
        $intersoccer_test_wc_order_item_meta[$item_id] = $before;

        intersoccer_renormalize_order_item_event_meta($item_id);

        $after = $intersoccer_test_wc_order_item_meta[$item_id];
        $this->assertEqualsCanonicalizing(array_keys($before), array_keys($after));
        $this->assertArrayNotHasKey('age_group', $after);
        $this->assertArrayNotHasKey('intersoccer_venues', $after);
    }

    /**
     * Regression: RR #104 — duplicate DB meta rows are not merged during admin repair.
     */
    public function test_renormalize_order_item_event_meta_does_not_merge_duplicate_meta_rows(): void {
        if (!function_exists('intersoccer_renormalize_order_item_event_meta')) {
            $this->markTestSkipped('intersoccer_renormalize_order_item_event_meta not found');
        }

        global $intersoccer_test_wc_order_item_meta, $wpdb;

        $item_id = 88108;
        $activity_key = "Type d'activité";
        $row_ids = [5001, 5002];
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            '_meta_rows' => [
                ['id' => $row_ids[0], 'key' => $activity_key, 'value' => 'Cours'],
                ['id' => $row_ids[1], 'key' => $activity_key, 'value' => 'Cours'],
                ['id' => 5003, 'key' => 'Lieux InterSoccer', 'value' => 'Geneva Centre'],
            ],
            $activity_key => 'Cours',
            'Lieux InterSoccer' => 'Geneva Centre',
        ];

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_row')->andReturn([]);

        $this->assertTrue(intersoccer_renormalize_order_item_event_meta($item_id));

        $store = $intersoccer_test_wc_order_item_meta[$item_id];
        $this->assertArrayHasKey('_meta_rows', $store);
        $rows = $store['_meta_rows'];
        $this->assertCount(3, $rows);
        $this->assertSame([5001, 5002, 5003], array_column($rows, 'id'));

        $activity_rows = array_values(array_filter(
            $rows,
            static function ($row) use ($activity_key) {
                return is_array($row) && ($row['key'] ?? '') === $activity_key;
            }
        ));
        $this->assertCount(2, $activity_rows);
        foreach ($activity_rows as $row) {
            $this->assertSame('Course', $row['value']);
        }
    }

    /**
     * Regression: RR #104 — already-English lines perform no order-meta write.
     */
    public function test_renormalize_order_item_event_meta_reports_no_write_when_values_already_english(): void {
        if (!function_exists('intersoccer_renormalize_order_item_event_meta')) {
            $this->markTestSkipped('intersoccer_renormalize_order_item_event_meta not found');
        }

        global $intersoccer_test_wc_order_item_meta;

        $this->mockEmptyRosterRowForRenormalize();

        $item_id = 88109;
        $before = [
            'Activity Type'     => 'Camp',
            'Age Group'         => '5-13y-full-day',
            'Sites InterSoccer' => 'geneva-centre',
            'Camp Times'        => '900am-400pm',
        ];
        $intersoccer_test_wc_order_item_meta[$item_id] = $before;

        $this->assertFalse(intersoccer_renormalize_order_item_event_meta($item_id));
        $this->assertSame($before, $intersoccer_test_wc_order_item_meta[$item_id]);
    }

    /**
     * Regression: RR #104 — lines with no event meta perform no order-meta write.
     */
    public function test_renormalize_order_item_event_meta_skips_line_with_only_non_event_meta(): void {
        if (!function_exists('intersoccer_renormalize_order_item_event_meta')) {
            $this->markTestSkipped('intersoccer_renormalize_order_item_event_meta not found');
        }

        global $intersoccer_test_wc_order_item_meta;

        $this->mockEmptyRosterRowForRenormalize();

        $item_id = 88110;
        $before = [
            'Assigned Attendee'  => 'Test Child',
            'assigned_player_id' => 'player-uuid-1',
        ];
        $intersoccer_test_wc_order_item_meta[$item_id] = $before;

        $this->assertFalse(intersoccer_renormalize_order_item_event_meta($item_id));
        $this->assertSame($before, $intersoccer_test_wc_order_item_meta[$item_id]);
    }
}
