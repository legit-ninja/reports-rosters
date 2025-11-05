<?php
/**
 * PlayerMatcher Test
 * Tests for the PlayerMatcher service
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use Mockery;

class PlayerMatcherTest extends TestCase {
    private $playerMatcher;
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        $this->logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
        $this->playerMatcher = new PlayerMatcher($this->logger);
    }
    
    public function test_player_matcher_initialization() {
        $this->assertInstanceOf(PlayerMatcher::class, $this->playerMatcher);
    }
    
    public function test_get_assigned_players_from_order_item() {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_meta')->andReturn('1');
        
        $players = new PlayersCollection();
        $result = $this->playerMatcher->getAssignedPlayers($item, $players);
        
        $this->assertInstanceOf(PlayersCollection::class, $result);
    }
    
    public function test_match_player_by_index() {
        $player = Mockery::mock();
        $player->player_index = 0;
        $player->first_name = 'John';
        
        $result = $this->playerMatcher->matchByIndex(0, new PlayersCollection([$player]));
        
        $this->assertNotNull($result);
    }
}

