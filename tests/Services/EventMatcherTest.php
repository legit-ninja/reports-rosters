<?php
/**
 * EventMatcher Test
 * Tests for the EventMatcher service
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Core\Logger;
use Mockery;

class EventMatcherTest extends TestCase {
    private $eventMatcher;
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        $this->logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
        $this->eventMatcher = new EventMatcher($this->logger);
    }
    
    public function test_event_matcher_initialization() {
        $this->assertInstanceOf(EventMatcher::class, $this->eventMatcher);
    }
    
    public function test_generate_event_signature() {
        $event_data = [
            'activity_type' => 'Camp',
            'venue' => 'Zurich',
            'start_date' => '2024-06-01',
            'age_group' => 'U10'
        ];
        
        $signature = $this->eventMatcher->generate_signature($event_data);
        
        $this->assertIsString($signature);
        $this->assertNotEmpty($signature);
    }
    
    public function test_match_event_by_signature() {
        $event1 = ['activity_type' => 'Camp', 'venue' => 'Zurich', 'start_date' => '2024-06-01'];
        $event2 = ['activity_type' => 'Camp', 'venue' => 'Zurich', 'start_date' => '2024-06-01'];
        
        $result = $this->eventMatcher->matches($event1, $event2);
        
        $this->assertTrue($result);
    }
}

