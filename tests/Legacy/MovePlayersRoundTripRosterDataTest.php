<?php
/**
 * Move then move-back roster column preservation (manual update + signature rebuild path used by AJAX).
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersRoundTripRosterDataTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private const ORDER_ID = 92001;
    private const ITEM_ID = 92011;
    private const PARENT_ID = 92000;
    private const SOURCE_VAR = 92010;
    private const TARGET_VAR = 92020;

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
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
        require_once dirname(__DIR__, 2) . '/includes/advanced.php';
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_emit_wp_hooks'],
            $GLOBALS['intersoccer_test_roster_store']
        );
        $this->resetMovePlayersTestGlobals();
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function baselineCourseRow(): array {
        return [
            'id' => 501,
            'order_item_id' => self::ITEM_ID,
            'order_id' => self::ORDER_ID,
            'variation_id' => self::SOURCE_VAR,
            'product_id' => self::PARENT_ID,
            'activity_type' => 'Course',
            'booking_type' => 'Full Term',
            'selected_days' => '',
            'days_selected' => '',
            'day_presence' => '',
            'city' => 'geneva',
            'canton_region' => 'Geneva',
            'event_signature' => 'baseline-sig-course',
            'product_name' => 'Course FR',
            'venue' => 'Venue A',
            'age_group' => '6-8',
            'girls_only' => 0,
        ];
    }

    private function installStatefulWorld(array $baseline): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_get_product_callback;

        $GLOBALS['intersoccer_test_roster_store'] = [
            self::ITEM_ID => $baseline,
        ];

        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = [
            'Activity Type' => 'Course',
            'City' => 'Geneva',
            'Canton' => 'Geneva',
            'Type de réservation' => 'Trimestre complet',
            '_intersoccer_canonical_booking_type' => 'full-term',
        ];

        $make_variation = static function (int $vid) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
            $v->shouldReceive('get_variation_attributes')->andReturn([]);
            $v->shouldReceive('get_name')->andReturn('Variation ' . $vid);
            $v->shouldReceive('get_attribute')->andReturn('');
            $v->shouldReceive('get_meta')->andReturn('');
            $v->shouldReceive('get_attributes')->andReturn([]);
            $v->shouldReceive('is_type')->with('variation')->andReturn(true);

            return $v;
        };

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_name')->andReturn('Parent');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($make_variation, $parent) {
            $id = (int) $id;
            if ($id === self::PARENT_ID) {
                return $parent;
            }
            if (in_array($id, [self::SOURCE_VAR, self::TARGET_VAR], true)) {
                return $make_variation($id);
            }

            return false;
        };

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
            if (strpos($sql, 'order_item_id') !== false) {
                foreach ($GLOBALS['intersoccer_test_roster_store'] as $row) {
                    if ((int) ($row['order_item_id'] ?? 0) === self::ITEM_ID) {
                        return $row;
                    }
                }
            }
            if (strpos($sql, ' WHERE id = ') !== false || strpos($sql, 'WHERE id =') !== false) {
                $row = $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID] ?? null;

                return $row;
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
    }

    /**
     * @param array<string,mixed> $baseline
     */
    private function runMoveBack(array $baseline): void {
        intersoccer_manual_update_roster_entry(self::ORDER_ID, self::ITEM_ID, self::TARGET_VAR);
        intersoccer_rebuild_event_signature_for_order_item(self::ITEM_ID);
        intersoccer_manual_update_roster_entry(self::ORDER_ID, self::ITEM_ID, self::SOURCE_VAR);
        intersoccer_rebuild_event_signature_for_order_item(self::ITEM_ID);
    }

    public function test_course_round_trip_preserves_booking_city_and_empty_day_fields(): void {
        $baseline = $this->baselineCourseRow();
        $this->installStatefulWorld($baseline);
        $this->runMoveBack($baseline);

        $after = $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID];
        foreach (['booking_type', 'selected_days', 'days_selected', 'day_presence', 'city', 'canton_region'] as $field) {
            $this->assertSame($baseline[$field], $after[$field], $field);
        }
        $this->assertSame(self::SOURCE_VAR, (int) $after['variation_id']);
    }

    public function test_camp_single_days_round_trip_keeps_selected_days_and_day_presence(): void {
        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_get_product_type_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'camp';
        };
        $intersoccer_test_get_product_type_callback = static function () {
            return 'camp';
        };

        $baseline = $this->baselineCourseRow();
        $baseline['activity_type'] = 'Camp';
        $baseline['booking_type'] = 'Single Day(s)';
        $baseline['selected_days'] = 'Monday, Wednesday';
        $baseline['days_selected'] = 'Monday, Wednesday';
        $baseline['day_presence'] = wp_json_encode([
            'Monday' => 'Yes',
            'Tuesday' => 'No',
            'Wednesday' => 'Yes',
            'Thursday' => 'No',
            'Friday' => 'No',
        ]);

        global $intersoccer_test_wc_order_item_meta;
        $this->installStatefulWorld($baseline);
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID]['Activity Type'] = 'Camp';
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID]['Days Selected'] = 'Monday, Wednesday';
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID]['_intersoccer_canonical_booking_type'] = 'single-days';
        unset($intersoccer_test_wc_order_item_meta[self::ITEM_ID]['Type de réservation']);

        $this->runMoveBack($baseline);
        $after = $GLOBALS['intersoccer_test_roster_store'][self::ITEM_ID];
        $this->assertSame($baseline['selected_days'], $after['selected_days']);
        $this->assertSame($baseline['days_selected'], $after['days_selected']);
        $decoded = json_decode((string) $after['day_presence'], true);
        $this->assertSame('Yes', $decoded['Monday'] ?? null);
        $this->assertSame('Yes', $decoded['Wednesday'] ?? null);
    }
}
