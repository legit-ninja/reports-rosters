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
        global $wpdb;
        $wpdb = new class extends \InterSoccerTestWpdb {
            public function get_row($query = null, $output = OBJECT, $y = 0) {
                return (object) [
                    'total_participants' => 12,
                    'total_events' => 3,
                    'total_venues' => 2,
                    'total_revenue' => 100,
                    'avg_age' => 8.5,
                    'male_count' => 7,
                    'female_count' => 5,
                    'paid_count' => 9,
                ];
            }

            public function get_results($query = null, $output = OBJECT) {
                return [];
            }
        };

        $stats = (new OverviewReport())->generateStatistics();

        $this->assertSame(12, $stats['overall_stats']['total_participants']);
        $this->assertSame(3, $stats['overall_stats']['total_events']);
        $this->assertSame(2, $stats['overall_stats']['total_venues']);
        $this->assertSame('75%', $stats['overall_stats']['payment_rate']);
    }
    
    public function test_get_attendance_by_venue() {
        global $wpdb;
        $wpdb = new class extends \InterSoccerTestWpdb {
            public function get_results($query = null, $output = OBJECT) {
                return [
                    ['venue' => 'Zurich', 'count' => 50],
                ];
            }
        };
        
        $data = (new OverviewReport())->getAttendanceByVenue();
        
        $this->assertSame('Zurich', $data[0]['venue']);
        $this->assertSame(50, $data[0]['count']);
    }
}

