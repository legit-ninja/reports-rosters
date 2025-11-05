<?php
/**
 * CampReport Test
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Reports\CampReport;
use Mockery;

class CampReportTest extends TestCase {
    private $report;
    
    protected function setUp(): void {
        parent::setUp();
        $this->report = new CampReport();
    }
    
    public function test_report_initialization() {
        $this->assertInstanceOf(CampReport::class, $this->report);
    }
    
    public function test_generate_camp_report() {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')->andReturn([]);
        
        global $wpdb;
        $wpdb = $wpdb;
        
        $data = $this->report->generate(['venue' => 'Zurich']);
        
        $this->assertIsArray($data);
    }
    
    public function test_filter_by_date_range() {
        $filtered = $this->report->filterByDateRange('2024-06-01', '2024-06-30');
        
        $this->assertIsArray($filtered);
    }
}

