<?php
/**
 * Closed roster rows: backfill product_name to English catalogue title (dry-run default).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class ClosedRosterProductNameBackfillTest extends TestCase {

    /** @var array<int,array<string,mixed>> */
    private static $roster_rows = [];

    /** @var array<int,array<string,mixed>> */
    private static $order_items = [];

    protected function setUp(): void {
        parent::setUp();
        self::$roster_rows = [];
        self::$order_items = [];
        $path = dirname(__DIR__, 2) . '/includes/roster-closed-line-product-name-backfill.php';
        if (file_exists($path)) {
            require_once $path;
        }
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_wc_get_product_callback'], $GLOBALS['intersoccer_test_apply_filters_callback']);
        parent::tearDown();
    }

    private function stub_english_product(int $booked_id, string $en_title, int $en_id): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) use ($booked_id, $en_id) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id' && isset($args[0]) && $args[0] === 'product' && (int) $value === $booked_id) {
                return $en_id;
            }
            if ($hook === 'wpml_active_languages') {
                return ['en' => []];
            }
            return $value;
        };
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($booked_id, $en_id, $en_title) {
            $id = (int) $id;
            if ($id === $booked_id || $id === $en_id) {
                return new class ($en_title, $id) {
                    private $title;
                    private $id;

                    public function __construct($title, $id) {
                        $this->title = $title;
                        $this->id = $id;
                    }

                    public function get_name() {
                        return $this->title;
                    }

                    public function get_parent_id() {
                        return 0;
                    }

                    public function get_id() {
                        return $this->id;
                    }
                };
            }
            return false;
        };
    }

    private function makeService(): \Intersoccer_Roster_Closed_Line_Product_Name_Backfill_Service {
        return new \Intersoccer_Roster_Closed_Line_Product_Name_Backfill_Service(
            static function (int $cursor, int $chunk) {
                $rows = array_values(array_filter(
                    ClosedRosterProductNameBackfillTest::$roster_rows,
                    static function ($r) use ($cursor) {
                        return (int) $r['id'] > $cursor;
                    }
                ));
                return array_slice($rows, 0, $chunk);
            },
            static function (int $order_item_id) {
                return ClosedRosterProductNameBackfillTest::$order_items[$order_item_id] ?? null;
            },
            static function (int $id, string $name) {
                foreach (ClosedRosterProductNameBackfillTest::$roster_rows as &$row) {
                    if ((int) $row['id'] === $id) {
                        $row['product_name'] = $name;
                    }
                }
            }
        );
    }

    public function test_dry_run_counts_would_change_without_writes(): void {
        $this->stub_english_product(5010, 'SYNTH EN Target', 5010);
        self::$roster_rows = [
            ['id' => 1, 'order_item_id' => 501, 'order_id' => 601, 'product_id' => 5010, 'product_name' => 'SYNTH Old Catalogue', 'event_completed' => 1],
        ];
        self::$order_items = [
            501 => ['order_item_name' => 'SYNTH Line Name 2025'],
        ];

        $summary = $this->makeService()->run(['id_cursor' => 0, 'chunk' => 50, 'apply' => false]);
        $this->assertSame(1, $summary['rows_scanned']);
        $this->assertSame(1, $summary['would_change']);
        $this->assertSame(0, $summary['changed']);
        $this->assertSame('SYNTH Old Catalogue', self::$roster_rows[0]['product_name']);
    }

    public function test_apply_updates_closed_row_to_english_catalogue_idempotent_second_run(): void {
        $this->stub_english_product(5020, 'SYNTH EN Purchased Title', 5020);
        self::$roster_rows = [
            ['id' => 2, 'order_item_id' => 502, 'order_id' => 602, 'product_id' => 5020, 'product_name' => 'SYNTH Stale', 'event_completed' => 1],
        ];
        self::$order_items = [
            502 => ['order_item_name' => 'SYNTH Purchased Title FR'],
        ];

        $service = $this->makeService();
        $first = $service->run(['id_cursor' => 0, 'chunk' => 50, 'apply' => true]);
        $this->assertSame(1, $first['changed']);
        $this->assertSame('SYNTH EN Purchased Title', self::$roster_rows[0]['product_name']);

        $second = $service->run(['id_cursor' => 0, 'chunk' => 50, 'apply' => true]);
        $this->assertSame(0, $second['changed']);
        $this->assertSame(0, $second['would_change']);
    }

    public function test_apply_updates_french_line_to_english_catalogue(): void {
        $this->stub_english_product(5030, 'SYNTH EN From FR Twin', 5003);
        self::$roster_rows = [
            ['id' => 3, 'order_item_id' => 503, 'order_id' => 603, 'product_id' => 5030, 'product_name' => 'SYNTH Stale FR', 'event_completed' => 1],
        ];
        self::$order_items = [
            503 => ['order_item_name' => 'SYNTH Titre FR'],
        ];

        $summary = $this->makeService()->run(['apply' => true]);
        $this->assertSame(1, $summary['changed']);
        $this->assertSame('SYNTH EN From FR Twin', self::$roster_rows[0]['product_name']);
    }

    public function test_apply_without_catalogue_uses_order_line_last_resort(): void {
        unset($GLOBALS['intersoccer_test_wc_get_product_callback'], $GLOBALS['intersoccer_test_apply_filters_callback']);
        self::$roster_rows = [
            ['id' => 4, 'order_item_id' => 504, 'order_id' => 604, 'product_id' => 0, 'product_name' => 'SYNTH Stale DE', 'event_completed' => 1],
        ];
        self::$order_items = [
            504 => ['order_item_name' => 'SYNTH Titel DE'],
        ];

        $summary = $this->makeService()->run(['apply' => true]);
        $this->assertSame(1, $summary['changed']);
        $this->assertSame('SYNTH Titel DE', self::$roster_rows[0]['product_name']);
    }
}
