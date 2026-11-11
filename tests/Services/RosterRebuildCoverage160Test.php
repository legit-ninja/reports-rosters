<?php
/**
 * RR #160: full rebuild / per-order rebuild end-to-end through processOrderItems (repositories and Woo mocked).
 *
 * Synthetic ids only.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use Brain\Monkey\Functions;
use InterSoccer\ReportsRosters\Ajax\RosterAjaxHandler;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\DeletedProductFixture;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\DeletedProductWpdb;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\ReportAccuracyStore;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\StoredLineItem;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\StoredOrder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

require_once dirname(__DIR__) . '/ReportAccuracy/ReportAccuracyFixtures.php';
require_once dirname(__DIR__) . '/ReportAccuracy/DeletedProductRosterTest.php';


require_once dirname(__DIR__) . '/shims/roster-admin-log-recorder.php';
class RosterRebuildCoverage160Wpdb extends DeletedProductWpdb {
    /** @var array<int,array<string,string>> */
    public $existing = [];
    /** @var array<int,array<string,string>> DESCRIBE rows for the rosters table */
    public $describe = [];

    public function get_results($query = null, $output = OBJECT) {
        if (preg_match('/SELECT order_item_id, order_id FROM \S*intersoccer_rosters/', (string) $query)) {
            return $this->existing;
        }
        if (preg_match('/^DESCRIBE \S*intersoccer_rosters/', (string) $query)) {
            return $this->describe;
        }
        return parent::get_results($query, $output);
    }
}

/** StoredOrder plus WC_Order::get_type() (the full rebuild skips anything that is not a shop_order). */
class RosterRebuildCoverage160Order extends StoredOrder {
    public function get_type() { return 'shop_order'; }
}

class RosterRebuildCoverage160Test extends TestCase {

    /** @var RosterRebuildCoverage160Wpdb */
    private $wpdb;
    private $previous_wpdb;
    /** @var int[] */
    private $created = [];
    /** @var int[] */
    private $updated = [];
    /** @var int[] */
    private $deleted = [];
    /** @var array<int,array{0:int,1:string}> */
    private $synced = [];
    /** @var array<int,array<string,mixed>> stored rows by order_item_id */
    private $stored = [];
    /** @var array<int,array{0:string,1:array}> */
    private $adminLog = [];
    /** @var array<int,mixed> */
    private $ordersQueryStatuses = [];
    /** @var callable|null receives the data passed to RosterRepository::create() */
    private $createDataSpy = null;
    /** @var array<int,string> order_item_id => MySQL error the insert fails with */
    private $createDbError = [];

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        DeletedProductFixture::resetCatalogue();
        $this->wpdb = new RosterRebuildCoverage160Wpdb();
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->created = $this->updated = $this->deleted = $this->synced = $this->stored = [];
        $this->adminLog = [];
        $this->ordersQueryStatuses = [];
        $GLOBALS['intersoccer_test_admin_log_insert_callback'] = (function ($action, $sig = '', $payload = []) {
            $this->adminLog[] = [$action, $payload];
            return true;
        });
        $GLOBALS['intersoccer_test_track_order_meta_writes'] = true;
        $GLOBALS['intersoccer_test_order_meta_write_count'] = 0;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previous_wpdb;
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        unset(
            $GLOBALS['intersoccer_test_track_order_meta_writes'],
            $GLOBALS['intersoccer_test_order_meta_write_count'],
            $GLOBALS['intersoccer_test_json_error_throws']
        );
        ReportAccuracyStore::reset();
        parent::tearDown();
    }

    // ---- fixtures -------------------------------------------------------------------------------------------

    private function meta(array $overrides = []): array {
        return array_merge(DeletedProductFixture::courseMeta(), [
            'Start Date' => 'July 1, 2026',
            'End Date' => 'December 31, 2026',
            'Age Group' => '3-5y',
            'pa_age-group' => '3-5y',
        ], $overrides);
    }

    private function order(int $order_id, string $status, array $lines): void {
        $order = new RosterRebuildCoverage160Order($order_id, $status, '2026-01-12 10:00:00');
        foreach ($lines as $item_id => $meta) {
            $order->addLine(new StoredLineItem(
                $item_id,
                'Synthetic Sunday Course - Venue A - Winter 2026',
                DeletedProductFixture::LIVE_PID,
                DeletedProductFixture::LIVE_VID,
                $meta,
                true,
                300.0
            ));
        }
        ReportAccuracyStore::$orders[$order_id] = $order;
    }

    private function existingRow(int $item_id, int $order_id, string $status = 'completed'): void {
        $this->stored[$item_id] = ['order_item_id' => $item_id, 'order_id' => $order_id, 'order_status' => $status];
        $this->wpdb->existing[] = ['order_item_id' => (string) $item_id, 'order_id' => (string) $order_id];
    }

    private function player(string $dob): Player {
        return new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'Synthetic',
            'last_name' => 'Player',
            'dob' => $dob,
            'gender' => 'male',
            'medical_conditions' => '',
            'dietary_needs' => '',
        ]);
    }

    /**
     * @param array<int,string> $dobByItem item id => DOB ('' = default 2022-03-01, age 4 at start)
     * @param int[] $throwForItems
     */
    private function builder(array $dobByItem = [], array $throwForItems = [], ?Database $database = null): RosterBuilder {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        if ($database === null) {
            $database = Mockery::mock(Database::class);
            $database->shouldReceive('get_wpdb')->andReturn($this->wpdb);
            $database->shouldReceive('transaction')->andReturnUsing(function (callable $callback) {
                return $callback();
            });
        }

        $player_repo = Mockery::mock(PlayerRepository::class);
        $player_repo->shouldReceive('getPlayersByCustomerId')->andReturn(new PlayersCollection([$this->player('2022-03-01')]));

        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturnUsing(function ($item) use ($dobByItem, $throwForItems) {
            $id = (int) $item->get_id();
            if (in_array($id, $throwForItems, true)) {
                throw new \RuntimeException('synthetic build failure');
            }
            return new PlayersCollection([$this->player($dobByItem[$id] ?? '2022-03-01')]);
        });

        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => []]);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('first')->andReturnUsing(function (array $where = []) {
            $id = (int) ($where['order_item_id'] ?? 0);
            if ($id > 0 && isset($this->stored[$id])) {
                $r = new Roster(array_merge($this->stored[$id], [
                    'customer_id' => 1, 'player_index' => 0, 'first_name' => 'Synthetic', 'last_name' => 'Player',
                ]));
                $r->setKey($id);
                return $r;
            }
            return null;
        });
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturnUsing(function (array $where) {
            $order_id = (int) ($where['order_id'] ?? 0);
            $rows = [];
            foreach ($this->stored as $row) {
                if ((int) $row['order_id'] === $order_id) {
                    $rows[] = (object) $row;
                }
            }
            return new RostersCollection($rows);
        });
        $repo->shouldReceive('syncOrderStatusForKeptRows')->andReturnUsing(function (int $order_id, string $status) {
            $this->synced[] = [$order_id, $status];
            return 1;
        });
        $repo->shouldReceive('create')->andReturnUsing(function (array $data) {
            unset($data['_skip_age_group_validation']);
            if (is_callable($this->createDataSpy)) {
                ($this->createDataSpy)($data);
            }
            $failing_id = (int) ($data['order_item_id'] ?? 0);
            if (isset($this->createDbError[$failing_id])) {
                $this->wpdb->last_error = $this->createDbError[$failing_id];
                return null;
            }
            $roster = new Roster($data);
            $roster->setSkipAgeGroupValidation(true);
            $roster->validate();
            $id = (int) $data['order_item_id'];
            $roster->setKey($id);
            $this->created[] = $id;
            // Keep the written values so a later rebuild can compare them with the order line.
            $this->stored[$id] = array_merge($data, ['order_item_id' => $id, 'order_id' => (int) $data['order_id'], 'order_status' => 'completed']);
            return $roster;
        });
        $repo->shouldReceive('update')->andReturnUsing(function ($id, array $data) {
            unset($data['_skip_age_group_validation']);
            // Like RosterRepository::update(): merge into the stored row, then validate.
            $stored_row = $this->stored[(int) ($data['order_item_id'] ?? $id)] ?? [];
            $data = array_merge(
                ['customer_id' => 1, 'player_index' => 0, 'first_name' => 'Synthetic', 'last_name' => 'Player', 'dob' => '2018-01-01', 'gender' => 'male'],
                $stored_row,
                $data
            );
            $roster = new Roster($data);
            $roster->setSkipAgeGroupValidation(true);
            $roster->validate();
            $this->stored[(int) ($data['order_item_id'] ?? $id)] = $data;
            $this->updated[] = (int) ($data['order_item_id'] ?? $id);
            return $roster;
        });
        $repo->shouldReceive('fillFields')->andReturnUsing(function ($id, array $fields) {
            unset($fields['_skip_age_group_validation']);
            // Like RosterRepository::fillFields(): validate only the filled fields, then write them.
            $item_id = (int) $id;
            $stored_row = $this->stored[$item_id] ?? [];
            $roster = new Roster(array_merge($stored_row, $fields));
            $roster->validateOnly(array_keys($fields));
            $start = (string) ($roster->start_date ?? '');
            $end = (string) ($roster->end_date ?? '');
            if ($start !== '' && $end !== '' && strtotime($end) < strtotime($start)) {
                throw new \InterSoccer\ReportsRosters\Exceptions\ValidationException('End date cannot be before start date');
            }
            $this->stored[$item_id] = array_merge($stored_row, $fields);
            $this->updated[] = $item_id;
            return $roster;
        });
        $repo->shouldReceive('deleteByOrderItemId')->andReturnUsing(function (int $id) {
            $this->deleted[] = $id;
            unset($this->stored[$id]);
            return 1;
        });
        $repo->shouldNotReceive('deleteWhere');

        return new RosterBuilder($logger, $database, $player_repo, $repo, $validator, Mockery::mock(EventMatcher::class), $matcher);
    }

    private function routeOrders(array $listed_ids): void {
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = function ($args) use ($listed_ids) {
            if ((int) ($args['offset'] ?? 0) > 0) {
                return [];
            }
            $this->ordersQueryStatuses[] = $args['status'] ?? null;
            return $listed_ids;
        };
        $intersoccer_test_wc_get_order_callback = static fn ($oid) => ReportAccuracyStore::$orders[(int) $oid] ?? false;
    }

    /** Six age cases on six completed orders, plus an existing row for a line removed from the first order. */
    private function seedAgeCases(): void {
        $this->order(8101, 'completed', [8001 => $this->meta()]);                                   // under-age (2)
        $this->order(8102, 'completed', [8002 => $this->meta()]);                                   // over-age (8)
        $this->order(8103, 'completed', [8003 => $this->meta(['Age Group' => 'Pre-K to G5', 'pa_age-group' => 'pre-k-to-g5'])]);
        $this->order(8104, 'completed', [8004 => $this->meta(['Start Date' => ''])]);               // no start date
        $this->order(8105, 'completed', [8005 => $this->meta()]);                                   // no DOB (placeholder): model rejects the write, old row must stay
        $this->order(8106, 'completed', [8006 => $this->meta(['Age Group' => '5-12', 'pa_age-group' => '5-12'])]); // ok, was unparseable on master
        foreach ([8001 => 8101, 8002 => 8102, 8003 => 8103, 8004 => 8104, 8005 => 8105, 8006 => 8106, 8999 => 8101] as $item => $order) {
            $this->existingRow($item, $order);
        }
    }

    private function ageDobs(): array {
        return [8001 => '2024-01-01', 8002 => '2018-01-01', 8005 => '1970-01-01', 8006 => '2018-01-01'];
    }

    private function snapshotOrderWrites(): array {
        return [count(ReportAccuracyStore::$writes), (int) ($GLOBALS['intersoccer_test_order_meta_write_count'] ?? 0)];
    }

    private static function ids(array $rows): array {
        $ids = array_map(static fn ($r) => (int) $r['order_item_id'], $rows);
        sort($ids);
        return $ids;
    }

    // ---- tests -----------------------------------------------------------------------------------------------

    public function test_full_rebuild_keeps_every_row_and_flags_age_cases(): void {
        $this->seedAgeCases();
        $this->routeOrders([8101, 8102, 8103, 8104, 8105, 8106]);
        $before = $this->snapshotOrderWrites();

        $result = $this->builder($this->ageDobs())->buildRosters(['clear_existing' => true, 'batch_size' => 50]);

        $this->assertEmpty($result['rebuild_failed'] ?? null);
        $this->assertSame([['completed', 'processing', 'on-hold']], $this->ordersQueryStatuses);
        $this->assertSame([], $this->created, 'every line already has a row');
        // The seeded rows only hold ids and status: their empty fields are filled, nothing is overwritten.
        $this->assertSame([8001, 8002, 8003, 8004, 8005, 8006], $this->updated, 'empty fields are filled');
        $this->assertSame(6, $result['row_fill']['count']);
        $this->assertSame(0, $result['row_fill']['failed']);
        $this->assertSame(0, $result['row_drift']['count']);
        $this->assertSame('completed', $this->stored[8001]['order_status'], 'non-empty fields are untouched');
        $this->assertSame([], $this->deleted, 'a rebuild never deletes');
        $this->assertArrayHasKey(8005, $this->stored, 'a line without a DOB keeps its old row');
        $this->assertArrayHasKey(8999, $this->stored, 'the removed line keeps its row');
        $this->assertSame(['line_removed' => 1], $result['rows_for_review']['by_reason']);
        $this->assertSame(8999, $result['rows_for_review']['rows'][0]['order_item_id']);
        $this->assertSame([8001, 8002], self::ids($result['age_mismatches']));
        $not_checked = $result['not_checked']['order_item_ids'];
        sort($not_checked);
        $this->assertSame([8003, 8004, 8005], $not_checked);
        $this->assertSame(3, $result['not_checked']['count']);
        $this->assertCount(1, $this->adminLog);
        $this->assertSame('rebuild_age_mismatch', $this->adminLog[0][0]);
        $this->assertSame([8001, 8002], $this->adminLog[0][1]['order_item_ids']);
        $this->assertSame($before, $this->snapshotOrderWrites(), 'nothing written to orders or order meta');
    }

    public function test_full_rebuild_without_mismatches_writes_no_admin_log(): void {
        $this->order(8201, 'completed', [8211 => $this->meta(['Age Group' => '3-12y', 'pa_age-group' => '3-12y'])]);
        $this->existingRow(8211, 8201);
        $this->routeOrders([8201]);

        $result = $this->builder([8211 => '2018-01-01'])->buildRosters(['clear_existing' => true]);

        $this->assertSame([], $result['age_mismatches']);
        $this->assertSame([], $this->adminLog);
        $this->assertSame([], $this->deleted);
    }

    public function test_full_rebuild_statuses_keep_every_row_and_list_failed_and_removed_for_review(): void {
        $this->order(7001, 'cancelled', [7101 => $this->meta()]);
        $this->order(7002, 'refunded', [7102 => $this->meta()]);
        $this->order(7003, 'failed', [7103 => $this->meta()]);
        $this->order(7004, 'pending', [7104 => $this->meta()]);
        $this->order(7005, 'on-hold', [7105 => $this->meta()]);
        $this->order(7006, 'processing', [7106 => $this->meta()]);
        $this->order(7007, 'completed', [7107 => $this->meta()]);
        $this->existingRow(7101, 7001, 'cancelled');
        $this->existingRow(7102, 7002, 'refunded');
        $this->existingRow(7103, 7003);
        $this->existingRow(7104, 7004);
        $this->existingRow(7105, 7005);
        $this->existingRow(7106, 7006);
        $this->existingRow(7108, 7007); // line no longer on the order
        // wc_get_orders filters by status; the store lists only what the status filter would return.
        $this->routeOrders([7005, 7006, 7007]);
        $before = $this->snapshotOrderWrites();

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([7107], $this->created, 'the completed line without a row is added');
        $updated = $this->updated;
        sort($updated);
        $this->assertSame([7105, 7106], $updated, 'on-hold and processing rows only get empty fields filled');
        $this->assertSame(0, $result['row_drift']['count']);
        $this->assertSame([], $this->deleted, 'a rebuild never deletes');
        $this->assertArrayHasKey(7103, $this->stored, 'failed-order row is kept for review');
        $this->assertArrayHasKey(7108, $this->stored, 'removed-line row is kept for review');
        $review = [];
        foreach ($result['rows_for_review']['rows'] as $row) {
            $review[$row['order_item_id']] = $row['reason'];
        }
        ksort($review);
        $this->assertSame([7103 => 'order_failed', 7108 => 'line_removed'], $review);
        $this->assertArrayHasKey(7101, $this->stored);
        $this->assertArrayHasKey(7102, $this->stored);
        $this->assertArrayHasKey(7104, $this->stored, 'pending row is kept');
        $this->assertContains([7001, Roster::STATUS_CANCELLED], $this->synced);
        $this->assertContains([7002, Roster::STATUS_REFUNDED], $this->synced);
        $this->assertSame($before, $this->snapshotOrderWrites());
    }

    public function test_rebuild_lists_for_review_exactly_what_reconcile_deletes(): void {
        $this->seedAgeCases();
        $this->order(8107, 'failed', [8007 => $this->meta()]);
        $this->existingRow(8007, 8107);
        $this->routeOrders([8101, 8102, 8103, 8104, 8105, 8106]);
        $stored_seed = $this->stored;
        $existing_seed = $this->wpdb->existing;

        $rebuild = $this->builder($this->ageDobs())->buildRosters(['clear_existing' => true]);
        $rebuild_upserts = array_merge($this->created, $this->updated);
        sort($rebuild_upserts);
        $rebuild_deleted = $this->deleted;
        sort($rebuild_deleted);

        $this->created = $this->updated = $this->deleted = $this->synced = [];
        $this->stored = $stored_seed;
        $this->wpdb->existing = $existing_seed;
        $this->adminLog = [];

        $reconcile = $this->builder($this->ageDobs())->reconcile([]);
        $reconcile_upserts = array_merge($this->created, $this->updated);
        sort($reconcile_upserts);
        $reconcile_deleted = $this->deleted;
        sort($reconcile_deleted);

        // Reconcile still deletes obsolete rows; the rebuild keeps the same rows and lists them for review.
        $this->assertSame([], $rebuild_deleted);
        $this->assertSame(0, $rebuild['row_drift']['count'], 'only empty fields were filled');
        $review = array_map(static fn ($row) => $row['order_item_id'], $rebuild['rows_for_review']['rows']);
        sort($review);
        $this->assertSame($reconcile_deleted, $review);
        $this->assertSame([8007, 8999], $review);
        $this->assertSame(self::ids($rebuild['age_mismatches']), self::ids($reconcile['age_mismatches']));
    }

    public function test_full_rebuild_transaction_failure_reports_error_and_changes_nothing(): void {
        $this->seedAgeCases();
        $this->routeOrders([8101]);
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($this->wpdb);
        $database->shouldReceive('transaction')->andReturn(false);

        $result = $this->builder($this->ageDobs(), [], $database)->buildRosters(['clear_existing' => true]);

        $this->assertTrue($result['rebuild_failed']);
        $this->assertSame([], $this->deleted);
        $this->assertSame([], $this->adminLog);
        $this->assertCount(7, $this->stored);
    }

    public function test_rebuild_specific_orders_age_cases_and_removed_line(): void {
        $this->seedAgeCases();
        $this->routeOrders([]);
        $before = $this->snapshotOrderWrites();

        $result = $this->builder($this->ageDobs())->rebuildSpecificOrders([8101, 8102, 8103, 8104, 8105, 8106]);

        $this->assertSame([], $this->created);
        $this->assertSame(0, $result['row_drift']['count'], 'existing rows are not overwritten, only empty fields filled');
        $this->assertSame([], $this->deleted, 'a rebuild never deletes');
        $this->assertArrayHasKey(8999, $this->stored);
        $this->assertSame(['line_removed' => 1], $result['rows_for_review']['by_reason']);
        $this->assertArrayHasKey(8005, $this->stored);
        $this->assertSame([8001, 8002], self::ids($result['age_mismatches']));
        $this->assertSame(3, $result['not_checked']['count'], 'start date, school-grade label and placeholder DOB');
        $this->assertCount(1, $this->adminLog);
        $this->assertSame($before, $this->snapshotOrderWrites());
    }

    public function test_rebuild_specific_orders_build_error_keeps_all_rows_of_that_order(): void {
        $this->order(8301, 'completed', [8401 => $this->meta(), 8402 => $this->meta()]);
        $this->existingRow(8401, 8301);
        $this->existingRow(8402, 8301);
        $this->existingRow(8409, 8301); // removed line, but the order did not build cleanly
        $this->routeOrders([]);

        $result = $this->builder([], [8402])->rebuildSpecificOrders([8301]);

        $this->assertSame([], $this->deleted);
        $this->assertArrayHasKey(8402, $this->stored);
        $this->assertArrayHasKey(8409, $this->stored);
        $this->assertNotEmpty($result['statistics']['errors']);
    }

    public function test_rebuild_specific_orders_no_assigned_players_skips_removed_line_delete(): void {
        $this->order(8350, 'completed', [8450 => $this->meta()]);
        $this->existingRow(8450, 8350);
        $this->existingRow(8459, 8350);
        $this->routeOrders([]);

        $builder = $this->builder();
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn(new PlayersCollection());
        (function () use ($matcher) { $this->player_matcher = $matcher; })->call($builder);

        $result = $builder->rebuildSpecificOrders([8350]);

        $this->assertSame([], $this->deleted);
        $this->assertArrayHasKey(8459, $this->stored);
        $this->assertNotEmpty($result['statistics']['warnings']);
    }

    public function test_rebuild_specific_orders_inactive_statuses_list_rows_for_review(): void {
        $this->order(7201, 'cancelled', [7301 => $this->meta()]);
        $this->order(7202, 'refunded', [7302 => $this->meta()]);
        $this->order(7203, 'failed', [7303 => $this->meta()]);
        $this->order(7204, 'pending', [7304 => $this->meta()]);
        $this->existingRow(7301, 7201, 'cancelled');
        $this->existingRow(7302, 7202, 'refunded');
        $this->existingRow(7303, 7203);
        $this->existingRow(7304, 7204);
        $this->existingRow(7305, 7299); // order no longer exists
        $this->routeOrders([]);

        $result = $this->builder()->rebuildSpecificOrders([7201, 7202, 7203, 7204, 7299]);

        $this->assertSame([], $this->deleted, 'a rebuild never deletes');
        $this->assertSame([], $this->created);
        $review = [];
        foreach ($result['rows_for_review']['rows'] as $row) {
            $review[$row['order_item_id']] = $row['reason'];
        }
        ksort($review);
        $this->assertSame([7303 => 'order_failed', 7305 => 'order_not_found'], $review);
        $this->assertContains([7201, Roster::STATUS_CANCELLED], $this->synced);
        $this->assertContains([7202, Roster::STATUS_REFUNDED], $this->synced);
        $this->assertSame(5, $result['statistics']['skipped_orders']);
        $this->assertSame([], $result['statistics']['errors']);
    }

    // ---- #160 review: no overwrite, drift report, dry run, DB-logging guard ------------------------------------

    /** Build rows for three single-line orders once, so stored rows match their order lines. */
    private function seedBuiltRows(): array {
        $this->order(9101, 'completed', [9001 => $this->meta()]);
        $this->order(9102, 'completed', [9002 => $this->meta()]);
        $this->order(9103, 'completed', [9003 => $this->meta()]);
        $this->routeOrders([9101, 9102, 9103]);
        $this->builder()->buildRosters(['clear_existing' => true]);
        $this->assertSame([9001, 9002, 9003], $this->created);
        $this->created = $this->updated = [];
        foreach ([9001 => 9101, 9002 => 9102, 9003 => 9103] as $item => $order) {
            $this->wpdb->existing[] = ['order_item_id' => (string) $item, 'order_id' => (string) $order];
        }

        return [9001, 9002, 9003];
    }

    public function test_full_rebuild_matching_rows_report_no_drift(): void {
        $this->seedBuiltRows();

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([], $this->created);
        $this->assertSame([], $this->updated);
        $this->assertSame(0, $result['row_drift']['count'], 'rows built from the same order line do not drift');
    }

    public function test_full_rebuild_reports_drift_without_overwriting_and_counts_moves(): void {
        global $intersoccer_test_wc_order_item_meta;
        $this->seedBuiltRows();
        // 9001: roster row points at another variation (moved before the move audit existed).
        $this->stored[9001]['variation_id'] = 99999;
        // 9002: moved with the audit meta on the line; only the venue differs.
        $this->stored[9002]['venue'] = 'Old Venue';
        $this->order(9102, 'completed', [9002 => $this->meta(['_intersoccer_roster_move_to_variation_id' => '4242'])]);
        $intersoccer_test_wc_order_item_meta[9002]['_intersoccer_roster_move_to_variation_id'] = 4242;
        // 9003: hand-edited venue, never moved.
        $this->stored[9003]['venue'] = 'Old Venue';

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([], $this->updated, 'drifted rows are not overwritten');
        $this->assertSame(99999, (int) $this->stored[9001]['variation_id']);
        $this->assertSame('Old Venue', $this->stored[9003]['venue']);
        $this->assertSame(3, $result['row_drift']['count']);
        $this->assertSame(2, $result['row_drift']['moved']);
        $this->assertSame(1, $result['row_drift']['not_moved']);
        $by_item = [];
        foreach ($result['row_drift']['rows'] as $row) {
            $by_item[$row['order_item_id']] = $row;
        }
        $this->assertContains('variation_id', $by_item[9001]['fields']);
        $this->assertSame(['venue'], $by_item[9002]['fields']);
        $this->assertTrue($by_item[9002]['moved']);
        $this->assertSame(['venue'], $by_item[9003]['fields']);
        $this->assertFalse($by_item[9003]['moved']);
        unset($intersoccer_test_wc_order_item_meta[9002]);
    }

    public function test_full_rebuild_fills_only_empty_fields_and_counts_no_conflict(): void {
        $this->seedBuiltRows();
        $start = $this->stored[9001]['start_date'];
        $season = $this->stored[9001]['season'];
        $this->assertNotEmpty($start);
        $this->stored[9001]['start_date'] = '';
        $this->stored[9001]['season'] = 'N/A';

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([9001], $this->updated, 'only the row with empty fields is written');
        $this->assertSame(1, $result['row_fill']['count']);
        $this->assertSame(['season' => 1, 'start_date' => 1], (function ($a) { ksort($a); return $a; })($result['row_fill']['by_field']));
        $this->assertSame(0, $result['row_drift']['count'], 'filled fields are not conflicts');
        $this->assertEquals($start, $this->stored[9001]['start_date']);
        $this->assertSame($season, $this->stored[9001]['season']);
    }

    public function test_season_differing_only_in_case_is_not_a_conflict(): void {
        $this->seedBuiltRows();
        $this->stored[9002]['season'] = strtoupper((string) $this->stored[9002]['season']);

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame(0, $result['row_drift']['count']);
        $this->assertSame([], $this->updated);
    }

    public function test_times_are_never_overwritten_or_filled_and_carry_a_trace(): void {
        $this->seedBuiltRows();
        $this->stored[9001]['times'] = '09:00-16:00';
        $this->stored[9002]['times'] = '';

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([], $this->updated, 'times are neither overwritten nor filled');
        $this->assertSame('09:00-16:00', $this->stored[9001]['times']);
        $this->assertSame('', $this->stored[9002]['times']);
        $this->assertSame(1, $result['row_fill']['held_times']);
        $this->assertSame(1, $result['row_drift']['count']);
        $this->assertSame(['times' => 1], $result['row_drift']['by_field']);
        $row = $result['row_drift']['rows'][0];
        $this->assertSame(9001, $row['order_item_id']);
        $this->assertSame('09:00-16:00', $row['values']['times']['roster']);
        $this->assertArrayHasKey('from_line', $row['times_trace']);
        $this->assertArrayHasKey('after_backfill', $row['times_trace']);
        $this->assertArrayHasKey('stored_as', $row['times_trace']);
        $this->assertArrayHasKey('line_meta', $row['times_trace']);
    }

    public function test_date_conflict_is_reported_with_both_values_and_kept(): void {
        $this->seedBuiltRows();
        $this->stored[9003]['start_date'] = '2026-08-04';

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([], $this->updated);
        $this->assertSame('2026-08-04', $this->stored[9003]['start_date']);
        $row = $result['row_drift']['rows'][0];
        $this->assertSame(['start_date'], $row['fields']);
        $this->assertSame('2026-08-04', $row['values']['start_date']['roster']);
        $this->assertNotSame('2026-08-04', $row['values']['start_date']['order_line']);
    }

    public function test_row_without_customer_id_or_dob_still_gets_empty_fields_filled(): void {
        $this->seedAgeCases();
        $this->routeOrders([8101]);

        $result = $this->builder($this->ageDobs())->buildRosters(['clear_existing' => true]);

        // The seeded row has no customer_id or dob: only the filled fields are validated.
        $this->assertArrayNotHasKey('customer_id', $this->stored[8001]);
        $this->assertArrayNotHasKey('dob', $this->stored[8001]);
        $this->assertContains(8001, $this->updated);
        $this->assertSame(0, $result['row_fill']['failed']);
        $this->assertSame('venue-a', $this->stored[8001]['venue']);
    }

    public function test_date_half_is_not_filled_when_the_other_half_conflicts(): void {
        $this->seedBuiltRows();
        $this->stored[9001]['start_date'] = '2026-08-04';
        $this->stored[9001]['end_date'] = '';

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([], $this->updated, 'end date is not filled next to an edited start date');
        $this->assertSame('', $this->stored[9001]['end_date']);
        $this->assertSame(0, $result['row_fill']['count']);
        $fields = $result['row_drift']['rows'][0]['fields'];
        sort($fields);
        $this->assertSame(['end_date', 'start_date'], $fields);
    }

    public function test_failed_fill_reports_fields_and_reason_and_keeps_the_row(): void {
        // The order line's own end date is before its start date, so filling it must fail.
        $this->order(9401, 'completed', [9411 => $this->meta(['End Date' => 'June 1, 2026'])]);
        $this->existingRow(9411, 9401);
        $this->stored[9411]['start_date'] = '2026-07-01';
        $this->stored[9411]['end_date'] = '';
        $this->routeOrders([9401]);

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame(1, $result['row_fill']['failed']);
        $this->assertSame(['End date cannot be before start date' => 1], $result['row_fill']['failed_by_reason']);
        $row = $result['row_fill']['failed_rows'][0];
        $this->assertSame(9411, $row['order_item_id']);
        $this->assertContains('end_date', $row['fields']);
        $this->assertSame('2026-07-01', $row['dates']['start_date']['roster']);
        $this->assertSame('', $this->stored[9411]['end_date'], 'the row is kept as it was');
        $this->assertNotContains(9411, $this->updated);
    }

    public function test_missing_line_with_start_year_outside_its_season_is_held(): void {
        $this->order(9601, 'completed', [9611 => $this->meta(['Start Date' => 'January 4, 2025', 'End Date' => 'March 29, 2025', 'Season' => 'Winter 2026', 'pa_program-season' => 'winter-2026'])]);
        $this->routeOrders([9601]);

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([], $this->created, 'a row with a suspect date is not added');
        $held = $result['created']['held_suspect_dates'];
        $this->assertSame(1, $held['count']);
        $this->assertSame(9611, $held['rows'][0]['order_item_id']);
        $this->assertSame('2025-01-04', $held['rows'][0]['start_date']);
        $this->assertStringContainsString('2026', $held['rows'][0]['season']);
        $this->assertArrayHasKey('product_id', $held['rows'][0]);
    }

    public function test_event_and_tournament_orders_get_roster_rows(): void {
        foreach (['Event' => 9801, 'Tournament' => 9802] as $type => $oid) {
            $this->order($oid, 'completed', [$oid + 10 => $this->meta(['Activity Type' => $type, 'pa_activity-type' => strtolower($type)])]);
        }
        $this->routeOrders([9801, 9802]);
        $builder = $this->builder();

        // The path new orders take (order hooks -> buildRosterFromOrder).
        $builder->buildRosterFromOrder(9801);
        $builder->buildRosterFromOrder(9802);

        $this->assertSame([9811, 9812], $this->created);
        $this->assertSame([], $builder->getBuildStatistics()['errors']);
    }

    public function test_line_error_reports_validation_fields(): void {
        // 'Other' is still outside the allowed activity types.
        $this->order(9901, 'completed', [9911 => $this->meta(['Activity Type' => 'Other', 'pa_activity-type' => 'other'])]);
        $this->routeOrders([9901]);

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([], $this->created);
        $errors = $result['line_errors'];
        $this->assertSame(1, $errors['count']);
        $this->assertSame(['ValidationException: activity_type' => 1], $errors['by_reason']);
        $this->assertSame(9911, $errors['rows'][0]['order_item_id']);
        $this->assertSame('Other', $errors['rows'][0]['activity_type']);
        $this->assertArrayHasKey('activity_type', $errors['rows'][0]['validation_errors']);
    }

    private function invokeBuilder(RosterBuilder $builder, string $method, ...$args) {
        $m = new \ReflectionMethod(RosterBuilder::class, $method);
        $m->setAccessible(true);
        return $m->invoke($builder, ...$args);
    }

    public function test_event_date_parser_reads_english_french_german_and_slugs_and_needs_a_year(): void {
        $builder = $this->builder();
        $cases = [
            '2026-03-14' => '2026-03-14',
            '14/03/2026' => '2026-03-14',
            '14.03.2026' => '2026-03-14',
            'March 14, 2026' => '2026-03-14',
            'Saturday 14 March 2026' => '2026-03-14',
            'Samedi 14 mars 2026' => '2026-03-14',
            '14 mars 2026' => '2026-03-14', // the shared parser reads this as 2026-03-15
            'Samstag, 14. März 2026' => '2026-03-14',
            '14. Maerz 2026' => '2026-03-14',
            'samedi-14-mars-2026' => '2026-03-14',
            'Dimanche 1er février 2026' => '2026-02-01',
            'Saturday 14 March' => null, // no year: not guessed
            '31 février 2026' => null,
            'TBA' => null,
        ];
        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, $this->invokeBuilder($builder, 'parseEventDateStrict', $raw), $raw);
        }
    }

    public function test_first_parsable_event_date_skips_unparseable_candidates_in_order(): void {
        [$date, $source] = $this->invokeBuilder($this->builder(), 'firstParsableEventDate', [
            'variation:pa_date' => 'TBA',
            'product:pa_date' => 'Samedi 14 mars 2026',
            'line:Date' => '2026-04-01',
        ]);
        $this->assertSame('2026-03-14', $date);
        $this->assertSame('product:pa_date', $source);
    }

    public function test_tournament_date_comes_from_the_variation_before_the_order_line_label(): void {
        $live = DeletedProductFixture::$catalogue[DeletedProductFixture::LIVE_VID];
        $attributes = [];
        foreach ($live->get_variation_attributes() as $key => $value) {
            $attributes[substr($key, strlen('attribute_'))] = $value;
        }
        $attributes['pa_date'] = 'Saturday 14 March 2026';
        DeletedProductFixture::$catalogue[DeletedProductFixture::LIVE_VID] = new \InterSoccer\ReportsRosters\Tests\ReportAccuracy\FakeVariation(
            DeletedProductFixture::LIVE_VID,
            DeletedProductFixture::LIVE_PID,
            $attributes,
            'Synthetic Tournament'
        );
        $this->order(9991, 'completed', [9992 => $this->meta([
            'Activity Type' => 'Tournament', 'pa_activity-type' => 'tournament',
            'Start Date' => '', 'End Date' => '',
            'Date' => 'Samedi 21 mars 2026',
        ])]);
        $this->routeOrders([9991]);
        $captured = null;
        $this->createDataSpy = function (array $data) use (&$captured) { $captured = $data; };

        $builder = $this->builder();
        $builder->buildRosterFromOrder(9991);

        $this->assertSame([9992], $this->created);
        $this->assertSame('2026-03-14', $this->normalizeDate($captured['start_date']), "the variation's date wins over the line label");
        $this->assertSame('2026-03-14', $this->normalizeDate($captured['end_date']));
    }

    public function test_tournament_french_date_label_is_used_when_the_variation_has_no_date(): void {
        $this->order(9993, 'completed', [9994 => $this->meta([
            'Activity Type' => 'Tournament', 'pa_activity-type' => 'tournament',
            'Start Date' => '', 'End Date' => '',
            'Date' => 'Samedi 14 mars 2026',
        ])]);
        $this->routeOrders([9993]);
        $captured = null;
        $this->createDataSpy = function (array $data) use (&$captured) { $captured = $data; };

        $builder = $this->builder();
        $builder->buildRosterFromOrder(9993);

        $this->assertSame([9994], $this->created);
        $this->assertSame('2026-03-14', $this->normalizeDate($captured['start_date']));
    }

    public function test_tournament_without_a_readable_date_is_reported(): void {
        $this->order(9995, 'completed', [9996 => $this->meta([
            'Activity Type' => 'Tournament', 'pa_activity-type' => 'tournament',
            'Start Date' => '', 'End Date' => '',
            'Date' => 'Saturday 14 March',
        ])]);
        $this->routeOrders([9995]);

        $result = $this->builder()->buildRosters(['clear_existing' => true, 'dry_run' => false]);

        $this->assertSame(1, $result['unparsed_event_dates']['count']);
        $this->assertSame(9996, $result['unparsed_event_dates']['rows'][0]['order_item_id']);
        $this->assertSame('Saturday 14 March', $result['unparsed_event_dates']['rows'][0]['values']['line:Date']);
        $this->assertSame(1, $result['created']['held_no_dates']['count'], 'an undated tournament is held, not added');
    }

    private function normalizeDate($value): string {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }

    public function test_long_camp_terms_label_is_truncated_to_the_event_type_column_and_reported(): void {
        // A 352-character multi-week "Camp Terms" label, with a multibyte character at the cut.
        $label = str_repeat('Été Semaine 1 : 30 juin – 4 juillet (5 jours), ', 8);
        $label = mb_substr($label, 0, 352, 'UTF-8');
        $this->assertSame(352, mb_strlen($label, 'UTF-8'));
        $this->order(9971, 'completed', [9981 => $this->meta(['Camp Terms' => $label])]);
        $this->routeOrders([9971]);
        $captured = null;
        $this->createDataSpy = function (array $data) use (&$captured) { $captured = $data; };

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([9981], $this->created, 'the line now gets a row');
        $this->assertSame(0, $result['line_errors']['count']);
        $this->assertSame(191, mb_strlen((string) $captured['event_type'], 'UTF-8'));
        $this->assertSame(mb_substr($label, 0, 191, 'UTF-8'), $captured['event_type']);
        $this->assertTrue(mb_check_encoding((string) $captured['event_type'], 'UTF-8'), 'cut on a character boundary');
        $this->assertSame(1, $result['truncated']['count']);
        $row = $result['truncated']['rows'][0];
        $this->assertSame(9981, $row['order_item_id']);
        $this->assertSame('event_type', $row['field']);
        $this->assertSame(352, $row['original_length']);
        $this->assertArrayHasKey('camp_terms_holds_full_value', $row);
    }

    public function test_event_type_within_the_column_is_unchanged(): void {
        $this->order(9972, 'completed', [9982 => $this->meta(['Camp Terms' => 'Summer Week 1: June 30 - July 4 (5 days)'])]);
        $this->routeOrders([9972]);
        $captured = null;
        $this->createDataSpy = function (array $data) use (&$captured) { $captured = $data; };

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame('Summer Week 1: June 30 - July 4 (5 days)', $captured['event_type']);
        $this->assertSame(0, $result['truncated']['count']);
    }

    public function test_too_long_insert_reports_column_value_length_and_width(): void {
        $this->order(9951, 'completed', [9961 => $this->meta(['Camp Terms' => str_repeat('Summer Week 1: June 30 - July 4 (5 days), ', 6)])]);
        $this->routeOrders([9951]);
        $this->createDbError[9961] = "Data too long for column 'event_type' at row 1";
        $this->wpdb->describe = [['Field' => 'event_type', 'Type' => 'varchar(100)']];

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $errors = $result['line_errors'];
        $this->assertSame(1, $errors['count']);
        $this->assertSame(['Data too long for column event_type' => 1], $errors['by_reason']);
        $too_long = $errors['rows'][0]['too_long'];
        $this->assertSame('event_type', $too_long['column']);
        $this->assertSame(100, $too_long['column_width']);
        $this->assertTrue($too_long['column_narrower_than_schema'], 'varchar(100) is below the 191 schema 2.4.0 sets');
        $this->assertNotNull($too_long['value_length'], 'the length of the value that did not fit is reported');
    }

    public function test_missing_line_without_dates_is_held_not_created(): void {
        $this->order(9501, 'completed', [9511 => $this->meta(['Start Date' => '', 'End Date' => ''])]);
        $this->routeOrders([9501]);

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([], $this->created, 'an undated row is not added');
        $this->assertSame(0, $result['created']['count']);
        $held = $result['created']['held_no_dates'];
        $this->assertSame(1, $held['count']);
        $this->assertSame(9511, $held['rows'][0]['order_item_id']);
        $this->assertArrayHasKey('line_date_meta', $held['rows'][0]);
        $this->assertArrayHasKey('variation_schedule', $held['rows'][0]);
        $this->assertArrayHasKey('camp_terms_parse', $held['rows'][0]);
        $this->assertArrayHasKey('product_id', $held['rows'][0]);
        $this->assertArrayHasKey('product_schedule', $held['rows'][0]);
        $this->assertNull($held['rows'][0]['would_fail_validation'], 'this line would otherwise have been added');
        $this->assertSame(0, $held['would_fail_validation']);
        $this->assertNotEmpty($held['by_date_source_found']);
        $this->assertNotEmpty($held['by_activity_type']);
    }

    public function test_held_undated_line_that_would_also_fail_creation_is_counted_separately(): void {
        $this->order(9701, 'completed', [9711 => $this->meta(['Start Date' => '', 'End Date' => ''])]);
        $this->routeOrders([9701]);

        // Placeholder DOB: the model refuses the insert, so this line was never in earlier "to add" counts.
        $result = $this->builder([9711 => '1970-01-01'])->buildRosters(['clear_existing' => true]);

        $held = $result['created']['held_no_dates'];
        $this->assertSame(1, $held['count']);
        $this->assertSame(1, $held['would_fail_validation']);
        $this->assertNotNull($held['rows'][0]['would_fail_validation']);
        $this->assertSame([], $this->created);
    }

    public function test_created_rows_report_order_month_status_and_missing_products(): void {
        $this->order(9301, 'completed', [9311 => $this->meta()]);
        $this->routeOrders([9301]);

        $result = $this->builder()->buildRosters(['clear_existing' => true]);

        $this->assertSame([9311], $this->created);
        $this->assertSame(1, $result['created']['count']);
        $this->assertCount(1, $result['created']['by_order_month']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', (string) array_key_first($result['created']['by_order_month']));
        $this->assertSame(['completed' => 1], $result['created']['by_order_status']);
        $row = $result['created']['rows'][0];
        $this->assertSame(9311, $row['order_item_id']);
        $this->assertArrayHasKey('product_missing', $row);
        $this->assertArrayHasKey('product_id', $row);
        $this->assertArrayHasKey('season', $row);
        $this->assertArrayHasKey('start_year_not_in_season', $row);
        $this->assertCount(1, $result['created']['by_age_group']);
        $this->assertCount(1, $result['created']['by_start_month']);
        $this->assertArrayHasKey('variation_missing', $row);
    }

    public function test_overwrite_existing_opt_in_updates_rows(): void {
        $this->seedBuiltRows();
        $this->stored[9003]['venue'] = 'Old Venue';

        $result = $this->builder()->buildRosters(['clear_existing' => true, 'overwrite_existing' => true]);

        $updated = $this->updated;
        sort($updated);
        $this->assertSame([9001, 9002, 9003], $updated);
        $this->assertSame(0, $result['row_drift']['count']);
        $this->assertSame([], $this->deleted);
    }

    public function test_dry_run_rolls_back_and_writes_no_admin_log(): void {
        $this->seedAgeCases();
        $this->routeOrders([8101, 8102, 8103, 8104, 8105, 8106]);
        $calls = [];
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($this->wpdb);
        $database->shouldNotReceive('transaction');
        $database->shouldReceive('begin_transaction')->once()->andReturnUsing(function () use (&$calls) {
            $calls[] = 'begin';
            return true;
        });
        $database->shouldReceive('rollback_transaction')->once()->andReturnUsing(function () use (&$calls) {
            $calls[] = 'rollback';
            return true;
        });

        $result = $this->builder($this->ageDobs(), [], $database)->buildRosters(['clear_existing' => true, 'dry_run' => true]);

        $this->assertTrue($result['dry_run']);
        $this->assertEmpty($result['rebuild_failed'] ?? null);
        $this->assertSame(['begin', 'rollback'], $calls);
        $this->assertSame([], $this->adminLog, 'a dry run writes no admin-log entry');
        $this->assertSame([8001, 8002], self::ids($result['age_mismatches']), 'counts are still reported');
        $this->assertSame(['line_removed' => 1], $result['rows_for_review']['by_reason']);
    }

    public function test_rebuild_specific_orders_dry_run_rolls_back(): void {
        $this->order(9201, 'completed', [9211 => $this->meta()]);
        $this->routeOrders([]);
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($this->wpdb);
        $database->shouldNotReceive('transaction');
        $database->shouldReceive('begin_transaction')->once()->andReturn(true);
        $database->shouldReceive('rollback_transaction')->once()->andReturn(true);

        $result = $this->builder([], [], $database)->rebuildSpecificOrders([9201], ['dry_run' => true]);

        $this->assertTrue($result['dry_run']);
        $this->assertEmpty($result['rebuild_failed'] ?? null);
    }

    public function test_rebuild_refused_while_db_logging_is_on(): void {
        $this->seedAgeCases();
        $this->routeOrders([8101]);
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($this->wpdb);
        $database->shouldNotReceive('transaction');
        $database->shouldNotReceive('begin_transaction');
        $builder = $this->builder([], [], $database);
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $logger->shouldReceive('is_db_logging_enabled')->andReturn(true);
        (function () use ($logger) { $this->logger = $logger; })->call($builder);

        $full = $builder->buildRosters(['clear_existing' => true]);
        $this->assertTrue($full['rebuild_failed']);
        $this->assertStringContainsString('database logging is on', $full['error']);

        $specific = $builder->rebuildSpecificOrders([8101]);
        $this->assertTrue($specific['rebuild_failed']);
        $this->assertStringContainsString('database logging is on', $specific['error']);
        $this->assertSame([], $this->created);
        $this->assertSame([], $this->deleted);
    }

    public function test_reconcile_still_counts_delete_failures_as_errors(): void {
        $this->order(8501, 'failed', [8601 => $this->meta()]);
        $this->existingRow(8601, 8501);
        $this->routeOrders([]);
        $builder = $this->builder();
        $fresh = Mockery::mock(RosterRepository::class);
        $fresh->shouldReceive('deleteByOrderItemId')->andThrow(new \RuntimeException('synthetic delete failure'));
        $fresh->shouldReceive('syncOrderStatusForKeptRows')->andReturn(0);
        (function () use ($fresh) { $this->roster_repository = $fresh; })->call($builder);

        $result = $builder->reconcile([]);

        $this->assertSame(1, $result['errors']);
        $this->assertStringContainsString('synthetic delete failure', implode(' ', $result['error_messages']));
    }

    public function test_ajax_rebuild_payload_and_failure(): void {
        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosters')->once()->andReturn([
            'orders_processed' => 2,
            'rosters_created' => 0,
            'errors' => [],
            'age_mismatches' => [['order_id' => 1, 'order_item_id' => 11, 'age_group' => '3-5y', 'age_at_start' => 7]],
            'not_checked' => ['count' => 1, 'order_item_ids' => [12]],
        ]);
        $handler = new RosterAjaxHandler(Mockery::mock(Logger::class)->shouldIgnoreMissing(), Mockery::mock(RosterRepository::class), $builder);

        ob_start();
        $handler->handleRebuildRosters();
        $json = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($json['success']);
        $this->assertSame(11, $json['data']['age_mismatches'][0]['order_item_id']);
        $this->assertSame(1, $json['data']['not_checked']['count']);
        $this->assertStringContainsString('Age outside group: 1', $json['data']['message']);

        $failing = Mockery::mock(RosterBuilder::class);
        $failing->shouldReceive('buildRosters')->once()->andReturn(['rebuild_failed' => true, 'errors' => ['x']]);
        $handler2 = new RosterAjaxHandler(Mockery::mock(Logger::class)->shouldIgnoreMissing(), Mockery::mock(RosterRepository::class), $failing);
        $GLOBALS['intersoccer_test_json_error_throws'] = true;
        // The test wp_send_json_error echoes then throws (production exits there). The handler's own catch
        // echoes a second error object, so read the first JSON object only.
        ob_start();
        try {
            $handler2->handleRebuildRosters();
        } catch (\RuntimeException $e) {
            // expected
        }
        $out = (string) ob_get_clean();
        $cut = strpos($out, '}{"success"');
        $first = json_decode($cut === false ? $out : substr($out, 0, $cut + 1), true);
        $this->assertStringNotContainsString('"success":true', $out, 'no success payload after a failed rebuild');
        $this->assertFalse($first['success']);
        $this->assertStringContainsString('no roster rows were changed', $first['data']['message']);
    }
}
