<?php
/**
 * RR #175 round 4 — roster row refreshes English session facets after a move.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersRosterRefreshAfterMoveTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private const ORDER_ID = 98401;
    private const ITEM_ID = 98411;
    private const PARENT_ID = 98400;
    private const SOURCE_VAR = 98410;
    private const TARGET_VAR = 98420;

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
        require_once dirname(__DIR__) . '/stubs/course-meta-stub.php';
        require_once dirname(__DIR__, 2) . '/includes/advanced.php';

        $GLOBALS['intersoccer_test_terms_by_slug'] = [
            'pa_course-times' => [
                '0930-1100' => (object) ['term_id' => 98401, 'slug' => '0930-1100', 'name' => '09:30-11:00'],
                '1530-1700' => (object) ['term_id' => 98402, 'slug' => '1530-1700', 'name' => '15:30-17:00'],
            ],
            'pa_course-day' => [
                'monday' => (object) ['term_id' => 98403, 'slug' => 'monday', 'name' => 'Monday'],
                'wednesday' => (object) ['term_id' => 98404, 'slug' => 'wednesday', 'name' => 'Wednesday'],
            ],
            'pa_canton-region' => [
                'zurich' => (object) ['term_id' => 98405, 'slug' => 'zurich', 'name' => 'Zurich'],
                'vaud' => (object) ['term_id' => 98406, 'slug' => 'vaud', 'name' => 'Vaud'],
            ],
            'pa_intersoccer-venues' => [
                'venue-a' => (object) ['term_id' => 98407, 'slug' => 'venue-a', 'name' => 'Venue A'],
                'venue-b' => (object) ['term_id' => 98408, 'slug' => 'venue-b', 'name' => 'Venue B'],
            ],
            'pa_age-group' => [
                '6-8y' => (object) ['term_id' => 98409, 'slug' => '6-8y', 'name' => '6-8y'],
            ],
            'pa_city' => [
                'lausanne' => (object) ['term_id' => 98411, 'slug' => 'lausanne', 'name' => 'Lausanne'],
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

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_roster_store'],
            $GLOBALS['intersoccer_test_get_course_meta_callback'],
            $GLOBALS['intersoccer_test_course_meta_by_variation']
        );
        $this->resetMovePlayersTestGlobals();
        parent::tearDown();
    }

    private function installWorld(): void {
        global $wpdb, $intersoccer_test_wc_get_product_callback;

        $GLOBALS['intersoccer_test_roster_store'] = [
            self::ITEM_ID => [
                'id' => 601,
                'order_item_id' => self::ITEM_ID,
                'order_id' => self::ORDER_ID,
                'variation_id' => self::SOURCE_VAR,
                'product_id' => self::PARENT_ID,
                'activity_type' => 'Course',
                'venue' => 'Venue A',
                'age_group' => '6-8y',
                'course_day' => 'Monday',
                'times' => '09:30-11:00',
                'canton_region' => 'Zurich',
                'season' => 'Autumn 2025',
                'start_date' => '2025-09-01',
                'end_date' => '2025-12-15',
                'city' => '',
                'camp_terms' => '',
                'girls_only' => 0,
                'product_name' => 'Course A',
            ],
        ];

        $make_variation = static function (int $vid, array $attrs) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
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
            $v->shouldReceive('get_name')->andReturn('Variation ' . $vid);
            $v->shouldReceive('get_meta')->andReturn('');
            $v->shouldReceive('get_attributes')->andReturn([]);
            $v->shouldReceive('is_type')->with('variation')->andReturn(true);

            return $v;
        };

        $source_attrs = [
            'attribute_pa_intersoccer-venues' => 'venue-a',
            'attribute_pa_course-day' => 'monday',
            'attribute_pa_course-times' => '0930-1100',
            'attribute_pa_canton-region' => 'zurich',
            'attribute_pa_age-group' => '6-8y',
            'attribute_pa_girls-only' => 'no',
        ];
        $target_attrs = [
            'attribute_pa_intersoccer-venues' => 'venue-b',
            'attribute_pa_course-day' => 'wednesday',
            'attribute_pa_course-times' => '1530-1700',
            'attribute_pa_canton-region' => 'vaud',
            'attribute_pa_age-group' => '6-8y',
            'attribute_pa_city' => 'lausanne',
            'attribute_pa_girls-only' => 'girls-only',
        ];

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_name')->andReturn('Parent course');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

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

        $GLOBALS['intersoccer_test_course_meta_by_variation'] = [
            self::TARGET_VAR => [
                '_course_start_date' => '2026-09-01',
                '_end_date' => '2026-12-20',
            ],
        ];

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return (string) $query;
        });
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_var')->andReturn('0');
        $wpdb->shouldReceive('query')->andReturn(true);
        $wpdb->shouldReceive('get_row')->andReturnUsing(static function ($sql) {
            if (!is_string($sql) || strpos($sql, 'intersoccer_rosters') === false) {
                return null;
            }
            foreach ($GLOBALS['intersoccer_test_roster_store'] as $row) {
                if ((int) ($row['order_item_id'] ?? 0) === self::ITEM_ID) {
                    return $row;
                }
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

            return 0;
        });

        $GLOBALS['intersoccer_test_get_english_product_name_callback'] = static function ($name, $product_id) {
            return 'Course B English';
        };

        $GLOBALS['intersoccer_test_get_product_season_callback'] = static function ($parent_id) {
            if ((int) $parent_id === self::PARENT_ID) {
                return 'Autumn 2026';
            }

            return '';
        };
    }

    public function test_rr175_r4_move_refreshes_roster_session_facets_from_destination(): void {
        $this->installWorld();

        intersoccer_manual_update_roster_entry(self::ORDER_ID, self::ITEM_ID, self::TARGET_VAR);

        $row = $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID];
        $this->assertSame(self::TARGET_VAR, (int) $row['variation_id']);
        $this->assertSame('15:30-17:00', $row['times']);
        $this->assertSame('Wednesday', $row['course_day']);
        $this->assertSame('Vaud', $row['canton_region']);
        $this->assertSame('Venue B', $row['venue']);
        $this->assertSame('6-8y', $row['age_group']);
        $this->assertSame('', $row['camp_terms']);
        $this->assertSame('Lausanne', $row['city']);
        $this->assertSame('Autumn 2026', $row['season']);
        $this->assertSame('2026-09-01', $row['start_date']);
        $this->assertSame('2026-12-20', $row['end_date']);
        $this->assertSame(1, (int) $row['girls_only']);
        $this->assertSame('Course, Girls Only', $row['activity_type']);
    }
}
