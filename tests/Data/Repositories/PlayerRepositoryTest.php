<?php
/**
 * PlayerRepository Test
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Repositories;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Models\Player;
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
        Functions\expect('get_user_meta')->andReturn([[
            'first_name' => 'John',
            'last_name' => 'Doe',
            'dob' => '2016-01-15',
            'gender' => 'male',
        ]]);
        
        $player = $this->repository->find('1_0');
        
        $this->assertInstanceOf(Player::class, $player);
        $this->assertSame(0, (int) $player->player_index);
        $this->assertSame('John', $player->first_name);
    }
    
    public function test_create_player() {
        Functions\expect('get_user_meta')->andReturn([]);
        Functions\expect('update_user_meta')->once()->with(1, PlayerRepository::META_KEY, Mockery::on(function ($rows) {
            return is_array($rows)
                && ($rows[0]['first_name'] ?? '') === 'John'
                && ($rows[0]['last_name'] ?? '') === 'Doe';
        }))->andReturn(true);
        
        $result = $this->repository->create([
            'customer_id' => 1,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'dob' => '2016-01-15',
            'gender' => 'male',
        ]);
        
        $this->assertInstanceOf(Player::class, $result);
        $this->assertSame('John', $result->first_name);
        $this->assertSame('Doe', $result->last_name);
    }
}

