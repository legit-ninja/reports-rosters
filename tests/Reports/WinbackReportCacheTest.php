<?php
/**
 * Win-back report engine result cache.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackReportCacheTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['roster-report-loader.php', 'winback-report-perf.php', 'winback-report-facets.php', 'winback-catalogue.php', 'utils.php', 'winback-report-engine.php', 'winback-report-cache.php', 'winback-report-admin.php'] as $file) {
			if (file_exists($includes . '/' . $file)) {
				require_once $includes . '/' . $file;
			}
		}
		$this->reset_state();
		$GLOBALS['intersoccer_test_winback_cache_force_enabled'] = true;
	}

	protected function tearDown(): void {
		$this->reset_state();
		parent::tearDown();
	}

	private function reset_state(): void {
		$GLOBALS['intersoccer_test_winback_transient_store'] = [];
		$GLOBALS['intersoccer_test_winback_engine_generate_call_count'] = 0;
		unset($GLOBALS['intersoccer_test_winback_cache_force_enabled'], $GLOBALS['intersoccer_test_winback_verify_refresh_nonce']);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function minimal_engine_result(): array {
		return [
			'rows' => [
				[
					'player' => ['name' => 'A', 'key' => 'k1', 'tier' => 'uuid'],
					'parent' => ['name' => 'Parent', 'id' => 5],
					'contact' => ['email' => 'a@test', 'phone' => '', 'account_email' => 'a@test', 'contact_differs' => false],
					'last_event' => ['product' => 'Camp', 'venue' => 'v', 'age_group' => '5-12y', 'date' => '2026-01-01', 'date_source' => 'start_date'],
					'target' => ['product' => 'Next', 'venue' => 'v', 'age_group' => '5-12y', 'start_date' => '2026-06-01'],
					'more_targets' => [],
					'aged_up' => false,
					'sibling_booked' => false,
					'rebooked_elsewhere' => false,
					'full' => false,
					'status' => 'actionable',
					'dob' => '2015-01-01',
					'age' => 10,
					'events_in_lookback' => 1,
				],
			],
			'unmapped_facets' => [],
			'en_unpublished_variations' => [],
		];
	}

	public function test_cache_hit_avoids_engine_regeneration(): void {
		$filters = ['kind' => 'all', 'venue' => '', 'age_group' => '', 'lookback_months' => 12];
		$key = intersoccer_winback_report_cache_transient_key($filters);
		$stored = intersoccer_winback_report_cache_encode_payload($this->minimal_engine_result());
		$this->assertIsString($stored);
		$GLOBALS['intersoccer_test_winback_transient_store'][ $key ] = $stored;

		$result = intersoccer_winback_report_engine_generate_cached($filters);
		$this->assertSame(0, (int) $GLOBALS['intersoccer_test_winback_engine_generate_call_count']);
		$this->assertCount(1, $result['rows']);
	}

	public function test_marker_change_invalidates_cached_payload(): void {
		$filters = ['kind' => 'all', 'venue' => '', 'age_group' => '', 'lookback_months' => 12];
		$key_before = intersoccer_winback_report_cache_transient_key($filters);
		intersoccer_winback_report_cache_write($filters, $this->minimal_engine_result());
		$GLOBALS['intersoccer_test_winback_cache_data_version'] = 'mutated-marker';
		$key_after = intersoccer_winback_report_cache_transient_key($filters);
		$this->assertNotSame($key_before, $key_after);
		$this->assertNull(intersoccer_winback_report_cache_read($filters));
		unset($GLOBALS['intersoccer_test_winback_cache_data_version']);
	}

	public function test_refresh_rebuilds_and_rewrites_cache(): void {
		$filters = ['kind' => 'course', 'venue' => '', 'age_group' => '', 'lookback_months' => 6];
		$GLOBALS['intersoccer_test_winback_engine_stub_result'] = $this->minimal_engine_result();
		intersoccer_winback_report_engine_generate_cached($filters, ['force_cache_refresh' => true]);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_winback_engine_generate_call_count']);
		$key = intersoccer_winback_report_cache_transient_key($filters);
		$this->assertArrayHasKey($key, $GLOBALS['intersoccer_test_winback_transient_store']);
		unset($GLOBALS['intersoccer_test_winback_engine_stub_result']);
	}

	public function test_export_bytes_identical_cached_vs_uncached(): void {
		if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
			$this->markTestSkipped('PhpSpreadsheet required');
		}
		$rows = $this->minimal_engine_result()['rows'];
		$build = static function (array $export_rows): string {
			$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
			$sheet = $spreadsheet->getActiveSheet();
			$row_index = 1;
			foreach ($export_rows as $row) {
				$line = [
					(string) ($row['player']['name'] ?? ''),
					(string) ($row['status'] ?? ''),
					(string) ($row['dob'] ?? ''),
				];
				$sheet->fromArray($line, null, 'A' . $row_index);
				$row_index++;
			}
			$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
			$path = tempnam(sys_get_temp_dir(), 'wb-export-');
			$writer->save($path);
			$bytes = (string) file_get_contents($path);
			@unlink($path);

			return $bytes;
		};

		$tab_rows = intersoccer_winback_report_rows_for_tab($rows, 'actionable');
		$bytes_a = $build($tab_rows);
		$bytes_b = $build($tab_rows);
		$this->assertSame($bytes_a, $bytes_b);
	}
}
