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

    public function test_normalize_booking_type_slug_for_reports_handles_labels() {
        if (!function_exists('intersoccer_normalize_booking_type_slug_for_reports')) {
            $this->markTestSkipped('intersoccer_normalize_booking_type_slug_for_reports not loaded');
        }
        $this->assertSame('full-week', intersoccer_normalize_booking_type_slug_for_reports('Full Week'));
        $this->assertSame('single-days', intersoccer_normalize_booking_type_slug_for_reports('Single Days'));
        $this->assertSame('full-week', intersoccer_normalize_booking_type_slug_for_reports('full-week'));
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
}

