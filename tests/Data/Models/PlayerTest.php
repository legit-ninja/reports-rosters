<?php
/**
 * Player Model Test
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Models;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Data\Models\Player;

class PlayerTest extends TestCase {
    public function test_player_creation() {
        $player = new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'dob' => '2010-01-01',
            'gender' => 'male'
        ]);
        
        $this->assertEquals('John', $player->first_name);
        $this->assertEquals('Doe', $player->last_name);
    }
    
    public function test_get_full_name() {
        $player = new Player(['first_name' => 'John', 'last_name' => 'Doe']);
        $this->assertEquals('John Doe', $player->getFullName());
    }
    
    public function test_calculate_age() {
        $dob = date('Y-m-d', strtotime('-10 years'));
        $player = new Player(['dob' => $dob]);
        $age = $player->calculateAge();
        $this->assertEquals(10, $age);
    }
    
    public function test_is_eligible_for_age_group() {
        $player = new Player(['dob' => '2010-01-01']);
        $eligible = $player->isEligibleForAgeGroup('U14', '2024-01-01');
        $this->assertTrue($eligible);
    }
    
    public function test_gender_normalization() {
        $player = new Player(['gender' => 'Male']);
        $this->assertEquals('male', $player->gender);
    }
}

