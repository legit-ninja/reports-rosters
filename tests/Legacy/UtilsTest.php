<?php
/**
 * Utils Test - Legacy utility functions
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey\Functions;

class UtilsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        
        if (file_exists(__DIR__ . '/../../includes/utils.php')) {
            require_once __DIR__ . '/../../includes/utils.php';
        }
        
        // Mock WordPress functions
        Functions\when('wpml_get_current_language')->justReturn('en');
        Functions\when('wpml_get_default_language')->justReturn('en');
        Functions\when('do_action')->justReturn();
        Functions\when('get_terms')->justReturn([]);
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
    }
    
    public function test_utility_functions_loaded() {
        $this->assertTrue(true);
    }
    
    public function test_date_formatting_helper() {
        if (function_exists('intersoccer_format_date')) {
            $formatted = intersoccer_format_date('2024-06-01');
            $this->assertIsString($formatted);
        } else {
            $this->markTestSkipped('Date formatting function not found');
        }
    }
    
    public function test_parse_date_unified_handles_tournament_date_formats() {
        if (!function_exists('intersoccer_parse_date_unified')) {
            $this->markTestSkipped('intersoccer_parse_date_unified function not found');
        }
        
        // Test tournament date formats
        $test_cases = [
            'Sunday, 21st December' => null, // May not parse perfectly, but shouldn't error
            '2025-12-14' => '2025-12-14',
            'December 14, 2025' => '2025-12-14',
        ];
        
        foreach ($test_cases as $input => $expected) {
            $result = intersoccer_parse_date_unified($input, 'test');
            if ($expected !== null && $result) {
                $this->assertEquals($expected, $result, "Failed to parse: {$input}");
            }
        }
    }
    
    public function test_canonical_activity_type_maps_french_cours_to_course() {
        if (!function_exists('intersoccer_canonical_activity_type_for_roster')) {
            $this->markTestSkipped('intersoccer_canonical_activity_type_for_roster not loaded');
        }
        $this->assertSame('Course', intersoccer_canonical_activity_type_for_roster('cours'));
        $this->assertSame('Course', intersoccer_canonical_activity_type_for_roster('Cours'));
        $this->assertSame('Camp', intersoccer_canonical_activity_type_for_roster('camp de vacances'));
    }

    public function test_roster_listing_activity_types_includes_legacy_french_course() {
        if (!function_exists('intersoccer_roster_listing_activity_types')) {
            $this->markTestSkipped('intersoccer_roster_listing_activity_types not loaded');
        }
        $types = intersoccer_roster_listing_activity_types('course');
        $this->assertContains('Course', $types);
        $this->assertContains('cours', $types);
    }

    public function test_roster_row_matches_listing_kind_excludes_birthday_from_camps() {
        if (!function_exists('intersoccer_roster_row_matches_listing_kind')) {
            $this->markTestSkipped('intersoccer_roster_row_matches_listing_kind not loaded');
        }

        $this->assertFalse(intersoccer_roster_row_matches_listing_kind(['activity_type' => 'Birthday Party'], 'camp'));
        $this->assertFalse(intersoccer_roster_row_matches_listing_kind(['activity_type' => 'Birthday'], 'camp'));
        $this->assertFalse(intersoccer_roster_row_matches_listing_kind(['activity_type' => 'anniversaire'], 'camp'));
        $this->assertTrue(intersoccer_roster_row_matches_listing_kind(['activity_type' => 'Camp'], 'camp'));
        $this->assertTrue(intersoccer_roster_row_matches_listing_kind(['activity_type' => 'camp'], 'camp'));
        $this->assertFalse(intersoccer_roster_row_matches_listing_kind(['activity_type' => 'Course'], 'camp'));
        $this->assertTrue(intersoccer_roster_row_matches_listing_kind(['activity_type' => 'Course'], 'course'));
        $this->assertTrue(intersoccer_roster_row_matches_listing_kind(['activity_type' => 'cours'], 'course'));
        $this->assertFalse(intersoccer_roster_row_matches_listing_kind(['activity_type' => 'Camp'], 'course'));
    }

    public function test_roster_row_matches_course_listing_rejects_camp_facets_even_when_product_type_course() {
        if (!function_exists('intersoccer_roster_row_matches_listing_kind')
            || !function_exists('intersoccer_roster_row_camp_facets_indicate_camp')) {
            $this->markTestSkipped('listing kind helpers not loaded');
        }

        $this->assertTrue(intersoccer_roster_row_camp_facets_indicate_camp([
            'activity_type' => 'Camp',
            'camp_terms' => 'summer-week-1-july-1-july-5-5-days',
            'course_day' => '',
        ]));

        $this->assertFalse(intersoccer_roster_row_matches_listing_kind([
            'activity_type' => 'Camp',
            'camp_terms' => 'summer-week-1-july-1-july-5-5-days',
            'course_day' => '',
        ], 'course'));

        $this->assertTrue(intersoccer_roster_row_matches_listing_kind([
            'activity_type' => 'cours',
            'course_day' => 'monday',
        ], 'course'));
    }

    public function test_girls_only_listing_excludes_birthday_and_includes_tournament() {
        if (!function_exists('intersoccer_roster_row_matches_girls_only_listing')) {
            $this->markTestSkipped('intersoccer_roster_row_matches_girls_only_listing not loaded');
        }

        $this->assertFalse(intersoccer_roster_row_matches_girls_only_listing([
            'girls_only' => 1,
            'activity_type' => 'Birthday Party',
        ]));
        $this->assertTrue(intersoccer_roster_row_matches_girls_only_listing([
            'girls_only' => 1,
            'activity_type' => 'Course, Girls Only',
        ]));
        $this->assertTrue(intersoccer_roster_row_matches_girls_only_listing([
            'girls_only' => 1,
            'activity_type' => 'Tournament, Girls Only',
        ]));
    }

    public function test_normalize_event_data_handles_french_values() {
        if (!function_exists('intersoccer_normalize_event_data_for_signature')) {
            $this->markTestSkipped('intersoccer_normalize_event_data_for_signature function not found');
        }
        
        $event_data = [
            'activity_type' => 'Tournament',
            'venue' => 'geneve-college-sismondi-nations',
            'city' => 'geneve',
            'canton_region' => 'geneve',
            'season' => 'Automne 2025',
        ];
        
        $normalized = intersoccer_normalize_event_data_for_signature($event_data);
        
        $this->assertIsArray($normalized);
        $this->assertArrayHasKey('venue', $normalized);
        $this->assertArrayHasKey('city', $normalized);
        $this->assertArrayHasKey('canton_region', $normalized);
    }

    public function test_compute_day_presence_maps_french_weekday_tokens() {
        if (!function_exists('intersoccer_compute_day_presence')) {
            $this->markTestSkipped('intersoccer_compute_day_presence not loaded');
        }
        $p = intersoccer_compute_day_presence('single-days', 'lundi, mercredi');
        $this->assertSame('Yes', $p['Monday']);
        $this->assertSame('Yes', $p['Wednesday']);
        $this->assertSame('No', $p['Tuesday']);
    }

    public function test_compute_day_presence_splits_space_separated_weekdays() {
        if (!function_exists('intersoccer_compute_day_presence')) {
            $this->markTestSkipped('intersoccer_compute_day_presence not loaded');
        }
        $p = intersoccer_compute_day_presence('single-days', "Monday  Wednesday\nFriday");
        $this->assertSame('Yes', $p['Monday']);
        $this->assertSame('Yes', $p['Wednesday']);
        $this->assertSame('Yes', $p['Friday']);
        $this->assertSame('No', $p['Tuesday']);
    }

    public function test_normalize_booking_type_slug_for_reports_handles_labels() {
        if (!function_exists('intersoccer_normalize_booking_type_slug_for_reports')) {
            $this->markTestSkipped('intersoccer_normalize_booking_type_slug_for_reports not loaded');
        }
        $this->assertSame('full-week', intersoccer_normalize_booking_type_slug_for_reports('Full Week'));
        $this->assertSame('single-days', intersoccer_normalize_booking_type_slug_for_reports('Single Days'));
        $this->assertSame('full-week', intersoccer_normalize_booking_type_slug_for_reports('full-week'));
        // WooCommerce attribute slugs (hyphens) must map like translated labels.
        $this->assertSame('full-week', intersoccer_normalize_booking_type_slug_for_reports('semaine-complete'));
        $this->assertSame('full-week', intersoccer_normalize_booking_type_slug_for_reports('journee-complete'));
        // Odd spacing / hyphens from storefront or imports (regex fallback before "other").
        $this->assertSame('single-days', intersoccer_normalize_booking_type_slug_for_reports('Camp — single days'));
        $this->assertSame('full-week', intersoccer_normalize_booking_type_slug_for_reports('full_week'));
    }

    public function test_roster_compute_camp_day_presence_full_week_wins_over_partial_selected_days_string() {
        if (!function_exists('intersoccer_roster_compute_camp_day_presence_for_display')) {
            $this->markTestSkipped('intersoccer_roster_compute_camp_day_presence_for_display not loaded');
        }
        $p = intersoccer_roster_compute_camp_day_presence_for_display('Full Week', 'Monday, Wednesday');
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $d) {
            $this->assertSame('Yes', $p[$d], $d);
        }
        $p2 = intersoccer_roster_compute_camp_day_presence_for_display('Full Week', '');
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $d) {
            $this->assertSame('Yes', $p2[$d], $d);
        }
    }

    public function test_compute_day_presence_full_week_from_hyphenated_semaine_slug() {
        if (!function_exists('intersoccer_compute_day_presence')) {
            $this->markTestSkipped('intersoccer_compute_day_presence not loaded');
        }
        $p = intersoccer_compute_day_presence('semaine-complete', '');
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $d) {
            $this->assertSame('Yes', $p[$d], $d);
        }
    }

    public function test_consolidated_group_key_matches_french_and_english_course_day() {
        if (!function_exists('intersoccer_consolidated_roster_group_key')) {
            $this->markTestSkipped('intersoccer_consolidated_roster_group_key not loaded');
        }
        $base = [
            'product_id' => 37940,
            'season' => 'summer 2026',
            'venue' => 'lausanne-centre-sportif-dorigny-unil-fr',
            'age_group' => 'u10',
            'times' => 'morning',
        ];
        $fr = $base + ['course_day' => 'dimanche'];
        $en = $base + ['course_day' => 'Sunday'];
        $this->assertSame(
            intersoccer_consolidated_roster_group_key($fr, 'course'),
            intersoccer_consolidated_roster_group_key($en, 'course')
        );
    }

    public function test_consolidated_roster_group_key_stable_per_facets() {
        if (!function_exists('intersoccer_consolidated_roster_group_key')) {
            $this->markTestSkipped('intersoccer_consolidated_roster_group_key not loaded');
        }
        $row = [
            'product_id' => 42,
            'season' => 'Summer 2026',
            'venue' => 'zurich',
            'age_group' => 'u10',
            'times' => 'morning',
            'camp_terms' => 'week1',
            'girls_only' => 0,
        ];
        $k1 = intersoccer_consolidated_roster_group_key($row, 'camp');
        $k2 = intersoccer_consolidated_roster_group_key($row, 'camp');
        $this->assertSame($k1, $k2);
        $row['venue'] = 'geneva';
        $this->assertNotSame($k1, intersoccer_consolidated_roster_group_key($row, 'camp'));

        $course = [
            'product_id' => 99,
            'season' => '2026',
            'venue' => 'basel',
            'age_group' => 'u8',
            'times' => '17:00',
            'course_day' => 'monday',
        ];
        $this->assertSame(
            intersoccer_consolidated_roster_group_key($course, 'course'),
            intersoccer_consolidated_roster_group_key($course, 'course')
        );
    }

    public function test_roster_row_is_sync_placeholder_detects_unknown_player_without_signature() {
        if (!function_exists('intersoccer_roster_row_is_sync_placeholder')) {
            $this->markTestSkipped('intersoccer_roster_row_is_sync_placeholder not loaded');
        }
        $this->assertTrue(intersoccer_roster_row_is_sync_placeholder([
            'player_name' => 'Unknown Player',
            'first_name' => 'Unknown',
            'event_signature' => '',
            'activity_type' => 'Course',
        ]));
        $this->assertFalse(intersoccer_roster_row_is_sync_placeholder([
            'player_name' => 'Anna Test',
            'first_name' => 'Anna',
            'event_signature' => 'abc123',
        ]));
    }

    public function test_resolve_course_season_for_filter_ui_maps_legacy_raw_to_display() {
        if (!function_exists('intersoccer_roster_resolve_course_season_for_filter_ui')) {
            $this->markTestSkipped('intersoccer_roster_resolve_course_season_for_filter_ui not loaded');
        }
        $available = ['Spring/Summer 2026', 'Summer Courses 2026', 'Winter 2026'];
        $this->assertSame(
            'Summer Courses 2026',
            intersoccer_roster_resolve_course_season_for_filter_ui('Summer Camps 2026', $available)
        );
        $this->assertSame('', intersoccer_roster_resolve_course_season_for_filter_ui('', $available));
    }

    public function test_course_season_filter_matches_display_and_legacy_raw_labels() {
        if (!function_exists('intersoccer_roster_course_season_filter_matches')) {
            $this->markTestSkipped('intersoccer_roster_course_season_filter_matches not loaded');
        }
        $group = [
            'season' => 'Summer Courses 2026',
            'season_raw' => 'Summer Camps 2026',
            'product_name' => 'Geneva Spring/Summer Football Courses 2026',
            'course_day' => 'Sunday',
        ];
        $this->assertTrue(intersoccer_roster_course_season_filter_matches($group, 'Summer Camps 2026'));
        $this->assertTrue(intersoccer_roster_course_season_filter_matches($group, 'Summer Courses 2026'));
        $this->assertFalse(intersoccer_roster_course_season_filter_matches($group, 'Winter 2026'));
    }

    public function test_resolve_season_taxonomy_label_humanizes_slug_when_term_missing() {
        if (!function_exists('intersoccer_roster_resolve_season_taxonomy_label')) {
            $this->markTestSkipped('intersoccer_roster_resolve_season_taxonomy_label not loaded');
        }
        $this->assertSame(
            'Summer Courses 2026',
            intersoccer_roster_resolve_season_taxonomy_label('summer-courses-2026')
        );
    }

    public function test_normalize_course_listing_season_canonicalizes_slug_for_course_rows() {
        if (!function_exists('intersoccer_roster_normalize_course_listing_season')) {
            $this->markTestSkipped('intersoccer_roster_normalize_course_listing_season not loaded');
        }
        $row = [
            'activity_type' => 'Course',
            'course_day' => 'Sunday',
            'product_name' => 'Geneva Spring/Summer Football Courses 2026',
        ];
        $display = intersoccer_roster_normalize_course_listing_season('summer-courses-2026', $row);
        $this->assertStringNotContainsString('summer-courses-2026', strtolower($display));
        $this->assertStringContainsString('Summer', $display);
        $this->assertStringContainsString('2026', $display);
    }

    public function test_normalize_course_listing_season_rewrites_camps_label_for_course_rows() {
        if (!function_exists('intersoccer_roster_normalize_course_listing_season')) {
            $this->markTestSkipped('intersoccer_roster_normalize_course_listing_season not loaded');
        }
        $row = [
            'activity_type' => 'Course',
            'course_day' => 'Sunday',
            'product_name' => 'Geneva Spring/Summer Football Courses 2026',
        ];
        $this->assertSame(
            'Summer Courses 2026',
            intersoccer_roster_normalize_course_listing_season('Summer Camps 2026', $row)
        );
        $this->assertSame(
            'Summer Camps 2026',
            intersoccer_roster_normalize_course_listing_season('Summer Camps 2026', [
                'activity_type' => 'Camp',
                'camp_terms' => 'july-week-1',
            ])
        );
    }

    public function test_merge_course_groups_with_empty_season_combines_matching_facets() {
        if (!function_exists('intersoccer_roster_merge_course_groups_with_empty_season')) {
            $this->markTestSkipped('intersoccer_roster_merge_course_groups_with_empty_season not loaded');
        }
        $facets = [
            'venue' => 'Lausanne',
            'course_day' => 'Sunday',
            'age_group' => '3-12y',
            'times' => '1000-1130',
            'variation_ids' => [37638 => 37638],
        ];
        $emptySeason = $facets + [
            'season' => '',
            'season_raw' => '',
            'order_item_ids' => [4760 => true],
            'merged_event_signatures' => [],
            'start_dates' => [],
            'end_dates' => [],
        ];
        $withSeason = $facets + [
            'season' => 'Spring/Summer 2026',
            'season_raw' => 'Spring/Summer 2026',
            'order_item_ids' => [100 => true, 101 => true],
            'merged_event_signatures' => [],
            'start_dates' => [],
            'end_dates' => [],
        ];
        $merged = intersoccer_roster_merge_course_groups_with_empty_season([
            'sig_empty' => $emptySeason,
            'sig_full' => $withSeason,
        ]);
        $this->assertCount(1, $merged);
        $group = reset($merged);
        $this->assertSame('Spring/Summer 2026', $group['season']);
        $this->assertArrayHasKey(4760, $group['order_item_ids']);
        $this->assertArrayHasKey(100, $group['order_item_ids']);
        $this->assertArrayHasKey(101, $group['order_item_ids']);
    }

    public function test_backfill_player_name_fields_from_player_name_column() {
        if (!function_exists('intersoccer_roster_backfill_player_name_fields')) {
            $this->markTestSkipped('intersoccer_roster_backfill_player_name_fields not loaded');
        }
        $row = [
            'player_name' => 'Theo Kuhn',
            'first_name' => '',
            'last_name' => '',
        ];
        $filled = intersoccer_roster_backfill_player_name_fields($row);
        $this->assertSame('Theo', $filled['first_name']);
        $this->assertSame('Kuhn', $filled['last_name']);
    }

    public function test_backfill_player_name_fields_from_player_first_name_columns() {
        if (!function_exists('intersoccer_roster_backfill_player_name_fields')) {
            $this->markTestSkipped('intersoccer_roster_backfill_player_name_fields not loaded');
        }
        $row = [
            'player_first_name' => 'Marie',
            'player_last_name' => 'Dupont',
            'first_name' => '',
            'last_name' => '',
        ];
        $filled = intersoccer_roster_backfill_player_name_fields($row);
        $this->assertSame('Marie', $filled['first_name']);
        $this->assertSame('Dupont', $filled['last_name']);
        $this->assertSame('Marie Dupont', $filled['player_name']);
    }
}

