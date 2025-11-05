<?php
/**
 * Player Test Helper
 * 
 * Helper methods for creating test player data
 */

namespace InterSoccer\ReportsRosters\Tests\Helpers;

use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;

class PlayerTestHelper {
    
    /**
     * Create a test player
     * 
     * @param array $data Player data
     * @return Player
     */
    public static function createPlayer(array $data = []) {
        $defaults = [
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'dob' => '2010-01-01',
            'gender' => 'male',
            'medical_conditions' => '',
            'dietary_needs' => '',
            'emergency_contact' => 'Jane Doe',
            'emergency_phone' => '+41 12 345 67 89'
        ];
        
        $data = array_merge($defaults, $data);
        
        return new Player($data);
    }
    
    /**
     * Create multiple test players
     * 
     * @param int $count Number of players to create
     * @param array $baseData Base data for all players
     * @return PlayersCollection
     */
    public static function createPlayers(int $count = 3, array $baseData = []) {
        $players = [];
        
        for ($i = 0; $i < $count; $i++) {
            $data = array_merge($baseData, [
                'player_index' => $i,
                'first_name' => 'Child' . ($i + 1),
            ]);
            
            $players[] = self::createPlayer($data);
        }
        
        return new PlayersCollection($players);
    }
    
    /**
     * Create a player with specific age
     * 
     * @param int $age Age in years
     * @param array $data Additional player data
     * @return Player
     */
    public static function createPlayerWithAge(int $age, array $data = []) {
        $dob = date('Y-m-d', strtotime("-{$age} years"));
        
        return self::createPlayer(array_merge($data, ['dob' => $dob]));
    }
    
    /**
     * Create player metadata array (for WordPress user meta)
     * 
     * @param array $data Player data
     * @return array
     */
    public static function createPlayerMetadata(array $data = []) {
        $player = self::createPlayer($data);
        
        return [
            'first_name' => $player->first_name,
            'last_name' => $player->last_name,
            'dob' => $player->dob,
            'gender' => $player->gender,
            'medical_conditions' => $player->medical_conditions,
            'dietary_needs' => $player->dietary_needs,
            'emergency_contact' => $player->emergency_contact,
            'emergency_phone' => $player->emergency_phone
        ];
    }
}

