<?php
/**
 * Underfilled programs ranking helpers.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class UnderfilledProgramsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        if (file_exists($includes . '/utils.php')) {
            require_once $includes . '/utils.php';
        }
        if (file_exists($includes . '/underfilled-programs.php')) {
            require_once $includes . '/underfilled-programs.php';
        }
    }

    public function test_underfilled_resolve_count_class_thresholds() {
        if (!function_exists('intersoccer_underfilled_resolve_count_class')) {
            $this->markTestSkipped('intersoccer_underfilled_resolve_count_class not loaded');
        }
        $this->assertSame('count-critical', intersoccer_underfilled_resolve_count_class(7));
        $this->assertSame('count-low', intersoccer_underfilled_resolve_count_class(20));
        $this->assertSame('count-good', intersoccer_underfilled_resolve_count_class(29));
        $this->assertSame('count-optimal', intersoccer_underfilled_resolve_count_class(30));
    }

    public function test_underfilled_build_rows_sorts_critical_first_and_filters_summer() {
        if (!function_exists('intersoccer_underfilled_build_rows')) {
            $this->markTestSkipped('intersoccer_underfilled_build_rows not loaded');
        }

        $groups = [
            [
                'product_name' => 'Busy Camp',
                'venue' => 'Geneva',
                'season' => 'Summer 2026',
                'camp_terms' => 'Week 1',
                'total_players' => 25,
                'event_signature' => 'sig-busy',
            ],
            [
                'product_name' => 'Empty Camp',
                'venue' => 'Zurich',
                'season' => 'Summer 2026',
                'camp_terms' => 'Week 2',
                'total_players' => 3,
                'event_signature' => 'sig-empty',
            ],
            [
                'product_name' => 'Winter Camp',
                'venue' => 'Bern',
                'season' => 'Winter 2026',
                'camp_terms' => 'Week 1',
                'total_players' => 2,
                'event_signature' => 'sig-winter',
            ],
        ];

        $rows = intersoccer_underfilled_build_rows($groups, 'Camp', '2026', 'Summer');

        $this->assertCount(2, $rows);
        $this->assertSame('Empty Camp', $rows[0]['product_name']);
        $this->assertSame('count-critical', $rows[0]['band']);
        $this->assertSame('Busy Camp', $rows[1]['product_name']);
        $this->assertSame('count-good', $rows[1]['band']);
    }

    public function test_underfilled_export_row_uses_camp_terms_or_course_day() {
        if (!function_exists('intersoccer_underfilled_export_row')) {
            $this->markTestSkipped('intersoccer_underfilled_export_row not loaded');
        }

        $camp = intersoccer_underfilled_export_row([
            'activity' => 'Camp',
            'product_name' => 'Camp A',
            'venue' => 'Geneva',
            'season' => 'Summer 2026',
            'camp_terms' => 'Week 1',
            'course_day' => 'Monday',
            'age_group' => 'U8',
            'total_players' => 4,
            'band' => 'count-critical',
        ]);
        $this->assertSame('Week 1', $camp[4]);
        $this->assertSame(4, $camp[6]);

        $course = intersoccer_underfilled_export_row([
            'activity' => 'Course',
            'product_name' => 'Course B',
            'venue' => 'Zurich',
            'season' => 'Autumn 2026',
            'camp_terms' => 'Week 1',
            'course_day' => 'Wednesday',
            'age_group' => 'U10',
            'total_players' => 12,
            'band' => 'count-low',
        ]);
        $this->assertSame('Wednesday', $course[4]);
        $this->assertSame(12, $course[6]);
    }
}
