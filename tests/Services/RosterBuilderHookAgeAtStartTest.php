<?php
/**
 * Hook path (HooksManager -> OrderProcessor -> RosterBuilder): every paid line must upsert
 * a roster row with skip_age_group_validation; age is flagged read-time via Player::ageGroupStatusAt.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\CacheManager;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;
use Brain\Monkey\Functions;
use Mockery;

class RosterBuilderHookAgeAtStartTest extends TestCase {

    /** Options passed by the live hook (order-processor.php). */
    private const LIVE_HOOK_OPTIONS = [
        'validate_data'   => true,
        'skip_duplicates' => true,
        'update_existing' => true,
        'skip_age_group_validation' => true,
    ];

    /** @var array<int,mixed> */
    private static $orders = [];

    /** @var array<int,array> rows handed to Database::insert_roster_entry */
    private $inserted = [];

    /** @var int */
    private $insert_calls = 0;

    /** @var int */
    private $update_calls = 0;

    /** @var array<int|string,string> */
    private $failureNoteMeta = [];

    /** @var int */
    private $first_lookup_calls = 0;

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
        $this->inserted = [];
        $this->insert_calls = 0;
        $this->update_calls = 0;
        $this->failureNoteMeta = [];
        $this->first_lookup_calls = 0;
    }

    protected function tearDown(): void {
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = null;
        self::$orders = [];
        parent::tearDown();
    }

    public static function getOrder($id) {
        return self::$orders[(int) $id] ?? false;
    }

    private function logger() {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        return $logger;
    }

    private function rosterRepository(?Roster $existing_on_second_lookup = null) {
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('insert_roster_entry')->andReturnUsing(function (array $row) {
            $this->insert_calls++;
            $this->inserted[] = $row;
            return 900000 + count($this->inserted);
        });
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->andReturn(true);
        $repo = Mockery::mock(RosterRepository::class, [$this->logger(), $db, $cache])->makePartial();
        if ($existing_on_second_lookup !== null) {
            $repo->shouldReceive('first')->andReturnUsing(function () use ($existing_on_second_lookup) {
                $this->first_lookup_calls++;
                return $this->first_lookup_calls <= 2 ? null : $existing_on_second_lookup;
            });
        } else {
            $repo->shouldReceive('first')->andReturn(null);
        }
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturn(new RostersCollection());
        if ($existing_on_second_lookup !== null) {
            $repo->shouldReceive('update')->once()->andReturnUsing(function ($id, $data) use ($existing_on_second_lookup) {
                $this->update_calls++;
                return $existing_on_second_lookup;
            });
        }
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

    private function makeBuilder(Player $player, ?RosterRepository $repo = null): RosterBuilder {
        $players = new PlayersCollection();
        $players->add($player);
        $playerRepo = Mockery::mock(PlayerRepository::class);
        $playerRepo->shouldReceive('getPlayersByCustomerId')->andReturn($players);
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn($players);
        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => [], 'field_errors' => []]);
        return new RosterBuilder(
            $this->logger(),
            Mockery::mock(Database::class),
            $playerRepo,
            $repo ?? $this->rosterRepository(),
            $validator,
            Mockery::mock(EventMatcher::class),
            $matcher
        );
    }

    private function makeBuilderWithRealValidator(Player $player, Logger $logger, ?RosterRepository $repo = null): RosterBuilder {
        $players = new PlayersCollection();
        $players->add($player);
        $playerRepo = Mockery::mock(PlayerRepository::class);
        $playerRepo->shouldReceive('getPlayersByCustomerId')->andReturn($players);
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn($players);
        return new RosterBuilder(
            $logger,
            Mockery::mock(Database::class),
            $playerRepo,
            $repo ?? $this->rosterRepository(),
            new DataValidator($logger),
            Mockery::mock(EventMatcher::class),
            $matcher
        );
    }

    private function meta(string $key, $value) {
        $m = Mockery::mock();
        $m->shouldReceive('get_data')->andReturn(['key' => $key, 'value' => $value]);
        return $m;
    }

    /** @param array<string,string> $meta */
    private function item(int $product_id, int $variation_id, array $meta) {
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

    private function order(int $order_id, int $customer_id, int $item_id, $item, array &$notes = null) {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_customer_id')->andReturn($customer_id);
        $order->shouldReceive('get_items')->andReturn([$item_id => $item]);
        $order->shouldReceive('get_item')->andReturnUsing(fn($id) => (int) $id === $item_id ? $item : false);
        if ($notes !== null) {
            $order->shouldReceive('add_order_note')->andReturnUsing(function ($note) use (&$notes) {
                $notes[] = $note;
                return count($notes);
            });
        } else {
            $order->shouldReceive('add_order_note')->andReturn(1);
        }
        $order->shouldReceive('get_meta')->andReturnUsing(function ($key, $single = false) {
            if ($key === '_intersoccer_roster_failure_notes') {
                return $single ? $this->failureNoteMeta : [$this->failureNoteMeta];
            }
            return $single ? '' : [];
        });
        $order->shouldReceive('get_coupon_codes')->andReturn([]);
        $order->shouldReceive('get_billing_company')->andReturn('');
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

    /** @param array<string,string> $overrides */
    private function courseItemMeta(string $uuid, string $dob, array $overrides = []): array {
        $base = [
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
        return array_merge($base, $overrides);
    }

    private function buildErrors(RosterBuilder $b): array {
        return $b->getBuildStatistics()['errors'] ?? [];
    }

    private function ageStatusForInsertedRow(int $index = 0): string {
        $row = $this->inserted[$index];
        $player = $this->player(
            (int) ($row['customer_id'] ?? 0),
            (string) ($row['player_id'] ?? '00000000-0000-4000-8000-000000000001'),
            (string) ($row['player_dob'] ?? $row['dob'] ?? '')
        );

        return $player->ageGroupStatusAt(
            (string) ($row['age_group'] ?? ''),
            (string) ($row['start_date'] ?? '') !== '' ? (string) $row['start_date'] : null
        );
    }

    private function ageOutsideBadgeHtml(int $index = 0): string {
        if (!function_exists('intersoccer_roster_age_outside_group_badge_html')) {
            require_once dirname(__DIR__, 2) . '/includes/roster-details.php';
        }
        return intersoccer_roster_age_outside_group_badge_html((object) $this->inserted[$index]);
    }

    private function eventDetailsArray(int $index = 0): array {
        $ed = $this->inserted[$index]['event_details'] ?? [];
        if (is_string($ed)) {
            $ed = json_decode($ed, true) ?: [];
        }
        return is_array($ed) ? $ed : [];
    }

    private function runHookBuild(int $order_id, Player $player, ?RosterRepository $repo = null): RosterBuilder {
        $b = $this->makeBuilder($player, $repo);
        $rows = $b->buildRosterFromOrder($order_id, self::LIVE_HOOK_OPTIONS);
        $this->assertSame(1, $rows->count(), 'errors: ' . json_encode($this->buildErrors($b)));
        $this->assertGreaterThanOrEqual(1, count($this->inserted));
        return $b;
    }

    public function test_live_hook_passes_skip_age_group_validation_like_reconcile_and_fix_sync() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1001);
        $order->shouldReceive('get_status')->andReturn('completed');
        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('count')->andReturn(0);
        $captured = null;
        $builder = Mockery::mock(RosterBuilder::class);
        $builder->shouldReceive('buildRosterFromOrder')->andReturnUsing(function ($id, $opts) use (&$captured) {
            $captured = $opts;
            return new RostersCollection();
        });
        $builder->shouldReceive('hadOrderItemWriteFailures')->andReturn(false);
        $logger = Mockery::spy(Logger::class);
        (new OrderProcessor($logger, $repo, $builder))->processOrder($order);

        $this->assertIsArray($captured);
        $this->assertTrue(!empty($captured['skip_age_group_validation']));
    }

    public function test_under_minimum_age_writes_row_and_read_time_outside_status() {
        $uuid = '00000000-0000-4000-8000-000000000511';
        $item = $this->item(52636, 52637, $this->courseItemMeta($uuid, '2023-08-31'));
        $this->order(1011, 211, 511, $item);
        $this->runHookBuild(1011, $this->player(211, $uuid, '2023-08-31'));

        $this->assertSame('outside', $this->ageStatusForInsertedRow());
        $this->assertStringContainsString('Age outside group', $this->ageOutsideBadgeHtml());
        $this->assertArrayNotHasKey('under_age_at_start', $this->eventDetailsArray());
    }

    public function test_over_maximum_age_writes_row_and_read_time_outside_status() {
        $uuid = '00000000-0000-4000-8000-000000000512';
        $meta = $this->courseItemMeta($uuid, '2010-02-03', [
            'pa_age-group' => '3-5y',
            '_intersoccer_canonical_age_group' => '3-5y',
            'Age Group' => '3-5y',
        ]);
        $item = $this->item(52636, 52637, $meta);
        $this->order(1012, 212, 512, $item);
        $this->runHookBuild(1012, $this->player(212, $uuid, '2010-02-03'));

        $this->assertSame('outside', $this->ageStatusForInsertedRow());
        $this->assertStringContainsString('Age outside group', $this->ageOutsideBadgeHtml());
    }

    public function test_age_group_5_12_in_range_is_ok() {
        $uuid = '00000000-0000-4000-8000-000000000513';
        $meta = $this->courseItemMeta($uuid, '2015-02-03', [
            'pa_age-group' => '5-12',
            '_intersoccer_canonical_age_group' => '5-12',
            'Age Group' => '5-12',
        ]);
        $item = $this->item(52636, 52637, $meta);
        $this->order(1013, 213, 513, $item);
        $this->runHookBuild(1013, $this->player(213, $uuid, '2015-02-03'));

        $this->assertSame('ok', $this->ageStatusForInsertedRow());
        $this->assertSame('', $this->ageOutsideBadgeHtml());
    }

    public function test_age_group_5_12_out_of_range_is_outside() {
        $uuid = '00000000-0000-4000-8000-000000000514';
        $meta = $this->courseItemMeta($uuid, '2023-08-31', [
            'pa_age-group' => '5-12',
            '_intersoccer_canonical_age_group' => '5-12',
            'Age Group' => '5-12',
        ]);
        $item = $this->item(52636, 52637, $meta);
        $this->order(1014, 214, 514, $item);
        $this->runHookBuild(1014, $this->player(214, $uuid, '2023-08-31'));

        $this->assertSame('outside', $this->ageStatusForInsertedRow());
    }

    public function test_pre_k_to_g5_label_is_not_checked() {
        $uuid = '00000000-0000-4000-8000-000000000515';
        $meta = $this->courseItemMeta($uuid, '2015-02-03', [
            'pa_age-group' => 'Pre-K to G5',
            '_intersoccer_canonical_age_group' => 'Pre-K to G5',
            'Age Group' => 'Pre-K to G5',
        ]);
        $item = $this->item(52636, 52637, $meta);
        $this->order(1015, 215, 515, $item);
        $this->runHookBuild(1015, $this->player(215, $uuid, '2015-02-03'));

        $this->assertSame('not_checked', $this->ageStatusForInsertedRow());
        $this->assertSame('', $this->ageOutsideBadgeHtml());
    }

    public function test_missing_dob_is_not_checked() {
        $uuid = '00000000-0000-4000-8000-000000000516';
        $meta = $this->courseItemMeta($uuid, '0000-00-00', [
            'Attendee DOB' => '0000-00-00',
            'Player DOB' => '0000-00-00',
        ]);
        $item = $this->item(52636, 52637, $meta);
        $this->order(1016, 216, 516, $item);
        $this->runHookBuild(1016, $this->player(216, $uuid, '0000-00-00'));

        $this->assertSame('not_checked', $this->ageStatusForInsertedRow());
    }

    public function test_real_validator_without_skip_rejects_under_age_at_start() {
        $uuid = '00000000-0000-4000-8000-000000000517';
        $item = $this->item(52636, 52637, $this->courseItemMeta($uuid, '2023-08-31'));
        $this->order(1017, 217, 517, $item);
        $advisory_rules = '';
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'error')->andReturn(null);
        $logger->shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$advisory_rules) {
            if ($message === 'Roster data validation advisory failure on write path' && !empty($context['rules'])) {
                $advisory_rules = (string) $context['rules'];
            }
            return null;
        });
        $b = $this->makeBuilderWithRealValidator($this->player(217, $uuid, '2023-08-31'), $logger);

        $rows = $b->buildRosterFromOrder(1017, [
            'validate_data' => true,
            'skip_duplicates' => true,
            'update_existing' => true,
            'skip_age_group_validation' => false,
        ]);

        $this->assertSame(0, $rows->count());
        $this->assertTrue($b->hadOrderItemWriteFailures());
        $this->assertStringContainsString('not eligible for age group', implode(' ', $this->buildErrors($b)));
        $this->assertStringContainsString('age_group_eligibility', $advisory_rules);
    }

    public function test_hook_rerun_on_same_order_upserts_without_duplicate_insert() {
        $uuid = '00000000-0000-4000-8000-000000000518';
        $item = $this->item(52636, 52637, $this->courseItemMeta($uuid, '2023-08-31'));
        $this->order(1018, 218, 518, $item);
        $existing = Mockery::mock(Roster::class)->shouldIgnoreMissing();
        $existing->id = 42;
        $existing->order_id = 1018;
        $existing->order_item_id = 518;
        $existing->customer_id = 218;
        $existing->player_index = 0;
        $existing->end_date = '2026-12-20';
        $existing->event_completed = 0;
        $existing->order_status = 'completed';
        $repo = $this->rosterRepository($existing);
        $b = $this->makeBuilder($this->player(218, $uuid, '2023-08-31'), $repo);

        $first = $b->buildRosterFromOrder(1018, self::LIVE_HOOK_OPTIONS);
        $this->assertSame(1, $first->count());
        $this->assertSame(1, $this->insert_calls);

        $second = $b->buildRosterFromOrder(1018, self::LIVE_HOOK_OPTIONS);
        $this->assertSame(1, $second->count());
        $this->assertSame(1, $this->insert_calls);
        $this->assertSame(1, $this->update_calls);
    }

    public function test_order_processor_hook_path_notes_once_and_returns_false_on_db_failure() {
        $uuid = '00000000-0000-4000-8000-000000000519';
        $item = $this->item(52636, 52637, $this->courseItemMeta($uuid, '2015-02-03'));
        $notes = [];
        $order = $this->order(1019, 219, 519, $item, $notes);
        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => [], 'field_errors' => []]);
        $db = Mockery::mock(Database::class);
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->andReturn(true);
        $repo = Mockery::mock(RosterRepository::class, [$this->logger(), $db, $cache])->makePartial();
        $repo->shouldReceive('first')->andReturn(null);
        $repo->shouldReceive('count')->andReturn(0);
        $repo->shouldReceive('where')->andReturn(new RostersCollection());
        $repo->shouldReceive('create')->andReturn(null);
        $players = new PlayersCollection();
        $players->add($this->player(219, $uuid, '2015-02-03'));
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
        $processor = new OrderProcessor($this->logger(), $repo, $builder);

        $this->assertFalse($processor->processOrder($order));
        $this->assertCount(1, $notes);
        $this->assertFalse($processor->processOrder($order));
        $this->assertCount(1, $notes);
    }

    public function test_eligible_age_is_ok_without_stored_marker() {
        $uuid = '00000000-0000-4000-8000-000000000520';
        $item = $this->item(52636, 52637, $this->courseItemMeta($uuid, '2015-02-03'));
        $this->order(1020, 220, 520, $item);
        $this->runHookBuild(1020, $this->player(220, $uuid, '2015-02-03'));

        $this->assertSame('ok', $this->ageStatusForInsertedRow());
        $this->assertArrayNotHasKey('under_age_at_start', $this->eventDetailsArray());
    }
}
