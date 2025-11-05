<?php
/**
 * PlayersCollection Test
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Collections;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use Mockery;

class PlayersCollectionTest extends TestCase {
    public function test_collection_creation() {
        $collection = new PlayersCollection();
        $this->assertInstanceOf(PlayersCollection::class, $collection);
    }
    
    public function test_add_player() {
        $player = Mockery::mock();
        $collection = new PlayersCollection();
        $collection->add($player);
        
        $this->assertEquals(1, $collection->count());
    }
    
    public function test_filter_players() {
        $player1 = Mockery::mock();
        $player1->gender = 'male';
        $player2 = Mockery::mock();
        $player2->gender = 'female';
        
        $collection = new PlayersCollection([$player1, $player2]);
        $filtered = $collection->filter(function($p) { return $p->gender === 'male'; });
        
        $this->assertEquals(1, $filtered->count());
    }
    
    public function test_collection_is_iterable() {
        $collection = new PlayersCollection([Mockery::mock(), Mockery::mock()]);
        $count = 0;
        
        foreach ($collection as $player) {
            $count++;
        }
        
        $this->assertEquals(2, $count);
    }
}

