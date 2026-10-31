<?php
/**
 * OOP roster-details path must load roster-move-admin-target without a prior require.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\RosterDetailsService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersServiceEmptySiblingBootstrapTest extends TestCase {

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_service_merge_empty_sibling_without_preloaded_admin_target_module(): void {
        require_once dirname(__DIR__) . '/bootstrap.php';
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

        $this->assertFalse(function_exists('intersoccer_roster_move_merge_empty_sibling_variation_targets'));

        global $intersoccer_test_wc_get_product_callback, $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('0');
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return (string) $query;
        });

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
        $parent->shouldReceive('get_id')->andReturn(881010);
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($parent, $empty_child) {
            if ((int) $id === 881010) {
                return $parent;
            }
            if ((int) $id === 881002) {
                return $empty_child;
            }

            return false;
        };

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug', 'info', 'warning', 'error')->andReturnNull();
        $service = new RosterDetailsService($logger);
        $method = new \ReflectionMethod(RosterDetailsService::class, 'mergeEmptySiblingVariationTargets');
        $method->setAccessible(true);
        $base = (object) [
            'product_id' => 881010,
            'activity_type' => 'Course',
            'girls_only' => 0,
        ];
        $merged = $method->invoke($service, [], $base, [(object) ['variation_id' => 881001]]);

        $this->assertCount(1, $merged);
        $this->assertSame(881002, (int) $merged[0]->variation_id);
        $this->assertSame(0, (int) $merged[0]->current_players);
        $this->assertTrue(function_exists('intersoccer_roster_move_merge_empty_sibling_variation_targets'));
    }

    public function test_service_path_uses_merge_then_partition_for_girls_only_empty_sibling(): void {
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';

        $go_variation = 881888;
        $mixed_variation = 881020;
        $parent_id = 881010;
        $rows = [
            (object) ['variation_id' => $mixed_variation, 'current_players' => 1, 'girls_only' => 0],
            (object) ['variation_id' => $go_variation, 'current_players' => 0, 'girls_only' => 1],
        ];

        $go_variation_mock = Mockery::mock('WC_Product_Variation');
        $go_variation_mock->shouldReceive('get_id')->andReturn($go_variation);
        $go_variation_mock->shouldReceive('get_parent_id')->andReturn($parent_id);
        $go_variation_mock->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_girls-only' => 'yes',
        ]);
        $go_variation_mock->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            if ($name === 'pa_girls-only' || $name === 'girls-only') {
                return 'yes';
            }

            return '';
        });
        $go_variation_mock->shouldReceive('get_attributes')->andReturn([]);
        $go_variation_mock->shouldReceive('get_name')->andReturn('Girls only camp');
        $go_variation_mock->shouldReceive('is_type')->with('variation')->andReturn(true);

        global $intersoccer_test_wc_get_product_callback;
        $intersoccer_test_wc_get_product_callback = static function ($id) use ($go_variation_mock, $go_variation) {
            if ((int) $id === $go_variation) {
                return $go_variation_mock;
            }

            return null;
        };

        [$regular, $cross] = intersoccer_roster_move_build_admin_destination_lists(
            $rows,
            'mixed',
            $parent_id,
            [],
            'Camp',
            0
        );

        $regular_ids = array_map(static fn($row) => (int) $row->variation_id, $regular);
        $cross_ids = array_map(static fn($row) => (int) $row->variation_id, $cross);

        $this->assertContains($mixed_variation, $regular_ids);
        $this->assertNotContains($go_variation, $regular_ids);
        $this->assertSame([$go_variation], $cross_ids);
    }
}
