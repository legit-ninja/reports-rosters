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
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\DeletedProductFixture;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\ReportAccuracyStore;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\StoredLineItem;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\StoredOrder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

require_once dirname(__DIR__) . '/ReportAccuracy/ReportAccuracyFixtures.php';

class RosterRebuildStatusesFilterTest extends TestCase {

    /** @var \InterSoccerTestWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        $this->wpdb = new \InterSoccerTestWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        unset($GLOBALS['intersoccer_test_apply_filters_callback']);
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_apply_filters_callback']);
        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = null;
        parent::tearDown();
    }

    private function builderWithCreateSpy(RosterRepository $repo, int &$create_calls): RosterBuilder {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('transaction')->andReturnUsing(function (callable $cb) {
            return $cb();
        });

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

        $repo->shouldReceive('first')->andReturn(null);
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturn(new RostersCollection());
        $repo->shouldReceive('create')->andReturnUsing(function (array $data) use (&$create_calls) {
            $create_calls++;
            $r = new Roster($data);
            $r->setSkipAgeGroupValidation(true);
            $r->validate();
            return $r;
        });
        $repo->shouldReceive('update')->andReturnUsing(fn ($r) => $r);
        $repo->shouldReceive('deleteByOrderItemId')->andReturn(1);
        $repo->shouldReceive('syncOrderStatusForKeptRows')->andReturn(0);

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

    private function seedCompletedOrder(int $order_id, int $line_id): void {
        $meta = array_merge(DeletedProductFixture::courseMeta(), [
            'Start Date' => 'July 1, 2026',
            'End Date' => 'December 31, 2026',
            'Activity Type' => 'Course',
        ]);
        $order = new StoredOrder($order_id, 'completed', '2026-01-12 10:00:00');
        $order->addLine(new StoredLineItem(
            $line_id,
            'Synthetic Course',
            DeletedProductFixture::LIVE_PID,
            DeletedProductFixture::LIVE_VID,
            $meta,
            true,
            300.0
        ));
        ReportAccuracyStore::$orders[$order_id] = $order;
    }

    public function test_filter_wc_completed_only_rebuilds_completed_order(): void {
        $completed_id = 8010;
        $processing_id = 8011;
        $line_completed = 8110;
        $line_processing = 8111;
        $this->seedCompletedOrder($completed_id, $line_completed);

        $processing = new StoredOrder($processing_id, 'processing', '2026-01-12 10:00:00');
        $processing->addLine(new StoredLineItem(
            $line_processing,
            'Synthetic Course',
            DeletedProductFixture::LIVE_PID,
            DeletedProductFixture::LIVE_VID,
            array_merge(DeletedProductFixture::courseMeta(), [
                'Start Date' => 'July 1, 2026',
                'End Date' => 'December 31, 2026',
                'Activity Type' => 'Course',
            ]),
            true,
            300.0
        ));
        ReportAccuracyStore::$orders[$processing_id] = $processing;

        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value) {
            if ($hook === 'intersoccer_roster_rebuild_statuses') {
                return ['wc-completed'];
            }
            return $value;
        };

        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = static fn ($oid) => ReportAccuracyStore::$orders[(int) $oid] ?? false;

        $create_calls = 0;
        $repo = Mockery::mock(RosterRepository::class);
        $builder = $this->builderWithCreateSpy($repo, $create_calls);
        $builder->rebuildSpecificOrders([$completed_id, $processing_id]);

        $this->assertSame(1, $create_calls);
    }

    public function test_filter_pending_only_falls_back_to_default_allowlist(): void {
        $completed_id = 8020;
        $pending_id = 8021;
        $line_completed = 8120;
        $line_pending = 8121;
        $this->seedCompletedOrder($completed_id, $line_completed);
        $pending = new StoredOrder($pending_id, 'pending', '2026-01-12 10:00:00');
        $pending->addLine(new StoredLineItem(
            $line_pending,
            'Synthetic Course',
            DeletedProductFixture::LIVE_PID,
            DeletedProductFixture::LIVE_VID,
            array_merge(DeletedProductFixture::courseMeta(), ['Activity Type' => 'Course']),
            true,
            300.0
        ));
        ReportAccuracyStore::$orders[$pending_id] = $pending;

        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value) {
            if ($hook === 'intersoccer_roster_rebuild_statuses') {
                return ['pending'];
            }
            return $value;
        };

        global $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_order_callback = static fn ($oid) => ReportAccuracyStore::$orders[(int) $oid] ?? false;

        $create_calls = 0;
        $repo = Mockery::mock(RosterRepository::class);
        $builder = $this->builderWithCreateSpy($repo, $create_calls);
        $builder->rebuildSpecificOrders([$completed_id, $pending_id]);

        $this->assertSame(1, $create_calls);
    }
}
