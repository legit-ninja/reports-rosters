<?php
/**
 * PCS / win-back warm-up hooks register from the main plugin bootstrap (not the report page).
 */

namespace InterSoccer\ReportsRosters\Tests;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WarmupBootstrapTest extends TestCase {

	private function load_plugin_main_file(): string {
		return dirname(__DIR__) . '/intersoccer-reports-rosters.php';
	}

	private function reset_warmup_test_globals(): void {
		$GLOBALS['intersoccer_test_wp_schedule_event_log'] = [];
		$GLOBALS['intersoccer_test_wp_schedule_single_event_log'] = [];
		$GLOBALS['intersoccer_test_wp_next_scheduled'] = [];
		$GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] = 0;
		$GLOBALS['intersoccer_test_winback_engine_generate_call_count'] = 0;
		$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = true;
		$GLOBALS['intersoccer_test_winback_cache_force_enabled'] = true;
		$GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
	}

	private function bootstrap_plugin_from_main_file(): void {
		require_once $this->load_plugin_main_file();
	}

	/**
	 * @param callable $test_body
	 */
	private function run_with_suppressed_plugin_noise(callable $test_body): void {
		$previous_log = ini_set('error_log', '/dev/null');
		ob_start();
		try {
			$test_body();
		} finally {
			ob_end_clean();
			if ($previous_log !== false) {
				ini_set('error_log', (string) $previous_log);
			}
		}
	}

	public function test_init_from_main_plugin_bootstrap_schedules_pcs_daily_cron(): void {
		$this->run_with_suppressed_plugin_noise(function () {
			$this->reset_warmup_test_globals();
			$this->bootstrap_plugin_from_main_file();
			$this->assertTrue(function_exists('intersoccer_rr_pcs_warmup_bootstrap'));
			do_action('init');
			$hooks = array_column($GLOBALS['intersoccer_test_wp_schedule_event_log'] ?? [], 'hook');
			$this->assertContains(intersoccer_rr_pcs_warmup_daily_cron_hook(), $hooks);
		});
	}

	public function test_order_touch_from_main_bootstrap_schedules_one_pcs_and_one_winback_without_inline_build(): void {
		$this->run_with_suppressed_plugin_noise(function () {
			$this->reset_warmup_test_globals();
			$this->bootstrap_plugin_from_main_file();
			do_action('init');
			for ($i = 0; $i < 5; $i++) {
				do_action('woocommerce_order_status_changed', 100 + $i, 'pending', 'completed', null);
				do_action('woocommerce_update_order', 200 + $i, null);
			}
			$this->assertSame(0, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
			$this->assertSame(0, (int) ($GLOBALS['intersoccer_test_winback_engine_generate_call_count'] ?? 0));
			$log = $GLOBALS['intersoccer_test_wp_schedule_single_event_log'] ?? [];
			$pcs = 0;
			$wb = 0;
			foreach ($log as $entry) {
				if (($entry['hook'] ?? '') === intersoccer_rr_pcs_warmup_year_event_hook()) {
					$pcs++;
				}
				if (($entry['hook'] ?? '') === intersoccer_rr_winback_warmup_event_hook()) {
					$wb++;
				}
			}
			$this->assertSame(1, $pcs);
			$this->assertSame(1, $wb);
		});
	}

	public function test_pcs_year_event_callback_runs_without_report_page_preloaded(): void {
		$this->run_with_suppressed_plugin_noise(function () {
			$this->reset_warmup_test_globals();
			$this->bootstrap_plugin_from_main_file();
			$this->assertFalse(function_exists('intersoccer_player_course_status_generate'));
			do_action('init');
			$GLOBALS['intersoccer_test_player_course_status_players'] = [];
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [];
			do_action(intersoccer_rr_pcs_warmup_year_event_hook(), 2026);
			$this->assertTrue(function_exists('intersoccer_player_course_status_build_year_base_dataset'));
		});
	}

	public function test_winback_warmup_event_callback_runs_without_admin_report_preloaded(): void {
		$this->run_with_suppressed_plugin_noise(function () {
			$this->reset_warmup_test_globals();
			$this->bootstrap_plugin_from_main_file();
			$this->assertFalse(function_exists('intersoccer_winback_report_admin_render_page'));
			add_filter('intersoccer_test_winback_engine_stub_result', static function () {
				return ['rows' => [], 'meta' => []];
			});
			do_action(intersoccer_rr_winback_warmup_event_hook(), 12);
			$this->assertTrue(function_exists('intersoccer_winback_report_engine_generate'));
			$this->assertSame(0, (int) ($GLOBALS['intersoccer_test_winback_engine_generate_call_count'] ?? 0));
		});
	}
}
