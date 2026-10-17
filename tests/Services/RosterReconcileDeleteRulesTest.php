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
use InterSoccer\ReportsRosters\Services\RosterRefundService;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use Mockery;

class RosterReconcileDeleteRulesTest extends TestCase {

    /** @var int[] */
    private $deletedItemIds = [];

    /** @var int[] */
    private $statusSyncOrderIds = [];

    /** @var RosterBuilder */
    private $builder;

    /** @var PlayerMatcher|\Mockery\MockInterface */
    private $playerMatcher;

    protected function setUp(): void {
        parent::setUp();

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $this->deletedItemIds = [];
        $this->statusSyncOrderIds = [];

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturnUsing(function () {
            return $this->makeWpdb($this->currentRows);
        });

        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('deleteByOrderItemId')->andReturnUsing(function (int $order_item_id) {
            $this->deletedItemIds[] = $order_item_id;
            return 1;
        });
        $rosterRepository->shouldReceive('syncOrderStatusForKeptRows')->andReturnUsing(function (int $order_id, string $status) {
            $this->statusSyncOrderIds[] = $order_id;
            return $this->applySyncOrderStatusForKeptRows($order_id, $status);
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

    /**
     * @return array<string,mixed>
     */
    private function runReconcile(array $rows, callable $ordersCallback, callable $orderCallback): array {
        $this->currentRows = $rows;
        $this->deletedItemIds = [];
        $this->statusSyncOrderIds = [];

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = $ordersCallback;
        $intersoccer_test_wc_get_order_callback = $orderCallback;

        return $this->builder->reconcile(['delete_obsolete' => true, 'batch_size' => 50]);
    }

    private function applySyncOrderStatusForKeptRows(int $order_id, string $status): int {
        $changed = 0;
        foreach ($this->currentRows as $index => $row) {
            if ((int) ($row['order_id'] ?? 0) !== $order_id) {
                continue;
            }
            $row_status = isset($row['order_status']) ? (string) $row['order_status'] : null;
            if ($row_status !== null && $row_status !== '' && RosterRefundService::isFlaggedRowStatus($row_status)) {
                continue;
            }
            if (($row['order_status'] ?? null) === $status) {
                continue;
            }
            $this->currentRows[$index]['order_status'] = $status;
            $changed++;
        }
        return $changed;
    }

    private function rowStatus(int $itemId): ?string {
        foreach ($this->currentRows as $row) {
            if ((int) ($row['order_item_id'] ?? 0) === $itemId) {
                return isset($row['order_status']) ? (string) $row['order_status'] : null;
            }
        }
        return null;
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

    public function test_completed_row_on_refunded_order_syncs_status_and_is_not_deleted(): void {
        $orderId = 5201;
        $itemId = 8201;
        $results = $this->runReconcile(
            [
                [
                    'order_item_id' => (string) $itemId,
                    'order_id' => (string) $orderId,
                    'order_status' => 'completed',
                ],
            ],
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
                    public function get_status() { return 'refunded'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() { return [$this->itemId => new \stdClass()]; }
                };
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
        $this->assertSame(Roster::STATUS_REFUNDED, $this->rowStatus($itemId));
        $this->assertSame(1, $results['status_synced']);
    }

    public function test_completed_row_on_cancelled_order_syncs_status_and_is_not_deleted(): void {
        $orderId = 5202;
        $itemId = 8202;
        $results = $this->runReconcile(
            [
                [
                    'order_item_id' => (string) $itemId,
                    'order_id' => (string) $orderId,
                    'order_status' => 'completed',
                ],
            ],
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
                    public function get_status() { return 'cancelled'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() { return [$this->itemId => new \stdClass()]; }
                };
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
        $this->assertSame(Roster::STATUS_CANCELLED, $this->rowStatus($itemId));
        $this->assertSame(1, $results['status_synced']);
    }

    public function test_item_level_refunded_row_on_completed_order_unchanged_without_status_sync(): void {
        $orderId = 5203;
        $itemId = 8203;
        $this->runReconcile(
            [
                [
                    'order_item_id' => (string) $itemId,
                    'order_id' => (string) $orderId,
                    'order_status' => Roster::STATUS_REFUNDED,
                ],
            ],
            static function (array $args) use ($orderId): array {
                return [$orderId];
            },
            static function ($order_id) use ($orderId, $itemId) {
                if ((int) $order_id !== $orderId) {
                    return false;
                }
                return self::makeEligibleOrder($orderId, $itemId, [$itemId]);
            }
        );

        $this->assertNotContains($itemId, $this->deletedItemIds);
        $this->assertSame(Roster::STATUS_REFUNDED, $this->rowStatus($itemId));
        $this->assertSame([], $this->statusSyncOrderIds);
    }

    public function test_flagged_sibling_unchanged_while_non_flagged_row_syncs_on_refunded_order(): void {
        $orderId = 5204;
        $flaggedItemId = 8204;
        $openItemId = 8205;
        $this->runReconcile(
            [
                [
                    'order_item_id' => (string) $flaggedItemId,
                    'order_id' => (string) $orderId,
                    'order_status' => Roster::STATUS_CANCELLED,
                ],
                [
                    'order_item_id' => (string) $openItemId,
                    'order_id' => (string) $orderId,
                    'order_status' => 'completed',
                ],
            ],
            static function (array $args): array {
                return [];
            },
            static function ($order_id) use ($orderId, $flaggedItemId, $openItemId) {
                if ((int) $order_id !== $orderId) {
                    return false;
                }
                return new class($orderId, $flaggedItemId, $openItemId) {
                    private $id;
                    /** @var int[] */
                    private $itemIds;
                    public function __construct($id, ...$itemIds) {
                        $this->id = $id;
                        $this->itemIds = $itemIds;
                    }
                    public function get_id() { return $this->id; }
                    public function get_type() { return 'shop_order'; }
                    public function get_status() { return 'refunded'; }
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
        );

        $this->assertNotContains($flaggedItemId, $this->deletedItemIds);
        $this->assertNotContains($openItemId, $this->deletedItemIds);
        $this->assertSame(Roster::STATUS_CANCELLED, $this->rowStatus($flaggedItemId));
        $this->assertSame(Roster::STATUS_REFUNDED, $this->rowStatus($openItemId));
        $this->assertSame([$orderId], $this->statusSyncOrderIds);
    }

    public function test_second_reconcile_after_status_sync_is_idempotent(): void {
        $orderId = 5205;
        $itemId = 8206;
        $first = $this->runReconcile(
            [
                [
                    'order_item_id' => (string) $itemId,
                    'order_id' => (string) $orderId,
                    'order_status' => 'completed',
                ],
            ],
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
                    public function get_status() { return 'refunded'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() { return [$this->itemId => new \stdClass()]; }
                };
            }
        );

        $this->assertSame(1, $first['status_synced']);
        $statusAfterFirst = $this->rowStatus($itemId);

        $second = $this->runReconcile(
            $this->currentRows,
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
                    public function get_status() { return 'refunded'; }
                    public function get_customer_id() { return 0; }
                    public function get_items() { return [$this->itemId => new \stdClass()]; }
                };
            }
        );

        $this->assertSame(0, $second['status_synced']);
        $this->assertSame($statusAfterFirst, $this->rowStatus($itemId));
        $this->assertNotContains($itemId, $this->deletedItemIds);
    }
}
