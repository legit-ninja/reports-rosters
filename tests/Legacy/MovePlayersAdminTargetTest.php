<?php
/**
 * Admin roster move target checks (replaces is_purchasable gate).
 *
 * Break-the-code: revert each guard in includes/roster-move-admin-target.php and re-run this class;
 * the matching test should fail.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccerRRTestMeta;
use Mockery;

class MovePlayersAdminTargetTest extends TestCase {

    private const TEST_VARIATION_ID = 881001;
    private const TEST_PARENT_ID = 881010;

    protected function setUp(): void {
        parent::setUp();
        InterSoccerRRTestMeta::reset();
        global $intersoccer_test_post_status_map, $intersoccer_test_product_type_safe_callback, $intersoccer_test_wc_order_item_meta, $wpdb;
        $intersoccer_test_post_status_map = [];
        $intersoccer_test_wc_order_item_meta = [];
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        // Fresh wpdb each test — roster preflight uses get_var; default to no row.
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn(null);

        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__) . '/stubs/move-players-product-type-stub.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
    }

    protected function tearDown(): void {
        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_post_status_map, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_get_product_callback;
        $intersoccer_test_product_type_safe_callback = null;
        $intersoccer_test_post_status_map = [];
        $intersoccer_test_wc_order_item_meta = [];
        $intersoccer_test_wc_get_product_callback = null;
        parent::tearDown();
    }

    /**
     * Variation with pa_girls-only on parent product only (repro variation 53637 pattern).
     *
     * @return \Mockery\MockInterface
     */
    private function makeParentOnlyGirlsOnlyTargetVariation(int $variation_id = 53637, int $parent_id = 25226) {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($variation_id);
        $variation->shouldReceive('get_parent_id')->andReturn($parent_id);
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_girls-only' => 'yes',
        ]);
        $variation->shouldReceive('get_name')->andReturn('Geneva Girls Only Course');
        $variation->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            if ($name === 'pa_girls-only' || $name === 'girls-only') {
                return 'yes';
            }
            return '';
        });
        $variation->shouldReceive('get_attributes')->andReturn([]);
        $variation->shouldReceive('managing_stock')->andReturn(false);
        $variation->shouldReceive('get_stock_quantity')->andReturn(1);
        $variation->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $variation->shouldReceive('is_type')->andReturn(true);

        global $intersoccer_test_wc_get_product_callback;
        $intersoccer_test_wc_get_product_callback = static function ($id) use ($variation, $variation_id) {
            return (int) $id === $variation_id ? $variation : false;
        };

        return $variation;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function applyCrossGender(array $config): array {
        $base = [
            'block' => false,
            'warnings' => [],
            'needs_confirmation' => false,
        ];

        return intersoccer_roster_move_apply_cross_gender_warnings(
            $base,
            $config['order_item_ids'] ?? [],
            $config['variation'],
            $config['allow_cross_gender'] ?? false
        );
    }

    /**
     * @param array<string,mixed> $config
     * @return \Mockery\MockInterface
     */
    private function makeVariation(array $config) {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($config['id'] ?? self::TEST_VARIATION_ID);
        $variation->shouldReceive('get_parent_id')->andReturn($config['parent_id'] ?? self::TEST_PARENT_ID);
        $variation->shouldReceive('get_variation_attributes')->andReturn($config['attributes'] ?? []);
        $variation->shouldReceive('managing_stock')->andReturn($config['managing_stock'] ?? false);
        $variation->shouldReceive('get_stock_quantity')->andReturn($config['stock_quantity'] ?? 1);
        $variation->shouldReceive('get_meta')->with('capacity', true)->andReturn($config['capacity'] ?? '');
        $variation->shouldReceive('get_name')->andReturn('Test Variation');

        return $variation;
    }

    public function test_private_warning_when_parent_product_is_private(): void {
        global $intersoccer_test_post_status_map;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'publish',
            self::TEST_PARENT_ID => 'private',
        ];

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertFalse($assessment['block']);
        $this->assertContains('private', $assessment['warnings']);
    }

    public function test_private_target_needs_confirmation_without_confirm_flag(): void {
        global $intersoccer_test_post_status_map;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'private',
            self::TEST_PARENT_ID => 'publish',
        ];

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertFalse($assessment['block']);
        $this->assertTrue($assessment['needs_confirmation']);
        $this->assertContains('private', $assessment['warnings']);
    }

    public function test_private_target_not_hard_blocked_for_admin(): void {
        global $intersoccer_test_post_status_map;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'private',
            self::TEST_PARENT_ID => 'publish',
        ];

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');
        $this->assertFalse($assessment['block']);
        $this->assertNotEmpty($assessment['warnings']);
    }

    public function test_trashed_variation_is_blocked(): void {
        global $intersoccer_test_post_status_map;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'trash',
            self::TEST_PARENT_ID => 'publish',
        ];

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertTrue($assessment['block']);
        $this->assertStringContainsString('trash', strtolower($assessment['message']));
    }

    public function test_wrong_activity_type_is_blocked(): void {
        global $intersoccer_test_product_type_safe_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'camp';
        };

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertTrue($assessment['block']);
        $this->assertStringContainsString('activity type', strtolower($assessment['message']));
    }

    public function test_cross_gender_warning_when_target_girls_only_and_source_not(): void {
        $this->assertFalse(intersoccer_roster_move_cross_gender_warning_needed('yes', 'mixed', false));
        $this->assertTrue(intersoccer_roster_move_cross_gender_warning_needed('mixed', 'yes', false));
        $this->assertTrue(intersoccer_roster_move_cross_gender_warning_needed('mixed', 'unknown', false));
    }

    public function test_cross_gender_skipped_when_allow_cross_gender_flag(): void {
        $this->assertFalse(intersoccer_roster_move_cross_gender_warning_needed('mixed', 'yes', true));
        $this->assertFalse(intersoccer_roster_move_cross_gender_warning_needed('yes', 'mixed', true));
    }

    public function test_cross_gender_adds_warning_via_apply_not_block(): void {
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta = [
            99001 => [
                'Activity Type' => 'Course',
                'pa_girls-only' => 'no',
            ],
        ];

        $variation = $this->makeParentOnlyGirlsOnlyTargetVariation();
        $base = [
            'block' => false,
            'warnings' => [],
            'needs_confirmation' => false,
        ];
        $assessment = intersoccer_roster_move_apply_cross_gender_warnings($base, [99001], $variation, false);
        $this->assertFalse($assessment['block']);
        $this->assertContains('cross_gender', $assessment['warnings']);
        $this->assertTrue($assessment['needs_confirmation']);
    }

    public function test_mixed_target_no_cross_gender_warning_or_block(): void {
        $variation = $this->makeVariation([
            'attributes' => ['attribute_pa_girls-only' => 'mixed'],
        ]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');
        $assessment = intersoccer_roster_move_apply_cross_gender_warnings($assessment, [], $variation, false);

        $this->assertFalse($assessment['block']);
        $this->assertNotContains('cross_gender', $assessment['warnings']);
    }

    public function test_unset_target_gender_no_cross_gender_warning(): void {
        $variation = $this->makeVariation(['attributes' => []]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');
        $assessment = intersoccer_roster_move_apply_cross_gender_warnings($assessment, [], $variation, false);

        $this->assertFalse($assessment['block']);
        $this->assertNotContains('cross_gender', $assessment['warnings']);
    }

    /**
     * Target with no pa_girls-only on variation or parent (sandbox variation 30712 pattern).
     */
    public function test_unset_target_does_not_warn_cross_gender_for_girls_only_source(): void {
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta = [
            7883 => ['Activity Type' => 'Course, Girls Only'],
        ];

        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn(30712);
        $variation->shouldReceive('get_parent_id')->andReturn(25200);
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_name')->andReturn('Geneva Regular Course');
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_attributes')->andReturn([]);
        $variation->shouldReceive('managing_stock')->andReturn(false);
        $variation->shouldReceive('get_stock_quantity')->andReturn(1);
        $variation->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $variation->shouldReceive('is_type')->andReturn(true);

        $this->assertNull(intersoccer_roster_move_variation_gender_bucket($variation));

        $assessment = $this->applyCrossGender([
            'order_item_ids' => [7883],
            'variation' => $variation,
        ]);

        $this->assertNotContains('cross_gender', $assessment['warnings']);
    }

    public function test_parent_only_girls_only_target_counts_as_girls_only_bucket(): void {
        $variation = $this->makeParentOnlyGirlsOnlyTargetVariation();
        $this->assertSame(1, intersoccer_roster_move_variation_gender_bucket($variation));
        $this->assertSame(1, intersoccer_roster_move_resolve_variation_girls_only_flag($variation));
    }

    public function test_mixed_source_to_parent_girls_only_target_gets_cross_gender_warning(): void {
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta = [
            99002 => [
                'Activity Type' => 'Course',
                'pa_girls-only' => 'mixed',
            ],
        ];

        $variation = $this->makeParentOnlyGirlsOnlyTargetVariation();
        $assessment = $this->applyCrossGender([
            'order_item_ids' => [99002],
            'variation' => $variation,
        ]);

        $this->assertContains('cross_gender', $assessment['warnings']);
    }

    public function test_girls_only_source_to_parent_girls_only_target_no_warning(): void {
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta = [
            99003 => ['Activity Type' => 'Course, Girls Only'],
        ];

        $variation = $this->makeParentOnlyGirlsOnlyTargetVariation();
        $assessment = $this->applyCrossGender([
            'order_item_ids' => [99003],
            'variation' => $variation,
        ]);

        $this->assertNotContains('cross_gender', $assessment['warnings']);
    }

    public function test_conflicting_pa_no_and_activity_girls_only_source_is_girls_only_bucket(): void {
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta = [
            99005 => [
                'Activity Type' => 'Course, Girls Only',
                'pa_girls-only' => 'no',
            ],
        ];

        $this->assertSame(1, intersoccer_roster_move_source_gender_bucket([99005]));
        if (function_exists('intersoccer_resolve_roster_girls_only_tristate')) {
            $this->assertSame(
                'yes',
                intersoccer_resolve_roster_girls_only_tristate([
                    'Activity Type' => 'Course, Girls Only',
                    'activity_type' => 'Course, Girls Only',
                    'pa_girls-only' => 'no',
                ])
            );
        }
    }

    /**
     * Sandbox variation 37665 pattern: girls-only product type without pa_girls-only on the variation.
     */
    public function test_product_type_girls_only_variation_bucket_matches_pa_write(): void {
        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_wc_get_product_callback;
        $intersoccer_test_product_type_safe_callback = static function ($product_id, $variation_id = null) {
            return 'course-girls-only';
        };

        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn(37665);
        $variation->shouldReceive('get_parent_id')->andReturn(37420);
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_name')->andReturn('Geneva Tuesday Girls Only Course');
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_attributes')->andReturn([]);
        $variation->shouldReceive('managing_stock')->andReturn(false);
        $variation->shouldReceive('get_stock_quantity')->andReturn(1);
        $variation->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $variation->shouldReceive('is_type')->andReturn(true);

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($variation) {
            return (int) $id === 37665 ? $variation : false;
        };

        $this->assertSame(1, intersoccer_roster_move_variation_gender_bucket($variation));
        $this->assertSame('yes', intersoccer_roster_move_resolve_variation_girls_only_tristate($variation));
        $this->assertSame(
            'yes',
            intersoccer_roster_move_pa_girls_only_slug_from_tristate(
                intersoccer_roster_move_resolve_variation_girls_only_tristate($variation)
            )
        );
    }

    public function test_variation_pa_no_overrides_parent_pa_yes_for_target_tristate(): void {
        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_name')->andReturn('Parent Girls Only Course');
        $parent->shouldReceive('get_attribute')->with('pa_girls-only')->andReturn('yes');
        $parent->shouldReceive('get_attribute')->with('girls-only')->andReturn('yes');
        $parent->shouldReceive('get_attribute')->with('pa_activity-type')->andReturn('');

        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn(88001);
        $variation->shouldReceive('get_parent_id')->andReturn(88010);
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_girls-only' => 'no',
        ]);
        $variation->shouldReceive('get_name')->andReturn('Geneva Regular Course Variation');
        $variation->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            if ($name === 'pa_girls-only' || $name === 'girls-only') {
                return 'no';
            }
            return '';
        });
        $variation->shouldReceive('get_attributes')->andReturn([]);

        global $intersoccer_test_wc_get_product_callback, $intersoccer_test_product_type_safe_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        $intersoccer_test_wc_get_product_callback = static function ($id) use ($variation, $parent) {
            if ((int) $id === 88001) {
                return $variation;
            }
            if ((int) $id === 88010) {
                return $parent;
            }
            return false;
        };

        $this->assertSame('mixed', intersoccer_roster_move_resolve_variation_girls_only_tristate($variation));
        $this->assertNull(intersoccer_roster_move_variation_gender_bucket($variation));
    }

    public function test_regular_source_to_parent_girls_only_target_gets_cross_gender_warning(): void {
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta = [
            99004 => [
                'Activity Type' => 'Course',
                'pa_girls-only' => 'no',
            ],
        ];

        $variation = $this->makeParentOnlyGirlsOnlyTargetVariation();
        $assessment = $this->applyCrossGender([
            'order_item_ids' => [99004],
            'variation' => $variation,
        ]);

        $this->assertContains('cross_gender', $assessment['warnings']);
    }

    public function test_cancelled_meta_blocks_target(): void {
        update_post_meta(self::TEST_PARENT_ID, '_course_cancelled', 'yes');

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertTrue($assessment['block']);
        $this->assertStringContainsString('cancelled', strtolower($assessment['message']));
    }

    public function test_past_end_date_adds_warning_not_block(): void {
        update_post_meta(self::TEST_VARIATION_ID, '_end_date', '2020-01-01');

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course');

        $this->assertFalse($assessment['block']);
        $this->assertContains('past_end_date', $assessment['warnings']);
    }

    public function test_order_item_has_roster_row_false_when_missing(): void {
        $this->assertFalse(intersoccer_roster_move_order_item_has_roster_row(0));
        $this->assertFalse(intersoccer_roster_move_order_item_has_roster_row(999001));
    }

    public function test_order_item_has_roster_row_true_when_db_has_row(): void {
        global $wpdb;
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('7');

        $this->assertTrue(intersoccer_roster_move_order_item_has_roster_row(42));
    }
}
