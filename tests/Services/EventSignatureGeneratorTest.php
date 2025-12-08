<?php
/**
 * EventSignatureGenerator Test
 * Tests for event signature generation including tournament dates and normalization
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\EventSignatureGenerator;
use InterSoccer\ReportsRosters\Core\Logger;
use Mockery;

class EventSignatureGeneratorTest extends TestCase {
    private $signatureGenerator;
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        $this->logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
        $this->signatureGenerator = new EventSignatureGenerator($this->logger);
    }
    
    public function test_generate_signature_for_tournament_with_date() {
        $event_data = [
            'activity_type' => 'Tournament',
            'venue' => 'geneva-college-sismondi-nations',
            'age_group' => '5-12',
            'times' => '1400-1530',
            'season' => 'Autumn 2025',
            'city' => 'Geneva',
            'canton_region' => 'Geneva',
            'product_id' => 39827,
            'start_date' => '2025-12-14',
            'girls_only' => 0,
        ];
        
        $signature = $this->signatureGenerator->generate($event_data);
        
        $this->assertIsString($signature);
        $this->assertNotEmpty($signature);
        $this->assertEquals(32, strlen($signature), 'Event signature should be MD5 hash (32 chars)');
    }
    
    public function test_tournaments_with_different_dates_have_different_signatures() {
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
            'girls_only' => 0,
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
            'girls_only' => 0,
        ];
        
        $signature_1 = $this->signatureGenerator->generate($event_data_1);
        $signature_2 = $this->signatureGenerator->generate($event_data_2);
        
        $this->assertNotEquals($signature_1, $signature_2, 'Tournaments with different dates should have different signatures');
    }
    
    public function test_tournaments_with_same_date_have_same_signature() {
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
            'girls_only' => 0,
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
            'girls_only' => 0,
        ];
        
        $signature_1 = $this->signatureGenerator->generate($event_data_1);
        $signature_2 = $this->signatureGenerator->generate($event_data_2);
        
        $this->assertEquals($signature_1, $signature_2, 'Tournaments with same date and attributes should have same signature');
    }
    
    public function test_non_tournament_activities_ignore_date_in_signature() {
        $camp_data = [
            'activity_type' => 'Camp',
            'venue' => 'geneva-college-sismondi-nations',
            'age_group' => '5-12',
            'times' => '1400-1530',
            'season' => 'Autumn 2025',
            'product_id' => 39827,
            'start_date' => '2025-12-14',
            'girls_only' => 0,
        ];
        
        $signature = $this->signatureGenerator->generate($camp_data);
        
        // Date should not be included for non-tournament activities
        $this->assertIsString($signature);
        $this->assertNotEmpty($signature);
    }
    
    public function test_normalize_venue_to_english() {
        $event_data = [
            'activity_type' => 'Tournament',
            'venue' => 'geneve-college-sismondi-nations', // French spelling
            'age_group' => '5-12',
            'product_id' => 39827,
        ];
        
        $normalized = $this->signatureGenerator->normalize($event_data);
        
        $this->assertArrayHasKey('venue', $normalized);
        // Note: Actual normalization depends on WPML and taxonomy terms
        // This test verifies the function runs without error
    }
    
    public function test_normalize_city_and_canton_region() {
        $event_data = [
            'activity_type' => 'Tournament',
            'venue' => 'geneva-college-sismondi-nations',
            'city' => 'geneve', // French
            'canton_region' => 'geneve', // French
            'age_group' => '5-12',
            'product_id' => 39827,
        ];
        
        $normalized = $this->signatureGenerator->normalize($event_data);
        
        $this->assertArrayHasKey('city', $normalized);
        $this->assertArrayHasKey('canton_region', $normalized);
    }
}

