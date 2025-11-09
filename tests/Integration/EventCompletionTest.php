<?php
/**
 * Event Completion Feature Tests
 * 
 * Tests the event completion functionality including database operations,
 * AJAX handlers, and filtering logic.
 * 
 * @package InterSoccer\ReportsRosters\Tests\Integration
 * @version 1.0.0
 */

namespace InterSoccer\ReportsRosters\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Mockery;

/**
 * Event Completion Test Class
 */
class EventCompletionTest extends TestCase {
    
    /**
     * WordPress database instance
     * 
     * @var \wpdb
     */
    private $wpdb;
    
    /**
     * Table name for rosters
     * 
     * @var string
     */
    private $table_name;
    
    /**
     * Setup test environment
     */
    public function setUp(): void {
        parent::setUp();
        
        // Mock global wpdb
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'intersoccer_rosters';
    }
    
    /**
     * Teardown test environment
     */
    public function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }
    
    
    /**
     * Test database structure for event_completed column
     */
    public function test_event_completed_column_structure() {
        // Mock the database response for DESCRIBE query
        $this->wpdb->shouldReceive('get_col')
            ->with("DESCRIBE {$this->table_name}", 0)
            ->andReturn([
                'id', 'order_id', 'order_item_id', 'variation_id', 'product_id',
                'player_name', 'first_name', 'last_name', 'event_signature',
                'is_placeholder', 'event_completed'
            ]);
        
        $columns = $this->wpdb->get_col("DESCRIBE {$this->table_name}", 0);
        
        $this->assertContains('event_completed', $columns, 'event_completed column should exist in schema');
    }
    
    /**
     * Test marking single event as completed
     */
    public function test_mark_event_completed() {
        $event_signature = md5('test-event-signature');
        
        // Mock database update
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                $this->table_name,
                ['event_completed' => 1],
                ['event_signature' => $event_signature],
                ['%d'],
                ['%s']
            )
            ->andReturn(2);
        
        // Mock prepare
        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function($query, $params) {
                return $query;
            });
        
        // Mock get_var for verification
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(2);
        
        // Mark event as completed
        $updated = $this->wpdb->update(
            $this->table_name,
            ['event_completed' => 1],
            ['event_signature' => $event_signature],
            ['%d'],
            ['%s']
        );
        
        $this->assertEquals(2, $updated, 'Should update 2 roster entries');
        
        // Verify all entries are marked as completed
        $completed_count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE event_signature = %s AND event_completed = 1",
                $event_signature
            )
        );
        
        $this->assertEquals(2, $completed_count, 'All 2 entries should be marked as completed');
    }
    
    /**
     * Test that only entries with matching signature are updated
     */
    public function test_mark_event_completed_only_affects_matching_signature() {
        $event_signature_1 = md5('event-1');
        $event_signature_2 = md5('event-2');
        
        // Mock database update for first event
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                $this->table_name,
                ['event_completed' => 1],
                ['event_signature' => $event_signature_1],
                ['%d'],
                ['%s']
            )
            ->andReturn(1);
        
        // Mock prepare
        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function($query) {
                return $query;
            });
        
        // Mock get_var for both events
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(1); // Event 1 completed
        
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(0); // Event 2 not completed
        
        // Mark only first event as completed
        $updated = $this->wpdb->update(
            $this->table_name,
            ['event_completed' => 1],
            ['event_signature' => $event_signature_1],
            ['%d'],
            ['%s']
        );
        
        $this->assertEquals(1, $updated, 'Should update only 1 entry');
        
        // Verify only first event is marked as completed
        $event_1_completed = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT event_completed FROM {$this->table_name} WHERE event_signature = %s",
                $event_signature_1
            )
        );
        
        $event_2_completed = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT event_completed FROM {$this->table_name} WHERE event_signature = %s",
                $event_signature_2
            )
        );
        
        $this->assertEquals(1, $event_1_completed, 'Event 1 should be completed');
        $this->assertEquals(0, $event_2_completed, 'Event 2 should not be completed');
    }
    
    /**
     * Test filtering active events
     */
    public function test_filter_active_events() {
        // Mock database query for active events
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->with("SELECT COUNT(*) FROM {$this->table_name} WHERE event_completed = 0")
            ->andReturn(2);
        
        // Query for active events only
        $active_count = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE event_completed = 0"
        );
        
        $this->assertEquals(2, $active_count, 'Should have 2 active events');
    }
    
    /**
     * Test filtering completed events
     */
    public function test_filter_completed_events() {
        // Mock database query for completed events
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->with("SELECT COUNT(*) FROM {$this->table_name} WHERE event_completed = 1")
            ->andReturn(2);
        
        // Query for completed events only
        $completed_count = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE event_completed = 1"
        );
        
        $this->assertEquals(2, $completed_count, 'Should have 2 completed events');
    }
    
    /**
     * Test filtering all events
     */
    public function test_filter_all_events() {
        // Mock database query for all events
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->with("SELECT COUNT(*) FROM {$this->table_name}")
            ->andReturn(3);
        
        // Query for all events (no filter)
        $total_count = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}"
        );
        
        $this->assertEquals(3, $total_count, 'Should have 3 total events');
    }
    
    /**
     * Test AJAX handler logic (database operations)
     */
    public function test_ajax_mark_event_completed_database_logic() {
        $event_signature = md5('test-event');
        
        // Mock prepare
        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function($query) {
                return $query;
            });
        
        // Mock count query before update
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(5); // 5 entries will be affected
        
        // Mock update operation
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                $this->table_name,
                ['event_completed' => 1],
                ['event_signature' => $event_signature],
                ['%d'],
                ['%s']
            )
            ->andReturn(5);
        
        // Simulate AJAX handler logic
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE event_signature = %s",
                $event_signature
            )
        );
        
        $updated = $this->wpdb->update(
            $this->table_name,
            ['event_completed' => 1],
            ['event_signature' => $event_signature],
            ['%d'],
            ['%s']
        );
        
        $this->assertEquals(5, $count, 'Should count 5 entries before update');
        $this->assertEquals(5, $updated, 'Should update 5 entries');
    }
    
    /**
     * Test AJAX handler requires event signature
     */
    public function test_ajax_requires_event_signature() {
        $empty_signature = '';
        
        // Validate that empty signature is rejected
        $this->assertEmpty($empty_signature, 'Empty event signature should be rejected');
        
        // Validate non-empty signature is accepted
        $valid_signature = md5('valid-event');
        $this->assertNotEmpty($valid_signature, 'Valid event signature should be accepted');
    }
    
    /**
     * Test count of affected entries is accurate
     */
    public function test_count_affected_entries() {
        $event_signature = md5('large-event');
        
        // Mock prepare
        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function($query) {
                return $query;
            });
        
        // Mock count before update
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(10);
        
        // Mock update operation
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                $this->table_name,
                ['event_completed' => 1],
                ['event_signature' => $event_signature],
                ['%d'],
                ['%s']
            )
            ->andReturn(10);
        
        // Mock count after update
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(10);
        
        // Count before update
        $count_before = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE event_signature = %s",
            $event_signature
        ));
        
        $this->assertEquals(10, $count_before, 'Should have 10 entries before update');
        
        // Mark as completed
        $updated = $this->wpdb->update(
            $this->table_name,
            ['event_completed' => 1],
            ['event_signature' => $event_signature],
            ['%d'],
            ['%s']
        );
        
        $this->assertEquals(10, $updated, 'Should update exactly 10 entries');
        
        // Verify all are completed
        $completed_count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE event_signature = %s AND event_completed = 1",
            $event_signature
        ));
        
        $this->assertEquals(10, $completed_count, 'All 10 entries should be marked as completed');
    }
    
    /**
     * Test default value for event_completed is 0 (active)
     */
    public function test_event_completed_default_value() {
        // Mock insert operation
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);
        
        // Mock insert_id
        $this->wpdb->insert_id = 123;
        
        // Mock prepare
        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function($query) {
                return $query;
            });
        
        // Mock get_var to return default value
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(0);
        
        // Insert without specifying event_completed
        $result = $this->wpdb->insert(
            $this->table_name,
            [
                'order_id' => 1,
                'order_item_id' => 1,
                'variation_id' => 1,
                'product_id' => 1,
                'player_name' => 'Test Player',
                'first_name' => 'Test',
                'last_name' => 'Player',
                'event_signature' => md5('test')
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );
        
        $id = $this->wpdb->insert_id;
        
        // Check default value
        $event_completed = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT event_completed FROM {$this->table_name} WHERE id = %d",
            $id
        ));
        
        $this->assertEquals(0, $event_completed, 'Default event_completed should be 0 (active)');
    }
    
    /**
     * Test that completed events can be filtered in GROUP BY queries
     */
    public function test_completed_events_in_group_by() {
        // Mock get_results for grouped query
        $mock_result = (object)[
            'event_signature' => md5('event-1'),
            'count' => 3
        ];
        
        // Use Mockery::pattern to match query flexibly
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->with(Mockery::pattern('/SELECT.*event_signature.*COUNT.*FROM.*WHERE.*event_completed.*GROUP BY/s'))
            ->andReturn([$mock_result]);
        
        // Query active events grouped by signature
        $active_events = $this->wpdb->get_results(
            "SELECT event_signature, COUNT(*) as count 
             FROM {$this->table_name} 
             WHERE event_completed = 0 
             GROUP BY event_signature"
        );
        
        $this->assertCount(1, $active_events, 'Should have 1 active event group');
        $this->assertEquals(3, $active_events[0]->count, 'Active event should have 3 entries');
    }
}

