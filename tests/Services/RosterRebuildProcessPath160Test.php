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
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\EventSignatureGenerator;
use InterSoccer\ReportsRosters\Services\PlaceholderManager;
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

class RosterRebuildProcessPath160Test extends TestCase {

    /** @var DeletedProductWpdb */
    private $wpdb;
    /** @var array<int,array<string,mixed>> */
    private $created = [];
    /** @var int[] */
    private $deletedItemIds = [];
    private $previous_wpdb;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        DeletedProductFixture::resetCatalogue();
        $this->wpdb = new DeletedProductWpdb();
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->created = [];
        $this->deletedItemIds = [];
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previous_wpdb;
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        unset($GLOBALS['intersoccer_test_track_order_meta_writes'], $GLOBALS['intersoccer_test_order_meta_write_count']);
        parent::tearDown();
    }

    private function courseMeta(array $overrides = []): array {
        $meta = array_merge(DeletedProductFixture::courseMeta(), [
            'Start Date' => 'July 1, 2026',
            'End Date' => 'December 31, 2026',
        ], $overrides);
        return $meta;
    }

    private function line(int $item_id, array $meta): StoredLineItem {
        return new StoredLineItem(
            $item_id,
            'Synthetic Sunday Course - Venue A - Winter 2026',
            DeletedProductFixture::LIVE_PID,
            DeletedProductFixture::LIVE_VID,
            $meta,
            true,
            300.0
        );
    }

    private function registerOrder(int $order_id, string $status, StoredLineItem $item): void {
        ReportAccuracyStore::$orders[$order_id] = (new StoredOrder($order_id, $status, '2026-01-12 10:00:00'))->addLine($item);
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

    private function builder(?callable $assignedPlayers = null): RosterBuilder {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($this->wpdb);
        $database->shouldReceive('transaction')->andReturnUsing(function (callable $callback) {
            return $callback();
        });

        $defaultPlayers = new PlayersCollection([$this->player('2018-03-01')]);
        $player_repo = Mockery::mock(PlayerRepository::class);
        $player_repo->shouldReceive('getPlayersByCustomerId')->andReturn($defaultPlayers);

        $matcher = Mockery::mock(PlayerMatcher::class);
        if ($assignedPlayers !== null) {
            $matcher->shouldReceive('getAssignedPlayers')->andReturnUsing($assignedPlayers);
        } else {
            $matcher->shouldReceive('getAssignedPlayers')->andReturn($defaultPlayers);
        }

        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => []]);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('first')->andReturn(null);
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('syncOrderStatusForKeptRows')->andReturn(0);
        $repo->shouldReceive('where')->andReturnUsing(function (array $where) {
            $order_id = (int) ($where['order_id'] ?? 0);
            $rows = [];
            foreach ($this->created as $row) {
                if ((int) ($row['order_id'] ?? 0) === $order_id) {
                    $rows[] = (object) [
                        'order_item_id' => $row['order_item_id'],
                        'order_id' => $row['order_id'],
                    ];
                }
            }
            return new RostersCollection($rows);
        });
        $repo->shouldReceive('create')->andReturnUsing(function (array $data) {
            unset($data['_skip_age_group_validation']);
            $roster = new Roster($data);
            $roster->setSkipAgeGroupValidation(true);
            $roster->validate();
            $roster->setKey(count($this->created) + 1);
            $this->created[] = $roster->toArray();
            return $roster;
        });
        $repo->shouldReceive('update')->andReturnUsing(function ($roster) {
            return $roster;
        });
        $repo->shouldReceive('deleteByOrderItemId')->andReturnUsing(function (int $id) {
            $this->deletedItemIds[] = $id;
            return 1;
        });

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

    public function test_full_rebuild_process_path_age_mismatch_and_not_checked(): void {
        $this->registerOrder(8101, 'completed', $this->line(8001, $this->courseMeta([
            'Age Group' => '3-5y',
            'Start Date' => 'July 1, 2026',
            'pa_age-group' => '3-5y',
        ])));
        $this->registerOrder(8102, 'completed', $this->line(8002, $this->courseMeta([
            'Age Group' => '3-5y',
            'Start Date' => 'July 1, 2026',
            'pa_age-group' => '3-5y',
        ])));
        $this->registerOrder(8103, 'completed', $this->line(8003, $this->courseMeta([
            'Age Group' => 'Pre-K to G5',
            'Start Date' => 'July 1, 2026',
            'pa_age-group' => 'pre-k-to-g5',
        ])));

        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = static fn ($oid) => ReportAccuracyStore::$orders[(int) $oid] ?? false;

        $playersUnder = new PlayersCollection([$this->player('2024-01-01')]);
        $playersOver = new PlayersCollection([$this->player('2010-01-01')]);
        $playersDefault = new PlayersCollection([$this->player('2018-03-01')]);

        $builder = $this->builder(function ($item) use ($playersUnder, $playersOver, $playersDefault) {
            $id = method_exists($item, 'get_id') ? (int) $item->get_id() : 0;
            if ($id === 8001) {
                return $playersUnder;
            }
            if ($id === 8002) {
                return $playersOver;
            }
            return $playersDefault;
        });

        $result = $builder->rebuildSpecificOrders([8101, 8102, 8103]);

        $this->assertCount(3, $this->created);
        $this->assertCount(2, $result['age_mismatches']);
        $this->assertSame(1, $result['not_checked']['count']);
        $this->assertContains(8003, $result['not_checked']['order_item_ids']);
    }

    public function test_full_rebuild_does_not_obsolete_check_upserted_item(): void {
        $this->registerOrder(8200, 'completed', $this->line(8100, $this->courseMeta()));
        $existing = [
            ['order_item_id' => '8100', 'order_id' => '8200'],
            ['order_item_id' => '9200', 'order_id' => '8200'],
        ];
        $this->wpdb = new class($existing) extends DeletedProductWpdb {
            /** @var array<int,array<string,string>> */
            private $existing;
            public function __construct(array $existing) {
                $this->existing = $existing;
            }
            public function get_results($query = null, $output = OBJECT) {
                if (preg_match('/SELECT order_item_id, order_id FROM/', (string) $query)) {
                    return $output === ARRAY_A ? $this->existing : $this->existing;
                }
                return parent::get_results($query, $output);
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function ($args) {
            if ((int) ($args['offset'] ?? 0) > 0) {
                return [];
            }
            return [8200];
        };
        $intersoccer_test_wc_get_order_callback = static fn ($oid) => ReportAccuracyStore::$orders[(int) $oid] ?? false;

        $builder = $this->builder();
        $builder->buildRosters(['clear_existing' => true, 'batch_size' => 5]);

        $this->assertNotContains(8100, $this->deletedItemIds, 'Upserted line must not go through obsolete delete');
    }

    public function test_transaction_false_sets_rebuild_failed_and_skips_admin_log(): void {
        $logged = [];
        $GLOBALS['intersoccer_test_admin_log_capture'] = &$logged;

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($this->wpdb);
        $database->shouldReceive('transaction')->andReturn(false);

        $builder = new RosterBuilder(
            $logger,
            $database,
            Mockery::mock(PlayerRepository::class),
            Mockery::mock(RosterRepository::class),
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        $result = $builder->buildRosters(['clear_existing' => true]);
        $this->assertTrue($result['rebuild_failed']);
        $this->assertContains('Rebuild failed; no roster rows were changed.', $result['errors']);
        $this->assertSame([], $logged);
    }

    public function test_roster_validate_skip_age_with_missing_fields_does_not_throw(): void {
        $data = [
            'order_id' => 1,
            'order_item_id' => 2,
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'A',
            'last_name' => 'B',
            'dob' => '2018-01-01',
            'gender' => 'male',
            'order_status' => 'completed',
            'activity_type' => 'Course',
            'age_group' => '',
            'start_date' => '',
            'booking_type' => 'Full Term',
            'venue' => 'Venue A',
            'season' => 'Winter 2026',
            'product_id' => DeletedProductFixture::LIVE_PID,
            'variation_id' => DeletedProductFixture::LIVE_VID,
        ];
        $roster = new Roster($data);
        $roster->setSkipAgeGroupValidation(true);
        $roster->validate();
        $this->assertTrue(true);
    }

    public function test_rebuild_specific_orders_invalid_status_runs_obsolete_not_error(): void {
        $this->created[] = [
            'order_id' => 9001,
            'order_item_id' => 9101,
        ];
        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = static function ($oid) {
            if ((int) $oid !== 9001) {
                return false;
            }
            return new class(9001) {
                private $id;
                public function __construct($id) { $this->id = $id; }
                public function get_id() { return $this->id; }
                public function get_status() { return 'pending'; }
                public function get_items() { return [9101 => new \stdClass()]; }
            };
        };

        $builder = $this->builder();
        $result = $builder->rebuildSpecificOrders([9001]);
        $this->assertSame(1, $result['statistics']['skipped_orders']);
        $this->assertSame([], array_filter($result['statistics']['errors'] ?? [], static function ($e) {
            return stripos($e, 'invalid status') !== false;
        }));
    }

    public function test_age_outside_badge_helper(): void {
        require_once dirname(__DIR__, 2) . '/includes/roster-details.php';
        $outside = (object) [
            'player_dob' => '2020-01-01',
            'start_date' => '2026-07-01',
            'age_group' => '3-5y',
            'customer_id' => 1,
            'player_index' => 0,
            'gender' => 'male',
            'first_name' => 'A',
            'last_name' => 'B',
        ];
        $ok = (object) [
            'player_dob' => '2018-03-01',
            'start_date' => '2026-07-01',
            'age_group' => '5-12y',
            'customer_id' => 1,
            'player_index' => 0,
            'gender' => 'male',
            'first_name' => 'A',
            'last_name' => 'B',
        ];
        $this->assertStringContainsString('Age outside group', intersoccer_roster_age_outside_group_badge_html($outside));
        $this->assertSame('', intersoccer_roster_age_outside_group_badge_html($ok));
        $this->assertSame('', intersoccer_roster_age_outside_group_badge_html((object) [
            'player_dob' => '2018-03-01',
            'start_date' => '2026-07-01',
            'age_group' => 'Pre-K to G5',
            'customer_id' => 1,
            'player_index' => 0,
            'gender' => 'male',
        ]));
    }
}
