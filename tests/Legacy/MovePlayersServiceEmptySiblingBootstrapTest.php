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
        $merged = $method->invoke($service, [], $base, 881001);

        $this->assertCount(1, $merged);
        $this->assertSame(881002, (int) $merged[0]->variation_id);
        $this->assertSame(0, (int) $merged[0]->current_players);
        $this->assertTrue(function_exists('intersoccer_roster_move_merge_empty_sibling_variation_targets'));
    }
}
