<?php
/**
 * Synthetic reconcile memory guard (~5k eligible orders, batched IDs only).
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

        $wpdb = new class {
            public $prefix = 'wp_';
            public function get_col($query) { return []; }
            public function get_var($query) { return 0; }
            public function prepare($query, ...$args) { return $query; }
        };

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($wpdb);

        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('deleteByOrderItemId')->andReturn(0);

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
        $ids = range($offset + 1, $end);
        return $ids;
    }

    public static function minimalOrder($order_id) {
        return new class((int) $order_id) {
            private $id;
            public function __construct(int $id) { $this->id = $id; }
            public function get_id() { return $this->id; }
            public function get_type() { return 'shop_order'; }
            public function get_status() { return 'completed'; }
            public function get_customer_id() { return 0; }
            public function get_items() { return []; }
        };
    }

    public function test_reconcile_peak_memory_stays_under_256mb_for_large_order_sets(): void {
        $peak_before = memory_get_peak_usage(true);
        $this->builder->reconcile(['delete_obsolete' => false, 'batch_size' => 200]);
        $peak_after = memory_get_peak_usage(true);
        $delta = $peak_after - $peak_before;

        $this->assertLessThan(256 * 1024 * 1024, $peak_after, 'Peak memory during reconcile exceeded 256M');
        $this->assertLessThan(64 * 1024 * 1024, $delta, 'Reconcile should not retain large per-order object graphs');
    }
}
