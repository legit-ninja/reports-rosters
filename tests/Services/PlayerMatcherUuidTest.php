<?php
/**
 * PlayerMatcher UUID assignment strategy tests.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use Mockery;

class PlayerMatcherUuidTest extends TestCase {
    private $playerMatcher;
    private $logger;

    protected function setUp(): void {
        parent::setUp();
        $this->logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
        $this->playerMatcher = new PlayerMatcher($this->logger);
    }

    public function test_uuid_strategy_matches_before_index() {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $player = new Player([
            'customer_id' => 1,
            'player_index' => 5,
            'player_id' => $uuid,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'dob' => '2015-01-01',
            'gender' => 'female',
        ]);
        $players = new PlayersCollection([$player]);

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn(99);
        $item->shouldReceive('get_product_id')->andReturn(100);
        $item->shouldReceive('get_variation_id')->andReturn(0);
        $item->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($uuid) {
            if ($key === 'assigned_player_id') {
                return $uuid;
            }
            if ($key === 'assigned_player') {
                return '0';
            }
            return '';
        });

        $result = $this->playerMatcher->getAssignedPlayers($item, $players, ['skip_age_gender_validation' => true]);

        $this->assertSame(1, $result->count());
        $this->assertSame($uuid, $result->first()->player_id);
    }

    public function test_index_fallback_when_uuid_missing() {
        $player = new Player([
            'customer_id' => 1,
            'player_index' => 2,
            'player_id' => 'legacy-uuid',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'dob' => '2014-06-01',
            'gender' => 'female',
        ]);
        $players = new PlayersCollection([$player]);

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn(100);
        $item->shouldReceive('get_product_id')->andReturn(101);
        $item->shouldReceive('get_variation_id')->andReturn(0);
        $item->shouldReceive('get_meta')->andReturnUsing(function ($key) {
            if ($key === 'assigned_player_id') {
                return '';
            }
            if ($key === 'assigned_player') {
                return '2';
            }
            return '';
        });

        $result = $this->playerMatcher->getAssignedPlayers($item, $players, ['skip_age_gender_validation' => true]);

        $this->assertSame(1, $result->count());
        $this->assertSame(2, $result->first()->player_index);
    }

    public function test_uuid_strategy_returns_empty_when_player_not_found() {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn(101);
        $item->shouldReceive('get_product_id')->andReturn(102);
        $item->shouldReceive('get_variation_id')->andReturn(0);
        $item->shouldReceive('get_meta')->andReturnUsing(function ($key) {
            if ($key === 'assigned_player_id') {
                return 'missing-uuid';
            }
            return '';
        });

        $result = $this->playerMatcher->getAssignedPlayers($item, new PlayersCollection(), ['skip_age_gender_validation' => true]);

        $this->assertTrue($result->isEmpty());
    }
}
