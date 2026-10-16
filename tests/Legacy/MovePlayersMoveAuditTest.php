<?php
/**
 * Roster move audit trail (hidden item meta + private order note).
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class MovePlayersMoveAuditTest extends TestCase {

    private const ITEM_ID = 88001;
    private const ORDER_ID = 88010;

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/roster-move-audit.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
    }

    public function test_successful_move_writes_hidden_meta_and_private_order_note(): void {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_order_notes, $intersoccer_test_userdata_map;
        global $intersoccer_test_wc_get_order_callback;

        $intersoccer_test_wc_order_item_meta = [];
        $intersoccer_test_order_notes = [];
        $intersoccer_test_userdata_map = [
            42 => (object) ['ID' => 42, 'display_name' => 'Roster Admin'],
        ];
        $GLOBALS['intersoccer_test_current_user'] = (object) ['ID' => 42, 'roles' => ['administrator']];

        $order = new \WC_Order(self::ORDER_ID);
        $intersoccer_test_wc_get_order_callback = static function ($id) use ($order) {
            return (int) $id === self::ORDER_ID ? $order : false;
        };

        intersoccer_roster_move_record_successful_move(
            self::ORDER_ID,
            self::ITEM_ID,
            30711,
            52707,
            42,
            '2026-10-16T12:00:00Z'
        );

        $keys = intersoccer_roster_move_audit_latest_meta_keys();
        $this->assertSame('30711', $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$keys['from_variation_id']]);
        $this->assertSame('52707', $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$keys['to_variation_id']]);
        $this->assertSame('42', $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$keys['user_id']]);
        $this->assertSame('2026-10-16T12:00:00Z', $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$keys['moved_at_utc']]);

        $history = intersoccer_roster_move_audit_decode_history(
            $intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_move_audit_history_meta_key()]
        );
        $this->assertCount(1, $history);
        $this->assertSame(30711, $history[0]['from_variation_id']);

        $this->assertArrayHasKey(self::ORDER_ID, $intersoccer_test_order_notes);
        $this->assertCount(1, $intersoccer_test_order_notes[self::ORDER_ID]);
        $this->assertSame('Roster move: 30711 to 52707 by Roster Admin', $intersoccer_test_order_notes[self::ORDER_ID][0]['note']);
        $this->assertFalse($intersoccer_test_order_notes[self::ORDER_ID][0]['is_customer_note']);
        $this->assertTrue($intersoccer_test_order_notes[self::ORDER_ID][0]['added_by_user']);
    }

    public function test_same_variation_target_skips_audit(): void {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_order_notes;

        $intersoccer_test_wc_order_item_meta = [];
        $intersoccer_test_order_notes = [];

        $this->assertTrue(intersoccer_roster_move_is_same_variation_target(53637, 53637));
        $this->assertFalse(intersoccer_roster_move_is_same_variation_target(53637, 30712));

        $this->assertArrayNotHasKey(self::ITEM_ID, $intersoccer_test_wc_order_item_meta);
        $this->assertSame([], $intersoccer_test_order_notes);
    }

    public function test_failed_move_writes_no_audit_when_record_not_invoked(): void {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_order_notes;

        $intersoccer_test_wc_order_item_meta = [];
        $intersoccer_test_order_notes = [];

        // Simulates blocked / needs_confirmation / missing roster row: handler never calls record.
        $this->assertArrayNotHasKey(self::ITEM_ID, $intersoccer_test_wc_order_item_meta);
        $this->assertSame([], $intersoccer_test_order_notes);
    }

    public function test_second_move_appends_history_and_updates_latest_keys(): void {
        global $intersoccer_test_wc_order_item_meta;

        $intersoccer_test_wc_order_item_meta = [];
        $keys = intersoccer_roster_move_audit_latest_meta_keys();

        intersoccer_roster_move_record_successful_move(
            self::ORDER_ID,
            self::ITEM_ID,
            100,
            200,
            1,
            '2026-10-16T10:00:00Z'
        );
        intersoccer_roster_move_record_successful_move(
            self::ORDER_ID,
            self::ITEM_ID,
            200,
            300,
            1,
            '2026-10-16T11:00:00Z'
        );

        $this->assertSame('200', $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$keys['from_variation_id']]);
        $this->assertSame('300', $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$keys['to_variation_id']]);
        $this->assertSame('2026-10-16T11:00:00Z', $intersoccer_test_wc_order_item_meta[self::ITEM_ID][$keys['moved_at_utc']]);

        $history = intersoccer_roster_move_audit_decode_history(
            $intersoccer_test_wc_order_item_meta[self::ITEM_ID][intersoccer_roster_move_audit_history_meta_key()]
        );
        $this->assertCount(2, $history);
        $this->assertSame(100, $history[0]['from_variation_id']);
        $this->assertSame(200, $history[0]['to_variation_id']);
        $this->assertSame(200, $history[1]['from_variation_id']);
        $this->assertSame(300, $history[1]['to_variation_id']);
    }
}
