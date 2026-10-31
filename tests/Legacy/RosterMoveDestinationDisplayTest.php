<?php
/**
 * Destination roster list: player counts and source variation exclusion.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterMoveDestinationDisplayTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private const PARENT_ID = 901000;
    private const VAR_A = 901010;
    private const VAR_B = 901020;
    private const VAR_SOURCE_FR = 901030;
    private const VAR_SOURCE_DEFAULT = 901031;

    protected function setUp(): void {
        parent::setUp();
        $this->resetMovePlayersTestGlobals();
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_resolve_variation_map']);
        $this->resetMovePlayersTestGlobals();
        parent::tearDown();
    }

    public function test_collapse_and_active_count_shows_five_for_duplicate_summary_rows(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return (string) $query;
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) {
            if (is_string($sql) && strpos($sql, 'COUNT(*)') !== false) {
                return '5';
            }

            return '0';
        });

        $rows = [
            (object) ['variation_id' => self::VAR_A, 'current_players' => 3],
            (object) ['variation_id' => self::VAR_A, 'current_players' => 2],
        ];
        $collapsed = intersoccer_roster_move_collapse_destination_rows_by_variation($rows);
        $this->assertCount(1, $collapsed);
        $this->assertSame(5, (int) $collapsed[0]->current_players);

        $with_active = intersoccer_roster_move_apply_destination_player_counts($collapsed);
        $this->assertSame(5, (int) $with_active[0]->current_players);
        $this->assertSame(
            intersoccer_roster_move_active_roster_count(self::VAR_A),
            (int) $with_active[0]->current_players
        );
    }

    public function test_page_exclude_variation_ids_collects_canonical_variations_on_page(): void {
        $exclude = intersoccer_roster_move_page_exclude_variation_ids([
            (object) ['variation_id' => self::VAR_SOURCE_DEFAULT],
            (object) ['variation_id' => self::VAR_SOURCE_DEFAULT],
        ]);
        $this->assertSame([self::VAR_SOURCE_DEFAULT], $exclude);

        $this->assertTrue(
            intersoccer_roster_move_variation_is_excluded(self::VAR_SOURCE_DEFAULT, $exclude)
        );
        $this->assertFalse(
            intersoccer_roster_move_variation_is_excluded(self::VAR_B, $exclude)
        );

        $targets = intersoccer_roster_move_merge_empty_sibling_variation_targets(
            [
                (object) ['variation_id' => self::VAR_SOURCE_DEFAULT, 'current_players' => 1],
                (object) ['variation_id' => self::VAR_B, 'current_players' => 2],
            ],
            self::PARENT_ID,
            $exclude,
            'Course',
            0
        );
        $ids = array_map(static function ($row) {
            return (int) $row->variation_id;
        }, $targets);
        $this->assertNotContains(self::VAR_SOURCE_DEFAULT, $ids);
        $this->assertContains(self::VAR_B, $ids);
    }

    public function test_mixed_girls_only_roster_rows_list_variation_once_as_cross_gender(): void {
        $mixed_variation = 901777;
        $rows = [
            (object) ['variation_id' => $mixed_variation, 'current_players' => 2, 'girls_only' => 0],
            (object) ['variation_id' => $mixed_variation, 'current_players' => 1, 'girls_only' => 1],
            (object) ['variation_id' => self::VAR_B, 'current_players' => 4, 'girls_only' => 0],
        ];
        $collapsed = intersoccer_roster_move_collapse_destination_rows_by_variation($rows);
        $this->assertCount(2, $collapsed);

        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($mixed_variation);
        $variation->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_girls-only' => 'yes',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_name')->andReturn('Girls only camp');

        global $intersoccer_test_wc_get_product_callback;
        $intersoccer_test_wc_get_product_callback = static function ($id) use ($variation, $mixed_variation) {
            if ((int) $id === $mixed_variation) {
                return $variation;
            }

            return null;
        };

        [$regular, $cross] = intersoccer_roster_move_partition_destination_lists($collapsed, 'mixed');
        $regular_ids = array_map(static fn($row) => (int) $row->variation_id, $regular);
        $cross_ids = array_map(static fn($row) => (int) $row->variation_id, $cross);

        $this->assertContains(self::VAR_B, $regular_ids);
        $this->assertNotContains($mixed_variation, $regular_ids);
        $this->assertSame([$mixed_variation], $cross_ids);
        $this->assertCount(1, $cross_ids);
    }

    public function test_regular_variation_stays_in_regular_list_once(): void {
        [$regular, $cross] = intersoccer_roster_move_partition_destination_lists(
            [(object) ['variation_id' => self::VAR_B, 'current_players' => 2, 'girls_only' => 0]],
            'mixed'
        );
        $this->assertCount(1, $regular);
        $this->assertSame([], $cross);
    }

    public function test_build_admin_destination_lists_partitions_girls_only_empty_sibling_once(): void {
        $go_variation = 901888;
        $rows = [
            (object) ['variation_id' => self::VAR_B, 'current_players' => 2, 'girls_only' => 0],
            (object) ['variation_id' => $go_variation, 'current_players' => 0, 'girls_only' => 1],
        ];

        $go_variation_mock = Mockery::mock('WC_Product_Variation');
        $go_variation_mock->shouldReceive('get_id')->andReturn($go_variation);
        $go_variation_mock->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
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
        $go_variation_mock->shouldReceive('get_name')->andReturn('Girls only camp week');
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
            self::PARENT_ID,
            [],
            'Camp',
            0
        );

        $regular_ids = array_map(static fn($row) => (int) $row->variation_id, $regular);
        $cross_ids = array_map(static fn($row) => (int) $row->variation_id, $cross);

        $this->assertContains(self::VAR_B, $regular_ids);
        $this->assertNotContains($go_variation, $regular_ids);
        $this->assertSame([$go_variation], $cross_ids);
        $this->assertCount(1, $cross_ids);
    }
}
