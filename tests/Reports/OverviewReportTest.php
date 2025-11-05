<?php
/**
 * OverviewReport Test
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Reports\OverviewReport;
use Mockery;

class OverviewReportTest extends TestCase {
    private $report;
    
    protected function setUp(): void {
        parent::setUp();
        $this->report = new OverviewReport();
    }
    
    public function test_report_initialization() {
        $this->assertInstanceOf(OverviewReport::class, $this->report);
    }
    
    public function test_generate_overview_statistics() {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn(100);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        
        global $wpdb;
        $wpdb = $wpdb;
        
        $stats = $this->report->generateStatistics();
        
        $this->assertIsArray($stats);
    }
    
    public function test_get_attendance_by_venue() {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')->andReturn([
            (object)['venue' => 'Zurich', 'count' => 50]
        ]);
        
        global $wpdb;
        $wpdb = $wpdb;
        
        $data = $this->report->getAttendanceByVenue();
        
        $this->assertIsArray($data);
    }
}

