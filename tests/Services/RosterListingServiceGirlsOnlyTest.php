<?php
/**
 * RosterListingService girls-only listing tests
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\RosterListingService;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use Mockery;

class RosterListingServiceGirlsOnlyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        if (!function_exists('intersoccer_normalize_season_for_display')) {
            eval('function intersoccer_normalize_season_for_display($season) { return (string) $season; }');
        }
        if (!function_exists('sanitize_key')) {
            eval('function sanitize_key($key) { return strtolower(preg_replace("/[^a-z0-9_-]/", "", (string) $key)); }');
        }
        if (!function_exists('sanitize_text_field')) {
            eval('function sanitize_text_field($str) { return trim((string) $str); }');
        }
    }

    public function test_camp_listings_default_mixed_and_girls_only_page_use_expected_criteria() {
        $captured = [];
        $repository = Mockery::mock(RosterRepository::class);
        $repository->shouldReceive('where')
            ->andReturnUsing(function ($criteria) use (&$captured) {
                $captured[] = $criteria;
                return new RostersCollection([]);
            });

        $service = new RosterListingService(null, $repository);
        $service->getCampListings([], [], false);
        $service->getGirlsOnlyListings([]);

        $this->assertCount(2, $captured);
        $this->assertSame(0, $captured[0]['girls_only']);
        $this->assertSame(1, $captured[1]['girls_only']);
    }

    public function test_camp_listings_girls_only_mode_all_omits_girls_only_criterion() {
        $captured = [];
        $repository = Mockery::mock(RosterRepository::class);
        $repository->shouldReceive('where')
            ->andReturnUsing(function ($criteria) use (&$captured) {
                $captured[] = $criteria;
                return new RostersCollection([]);
            });

        $service = new RosterListingService(null, $repository);
        $service->getCampListings(['girls_only_mode' => 'all'], [], false);

        $this->assertCount(1, $captured);
        $this->assertArrayNotHasKey('girls_only', $captured[0]);
    }

    public function test_camp_listings_girls_only_mode_girls_only_filters_camps_to_one() {
        $captured = [];
        $repository = Mockery::mock(RosterRepository::class);
        $repository->shouldReceive('where')
            ->andReturnUsing(function ($criteria) use (&$captured) {
                $captured[] = $criteria;
                return new RostersCollection([]);
            });

        $service = new RosterListingService(null, $repository);
        $service->getCampListings(['girls_only_mode' => 'girls_only'], [], false);

        $this->assertCount(1, $captured);
        $this->assertSame(1, $captured[0]['girls_only']);
    }

    public function test_course_listings_girls_only_mode_girls_only_filters_courses_to_one() {
        $captured = [];
        $repository = Mockery::mock(RosterRepository::class);
        $repository->shouldReceive('where')
            ->andReturnUsing(function ($criteria) use (&$captured) {
                $captured[] = $criteria;
                return new RostersCollection([]);
            });

        $service = new RosterListingService(null, $repository);
        $service->getCourseListings(['girls_only_mode' => 'girls_only'], [], false);

        $this->assertGreaterThanOrEqual(1, count($captured));
        foreach ($captured as $criteria) {
            $this->assertSame(1, $criteria['girls_only']);
        }
    }

    public function test_aggregate_camp_groups_merges_girls_only_flag_from_rows() {
        $repository = Mockery::mock(RosterRepository::class);
        $service = new RosterListingService(null, $repository);

        $signature = 'camp-sig-abc123';
        $rosters = [
            [
                'event_signature' => $signature,
                'order_item_id' => 101,
                'order_id' => 1,
                'venue' => 'Geneva',
                'camp_terms' => 'Summer Week 1',
                'season' => 'Summer 2026',
                'girls_only' => 0,
                'event_completed' => 0,
            ],
            [
                'event_signature' => $signature,
                'order_item_id' => 102,
                'order_id' => 2,
                'venue' => 'Geneva',
                'camp_terms' => 'Summer Week 1',
                'season' => 'Summer 2026',
                'girls_only' => 1,
                'event_completed' => 0,
            ],
        ];

        $method = new \ReflectionMethod(RosterListingService::class, 'aggregateCampGroups');
        $method->setAccessible(true);
        $result = $method->invoke($service, $rosters, false, false);
        $groups = array_values($result['groups']);

        $this->assertCount(1, $groups);
        $this->assertSame(1, $groups[0]['girls_only']);
    }

    // Regression: AUDIT-016 — Consolidated course cards merge mixed and girls-only registrations
    public function test_consolidated_courses_do_not_merge_mixed_and_girls_only()
    {
        if (!function_exists('intersoccer_consolidated_roster_group_key')) {
            require_once __DIR__ . '/../../includes/utils.php';
        }

        $base = [
            'product_id' => 100,
            'course_day' => 'Monday',
            'venue' => 'Geneva',
            'age_group' => '5-13y',
            'times' => '14:00-16:00',
            'season' => 'Autumn 2026',
        ];

        $mixed_key = intersoccer_consolidated_roster_group_key($base + ['girls_only' => 0], 'course');
        $girls_key = intersoccer_consolidated_roster_group_key($base + ['girls_only' => 1], 'course');

        $this->assertNotSame(
            $mixed_key,
            $girls_key,
            'Consolidated course groups with different girls_only flags must not share a bucket key'
        );
    }

    // Regression: AUDIT-017 — Girls-only course listing uses camp season filter logic
    public function test_girls_only_course_season_filter_uses_course_logic()
    {
        $repository = Mockery::mock(RosterRepository::class);
        $service = new RosterListingService(null, $repository);

        $courseGroup = [
            'season' => 'Course Season Label',
            'season_raw' => '2026-2027',
            'corrected_start_date' => '2026-09-01',
            'product_name' => 'Weekly Course',
            'venue' => 'Geneva',
            'course_day' => 'Monday',
            'camp_terms' => 'N/A',
        ];

        $campFilterCalled = false;
        $courseFilterCalled = false;

        if (!function_exists('intersoccer_roster_listing_season_filter_matches')) {
            eval('function intersoccer_roster_listing_season_filter_matches($row, $filter, $kind) {
                $GLOBALS["audit_017_last_kind"] = $kind;
                return true;
            }');
        }
        if (!function_exists('intersoccer_roster_course_season_filter_matches')) {
            eval('function intersoccer_roster_course_season_filter_matches($row, $filter) {
                $GLOBALS["audit_017_course_filter_called"] = true;
                return true;
            }');
        }

        $GLOBALS['audit_017_last_kind'] = null;
        $GLOBALS['audit_017_course_filter_called'] = false;

        $method = new \ReflectionMethod(RosterListingService::class, 'applyGirlsFilters');
        $method->setAccessible(true);
        $method->invoke($service, [$courseGroup], ['season' => '2026-2027', 'type' => 'courses']);

        $this->assertSame(
            'course',
            $GLOBALS['audit_017_last_kind'] ?? null,
            'Course girls-only listings should use course season filter logic, not camp'
        );
    }
}
