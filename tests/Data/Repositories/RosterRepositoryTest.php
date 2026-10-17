<?php
/**
 * RosterRepository Test
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Repositories;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Services\RosterRefundService;
use Mockery;

class RosterRepositoryTest extends TestCase {
    private $repository;
    private $wpdb;
    
    protected function setUp(): void {
        parent::setUp();
        
        global $wpdb;
        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $wpdb = $this->wpdb;
        
        $this->repository = new RosterRepository();
    }
    
    public function test_create_roster() {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(1);
        $this->wpdb->insert_id = 123;
        
        $result = $this->repository->create(['order_id' => 1]);
        
        $this->assertNotNull($result);
    }
    
    public function test_find_roster() {
        $this->wpdb->shouldReceive('get_row')->once()->andReturn((object)['id' => 1, 'order_id' => 1]);
        
        $roster = $this->repository->find(1);
        
        $this->assertNotNull($roster);
    }
    
    public function test_get_rosters_by_venue() {
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);
        
        $rosters = $this->repository->where(['venue' => 'Zurich']);
        
        $this->assertInstanceOf(RostersCollection::class, $rosters);
    }
    
    public function test_delete_roster() {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(1);
        
        $result = $this->repository->delete(1);
        
        $this->assertTrue($result);
    }

    public function test_find_girls_only_includes_flagged_and_legacy_text_rows() {
        if (!function_exists('intersoccer_roster_is_girls_only')) {
            $utils = dirname(__DIR__, 3) . '/includes/utils.php';
            if (is_readable($utils)) {
                require_once $utils;
            }
        }

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                [
                    'id' => 10,
                    'girls_only' => 1,
                    'activity_type' => 'Camp',
                    'is_placeholder' => 0,
                    'order_id' => 1,
                    'order_item_id' => 2,
                    'product_id' => 3,
                    'customer_id' => 4,
                    'player_index' => 0,
                    'first_name' => 'A',
                    'last_name' => 'B',
                ],
                [
                    'id' => 11,
                    'girls_only' => 0,
                    'activity_type' => 'Camp, Girls Only',
                    'is_placeholder' => 0,
                    'order_id' => 1,
                    'order_item_id' => 3,
                    'product_id' => 3,
                    'customer_id' => 4,
                    'player_index' => 0,
                    'first_name' => 'C',
                    'last_name' => 'D',
                ],
                [
                    'id' => 12,
                    'girls_only' => 0,
                    'activity_type' => 'Camp',
                    'is_placeholder' => 0,
                    'order_id' => 1,
                    'order_item_id' => 4,
                    'product_id' => 3,
                    'customer_id' => 4,
                    'player_index' => 0,
                    'first_name' => 'E',
                    'last_name' => 'F',
                ],
            ]);

        $collection = $this->repository->find_girls_only();

        $this->assertSame(2, $collection->count());
    }

    public function test_sync_order_status_for_kept_rows_builds_scoped_update_and_rejects_other_statuses(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function ($sql, ...$params) use (&$captured_sql) {
            $captured_sql = $sql . ' /*' . implode(',', $params) . '*/';
            return $sql;
        });
        $this->wpdb->shouldReceive('query')->once()->andReturn(2);

        $updated = $this->repository->syncOrderStatusForKeptRows(77, Roster::STATUS_REFUNDED);

        $this->assertSame(2, $updated);
        $this->assertStringContainsString('WHERE order_id = %d', $captured_sql);
        $fragment = RosterRefundService::getSqlExcludeFlaggedRowStatuses('order_status');
        $this->assertStringContainsString($fragment['clause'], $captured_sql);

        $this->assertSame(0, $this->repository->syncOrderStatusForKeptRows(77, 'completed'));
    }
}

