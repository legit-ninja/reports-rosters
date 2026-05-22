<?php
/**
 * Roster Model Test
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Models;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Data\Models\Roster;

class RosterTest extends TestCase {
    public function test_roster_creation() {
        $roster = new Roster([
            'order_id' => 1,
            'customer_id' => 1,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'activity_type' => 'Camp',
            'venue' => 'Zurich'
        ]);
        
        $this->assertEquals(1, $roster->order_id);
        $this->assertEquals('Camp', $roster->activity_type);
    }
    
    public function test_get_event_details() {
        $roster = new Roster(['event_details' => json_encode(['camp_times' => '9:00-16:00'])]);
        $details = $roster->getEventDetails();
        $this->assertIsArray($details);
        $this->assertEquals('9:00-16:00', $details['camp_times']);
    }
    
    public function test_conflicts_with_another_roster() {
        $roster1 = new Roster([
            'start_date' => '2024-06-01',
            'end_date' => '2024-06-07',
            'customer_id' => 1,
            'player_index' => 0
        ]);
        
        $roster2 = new Roster([
            'start_date' => '2024-06-05',
            'end_date' => '2024-06-10',
            'customer_id' => 1,
            'player_index' => 0
        ]);
        
        $this->assertTrue($roster1->conflictsWith($roster2));
    }

    public function test_skip_age_group_validation_allows_completed_order_repair(): void {
        $roster = new Roster([
            'order_id' => 1,
            'order_item_id' => 10,
            'product_id' => 100,
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'Noah',
            'last_name' => 'Test',
            'dob' => '2023-03-22',
            'gender' => 'male',
            'activity_type' => 'Course',
            'venue' => 'Zurich',
            'age_group' => '3-8y',
            'start_date' => '2026-03-04',
            'end_date' => '2026-07-08',
            'booking_type' => 'Full Term',
            'order_status' => 'completed',
        ]);
        $roster->setSkipAgeGroupValidation(true);
        $this->assertTrue($roster->validate());
    }
}

