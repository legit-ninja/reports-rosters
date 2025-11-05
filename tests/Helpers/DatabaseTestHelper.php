<?php
/**
 * Database Test Helper
 * 
 * Helper methods for database testing
 */

namespace InterSoccer\ReportsRosters\Tests\Helpers;

use Mockery;

class DatabaseTestHelper {
    
    /**
     * Create a mock wpdb instance
     * 
     * @return \Mockery\MockInterface
     */
    public static function createMockWpdb() {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('prepare')->andReturnUsing(function($query) {
            $args = func_get_args();
            array_shift($args);
            return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
        });
        
        return $wpdb;
    }
    
    /**
     * Create roster test data
     * 
     * @param int $count Number of rosters to create
     * @return array
     */
    public static function createRosterData(int $count = 1) {
        $rosters = [];
        
        for ($i = 0; $i < $count; $i++) {
            $rosters[] = [
                'id' => $i + 1,
                'order_id' => 100 + $i,
                'order_item_id' => 200 + $i,
                'customer_id' => 1,
                'player_index' => $i,
                'first_name' => 'Child' . ($i + 1),
                'last_name' => 'Doe',
                'dob' => '2010-01-01',
                'gender' => 'male',
                'activity_type' => 'Camp',
                'venue' => 'Zurich',
                'start_date' => '2024-06-01',
                'end_date' => '2024-06-07',
                'age_group' => 'U14',
                'parent_email' => 'parent@example.com',
                'parent_phone' => '+41 12 345 67 89'
            ];
        }
        
        return $count === 1 ? $rosters[0] : $rosters;
    }
    
    /**
     * Setup database expectations for insert
     * 
     * @param \Mockery\MockInterface $wpdb
     * @param int $insertId
     * @return void
     */
    public static function expectInsert($wpdb, int $insertId = 1) {
        $wpdb->shouldReceive('insert')->once()->andReturn(1);
        $wpdb->insert_id = $insertId;
    }
    
    /**
     * Setup database expectations for update
     * 
     * @param \Mockery\MockInterface $wpdb
     * @param int $affectedRows
     * @return void
     */
    public static function expectUpdate($wpdb, int $affectedRows = 1) {
        $wpdb->shouldReceive('update')->once()->andReturn($affectedRows);
    }
    
    /**
     * Setup database expectations for delete
     * 
     * @param \Mockery\MockInterface $wpdb
     * @param int $affectedRows
     * @return void
     */
    public static function expectDelete($wpdb, int $affectedRows = 1) {
        $wpdb->shouldReceive('delete')->once()->andReturn($affectedRows);
    }
    
    /**
     * Setup database expectations for select query
     * 
     * @param \Mockery\MockInterface $wpdb
     * @param array $results
     * @return void
     */
    public static function expectSelect($wpdb, array $results = []) {
        $wpdb->shouldReceive('get_results')->once()->andReturn($results);
    }
    
    /**
     * Clean test database tables
     * 
     * @return void
     */
    public static function cleanTestTables() {
        global $wpdb;
        
        if ($wpdb && method_exists($wpdb, 'query')) {
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}intersoccer_rosters");
        }
    }
}

