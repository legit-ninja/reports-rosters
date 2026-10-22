<?php
/**
 * Season and facet fallbacks from order line meta.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterOrderLineSeasonFacetTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        foreach (['utils.php', 'order-meta-keys.php'] as $file) {
            $path = dirname(__DIR__, 2) . '/includes/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    public function test_season_from_order_item_meta_wins_over_variation_attribute(): void {
        $item_id = 88001;
        $GLOBALS['intersoccer_test_wc_get_order_item_meta'] = static function ($id, $key, $single = true) use ($item_id) {
            if ((int) $id !== $item_id) {
                return $single ? '' : [];
            }
            $map = [
                'Season' => 'SYNTH Winter 2025',
                'pa_program-season' => 'synth-summer-2026',
                'pa_intersoccer-venues' => 'venue-synth-a',
                'pa_age-group' => '5-12y',
                'pa_course-day' => 'sunday',
                'pa_course-times' => '1000-1130',
            ];
            if ($key === '') {
                return $map;
            }
            return $map[$key] ?? ($single ? '' : []);
        };
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($vid) {
            return new class {
                public function get_attribute($tax) {
                    if ($tax === 'pa_program-season') {
                        return 'synth-summer-2026';
                    }
                    return '';
                }
            };
        };

        $row = intersoccer_roster_backfill_facets_from_variation([
            'variation_id' => 88002,
            'order_item_id' => $item_id,
            'season' => '',
        ]);

        $this->assertSame('SYNTH Winter 2025', $row['season']);
        unset($GLOBALS['intersoccer_test_wc_get_order_item_meta'], $GLOBALS['intersoccer_test_wc_get_product_callback']);
    }

    public function test_missing_variation_backfills_venue_times_course_day_age_from_order_meta(): void {
        $item_id = 88011;
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function () {
            return false;
        };
        $GLOBALS['intersoccer_test_wc_get_order_item_meta'] = static function ($id, $key, $single = true) use ($item_id) {
            if ((int) $id !== $item_id) {
                return $single ? '' : [];
            }
            $map = [
                'pa_intersoccer-venues' => 'venue-synth-b',
                'pa_age-group' => '8-12y',
                'pa_course-day' => 'monday',
                'pa_course-times' => '1400-1530',
            ];
            if ($key === '') {
                return $map;
            }
            return $map[$key] ?? ($single ? '' : []);
        };

        $row = intersoccer_roster_backfill_facets_from_variation([
            'variation_id' => 88012,
            'order_item_id' => $item_id,
            'venue' => '',
            'times' => '',
            'course_day' => '',
            'age_group' => '',
        ]);

        $this->assertSame('venue-synth-b', $row['venue']);
        $this->assertSame('8-12y', $row['age_group']);
        $this->assertSame('monday', $row['course_day']);
        $this->assertSame('1400-1530', $row['times']);
        unset($GLOBALS['intersoccer_test_wc_get_order_item_meta'], $GLOBALS['intersoccer_test_wc_get_product_callback']);
    }
}
