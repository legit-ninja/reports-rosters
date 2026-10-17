<?php
/**
 * Dedup private order notes for repeated roster write failures per line.
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

class RosterBuilderFailureNoteDedupTest extends TestCase {

    /** @var array<int,mixed> */
    private static $orders = [];

    /** @var array<string,mixed> */
    private $failureNoteMeta = [];

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
        $this->failureNoteMeta = [];
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
        $logger->shouldReceive('error')->andReturn(null);
        return $logger;
    }

    private function meta(string $key, $value) {
        $m = Mockery::mock();
        $m->shouldReceive('get_data')->andReturn(['key' => $key, 'value' => $value]);
        return $m;
    }

    /** @param array<string,string> $meta */
    private function courseItem(array $meta) {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn(null);
        $item->shouldReceive('get_product_id')->andReturn(52636);
        $item->shouldReceive('get_variation_id')->andReturn(52637);
        $item->shouldReceive('get_meta_data')->andReturn(array_map(
            fn($k) => $this->meta($k, $meta[$k]),
            array_keys($meta)
        ));
        $item->shouldReceive('get_meta')->andReturnUsing(fn($k) => $meta[$k] ?? '');
        $item->shouldReceive('get_id')->andReturn(0);
        return $item;
    }

    private function orderWithMeta(int $order_id, int $customer_id, int $item_id, $item, array &$notes) {
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
        $order->shouldReceive('get_meta')->andReturnUsing(function ($key, $single = false) {
            if ($key === '_intersoccer_roster_failure_notes') {
                return $single ? $this->failureNoteMeta : [$this->failureNoteMeta];
            }
            return $single ? '' : [];
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(function ($key, $value) {
            if ($key === '_intersoccer_roster_failure_notes') {
                $this->failureNoteMeta = is_array($value) ? $value : [];
            }
        });
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
            'assigned_player_id' => $uuid,
            '_assigned_player_id' => $uuid,
            'Attendee DOB' => $dob,
            'Attendee Gender' => 'male',
            'Medical Conditions' => 'N/A',
            'Start Date' => 'August 23, 2026',
            'End Date' => 'December 20, 2026',
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
            'Player Index' => '0',
            'Player DOB' => $dob,
            'Player Medical' => 'N/A',
        ];
    }

    private function makeBuilder(RosterRepository $repo): RosterBuilder {
        $uuid = '00000000-0000-4000-8000-000000000901';
        $players = new PlayersCollection();
        $players->add(Player::fromUserMetadata(601, 0, [
            'player_id' => $uuid,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'dob' => '2015-02-03',
            'gender' => 'male',
            'avs_number' => '',
            'medical_conditions' => '',
        ]));
        $playerRepo = Mockery::mock(PlayerRepository::class);
        $playerRepo->shouldReceive('getPlayersByCustomerId')->andReturn($players);
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn($players);
        return new RosterBuilder(
            $this->logger(),
            Mockery::mock(Database::class),
            $playerRepo,
            $repo,
            Mockery::mock(DataValidator::class)->shouldReceive('validateRosterData')->andReturn([
                'valid' => true, 'errors' => [], 'field_errors' => [],
            ])->getMock(),
            Mockery::mock(EventMatcher::class),
            $matcher
        );
    }

    private function failingRepo(): RosterRepository {
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

    private function succeedingRepo(): RosterRepository {
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('insert_roster_entry')->andReturn(930001);
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->andReturn(true);
        $repo = Mockery::mock(RosterRepository::class, [$this->logger(), $db, $cache])->makePartial();
        $repo->shouldReceive('first')->andReturn(null);
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturn(new RostersCollection());
        return $repo;
    }

    public function test_same_write_failure_twice_adds_one_order_note() {
        $uuid = '00000000-0000-4000-8000-000000000901';
        $item = $this->courseItem($this->courseMeta($uuid, '2015-02-03'));
        $notes = [];
        $this->orderWithMeta(6001, 601, 901, $item, $notes);
        $builder = $this->makeBuilder($this->failingRepo());

        $builder->buildRosterFromOrder(6001, ['validate_data' => true, 'skip_age_group_validation' => true]);
        $builder->buildRosterFromOrder(6001, ['validate_data' => true, 'skip_age_group_validation' => true]);

        $this->assertCount(1, $notes);
    }

    public function test_successful_write_clears_failure_note_marker() {
        $uuid = '00000000-0000-4000-8000-000000000902';
        $item = $this->courseItem($this->courseMeta($uuid, '2015-02-03'));
        $notes = [];
        $this->orderWithMeta(6002, 602, 902, $item, $notes);

        $fail_builder = $this->makeBuilder($this->failingRepo());
        $fail_builder->buildRosterFromOrder(6002, ['validate_data' => true, 'skip_age_group_validation' => true]);
        $this->assertNotEmpty($this->failureNoteMeta);

        $ok_builder = $this->makeBuilder($this->succeedingRepo());
        $rows = $ok_builder->buildRosterFromOrder(6002, ['validate_data' => true, 'skip_age_group_validation' => true]);
        $this->assertSame(1, $rows->count());
        $this->assertSame([], $this->failureNoteMeta);
    }
}
