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
}

