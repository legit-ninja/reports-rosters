<?php
/**
 * Export queries must exclude refund/cancel flagged roster rows.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\RosterExportService;
use InterSoccer\ReportsRosters\Services\RosterRefundService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterExportRefundFilterTest extends TestCase {

    /**
     * @param string $capturedSql
     */
    private function createExportServiceWithCapturedSql(&$capturedSql): RosterExportService {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(function ($value) {
            return (string) $value;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql) use (&$capturedSql) {
            $capturedSql = (string) $sql;
            return $sql;
        });
        $wpdb->shouldReceive('get_results')->andReturn([
            ['player_name' => 'Active Only'],
        ]);

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($wpdb);

        return new RosterExportService(new Logger(), $database);
    }

    private function assertSqlExcludesFlaggedStatuses(string $sql): void {
        $flagged_count = count(RosterRefundService::getFlaggedRowStatuses());
        $this->assertStringContainsString('order_status IS NULL', $sql);
        $this->assertStringContainsString('order_status NOT IN', $sql);
        $this->assertSame(
            $flagged_count,
            substr_count($sql, '%s'),
            'Expected one placeholder per flagged roster row status'
        );
    }

    public function test_fetch_using_field_filters_excludes_flagged_row_statuses_in_sql(): void {
        $sql = '';
        $service = $this->createExportServiceWithCapturedSql($sql);
        $rows = $service->getExportRows([
            'use_fields' => true,
            'variation_id' => 42,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSqlExcludesFlaggedStatuses($sql);
    }

    public function test_fetch_using_variations_excludes_flagged_row_statuses_in_sql(): void {
        $sql = '';
        $service = $this->createExportServiceWithCapturedSql($sql);
        $rows = $service->getExportRows([
            'use_fields' => false,
            'variation_ids' => [10, 11],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSqlExcludesFlaggedStatuses($sql);
    }

    public function test_legacy_export_field_filters_where_appends_flagged_exclusion(): void {
        $where_clauses = ['variation_id = %d'];
        $query_params = [77];
        RosterRefundService::appendSqlExcludeFlaggedRowStatuses($where_clauses, $query_params);

        $this->assertCount(2, $where_clauses);
        $this->assertStringContainsString('order_status IS NULL', $where_clauses[1]);
        $this->assertSame(
            array_merge([77], RosterRefundService::getFlaggedRowStatuses()),
            $query_params
        );
    }

    public function test_legacy_export_variation_branch_and_clause_excludes_flagged_statuses(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($clause, ...$params) {
            return $clause . ' /*params:' . implode(',', $params) . '*/';
        });

        $fragment = RosterRefundService::getSqlExcludeFlaggedRowStatuses('order_status');
        $and_clause = $wpdb->prepare($fragment['clause'], ...$fragment['params']);

        $this->assertStringContainsString('order_status NOT IN', $and_clause);
        $this->assertStringContainsString('cancelled', $and_clause);
        $this->assertStringContainsString('refunded', $and_clause);
    }
}
