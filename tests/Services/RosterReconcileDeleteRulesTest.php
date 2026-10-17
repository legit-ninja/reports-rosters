<?php
/**
 * Per-row obsolete delete rules during roster reconcile (stored order_id, direct wc_get_order).
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use Mockery;

class RosterReconcileDeleteRulesTest extends TestCase {

    /** @var int[] */
    private $deletedItemIds = [];

    /** @var RosterBuilder */
    private $builder;

    /** @var PlayerMatcher|\Mockery\MockInterface */
    private $playerMatcher;

    protected function setUp(): void {
        parent::setUp();

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $this->deletedItemIds = [];

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturnUsing(function () {
            return $this->makeWpdb($this->currentRows);
        });

        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('deleteByOrderItemId')->andReturnUsing(function (int $order_item_id) {
            $this->deletedItemIds[] = $order_item_id;
            return 1;
        });

        $this->playerMatcher = Mockery::mock(PlayerMatcher::class);

        $this->builder = new RosterBuilder(
            $logger,
            $database,
            Mockery::mock(PlayerRepository::class),
            $rosterRepository,
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            $this->playerMatcher
        );
    }

    /** @var array<int,array<string,string>> */
    private $currentRows = [];

    private function makeWpdb(array $rows): object {
        return new class($rows) {
            public $prefix = 'wp_';
            /** @var array<int,array<string,string>> */
            private $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function get_results($query, $output = OBJECT) {
                return $output === ARRAY_A ? $this->rows : $this->rows;
            }
            public function get_var($query) { return 0; }
            public function prepare($query, ...$args) { return $query; }
        };
    }

    private function runReconcile(array $rows, callable $ordersCallback, callable $orderCallback): void {
        $this->currentRows = $rows;
        $this->deletedItemIds = [];

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = $ordersCallback;
        $intersoccer_test_wc_get_order_callback = $orderCallback;

        $this->builder->reconcile(['delete_obsolete' => true, 'batch_size' => 50]);
    }

    protected function tearDown(): void {
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        $this->currentRows = [];
        parent::tearDown();
    }

    private static function makeEligibleOrder(int $orderId, int $itemId, array $itemIdsOnOrder): object {
        return new class($orderId, $itemId, $itemIdsOnOrder) {
            private $id;
            private $primaryItem;
            /** @var int[] */
            private $itemIds;
            public function __construct(int $id, int $primaryItem, array $itemIds) {
                $this->id = $id;
                $this->primaryItem = $primaryItem;
                $this->itemIds = $itemIds;
            }
            public function get_id() { return $this->id; }
            public function get_type() { return 'shop_order'; }
            public function get_status() { return 'completed'; }
            public function get_customer_id() { return 0; }
            public function get_items() {
                $items = [];
                foreach ($this->itemIds as $iid) {
                    $items[$iid] = new \stdClass();
                }
                return $items;
            }
        };
    }

    public function test_eligible_order_missing_from_query_keeps_row(): void {
        $orderId = 5001;
        $itemId = 8001;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId]],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $itemId) {
                if ((int) $order_id === $orderId) {
                    return self::makeEligibleOrder($orderId, $itemId, [$itemId]);
                }
                return false;
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_row_deleted_when_stored_order_no_longer_exists(): void {
        $itemId = 8002;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => '7500']],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) {
                return false;
            }
        );

        $this->assertContains($itemId, $this->deletedItemIds);
    }

    public function test_row_deleted_when_line_item_removed_from_eligible_order(): void {
        $orderId = 5003;
        $itemId = 8003;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId]],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $itemId) {
                if ((int) $order_id === $orderId) {
                    return self::makeEligibleOrder($orderId, $itemId, [99999]);
                }
                return false;
            }
        );

        $this->assertContains($itemId, $this->deletedItemIds);
    }

    public function test_failed_order_status_row_is_deleted(): void {
        $orderId = 5004;
        $itemId = 8004;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId]],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $itemId) {
                if ((int) $order_id !== $orderId) {
                    return false;
                }
                return new class($orderId, $itemId) {
                    private $id;
                    private $itemId;
                    public function __construct($id, $itemId) { $this->id = $id; $this->itemId = $itemId; }
                    public function get_id() { return $this->id; }
                    public function get_type() { return 'shop_order'; }
                    public function get_status() { return 'failed'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() { return [$this->itemId => new \stdClass()]; }
                };
            }
        );

        $this->assertContains($itemId, $this->deletedItemIds);
    }

    public function test_invalid_stored_order_id_keeps_row(): void {
        $itemId = 8005;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => '0']],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) {
                return false;
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_unreadable_order_items_keeps_row(): void {
        $orderId = 5007;
        $itemId = 8007;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId]],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $itemId) {
                if ((int) $order_id !== $orderId) {
                    return false;
                }
                return new class($orderId) {
                    private $id;
                    public function __construct($id) { $this->id = $id; }
                    public function get_id() { return $this->id; }
                    public function get_type() { return 'shop_order'; }
                    public function get_status() { return 'processing'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() { return null; }
                };
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_empty_order_items_array_keeps_row(): void {
        $orderId = 5008;
        $itemId = 8008;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId]],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $itemId) {
                if ((int) $order_id !== $orderId) {
                    return false;
                }
                return new class($orderId) {
                    private $id;
                    public function __construct($id) { $this->id = $id; }
                    public function get_id() { return $this->id; }
                    public function get_type() { return 'shop_order'; }
                    public function get_status() { return 'processing'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() { return []; }
                };
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_partial_line_item_removal_deletes_only_missing_row(): void {
        $orderId = 5009;
        $removedItemId = 8009;
        $keptItemId = 8010;
        $this->runReconcile(
            [
                ['order_item_id' => (string) $removedItemId, 'order_id' => (string) $orderId],
                ['order_item_id' => (string) $keptItemId, 'order_id' => (string) $orderId],
            ],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $keptItemId) {
                if ((int) $order_id === $orderId) {
                    return self::makeEligibleOrder($orderId, $keptItemId, [$keptItemId]);
                }
                return false;
            }
        );

        $this->assertContains($removedItemId, $this->deletedItemIds);
        $this->assertNotContains($keptItemId, $this->deletedItemIds);
    }

    public function test_false_order_items_keeps_row(): void {
        $orderId = 5010;
        $itemId = 8011;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId]],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $itemId) {
                if ((int) $order_id !== $orderId) {
                    return false;
                }
                return new class($orderId) {
                    private $id;
                    public function __construct($id) { $this->id = $id; }
                    public function get_id() { return $this->id; }
                    public function get_type() { return 'shop_order'; }
                    public function get_status() { return 'completed'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() { return false; }
                };
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_non_array_order_items_keeps_row(): void {
        $orderId = 5011;
        $itemId = 8012;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId]],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $itemId) {
                if ((int) $order_id !== $orderId) {
                    return false;
                }
                return new class($orderId) {
                    private $id;
                    public function __construct($id) { $this->id = $id; }
                    public function get_id() { return $this->id; }
                    public function get_type() { return 'shop_order'; }
                    public function get_status() { return 'completed'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() { return 'not-an-array'; }
                };
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_get_items_exception_keeps_row(): void {
        $orderId = 5012;
        $itemId = 8013;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => (string) $orderId]],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $itemId) {
                if ((int) $order_id !== $orderId) {
                    return false;
                }
                return new class($orderId) {
                    private $id;
                    public function __construct($id) { $this->id = $id; }
                    public function get_id() { return $this->id; }
                    public function get_type() { return 'shop_order'; }
                    public function get_status() { return 'processing'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() {
                        throw new \RuntimeException('simulated get_items failure');
                    }
                };
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_lookup_exception_keeps_row(): void {
        $itemId = 8006;
        $this->runReconcile(
            [['order_item_id' => (string) $itemId, 'order_id' => '5006']],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) {
                throw new \RuntimeException('simulated lookup failure');
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
    }

    public function test_refunded_and_cancelled_orders_are_not_resynced(): void {
        $this->playerMatcher->shouldReceive('getAssignedPlayers')->never();

        $cases = [
            ['order_id' => 5101, 'item_id' => 8101, 'status' => 'refunded'],
            ['order_id' => 5102, 'item_id' => 8102, 'status' => 'cancelled'],
        ];
        $rows = [];
        foreach ($cases as $c) {
            $rows[] = [
                'order_item_id' => (string) $c['item_id'],
                'order_id' => (string) $c['order_id'],
            ];
        }

        $this->runReconcile(
            $rows,
            static function (array $args) use ($cases): array {
                return array_column($cases, 'order_id');
            },
            static function ($order_id) use ($cases) {
                foreach ($cases as $c) {
                    if ((int) $order_id !== (int) $c['order_id']) {
                        continue;
                    }
                    return new class($c['order_id'], $c['status'], $c['item_id']) {
                        private $id;
                        private $status;
                        private $itemId;
                        public function __construct($id, $status, $itemId) {
                            $this->id = $id;
                            $this->status = $status;
                            $this->itemId = $itemId;
                        }
                        public function get_id() { return $this->id; }
                        public function get_type() { return 'shop_order'; }
                        public function get_status() { return $this->status; }
                        public function get_customer_id() { return 1; }
                        public function get_items() { return [$this->itemId => new \stdClass()]; }
                    };
                }
                return false;
            }
        );

        $this->assertNotContains(8101, $this->deletedItemIds);
        $this->assertNotContains(8102, $this->deletedItemIds);
    }
}
