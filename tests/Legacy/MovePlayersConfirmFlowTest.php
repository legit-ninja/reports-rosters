<?php
/**
 * Admin roster move confirm flow (reason codes, needs_confirm payload, empty targets).
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersConfirmFlowTest extends TestCase {

    private const TEST_VARIATION_ID = 881001;
    private const TEST_PARENT_ID = 881010;

    protected function setUp(): void {
        parent::setUp();
        global $intersoccer_test_post_status_map, $intersoccer_test_product_type_safe_callback, $wpdb;
        $intersoccer_test_post_status_map = [];
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn(null);

        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__) . '/stubs/move-players-product-type-stub.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
    }

    /**
     * @param array<string,mixed> $config
     */
    private function makeVariation(array $config) {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($config['id'] ?? self::TEST_VARIATION_ID);
        $variation->shouldReceive('get_parent_id')->andReturn($config['parent_id'] ?? self::TEST_PARENT_ID);
        $variation->shouldReceive('get_variation_attributes')->andReturn($config['attributes'] ?? []);
        $variation->shouldReceive('managing_stock')->andReturn($config['managing_stock'] ?? false);
        $variation->shouldReceive('get_stock_quantity')->andReturn($config['stock_quantity'] ?? 1);
        $variation->shouldReceive('get_meta')->with('capacity', true)->andReturn($config['capacity'] ?? '');
        $variation->shouldReceive('get_name')->andReturn($config['name'] ?? 'Test Variation');
        $variation->shouldReceive('get_price')->andReturn($config['price'] ?? '120');
        $variation->shouldReceive('is_purchasable')->andReturn($config['is_purchasable'] ?? true);
        $variation->shouldReceive('is_type')->andReturn(true);

        return $variation;
    }

    public function test_needs_confirm_payload_lists_reason_codes_and_messages(): void {
        $payload = intersoccer_roster_move_build_needs_confirm_payload(
            ['no_price', 'at_capacity'],
            ['capacity_active' => 39, 'capacity_max' => 39]
        );

        $this->assertTrue($payload['needs_confirm']);
        $this->assertSame(['no_price', 'at_capacity'], $payload['reason_codes']);
        $this->assertCount(2, $payload['confirm_messages']);
        $this->assertStringContainsString('39/39', $payload['confirm_messages'][1]);
    }

    public function test_no_price_warning_matches_master_is_purchasable_gate(): void {
        global $intersoccer_test_post_status_map;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'publish',
            self::TEST_PARENT_ID => 'publish',
        ];

        $variation = $this->makeVariation(['price' => '', 'is_purchasable' => false]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertFalse($assessment['block']);
        $this->assertContains('no_price', $assessment['warnings']);
        $this->assertTrue($assessment['needs_confirmation']);
    }

    public function test_out_of_stock_warning_not_hard_block(): void {
        global $intersoccer_test_post_status_map;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'publish',
            self::TEST_PARENT_ID => 'publish',
        ];

        $variation = $this->makeVariation([
            'managing_stock' => true,
            'stock_quantity' => 0,
        ]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertFalse($assessment['block']);
        $this->assertContains('out_of_stock', $assessment['warnings']);
    }

    public function test_at_capacity_warning_includes_counts_in_message(): void {
        global $intersoccer_test_post_status_map, $wpdb;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'publish',
            self::TEST_PARENT_ID => 'publish',
        ];
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('39');

        $variation = $this->makeVariation(['capacity' => '39']);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertContains('at_capacity', $assessment['warnings']);
        $message = intersoccer_roster_move_reason_message('at_capacity', $assessment['warning_context']);
        $this->assertStringContainsString('39/39', $message);
    }

    public function test_closed_for_sale_warning_when_roster_flag_set(): void {
        global $intersoccer_test_post_status_map, $wpdb;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'publish',
            self::TEST_PARENT_ID => 'publish',
        ];
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) {
            if (is_string($sql) && strpos($sql, 'event_completed') !== false) {
                return '1';
            }
            return '0';
        });

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');
        $this->assertContains('closed_for_sale', $assessment['warnings']);
    }

    public function test_merge_empty_sibling_variation_adds_zero_player_target(): void {
        global $intersoccer_test_wc_get_product_callback;
        $empty_child = Mockery::mock('WC_Product_Variation');
        $empty_child->shouldReceive('get_id')->andReturn(881002);
        $empty_child->shouldReceive('get_parent_id')->andReturn(self::TEST_PARENT_ID);
        $empty_child->shouldReceive('get_variation_attributes')->andReturn([]);
        $empty_child->shouldReceive('get_name')->andReturn('Tuesday Zumikon');
        $empty_child->shouldReceive('get_attribute')->andReturn('');
        $empty_child->shouldReceive('get_attributes')->andReturn([]);
        $empty_child->shouldReceive('managing_stock')->andReturn(false);
        $empty_child->shouldReceive('get_stock_quantity')->andReturn(1);
        $empty_child->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $empty_child->shouldReceive('get_price')->andReturn('120');
        $empty_child->shouldReceive('is_purchasable')->andReturn(true);

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_children')->andReturn([881002]);
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');

        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('0');
        global $wpdb;

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($parent, $empty_child) {
            if ((int) $id === self::TEST_PARENT_ID) {
                return $parent;
            }
            if ((int) $id === 881002) {
                return $empty_child;
            }
            return false;
        };

        $merged = intersoccer_roster_move_merge_empty_sibling_variation_targets(
            [],
            self::TEST_PARENT_ID,
            self::TEST_VARIATION_ID,
            'Course',
            0
        );

        $this->assertCount(1, $merged);
        $this->assertSame(881002, (int) $merged[0]->variation_id);
        $this->assertSame(0, (int) $merged[0]->current_players);
    }
}
