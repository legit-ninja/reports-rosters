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

    use MovePlayersTestIsolationTrait;

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

        $parent_product = Mockery::mock('WC_Product');
        $parent_product->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $parent_product->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent_product->shouldReceive('get_name')->andReturn('Parent product');
        $parent_product->shouldReceive('get_attribute')->andReturn('');
        global $intersoccer_test_wc_get_product_callback;
        $intersoccer_test_wc_get_product_callback = static function ($id) use ($parent_product) {
            return (int) $id === self::TEST_PARENT_ID ? $parent_product : false;
        };

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
        $variation->shouldReceive('get_stock_status')->andReturn($config['stock_status'] ?? 'instock');
        $variation->shouldReceive('get_meta')->with('capacity', true)->andReturn($config['capacity'] ?? '');
        $variation->shouldReceive('get_name')->andReturn($config['name'] ?? 'Test Variation');
        $variation->shouldReceive('get_price')->andReturn($config['price'] ?? '120');
        $variation->shouldReceive('is_purchasable')->andReturn($config['is_purchasable'] ?? true);
        $variation->shouldReceive('is_type')->andReturn(true);

        return $variation;
    }

    public function test_needs_confirm_payload_lists_reason_codes_and_messages(): void {
        $payload = intersoccer_roster_move_build_needs_confirm_payload(
            ['no_price', 'full'],
            ['taken' => 39, 'capacity' => 39, 'over_capacity_n' => 0]
        );

        $this->assertTrue($payload['needs_confirm']);
        $this->assertSame(['no_price', 'full'], $payload['reason_codes']);
        $this->assertCount(2, $payload['reason_lines']);
        $this->assertStringContainsString('39 of 39', $payload['reason_lines'][1]);
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
            'stock_status' => 'outofstock',
        ]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertFalse($assessment['block']);
        $this->assertContains('out_of_stock', $assessment['warnings']);
    }

    public function test_full_warning_includes_counts_in_message(): void {
        global $intersoccer_test_post_status_map, $wpdb;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'publish',
            self::TEST_PARENT_ID => 'publish',
        ];
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('39');

        $variation = $this->makeVariation(['capacity' => '39']);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course', 1);

        $this->assertContains('full', $assessment['warnings']);
        $message = intersoccer_roster_move_reason_message('full', $assessment['warning_context']);
        $this->assertStringContainsString('39 of 39', $message);
    }

    public function test_closed_warning_when_roster_flag_set(): void {
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
        $this->assertContains('closed', $assessment['warnings']);
    }

    public function test_purchasable_target_has_no_confirmation_reasons(): void {
        global $intersoccer_test_post_status_map;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'publish',
            self::TEST_PARENT_ID => 'publish',
        ];

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertTrue($assessment['purchasable']);
        $this->assertFalse($assessment['needs_confirmation']);
        $this->assertSame([], $assessment['reason_codes']);
    }

    public function test_merge_empty_sibling_variation_adds_zero_player_target(): void {
        global $intersoccer_test_wc_get_product_callback;
        $empty_child = new class(881002) extends \WC_Product_Variation {
            public function get_parent_id() {
                return 881010;
            }

            public function get_name() {
                return 'Tuesday Zumikon';
            }

            public function get_variation_attributes() {
                return [];
            }

            public function get_meta($key, $single = true) {
                return '';
            }

            public function get_attribute($name) {
                return '';
            }

            public function get_attributes() {
                return [];
            }
        };

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_children')->andReturn([881002]);
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent->shouldReceive('get_name')->andReturn('Parent course');
        $parent->shouldReceive('get_id')->andReturn(self::TEST_PARENT_ID);
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

        global $wpdb;
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('0');

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
            [self::TEST_VARIATION_ID],
            'Course',
            0
        );

        $this->assertCount(1, $merged);
        $this->assertSame(881002, (int) $merged[0]->variation_id);
        $this->assertSame(0, (int) $merged[0]->current_players);
    }

    protected function tearDown(): void {
        $this->resetMovePlayersTestGlobals();
        parent::tearDown();
    }
}
