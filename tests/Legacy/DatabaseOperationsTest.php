<?php
/**
 * DatabaseOperations Test - Legacy code in includes/db.php
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

class DatabaseOperationsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        
        // Load legacy file
        require_once __DIR__ . '/../../includes/db.php';
    }
    
    public function test_intersoccer_create_rosters_table() {
        $seen = '';
        Functions\expect('dbDelta')->once()->andReturnUsing(function ($sql) use (&$seen) {
            $seen = (string) $sql;
            return ['Created table'];
        });
        
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_charset_collate')->andReturn('');
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('get_col')->andReturn(['event_signature']);
        $wpdb->shouldReceive('get_var')->andReturn('1');
        $wpdb->shouldReceive('query')->andReturn(true);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->last_error = '';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query) {
            return $query;
        });
        
        intersoccer_create_rosters_table();

        $this->assertStringContainsString('CREATE TABLE wp_intersoccer_rosters', $seen);
        $this->assertStringContainsString('event_signature', $seen);
    }
    
    public function test_intersoccer_validate_rosters_table() {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_var')->andReturn('wp_intersoccer_rosters');
        $wpdb->shouldReceive('get_results')->andReturn($this->rosterDescribeRows());
        
        $this->assertTrue(intersoccer_validate_rosters_table());
    }

    private function rosterDescribeRows(): array {
        $columns = [
            'id' => 'bigint unsigned',
            'order_id' => 'bigint unsigned',
            'order_item_id' => 'bigint unsigned',
            'variation_id' => 'bigint unsigned',
            'player_name' => 'varchar(255)',
            'first_name' => 'varchar(100)',
            'last_name' => 'varchar(100)',
            'age' => 'int',
            'gender' => 'varchar(20)',
            'booking_type' => 'varchar(50)',
            'selected_days' => 'text',
            'camp_terms' => 'varchar(100)',
            'venue' => 'varchar(200)',
            'parent_phone' => 'varchar(20)',
            'parent_email' => 'varchar(100)',
            'medical_conditions' => 'text',
            'late_pickup' => 'varchar(10)',
            'late_pickup_days' => 'text',
            'day_presence' => 'text',
            'age_group' => 'varchar(50)',
            'start_date' => 'date',
            'end_date' => 'date',
            'event_dates' => 'varchar(100)',
            'product_name' => 'varchar(255)',
            'activity_type' => 'varchar(50)',
            'shirt_size' => 'varchar(50)',
            'shorts_size' => 'varchar(50)',
            'registration_timestamp' => 'datetime',
            'course_day' => 'varchar(20)',
            'updated_at' => 'timestamp',
            'product_id' => 'bigint unsigned',
            'player_first_name' => 'varchar(100)',
            'player_last_name' => 'varchar(100)',
            'player_dob' => 'date',
            'player_gender' => 'varchar(10)',
            'player_medical' => 'text',
            'player_dietary' => 'text',
            'parent_first_name' => 'varchar(100)',
            'parent_last_name' => 'varchar(100)',
            'emergency_contact' => 'varchar(20)',
            'term' => 'varchar(200)',
            'times' => 'varchar(191)',
            'days_selected' => 'varchar(200)',
            'season' => 'varchar(50)',
            'canton_region' => 'varchar(100)',
            'city' => 'varchar(100)',
            'avs_number' => 'varchar(50)',
            'created_at' => 'datetime',
            'base_price' => 'decimal(10,2)',
            'discount_amount' => 'decimal(10,2)',
            'final_price' => 'decimal(10,2)',
            'reimbursement' => 'decimal(10,2)',
            'discount_codes' => 'varchar(255)',
            'girls_only' => 'boolean',
            'event_signature' => 'varchar(255)',
            'is_placeholder' => 'tinyint',
            'event_completed' => 'tinyint',
        ];
        $rows = [];
        foreach ($columns as $field => $type) {
            $rows[] = ['Field' => $field, 'Type' => $type];
        }

        return $rows;
    }
}

