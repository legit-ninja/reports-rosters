<?php

namespace InterSoccer\ReportsRosters\Tests\Services;

use Brain\Monkey\Functions;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Services\RosterRefundService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterRebuildKeepRows160Test extends TestCase {

    /** @var int[] */
    private $deletedItemIds = [];

    /** @var array<int,array<string,string>> */
    private $currentRows = [];

    protected function setUp(): void {
        parent::setUp();
        $this->deletedItemIds = [];
        $this->currentRows = [];
    }

    protected function tearDown(): void {
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        unset($GLOBALS['intersoccer_test_track_order_meta_writes'], $GLOBALS['intersoccer_test_order_meta_write_count']);
        $this->currentRows = [];
        parent::tearDown();
    }

    public function test_rebuild_statuses_filter_normalizes(): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value) {
            if ($hook === 'intersoccer_roster_rebuild_statuses') {
                return [' processing ', '', 99, 'completed'];
            }
            return $value;
        };
        $statuses = intersoccer_roster_rebuild_statuses();
        unset($GLOBALS['intersoccer_test_apply_filters_callback']);
        $this->assertSame(['processing', 'completed'], $statuses);
    }

    public function test_rebuild_statuses_filter_garbage_falls_back_to_default(): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value) {
            if ($hook === 'intersoccer_roster_rebuild_statuses') {
                return 'not-an-array';
            }
            return $value;
        };
        $this->assertSame(['completed', 'processing', 'on-hold'], intersoccer_roster_rebuild_statuses());
        unset($GLOBALS['intersoccer_test_apply_filters_callback']);
    }

    public function test_rebuild_statuses_option_a(): void {
        $this->assertSame(['completed', 'processing', 'on-hold'], intersoccer_roster_rebuild_statuses());
    }

    private function makeBuilder(): RosterBuilder {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturnUsing(function () {
            return $this->makeWpdb($this->currentRows);
        });
        $database->shouldReceive('transaction')->andReturnUsing(function (callable $callback) {
            return $callback();
        });

        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('deleteByOrderItemId')->andReturnUsing(function (int $id) {
            $this->deletedItemIds[] = $id;
            return 1;
        });
        $rosterRepository->shouldReceive('syncOrderStatusForKeptRows')->andReturn(0);
        $rosterRepository->shouldReceive('where')->andReturn(new \ArrayObject());

        return new RosterBuilder(
            $logger,
            $database,
            Mockery::mock(PlayerRepository::class),
            $rosterRepository,
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );
    }

    private function makeWpdb(array $rows): object {
        return new class($rows) {
            public $prefix = 'wp_';
            public $queries = [];
            /** @var array<int,array<string,string>> */
            private $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function get_results($query, $output = OBJECT) {
                $this->queries[] = (string) $query;
                return $output === ARRAY_A ? $this->rows : $this->rows;
            }
            public function get_var($query) { return 0; }
            public function prepare($query, ...$args) { return $query; }
            public function query($query) {
                $this->queries[] = (string) $query;
                return true;
            }
        };
    }

    private function invoke(object $obj, string $method, ...$args) {
        $m = (new \ReflectionClass($obj))->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    public function test_record_age_mismatch_and_not_checked(): void {
        $builder = $this->makeBuilder();
        $this->invoke($builder, 'init_build_stats');

        $young = [
            'order_id' => 10,
            'order_item_id' => 101,
            'customer_id' => 1,
            'player_index' => 0,
            'player_dob' => '2020-01-01',
            'player_first_name' => 'Kid',
            'player_last_name' => 'One',
            'player_gender' => 'male',
            'age_group' => '3-5y',
            'start_date' => '2026-07-01',
        ];
        $old = $young;
        $old['order_item_id'] = 102;
        $old['player_dob'] = '2010-01-01';
        $old['age_group'] = '3-5y';

        $unparsed = $young;
        $unparsed['order_item_id'] = 103;
        $unparsed['age_group'] = 'Pre-K to G5';

        $this->invoke($builder, 'recordAgeGroupBuildOutcome', $young);
        $this->invoke($builder, 'recordAgeGroupBuildOutcome', $old);
        $this->invoke($builder, 'recordAgeGroupBuildOutcome', $unparsed);

        $stats = $this->invoke($builder, 'getBuildStatistics');
        $this->assertCount(2, $stats['age_mismatches']);
        $this->assertSame(1, $stats['not_checked']['count']);
        $this->assertContains(103, $stats['not_checked']['order_item_ids']);
    }

    public function test_admin_log_once_when_mismatches(): void {
        $builder = $this->makeBuilder();
        $logged = [];
        Functions\when('intersoccer_roster_admin_log_insert')->alias(function ($action, $sig, $payload) use (&$logged) {
            $logged[] = [$action, $payload];
            return true;
        });

        $this->invoke($builder, 'logRebuildAgeMismatchAdminEntry', [
            ['order_item_id' => 501, 'order_id' => 50],
            ['order_item_id' => 502, 'order_id' => 50],
        ]);
        $this->invoke($builder, 'logRebuildAgeMismatchAdminEntry', []);

        $this->assertCount(1, $logged);
        $this->assertSame('rebuild_age_mismatch', $logged[0][0]);
        $this->assertSame(2, $logged[0][1]['mismatch_count']);
        $this->assertSame([501, 502], $logged[0][1]['order_item_ids']);
    }

    public function test_full_rebuild_without_orders_keeps_flagged_existing_row(): void {
        $orderId = 7001;
        $itemId = 9001;
        $this->currentRows = [
            ['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId],
        ];
        $builder = $this->makeBuilder();

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function () {
            return [];
        };
        $intersoccer_test_wc_get_order_callback = static function ($oid) use ($orderId, $itemId) {
            if ((int) $oid !== $orderId) {
                return false;
            }
            return new class($orderId, $itemId) {
                private $id;
                private $itemId;
                public function __construct($id, $itemId) { $this->id = $id; $this->itemId = $itemId; }
                public function get_id() { return $this->id; }
                public function get_type() { return 'shop_order'; }
                public function get_status() { return 'refunded'; }
                public function get_items() { return [$this->itemId => new \stdClass()]; }
            };
        };

        $result = $builder->buildRosters(['clear_existing' => true, 'batch_size' => 10]);
        $this->assertSame(0, $result['rosters_deleted'] ?? -1);
        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_full_rebuild_does_not_truncate(): void {
        $this->currentRows = [];
        $builder = $this->makeBuilder();
        $wpdb = $this->makeWpdb([]);
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($wpdb);
        $database->shouldReceive('transaction')->andReturnUsing(function (callable $callback) {
            return $callback();
        });
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $builder = new RosterBuilder(
            $logger,
            $database,
            Mockery::mock(PlayerRepository::class),
            Mockery::mock(RosterRepository::class),
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        global $intersoccer_test_wc_get_orders_callback;
        $intersoccer_test_wc_get_orders_callback = static function () {
            return [];
        };

        $builder->buildRosters(['clear_existing' => true]);
        foreach ($wpdb->queries as $sql) {
            $this->assertStringNotContainsString('TRUNCATE', strtoupper($sql));
        }
    }

    public function test_transaction_exception_leaves_delete_pass_unrun(): void {
        $this->currentRows = [
            ['order_item_id' => '9100', 'order_id' => '7100'],
        ];
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturnUsing(function () {
            return $this->makeWpdb($this->currentRows);
        });
        $database->shouldReceive('transaction')->andThrow(new \RuntimeException('boom'));

        $builder = new RosterBuilder(
            $logger,
            $database,
            Mockery::mock(PlayerRepository::class),
            Mockery::mock(RosterRepository::class),
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        $builder->buildRosters(['clear_existing' => true]);
        $this->assertSame([], $this->deletedItemIds);
    }

    public function test_cancelled_row_kept_on_obsolete_pass(): void {
        $orderId = 7200;
        $itemId = 9200;
        $this->currentRows = [
            ['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId, 'order_status' => Roster::STATUS_CANCELLED],
        ];
        $builder = $this->makeBuilder();

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function () {
            return [];
        };
        $intersoccer_test_wc_get_order_callback = static function ($oid) use ($orderId, $itemId) {
            if ((int) $oid !== $orderId) {
                return false;
            }
            return new class($orderId, $itemId) {
                private $id;
                private $itemId;
                public function __construct($id, $itemId) { $this->id = $id; $this->itemId = $itemId; }
                public function get_id() { return $this->id; }
                public function get_type() { return 'shop_order'; }
                public function get_status() { return 'cancelled'; }
                public function get_items() { return [$this->itemId => new \stdClass()]; }
            };
        };

        $builder->buildRosters(['clear_existing' => true, 'batch_size' => 5]);
        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_reconcile_age_stats_match_record_helper(): void {
        $builder = $this->makeBuilder();
        $this->invoke($builder, 'init_build_stats');
        $row = [
            'order_id' => 33,
            'order_item_id' => 330,
            'customer_id' => 1,
            'player_index' => 0,
            'player_dob' => '2014-01-01',
            'player_first_name' => 'A',
            'player_last_name' => 'B',
            'player_gender' => 'female',
            'age_group' => '3-5y',
            'start_date' => '2026-08-01',
        ];
        $this->invoke($builder, 'recordAgeGroupBuildOutcome', $row);
        $player = new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'A',
            'last_name' => 'B',
            'dob' => '2014-01-01',
            'gender' => 'female',
        ]);
        $this->assertSame('outside', $player->ageGroupStatusAt('3-5y', '2026-08-01'));
        $stats = $this->invoke($builder, 'getBuildStatistics');
        $this->assertCount(1, $stats['age_mismatches']);
        $this->assertSame(330, $stats['age_mismatches'][0]['order_item_id']);
    }

    public function test_age_outside_badge_player_helper_only(): void {
        $inside = new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'A',
            'last_name' => 'B',
            'dob' => '2016-01-01',
            'gender' => 'male',
        ]);
        $this->assertSame('ok', $inside->ageGroupStatusAt('5-13y (Full Day)', '2026-07-01'));
        $this->assertSame('not_checked', $inside->ageGroupStatusAt('', '2026-07-01'));
    }

    public function test_record_age_does_not_write_order_meta(): void {
        $GLOBALS['intersoccer_test_track_order_meta_writes'] = true;
        $GLOBALS['intersoccer_test_order_meta_write_count'] = 0;
        $builder = $this->makeBuilder();
        $this->invoke($builder, 'init_build_stats');
        $this->invoke($builder, 'recordAgeGroupBuildOutcome', [
            'order_id' => 1,
            'order_item_id' => 2,
            'customer_id' => 1,
            'player_index' => 0,
            'player_dob' => '2015-01-01',
            'player_first_name' => 'A',
            'player_last_name' => 'B',
            'player_gender' => 'male',
            'age_group' => '5-13y (Full Day)',
            'start_date' => '2026-07-01',
        ]);
        $this->assertSame(0, $GLOBALS['intersoccer_test_order_meta_write_count']);
    }
}
