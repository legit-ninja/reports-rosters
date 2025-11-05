<?php
/**
 * PlayerRepository Test
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Repositories;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use Brain\Monkey\Functions;
use Mockery;

class PlayerRepositoryTest extends TestCase {
    private $repository;
    
    protected function setUp(): void {
        parent::setUp();
        $this->repository = new PlayerRepository();
    }
    
    public function test_get_players_by_customer_id() {
        Functions\expect('get_user_meta')
            ->once()
            ->andReturn([['first_name' => 'John', 'last_name' => 'Doe']]);
        
        $players = $this->repository->getPlayersByCustomerId(1);
        
        $this->assertInstanceOf(PlayersCollection::class, $players);
    }
    
    public function test_find_player() {
        Functions\expect('get_user_meta')->andReturn(['first_name' => 'John']);
        
        $player = $this->repository->find('1_0');
        
        $this->assertNotNull($player);
    }
    
    public function test_create_player() {
        Functions\expect('update_user_meta')->once()->andReturn(true);
        
        $result = $this->repository->create(['customer_id' => 1, 'first_name' => 'John']);
        
        $this->assertTrue($result);
    }
}

