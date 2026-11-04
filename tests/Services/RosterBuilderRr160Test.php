<?php
/**
 * Regression: GitHub #160 — rebuild must keep paid bookings when player age does not match age group.
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
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\FakeLineItem;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\FakeOrder;
use InterSoccer\ReportsRosters\Tests\ReportAccuracy\ReportAccuracyStore;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

require_once __DIR__ . '/../ReportAccuracy/ReportAccuracyFixtures.php';

class Rr160MetaStub {
    private $key;
    private $value;

    public function __construct(string $key, $value) {
        $this->key = $key;
        $this->value = $value;
    }

    public function get_data() {
        return ['key' => $this->key, 'value' => $this->value];
    }
}

class Rr160CampLineItem extends FakeLineItem {
    /** @var array<string,mixed> */
    private $visible_meta;

    public function __construct(int $item_id, array $meta, float $total = 250.0) {
        parent::__construct($item_id, ['subtotal' => $total, 'total' => $total], $meta);
        $this->visible_meta = $meta;
    }

    public function get_product() {
        return null;
    }

    public function get_variation_id() {
        return 0;
    }

    public function get_product_id() {
        return 7100;
    }

    public function get_meta_data() {
        $out = [];
        foreach ($this->visible_meta as $key => $value) {
            $out[] = new Rr160MetaStub((string) $key, $value);
        }
        return $out;
    }
}

class Rr160Order extends FakeOrder {
    public function get_customer_id() {
        return 1;
    }

    public function get_type() {
        return 'shop_order';
    }

    public function get_billing_email() {
        return 'fixture@example.com';
    }

    public function get_billing_phone() {
        return '';
    }

    public function get_billing_first_name() {
        return 'Fixture';
    }

    public function get_billing_last_name() {
        return 'Guardian';
    }
}

class RosterBuilderRr160Test extends TestCase {

    private const FIXTURE_ORDER_ID = 88001;
    private const FIXTURE_ITEM_MISMATCH = 88011;
    private const FIXTURE_ITEM_UNPARSED = 88012;

    /** @var array<int,array<string,mixed>> */
    private $created = [];

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        $this->created = [];

        $includes = dirname(__DIR__, 2) . '/includes';
        $previous_log = ini_set('error_log', '/dev/null');
        foreach (['utils.php', 'order-meta-keys.php'] as $file) {
            $path = $includes . '/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
        ini_set('error_log', (string) $previous_log);
    }

    protected function tearDown(): void {
        global $intersoccer_test_wc_get_orders_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        unset($GLOBALS['intersoccer_test_wc_get_orders_callback']);
        parent::tearDown();
    }

    private function mismatchCampMeta(): array {
        return [
            'Activity Type' => 'Camp',
            'Age Group' => '3-5y (Half-Day)',
            'Booking Type' => 'Full Week',
            'Start Date' => '2027-04-05',
            'End Date' => '2027-04-09',
            'Assigned Attendee' => 'Fixture Player',
        ];
    }

    private function unparsedCampMeta(): array {
        $meta = $this->mismatchCampMeta();
        $meta['Age Group'] = '5-12';
        return $meta;
    }

    private function registerOrder(int $order_id, Rr160CampLineItem $item): Rr160Order {
        $order = (new Rr160Order($order_id, 'completed', '2027-01-10 09:00:00'))->addLine($item);
        ReportAccuracyStore::$orders[$order_id] = $order;
        return $order;
    }

    /**
     * @param callable(RosterRepository&\Mockery\MockInterface):void|null $configure_repo
     */
    private function makeBuilder(Database $database, ?callable $configure_repo = null): RosterBuilder {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturnNull();

        $player = new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'Fixture',
            'last_name' => 'Player',
            'dob' => '2016-03-03',
            'gender' => 'male',
            'medical_conditions' => '',
            'dietary_needs' => '',
        ]);
        $players = new PlayersCollection([$player]);

        $player_repo = Mockery::mock(PlayerRepository::class);
        $player_repo->shouldReceive('getPlayersByCustomerId')->andReturn($players);

        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn($players);

        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => []]);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('first')->andReturn(null);
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturn(new RostersCollection());
        $repo->shouldReceive('create')->andReturnUsing(function (array $data) {
            $skip = !empty($data['_skip_age_group_validation']);
            unset($data['_skip_age_group_validation']);
            $roster = new Roster($data);
            if ($skip) {
                $roster->setSkipAgeGroupValidation(true);
            }
            $roster->validate();
            $roster->setKey(count($this->created) + 1);
            $this->created[] = $roster->toArray();
            return $roster;
        });
        if ($configure_repo !== null) {
            $configure_repo($repo);
        }

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

    public function test_rr160_build_rosters_clear_existing_keeps_age_mismatch_booking(): void {
        $this->registerOrder(
            self::FIXTURE_ORDER_ID,
            new Rr160CampLineItem(self::FIXTURE_ITEM_MISMATCH, $this->mismatchCampMeta())
        );

        $wpdb_stub = Mockery::mock('wpdb');
        $wpdb_stub->prefix = 'wp_';
        $wpdb_stub->shouldReceive('query')
            ->once()
            ->with('TRUNCATE TABLE wp_intersoccer_rosters')
            ->andReturn(true);

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($wpdb_stub);
        $database->shouldReceive('transaction')->andReturnUsing(static function ($callback) {
            return $callback();
        });

        $builder = $this->makeBuilder($database, static function ($repo) {
            $repo->shouldReceive('clearAllCaches')->once();
        });

        global $intersoccer_test_wc_get_orders_callback;
        $intersoccer_test_wc_get_orders_callback = static function ($args) {
            $offset = isset($args['offset']) ? (int) $args['offset'] : 0;
            if ($offset > 0) {
                return [];
            }
            return [self::FIXTURE_ORDER_ID];
        };

        $result = $builder->buildRosters(['clear_existing' => true]);

        $this->assertCount(1, $this->created, 'Paid booking must still produce a roster row after rebuild');
        $this->assertSame(1, $result['rosters_created'] ?? 0);
        $this->assertSame(1, $result['age_group_mismatch_warnings']['count']);
        $this->assertContains(self::FIXTURE_ITEM_MISMATCH, $result['age_group_mismatch_warnings']['order_item_ids']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_rr160_rebuild_specific_orders_keeps_age_mismatch_booking(): void {
        $this->registerOrder(
            self::FIXTURE_ORDER_ID,
            new Rr160CampLineItem(self::FIXTURE_ITEM_MISMATCH, $this->mismatchCampMeta())
        );

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('transaction')->andReturnUsing(static function ($callback) {
            return $callback();
        });

        $builder = $this->makeBuilder($database, static function ($repo) {
            $repo->shouldReceive('deleteWhere')
                ->once()
                ->with(['order_id' => self::FIXTURE_ORDER_ID])
                ->andReturn(1);
        });

        $result = $builder->rebuildSpecificOrders([self::FIXTURE_ORDER_ID]);
        $stats = $result['statistics'];

        $this->assertCount(1, $this->created);
        $this->assertSame(1, $stats['age_group_mismatch_warnings']['count']);
        $this->assertContains(self::FIXTURE_ITEM_MISMATCH, $stats['age_group_mismatch_warnings']['order_item_ids']);
    }

    public function test_rr160_unparsed_age_group_label_kept_and_warned(): void {
        $this->registerOrder(
            self::FIXTURE_ORDER_ID,
            new Rr160CampLineItem(self::FIXTURE_ITEM_UNPARSED, $this->unparsedCampMeta())
        );

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('transaction')->andReturnUsing(static function ($callback) {
            return $callback();
        });

        $builder = $this->makeBuilder($database, static function ($repo) {
            $repo->shouldReceive('deleteWhere')->andReturn(0);
        });

        $result = $builder->rebuildSpecificOrders([self::FIXTURE_ORDER_ID]);
        $stats = $result['statistics'];

        $this->assertCount(1, $this->created);
        $this->assertSame(1, $stats['unparsed_age_group_warnings']['count']);
        $this->assertContains(self::FIXTURE_ITEM_UNPARSED, $stats['unparsed_age_group_warnings']['order_item_ids']);
        $this->assertSame(0, $stats['age_group_mismatch_warnings']['count']);
    }
}
