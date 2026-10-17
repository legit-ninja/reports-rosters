<?php
/**
 * Repro: live order-status hook writes no roster row for a course line when the assigned
 * player is still under the age group's minimum on the course start date (turns 3 a few
 * days/weeks after the term starts).
 *
 * Live path (origin/master 80bf153):
 *   HooksManager::handle_order_status (woocommerce_order_status_completed)
 *   -> OrderProcessor::processOrder, order-processor.php:119-126 (no skip_age_group_validation)
 *   -> RosterBuilder::buildRosterFromOrder -> processOrderItems -> processOrderItem
 *   -> createOrUpdateRoster (roster-builder.php:1668-1672, flag not set)
 *   -> RosterRepository::create -> Roster::validate (roster-repository.php:500)
 *   -> Roster::validateAgeGroup throws (roster.php:926-930)
 *   -> rethrown roster-repository.php:545-550, swallowed roster-builder.php:755-763.
 * Reconcile (roster-builder.php:304) and diagnostics "Fix Sync" pass skip_age_group_validation=true,
 * so the same line gets a row from those paths but never from the hook.
 *
 * Meta shapes are copied from two missing course lines in the staging-copy sandbox (Etoy EN
 * order, Versoix FR order) and from a same-variation line that did get a row for each, anonymized:
 * no names, emails, phones; order, item, customer ids and player UUIDs are synthetic. Course
 * dates, age groups and the players' dates of birth relative to the start date are the real values.
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

    /** Options passed by the live hook, copied from order-processor.php build options. */
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

    /** @var RosterBuilder */
    private $builder;

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
    }

    protected function tearDown(): void {
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = null;
        self::$orders = [];
        parent::tearDown();
    }

    public static function getOrder($id) {
        return self::$orders[(int) $id] ?? false;
    }

    // ------------------------------------------------------------------ fixtures

    private function logger() {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        return $logger;
    }

    /** Real RosterRepository::create() + Roster::validate(); only storage is faked. */
    private function rosterRepository() {
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('insert_roster_entry')->andReturnUsing(function (array $row) {
            $this->inserted[] = $row;
            return 900000 + count($this->inserted);
        });
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->andReturn(true);
        $repo = Mockery::mock(RosterRepository::class, [$this->logger(), $db, $cache])->makePartial();
        $repo->shouldReceive('first')->andReturn(null);          // no existing row for the line
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

    private function makeBuilder(Player $player): RosterBuilder {
        $players = new PlayersCollection();
        $players->add($player);
        $playerRepo = Mockery::mock(PlayerRepository::class);
        $playerRepo->shouldReceive('getPlayersByCustomerId')->andReturn($players);
        // Players match by UUID in the sandbox (assignByMetadataUuid); not under test here.
        $matcher = Mockery::mock(PlayerMatcher::class);
        $matcher->shouldReceive('getAssignedPlayers')->andReturn($players);
        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => [], 'field_errors' => []]);
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

    /** Same wiring as makeBuilder but exercises DataValidator cross-field age rules. */
    private function makeBuilderWithRealValidator(Player $player): RosterBuilder {
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

    private function order(int $order_id, int $customer_id, int $item_id, $item) {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_customer_id')->andReturn($customer_id);
        $order->shouldReceive('get_items')->andReturn([$item_id => $item]);
        $order->shouldReceive('get_item')->andReturnUsing(fn($id) => (int) $id === $item_id ? $item : false);
        $order->shouldReceive('add_order_note')->andReturn(1);
        foreach (['get_billing_email' => 'parent@example.test', 'get_billing_phone' => '+41 00 000 00 00',
                  'get_billing_first_name' => 'Parent', 'get_billing_last_name' => 'Test'] as $m => $v) {
            $order->shouldReceive($m)->andReturn($v);
        }
        self::$orders[$order_id] = $order;
        return $order;
    }

    /** Etoy Autumn 2026 course line, EN order (missing case A and its control). */
    private function etoyItemMeta(string $uuid, string $dob): array {
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

    /**
     * Versoix Autumn 2026 course line after RosterBuilder::buildRosterData() (values as replayed
     * in the sandbox for missing case B and its control, terms already resolved to English names).
     */
    private function versoixRosterData(int $order_id, int $item_id, int $customer_id, string $uuid, string $dob): array {
        return [
            'order_id' => $order_id, 'order_item_id' => $item_id,
            'product_id' => 30713, 'variation_id' => 30714, 'customer_id' => $customer_id,
            'order_status' => 'completed', 'activity_type' => 'Course',
            'venue' => 'Versoix - Centre Sportif Versoix', 'age_group' => '3-7y',
            'season' => 'Autumn 2026', 'booking_type' => 'Full Term', 'course_day' => 'Wednesday',
            'times' => '1600-1700', 'course_times' => '16h00-17h00',
            'start_date' => '2026-08-26', 'end_date' => '2026-12-09', 'selected_days' => '',
            'girls_only' => 0, 'player_index' => 0, 'player_id' => $uuid,
            'first_name' => 'Test', 'last_name' => 'Player', 'player_first_name' => 'Test', 'player_last_name' => 'Player',
            'dob' => $dob, 'player_dob' => $dob, 'gender' => 'male', 'player_gender' => 'male',
            'parent_email' => 'parent@example.test', 'parent_phone' => '+41 00 000 00 00',
            'event_signature' => '9e06fef35bc59188776ee293500fbdae',
        ];
    }

    private function callPrivate($obj, string $method, ...$args) {
        $m = (new \ReflectionClass($obj))->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    private function buildErrors(RosterBuilder $b): array {
        return $b->getBuildStatistics()['errors'] ?? [];
    }

    // ------------------------------------------------------------------ live hook contract

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
        $logger = Mockery::spy(Logger::class);
        (new OrderProcessor($logger, $repo, $builder))->processOrder($order);

        $this->assertIsArray($captured, 'buildRosterFromOrder was not called');
        $this->assertTrue(
            !empty($captured['skip_age_group_validation']),
            'order-processor.php:119-126 builds with ' . json_encode($captured)
            . ' - Roster::validateAgeGroup then rejects players under the minimum age on the start date'
        );
    }

    // ------------------------------------------------------------------ Etoy (full path from item meta)

    public function test_real_validator_hook_options_with_skip_creates_under_age_row() {
        $uuid = '00000000-0000-4000-8000-000000000511';
        $item = $this->item(52636, 52637, $this->etoyItemMeta($uuid, '2023-08-31'));
        $this->order(1011, 211, 511, $item);
        $b = $this->makeBuilderWithRealValidator($this->player(211, $uuid, '2023-08-31'));

        $rows = $b->buildRosterFromOrder(1011, self::LIVE_HOOK_OPTIONS);

        $this->assertSame(1, $rows->count(), 'errors: ' . json_encode($this->buildErrors($b)));
        $this->assertCount(1, $this->inserted);
        $this->assertFalse($b->hadOrderItemWriteFailures());
    }

    public function test_real_validator_without_skip_rejects_under_age_at_start() {
        $uuid = '00000000-0000-4000-8000-000000000512';
        $item = $this->item(52636, 52637, $this->etoyItemMeta($uuid, '2023-08-31'));
        $this->order(1012, 212, 512, $item);
        $b = $this->makeBuilderWithRealValidator($this->player(212, $uuid, '2023-08-31'));

        $rows = $b->buildRosterFromOrder(1012, [
            'validate_data' => true,
            'skip_duplicates' => true,
            'update_existing' => true,
        ]);

        $this->assertSame(0, $rows->count());
        $this->assertTrue($b->hadOrderItemWriteFailures());
        $errors = $this->buildErrors($b);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not eligible for age group', implode(' ', $errors));
    }

    public function test_case_a_etoy_live_hook_options_create_one_row() {
        $uuid = '00000000-0000-4000-8000-000000000501';
        $item = $this->item(52636, 52637, $this->etoyItemMeta($uuid, '2023-08-31')); // age 2 on 2026-08-23
        $this->order(1001, 201, 501, $item);
        $b = $this->makeBuilder($this->player(201, $uuid, '2023-08-31'));

        $rows = $b->buildRosterFromOrder(1001, self::LIVE_HOOK_OPTIONS);

        $this->assertSame(1, $rows->count(), 'swallowed: ' . json_encode($this->buildErrors($b)));
        $this->assertCount(1, $this->inserted);
    }

    public function test_control_a_etoy_same_variation_creates_one_row() {
        $uuid = '00000000-0000-4000-8000-000000000502';
        $item = $this->item(52636, 52637, $this->etoyItemMeta($uuid, '2015-02-03')); // age 11 on 2026-08-23
        $this->order(1002, 202, 502, $item);
        $b = $this->makeBuilder($this->player(202, $uuid, '2015-02-03'));

        $rows = $b->buildRosterFromOrder(1002, self::LIVE_HOOK_OPTIONS);

        $this->assertSame(1, $rows->count(), 'errors: ' . json_encode($this->buildErrors($b)));
        $row = $this->inserted[0];
        $this->assertSame('3-12y', $row['age_group']);
        $this->assertSame('2026-08-23', (string) $row['start_date']);
    }

    public function test_case_a_etoy_reconcile_options_create_one_row() {
        $uuid = '00000000-0000-4000-8000-000000000501';
        $item = $this->item(52636, 52637, $this->etoyItemMeta($uuid, '2023-08-31'));
        $this->order(1001, 201, 501, $item);
        $b = $this->makeBuilder($this->player(201, $uuid, '2023-08-31'));

        $rows = $b->buildRosterFromOrder(1001, self::LIVE_HOOK_OPTIONS + ['skip_age_group_validation' => true]);

        $this->assertSame(1, $rows->count(), 'errors: ' . json_encode($this->buildErrors($b)));
    }

    // ------------------------------------------------------------------ Versoix (createOrUpdateRoster -> real create/validate)

    public function test_case_b_versoix_live_hook_options_create_row() {
        $uuid = '00000000-0000-4000-8000-000000000503';
        $b = $this->makeBuilder($this->player(203, $uuid, '2023-09-10'));
        $data = $this->versoixRosterData(1003, 503, 203, $uuid, '2023-09-10'); // age 2 on 2026-08-26

        try {
            $roster = $this->callPrivate($b, 'createOrUpdateRoster', $data, self::LIVE_HOOK_OPTIONS);
        } catch (\Throwable $e) {
            $this->fail(get_class($e) . ' (swallowed live at roster-builder.php:755): ' . $e->getMessage());
        }
        $this->assertInstanceOf(Roster::class, $roster);
    }

    public function test_control_b_versoix_same_variation_creates_row() {
        $uuid = '00000000-0000-4000-8000-000000000504';
        $b = $this->makeBuilder($this->player(204, $uuid, '2023-08-22'));
        $data = $this->versoixRosterData(1004, 504, 204, $uuid, '2023-08-22'); // turned 3 on 2026-08-22

        $roster = $this->callPrivate($b, 'createOrUpdateRoster', $data, self::LIVE_HOOK_OPTIONS);

        $this->assertInstanceOf(Roster::class, $roster);
        $this->assertCount(1, $this->inserted);
    }

    /** Rules out the 'y'-suffix parse bug: both age groups parse; only age-at-start differs. */
    public function test_age_groups_parse_and_only_age_on_start_date_differs() {
        $parse = new \ReflectionMethod(Player::class, 'parseAgeGroup');
        $parse->setAccessible(true);
        $p = $this->player(1, 'x', '2023-09-10');
        $this->assertSame(['min' => 3, 'max' => 7], $parse->invoke($p, '3-7y'));
        $this->assertSame(['min' => 3, 'max' => 12], $parse->invoke($p, '3-12y'));
        $this->assertSame(2, $this->player(1, 'x', '2023-09-10')->getAgeAt('2026-08-26'));
        $this->assertSame(2, $this->player(1, 'x', '2023-08-31')->getAgeAt('2026-08-23'));
        $this->assertSame(3, $this->player(1, 'x', '2023-08-22')->getAgeAt('2026-08-26'));
    }
}
