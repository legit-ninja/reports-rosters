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

class RosterRebuildCoverage160Wpdb extends DeletedProductWpdb {
    /** @var array<int,array<string,string>> */
    public $existing = [];

    public function get_results($query = null, $output = OBJECT) {
        if (preg_match('/SELECT order_item_id, order_id FROM \S*intersoccer_rosters/', (string) $query)) {
            return $this->existing;
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
        Functions\when('intersoccer_roster_admin_log_insert')->alias(function ($action, $sig = '', $payload = []) {
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
            $roster = new Roster($data);
            $roster->setSkipAgeGroupValidation(true);
            $roster->validate();
            $id = (int) $data['order_item_id'];
            $roster->setKey($id);
            $this->created[] = $id;
            $this->stored[$id] = ['order_item_id' => $id, 'order_id' => (int) $data['order_id'], 'order_status' => 'completed'];
            return $roster;
        });
        $repo->shouldReceive('update')->andReturnUsing(function ($id, array $data) {
            unset($data['_skip_age_group_validation']);
            $roster = new Roster($data);
            $roster->setSkipAgeGroupValidation(true);
            $roster->validate();
            $this->updated[] = (int) ($data['order_item_id'] ?? $id);
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
        $upserted = array_merge($this->created, $this->updated);
        sort($upserted);
        $this->assertSame([8001, 8002, 8003, 8004, 8006], $upserted, 'every line with a DOB is upserted');
        $this->assertSame([8999], $this->deleted, 'only the removed line is deleted');
        $this->assertArrayHasKey(8005, $this->stored, 'a line the model refuses (no DOB) keeps its old row');
        $this->assertSame([8001, 8002], self::ids($result['age_mismatches']));
        $not_checked = $result['not_checked']['order_item_ids'];
        sort($not_checked);
        $this->assertSame([8003, 8004], $not_checked);
        $this->assertSame(2, $result['not_checked']['count']);
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

    public function test_full_rebuild_statuses_keep_flags_delete_failed_and_rebuild_on_hold(): void {
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

        $this->builder()->buildRosters(['clear_existing' => true]);

        $upserted = array_merge($this->created, $this->updated);
        sort($upserted);
        $this->assertSame([7105, 7106, 7107], $upserted, 'on-hold, processing and completed are rebuilt');
        $deleted = $this->deleted;
        sort($deleted);
        $this->assertSame([7103, 7108], $deleted, 'failed order and removed line only');
        $this->assertArrayHasKey(7101, $this->stored);
        $this->assertArrayHasKey(7102, $this->stored);
        $this->assertArrayHasKey(7104, $this->stored, 'pending row is kept');
        $this->assertContains([7001, Roster::STATUS_CANCELLED], $this->synced);
        $this->assertContains([7002, Roster::STATUS_REFUNDED], $this->synced);
        $this->assertSame($before, $this->snapshotOrderWrites());
    }

    public function test_rebuild_and_reconcile_agree(): void {
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

        $this->assertSame($rebuild_upserts, $reconcile_upserts);
        $this->assertSame($rebuild_deleted, $reconcile_deleted);
        $this->assertSame([8007, 8999], $rebuild_deleted);
        $this->assertSame(self::ids($rebuild['age_mismatches']), self::ids($reconcile['age_mismatches']));
        $a = $rebuild['not_checked']['order_item_ids'];
        $b = $reconcile['not_checked']['order_item_ids'];
        sort($a);
        sort($b);
        $this->assertSame($a, $b);
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

        $upserted = array_merge($this->created, $this->updated);
        sort($upserted);
        $this->assertSame([8001, 8002, 8003, 8004, 8006], $upserted);
        $this->assertSame([8999], $this->deleted, 'removed line deleted after the build');
        $this->assertArrayHasKey(8005, $this->stored);
        $this->assertSame([8001, 8002], self::ids($result['age_mismatches']));
        $this->assertSame(2, $result['not_checked']['count']);
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

    public function test_rebuild_specific_orders_inactive_statuses_use_obsolete_rule(): void {
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

        $deleted = $this->deleted;
        sort($deleted);
        $this->assertSame([7303, 7305], $deleted);
        $this->assertSame([], $this->created);
        $this->assertContains([7201, Roster::STATUS_CANCELLED], $this->synced);
        $this->assertContains([7202, Roster::STATUS_REFUNDED], $this->synced);
        $this->assertSame(5, $result['statistics']['skipped_orders']);
        $this->assertSame([], $result['statistics']['errors']);
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
