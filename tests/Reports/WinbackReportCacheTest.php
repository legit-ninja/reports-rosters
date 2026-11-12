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
		$key = intersoccer_winback_report_cache_transient_key(12);
		$stored = intersoccer_winback_report_cache_encode_payload($this->minimal_engine_result());
		$this->assertIsString($stored);
		$GLOBALS['intersoccer_test_winback_transient_store'][ $key ] = $stored;

		$result = intersoccer_winback_report_engine_generate_cached($filters);
		$this->assertSame(0, (int) $GLOBALS['intersoccer_test_winback_engine_generate_call_count']);
		$this->assertCount(1, $result['rows']);
	}

	public function test_marker_change_invalidates_cached_payload(): void {
		$key_before = intersoccer_winback_report_cache_transient_key(12);
		intersoccer_winback_report_cache_write(12, $this->minimal_engine_result());
		$GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
		add_filter('intersoccer_winback_report_cache_data_version', static function () {
			return 'mutated-marker';
		});
		$key_after = intersoccer_winback_report_cache_transient_key(12);
		$this->assertNotSame($key_before, $key_after);
		$this->assertNull(intersoccer_winback_report_cache_read(12));
		unset($GLOBALS['intersoccer_test_emit_wp_hooks']);
	}

	public function test_refresh_rebuilds_and_rewrites_cache(): void {
		$filters = ['kind' => 'course', 'venue' => '', 'age_group' => '', 'lookback_months' => 6];
		$GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
		add_filter('intersoccer_test_winback_engine_stub_result', static function () {
			return [
				'rows' => [],
				'unmapped_facets' => [],
				'en_unpublished_variations' => [],
			];
		});
		intersoccer_winback_report_engine_generate_cached($filters, ['force_cache_refresh' => true]);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_winback_engine_generate_call_count']);
		$key = intersoccer_winback_report_cache_transient_key(6);
		$this->assertArrayHasKey($key, $GLOBALS['intersoccer_test_winback_transient_store']);
		unset($GLOBALS['intersoccer_test_emit_wp_hooks']);
	}

	public function test_export_bytes_identical_cached_vs_uncached(): void {
		if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
			$this->markTestSkipped('PhpSpreadsheet required');
		}
		$rows = $this->minimal_engine_result()['rows'];
		$fixed_stamp = 1577836800;
		$build = static function (array $export_rows) use ($fixed_stamp): array {
			$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
			$spreadsheet->getProperties()
				->setCreated($fixed_stamp)
				->setModified($fixed_stamp);
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
			$parts = self::xlsx_part_contents($path);
			@unlink($path);

			return $parts;
		};

		$tab_rows = intersoccer_winback_report_rows_for_tab($rows, 'actionable');
		$parts_a = $build($tab_rows);
		usleep(1100000);
		$parts_b = $build($tab_rows);
		$this->assertNotSame([], $parts_a);
		$this->assertSame($parts_a, $parts_b);
		$this->assertArrayHasKey('xl/worksheets/sheet1.xml', $parts_a);
		$this->assertArrayHasKey('xl/sharedStrings.xml', $parts_a);
		$this->assertArrayHasKey('docProps/core.xml', $parts_a);
		$this->assertStringContainsString('actionable', $parts_a['xl/sharedStrings.xml']);
		$this->assertStringContainsString('2015-01-01', $parts_a['xl/sharedStrings.xml']);
	}

	/**
	 * Uncompressed xlsx parts. Zip entry timestamps are ignored so a second
	 * boundary does not change the comparison.
	 *
	 * @return array<string,string>
	 */
	private static function xlsx_part_contents(string $path): array {
		if (class_exists('ZipArchive')) {
			$zip = new \ZipArchive();
			if ($zip->open($path) === true) {
				$parts = [];
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$name = $zip->getNameIndex($i);
					if (!is_string($name) || $name === '' || substr($name, -1) === '/') {
						continue;
					}
					$contents = $zip->getFromIndex($i);
					if (!is_string($contents)) {
						$zip->close();
						throw new \RuntimeException('Could not read export part');
					}
					$parts[$name] = $contents;
				}
				$zip->close();
				ksort($parts);

				return $parts;
			}
		}

		$bytes = file_get_contents($path);
		if (!is_string($bytes) || $bytes === '') {
			throw new \RuntimeException('Empty export');
		}

		return self::xlsx_part_contents_from_bytes($bytes);
	}

	/**
	 * @return array<string,string>
	 */
	private static function xlsx_part_contents_from_bytes(string $data): array {
		$eocd = strrpos($data, "PK\x05\x06");
		if ($eocd === false || $eocd + 22 > strlen($data)) {
			throw new \RuntimeException('Missing zip directory');
		}
		$total = unpack('v', substr($data, $eocd + 10, 2))[1];
		$cd_size = unpack('V', substr($data, $eocd + 12, 4))[1];
		$cd_offset = unpack('V', substr($data, $eocd + 16, 4))[1];
		$offset = $cd_offset;
		$end = $cd_offset + $cd_size;
		$parts = [];
		for ($n = 0; $n < $total && $offset + 46 <= $end; $n++) {
			if (substr($data, $offset, 4) !== "PK\x01\x02") {
				break;
			}
			$method = unpack('v', substr($data, $offset + 10, 2))[1];
			$comp_size = unpack('V', substr($data, $offset + 20, 4))[1];
			$name_len = unpack('v', substr($data, $offset + 28, 2))[1];
			$extra_len = unpack('v', substr($data, $offset + 30, 2))[1];
			$comment_len = unpack('v', substr($data, $offset + 32, 2))[1];
			$local_off = unpack('V', substr($data, $offset + 42, 4))[1];
			$name = substr($data, $offset + 46, $name_len);
			$offset += 46 + $name_len + $extra_len + $comment_len;
			if (substr($data, $local_off, 4) !== "PK\x03\x04") {
				throw new \RuntimeException('Bad local header for ' . $name);
			}
			$local_name_len = unpack('v', substr($data, $local_off + 26, 2))[1];
			$local_extra_len = unpack('v', substr($data, $local_off + 28, 2))[1];
			$data_off = $local_off + 30 + $local_name_len + $local_extra_len;
			$compressed = substr($data, $data_off, $comp_size);
			if ($method === 0) {
				$contents = $compressed;
			} elseif ($method === 8) {
				$contents = gzinflate($compressed);
				if (!is_string($contents)) {
					throw new \RuntimeException('Inflate failed for ' . $name);
				}
			} else {
				throw new \RuntimeException('Unsupported zip method for ' . $name);
			}
			if ($name !== '' && substr($name, -1) !== '/') {
				$parts[$name] = $contents;
			}
		}
		if ($parts === []) {
			throw new \RuntimeException('No export parts');
		}
		ksort($parts);

		return $parts;
	}
}
