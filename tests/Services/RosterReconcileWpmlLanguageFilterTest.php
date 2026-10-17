<?php
/**
 * Repro (failing on purpose): full roster reconcile deletes rows for valid orders
 * that a WPML language-filtered wc_get_orders() did not return.
 *
 * Background: with WooCommerce orders stored as posts and WPML set to "display as
 * translated" for shop_order, wc_get_orders() without suppress_filters goes through
 * WP_Query and WPML_Query_Filter::posts_where_filter, so it only returns orders in the
 * current language (plus the default-language fallback). RosterBuilder::reconcile()
 * builds its "active order items" set from that result (roster-builder.php:339-369) and
 * deletes every roster row whose order_item_id is not in it (roster-builder.php:445-466).
 *
 * The fake order store below simulates that filter: unless the query passes
 * suppress_filters => true, it only returns orders whose language is the current
 * language or the default language (en). It also respects the 'status' argument the way
 * WooCommerce does, so refunded and cancelled orders are not returned by the default
 * reconcile status list.
 *
 * All ids and data are synthetic; no customer data.
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

class RosterReconcileWpmlLanguageFilterTest extends TestCase {

    /** Synthetic order store: order_id => [lang, status, item_id]. */
    private const ORDERS = [
        1001 => ['lang' => 'en', 'status' => 'completed', 'item' => 9001],
        1002 => ['lang' => 'fr', 'status' => 'completed', 'item' => 9002],
        1003 => ['lang' => 'de', 'status' => 'completed', 'item' => 9003],
        1004 => ['lang' => 'en', 'status' => 'refunded',  'item' => 9004],
        1005 => ['lang' => 'en', 'status' => 'cancelled', 'item' => 9005],
        1006 => ['lang' => 'fr', 'status' => 'refunded',  'item' => 9006],
        1007 => ['lang' => 'de', 'status' => 'cancelled', 'item' => 9007],
        1008 => ['lang' => 'fr', 'status' => 'processing', 'item' => 9008],
        1009 => ['lang' => 'de', 'status' => 'on-hold',   'item' => 9009],
    ];

    /** Roster row whose stored order no longer exists (confirmed missing on lookup). */
    private const ORPHAN_ITEM = 9999;

    private const ORPHAN_ORDER_ID = 19999;

    /** @var string */
    private static $currentLang = 'en';

    /** @var array<int,array> */
    private static $queryArgs = [];

    /** @var int[] */
    private $deletedItemIds = [];

    /** @var RosterBuilder */
    private $builder;

    protected function setUp(): void {
        parent::setUp();

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);

        $rows = [];
        foreach (self::ORDERS as $order_id => $o) {
            $rows[] = [
                'order_item_id' => (string) $o['item'],
                'order_id' => (string) $order_id,
            ];
        }
        $rows[] = [
            'order_item_id' => (string) self::ORPHAN_ITEM,
            'order_id' => (string) self::ORPHAN_ORDER_ID,
        ];
        $wpdb = new class($rows) {
            public $prefix = 'wp_';
            /** @var array<int,array<string,string>> */
            private $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function get_results($query, $output = OBJECT) {
                if ($output === ARRAY_A) {
                    return $this->rows;
                }
                return $this->rows;
            }
            public function get_var($query) { return 1; }
            public function prepare($query, ...$args) { return $query; }
        };

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($wpdb);

        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('deleteByOrderItemId')->andReturnUsing(function (int $order_item_id) {
            $this->deletedItemIds[] = $order_item_id;
            return 1;
        });

        $this->builder = new RosterBuilder(
            $logger,
            $database,
            Mockery::mock(PlayerRepository::class),
            $rosterRepository,
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        self::$currentLang = 'en';
        self::$queryArgs = [];
        $this->deletedItemIds = [];

        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = [self::class, 'fakeWpmlFilteredWcGetOrders'];
        $intersoccer_test_wc_get_order_callback = [self::class, 'fakeWcGetOrder'];
    }

    protected function tearDown(): void {
        global $intersoccer_test_wc_get_orders_callback, $intersoccer_test_wc_get_order_callback;
        $intersoccer_test_wc_get_orders_callback = null;
        $intersoccer_test_wc_get_order_callback = null;
        parent::tearDown();
    }

    public static function fakeWcGetOrder($order_id) {
        $order_id = (int) $order_id;
        if (!isset(self::ORDERS[$order_id])) {
            return false;
        }
        $o = self::ORDERS[$order_id];
        return self::makeOrder($order_id, $o['status'], $o['item']);
    }

    /**
     * Simulates wc_get_orders() on posts storage under WPML "display as translated".
     */
    public static function fakeWpmlFilteredWcGetOrders(array $args): array {
        self::$queryArgs[] = $args;

        $statuses = null;
        if (isset($args['status'])) {
            $statuses = array_map(static function ($s) {
                return preg_replace('/^wc-/', '', (string) $s);
            }, (array) $args['status']);
        }
        $languageFiltered = empty($args['suppress_filters']);
        $visibleLangs = array_unique([self::$currentLang, 'en']);

        $return_ids = isset($args['return']) && $args['return'] === 'ids';
        $out = [];
        foreach (self::ORDERS as $id => $o) {
            if ($statuses !== null && !in_array($o['status'], $statuses, true)) {
                continue;
            }
            if ($languageFiltered && !in_array($o['lang'], $visibleLangs, true)) {
                continue;
            }
            $out[] = $return_ids ? $id : self::makeOrder($id, $o['status'], $o['item']);
        }

        $limit = isset($args['limit']) ? (int) $args['limit'] : -1;
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;
        if ($limit > 0) {
            return array_slice($out, $offset, $limit);
        }
        if ($offset > 0) {
            return array_slice($out, $offset);
        }

        return $out;
    }

    private static function makeOrder(int $id, string $status, int $itemId) {
        // customer_id 0 makes reconcile skip the (heavy) per-item rebuild for this order,
        // after it has already been counted in $active_order_item_ids, so only the
        // order-collection and delete decisions are under test.
        return new class($id, $status, $itemId) {
            private $id; private $status; private $itemId;
            public function __construct($id, $status, $itemId) { $this->id = $id; $this->status = $status; $this->itemId = $itemId; }
            public function get_id() { return $this->id; }
            public function get_status() { return $this->status; }
            public function get_customer_id() { return 0; }
            public function get_items() { return [$this->itemId => new \stdClass()]; }
        };
    }

    private function runFullReconcile(string $lang): array {
        self::$currentLang = $lang;
        return $this->builder->reconcile(['delete_obsolete' => true]);
    }

    public function languageContexts(): array {
        return [
            'admin/wp-cli in en' => ['en'],
            'admin in fr' => ['fr'],
            'admin in de' => ['de'],
        ];
    }

    /**
     * Completed / processing / on-hold orders in a non-current language are valid orders;
     * their roster rows must survive a full reconcile.
     *
     * @dataProvider languageContexts
     */
    public function test_reconcile_keeps_rows_of_active_orders_in_other_languages(string $lang): void {
        $this->runFullReconcile($lang);

        $activeItems = [];
        foreach (self::ORDERS as $id => $o) {
            if (in_array($o['status'], ['completed', 'processing', 'on-hold', 'pending'], true)) {
                $activeItems[$id . ' (' . $o['lang'] . ', ' . $o['status'] . ')'] = $o['item'];
            }
        }
        $wronglyDeleted = array_keys(array_intersect($activeItems, $this->deletedItemIds));

        $this->assertSame(
            [],
            $wronglyDeleted,
            "Reconcile in '{$lang}' context deleted roster rows of valid orders not returned by the language-filtered order query"
        );
    }

    /**
     * Full reconcile must not depend on the admin's current language: the set of rows
     * it deletes must be identical in every language context.
     */
    public function test_reconcile_deletions_do_not_depend_on_current_language(): void {
        $byLang = [];
        foreach (['en', 'fr', 'de'] as $lang) {
            $this->deletedItemIds = [];
            $this->runFullReconcile($lang);
            $ids = $this->deletedItemIds;
            sort($ids);
            $byLang[$lang] = $ids;
        }

        $this->assertSame($byLang['en'], $byLang['fr'], 'en vs fr deleted item ids differ');
        $this->assertSame($byLang['en'], $byLang['de'], 'en vs de deleted item ids differ');
    }

    /**
     * Per PR #102 (RosterRefundService), cancelled/refunded orders keep their roster rows,
     * flagged via order_status, hidden from listings/exports and reactivated if the order
     * returns to completed/processing. Reconcile deleting them breaks reinstatement.
     */
    public function test_reconcile_keeps_rows_of_refunded_orders(): void {
        $this->runFullReconcile('en');

        $this->assertNotContains(9004, $this->deletedItemIds, 'Refunded EN order 1004 rows were deleted');
        $this->assertNotContains(9006, $this->deletedItemIds, 'Refunded FR order 1006 rows were deleted');
    }

    public function test_reconcile_keeps_rows_of_cancelled_orders(): void {
        $this->runFullReconcile('en');

        $this->assertNotContains(9005, $this->deletedItemIds, 'Cancelled EN order 1005 rows were deleted');
        $this->assertNotContains(9007, $this->deletedItemIds, 'Cancelled DE order 1007 rows were deleted');
    }

    /**
     * Control (expected to PASS today and after a fix): a row whose line item belongs to
     * no order at all is still removed. Guards against "fixing" by disabling deletes.
     *
     * @dataProvider languageContexts
     */
    public function test_reconcile_still_deletes_true_orphan_rows(string $lang): void {
        $this->runFullReconcile($lang);

        $this->assertContains(self::ORPHAN_ITEM, $this->deletedItemIds);
    }

    public function test_reconcile_uses_suppress_filters_and_paged_batches(): void {
        self::$queryArgs = [];
        $this->builder->reconcile(['delete_obsolete' => true, 'batch_size' => 2]);

        $this->assertGreaterThan(1, count(self::$queryArgs), 'Expected multiple paged wc_get_orders calls');
        foreach (self::$queryArgs as $args) {
            $this->assertNotEmpty($args['suppress_filters']);
            $this->assertSame('ids', $args['return'] ?? null);
            $this->assertSame(2, (int) ($args['limit'] ?? 0));
        }
    }
}
