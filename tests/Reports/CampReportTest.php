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
        $report = $this->reportWithStatsRow();
        $data = $report->generate(['venue' => 'Zurich']);

        $this->assertIsArray($data);
        $this->assertSame(4, $data['statistics']['total_camps']);
        $this->assertSame(12, $data['statistics']['total_participants']);

        $sql = implode("\n", $this->statsDb->prepared);
        $this->assertStringContainsString("activity_type LIKE '%camp%'", $sql);
        $this->assertStringContainsString("product_name LIKE '%summer%'", $sql);
        $this->assertStringContainsString('COALESCE(is_placeholder, 0) = 0', $sql);
    }

    /**
     * Every column the report reads exists in the roster table: the CREATE TABLE in includes/db.php
     * plus the schema Core\Database applies with dbDelta. (player_age, order_total, payment_status,
     * product_type and event_name do not exist, and made the report return no camps.)
     */
    public function test_report_sql_only_reads_real_roster_columns(): void {
        $report = $this->reportWithStatsRow();
        $report->generate(['venue' => 'Zurich', 'gender' => 'female', 'age_group' => '5-13y', 'date_from' => '2026-06-01', 'date_to' => '2026-08-31']);

        $columns = $this->rosterTableColumns();
        $this->assertContains('product_name', $columns);
        $this->assertNotContains('player_age', $columns);

        $non_columns = [
            'select', 'as', 'min', 'max', 'count', 'avg', 'sum', 'case', 'when', 'then', 'else', 'end',
            'from', 'where', 'and', 'or', 'like', 'group', 'by', 'order', 'asc', 'desc', 'limit',
            'distinct', 'lower', 'coalesce', 'nullif', 'is', 'not', 'null', 'in', 'week', 'year',
            'wp_intersoccer_rosters',
            // aliases defined in the report's SELECT lists
            'event_name', 'participant_count', 'min_age', 'max_age', 'avg_age', 'total_revenue',
            'male_count', 'female_count', 'paid_count', 'total_camps', 'total_participants',
            'venues_used', 'avg_camp_price', 'avg_participant_age', 'earliest_camp', 'latest_camp',
            'week_number', 'participants', 'age_band',
        ];
        $this->assertNotEmpty($this->statsDb->prepared);
        foreach ($this->statsDb->prepared as $sql) {
            $without_literals = preg_replace("/'[^']*'/", ' ', (string) $sql);
            preg_match_all('/\b[a-z_][a-z0-9_]*\b/i', (string) $without_literals, $m);
            foreach (array_unique(array_map('strtolower', $m[0])) as $identifier) {
                if (in_array($identifier, $non_columns, true)) {
                    continue;
                }
                $this->assertContains($identifier, $columns, "column '{$identifier}' is not in the roster table:\n{$sql}");
            }
        }
    }

    public function test_failed_queries_return_empty_results_without_warnings(): void {
        $failing = new class extends \InterSoccerTestWpdb {
            public $last_error = "Unknown column 'player_age' in 'field list'";
            public function get_row($query = null, $output = OBJECT, $y = 0) {
                return null;
            }
            public function get_results($query = null, $output = OBJECT) {
                return null;
            }
        };
        global $wpdb;
        $wpdb = $failing;

        // PHPUnit turns PHP warnings into test failures, so this also proves no warnings are raised.
        $data = (new CampReport())->generate(['venue' => 'Zurich']);

        $this->assertSame([], $data['camps']);
        $this->assertSame(0, $data['statistics']['total_camps']);
        $this->assertSame(0, $data['statistics']['total_participants']);
        $this->assertSame('N/A', $data['statistics']['average_participant_age']);
        $this->assertSame('N/A', $data['statistics']['camp_season']);
        $this->assertSame('N/A', $data['statistics']['most_popular_venue']);
        $this->assertSame([], $data['attendance_patterns']['weekly_attendance']);
        $this->assertSame([], $data['attendance_patterns']['age_distribution']);
    }

    /**
     * @return string[]
     */
    private function rosterTableColumns(): array {
        $root = dirname(__DIR__, 2);
        $columns = [];
        $db = (string) file_get_contents($root . '/includes/db.php');
        if (preg_match('/CREATE TABLE \$rosters_table \((.*?)\n\s*\) \$charset_collate/s', $db, $create)) {
            preg_match_all('/^\s*([a-z_]+) (?:bigint|int|varchar|text|date|datetime|timestamp|decimal|boolean|tinyint)/mi', $create[1], $m);
            $columns = array_merge($columns, $m[1]);
        }
        $schema = (string) file_get_contents($root . '/classes/Core/database.php');
        if (preg_match("/'intersoccer_rosters'\s*=>\s*\[\s*'columns'\s*=>\s*\[(.*?)\n\s*\],/s", $schema, $block)) {
            preg_match_all("/'([a-z_]+)'\s*=>\s*'/", $block[1], $m);
            $columns = array_merge($columns, $m[1]);
        }

        return array_values(array_unique(array_map('strtolower', $columns)));
    }

    public function test_filter_by_date_range() {
        $filtered = $this->reportWithStatsRow()->filterByDateRange('2024-06-01', '2024-06-30');

        $this->assertIsArray($filtered);
        $this->assertSame(4, $filtered['statistics']['total_camps']);
    }

    /** @var \InterSoccerTestWpdb */
    private $statsDb;

    private function reportWithStatsRow(): CampReport {
        $this->statsDb = new class extends \InterSoccerTestWpdb {
            public $prepared = [];

            public function prepare($query, ...$args) {
                $bound = parent::prepare($query, ...$args);
                $this->prepared[] = $bound;

                return $bound;
            }

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
        $wpdb = $this->statsDb;

        return new CampReport();
    }
}
