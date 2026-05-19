<?php
/**
 * Event Signature Normalization Test
 * Tests for legacy event signature generation and normalization functions
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey\Functions;

class EventSignatureNormalizationTest extends TestCase {
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
    
    public function test_generate_event_signature_includes_tournament_date() {
        if (!function_exists('intersoccer_generate_event_signature')) {
            $this->markTestSkipped('intersoccer_generate_event_signature function not found');
        }
        
        $event_data_1 = [
            'activity_type' => 'Tournament',
            'venue' => 'geneva-college-sismondi-nations',
            'age_group' => '5-12',
            'times' => '1400-1530',
            'season' => 'Autumn 2025',
            'city' => 'Geneva',
            'canton_region' => 'Geneva',
            'product_id' => 39827,
            'start_date' => '2025-12-14',
        ];
        
        $event_data_2 = [
            'activity_type' => 'Tournament',
            'venue' => 'geneva-college-sismondi-nations',
            'age_group' => '5-12',
            'times' => '1400-1530',
            'season' => 'Autumn 2025',
            'city' => 'Geneva',
            'canton_region' => 'Geneva',
            'product_id' => 39827,
            'start_date' => '2025-12-21', // Different date
        ];
        
        $signature_1 = intersoccer_generate_event_signature($event_data_1);
        $signature_2 = intersoccer_generate_event_signature($event_data_2);
        
        $this->assertIsString($signature_1);
        $this->assertIsString($signature_2);
        $this->assertNotEmpty($signature_1);
        $this->assertNotEmpty($signature_2);
        $this->assertNotEquals($signature_1, $signature_2, 'Tournaments with different dates should have different signatures');
    }
    
    public function test_generate_event_signature_same_date_same_signature() {
        if (!function_exists('intersoccer_generate_event_signature')) {
            $this->markTestSkipped('intersoccer_generate_event_signature function not found');
        }
        
        $event_data_1 = [
            'activity_type' => 'Tournament',
            'venue' => 'geneva-college-sismondi-nations',
            'age_group' => '5-12',
            'times' => '1400-1530',
            'season' => 'Autumn 2025',
            'city' => 'Geneva',
            'canton_region' => 'Geneva',
            'product_id' => 39827,
            'start_date' => '2025-12-14',
        ];
        
        $event_data_2 = [
            'activity_type' => 'Tournament',
            'venue' => 'geneva-college-sismondi-nations',
            'age_group' => '5-12',
            'times' => '1400-1530',
            'season' => 'Autumn 2025',
            'city' => 'Geneva',
            'canton_region' => 'Geneva',
            'product_id' => 39827,
            'start_date' => '2025-12-14', // Same date
        ];
        
        $signature_1 = intersoccer_generate_event_signature($event_data_1);
        $signature_2 = intersoccer_generate_event_signature($event_data_2);
        
        $this->assertEquals($signature_1, $signature_2, 'Tournaments with same date and attributes should have same signature');
    }
    
    public function test_normalize_event_data_for_signature() {
        if (!function_exists('intersoccer_normalize_event_data_for_signature')) {
            $this->markTestSkipped('intersoccer_normalize_event_data_for_signature function not found');
        }
        
        $event_data = [
            'activity_type' => 'Tournament',
            'venue' => 'geneve-college-sismondi-nations', // French
            'age_group' => '5-12',
            'city' => 'geneve', // French
            'canton_region' => 'geneve', // French
            'season' => 'Automne 2025', // French
        ];
        
        $normalized = intersoccer_normalize_event_data_for_signature($event_data);
        
        $this->assertIsArray($normalized);
        $this->assertArrayHasKey('venue', $normalized);
        $this->assertArrayHasKey('city', $normalized);
        $this->assertArrayHasKey('canton_region', $normalized);
        $this->assertArrayHasKey('season', $normalized);
    }
    
    public function test_rebuild_event_signatures_updates_stored_values() {
        if (!function_exists('intersoccer_rebuild_event_signatures')) {
            $this->markTestSkipped('intersoccer_rebuild_event_signatures function not found');
        }
        
        global $wpdb;
        
        // Mock database operations
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $mock_record = [
            'id' => 1,
            'activity_type' => 'Tournament',
            'venue' => 'geneve-college-sismondi-nations',
            'age_group' => '5-12',
            'city' => 'geneve',
            'canton_region' => 'geneve',
            'product_id' => 39827,
            'start_date' => '2025-12-14',
        ];
        
        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([$mock_record]);
        
        $wpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);
        
        $result = intersoccer_rebuild_event_signatures();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('updated', $result);
    }

    public function test_rebuild_event_signature_for_order_item_persists_normalized_facets() {
        if (!function_exists('intersoccer_rebuild_event_signature_for_order_item')) {
            $this->markTestSkipped('intersoccer_rebuild_event_signature_for_order_item not found');
        }

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $mock_record = [
            'id' => 5,
            'activity_type' => 'Camp',
            'venue' => 'Genève Centre',
            'age_group' => "5-13a (Journée complète)",
            'camp_terms' => 'Été Semaine 1',
            'course_day' => '',
            'times' => '9:00-16:00',
            'season' => 'Été 2025',
            'girls_only' => 0,
            'city' => '',
            'canton_region' => '',
            'product_id' => 100,
            'product_name' => 'Camp FR',
            'start_date' => '2025-07-07',
        ];

        $wpdb->shouldReceive('get_row')->once()->andReturn($mock_record);

        $captured_update = null;
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $data) use (&$captured_update) {
                $captured_update = $data;
                return is_array($data)
                    && isset($data['event_signature'])
                    && isset($data['venue'])
                    && isset($data['season']);
            })
            ->andReturn(1);

        \Brain\Monkey\Functions\expect('intersoccer_normalize_roster_facets_for_storage')
            ->once()
            ->andReturn([
                'venue' => 'Geneva Centre',
                'age_group' => '5-13y (Full Day)',
                'camp_terms' => 'Summer Week 1',
                'course_day' => '',
                'times' => '9:00am-4:00pm',
                'season' => 'Summer 2025',
                'activity_type' => 'Camp',
                'city' => '',
                'canton_region' => '',
                'girls_only' => 0,
                'product_id' => 100,
                'start_date' => '2025-07-07',
            ]);

        \Brain\Monkey\Functions\expect('intersoccer_build_roster_facet_db_update')
            ->once()
            ->andReturn([
                'event_signature' => 'sig123',
                'venue' => 'Geneva Centre',
                'season' => 'Summer 2025',
                'product_name' => 'Summer Camp EN',
            ]);

        \Brain\Monkey\Functions\when('intersoccer_get_english_product_name')->justReturn('Summer Camp EN');

        $ok = intersoccer_rebuild_event_signature_for_order_item(42);
        $this->assertTrue($ok);
        $this->assertSame('Geneva Centre', $captured_update['venue']);
        $this->assertSame('Summer 2025', $captured_update['season']);
    }
}

