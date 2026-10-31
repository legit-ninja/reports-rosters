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
        $item_id = (int) ($where['order_item_id'] ?? 0);
        if ($item_id > 0) {
            $this->sql[] = sprintf(
                'DELETE FROM %s WHERE order_item_id = %d',
                $table,
                $item_id
            );
        }
        $this->delete_attempts++;
        if ($this->fail_delete_on_attempt !== null
            && $this->delete_attempts === $this->fail_delete_on_attempt
        ) {
            $this->last_error = 'synthetic delete failure';
            return false;
        }
        if ($item_id > 0) {
            unset($this->working[$item_id]);
        }
        return 1;
    }

    public function get_results($query = null, $output = OBJECT) {
        $this->last_error = '';
        $sql = trim((string) $query);
        if ($sql !== '') {
            $this->sql[] = $sql;
        }
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

    /** @var list<string> */
    private $admin_log_actions = [];

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        DeletedProductFixture::resetCatalogue();
        $this->wpdb = new RosterRebuildTransactionJournalWpdb();
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->admin_log_actions = [];
        \Brain\Monkey\Functions\when('intersoccer_roster_admin_log_insert')->alias(function ($action) {
            $this->admin_log_actions[] = (string) $action;
            return true;
        });
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
    private function writeSqlSequence(): array {
        $out = [];
        foreach ($this->wpdb->sql as $line) {
            if ($this->isReadOnlySql($line)) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    private function isReadOnlySql(string $sql): bool {
        $upper = ltrim(strtoupper(trim($sql)));
        return strpos($upper, 'SELECT ') === 0
            || strpos($upper, 'SHOW ') === 0
            || strpos($upper, 'DESCRIBE ') === 0;
    }

    /** @param list<string> $sequence */
    private function assertWriteSequenceBoundedByTransaction(array $sequence): void {
        $start_idx = null;
        $end_idx = null;
        foreach ($sequence as $i => $line) {
            $upper = strtoupper(trim($line));
            if ($upper === 'START TRANSACTION') {
                $start_idx = $i;
            }
            if ($upper === 'COMMIT' || $upper === 'ROLLBACK') {
                $end_idx = $i;
            }
        }
        $this->assertNotNull($start_idx, 'Expected START TRANSACTION in write sequence');
        $this->assertNotNull($end_idx, 'Expected COMMIT or ROLLBACK in write sequence');
        $this->assertSame($end_idx, count($sequence) - 1, 'No SQL statements after outer COMMIT/ROLLBACK');

        foreach ($sequence as $i => $line) {
            if (!$this->isTransactionalControlSql($line) && !$this->isReadOnlySql($line)) {
                $this->assertGreaterThanOrEqual($start_idx, $i, "Write before START TRANSACTION: {$line}");
                $this->assertLessThanOrEqual($end_idx, $i, "Write after outer COMMIT/ROLLBACK: {$line}");
            }
        }
    }

    private function isTransactionalControlSql(string $sql): bool {
        $upper = strtoupper(trim($sql));
        return $upper === 'START TRANSACTION'
            || $upper === 'COMMIT'
            || $upper === 'ROLLBACK'
            || strpos($upper, 'SAVEPOINT ') === 0
            || strpos($upper, 'RELEASE SAVEPOINT ') === 0
            || strpos($upper, 'ROLLBACK TO SAVEPOINT ') === 0;
    }

    private function assertTransactionLevelZero(Database $database): void {
        $ref = new \ReflectionClass($database);
        $prop = $ref->getProperty('transaction_level');
        $prop->setAccessible(true);
        $this->assertSame(0, $prop->getValue($database));
    }

    private function deleteSql(int $order_item_id): string {
        return sprintf(
            'DELETE FROM %sintersoccer_rosters WHERE order_item_id = %d',
            $this->wpdb->prefix,
            $order_item_id
        );
    }

    /** @return list<string> */
    private function expectedTwoDeleteCommitSequence(int $first_id, int $second_id): array {
        return [
            'START TRANSACTION',
            'SAVEPOINT intersoccer_sp_2',
            $this->deleteSql($first_id),
            'RELEASE SAVEPOINT intersoccer_sp_2',
            'SAVEPOINT intersoccer_sp_2',
            $this->deleteSql($second_id),
            'RELEASE SAVEPOINT intersoccer_sp_2',
            'COMMIT',
        ];
    }

    /** @return list<string> */
    private function expectedSecondDeleteFailureSequence(int $first_id, int $second_id): array {
        return [
            'START TRANSACTION',
            'SAVEPOINT intersoccer_sp_2',
            $this->deleteSql($first_id),
            'RELEASE SAVEPOINT intersoccer_sp_2',
            'SAVEPOINT intersoccer_sp_2',
            $this->deleteSql($second_id),
            'ROLLBACK TO SAVEPOINT intersoccer_sp_2',
            'ROLLBACK',
        ];
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

        $sequence = $this->writeSqlSequence();
        $this->assertSame($this->expectedTwoDeleteCommitSequence(6101, 6102), $sequence);
        $this->assertWriteSequenceBoundedByTransaction($sequence);
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

        $sequence = $this->writeSqlSequence();
        $this->assertSame($this->expectedTwoDeleteCommitSequence($removed_a, $removed_b), $sequence);
        $this->assertWriteSequenceBoundedByTransaction($sequence);
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
        $this->assertStringContainsString('Failed to delete obsolete roster 6102', (string) ($result['error'] ?? ''));
        $sequence = $this->writeSqlSequence();
        $this->assertSame($this->expectedSecondDeleteFailureSequence(6101, 6102), $sequence);
        $this->assertWriteSequenceBoundedByTransaction($sequence);
        $this->assertSame([6101, 6102], $this->wpdb->committed_item_ids());
        $this->assertSame([], $this->admin_log_actions);
        $this->assertTransactionLevelZero($database);
    }

    public function test_rebuild_specific_orders_second_delete_failure_rolls_back_and_rebuild_failed(): void {
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
        $this->wpdb->fail_delete_on_attempt = 2;

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

        $result = $builder->rebuildSpecificOrders([$order_id]);

        $this->assertTrue($result['rebuild_failed'] ?? false);
        $this->assertStringContainsString('Failed to delete obsolete roster 7103', (string) ($result['error'] ?? ''));
        $sequence = $this->writeSqlSequence();
        $this->assertSame($this->expectedSecondDeleteFailureSequence($removed_a, $removed_b), $sequence);
        $this->assertWriteSequenceBoundedByTransaction($sequence);
        $this->assertSame([$line_keep, $removed_a, $removed_b], $this->wpdb->committed_item_ids());
        $this->assertSame([], $this->admin_log_actions);
        $this->assertTransactionLevelZero($database);
    }

    public function test_rebuild_specific_orders_inactive_order_second_delete_failure_rolls_back(): void {
        $order_id = 7203;
        $row_a = 7303;
        $row_b = 7304;
        $this->wpdb->committed = [$row_a => true, $row_b => true];
        $this->wpdb->fail_delete_on_attempt = 2;

        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = static function ($oid) use ($order_id) {
            if ((int) $oid !== $order_id) {
                return false;
            }
            return new class($order_id) {
                private $id;
                public function __construct($id) { $this->id = $id; }
                public function get_id() { return $this->id; }
                public function get_type() { return 'shop_order'; }
                public function get_status() { return 'failed'; }
                public function get_items() { return [7400 => new \stdClass()]; }
            };
        };

        $database = $this->realDatabase();
        $repo_real = $this->realRosterRepository($database);
        $stored = [
            $row_a => ['order_item_id' => $row_a, 'order_id' => $order_id],
            $row_b => ['order_item_id' => $row_b, 'order_id' => $order_id],
        ];
        $repo = Mockery::mock(RosterRepository::class);
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
        $repo->shouldReceive('deleteByOrderItemId')->andReturnUsing(function (int $id) use ($repo_real) {
            return $repo_real->deleteByOrderItemId($id);
        });
        $repo->shouldReceive('syncOrderStatusForKeptRows')->andReturn(0);

        $builder = $this->minimalBuilder($database, $repo);
        $result = $builder->rebuildSpecificOrders([$order_id]);

        $this->assertTrue($result['rebuild_failed'] ?? false);
        $this->assertStringContainsString('Failed to delete obsolete roster 7304', (string) ($result['error'] ?? ''));
        $sequence = $this->writeSqlSequence();
        $this->assertSame($this->expectedSecondDeleteFailureSequence($row_a, $row_b), $sequence);
        $this->assertWriteSequenceBoundedByTransaction($sequence);
        $this->assertSame([$row_a, $row_b], $this->wpdb->committed_item_ids());
        $this->assertSame([], $this->admin_log_actions);
        $this->assertTransactionLevelZero($database);
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
