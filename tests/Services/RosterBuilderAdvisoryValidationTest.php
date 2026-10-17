<?php
/**
 * Advisory validateRosterData on roster write paths (hook, rebuild, details resync).
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use Brain\Monkey\Functions;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\CacheManager;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterBuilderAdvisoryValidationTest extends TestCase {

    /** @var array<int,mixed> */
    private static $orders = [];

    /** @var array<int,array<string,mixed>> */
    private $warningLogContexts = [];

    /** @var array<int,array> */
    private $inserted = [];

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_parse_date_unified')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }
        Functions\when('remove_accents')->alias(function ($string) {
            if (!is_string($string) || !class_exists('\Normalizer')) {
                return $string;
            }
            $n = \Normalizer::normalize($string, \Normalizer::FORM_D);
            return $n === false ? $string : (preg_replace('/\p{Mn}+/u', '', $n) ?? $string);
        });
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = [self::class, 'getOrder'];
        self::$orders = [];
        $this->warningLogContexts = [];
        $this->inserted = [];
    }

    protected function tearDown(): void {
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = null;
        self::$orders = [];
        parent::tearDown();
    }

    public static function getOrder($id) {
        return self::$orders[(int) $id] ?? false;
    }

    private function logger(): Logger {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'error')->andReturn(null);
        $logger->shouldReceive('warning')->andReturnUsing(function ($message, $context = []) {
            if ($message === 'Roster data validation advisory failure on write path') {
                $this->warningLogContexts[] = $context;
            }
            return null;
        });
        return $logger;
    }

    private function rosterRepository(): RosterRepository {
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('insert_roster_entry')->andReturnUsing(function (array $row) {
            $this->inserted[] = $row;
            return 910000 + count($this->inserted);
        });
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->andReturn(true);
        $repo = Mockery::mock(RosterRepository::class, [$this->logger(), $db, $cache])->makePartial();
        $repo->shouldReceive('first')->andReturn(null);
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturn(new RostersCollection());
        return $repo;
    }

    private function player(int $customer_id, string $uuid, string $dob): Player {
        return Player::fromUserMetadata($customer_id, 0, [
            'player_id' => $uuid,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'dob' => $dob,
            'gender' => 'male',
            'avs_number' => '',
            'medical_conditions' => '',
        ]);
    }

    private function meta(string $key, $value) {
        $m = Mockery::mock();
        $m->shouldReceive('get_data')->andReturn(['key' => $key, 'value' => $value]);
        return $m;
    }

    /** @param array<string,string> $meta */
    private function courseItem(int $product_id, int $variation_id, array $meta) {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn(null);
        $item->shouldReceive('get_product_id')->andReturn($product_id);
        $item->shouldReceive('get_variation_id')->andReturn($variation_id);
        $item->shouldReceive('get_meta_data')->andReturn(array_map(
            fn($k) => $this->meta($k, $meta[$k]),
            array_keys($meta)
        ));
        $item->shouldReceive('get_meta')->andReturnUsing(fn($k) => $meta[$k] ?? '');
        $item->shouldReceive('get_id')->andReturn(0);
        return $item;
    }

    private function order(int $order_id, int $customer_id, int $item_id, $item) {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_customer_id')->andReturn($customer_id);
        $order->shouldReceive('get_items')->andReturn([$item_id => $item]);
        $order->shouldReceive('get_item')->andReturnUsing(fn($id) => (int) $id === $item_id ? $item : false);
        $order->shouldReceive('add_order_note')->andReturn(1);
        $order->shouldReceive('get_meta')->andReturn([]);
        $order->shouldReceive('update_meta_data')->andReturn(null);
        $order->shouldReceive('save')->andReturn($order_id);
        foreach (['get_billing_email' => 'parent@example.test', 'get_billing_phone' => '+41 00 000 00 00',
                  'get_billing_first_name' => 'Parent', 'get_billing_last_name' => 'Test'] as $m => $v) {
            $order->shouldReceive($m)->andReturn($v);
        }
        self::$orders[$order_id] = $order;
        return $order;
    }

    /** @return array<string,string> */
    private function courseMeta(string $uuid, string $dob): array {
        return [
            'pa_intersoccer-venues' => 'etoy-centre-sportif-etoy',
            'pa_course-day' => 'sunday',
            'pa_age-group' => '3-12y',
            'pa_course-times' => '1000-1130',
            'Activity Type' => 'Course',
            '_intersoccer_canonical_activity_type' => 'course',
            '_intersoccer_canonical_girls_only' => '0',
            '_intersoccer_canonical_venue' => 'etoy-centre-sportif-etoy',
            '_intersoccer_canonical_canton' => 'vaud',
            '_intersoccer_canonical_age_group' => '3-12y',
            '_assigned_player' => '0',
            'assigned_player_id' => $uuid,
            '_assigned_player_id' => $uuid,
            'Attendee DOB' => $dob,
            'Attendee Gender' => 'male',
            'Medical Conditions' => 'N/A',
            'Start Date' => 'August 23, 2026',
            'End Date' => 'December 20, 2026',
            'Holidays' => 'October 18, 2026',
            'Season' => 'Autumn 2026',
            'Sites InterSoccer' => 'Etoy - Centre Sportif Etoy',
            'Year' => '2026',
            'Course Day' => 'Sunday',
            'Age Group' => '3-12y',
            'Course Times' => '1000-1130',
            'Canton / Region' => 'Vaud',
            'City' => 'Etoy',
            'Assigned Attendee' => 'Test Player',
            'intersoccer_player_index' => '0',
            'assigned_player' => '0',
            'Player Index' => '0',
            'Player DOB' => $dob,
            'Player Medical' => 'N/A',
        ];
    }

    private function makeBuilder(Player $player): RosterBuilder {
        $players = new PlayersCollection();
        $players->add($player);
        $playerRepo = Mockery::mock(PlayerRepository::class);
        $playerRepo->shouldReceive('getPlayersByCustomerId')->andReturn($players);
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn($players);
        return new RosterBuilder(
            $this->logger(),
            Mockery::mock(Database::class),
            $playerRepo,
            $this->rosterRepository(),
            new DataValidator($this->logger()),
            Mockery::mock(EventMatcher::class),
            $matcher
        );
    }

    public function test_dob_under_three_today_writes_row_and_logs_advisory_warning() {
        $uuid = '00000000-0000-4000-8000-000000000801';
        $dob = '2024-06-01';
        $item = $this->courseItem(52636, 52637, $this->courseMeta($uuid, $dob));
        $this->order(5001, 501, 801, $item);
        $builder = $this->makeBuilder($this->player(501, $uuid, $dob));

        $rows = $builder->buildRosterFromOrder(5001, [
            'validate_data' => true,
            'skip_duplicates' => true,
            'update_existing' => true,
            'skip_age_group_validation' => true,
        ]);

        $this->assertSame(1, $rows->count());
        $this->assertFalse($builder->hadOrderItemWriteFailures());
        $this->assertNotEmpty($this->warningLogContexts);
        $ctx = $this->warningLogContexts[0];
        $this->assertSame(5001, $ctx['order_id']);
        $this->assertSame(801, $ctx['item_id']);
        $this->assertStringContainsString('dob/player_age_limit', (string) ($ctx['rules'] ?? ''));
    }

    public function test_dob_over_eighteen_today_writes_row_and_logs_advisory_warning() {
        $uuid = '00000000-0000-4000-8000-000000000802';
        $dob = '2000-01-01';
        $item = $this->courseItem(52636, 52637, $this->courseMeta($uuid, $dob));
        $this->order(5002, 502, 802, $item);
        $builder = $this->makeBuilder($this->player(502, $uuid, $dob));

        $rows = $builder->buildRosterFromOrder(5002, [
            'validate_data' => true,
            'skip_duplicates' => true,
            'update_existing' => true,
            'skip_age_group_validation' => true,
        ]);

        $this->assertSame(1, $rows->count());
        $this->assertStringContainsString('dob/player_age_limit', (string) ($this->warningLogContexts[0]['rules'] ?? ''));
    }

    public function test_autumn_season_fallback_writes_row_despite_season_format_advisory() {
        $uuid = '00000000-0000-4000-8000-000000000803';
        $dob = '2015-02-03';
        $item = $this->courseItem(52636, 52637, $this->courseMeta($uuid, $dob));
        $this->order(5003, 503, 803, $item);
        $builder = $this->makeBuilder($this->player(503, $uuid, $dob));

        $rows = $builder->buildRosterFromOrder(5003, [
            'validate_data' => true,
            'skip_duplicates' => true,
            'update_existing' => true,
            'skip_age_group_validation' => true,
        ]);

        $this->assertSame(1, $rows->count());
        $rules = (string) ($this->warningLogContexts[0]['rules'] ?? '');
        if ($rules !== '') {
            $this->assertStringContainsString('season/season_format', $rules);
        }
    }

    public function test_rebuild_specific_orders_recreates_rows_after_advisory_validation() {
        $uuid = '00000000-0000-4000-8000-000000000804';
        $dob = '2024-06-01';
        $item = $this->courseItem(52636, 52637, $this->courseMeta($uuid, $dob));
        $this->order(5004, 504, 804, $item);

        $db = Mockery::mock(Database::class);
        $db->shouldReceive('transaction')->andReturnUsing(function ($callback) {
            return $callback();
        });
        $db->shouldReceive('insert_roster_entry')->andReturnUsing(function (array $row) {
            $this->inserted[] = $row;
            return 920000 + count($this->inserted);
        });

        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->andReturn(true);
        $rosterRepo = Mockery::mock(RosterRepository::class, [$this->logger(), $db, $cache])->makePartial();
        $rosterRepo->shouldReceive('deleteWhere')->once()->andReturn(1);
        $rosterRepo->shouldReceive('first')->andReturn(null);
        $rosterRepo->shouldReceive('count')->andReturn(0);
        $rosterRepo->shouldReceive('where')->andReturn(new RostersCollection());

        $players = new PlayersCollection();
        $players->add($this->player(504, $uuid, $dob));
        $playerRepo = Mockery::mock(PlayerRepository::class);
        $playerRepo->shouldReceive('getPlayersByCustomerId')->andReturn($players);
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn($players);

        $builder = new RosterBuilder(
            $this->logger(),
            $db,
            $playerRepo,
            $rosterRepo,
            new DataValidator($this->logger()),
            Mockery::mock(EventMatcher::class),
            $matcher
        );

        $result = $builder->rebuildSpecificOrders([5004], [
            'validate_data' => true,
            'skip_age_group_validation' => true,
        ]);

        $this->assertSame(1, $result['rosters']->count());
        $this->assertSame(0, $result['statistics']['item_write_failures']);
        $this->assertCount(1, $this->inserted);
    }
}
