<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

class DryRunSqlDateTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/dryrun-readonly.php';
    }

    public function test_bad_date_predicate_never_compares_date_to_empty_string(): void {
        $sql = intersoccer_reports_sql_roster_date_is_bad('end_date');
        $this->assertStringNotContainsString("= ''", $sql);
        $this->assertStringContainsString('IS NULL', $sql);
        $this->assertStringContainsString("'1970-01-01'", $sql);
        $this->assertStringContainsString("'1000-01-01'", $sql);
    }

    public function test_bad_end_only_query_shape(): void {
        $bad_start = intersoccer_reports_sql_roster_date_is_bad('start_date');
        $bad_end = intersoccer_reports_sql_roster_date_is_bad('end_date');
        $query = "SELECT COUNT(*) FROM t WHERE {$bad_end} AND NOT ({$bad_start})";
        $this->assertStringNotContainsString("end_date = ''", $query);
    }
}
