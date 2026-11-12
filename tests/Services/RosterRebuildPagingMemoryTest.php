<?php
/**
 * Full rebuild drops order objects between pages and flushes the object cache.
 * A later-page failure rolls back the single rebuild transaction.
 * On-hold, refunded, and cancelled rows stay, and a fixture event signature is left as stored.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Exceptions\RosterRebuildAbortedException;
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
 * Journal wpdb: roster inserts stay in the open transaction until COMMIT.
 */
class RosterRebuildPagingMemoryJournalWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 0;
    public $posts = 'wp_posts';
    /** @var list<string> */
    public $sql = [];
    /** @var list<int> */
    public $inserted_item_ids = [];
    /** @var array<int,bool> */
    public $committed = [];
    /** @var array<int,bool> */
    private $working = [];

    public function query($query) {
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
            return true;
        }
        if ($upper === 'ROLLBACK') {
            $this->working = [];
            return true;
        }
        return true;
    }

    public function insert($table, $data, $format = null) {
        $item_id = (int) ($data['order_item_id'] ?? 0);
        $this->sql[] = 'INSERT INTO ' . $table . ' order_item_id=' . $item_id;
        if ($item_id > 0) {
            $this->working[$item_id] = true;
            $this->inserted_item_ids[] = $item_id;
            $this->insert_id = $item_id;
        }
        return 1;
    }

    public function get_results($query = null, $output = OBJECT) {
        if ($query) {
            $this->sql[] = trim((string) $query);
        }
        return [];
    }

    public function get_var($query) {
        return 0;
    }

    public function get_row($query = null, $output = OBJECT, $y = 0) {
        return null;
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

/**
 * Existing-row SELECT for a full rebuild. Returns the seeded id pairs and ignores paging clauses.
 */
class RosterRebuildPagingMemoryRowsWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $posts = 'wp_posts';
    /** @var array<int,array<string,string>> */
    public $existing = [];

    public function get_results($query, $output = OBJECT) {
        if (is_string($query) && strpos($query, 'SELECT order_item_id, order_id FROM') !== false) {
            return $this->existing;
        }
        return [];
    }

    public function get_var($query) {
        return 0;
    }

    public function get_row($query = null, $output = OBJECT, $y = 0) {
        return null;
    }

    public function prepare($query, ...$args) {
        return $query;
    }

    public function query($query) {
        return true;
    }
}

class RosterRebuildPagingMemoryOrder extends StoredOrder {
    public function get_type() {
        return 'shop_order';
    }
}

class RosterRebuildPagingMemoryTest extends TestCase {

    /** @var RosterRebuildPagingMemoryJournalWpdb|null */
    private $journal;

    /** @var RosterRebuildPagingMemoryRowsWpdb */
    private $rowsWpdb;

    /** @var mixed */
    private $previousWpdb;

    /** @var array<int,array<string,mixed>> */
    private $stored = [];

    /** @var int[] */
    private $created = [];

    /** @var int[] */
    private $deleted = [];

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        DeletedProductFixture::resetCatalogue();
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $this->journal = null;
        $this->rowsWpdb = new RosterRebuildPagingMemoryRowsWpdb();
        $this->stored = [];
        $this->created = [];
        $this->deleted = [];
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        unset($GLOBALS['intersoccer_test_wp_cache_flush_count']);
        ReportAccuracyStore::reset();
        parent::tearDown();
    }

    public function test_full_rebuild_flushes_cache_between_order_pages(): void {
        $GLOBALS['intersoccer_test_wp_cache_flush_count'] = 0;
        $seen = [];

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function (array $args) use (&$seen) {
            $limit = (int) ($args['limit'] ?? 0);
            $offset = (int) ($args['offset'] ?? 0);
            $seen[] = [
                'limit' => $limit,
                'offset' => $offset,
                'flushes_so_far' => (int) $GLOBALS['intersoccer_test_wp_cache_flush_count'],
            ];
            if ($offset >= 5) {
                return [];
            }
            $end = min(5, $offset + max(1, $limit));
            return range($offset + 1, $end);
        };
        $intersoccer_test_wc_get_order_callback = static function ($order_id) {
            return new class((int) $order_id) extends \WC_Order {
                public function __construct($id) {
                    parent::__construct($id);
                }
            };
        };

        $result = $this->builder($this->mockDatabase(), false)->buildRosters([
            'clear_existing' => true,
            'batch_size' => 2,
        ]);

        $this->assertSame(5, $result['orders_processed']);
        $offsets = array_column($seen, 'offset');
        $this->assertContains(0, $offsets);
        $this->assertContains(2, $offsets);
        $this->assertGreaterThan(1, count($seen));
        foreach ($seen as $page) {
            $this->assertSame(2, $page['limit']);
        }
        $flushes_at = [];
        foreach ($seen as $page) {
            $flushes_at[$page['offset']] = $page['flushes_so_far'];
        }
        $this->assertSame(0, $flushes_at[0]);
        $this->assertSame(1, $flushes_at[2]);
        $this->assertSame(2, $flushes_at[4]);
        $this->assertSame(2, (int) $GLOBALS['intersoccer_test_wp_cache_flush_count']);
    }

    public function test_later_page_failure_rolls_back_the_first_page(): void {
        $this->journal = new RosterRebuildPagingMemoryJournalWpdb();
        $GLOBALS['wpdb'] = $this->journal;
        $this->seedCourseOrder(6501, 6601, 'completed');

        $offsets = [];
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function (array $args) use (&$offsets) {
            $limit = max(1, (int) ($args['limit'] ?? 1));
            $offset = max(0, (int) ($args['offset'] ?? 0));
            $offsets[] = $offset;
            $ids = [6501, 6502];
            if ($offset >= count($ids)) {
                return [];
            }
            return array_values(array_slice($ids, $offset, $limit));
        };
        $intersoccer_test_wc_get_order_callback = static function ($order_id) {
            if ((int) $order_id === 6502) {
                throw new RosterRebuildAbortedException('synthetic later page failure');
            }
            return ReportAccuracyStore::$orders[(int) $order_id] ?? false;
        };

        $logger = $this->quietLogger();
        $database = new Database($logger);
        $result = $this->builder($database, true, $logger)->buildRosters([
            'clear_existing' => true,
            'batch_size' => 1,
        ]);

        $this->assertTrue(!empty($result['rebuild_failed']));
        $this->assertStringContainsString('synthetic later page failure', (string) ($result['error'] ?? ''));
        $this->assertSame(1, $result['orders_processed'], json_encode($result['errors'] ?? []));
        $this->assertContains(0, $offsets);
        $this->assertContains(1, $offsets);
        $this->assertSame(
            [6601],
            $this->journal->inserted_item_ids,
            json_encode($result['errors'] ?? [])
        );
        $this->assertSame([], $this->journal->committed_item_ids());
        $this->assertSame(0, $database->get_transaction_level());

        $insert_at = null;
        $rollback_at = null;
        $commits = 0;
        $starts = 0;
        foreach ($this->journal->sql as $i => $sql) {
            $upper = strtoupper(trim($sql));
            if ($upper === 'START TRANSACTION') {
                $starts++;
            }
            if ($upper === 'COMMIT') {
                $commits++;
            }
            if ($insert_at === null && strpos($sql, 'INSERT INTO') === 0) {
                $insert_at = $i;
            }
            if ($upper === 'ROLLBACK') {
                $rollback_at = $i;
            }
        }
        $this->assertSame(1, $starts);
        $this->assertSame(0, $commits);
        $this->assertNotNull($insert_at);
        $this->assertNotNull($rollback_at);
        $this->assertGreaterThan($insert_at, $rollback_at);
    }

    public function test_full_rebuild_keeps_on_hold_refunded_and_cancelled_statuses(): void {
        $this->seedCourseOrder(7401, 7501, 'on-hold');
        $this->seedCourseOrder(7402, 7502, 'refunded');
        $this->seedCourseOrder(7403, 7503, 'cancelled');
        $this->existingRow(7501, 7401, 'on-hold');
        $this->existingRow(7502, 7402, 'refunded');
        $this->existingRow(7503, 7403, 'cancelled');
        $this->routeOrders([7401]);

        $result = $this->builder($this->mockDatabase(), false)->buildRosters([
            'clear_existing' => true,
            'batch_size' => 10,
        ]);

        $this->assertTrue(empty($result['rebuild_failed']));
        $this->assertSame([], $this->deleted);
        $this->assertSame([], $this->created);
        $this->assertSame('on-hold', $this->stored[7501]['order_status']);
        $this->assertSame('refunded', $this->stored[7502]['order_status']);
        $this->assertSame('cancelled', $this->stored[7503]['order_status']);
        $this->assertArrayHasKey(7501, $this->stored);
        $this->assertArrayHasKey(7502, $this->stored);
        $this->assertArrayHasKey(7503, $this->stored);
    }

    public function test_full_rebuild_leaves_fixture_event_signature_unchanged(): void {
        $signature = 'fixture-event-signature-kept';
        $this->seedCourseOrder(9101, 9001, 'completed');
        $this->existingRow(9001, 9101, 'completed', [
            'event_signature' => $signature,
        ]);
        $this->routeOrders([9101]);

        $result = $this->builder($this->mockDatabase(), false)->buildRosters([
            'clear_existing' => true,
            'batch_size' => 10,
        ]);

        $this->assertTrue(empty($result['rebuild_failed']));
        $this->assertSame([], $this->deleted);
        $this->assertSame([], $this->created);
        $this->assertSame($signature, $this->stored[9001]['event_signature']);
    }

    private function builder(Database $database, bool $write_through_wpdb, ?Logger $logger = null): RosterBuilder {
        $logger = $logger ?: $this->quietLogger();
        $player = $this->player();
        $player_repo = Mockery::mock(PlayerRepository::class);
        $player_repo->shouldReceive('getPlayersByCustomerId')->andReturn(new PlayersCollection([$player]));
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn(new PlayersCollection([$player]));
        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => []]);

        return new RosterBuilder(
            $logger,
            $database,
            $player_repo,
            $this->repository($write_through_wpdb),
            $validator,
            Mockery::mock(EventMatcher::class),
            $matcher
        );
    }

    private function repository(bool $write_through_wpdb): RosterRepository {
        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('first')->andReturnUsing(function (array $where = []) {
            $id = (int) ($where['order_item_id'] ?? 0);
            if ($id <= 0 || !isset($this->stored[$id])) {
                return null;
            }
            $roster = new Roster(array_merge([
                'customer_id' => 1,
                'player_index' => 0,
                'first_name' => 'Synthetic',
                'last_name' => 'Player',
                'dob' => '2022-03-01',
                'gender' => 'male',
            ], $this->stored[$id]));
            $roster->setKey($id);
            return $roster;
        });
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturn(new RostersCollection());
        $repo->shouldReceive('syncOrderStatusForKeptRows')->andReturnUsing(function (int $order_id, string $status) {
            foreach ($this->stored as $item_id => $row) {
                if ((int) ($row['order_id'] ?? 0) === $order_id) {
                    $this->stored[$item_id]['order_status'] = $status;
                }
            }
            return 1;
        });
        $repo->shouldReceive('create')->andReturnUsing(function (array $data) use ($write_through_wpdb) {
            unset($data['_skip_age_group_validation']);
            $roster = new Roster($data);
            $roster->setSkipAgeGroupValidation(true);
            $roster->validate();
            $item_id = (int) ($data['order_item_id'] ?? 0);
            if ($write_through_wpdb) {
                $this->journal->insert($this->journal->prefix . 'intersoccer_rosters', [
                    'order_id' => (int) ($data['order_id'] ?? 0),
                    'order_item_id' => $item_id,
                ]);
                $roster->setKey((int) ($this->journal->insert_id ?: $item_id));
            } else {
                $roster->setKey($item_id);
                $this->created[] = $item_id;
                $this->stored[$item_id] = $data;
            }
            return $roster;
        });
        $repo->shouldReceive('update')->andReturnUsing(function ($id, array $data) {
            unset($data['_skip_age_group_validation']);
            $item_id = (int) ($data['order_item_id'] ?? $id);
            $this->stored[$item_id] = array_merge($this->stored[$item_id] ?? [], $data);
            $roster = new Roster($this->stored[$item_id]);
            $roster->setKey($item_id);
            return $roster;
        });
        $repo->shouldReceive('fillFields')->andReturnUsing(function ($id, array $fields) {
            unset($fields['_skip_age_group_validation']);
            $item_id = (int) $id;
            $this->stored[$item_id] = array_merge($this->stored[$item_id] ?? [], $fields);
            $roster = new Roster(array_merge([
                'customer_id' => 1,
                'player_index' => 0,
                'first_name' => 'Synthetic',
                'last_name' => 'Player',
            ], $this->stored[$item_id]));
            $roster->setKey($item_id);
            return $roster;
        });
        $repo->shouldReceive('deleteByOrderItemId')->andReturnUsing(function (int $id) {
            $this->deleted[] = $id;
            unset($this->stored[$id]);
            return 1;
        });

        return $repo;
    }

    private function mockDatabase(): Database {
        $rows = $this->rowsWpdb;
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($rows);
        $database->shouldReceive('get_logger')->andReturn($this->quietLogger());
        $database->shouldReceive('transaction')->andReturnUsing(function (callable $callback) {
            return $callback();
        });
        $GLOBALS['wpdb'] = $rows;
        return $database;
    }

    private function quietLogger(): Logger {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $logger->shouldReceive('is_db_logging_enabled')->andReturn(false);
        return $logger;
    }

    private function player(): Player {
        return new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'Synthetic',
            'last_name' => 'Player',
            'dob' => '2022-03-01',
            'gender' => 'male',
            'medical_conditions' => '',
            'dietary_needs' => '',
        ]);
    }

    /** @return array<string,string> */
    private function courseMeta(): array {
        return array_merge(DeletedProductFixture::courseMeta(), [
            'Start Date' => 'July 1, 2026',
            'End Date' => 'December 31, 2026',
            'Age Group' => '3-5y',
            'pa_age-group' => '3-5y',
        ]);
    }

    private function seedCourseOrder(int $order_id, int $item_id, string $status): void {
        $order = new RosterRebuildPagingMemoryOrder($order_id, $status, '2026-01-12 10:00:00');
        $order->addLine(new StoredLineItem(
            $item_id,
            'Synthetic Sunday Course - Venue A - Winter 2026',
            DeletedProductFixture::LIVE_PID,
            DeletedProductFixture::LIVE_VID,
            $this->courseMeta(),
            true,
            300.0
        ));
        ReportAccuracyStore::$orders[$order_id] = $order;
    }

    /** @param array<string,mixed> $extra */
    private function existingRow(int $item_id, int $order_id, string $status, array $extra = []): void {
        $this->stored[$item_id] = array_merge([
            'order_item_id' => $item_id,
            'order_id' => $order_id,
            'order_status' => $status,
        ], $extra);
        $this->rowsWpdb->existing[] = [
            'order_item_id' => (string) $item_id,
            'order_id' => (string) $order_id,
        ];
    }

    /** @param int[] $ids */
    private function routeOrders(array $ids): void {
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function (array $args) use ($ids) {
            $limit = max(1, (int) ($args['limit'] ?? 1));
            $offset = max(0, (int) ($args['offset'] ?? 0));
            if ($offset >= count($ids)) {
                return [];
            }
            return array_values(array_slice($ids, $offset, $limit));
        };
        $intersoccer_test_wc_get_order_callback = static function ($order_id) {
            return ReportAccuracyStore::$orders[(int) $order_id] ?? false;
        };
    }
}
