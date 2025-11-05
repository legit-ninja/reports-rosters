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
        Functions\expect('dbDelta')->once()->andReturn(['Created table']);
        
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_charset_collate')->andReturn('');
        
        $result = intersoccer_create_rosters_table();
        
        $this->assertTrue($result);
    }
    
    public function test_intersoccer_validate_rosters_table() {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('wp_intersoccer_rosters');
        
        $result = intersoccer_validate_rosters_table();
        
        $this->assertTrue($result);
    }
}

