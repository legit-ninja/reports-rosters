<?php
/**
 * Synthetic reconcile memory guard (~5k eligible orders with roster rows, delete pass included).
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

class RosterReconcileMemoryBatchTest extends TestCase {

    private const ORDER_COUNT = 5000;

    /** @var RosterBuilder */
    private $builder;

    protected function setUp(): void {
        parent::setUp();

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $rows = [];
        for ($i = 1; $i <= self::ORDER_COUNT; $i++) {
            $rows[] = [
                'order_item_id' => (string) $i,
                'order_id' => (string) $i,
            ];
        }

        $wpdb = new class($rows) {
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

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($wpdb);

        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('deleteByOrderItemId')->andReturn(0);
        $rosterRepository->shouldReceive('syncOrderStatusForKeptRows')->andReturn(0);

        $this->builder = new RosterBuilder(
            $logger,
            $database,
            Mockery::mock(PlayerRepository::class),
            $rosterRepository,
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = [self::class, 'pagedOrderIds'];
        $intersoccer_test_wc_get_order_callback = [self::class, 'minimalOrder'];
    }

    protected function tearDown(): void {
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        parent::tearDown();
    }

    public static function pagedOrderIds(array $args): array {
        $limit = max(1, (int) ($args['limit'] ?? 200));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        if ($offset >= self::ORDER_COUNT) {
            return [];
        }
        $end = min(self::ORDER_COUNT, $offset + $limit);
        return range($offset + 1, $end);
    }

    public static function minimalOrder($order_id) {
        $id = (int) $order_id;
        return new class($id) {
            private $id;
            public function __construct(int $id) { $this->id = $id; }
            public function get_id() { return $this->id; }
            public function get_type() { return 'shop_order'; }
            public function get_status() { return 'completed'; }
            public function get_customer_id() { return 0; }
            public function get_items() {
                return [$this->id => new \stdClass()];
            }
        };
    }

    public function test_reconcile_peak_memory_stays_under_256mb_for_large_order_sets(): void {
        $peak_before = memory_get_peak_usage(true);
        $this->builder->reconcile(['delete_obsolete' => true, 'batch_size' => 200]);
        $peak_after = memory_get_peak_usage(true);
        $delta = $peak_after - $peak_before;

        fwrite(STDERR, sprintf(
            "\n[reconcile-memory] peak=%d bytes delta=%d bytes orders=%d\n",
            $peak_after,
            $delta,
            self::ORDER_COUNT
        ));

        $this->assertLessThan(256 * 1024 * 1024, $peak_after, 'Peak memory during reconcile exceeded 256M');
        $this->assertLessThan(64 * 1024 * 1024, $delta, 'Reconcile should not retain large per-order object graphs');
    }
}
