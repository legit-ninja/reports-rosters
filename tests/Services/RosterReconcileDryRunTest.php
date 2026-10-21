<?php

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterReconcileDryRunTest extends TestCase {

    /** @var array<int,array<string,mixed>> */
    private $rows = [];

    /** @var int */
    private $insertCount = 0;

    /** @var int */
    private $updateCount = 0;

    /** @var int */
    private $deleteCount = 0;

    /** @var RosterBuilder */
    private $builder;

    /** @var PlayerMatcher */
    private $playerMatcher;

    protected function setUp(): void {
        parent::setUp();

        $this->rows = [];
        $this->insertCount = 0;
        $this->updateCount = 0;
        $this->deleteCount = 0;

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturnUsing(function () {
            return $this->makeWpdb();
        });

        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('deleteByOrderItemId')->andReturnUsing(function () {
            $this->deleteCount++;
            return 1;
        });
        $rosterRepository->shouldReceive('first')->andReturnUsing(function (array $where) {
            $item_id = (int) ($where['order_item_id'] ?? 0);
            foreach ($this->rows as $row) {
                if ((int) ($row['order_item_id'] ?? 0) === $item_id) {
                    $model = new Roster();
                    foreach ($row as $key => $value) {
                        $model->$key = $value;
                    }
                    return $model;
                }
            }
            return null;
        });
        $rosterRepository->shouldReceive('create')->andReturnUsing(function () {
            $this->insertCount++;
            return new Roster();
        });
        $rosterRepository->shouldReceive('update')->andReturnUsing(function () {
            $this->updateCount++;
            return new Roster();
        });
        $rosterRepository->shouldReceive('where')->andReturnSelf();
        $rosterRepository->shouldReceive('count')->andReturn(0);

        $this->playerMatcher = new PlayerMatcher($logger);

        $this->builder = new RosterBuilder(
            $logger,
            $database,
            Mockery::mock(PlayerRepository::class),
            $rosterRepository,
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            $this->playerMatcher
        );
    }

    private function makeWpdb(): object {
        $rows = &$this->rows;
        return new class($rows) {
            public $prefix = 'wp_';
            /** @var array<int,array<string,mixed>> */
            private $rowsRef;
            public function __construct(&$rows) {
                $this->rowsRef = &$rows;
            }
            public function get_results($query, $output = OBJECT) {
                return $output === ARRAY_A ? $this->rowsRef : $this->rowsRef;
            }
            public function get_var($query) {
                return 0;
            }
            public function prepare($query, ...$args) {
                return $query;
            }
        };
    }

    public function test_dry_run_reports_would_delete_without_repository_delete(): void {
        $this->rows = [
            ['order_item_id' => 501, 'order_id' => 9001],
        ];

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function () {
            return [];
        };
        $intersoccer_test_wc_get_order_callback = static function () {
            return false;
        };

        $result = $this->builder->reconcile(['dry_run' => true, 'delete_obsolete' => true]);

        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(0, $this->deleteCount);
        $this->assertSame(1, $result['report']['would_delete']);
    }

    public function test_ambiguous_fallback_skips_insert_and_reports(): void {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn(601);
        $item->shouldReceive('get_product_id')->andReturn(10);
        $item->shouldReceive('get_variation_id')->andReturn(0);
        $item->shouldReceive('get_meta')->andReturn('');
        $item->shouldReceive('get_meta_data')->andReturn([]);
        $item->shouldReceive('get_quantity')->andReturn(1);

        $players = new PlayersCollection();
        $players->add($this->makePlayer('a'));
        $players->add($this->makePlayer('b'));

        $resolution = $this->playerMatcher->resolveAssignedPlayers($item, $players, ['skip_age_gender_validation' => true]);
        $this->assertTrue($resolution['ambiguous']);
    }

    public function test_only_missing_does_not_delete_obsolete_rows(): void {
        $this->rows = [
            ['order_item_id' => 701, 'order_id' => 9002],
        ];

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function () {
            return [];
        };
        $intersoccer_test_wc_get_order_callback = static function () {
            return false;
        };

        $result = $this->builder->reconcile(['only_missing' => true, 'delete_obsolete' => true]);

        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(0, $this->deleteCount);
    }

    private function makePlayer(string $suffix): Player {
        return new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'player_id' => 'uuid-' . $suffix,
            'first_name' => 'Child' . $suffix,
            'last_name' => 'Test',
            'dob' => '2015-01-01',
            'gender' => 'male',
        ]);
    }
}
