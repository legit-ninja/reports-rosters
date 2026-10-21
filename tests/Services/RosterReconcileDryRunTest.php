<?php

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class RosterReconcileDryRunTest extends TestCase {

    /** @var array<int,array<string,mixed>> */
    private $rows = [];

    /** @var int */
    private $insertCount = 0;

    /** @var int */
    private $updateCount = 0;

    /** @var int */
    private $deleteCount = 0;

    /** @var array<int,array<string,mixed>> */
    private $lastCreatePayload = [];

    /** @var array<int,array<string,mixed>> */
    private $lastUpdatePayload = [];

    /** @var RosterBuilder */
    private $builder;

    /** @var PlayerMatcher */
    private $playerMatcher;

    /** @var PlayerRepository|\Mockery\MockInterface */
    private $playerRepository;

    /** @var array<int,PlayersCollection> */
    private $playersByCustomerId = [];

    protected function setUp(): void {
        parent::setUp();

        $order_meta_keys = dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        if (is_readable($order_meta_keys)) {
            require_once $order_meta_keys;
        }
        $this->rows = [];
        $this->insertCount = 0;
        $this->updateCount = 0;
        $this->deleteCount = 0;
        $this->lastCreatePayload = [];
        $this->lastUpdatePayload = [];
        $this->playersByCustomerId = [];
        \InterSoccerRRTestMeta::$data = [];
        $GLOBALS['intersoccer_test_transient_calls'] = 0;
        $GLOBALS['intersoccer_test_wp_cache_flush_count'] = 0;
        $GLOBALS['intersoccer_test_update_post_meta_calls'] = 0;
        $GLOBALS['intersoccer_test_pv_get_product_type_called'] = false;

        add_action('setted_transient', static function () {
            $GLOBALS['intersoccer_test_transient_calls'] = (int) ($GLOBALS['intersoccer_test_transient_calls'] ?? 0) + 1;
        }, 10, 3);

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturnUsing(function () {
            return $this->makeWpdb();
        });

        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('deleteByOrderItemId')->andReturnUsing(function () {
            $this->deleteCount++;
            return 1;
        });
        $rosterRepository->shouldReceive('first')->andReturnUsing(function (array $where) {
            $item_id = (int) ($where['order_item_id'] ?? 0);
            foreach ($this->rows as $row) {
                if ((int) ($row['order_item_id'] ?? 0) === $item_id) {
                    $model = new Roster($row);
                    return $model;
                }
            }
            return null;
        });
        $rosterRepository->shouldReceive('create')->andReturnUsing(function (array $data) {
            $this->insertCount++;
            $this->lastCreatePayload = $data;
            return new Roster($data);
        });
        $rosterRepository->shouldReceive('update')->andReturnUsing(function ($id, array $data) {
            $this->updateCount++;
            $this->lastUpdatePayload = $data;
            return new Roster($data);
        });
        $rosterRepository->shouldReceive('where')->andReturnSelf();
        $rosterRepository->shouldReceive('count')->andReturn(0);
        $rosterRepository->shouldReceive('syncOrderStatusForKeptRows')->andReturn(0);

        $this->playerMatcher = new PlayerMatcher($logger);

        $this->playerRepository = Mockery::mock(PlayerRepository::class);
        $this->playerRepository->shouldReceive('getPlayersByCustomerId')->andReturnUsing(function ($customerId) {
            $id = (int) $customerId;
            return $this->playersByCustomerId[$id] ?? new PlayersCollection();
        });

        $validator = Mockery::mock(DataValidator::class);
        $validator->shouldReceive('validateRosterData')->andReturn(['valid' => true, 'errors' => []]);

        $this->builder = new RosterBuilder(
            $logger,
            $database,
            $this->playerRepository,
            $rosterRepository,
            $validator,
            Mockery::mock(EventMatcher::class),
            $this->playerMatcher
        );

    }

    protected function tearDown(): void {
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        if (class_exists(Monkey::class)) {
            Monkey\tearDown();
        }
        parent::tearDown();
    }

    /**
     * @param int[] $orderIds
     */
    private function primeWcOrderMocks(array $orderIds): void {
        if (!class_exists(Monkey::class)) {
            return;
        }
        Monkey\setUp();
        Functions\when('wc_get_orders')->justReturn($orderIds);
        Functions\when('wc_get_order')->alias(function ($order_id) {
            global $intersoccer_test_wc_get_order_callback;
            if (is_callable($intersoccer_test_wc_get_order_callback)) {
                return call_user_func($intersoccer_test_wc_get_order_callback, $order_id);
            }
            return false;
        });
    }

    private function makeWpdb(): object {
        $rows = &$this->rows;
        return new class($rows) {
            public $prefix = 'wp_';
            public $last_error = '';
            /** @var array<int,array<string,mixed>> */
            private $rowsRef;
            public function __construct(&$rows) {
                $this->rowsRef = &$rows;
            }
            public function get_results($query, $output = OBJECT) {
                return $output === ARRAY_A ? $this->rowsRef : $this->rowsRef;
            }
            public function get_var($query) {
                if (preg_match('/order_item_id\s*=\s*(\d+)/', (string) $query, $m)) {
                    $item_id = (int) $m[1];
                    foreach ($this->rowsRef as $row) {
                        if ((int) ($row['order_item_id'] ?? 0) === $item_id) {
                            return 1;
                        }
                    }
                    return 0;
                }
                return 0;
            }
            public function prepare($query, ...$args) {
                return $query;
            }
            public function query($query) {
                return false;
            }
            public function insert($table, $data, $format = null) {
                return false;
            }
            public function update($table, $data, $where, $format = null, $where_format = null) {
                return false;
            }
            public function delete($table, $where, $where_format = null) {
                return false;
            }
            public function replace($table, $data, $format = null) {
                return false;
            }
        };
    }

    public function test_dry_run_reconcile_reports_insert_update_delete_without_writes(): void {
        $this->rows = [
            [
                'id' => 1,
                'order_item_id' => 9102,
                'order_id' => 5001,
                'venue' => 'stored-venue',
                'activity_type' => 'Camp',
                'season' => 'Summer 2026',
                'event_signature' => 'sig-old',
                'event_completed' => 0,
                'end_date' => '2030-06-01',
                'player_first_name' => 'Keep',
                'player_last_name' => 'Me',
            ],
            ['order_item_id' => 9103, 'order_id' => 5002],
        ];

        $this->playersByCustomerId[42] = new PlayersCollection([$this->makePlayer('a')]);

        $itemInsert = $this->makeCampItem(9101, [
            'Activity Type' => 'Camp',
            'assigned_player_id' => 'uuid-a',
            'Venue' => 'geneva-sport-center',
            'Season' => 'Summer 2026',
            'Booking Type' => 'Full Week',
        ]);
        $itemUpdate = $this->makeCampItem(9102, [
            'Activity Type' => 'Camp',
            'assigned_player_id' => 'uuid-a',
            'Venue' => 'zurich-new-venue',
            'Season' => 'Summer 2026',
            'Booking Type' => 'Full Week',
        ]);

        $order = $this->makeOrder(5001, 42, [
            9101 => $itemInsert,
            9102 => $itemUpdate,
        ]);

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function () {
            return [5001];
        };
        $intersoccer_test_wc_get_order_callback = static function ($order_id) use ($order) {
            if ((int) $order_id === 5001) {
                return $order;
            }
            return false;
        };

        $this->primeWcOrderMocks([5001]);

        $metaBefore = count(\InterSoccerRRTestMeta::$data, COUNT_RECURSIVE);

        $result = $this->builder->reconcile(['dry_run' => true, 'delete_obsolete' => true]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['report']['would_insert']);
        $this->assertSame(1, $result['report']['would_update']);
        $this->assertSame(1, $result['report']['would_delete']);
        $this->assertSame(0, $this->insertCount);
        $this->assertSame(0, $this->updateCount);
        $this->assertSame(0, $this->deleteCount);
        $this->assertSame(0, (int) ($GLOBALS['intersoccer_test_transient_calls'] ?? 0));
        $this->assertSame($metaBefore, count(\InterSoccerRRTestMeta::$data, COUNT_RECURSIVE));
    }

    public function test_order_quantity_with_matching_player_count_is_not_ambiguous(): void {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn(602);
        $item->shouldReceive('get_product_id')->andReturn(10);
        $item->shouldReceive('get_variation_id')->andReturn(0);
        $item->shouldReceive('get_meta')->andReturn('');
        $item->shouldReceive('get_meta_data')->andReturn([]);
        $item->shouldReceive('get_quantity')->andReturn(2);

        $players = new PlayersCollection();
        $players->add($this->makePlayer('a'));
        $players->add($this->makePlayer('b'));

        $resolution = $this->playerMatcher->resolveAssignedPlayers($item, $players, ['skip_age_gender_validation' => true]);
        $this->assertFalse($resolution['ambiguous']);
    }

    public function test_reconcile_skips_ambiguous_wrong_child_and_preserves_existing_player_fields(): void {
        $this->rows = [
            [
                'id' => 5,
                'order_item_id' => 9301,
                'order_id' => 5100,
                'venue' => 'venue-a',
                'activity_type' => 'Camp',
                'season' => 'Summer 2026',
                'event_completed' => 0,
                'end_date' => '2030-06-01',
                'player_first_name' => 'Original',
                'player_last_name' => 'Child',
                'player_id' => 'uuid-keep',
            ],
        ];

        $this->playersByCustomerId[77] = new PlayersCollection([
            $this->makePlayer('a'),
            $this->makePlayer('b'),
        ]);

        $item = $this->makeCampItem(9301, [
            'Activity Type' => 'Camp',
            'Venue' => 'venue-a',
            'Season' => 'Summer 2026',
            'Booking Type' => 'Full Week',
        ]);
        $item->shouldReceive('get_quantity')->andReturn(1);

        $order = $this->makeOrder(5100, 77, [9301 => $item]);

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function () {
            return [5100];
        };
        $intersoccer_test_wc_get_order_callback = static function ($order_id) use ($order) {
            return (int) $order_id === 5100 ? $order : false;
        };

        $this->primeWcOrderMocks([5100]);

        $result = $this->builder->reconcile(['dry_run' => false, 'delete_obsolete' => false]);

        $this->assertSame(1, $result['report']['ambiguous_player']['count']);
        $this->assertSame(0, $this->insertCount);
        $this->assertSame(0, $this->updateCount);
        $this->assertSame('Original', $this->rows[0]['player_first_name']);
        $this->assertSame('Child', $this->rows[0]['player_last_name']);
    }

    public function test_only_missing_inserts_missing_row_without_update_or_delete(): void {
        $this->rows = [
            [
                'id' => 2,
                'order_item_id' => 9201,
                'order_id' => 5001,
                'venue' => 'stored-venue',
                'activity_type' => 'Camp',
                'season' => 'Summer 2026',
                'event_completed' => 0,
                'end_date' => '2030-06-01',
                'player_first_name' => 'Existing',
                'player_last_name' => 'Row',
            ],
            ['order_item_id' => 9209, 'order_id' => 5299],
        ];

        $this->playersByCustomerId[42] = new PlayersCollection([$this->makePlayer('a')]);

        $existingItem = $this->makeCampItem(9201, [
            'Activity Type' => 'Camp',
            'assigned_player_id' => 'uuid-a',
            'Venue' => 'zurich-new-venue',
            'Season' => 'Summer 2026',
            'Booking Type' => 'Full Week',
        ]);
        $missingItem = $this->makeCampItem(9202, [
            'Activity Type' => 'Camp',
            'assigned_player_id' => 'uuid-a',
            'Venue' => 'geneva-sport-center',
            'Season' => 'Summer 2026',
            'Booking Type' => 'Full Week',
        ]);

        $order = $this->makeOrder(5001, 42, [
            9201 => $existingItem,
            9202 => $missingItem,
        ]);

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function () {
            return [5001];
        };
        $intersoccer_test_wc_get_order_callback = static function ($order_id) use ($order) {
            if ((int) $order_id === 5001) {
                return $order;
            }
            return false;
        };

        $this->primeWcOrderMocks([5001]);

        $result = $this->builder->reconcile(['only_missing' => true, 'delete_obsolete' => true]);

        $this->assertSame(1, $this->insertCount);
        $this->assertSame(0, $this->updateCount);
        $this->assertSame(0, $this->deleteCount);
        $this->assertSame(0, $result['deleted']);
        $this->assertNotSame('', (string) ($this->lastCreatePayload['activity_type'] ?? ''));
        if (function_exists('intersoccer_canonical_activity_type_for_roster')) {
            $this->assertSame('Camp', intersoccer_canonical_activity_type_for_roster($this->lastCreatePayload['activity_type'] ?? ''));
        }
        if (function_exists('intersoccer_normalize_booking_type_for_storage')) {
            $this->assertSame('Full Week', intersoccer_normalize_booking_type_for_storage('Semaine complète'));
        }
    }

    public function test_get_product_type_safe_read_only_skips_pv_and_post_meta_writes(): void {
        if (!function_exists('intersoccer_get_product_type_safe')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }

        if (!function_exists('intersoccer_get_product_type')) {
            eval('function intersoccer_get_product_type($id) {
                $GLOBALS["intersoccer_test_pv_get_product_type_called"] = true;
                if (function_exists("update_post_meta")) {
                    update_post_meta((int) $id, "_intersoccer_product_type", "camp");
                }
                return "camp";
            }');
        }

        \InterSoccerRRTestMeta::$data[10] = ['_intersoccer_product_type' => 'course'];

        $GLOBALS['intersoccer_rr_reconcile_block_product_type_meta_write'] = true;

        $type = intersoccer_get_product_type_safe(10, 0);

        unset($GLOBALS['intersoccer_rr_reconcile_block_product_type_meta_write']);

        $this->assertSame('course', $type);
        $this->assertFalse((bool) ($GLOBALS['intersoccer_test_pv_get_product_type_called'] ?? false));
        $this->assertSame('course', \InterSoccerRRTestMeta::$data[10]['_intersoccer_product_type']);
    }

    private function makeOrder(int $orderId, int $customerId, array $items): \Mockery\MockInterface {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($orderId);
        $order->shouldReceive('get_type')->andReturn('shop_order');
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_customer_id')->andReturn($customerId);
        $order->shouldReceive('get_billing_email')->andReturn('parent@example.com');
        $order->shouldReceive('get_billing_phone')->andReturn('');
        $order->shouldReceive('get_billing_first_name')->andReturn('Parent');
        $order->shouldReceive('get_billing_last_name')->andReturn('Test');
        $order->shouldReceive('get_items')->andReturn($items);
        return $order;
    }

    /**
     * @param array<string,string> $meta
     */
    private function makeCampItem(int $itemId, array $meta): \Mockery\MockInterface {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn($itemId);
        $item->shouldReceive('get_product_id')->andReturn(100);
        $item->shouldReceive('get_variation_id')->andReturn(0);
        $item->shouldReceive('get_product')->andReturn(null);
        $item->shouldReceive('get_quantity')->andReturn(1);
        $item->shouldReceive('get_meta')->andReturnUsing(static function ($key) use ($meta) {
            return $meta[$key] ?? '';
        });
        $metaObjects = [];
        foreach ($meta as $key => $value) {
            $metaObjects[] = $this->createOrderItemMeta($key, $value);
        }
        $item->shouldReceive('get_meta_data')->andReturn($metaObjects);
        return $item;
    }

    private function createOrderItemMeta(string $key, $value): object {
        return new class($key, $value) {
            private $key;
            private $value;
            public function __construct($key, $value) {
                $this->key = $key;
                $this->value = $value;
            }
            public function get_data() {
                return ['key' => $this->key, 'value' => $this->value];
            }
        };
    }

    private function makePlayer(string $suffix): Player {
        return new Player([
            'customer_id' => 1,
            'player_index' => $suffix === 'a' ? 0 : 1,
            'player_id' => 'uuid-' . $suffix,
            'first_name' => 'Child' . strtoupper($suffix),
            'last_name' => 'Test',
            'dob' => '2015-01-01',
            'gender' => 'male',
        ]);
    }
}
