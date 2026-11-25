<?php
/**
 * Card total_players must match View Roster rows for merged listing groups.
 *
 * Regression: mixed season labels + FR/EN course_day duplicates on course cards.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\RosterDetailsService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use ReflectionMethod;

class RosterListingCardDetailsParityTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_roster_merge_course_groups_by_shared_facets')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }
    }

    public function test_listing_allowed_order_statuses_shared_helper_matches_database(): void {
        $this->assertTrue(function_exists('intersoccer_roster_listing_allowed_order_statuses'));
        $expected = ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'];
        $this->assertSame($expected, intersoccer_roster_listing_allowed_order_statuses());

        $db = new Database(new Logger());
        $this->assertSame($expected, $db->get_listing_allowed_order_statuses());

        $service = new RosterDetailsService(new Logger());
        $prop = new \ReflectionProperty(RosterDetailsService::class, 'allowed_statuses');
        $prop->setAccessible(true);
        $this->assertSame($expected, $prop->getValue($service));
    }

    public function test_merged_mixed_season_card_count_matches_details_order_item_ids_scope(): void {
        $this->assertTrue(function_exists('intersoccer_roster_merge_course_groups_by_shared_facets'));
        $this->assertTrue(function_exists('intersoccer_get_roster_details_url_for_listing_group'));

        $facets = [
            'venue' => 'Basel',
            'course_day' => 'Sunday',
            'age_group' => '3-12y',
            'times' => '1000-1130',
            'variation_ids' => [501 => 501],
            'merged_event_signatures' => [],
            'start_dates' => [],
            'end_dates' => [],
            'product_name' => 'Winter Courses',
        ];

        $winter_courses_ids = [];
        for ($i = 1; $i <= 16; $i++) {
            $winter_courses_ids[1000 + $i] = true;
        }
        $winter_2027_ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $winter_2027_ids[2000 + $i] = true;
        }

        $merged = intersoccer_roster_merge_course_groups_by_shared_facets([
            'sig_courses' => $facets + [
                'event_signature' => 'sig_winter_courses',
                'season' => 'Winter Courses',
                'season_raw' => 'Winter Courses',
                'order_item_ids' => $winter_courses_ids,
            ],
            'sig_2027' => $facets + [
                'event_signature' => 'sig_winter_2027',
                'season' => 'Winter 2027',
                'season_raw' => 'Winter 2027',
                'order_item_ids' => $winter_2027_ids,
            ],
        ]);

        $this->assertCount(1, $merged);
        $group = reset($merged);
        // Listing service converts id maps to lists before total_players / View Roster URL.
        $group['order_item_ids'] = array_map('intval', array_keys($group['order_item_ids']));
        $group['merged_event_signatures'] = array_keys($group['merged_event_signatures'] ?? []);
        $group['total_players'] = count($group['order_item_ids']);
        $this->assertSame(20, $group['total_players']);

        $url = intersoccer_get_roster_details_url_for_listing_group($group, 'courses');
        $this->assertStringContainsString('order_item_ids=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $url_ids = array_values(array_filter(array_map('intval', explode(',', (string) ($params['order_item_ids'] ?? '')))));
        sort($url_ids);
        $card_ids = $group['order_item_ids'];
        sort($card_ids);
        $this->assertSame($card_ids, $url_ids);
        $this->assertCount($group['total_players'], $url_ids);

        $service = new RosterDetailsService(new Logger());
        $method = new ReflectionMethod(RosterDetailsService::class, 'buildCriteria');
        $method->setAccessible(true);
        $criteria = $method->invoke($service, [
            'product_id' => 0,
            'variation_id' => 0,
            'variation_ids' => [],
            'order_item_ids' => $url_ids,
            'event_signature' => (string) ($params['event_signature'] ?? ''),
            'event_signatures' => !empty($params['event_signatures'])
                ? array_filter(array_map('trim', explode(',', (string) $params['event_signatures'])))
                : [],
            'camp_terms' => '',
            'course_day' => (string) ($params['course_day'] ?? 'Sunday'),
            'venue' => (string) ($params['venue'] ?? 'Basel'),
            'age_group' => (string) ($params['age_group'] ?? '3-12y'),
            'times' => (string) ($params['times'] ?? '1000-1130'),
            'season' => (string) ($params['season'] ?? 'Winter Courses'),
            'girls_only' => false,
        ], [
            'is_from_courses_page' => true,
            'is_from_camps_page' => false,
            'is_from_girls_only_page' => false,
            'is_from_tournaments_page' => false,
        ], false);

        $this->assertSame($url_ids, $criteria['order_item_id']);
        $this->assertArrayNotHasKey('season', $criteria);
        $this->assertArrayNotHasKey('venue', $criteria);
        $this->assertArrayNotHasKey('course_day', $criteria);
        $this->assertArrayNotHasKey('age_group', $criteria);
        $this->assertArrayNotHasKey('times', $criteria);
    }

    public function test_fr_dimanche_and_en_sunday_merge_to_one_course_card(): void {
        $this->assertTrue(function_exists('intersoccer_roster_merge_course_groups_by_shared_facets'));
        $this->assertTrue(function_exists('intersoccer_roster_course_groups_share_listing_facets'));

        $base = [
            'venue' => 'Lausanne',
            'age_group' => 'U10',
            'times' => '0900-1030',
            'variation_ids' => [777 => 777],
            'season' => 'Winter Courses',
            'season_raw' => 'Winter Courses',
            'merged_event_signatures' => [],
            'start_dates' => [],
            'end_dates' => [],
        ];

        $this->assertTrue(intersoccer_roster_course_groups_share_listing_facets(
            $base + ['course_day' => 'Dimanche'],
            $base + ['course_day' => 'Sunday']
        ));

        $merged = intersoccer_roster_merge_course_groups_by_shared_facets([
            'sig_fr' => $base + [
                'event_signature' => 'sig_fr_dimanche',
                'course_day' => 'Dimanche',
                'order_item_ids' => [11 => true, 12 => true],
            ],
            'sig_en' => $base + [
                'event_signature' => 'sig_en_sunday',
                'course_day' => 'Sunday',
                'order_item_ids' => [21 => true],
            ],
        ]);

        $this->assertCount(1, $merged);
        $group = reset($merged);
        $this->assertCount(3, $group['order_item_ids']);
        $this->assertSame(
            intersoccer_roster_course_group_facet_key($base + ['course_day' => 'Dimanche']),
            intersoccer_roster_course_group_facet_key($base + ['course_day' => 'Sunday'])
        );
    }

    public function test_old_style_details_criteria_still_apply_season_and_facets(): void {
        $service = new RosterDetailsService(new Logger());
        $method = new ReflectionMethod(RosterDetailsService::class, 'buildCriteria');
        $method->setAccessible(true);
        $criteria = $method->invoke($service, [
            'product_id' => 0,
            'variation_id' => 0,
            'variation_ids' => [],
            'order_item_ids' => [],
            'event_signature' => '',
            'event_signatures' => [],
            'camp_terms' => '',
            'course_day' => 'Sunday',
            'venue' => 'Geneva',
            'age_group' => 'U8',
            'times' => '1700-1800',
            'season' => 'Winter 2027',
            'girls_only' => false,
        ], [
            'is_from_courses_page' => true,
            'is_from_camps_page' => false,
            'is_from_girls_only_page' => false,
            'is_from_tournaments_page' => false,
        ], false);

        $this->assertSame('Winter 2027', $criteria['season']);
        $this->assertSame('Geneva', $criteria['venue']);
        $this->assertSame('Sunday', $criteria['course_day']);
        $this->assertSame('U8', $criteria['age_group']);
        $this->assertSame('1700-1800', $criteria['times']);
        $this->assertArrayNotHasKey('order_item_id', $criteria);
        $this->assertArrayNotHasKey('event_signature', $criteria);
    }

    public function test_signature_only_details_criteria_skip_season(): void {
        $service = new RosterDetailsService(new Logger());
        $method = new ReflectionMethod(RosterDetailsService::class, 'buildCriteria');
        $method->setAccessible(true);
        $criteria = $method->invoke($service, [
            'product_id' => 0,
            'variation_id' => 0,
            'variation_ids' => [],
            'order_item_ids' => [],
            'event_signature' => 'abcdef0123456789abcdef0123456789',
            'event_signatures' => [],
            'camp_terms' => '',
            'course_day' => 'Sunday',
            'venue' => 'Geneva',
            'age_group' => 'U8',
            'times' => '1700-1800',
            'season' => 'Winter Courses',
            'girls_only' => false,
        ], [
            'is_from_courses_page' => true,
            'is_from_camps_page' => false,
            'is_from_girls_only_page' => false,
            'is_from_tournaments_page' => false,
        ], false);

        $this->assertSame('abcdef0123456789abcdef0123456789', $criteria['event_signature']);
        $this->assertArrayNotHasKey('season', $criteria);
        $this->assertArrayNotHasKey('venue', $criteria);
        $this->assertArrayNotHasKey('course_day', $criteria);
    }

    public function test_course_day_for_storage_maps_fr_de_weekdays_without_terms(): void {
        $this->assertTrue(function_exists('intersoccer_roster_course_day_for_storage'));
        $this->assertSame('Sunday', intersoccer_roster_course_day_for_storage('Dimanche'));
        $this->assertSame('Sunday', intersoccer_roster_course_day_for_storage('Sonntag'));
        $this->assertSame('Monday', intersoccer_roster_course_day_for_storage('Lundi'));
        $this->assertSame('Monday', intersoccer_roster_course_day_for_storage('Montag'));
    }
}
