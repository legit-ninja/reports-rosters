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

    /**
     * Legacy intersoccer_export_roster() field-filter path (non-OOP) must keep flagged-status filter.
     */
    public function test_legacy_intersoccer_export_roster_field_filters_sql_excludes_refunded_and_cancelled_orders(): void {
        $captured_sql = $this->runLegacyIntersoccerExportStoppingAfterRosterQuery([
            'nonce' => 'test_nonce',
            'use_fields' => '1',
            'variation_id' => 123,
        ]);

        $this->assertSqlExcludesExactFlaggedOrderStatuses($captured_sql);
    }

    /**
     * Legacy intersoccer_export_roster() variation-id branch must keep the prepared status filter.
     */
    public function test_legacy_intersoccer_export_roster_variation_ids_sql_excludes_flagged_order_statuses(): void {
        $captured_sql = $this->runLegacyIntersoccerExportStoppingAfterRosterQuery([
            'nonce' => 'test_nonce',
            'use_fields' => '0',
            'variation_ids' => '10,11',
        ]);

        $this->assertStringContainsString('variation_id IN', $captured_sql);
        $this->assertSqlExcludesExactFlaggedOrderStatuses($captured_sql);
    }

    /**
     * @param array<string,mixed> $post
     */
    private function runLegacyIntersoccerExportStoppingAfterRosterQuery(array $post): string {
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->markTestSkipped('PhpSpreadsheet not available');
        }

        require_once dirname(__DIR__, 2) . '/includes/roster-export.php';

        global $wpdb;
        $captured_sql = '';
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->last_query = '';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(function ($value) {
            return (string) $value;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query) {
            $args = func_get_args();
            array_shift($args);
            $flat = [];
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    foreach ($arg as $item) {
                        $flat[] = $item;
                    }
                    continue;
                }
                $flat[] = $arg;
            }
            return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $flat);
        });
        $wpdb->shouldReceive('get_results')->andReturnUsing(function ($query) use (&$captured_sql) {
            $captured_sql = (string) $query;
            throw new \RuntimeException('intersoccer_test_stop_after_roster_query');
        });

        $_POST = $post;

        ob_start();
        try {
            intersoccer_export_roster();
        } catch (\RuntimeException $e) {
            $this->assertSame('intersoccer_test_stop_after_roster_query', $e->getMessage());
        }
        ob_end_clean();

        return $captured_sql;
    }

    private function assertSqlExcludesExactFlaggedOrderStatuses(string $sql): void {
        $this->assertNotEmpty($sql, 'Legacy export should execute a roster query');
        $this->assertStringContainsString('order_status IS NULL', $sql);
        $this->assertStringContainsString('order_status NOT IN', $sql);

        foreach (RosterRefundService::getFlaggedRowStatuses() as $status) {
            $this->assertStringContainsString(
                "'" . $status . "'",
                $sql,
                'SQL must exclude flagged roster order_status: ' . $status
            );
        }
    }
}
