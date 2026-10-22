<?php
/**
 * Closed roster rows: backfill product_name from order line (dry-run default).
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
        self::$roster_rows = [
            ['id' => 1, 'order_item_id' => 501, 'order_id' => 601, 'product_name' => 'SYNTH Old Catalogue', 'event_completed' => 1],
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

    public function test_apply_updates_closed_english_line_idempotent_second_run(): void {
        self::$roster_rows = [
            ['id' => 2, 'order_item_id' => 502, 'order_id' => 602, 'product_name' => 'SYNTH Stale', 'event_completed' => 1],
        ];
        self::$order_items = [
            502 => ['order_item_name' => 'SYNTH Purchased Title'],
        ];

        $service = $this->makeService();
        $first = $service->run(['id_cursor' => 0, 'chunk' => 50, 'apply' => true]);
        $this->assertSame(1, $first['changed']);
        $this->assertSame('SYNTH Purchased Title', self::$roster_rows[0]['product_name']);

        $second = $service->run(['id_cursor' => 0, 'chunk' => 50, 'apply' => true]);
        $this->assertSame(0, $second['changed']);
        $this->assertSame(0, $second['would_change']);
    }

    public function test_apply_updates_closed_french_line_as_booked(): void {
        self::$roster_rows = [
            ['id' => 3, 'order_item_id' => 503, 'order_id' => 603, 'product_name' => 'SYNTH Stale FR', 'event_completed' => 1],
        ];
        self::$order_items = [
            503 => ['order_item_name' => 'SYNTH Titre FR'],
        ];

        $summary = $this->makeService()->run(['apply' => true]);
        $this->assertSame(1, $summary['changed']);
        $this->assertSame('SYNTH Titre FR', self::$roster_rows[0]['product_name']);
    }

    public function test_apply_updates_closed_german_line_as_booked(): void {
        self::$roster_rows = [
            ['id' => 4, 'order_item_id' => 504, 'order_id' => 604, 'product_name' => 'SYNTH Stale DE', 'event_completed' => 1],
        ];
        self::$order_items = [
            504 => ['order_item_name' => 'SYNTH Titel DE'],
        ];

        $summary = $this->makeService()->run(['apply' => true]);
        $this->assertSame(1, $summary['changed']);
        $this->assertSame('SYNTH Titel DE', self::$roster_rows[0]['product_name']);
    }
}
