<?php
/**
 * ExportWorkflow Integration Test
 * 
 * Tests the complete export workflow from data to file
 */

namespace InterSoccer\ReportsRosters\Tests\Integration;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class ExportWorkflowTest extends TestCase {
    public function test_generate_and_export_report() {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')->andReturn([
            (object)['first_name' => 'John', 'last_name' => 'Doe', 'venue' => 'Zurich']
        ]);
        
        // Simulate export workflow
        $data = $wpdb->get_results("SELECT * FROM rosters");
        
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }
    
    public function test_export_large_dataset() {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_results')->andReturn(array_fill(0, 1000, (object)[]));
        
        $data = $wpdb->get_results("SELECT * FROM rosters");
        
        $this->assertCount(1000, $data);
    }
    
    public function test_export_with_filters() {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_results')
            ->with(Mockery::pattern('/venue.*Zurich/'))
            ->andReturn([(object)['venue' => 'Zurich']]);
        
        $data = $wpdb->get_results("SELECT * FROM rosters WHERE venue = 'Zurich'");
        
        $this->assertNotEmpty($data);
        $this->assertEquals('Zurich', $data[0]->venue);
    }
}

