<?php

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Exceptions\DatabaseException;
use InterSoccer\ReportsRosters\Services\CacheManager;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\DeletedProductFixture;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\ReportAccuracyStore;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\StoredLineItem;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\StoredOrder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

require_once dirname(__DIR__) . '/ReportAccuracy/ReportAccuracyFixtures.php';
require_once dirname(__DIR__) . '/ReportAccuracy/DeletedProductRosterTest.php';

/**
 * wpdb stand-in: records SQL, applies roster deletes only on COMMIT, supports SAVEPOINT stack.
 */
class RosterRebuildTransactionJournalWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    /** @var list<string> */
    public $sql = [];
    /** @var array<int,bool> */
    public $committed = [];
    /** @var array<int,bool> */
    private $working = [];
    /** @var list<array<int,bool>> */
    private $savepoints = [];
    /** @var int|null */
    public $fail_delete_on_attempt = null;
    private $delete_attempts = 0;
    /** @var array<int,array<string,string>> */
    public $existing_map_rows = [];

    public function query($query) {
        $this->last_error = '';
        $sql = trim((string) $query);
        $this->sql[] = $sql;
        $upper = strtoupper($sql);

        if ($upper === 'START TRANSACTION') {
            $this->working = $this->committed;
            return true;
        }
        if ($upper === 'COMMIT') {
            $this->committed = $this->working;
            $this->working = [];
            $this->savepoints = [];
            return true;
        }
        if ($upper === 'ROLLBACK') {
            $this->working = [];
            $this->savepoints = [];
            return true;
        }
        if (strpos($upper, 'SAVEPOINT ') === 0) {
            $this->savepoints[] = $this->working;
            return true;
        }
        if (strpos($upper, 'RELEASE SAVEPOINT ') === 0) {
            if ($this->savepoints !== []) {
                array_pop($this->savepoints);
            }
            return true;
        }
        if (strpos($upper, 'ROLLBACK TO SAVEPOINT ') === 0) {
            if ($this->savepoints !== []) {
                $this->working = array_pop($this->savepoints);
            }
            return true;
        }

        return true;
    }

    public function delete($table, $where) {
        $this->last_error = '';
        $this->delete_attempts++;
        if ($this->fail_delete_on_attempt !== null
            && $this->delete_attempts === $this->fail_delete_on_attempt
        ) {
            $this->last_error = 'synthetic delete failure';
            return false;
        }
        $item_id = (int) ($where['order_item_id'] ?? 0);
        if ($item_id > 0) {
            unset($this->working[$item_id]);
        }
        return 1;
    }

    public function get_results($query = null, $output = OBJECT) {
        $this->last_error = '';
        if (preg_match('/SELECT order_item_id, order_id FROM/', (string) $query)) {
            return $this->existing_map_rows;
        }
        return [];
    }

    public function get_var($query) {
        $this->last_error = '';
        return 0;
    }

    public function prepare($query, ...$args) {
        return $query;
    }

    /** @return list<int> */
    public function committed_item_ids(): array {
        $ids = array_keys($this->committed);
        sort($ids);
        return $ids;
    }
}

class RosterRebuildNestedTransaction160Test extends TestCase {

    /** @var RosterRebuildTransactionJournalWpdb */
    private $wpdb;
    private $previous_wpdb;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        DeletedProductFixture::resetCatalogue();
        $this->wpdb = new RosterRebuildTransactionJournalWpdb();
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previous_wpdb;
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        parent::tearDown();
    }

    private function seedObsoletePair(): void {
        $this->wpdb->existing_map_rows = [
            ['order_item_id' => '6101', 'order_id' => '6001'],
            ['order_item_id' => '6102', 'order_id' => '6001'],
        ];
        $this->wpdb->committed = [6101 => true, 6102 => true];

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function () {
            return [];
        };
        $intersoccer_test_wc_get_order_callback = static function ($oid) {
            if ((int) $oid !== 6001) {
                return false;
            }
            return new class() {
                public function get_id() { return 6001; }
                public function get_type() { return 'shop_order'; }
                public function get_status() { return 'completed'; }
                public function get_items() { return [5999 => new \stdClass()]; }
            };
        };
    }

    private function realDatabase(): Database {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug', 'info', 'warning', 'error')->andReturn(null);
        return new Database($logger);
    }

    private function realRosterRepository(Database $database): RosterRepository {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug', 'info', 'warning', 'error')->andReturn(null);
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('forgetPattern', 'forget')->andReturn(null);
        return new RosterRepository($logger, $database, $cache);
    }

    /** @return list<string> */
    private function txSqlOnly(): array {
        $out = [];
        foreach ($this->wpdb->sql as $line) {
            $u = strtoupper($line);
            if ($u === 'START TRANSACTION'
                || $u === 'COMMIT'
                || $u === 'ROLLBACK'
                || strpos($u, 'SAVEPOINT ') === 0
                || strpos($u, 'RELEASE SAVEPOINT ') === 0
                || strpos($u, 'ROLLBACK TO SAVEPOINT ') === 0
            ) {
                $out[] = $line;
            }
        }
        return $out;
    }

    private function minimalBuilder(Database $database, RosterRepository $repo): RosterBuilder {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $player_repo = Mockery::mock(PlayerRepository::class);
        $player_repo->shouldReceive('getPlayersByCustomerId')->andReturn(new PlayersCollection());
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn(new PlayersCollection());
        $validator = Mockery::mock(DataValidator::class);
        return new RosterBuilder(
            $logger,
            $database,
            $player_repo,
            $repo,
            $validator,
            Mockery::mock(EventMatcher::class),
            $matcher
        );
    }

    public function test_full_rebuild_two_deletes_nested_savepoints_single_commit(): void {
        $this->seedObsoletePair();
        $database = $this->realDatabase();
        $repo = $this->realRosterRepository($database);
        $builder = $this->minimalBuilder($database, $repo);

        $builder->buildRosters(['clear_existing' => true, 'batch_size' => 5]);

        $this->assertSame(
            [
                'START TRANSACTION',
                'SAVEPOINT intersoccer_sp_2',
                'RELEASE SAVEPOINT intersoccer_sp_2',
                'SAVEPOINT intersoccer_sp_2',
                'RELEASE SAVEPOINT intersoccer_sp_2',
                'COMMIT',
            ],
            $this->txSqlOnly()
        );
        $this->assertSame([], $this->wpdb->committed_item_ids());
    }

    public function test_rebuild_specific_orders_two_deletes_nested_savepoints_single_commit(): void {
        $order_id = 7001;
        $line_keep = 7101;
        $removed_a = 7102;
        $removed_b = 7103;
        $meta = array_merge(DeletedProductFixture::courseMeta(), [
            'Start Date' => 'July 1, 2026',
            'End Date' => 'December 31, 2026',
            'Activity Type' => 'Course',
        ]);
        $order = new StoredOrder($order_id, 'completed', '2026-01-12 10:00:00');
        $order->addLine(new StoredLineItem(
            $line_keep,
            'Synthetic Course',
            DeletedProductFixture::LIVE_PID,
            DeletedProductFixture::LIVE_VID,
            $meta,
            true,
            300.0
        ));
        ReportAccuracyStore::$orders[$order_id] = $order;

        $this->wpdb->committed = [
            $line_keep => true,
            $removed_a => true,
            $removed_b => true,
        ];

        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = static fn ($oid) => ReportAccuracyStore::$orders[(int) $oid] ?? false;

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $database = $this->realDatabase();
        $repo_real = $this->realRosterRepository($database);

        $stored = [
            $line_keep => ['order_item_id' => $line_keep, 'order_id' => $order_id],
            $removed_a => ['order_item_id' => $removed_a, 'order_id' => $order_id],
            $removed_b => ['order_item_id' => $removed_b, 'order_id' => $order_id],
        ];

        $player = new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'Synthetic',
            'last_name' => 'Player',
            'dob' => '2018-03-01',
            'gender' => 'male',
        ]);
        $player_repo = Mockery::mock(PlayerRepository::class);
        $player_repo->shouldReceive('getPlayersByCustomerId')->andReturn(new PlayersCollection([$player]));
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn(new PlayersCollection([$player]));
        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => []]);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('first')->andReturn(null);
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturnUsing(function (array $where) use ($stored, $order_id) {
            if ((int) ($where['order_id'] ?? 0) !== $order_id) {
                return new RostersCollection();
            }
            $rows = [];
            foreach ($stored as $row) {
                $rows[] = (object) $row;
            }
            return new RostersCollection($rows);
        });
        $repo->shouldReceive('create')->andReturnUsing(function (array $data) {
            $r = new Roster($data);
            $r->setSkipAgeGroupValidation(true);
            $r->validate();
            return $r;
        });
        $repo->shouldReceive('update')->andReturnUsing(fn ($r) => $r);
        $repo->shouldReceive('deleteByOrderItemId')->andReturnUsing(function (int $id) use ($repo_real) {
            return $repo_real->deleteByOrderItemId($id);
        });

        $builder = new RosterBuilder(
            $logger,
            $database,
            $player_repo,
            $repo,
            $validator,
            Mockery::mock(EventMatcher::class),
            $matcher
        );

        $builder->rebuildSpecificOrders([$order_id]);

        $this->assertSame(
            [
                'START TRANSACTION',
                'SAVEPOINT intersoccer_sp_2',
                'RELEASE SAVEPOINT intersoccer_sp_2',
                'SAVEPOINT intersoccer_sp_2',
                'RELEASE SAVEPOINT intersoccer_sp_2',
                'COMMIT',
            ],
            $this->txSqlOnly()
        );
        $this->assertSame([$line_keep], $this->wpdb->committed_item_ids());
    }

    public function test_full_rebuild_second_delete_failure_rolls_back_all_and_rebuild_failed(): void {
        $this->seedObsoletePair();
        $this->wpdb->fail_delete_on_attempt = 2;
        $database = $this->realDatabase();
        $repo = $this->realRosterRepository($database);
        $builder = $this->minimalBuilder($database, $repo);

        $result = $builder->buildRosters(['clear_existing' => true, 'batch_size' => 5]);

        $this->assertTrue($result['rebuild_failed'] ?? false);
        $this->assertSame(
            [
                'START TRANSACTION',
                'SAVEPOINT intersoccer_sp_2',
                'RELEASE SAVEPOINT intersoccer_sp_2',
                'SAVEPOINT intersoccer_sp_2',
                'ROLLBACK TO SAVEPOINT intersoccer_sp_2',
                'ROLLBACK',
            ],
            $this->txSqlOnly()
        );
        $this->assertSame([6101, 6102], $this->wpdb->committed_item_ids());
    }

    public function test_reconcile_continue_mode_one_delete_error_other_succeeds(): void {
        $this->wpdb->committed = [8101 => true, 8102 => true];
        $this->wpdb->fail_delete_on_attempt = 1;

        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = static function ($oid) {
            if ((int) $oid !== 8001) {
                return false;
            }
            return new class() {
                public function get_id() { return 8001; }
                public function get_type() { return 'shop_order'; }
                public function get_status() { return 'completed'; }
                public function get_items() { return [7999 => new \stdClass()]; }
            };
        };

        $database = $this->realDatabase();
        $repo = $this->realRosterRepository($database);
        $builder = $this->minimalBuilder($database, $repo);
        $ref = new \ReflectionClass($builder);
        $ref->getMethod('init_build_stats')->invoke($builder);
        $map = [8101 => 8001, 8102 => 8001];
        $deleted = $ref->getMethod('deleteObsoleteReconcileRows')->invokeArgs(
            $builder,
            [&$map, 200, 'continue']
        );

        $this->assertSame(1, $deleted);
        $stats = $ref->getMethod('getBuildStatistics')->invoke($builder);
        $this->assertCount(1, $stats['errors']);
        $this->assertStringContainsString('8101', $stats['errors'][0]);
        $this->assertSame([8101], $this->wpdb->committed_item_ids());
    }

    public function test_delete_by_order_item_id_throws_database_exception_on_failure(): void {
        $this->wpdb->fail_delete_on_attempt = 1;
        $repo = $this->realRosterRepository($this->realDatabase());
        $this->expectException(DatabaseException::class);
        $repo->deleteByOrderItemId(9991);
    }
}
