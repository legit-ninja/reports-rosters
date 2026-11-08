<?php
/**
 * CampReport Test
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Reports\CampReport;

class CampReportTest extends TestCase {
    public function test_report_initialization() {
        $this->assertInstanceOf(CampReport::class, new CampReport());
    }

    public function test_generate_camp_report() {
        $data = $this->reportWithStatsRow()->generate(['venue' => 'Zurich']);

        $this->assertIsArray($data);
        $this->assertSame(4, $data['statistics']['total_camps']);
        $this->assertSame(12, $data['statistics']['total_participants']);
    }

    public function test_filter_by_date_range() {
        $filtered = $this->reportWithStatsRow()->filterByDateRange('2024-06-01', '2024-06-30');

        $this->assertIsArray($filtered);
        $this->assertSame(4, $filtered['statistics']['total_camps']);
    }

    private function reportWithStatsRow(): CampReport {
        $db = new class extends \InterSoccerTestWpdb {
            public function get_row($query = null, $output = OBJECT, $y = 0) {
                return (object) [
                    'total_camps' => 4,
                    'total_participants' => 12,
                    'venues_used' => 2,
                    'total_revenue' => 100,
                    'avg_camp_price' => 25,
                    'avg_participant_age' => 8.5,
                    'earliest_camp' => '2024-06-01',
                    'latest_camp' => '2024-06-30',
                    'venue' => 'Zurich',
                    'count' => 7,
                ];
            }

            public function get_results($query = null, $output = OBJECT) {
                return [];
            }
        };

        global $wpdb;
        $wpdb = $db;

        return new CampReport();
    }
}
