<?php
/**
 * FIX3: capacity resolver, AJAX failure payload, logging privacy, l10n table, service empty siblings.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Services\RosterDetailsService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersCapacityPrivacyTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private const TEST_VARIATION_ID = 881001;
    private const TEST_PARENT_ID = 881010;

    protected function setUp(): void {
        parent::setUp();
        $this->resetMovePlayersTestGlobals();

        global $intersoccer_test_post_status_map, $intersoccer_test_product_type_safe_callback, $wpdb;
        $intersoccer_test_post_status_map = [
            self::TEST_VARIATION_ID => 'publish',
            self::TEST_PARENT_ID => 'publish',
        ];
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
        require_once dirname(__DIR__, 2) . '/includes/roster-move-reason-messages.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-item-renormalize-suppress.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/advanced.php';
    }

    protected function tearDown(): void {
        $this->resetMovePlayersTestGlobals();
        $_POST = [];
        unset($GLOBALS['intersoccer_test_capture_roster_move_logs']);
        parent::tearDown();
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
        $variation->shouldReceive('get_name')->andReturn('Test Variation');
        $variation->shouldReceive('get_price')->andReturn($config['price'] ?? '100');
        $variation->shouldReceive('is_purchasable')->andReturn($config['is_purchasable'] ?? true);
        $variation->shouldReceive('get_attribute')->andReturn('')->byDefault();
        $variation->shouldReceive('get_attributes')->andReturn([])->byDefault();
        $variation->shouldReceive('is_type')->andReturn(true)->byDefault();

        return $variation;
    }

    public function test_in_stock_with_players_and_unknown_capacity_needs_no_confirm(): void {
        global $wpdb;
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('12');

        $variation = $this->makeVariation([]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course', 1);

        $this->assertTrue($assessment['purchasable']);
        $this->assertFalse($assessment['needs_confirmation']);
        $this->assertSame([], $assessment['reason_codes']);
    }

    public function test_managed_stock_full_reports_full_with_capacity_and_taken(): void {
        global $wpdb;
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('10');

        $variation = $this->makeVariation([
            'managing_stock' => true,
            'stock_quantity' => 0,
            'stock_status' => 'instock',
        ]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course', 1);

        $this->assertContains('full', $assessment['warnings']);
        $this->assertSame(10, (int) ($assessment['warning_context']['capacity'] ?? 0));
        $this->assertSame(10, (int) ($assessment['warning_context']['taken'] ?? 0));
    }

    public function test_managed_stock_with_remaining_room_needs_no_confirm(): void {
        global $wpdb;
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('3');

        $variation = $this->makeVariation([
            'managing_stock' => true,
            'stock_quantity' => 5,
            'stock_status' => 'instock',
        ]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course', 1);

        $this->assertFalse($assessment['needs_confirmation']);
        $this->assertSame([], $assessment['reason_codes']);
    }

    public function test_capacity_meta_on_variation_reports_full_with_taken(): void {
        global $wpdb;
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('12');

        $variation = $this->makeVariation(['capacity' => '12']);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course', 1);

        $this->assertContains('full', $assessment['warnings']);
        $this->assertSame(12, (int) ($assessment['warning_context']['capacity'] ?? 0));
        $this->assertSame(12, (int) ($assessment['warning_context']['taken'] ?? 0));
    }

    public function test_managed_stock_overbooked_negative_remaining_reports_full(): void {
        global $wpdb;
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('10');

        $variation = $this->makeVariation([
            'managing_stock' => true,
            'stock_quantity' => -2,
            'stock_status' => 'instock',
        ]);
        $context = intersoccer_roster_move_warning_context_for_variation($variation, 1);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course', 1);

        $this->assertSame(8, (int) ($context['capacity'] ?? 0));
        $this->assertContains('full', $assessment['warnings']);
        $this->assertGreaterThan(0, (int) ($context['over_capacity_n'] ?? 0));
    }

    public function test_out_of_stock_status_without_known_capacity(): void {
        $variation = $this->makeVariation([
            'stock_status' => 'outofstock',
        ]);
        $assessment = intersoccer_assess_roster_move_admin_target($variation, 'course', 1);

        $this->assertContains('out_of_stock', $assessment['warnings']);
        $this->assertNotContains('full', $assessment['warnings']);
    }

    public function test_js_localize_strings_non_empty_and_include_required_keys(): void {
        $strings = intersoccer_roster_move_js_localize_strings();
        $this->assertNotEmpty($strings);
        foreach ([
            'apply',
            'checking',
            'heading',
            'move_anyway',
            'cancel',
            'select_destination',
            'no_players_selected',
            'try_again',
            'dismiss',
            'gender_block',
            'failed_player_line',
            'ajax_error',
            'unknown_error',
            'partial_one',
            'partial_many',
        ] as $key) {
            $this->assertArrayHasKey($key, $strings);
            $this->assertNotSame('', (string) $strings[$key]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function invokeMoveAjax(array $post): array {
        $_POST = array_merge(
            [
                'action' => 'intersoccer_move_players',
                'nonce' => wp_create_nonce('intersoccer_move_nonce'),
            ],
            $post
        );
        $GLOBALS['intersoccer_test_json_success_throws'] = true;
        $GLOBALS['intersoccer_test_json_error_throws'] = true;
        try {
            ob_start();
            intersoccer_move_players_ajax();
            $json = ob_get_clean();
        } catch (\RuntimeException $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $json = $e->getMessage();
        } finally {
            unset($GLOBALS['intersoccer_test_json_success_throws'], $GLOBALS['intersoccer_test_json_error_throws']);
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function test_invalid_input_returns_clean_error_without_logging_nonce(): void {
        $GLOBALS['intersoccer_test_capture_roster_move_logs'] = true;
        $GLOBALS['intersoccer_test_captured_roster_move_logs'] = [];
        $nonce = wp_create_nonce('intersoccer_move_nonce');

        $response = $this->invokeMoveAjax([
            'target_variation_id' => 0,
            'order_item_ids' => [],
            'nonce' => $nonce,
        ]);

        $this->assertFalse($response['success'] ?? true);
        $logs = implode("\n", (array) ($GLOBALS['intersoccer_test_captured_roster_move_logs'] ?? []));
        $this->assertStringNotContainsString($nonce, $logs);
    }

    public function test_service_merge_empty_sibling_includes_zero_player_variation(): void {
        global $intersoccer_test_wc_get_product_callback, $wpdb;
        $wpdb = $this->createWpdbMock();
        $wpdb->shouldReceive('get_var')->andReturn('0');

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

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($parent, $empty_child) {
            if ((int) $id === self::TEST_PARENT_ID) {
                return $parent;
            }
            if ((int) $id === 881002) {
                return $empty_child;
            }
            return false;
        };

        $service = new RosterDetailsService();
        $method = new \ReflectionMethod(RosterDetailsService::class, 'mergeEmptySiblingVariationTargets');
        $method->setAccessible(true);
        $base = (object) [
            'product_id' => self::TEST_PARENT_ID,
            'activity_type' => 'Course',
            'girls_only' => 0,
        ];
        $merged = $method->invoke($service, [], $base, self::TEST_VARIATION_ID);

        $this->assertCount(1, $merged);
        $this->assertSame(881002, (int) $merged[0]->variation_id);
        $this->assertSame(0, (int) $merged[0]->current_players);
    }
}
