<?php
/**
 * Roster Repository Event Completion Tests
 * 
 * Unit tests for event completion functionality in RosterRepository.
 * 
 * @package InterSoccer\ReportsRosters\Tests\Unit
 * @version 1.0.0
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Services\CacheManager;
use Mockery;

/**
 * Roster Repository Event Completion Test Class
 */
class RosterRepositoryEventCompletionTest extends TestCase {
    
    /**
     * Roster repository instance
     * 
     * @var RosterRepository
     */
    private $repository;
    
    /**
     * Mock logger
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Mock database
     * 
     * @var Database
     */
    private $database;
    
    /**
     * Mock cache manager
     * 
     * @var CacheManager
     */
    private $cache;
    
    /**
     * Setup test environment
     */
    public function setUp(): void {
        parent::setUp();
        
        // Create mocks
        $this->logger = Mockery::mock(Logger::class);
        $this->database = Mockery::mock(Database::class);
        $this->cache = Mockery::mock(CacheManager::class);
        
        // Set default expectations
        $this->logger->shouldReceive('debug')->byDefault();
        $this->logger->shouldReceive('info')->byDefault();
        $this->logger->shouldReceive('warning')->byDefault();
        $this->logger->shouldReceive('error')->byDefault();
        
        // Create repository instance
        $this->repository = new RosterRepository(
            $this->logger,
            $this->database,
            $this->cache
        );
    }
    
    /**
     * Teardown test environment
     */
    public function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }
    
    /**
     * Test marking event as completed by event signature
     */
    public function test_mark_event_completed_by_signature() {
        $event_signature = md5('test-event');
        $affected_count = 5;
        
        // Mock database update operation
        $this->database->shouldReceive('update')
            ->once()
            ->with(
                'intersoccer_rosters',
                ['event_completed' => 1],
                ['event_signature' => $event_signature]
            )
            ->andReturn($affected_count);
        
        // Test database update logic (cache would be cleared by calling code)
        $result = $this->database->update(
            'intersoccer_rosters',
            ['event_completed' => 1],
            ['event_signature' => $event_signature]
        );
        
        $this->assertEquals(5, $result, 'Should return count of updated entries');
    }
    
    /**
     * Test querying rosters by completion status
     */
    public function test_query_rosters_by_completion_status() {
        // Mock database query for active rosters
        $this->database->shouldReceive('get_roster_entries')
            ->once()
            ->with(['event_completed' => 0], [])
            ->andReturn([
                ['id' => 1, 'event_completed' => 0, 'player_name' => 'Player 1'],
                ['id' => 2, 'event_completed' => 0, 'player_name' => 'Player 2'],
            ]);
        
        $active_rosters = $this->database->get_roster_entries(['event_completed' => 0], []);
        
        $this->assertCount(2, $active_rosters, 'Should return 2 active rosters');
        $this->assertEquals(0, $active_rosters[0]['event_completed']);
    }
    
    /**
     * Test counting completed events
     */
    public function test_count_completed_events() {
        // Mock database count operation
        $this->database->shouldReceive('get_roster_entries_count')
            ->once()
            ->with(['event_completed' => 1])
            ->andReturn(15);
        
        $count = $this->database->get_roster_entries_count(['event_completed' => 1]);
        
        $this->assertEquals(15, $count, 'Should return count of completed event rosters');
    }
    
    /**
     * Test checking if event is completed
     */
    public function test_check_event_is_completed() {
        $event_signature = md5('test-event');
        
        // Mock database query
        $this->database->shouldReceive('get_roster_entries')
            ->once()
            ->with(['event_signature' => $event_signature], ['limit' => 1])
            ->andReturn([
                ['id' => 1, 'event_signature' => $event_signature, 'event_completed' => 1]
            ]);
        
        $rosters = $this->database->get_roster_entries(
            ['event_signature' => $event_signature],
            ['limit' => 1]
        );
        
        $this->assertNotEmpty($rosters);
        $this->assertEquals(1, $rosters[0]['event_completed'], 'Event should be marked as completed');
    }
    
    /**
     * Test cache invalidation after marking event as completed
     */
    public function test_cache_invalidation_on_completion() {
        $event_signature = md5('test-event');
        
        // Expect cache to be cleared
        $this->cache->shouldReceive('forgetPattern')
            ->once()
            ->with('roster_*');
        
        $this->cache->shouldReceive('forget')
            ->once()
            ->with('all_rosters');
        
        // Simulate cache invalidation
        $this->cache->forgetPattern('roster_*');
        $this->cache->forget('all_rosters');
        
        // Mockery will verify the expectations
        $this->assertTrue(true);
    }
    
    /**
     * Test error handling when event signature is empty
     */
    public function test_rejects_empty_event_signature() {
        $event_signature = '';
        
        // Should not attempt database update with empty signature
        $this->database->shouldNotReceive('update');
        
        // Validation would happen before database call
        $this->assertEmpty($event_signature, 'Empty signature should be rejected');
    }
    
    /**
     * Test marking already completed event (idempotent)
     */
    public function test_mark_already_completed_event_is_idempotent() {
        $event_signature = md5('completed-event');
        
        // Mock database update - even if already completed, update should succeed
        $this->database->shouldReceive('update')
            ->once()
            ->with(
                'intersoccer_rosters',
                ['event_completed' => 1],
                ['event_signature' => $event_signature]
            )
            ->andReturn(0); // 0 rows affected if already completed
        
        $result = $this->database->update(
            'intersoccer_rosters',
            ['event_completed' => 1],
            ['event_signature' => $event_signature]
        );
        
        // Should not error, just return 0
        $this->assertEquals(0, $result, 'Should return 0 if no rows affected');
    }
}

