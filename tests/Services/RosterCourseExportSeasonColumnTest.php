<?php
/**
 * Bulk course export includes Season column and honors season filter in SQL.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\RosterExportService;
use InterSoccer\ReportsRosters\Services\RosterRefundService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterCourseExportSeasonColumnTest extends TestCase {

    private function createExportServiceWithCapturedSql(&$capturedSql): RosterExportService {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static function ($value) {
            return (string) $value;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) use (&$capturedSql) {
            $capturedSql = (string) $sql;
            return $sql;
        });
        $wpdb->shouldReceive('get_results')->andReturn([
            [
                'player_name' => 'Synth Player',
                'Season' => 'SYNTH Autumn 2026',
                'event_details' => '{}',
                'avs_number' => '1',
            ],
        ]);

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($wpdb);

        return new RosterExportService(new Logger(), $database);
    }

    public function test_export_sql_selects_season_column_with_english_header_alias(): void {
        $sql = '';
        $service = $this->createExportServiceWithCapturedSql($sql);
        $rows = $service->getExportRows([
            'use_fields' => true,
            'variation_id' => 42,
        ]);

        $this->assertStringContainsString('season AS Season', $sql);
        $this->assertArrayHasKey('Season', $rows[0]);
        $this->assertSame('SYNTH Autumn 2026', $rows[0]['Season']);
        $this->assertArrayHasKey('event_details', $rows[0]);
        $this->assertArrayHasKey('avs_number', $rows[0]);
    }

    public function test_export_sql_applies_season_filter_when_provided(): void {
        $sql = '';
        $service = $this->createExportServiceWithCapturedSql($sql);
        $service->getExportRows([
            'use_fields' => true,
            'event_signature' => 'sig-synth',
            'season' => 'SYNTH Autumn 2026',
        ]);

        $this->assertStringContainsString('season = %s', $sql);
        $this->assertStringContainsString('event_signature = %s', $sql);
    }
}
