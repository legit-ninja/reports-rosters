<?php
/**
 * Reconcile and pre-reconcile diagnostics page orders and free caches between pages.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\ReportsRostersDiagnosticsService;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterReconcilePaging155Test extends TestCase {

    /** @var array<int,array<string,int>> */
    private $orderQueries = [];

    /** @var array<int,array{0:int,1:int}> */
    private $rowPages = [];

    protected function tearDown(): void {
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        unset($GLOBALS['intersoccer_test_wp_cache_flush_count']);
        parent::tearDown();
    }

    public function test_reconcile_pages_orders_and_rows_and_flushes_between_pages(): void {
        $this->orderQueries = [];
        $this->rowPages = [];
        $GLOBALS['intersoccer_test_wp_cache_flush_count'] = 0;

        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = [
                'order_item_id' => (string) $i,
                'order_id' => (string) $i,
            ];
        }

        $rowPages = &$this->rowPages;
        $wpdb = new class($rows, $rowPages) {
            public $prefix = 'wp_';
            /** @var array<int,array<string,string>> */
            private $rows;
            /** @var array<int,array{0:int,1:int}> */
            private $pages;
            public function __construct(array $rows, array &$pages) {
                $this->rows = $rows;
                $this->pages = &$pages;
            }
            public function get_results($query, $output = OBJECT) {
                $limit = count($this->rows);
                $offset = 0;
                if (preg_match('/LIMIT (\d+) OFFSET (\d+)/', (string) $query, $match)) {
                    $limit = (int) $match[1];
                    $offset = (int) $match[2];
                }
                $this->pages[] = [$limit, $offset];
                $slice = array_slice($this->rows, $offset, $limit);
                return $output === ARRAY_A ? $slice : $slice;
            }
            public function get_var($query) { return 0; }
            public function prepare($query, ...$args) { return $query; }
        };

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($wpdb);
        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('deleteByOrderItemId')->andReturn(0);
        $rosterRepository->shouldReceive('syncOrderStatusForKeptRows')->andReturn(0);
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $builder = new RosterBuilder(
            $logger,
            $database,
            Mockery::mock(PlayerRepository::class),
            $rosterRepository,
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        $queries = &$this->orderQueries;
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function (array $args) use (&$queries) {
            $queries[] = [
                'limit' => (int) ($args['limit'] ?? 0),
                'offset' => (int) ($args['offset'] ?? 0),
            ];
            $limit = max(1, (int) ($args['limit'] ?? 1));
            $offset = max(0, (int) ($args['offset'] ?? 0));
            if ($offset >= 5) {
                return [];
            }
            $end = min(5, $offset + $limit);
            return range($offset + 1, $end);
        };
        $intersoccer_test_wc_get_order_callback = static function ($order_id) {
            $id = (int) $order_id;
            return new class($id) {
                private $id;
                public function __construct(int $id) { $this->id = $id; }
                public function get_id() { return $this->id; }
                public function get_type() { return 'shop_order'; }
                public function get_status() { return 'completed'; }
                public function get_customer_id() { return 0; }
                public function get_items() { return [$this->id => new \stdClass()]; }
            };
        };

        $builder->reconcile(['delete_obsolete' => true, 'batch_size' => 2]);

        $limits = array_column($this->orderQueries, 'limit');
        $offsets = array_column($this->orderQueries, 'offset');
        $this->assertNotEmpty($limits);
        $this->assertSame([2], array_values(array_unique($limits)));
        $this->assertContains(0, $offsets);
        $this->assertContains(2, $offsets);

        $rowOffsets = array_map(static function (array $page) {
            return $page[1];
        }, $this->rowPages);
        $rowLimits = array_map(static function (array $page) {
            return $page[0];
        }, $this->rowPages);
        $this->assertContains(2, $rowLimits);
        $this->assertContains(0, $rowOffsets);
        $this->assertContains(2, $rowOffsets);
        $this->assertGreaterThanOrEqual(2, (int) $GLOBALS['intersoccer_test_wp_cache_flush_count']);
    }

    public function test_diagnostics_date_range_is_paged_and_does_not_load_the_whole_year(): void {
        $this->orderQueries = [];
        $GLOBALS['intersoccer_test_wp_cache_flush_count'] = 0;
        $GLOBALS['intersoccer_test_wc_get_order_call_count'] = 0;

        $queries = &$this->orderQueries;
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = static function (array $args) use (&$queries) {
            $queries[] = $args;
            $limit = max(1, (int) ($args['limit'] ?? 1));
            $offset = max(0, (int) ($args['offset'] ?? 0));
            if ($limit < 0 || $offset >= 201) {
                return [];
            }
            $end = min(201, $offset + $limit);
            $ids = [];
            for ($id = $offset + 1; $id <= $end; $id++) {
                $ids[] = $id;
            }
            return $ids;
        };
        $intersoccer_test_wc_get_order_callback = static function ($order_id) {
            return new class((int) $order_id) {
                private $id;
                public function __construct(int $id) { $this->id = $id; }
                public function get_id() { return $this->id; }
                public function get_status() { return 'completed'; }
                public function get_date_created() { return null; }
                public function get_customer_id() { return 1; }
            };
        };

        $service = new ReportsRostersDiagnosticsService();
        $service->runDiagnostics([
            'year' => 2026,
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-07',
            'limit' => 1,
            'offset' => 0,
        ]);

        $this->assertNotEmpty($this->orderQueries);
        $offsets = [];
        foreach ($this->orderQueries as $args) {
            $limit = (int) ($args['limit'] ?? 0);
            $this->assertGreaterThan(0, $limit);
            $this->assertLessThanOrEqual(200, $limit);
            $this->assertNotSame(-1, $limit);
            $date_created = (string) ($args['date_created'] ?? '');
            $this->assertStringContainsString('2026-03-01', $date_created);
            $this->assertStringContainsString('2026-03-07', $date_created);
            $this->assertStringNotContainsString('2026-01-01...2026-12-31', $date_created);
            $offsets[] = (int) ($args['offset'] ?? 0);
        }
        $this->assertContains(0, $offsets);
        $this->assertContains(200, $offsets);
        $this->assertGreaterThanOrEqual(1, (int) $GLOBALS['intersoccer_test_wp_cache_flush_count']);
        $this->assertLessThan(500, (int) $GLOBALS['intersoccer_test_wc_get_order_call_count']);
        unset($GLOBALS['intersoccer_test_wc_get_order_call_count']);
    }
}
