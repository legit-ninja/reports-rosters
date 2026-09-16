<?php
/**
 * Distressed variation IDs = Final Numbers Critical/Low (no second definition).
 */

use PHPUnit\Framework\TestCase;

class DistressedVariationIdsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        if (!defined('INTERSOCCER_TESTING')) {
            define('INTERSOCCER_TESTING', true);
        }
        $includes = dirname(__DIR__, 2) . '/includes';
        require_once $includes . '/final-reports-aggregation.php';
        if (file_exists($includes . '/utils.php')) {
            require_once $includes . '/utils.php';
        }
        $GLOBALS['intersoccer_reports_distressed_source_reports'] = null;
        unset($GLOBALS['intersoccer_reports_distressed_source_reports']);
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_reports_distressed_source_reports']);
        parent::tearDown();
    }

    public function test_urgency_bands_are_the_distressed_definition() {
        $this->assertSame('count-critical', intersoccer_reports_urgency_band(7));
        $this->assertSame('count-low', intersoccer_reports_urgency_band(20));
        $this->assertSame('count-good', intersoccer_reports_urgency_band(29));
        $this->assertSame('count-optimal', intersoccer_reports_urgency_band(30));
        $this->assertTrue(intersoccer_reports_is_urgent_band('count-critical'));
        $this->assertTrue(intersoccer_reports_is_urgent_band('count-low'));
        $this->assertFalse(intersoccer_reports_is_urgent_band('count-good'));
        $this->assertFalse(intersoccer_reports_is_urgent_band('count-optimal'));
    }

    public function test_api_returns_ids_only_for_critical_and_low_rows() {
        $critical_entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $critical_entries[] = [
                'order_item_id' => 100 + $i,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Vessy',
                'event_start_date' => '2026-10-05',
                'event_end_date' => '2026-10-09',
                'variation_id' => 101,
                'product_id' => 11,
                'is_buyclub' => false,
            ];
        }

        $low_entries = [];
        for ($i = 1; $i <= 15; $i++) {
            $low_entries[] = [
                'order_item_id' => 200 + $i,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Frontenex',
                'event_start_date' => '2026-10-12',
                'event_end_date' => '2026-10-16',
                'variation_id' => 202,
                'product_id' => 22,
                'is_buyclub' => false,
            ];
        }

        $good_entries = [];
        for ($i = 1; $i <= 25; $i++) {
            $good_entries[] = [
                'order_item_id' => 300 + $i,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Vaud',
                'venue' => 'Dorigny',
                'event_start_date' => '2026-10-19',
                'event_end_date' => '2026-10-23',
                'variation_id' => 303,
                'product_id' => 33,
                'is_buyclub' => false,
            ];
        }

        $camp = intersoccer_reports_build_camp_report_from_entries(
            array_merge($critical_entries, $low_entries, $good_entries),
            false,
            2026
        );

        $course = intersoccer_reports_build_course_report_from_entries([
            [
                'order_item_id' => 401,
                'roster_row_id' => 401,
                'canton' => 'Geneva',
                'venue' => 'Sismondi',
                'season' => 'Autumn',
                'course_day' => 'Monday',
                'variation_id' => 404,
                'product_id' => 44,
                'order_item_name' => 'Autumn Course Low',
                'event_start_date' => '2026-09-07',
                'is_buyclub' => false,
            ],
            [
                'order_item_id' => 501,
                'roster_row_id' => 501,
                'canton' => 'Geneva',
                'venue' => 'Sismondi',
                'season' => 'Autumn',
                'course_day' => 'Wednesday',
                'variation_id' => 505,
                'product_id' => 55,
                'order_item_name' => 'Autumn Course Optimal',
                'event_start_date' => '2026-09-09',
                'is_buyclub' => false,
            ],
        ]);

        // Inflate the Wednesday course to Optimal (30+) without changing identity.
        $course['Autumn']['Geneva']['505|Wednesday|Sismondi']['registrations'] = 30;

        $ids = intersoccer_reports_collect_distressed_ids_from_final_numbers($camp, $course);

        $this->assertSame([101, 202, 404], $ids['variation_ids']);
        $this->assertSame([11, 22, 44], $ids['product_ids']);
        $this->assertNotContains(303, $ids['variation_ids']);
        $this->assertNotContains(505, $ids['variation_ids']);
    }

    public function test_public_api_uses_same_urgency_helpers() {
        $camp = intersoccer_reports_build_camp_report_from_entries([
            [
                'order_item_id' => 1,
                'booking_type' => 'Full Week',
                'age_group' => '6-9y Full Day',
                'canton' => 'Geneva',
                'venue' => 'Vessy',
                'event_start_date' => '2026-10-05',
                'event_end_date' => '2026-10-09',
                'variation_id' => 777,
                'product_id' => 70,
                'is_buyclub' => false,
            ],
        ], false, 2026);

        $GLOBALS['intersoccer_reports_distressed_source_reports'] = [
            'camp' => $camp,
            'course' => [],
        ];

        $ids = intersoccer_reports_distressed_variation_ids('autumn', 2026);
        $this->assertSame(['variation_ids', 'product_ids'], array_keys($ids));
        $this->assertSame([777], $ids['variation_ids']);
        $this->assertSame([70], $ids['product_ids']);

        $metrics = null;
        foreach ($camp as $week => $cantons) {
            if ($week === '__player_registration_totals__' || !is_array($cantons)) {
                continue;
            }
            $metrics = $cantons['Geneva']['Vessy']['Full Day'] ?? null;
        }
        $this->assertIsArray($metrics);
        $this->assertTrue(intersoccer_reports_is_urgent_band(intersoccer_reports_camp_metrics_urgency_band($metrics)));
    }

    public function test_public_api_empty_without_source_reports() {
        $ids = intersoccer_reports_distressed_variation_ids('Autumn', 2026);
        $this->assertSame([], $ids['variation_ids']);
        $this->assertSame([], $ids['product_ids']);
    }

    public function test_season_slug_maps_to_final_numbers_filter() {
        $this->assertSame('Autumn', intersoccer_reports_normalize_season_type_filter('autumn'));
        $this->assertSame('Summer', intersoccer_reports_normalize_season_type_filter('summer'));
        $this->assertSame('Autumn', intersoccer_reports_normalize_season_type_filter('Autumn'));
        $this->assertNull(intersoccer_reports_normalize_season_type_filter(''));
    }
}
