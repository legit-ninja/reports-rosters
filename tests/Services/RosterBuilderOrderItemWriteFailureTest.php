<?php
/**
 * Visible failures when an order line cannot be written to the roster (hook path).
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
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;
use Mockery;

class RosterBuilderOrderItemWriteFailureTest extends TestCase {

    /** @var array<int,mixed> */
    private static $orders = [];

    /** @var array<int,array<string,mixed>> */
    private $errorLogContexts = [];

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
        $this->errorLogContexts = [];
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
        $logger->shouldReceive('info', 'debug', 'warning')->andReturn(null);
        $logger->shouldReceive('error')->andReturnUsing(function ($message, $context = []) {
            if ($message === 'Order line roster write failed') {
                $this->errorLogContexts[] = $context;
            }
            return null;
        });
        return $logger;
    }

    private function rosterRepository(): RosterRepository {
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('insert_roster_entry')->andReturn(0);
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

    private function orderWithNoteCapture(int $order_id, int $customer_id, int $item_id, $item, array &$notes) {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_customer_id')->andReturn($customer_id);
        $order->shouldReceive('get_items')->andReturn([$item_id => $item]);
        $order->shouldReceive('get_item')->andReturnUsing(fn($id) => (int) $id === $item_id ? $item : false);
        $order->shouldReceive('add_order_note')->andReturnUsing(function ($note) use (&$notes) {
            $notes[] = $note;
            return 1;
        });
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

    private function makeBuilder(Player $player, DataValidator $validator): RosterBuilder {
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
            $validator,
            Mockery::mock(EventMatcher::class),
            $matcher
        );
    }

    public function test_ineligible_age_records_log_note_and_build_failure_flag() {
        $uuid = '00000000-0000-4000-8000-000000000601';
        $item = $this->courseItem(52636, 52637, $this->courseMeta($uuid, '2015-02-03'));
        $notes = [];
        $this->orderWithNoteCapture(2001, 301, 601, $item, $notes);
        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => [], 'field_errors' => []]);
        $builder = $this->makeBuilder($this->player(301, $uuid, '2015-02-03'), $validator);

        $rows = $builder->buildRosterFromOrder(2001, [
            'validate_data' => true,
            'skip_duplicates' => true,
            'update_existing' => true,
            'skip_age_group_validation' => true,
        ]);

        $this->assertSame(0, $rows->count());
        $this->assertTrue($builder->hadOrderItemWriteFailures());
        $this->assertCount(1, $this->errorLogContexts);
        $ctx = $this->errorLogContexts[0];
        $this->assertSame(2001, $ctx['order_id']);
        $this->assertSame(601, $ctx['item_id']);
        $this->assertArrayHasKey('reason', $ctx);
        $this->assertSame('database write failed', $ctx['reason']);
        $this->assertArrayNotHasKey('player', $ctx);
        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('could not be added to the roster', $notes[0]);
        $this->assertStringContainsString('database write failed', $notes[0]);
    }

    public function test_validate_roster_data_advisory_does_not_block_write_or_note() {
        $uuid = '00000000-0000-4000-8000-000000000602';
        $item = $this->courseItem(52636, 52637, $this->courseMeta($uuid, '2015-02-03'));
        $notes = [];
        $this->orderWithNoteCapture(2002, 302, 602, $item, $notes);
        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->once()->andReturn([
            'valid' => false,
            'errors' => ['venue field is required'],
            'field_errors' => ['venue' => ['venue field is required']],
            'failed_rules' => ['venue' => ['required']],
        ]);
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('insert_roster_entry')->andReturn(42);
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->andReturn(true);
        $repo = Mockery::mock(RosterRepository::class, [$this->logger(), $db, $cache])->makePartial();
        $repo->shouldReceive('first')->andReturn(null);
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturn(new RostersCollection());
        $players = new PlayersCollection();
        $players->add($this->player(302, $uuid, '2015-02-03'));
        $playerRepo = Mockery::mock(PlayerRepository::class);
        $playerRepo->shouldReceive('getPlayersByCustomerId')->andReturn($players);
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn($players);
        $builder = new RosterBuilder(
            $this->logger(),
            Mockery::mock(Database::class),
            $playerRepo,
            $repo,
            $validator,
            Mockery::mock(EventMatcher::class),
            $matcher
        );

        $rows = $builder->buildRosterFromOrder(2002, ['validate_data' => true, 'skip_age_group_validation' => true]);

        $this->assertSame(1, $rows->count());
        $this->assertFalse($builder->hadOrderItemWriteFailures());
        $this->assertSame([], $notes);
    }

    public function test_rebuild_specific_orders_accumulates_build_statistics_across_orders() {
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('transaction')->andReturnUsing(function ($callback) {
            return $callback();
        });
        $db->shouldReceive('insert_roster_entry')->andReturn(0);
        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => [], 'field_errors' => []]);

        $makeOrder = function (int $order_id, int $customer_id, int $item_id) {
            $uuid = sprintf('00000000-0000-4000-8000-%012d', $item_id);
            $item = $this->courseItem(52636, 52637, $this->courseMeta($uuid, '2015-02-03'));
            $notes = [];
            $this->orderWithNoteCapture($order_id, $customer_id, $item_id, $item, $notes);
            return $uuid;
        };
        $makeOrder(4001, 401, 701);
        $makeOrder(4002, 402, 702);

        $playerRepo = Mockery::mock(PlayerRepository::class);
        $playerRepo->shouldReceive('getPlayersByCustomerId')->andReturnUsing(function ($customer_id) {
            $item_by_customer = [401 => 701, 402 => 702];
            $item_id = $item_by_customer[(int) $customer_id] ?? 701;
            $uuid = sprintf('00000000-0000-4000-8000-%012d', $item_id);
            $players = new PlayersCollection();
            $players->add($this->player((int) $customer_id, $uuid, '2015-02-03'));
            return $players;
        });
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturnUsing(function ($item, $players) {
            return $players;
        });

        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->andReturn(true);
        $rosterRepo = Mockery::mock(RosterRepository::class, [$this->logger(), $db, $cache])->makePartial();
        $rosterRepo->shouldReceive('deleteWhere')->twice()->andReturn(1);
        $rosterRepo->shouldReceive('first')->andReturn(null);
        $rosterRepo->shouldReceive('count')->andReturn(0);
        $rosterRepo->shouldReceive('where')->andReturn(new RostersCollection());

        $builder = new RosterBuilder(
            $this->logger(),
            $db,
            $playerRepo,
            $rosterRepo,
            $validator,
            Mockery::mock(EventMatcher::class),
            $matcher
        );

        $result = $builder->rebuildSpecificOrders([4001, 4002], ['validate_data' => true]);

        $stats = $result['statistics'];
        $this->assertSame(2, $stats['orders_processed']);
        $this->assertSame(2, $stats['item_write_failures']);
    }

    public function test_order_processor_returns_false_when_builder_reports_write_failures() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(3001);
        $order->shouldReceive('get_status')->andReturn('processing');

        $rosters = Mockery::mock(RostersCollection::class);
        $rosters->shouldReceive('count')->andReturn(0);

        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')->once()->andReturn($rosters);
        $builder->shouldReceive('hadOrderItemWriteFailures')->once()->andReturn(true);

        $repo = Mockery::mock(RosterRepository::class);
        $logger = Mockery::spy(Logger::class);

        $result = (new OrderProcessor($logger, $repo, $builder))->processOrder($order);

        $this->assertFalse($result);
    }

    public function test_item_level_exception_records_failure_and_note() {
        $uuid = '00000000-0000-4000-8000-000000000603';
        $item = $this->courseItem(52636, 52637, $this->courseMeta($uuid, '2015-02-03'));
        $notes = [];
        $this->orderWithNoteCapture(2003, 303, 603, $item, $notes);
        $players = new PlayersCollection();
        $players->add($this->player(303, $uuid, '2015-02-03'));
        $playerRepo = Mockery::mock(PlayerRepository::class);
        $playerRepo->shouldReceive('getPlayersByCustomerId')->andReturn($players);
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andThrow(new \RuntimeException('simulated matcher failure'));
        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->never();
        $builder = new RosterBuilder(
            $this->logger(),
            Mockery::mock(Database::class),
            $playerRepo,
            $this->rosterRepository(),
            $validator,
            Mockery::mock(EventMatcher::class),
            $matcher
        );

        $builder->buildRosterFromOrder(2003, ['validate_data' => true]);

        $this->assertTrue($builder->hadOrderItemWriteFailures());
        $this->assertCount(1, $this->errorLogContexts);
        $this->assertSame(2003, $this->errorLogContexts[0]['order_id']);
        $this->assertSame(603, $this->errorLogContexts[0]['item_id']);
        $this->assertCount(1, $notes);
        $this->assertSame('roster write failed', $this->errorLogContexts[0]['reason']);
    }
}
