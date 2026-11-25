<?php
/**
 * Review fixes for Advanced tools: real preview/apply, caps, and legacy refusal.
 */

use PHPUnit\Framework\TestCase;

class AdvancedToolsRepairWpdb extends InterSoccerTestWpdb {
    public $rows = [];
    public $count = null;
    public $updates = [];
    public $queries = [];
    public $reads = [];
    public $writes = 0;

    public function get_results($query = null, $output = OBJECT) {
        $this->reads[] = (string) $query;
        if (!is_string($query) || stripos($query, 'intersoccer_rosters') === false) {
            return [];
        }
        $rows = $this->rows;
        if (preg_match('/\bid > (\d+)/', $query, $cursor_match)) {
            $cursor = (int) $cursor_match[1];
            $rows = array_values(array_filter($rows, static function ($row) use ($cursor) {
                return is_array($row) && (int) ($row['id'] ?? 0) > $cursor;
            }));
        }
        if (preg_match('/\bLIMIT (\d+)/i', $query, $limit_match)) {
            $rows = array_slice($rows, 0, (int) $limit_match[1]);
        }
        return $rows;
    }

    public function get_row($query = null, $output = OBJECT, $y = 0) {
        $this->reads[] = (string) $query;
        if (!is_string($query) || stripos($query, 'intersoccer_rosters') === false) {
            return parent::get_row($query, $output, $y);
        }
        if (!preg_match('/\bid = (\d+)/', $query, $id_match)) {
            return null;
        }
        $id = (int) $id_match[1];
        foreach ($this->rows as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        return null;
    }

    public function get_var($query = null, $x = 0, $y = 0) {
        if ($this->count !== null) {
            return $this->count;
        }
        return parent::get_var($query, $x, $y);
    }

    public function query($query) {
        $this->queries[] = (string) $query;
        $this->writes++;
        return count($this->rows);
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        $this->writes++;
        $id = is_array($where) ? (int) ($where['id'] ?? 0) : 0;
        if ($id > 0 && is_array($data)) {
            foreach ($this->rows as $index => $row) {
                if (is_array($row) && (int) ($row['id'] ?? 0) === $id) {
                    foreach ($data as $column => $value) {
                        $this->rows[$index][$column] = $value;
                    }
                }
            }
        }
        return 1;
    }

    public function insert($table, $data, $format = null) {
        $this->writes++;
        return 1;
    }

    public function delete($table, $where, $where_format = null) {
        $this->writes++;
        return 1;
    }
}

class AdvancedToolsRepairDiagnosticsDouble {
    public static $calls = [];

    public function runDiagnostics(array $filters): array {
        self::$calls[] = ['diagnostics', $filters];
        return [
            'reason_counts' => [
                'missing_in_rosters' => 2,
                'missing_in_woo_meta' => 1,
                'missing_in_woo' => 1,
            ],
            'mismatches' => [
                ['order_item_id' => 9, 'reasons' => ['missing_in_rosters']],
            ],
        ];
    }

    public function runSafeFix(array $filters): array {
        self::$calls[] = ['safe_fix', $filters];
        return [
            'fixed_missing_in_rosters' => 2,
            'fixed_missing_in_woo_meta' => 1,
            'quarantined_missing_in_woo' => 1,
        ];
    }
}

function intersoccer_rr_test_diagnostics_service_filter() {
    return new AdvancedToolsRepairDiagnosticsDouble();
}

class AdvancedToolsRepairTest extends TestCase {
    private $backupDir;
    private $previousWpdb;

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 2) . '/includes/advanced-tools-admin.php';
        require_once dirname(__DIR__, 2) . '/includes/order-meta-repair-hook.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-details.php';
        require_once dirname(__DIR__, 2) . '/includes/db.php';
        require_once dirname(__DIR__, 2) . '/includes/advanced.php';
        require_once dirname(__DIR__, 2) . '/includes/placeholder-rosters.php';
    }

    protected function setUp(): void {
        if (!function_exists('intersoccer_rr_repair_reset_state')) {
            require_once dirname(__DIR__, 2) . '/includes/advanced-tools-admin.php';
        }
        intersoccer_rr_repair_reset_state();
        $this->backupDir = sys_get_temp_dir() . '/rr-repair-' . getmypid() . '-' . uniqid('', true);
        $GLOBALS['intersoccer_rr_test_backup_dir'] = $this->backupDir;
        $GLOBALS['intersoccer_rr_test_writes'] = 0;
        $GLOBALS['intersoccer_test_roles'] = [];
        unset($GLOBALS['intersoccer_rr_test_tool_runner'], $GLOBALS['intersoccer_rr_test_nonce_valid']);
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        intersoccer_test_set_user_caps([
            'intersoccer_run_repairs' => true,
            'manage_options' => true,
        ]);
        AdvancedToolsRepairDiagnosticsDouble::$calls = [];
        remove_filter('intersoccer_rr_diagnostics_service', 'intersoccer_rr_test_diagnostics_service_filter');
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        unset(
            $GLOBALS['intersoccer_rr_test_tool_runner'],
            $GLOBALS['intersoccer_rr_test_nonce_valid'],
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_current_user']
        );
        remove_filter('intersoccer_rr_diagnostics_service', 'intersoccer_rr_test_diagnostics_service_filter');
        unset($GLOBALS['intersoccer_test_emit_wp_hooks'], $GLOBALS['intersoccer_test_roles']);
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    private function postTool(string $tool, string $mode, array $extra = []): array {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array_merge([
            'intersoccer_rr_repair_tool' => $tool,
            'intersoccer_rr_repair_mode' => $mode,
            '_wpnonce' => 'test-nonce',
            'intersoccer_rr_repair_phrase' => '',
            'intersoccer_rr_repair_global' => '',
            'intersoccer_rr_repair_delete' => '',
        ], $extra);
        return intersoccer_rr_advanced_tools_handle_request();
    }

    public function test_delete_phrase_and_fingerprint_and_rollback_gates(): void {
        $state = ['changed' => 1, 'deleted' => 2, 'season' => 'B'];
        $GLOBALS['intersoccer_rr_test_tool_runner'] = static function ($tool, $dry_run, $scope) use (&$state) {
            if (!$dry_run) {
                $GLOBALS['intersoccer_rr_test_writes']++;
            }
            return [
                'summary' => 'rows',
                'counts' => [
                    'changed' => (int) $state['changed'],
                    'added' => 0,
                    'updated' => (int) $state['changed'],
                    'deleted' => (int) $state['deleted'],
                ],
                'rows' => [[
                    'id' => 5,
                    'before' => ['season' => 'A'],
                    'after' => ['season' => (string) $state['season']],
                ]],
                'wrote' => !$dry_run,
            ];
        };

        $this->postTool('reconcile_rosters', 'preview', ['order_ids' => '15']);
        $refused = $this->postTool('reconcile_rosters', 'apply', [
            'order_ids' => '15',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertStringContainsString('DELETE', $refused['message']);
        $this->assertSame(0, $GLOBALS['intersoccer_rr_test_writes']);

        $applied = $this->postTool('reconcile_rosters', 'apply', [
            'order_ids' => '15',
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_delete' => 'DELETE',
        ]);
        $this->assertSame('success', $applied['type']);
        $this->assertSame(1, $GLOBALS['intersoccer_rr_test_writes']);

        intersoccer_rr_repair_reset_state();
        $GLOBALS['intersoccer_rr_test_writes'] = 0;
        $state['deleted'] = 0;
        $state['season'] = 'B';
        $this->postTool('reconcile_rosters', 'preview', ['order_ids' => '15']);
        $state['season'] = 'C';
        $mismatch = $this->postTool('reconcile_rosters', 'apply', [
            'order_ids' => '15',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertStringContainsString('no longer matches the Preview', $mismatch['message']);
        $this->assertSame(0, $GLOBALS['intersoccer_rr_test_writes']);

        unset($GLOBALS['intersoccer_rr_test_tool_runner']);
        intersoccer_test_set_user_caps(['manage_woocommerce' => true]);
        try {
            $this->postTool('reconcile_rosters', 'preview', ['order_ids' => '15']);
            $this->fail('A shop manager must not preview repairs.');
        } catch (Exception $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        intersoccer_test_set_user_caps(['intersoccer_run_repairs' => true]);
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'intersoccer_rr_repair_tool' => 'rollback',
                'intersoccer_rr_repair_mode' => 'rollback',
            ];
            intersoccer_rr_advanced_tools_handle_request();
            $this->fail('Rollback without a nonce must be refused.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Security check failed', $e->getMessage());
        }

        $missing_phrase = $this->postTool('rollback', 'rollback', ['intersoccer_rr_repair_phrase' => '']);
        $this->assertStringContainsString('APPLY', $missing_phrase['message']);

        $file = intersoccer_rr_repair_write_backup('upgrade_database', 42, [
            ['id' => 'schema_version', 'before' => ['value' => '1.0.0'], 'after' => ['value' => '2.4.0']],
        ]);
        $no_rollback = $this->postTool('rollback', 'rollback', [
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_backup' => $file,
        ]);
        $this->assertStringContainsString('no rollback', strtolower($no_rollback['message']));
    }

    public function test_shop_manager_cannot_open_season_rename_and_shop_wide_needs_global(): void {
        intersoccer_test_set_user_caps(['manage_woocommerce' => true]);
        try {
            intersoccer_rr_render_season_rename_page();
            $this->fail('A shop manager must not open the season rename repair.');
        } catch (Exception $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }

        intersoccer_test_set_user_caps(['intersoccer_run_repairs' => true]);
        $wide = intersoccer_rr_season_rename_tool_run(true, ['season' => '']);
        $scoped = intersoccer_rr_season_rename_tool_run(true, ['season' => 'Spring']);
        $this->assertTrue($wide['shop_wide']);
        $this->assertFalse($scoped['shop_wide']);

        intersoccer_rr_repair_store_preview(42, 'season_rename', ['tool' => 'season_rename', 'season' => ''], [
            'summary' => 'rows',
            'counts' => ['changed' => 1, 'added' => 0, 'updated' => 1, 'deleted' => 0],
            'rows' => [['id' => 3, 'before' => ['season' => 'Old'], 'after' => ['season' => 'New']]],
            'shop_wide' => true,
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'intersoccer_rr_season_rename_action' => 'apply',
            '_wpnonce' => 'test-nonce',
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_global' => '',
            'intersoccer_rr_repair_season' => '',
        ];
        ob_start();
        intersoccer_rr_render_season_rename_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('GLOBAL', $html);
        $this->assertStringContainsString('name="intersoccer_rr_repair_global"', $html);
    }

    public function test_dates_alone_are_shop_wide_and_order_ids_are_scoped(): void {
        $this->assertTrue(intersoccer_rr_scope_is_shop_wide('reconcile_rosters', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]));
        $this->assertFalse(intersoccer_rr_scope_is_shop_wide('reconcile_rosters', [
            'order_ids' => '15',
            'date_from' => '2026-01-01',
        ]));

        unset($GLOBALS['intersoccer_rr_test_tool_runner']);
        $preview = $this->postTool('reconcile_rosters', 'preview', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);
        $this->assertNotSame('unavailable', $preview['result']['code'] ?? '');
        $this->assertStringContainsString('order-sync: no rollback', (string) ($preview['message'] ?? ''));
        $refused = $this->postTool('reconcile_rosters', 'apply', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertStringContainsString('GLOBAL', $refused['message']);

        $scoped = $this->postTool('reconcile_rosters', 'preview', ['order_ids' => '15']);
        $this->assertStringContainsString('order-sync: no rollback', (string) $scoped['message']);
        $this->assertFalse((bool) ($scoped['result']['wrote'] ?? true));
        $empty = $this->postTool('reconcile_rosters', 'apply', [
            'order_ids' => '15',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertStringContainsString('no changes', strtolower($empty['message']));
    }

    public function test_normalize_previews_real_diffs_and_refuses_over_cap(): void {
        unset($GLOBALS['intersoccer_rr_test_tool_runner']);
        $db = new AdvancedToolsRepairWpdb();
        $unchanged = $this->rosterRow(2, 91, 'Monday');
        $unchanged['venue'] = 'zurich';
        $unchanged['times'] = '1000';
        $unchanged['city'] = 'zurich';
        $unchanged['canton_region'] = 'zh';
        $db->rows = [
            $this->rosterRow(1, 90, 'lundi'),
            $unchanged,
        ];
        $GLOBALS['wpdb'] = $db;
        $GLOBALS['intersoccer_test_wc_order_item_meta'][90]['Course Day'] = 'lundi';

        $preview = $this->postTool('normalize_language', 'preview');
        $this->assertSame('info', $preview['type'], $preview['message']);
        $scan = implode("\n", $db->reads);
        $this->assertStringNotContainsString('SELECT *', $scan);
        $this->assertStringContainsString('city', $scan);
        $this->assertStringContainsString('LIMIT 200', $scan);
        $ids = array_map(static function ($row) {
            return (string) ($row['id'] ?? '');
        }, $preview['result']['rows']);
        $this->assertContains('1', $ids);
        $this->assertContains('meta:90:Course Day', $ids);
        $this->assertNotContains('2', $ids);
        $this->assertSame(0, $db->writes);

        $applied = $this->postTool('normalize_language', 'apply', [
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_global' => 'GLOBAL',
        ]);
        $this->assertSame('success', $applied['type'], $applied['message']);
        $this->assertSame('Monday', $GLOBALS['intersoccer_test_wc_order_item_meta'][90]['Course Day']);
        $course_days = [];
        foreach ($db->updates as $update) {
            if (isset($update['data']['course_day'])) {
                $course_days[] = $update['data']['course_day'];
            }
        }
        $this->assertContains('Monday', $course_days);

        $db->rows = [];
        $db->writes = 0;
        $db->updates = [];
        for ($i = 1; $i <= 201; $i++) {
            $db->rows[] = $this->rosterRow($i, 0, 'lundi');
        }
        intersoccer_rr_repair_reset_state();
        $over = $this->postTool('normalize_language', 'preview');
        $this->assertSame('error', $over['type']);
        $this->assertStringContainsString('200', $over['message']);
        $this->assertSame([], $over['result']['rows']);
        $this->assertSame(0, $db->writes);
    }

    public function test_upgrade_lists_pending_steps_and_refuses_when_current(): void {
        unset($GLOBALS['intersoccer_rr_test_tool_runner']);
        $GLOBALS['intersoccer_test_option_store']['intersoccer_db_version'] = '2.4.0';
        $current = $this->postTool('upgrade_database', 'preview');
        $this->assertSame('error', $current['type']);
        $this->assertStringContainsString('No database upgrades are pending', $current['message']);

        $refused = $this->postTool('upgrade_database', 'apply', [
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_global' => 'GLOBAL',
        ]);
        $this->assertSame('error', $refused['type']);

        unset($GLOBALS['intersoccer_test_option_store']['intersoccer_db_version']);
        intersoccer_rr_repair_reset_state();
        $pending = $this->postTool('upgrade_database', 'preview');
        $this->assertStringContainsString('2.0.0', $pending['message']);
        $this->assertStringContainsString('no rollback', strtolower($pending['message']));
        $this->assertNotEmpty($pending['result']['rows']);
        $this->assertTrue((bool) ($pending['result']['no_rollback'] ?? false));
    }

    public function test_fix_issues_uses_diagnostics_and_the_same_filters(): void {
        unset($GLOBALS['intersoccer_rr_test_tool_runner']);
        $real = intersoccer_rr_tool_fix_issues(true, ['year' => '2026', 'order_id' => '55']);
        $this->assertArrayHasKey('fixed_missing_in_rosters', $real['counts']);
        $this->assertArrayHasKey('fixed_missing_in_woo_meta', $real['counts']);
        $this->assertArrayHasKey('quarantined_missing_in_woo', $real['counts']);
        $this->assertStringNotContainsString('fixed_missing_in_rosters', $real['summary']);
        $this->assertStringContainsString('Roster rows to insert: 0', $real['summary']);
        $this->assertStringContainsString('Order-line details to fill in: 0', $real['summary']);
        $this->assertStringContainsString('Roster rows to set aside: 0', $real['summary']);
        $this->assertStringContainsString('no rollback', $real['summary']);
        $this->assertSame(0, (int) $real['counts']['changed']);
        $refused = $this->postTool('fix_issues', 'preview', ['year' => '2026', 'order_id' => '55']);
        $this->postTool('fix_issues', 'apply', [
            'year' => '2026',
            'order_id' => '55',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertSame(0, (int) ($refused['result']['counts']['changed'] ?? 0));

        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        add_filter('intersoccer_rr_diagnostics_service', 'intersoccer_rr_test_diagnostics_service_filter');
        intersoccer_rr_repair_reset_state();
        $preview = $this->postTool('fix_issues', 'preview', ['year' => '2026', 'order_id' => '55']);
        $this->assertStringContainsString('Roster rows to insert: 2', $preview['message']);
        $this->assertStringNotContainsString('fixed_missing_in_rosters', $preview['message']);
        $this->assertSame(2, (int) $preview['result']['counts']['fixed_missing_in_rosters']);
        $this->assertStringNotContainsString('added:', strtolower($preview['message']));
        $applied = $this->postTool('fix_issues', 'apply', [
            'year' => '2026',
            'order_id' => '55',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertSame('success', $applied['type'], $applied['message']);
        $diag = null;
        $fix = null;
        foreach (AdvancedToolsRepairDiagnosticsDouble::$calls as $call) {
            if ($call[0] === 'diagnostics') {
                $diag = $call[1];
            }
            if ($call[0] === 'safe_fix') {
                $fix = $call[1];
            }
        }
        $this->assertNotNull($diag);
        $this->assertNotNull($fix);
        $this->assertSame($diag, $fix);
        $this->assertSame('2026', $diag['year']);
        $this->assertSame(55, $diag['order_id']);
        $this->assertSame('All', $diag['activity_type']);
        $this->assertSame(200, $diag['limit']);
    }

    public function test_merge_uses_one_limit_and_refuses_when_the_count_exceeds_it(): void {
        unset($GLOBALS['intersoccer_rr_test_tool_runner']);
        $db = new AdvancedToolsRepairWpdb();
        $db->count = 2;
        $db->rows = [
            ['id' => 1, 'event_signature' => 'source-sig'],
            ['id' => 2, 'event_signature' => 'source-sig'],
        ];
        $GLOBALS['wpdb'] = $db;
        $preview = $this->postTool('merge_signatures', 'preview', [
            'source_signature' => 'source-sig',
            'target_signature' => 'target-sig',
        ]);
        $this->assertSame(2, (int) $preview['result']['counts']['changed']);
        $this->assertSame([], $db->queries);
        $applied = $this->postTool('merge_signatures', 'apply', [
            'source_signature' => 'source-sig',
            'target_signature' => 'target-sig',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertSame('success', $applied['type'], $applied['message']);
        $this->assertNotEmpty($db->queries);
        $this->assertStringContainsString('LIMIT 200', $db->queries[0]);

        $db->count = 201;
        $db->queries = [];
        $db->writes = 0;
        intersoccer_rr_repair_reset_state();
        $over = $this->postTool('merge_signatures', 'preview', [
            'source_signature' => 'source-sig',
            'target_signature' => 'target-sig',
        ]);
        $this->assertSame('error', $over['type']);
        $this->assertSame([], $over['result']['rows']);
        $this->assertSame(0, $db->writes);
    }

    public function test_roster_details_url_does_not_persist_event_signature(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/roster-details.php');
        $start = strpos($source, 'function intersoccer_get_roster_details_url_for_order_item');
        $end = strpos($source, 'function ', $start + 20);
        $body = substr($source, $start, $end - $start);
        $this->assertStringNotContainsString('->update(', $body);

        $db = new AdvancedToolsRepairWpdb();
        $db->rows = [[
            'id' => 8,
            'order_item_id' => 44,
            'event_signature' => '',
            'activity_type' => 'Tournament',
            'venue' => 'Zurich',
            'course_day' => 'Monday',
            'camp_terms' => '',
            'age_group' => '9-10y',
            'times' => '10:00',
            'season' => '2026',
            'product_id' => 12,
            'variation_id' => 13,
            'girls_only' => 0,
        ]];
        $GLOBALS['wpdb'] = $db;
        $url = intersoccer_get_roster_details_url_for_order_item(44);
        $this->assertIsString($url);
        $this->assertStringContainsString('event_signature', $url);
        $this->assertSame(0, $db->writes);
        $this->assertSame([], $db->updates);
    }

    public function test_legacy_ajax_handlers_refuse_for_an_administrator(): void {
        $adapter = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/oop-adapter.php');
        $this->assertMatchesRegularExpression(
            "/function intersoccer_oop_backfill_financial_attribution_ajax\(\) \{\s*intersoccer_rr_repair_block_legacy_ajax\('financial_backfill'\);\s*return;\s*\}/",
            $adapter
        );
        if (!function_exists('intersoccer_oop_backfill_financial_attribution_ajax')) {
            eval('function intersoccer_oop_backfill_financial_attribution_ajax() { intersoccer_rr_repair_block_legacy_ajax("financial_backfill"); return; }');
        }

        $db = new AdvancedToolsRepairWpdb();
        $GLOBALS['wpdb'] = $db;

        $calls = [
            'intersoccer_rebuild_rosters_and_reports_ajax',
            'intersoccer_reconcile_rosters_ajax',
            'intersoccer_upgrade_database_ajax',
            'intersoccer_rebuild_event_signatures_ajax',
            'intersoccer_repair_day_presence_ajax',
            'intersoccer_process_existing_orders_ajax',
            'intersoccer_sync_placeholders_ajax',
            'intersoccer_oop_backfill_financial_attribution_ajax',
        ];
        foreach ($calls as $fn) {
            $this->assertTrue(function_exists($fn), $fn);
            $db->writes = 0;
            ob_start();
            $fn();
            $raw = ob_get_clean();
            $payload = json_decode((string) $raw, true);
            $this->assertFalse($payload['success'] ?? true, $fn);
            $this->assertNotSame('', (string) ($payload['data']['message'] ?? ''), $fn);
            $this->assertSame(0, $db->writes, $fn);
        }

        $roster = new \InterSoccer\ReportsRosters\Ajax\RosterAjaxHandler();
        foreach ([
            'handleRebuildRosters',
            'handleReconcileRosters',
            'handleReconcileAlignmentBatch',
            'handleRenormalizeRosterLanguage',
            'handleRebuildEventSignatures',
            'handleUpgradeDatabase',
            'handleSyncPlaceholders',
            'handleCleanupPlaceholders',
        ] as $method) {
            $db->writes = 0;
            ob_start();
            $roster->{$method}();
            $raw = ob_get_clean();
            $payload = json_decode((string) $raw, true);
            $this->assertFalse($payload['success'] ?? true, $method);
            $this->assertSame(0, $db->writes, $method);
        }

        $logger = new \InterSoccer\ReportsRosters\Core\Logger();
        $admin = new \InterSoccer\ReportsRosters\Ajax\AdminToolsAjaxHandler(
            $logger,
            new \InterSoccer\ReportsRosters\WooCommerce\OrderProcessor($logger)
        );
        foreach ([
            'processExistingOrders',
            'repairDayPresence',
            'fixReportsRostersIssuesSafe',
            'fixReportsRostersItemSafe',
            'mergeRosterSignaturesApply',
        ] as $method) {
            $db->writes = 0;
            ob_start();
            $admin->{$method}();
            $raw = ob_get_clean();
            $payload = json_decode((string) $raw, true);
            $this->assertFalse($payload['success'] ?? true, $method);
            $this->assertSame(0, $db->writes, $method);
        }

        $reconcile = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/db.php');
        $start = strpos($reconcile, 'function intersoccer_reconcile_rosters_ajax');
        $end = strpos($reconcile, 'function ', $start + 20);
        $this->assertStringNotContainsString('intersoccer_reconcile_rosters(', substr($reconcile, $start, $end - $start));
    }

    public function test_cap_grant_uninstall_backup_and_partial_rollback(): void {
        $GLOBALS['intersoccer_test_roles'] = [];
        intersoccer_rr_repair_grant_administrator_cap();
        $admin = get_role('administrator');
        $this->assertTrue($admin->has_cap('intersoccer_run_repairs'));
        $this->assertSame(1, $admin->add_calls);
        intersoccer_rr_repair_grant_administrator_cap();
        $this->assertSame(1, $admin->add_calls);
        $shop = get_role('shop_manager');
        $this->assertFalse($shop->has_cap('intersoccer_run_repairs'));

        $GLOBALS['intersoccer_test_option_store']['intersoccer_rr_repair_sample'] = 'keep-me-not';
        $db = new AdvancedToolsRepairWpdb();
        $GLOBALS['wpdb'] = $db;
        intersoccer_rr_repair_uninstall();
        $this->assertFalse($admin->has_cap('intersoccer_run_repairs'));
        $this->assertArrayNotHasKey('intersoccer_rr_repair_sample', $GLOBALS['intersoccer_test_option_store']);
        $this->assertNotEmpty($db->queries);
        $this->assertStringContainsString('DELETE FROM', $db->queries[0]);
        $this->assertStringNotContainsString('DROP', $db->queries[0]);

        $uninstall = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
        $this->assertStringContainsString('WP_UNINSTALL_PLUGIN', $uninstall);
        $this->assertStringContainsString('intersoccer_rr_repair_uninstall', $uninstall);
        $this->assertStringNotContainsString('DROP TABLE', $uninstall);

        $activator = (string) file_get_contents(dirname(__DIR__, 2) . '/classes/Core/activator.php');
        $rollback_at = strpos($activator, 'function rollback_activation');
        $this->assertNotFalse($rollback_at);
        $this->assertStringContainsString('intersoccer_run_repairs', substr($activator, $rollback_at, 1800));

        $main = (string) file_get_contents(dirname(__DIR__, 2) . '/intersoccer-reports-rosters.php');
        $contract_at = strpos($main, 'includes/repair-contract.php');
        $plugin_at = strpos($main, 'Plugin::get_instance');
        $this->assertNotFalse($contract_at);
        $this->assertLessThan($plugin_at, $contract_at);

        $menu = (string) file_get_contents(dirname(__DIR__, 2) . '/classes/Admin/menu-manager.php');
        $this->assertStringContainsString('intersoccer-season-rename-repair', $menu);
        $this->assertStringContainsString('intersoccer-advanced-tools#intersoccer-rr-season-rename', $menu);

        $dir = intersoccer_rr_repair_backup_dir();
        $this->assertStringContainsString('Deny from all', (string) file_get_contents($dir . '/.htaccess'));
        $name = intersoccer_rr_repair_write_backup('reconcile_rosters', 42, []);
        $this->assertMatchesRegularExpression('/-[a-f0-9]{32}\.json$/', $name);

        intersoccer_rr_repair_store_preview(42, 'reconcile_rosters', ['tool' => 'reconcile_rosters', 'order_ids' => '15'], [
            'counts' => ['changed' => 1, 'added' => 0, 'updated' => 1, 'deleted' => 0],
            'rows' => [['id' => 1, 'before' => ['season' => 'A'], 'after' => ['season' => 'B']]],
            'shop_wide' => false,
        ]);
        $key = intersoccer_rr_repair_preview_key(42, 'reconcile_rosters');
        $stored = intersoccer_rr_repair_state_get($key, []);
        $stored['created_at'] = time() - 4000;
        intersoccer_rr_repair_state_set($key, $stored);
        $this->assertNull(intersoccer_rr_repair_get_preview(42, 'reconcile_rosters'));

        $current = [
            1 => ['season' => 'B'],
            2 => ['season' => 'C'],
            3 => ['season' => 'A'],
        ];
        $restored = [];
        $outcome = intersoccer_rr_repair_rollback_rows(
            [
                'rows' => [
                    ['id' => 1, 'before' => ['season' => 'A'], 'after' => ['season' => 'B']],
                    ['id' => 2, 'before' => ['season' => 'A'], 'after' => ['season' => 'A']],
                    ['id' => 3, 'before' => ['season' => 'A'], 'after' => ['season' => 'B']],
                ],
            ],
            static function ($id) use (&$current) {
                return $current[$id];
            },
            static function ($id, $before) use (&$restored) {
                $restored[$id] = $before;
            }
        );
        $this->assertSame(2, $outcome['restored']);
        $this->assertSame([], $outcome['conflicts']);
        $this->assertSame(['season' => 'A'], $restored[1]);
        $this->assertSame(['season' => 'A'], $restored[2]);
        $this->assertArrayNotHasKey(3, $restored);

        intersoccer_rr_repair_state_set('intersoccer_rr_repair_backup_index', [
            ['file' => 'upgrade.json', 'tool' => 'upgrade_database', 'created_at' => 't1'],
            ['file' => 'fix.json', 'tool' => 'fix_issues', 'created_at' => 't2'],
            ['file' => 'reconcile.json', 'tool' => 'reconcile_rosters', 'created_at' => 't3'],
        ]);
        ob_start();
        intersoccer_rr_render_repair_rollback_form();
        $form = ob_get_clean();
        $this->assertStringContainsString('reconcile.json', $form);
        $this->assertStringNotContainsString('upgrade.json', $form);
        $this->assertStringNotContainsString('fix.json', $form);
    }

    public function test_normalize_rollback_restores_city_canton_and_activity_type(): void {
        unset($GLOBALS['intersoccer_rr_test_tool_runner']);
        $db = new AdvancedToolsRepairWpdb();
        $original_city = 'Zurich City';
        $original_canton = 'Geneve';
        $original_activity = 'Cours';
        $english_canton = (object) ['term_id' => 880, 'name' => 'Geneva', 'slug' => 'geneva'];
        $previous_terms = [
            $GLOBALS['intersoccer_test_terms_by_id'] ?? null,
            $GLOBALS['intersoccer_test_terms_by_slug'] ?? null,
            $GLOBALS['intersoccer_test_terms_by_name'] ?? null,
        ];
        $GLOBALS['intersoccer_test_terms_by_id'][880] = $english_canton;
        $GLOBALS['intersoccer_test_terms_by_slug']['pa_canton-region']['geneve'] = $english_canton;
        $GLOBALS['intersoccer_test_terms_by_slug']['pa_canton-region']['geneva'] = $english_canton;
        $GLOBALS['intersoccer_test_terms_by_name']['pa_canton-region']['geneve'] = $english_canton;
        $row = $this->rosterRow(7, 0, 'Monday');
        $row['venue'] = 'zurich';
        $row['times'] = '1000';
        $row['city'] = $original_city;
        $row['canton_region'] = $original_canton;
        $row['activity_type'] = $original_activity;
        $row['season'] = '2026';
        $row['event_signature'] = 'old-sig';
        $db->rows = [$row];
        $GLOBALS['wpdb'] = $db;

        $preview = $this->postTool('normalize_language', 'preview');
        $this->assertSame('info', $preview['type'], $preview['message']);
        $planned = $preview['result']['rows'][0] ?? [];
        $this->assertSame($original_city, $planned['before']['city'] ?? null);
        $this->assertNotSame($original_city, $planned['after']['city'] ?? null);
        $this->assertSame($original_canton, $planned['before']['canton_region'] ?? null);
        $this->assertSame($original_activity, $planned['before']['activity_type'] ?? null);

        $applied = $this->postTool('normalize_language', 'apply', [
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_global' => 'GLOBAL',
        ]);
        $this->assertSame('success', $applied['type'], $applied['message']);
        $this->assertNotSame($original_city, $db->rows[0]['city']);

        $index = intersoccer_rr_repair_state_get('intersoccer_rr_repair_backup_index', []);
        $this->assertNotEmpty($index);
        $rolled = $this->postTool('rollback', 'rollback', [
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_backup' => (string) $index[0]['file'],
        ]);
        $this->assertStringContainsString('Conflicts left untouched: 0', $rolled['message']);
        $this->assertSame($original_city, $db->rows[0]['city']);
        $this->assertSame($original_canton, $db->rows[0]['canton_region']);
        $this->assertSame($original_activity, $db->rows[0]['activity_type']);
        $read_sql = implode("\n", $db->reads);
        $this->assertStringContainsString('city', $read_sql);
        $this->assertStringContainsString('canton_region', $read_sql);
        $this->assertStringContainsString('activity_type', $read_sql);
        $GLOBALS['intersoccer_test_terms_by_id'] = $previous_terms[0];
        $GLOBALS['intersoccer_test_terms_by_slug'] = $previous_terms[1];
        $GLOBALS['intersoccer_test_terms_by_name'] = $previous_terms[2];
    }

    public function test_rebuild_preview_uses_expected_signature_and_rollback_reports_conflicts(): void {
        unset($GLOBALS['intersoccer_rr_test_tool_runner']);
        $service = new \InterSoccer\ReportsRosters\Services\SignatureDriftService();
        $drift = $this->rosterRow(11, 0, 'Monday');
        $drift['event_signature'] = 'stale-signature';
        $expected = $service->expectedSignatureForRow($drift);
        $this->assertNotSame('', $expected);
        $this->assertNotSame('stale-signature', $expected);
        $stable = $this->rosterRow(12, 0, 'Monday');
        $stable['event_signature'] = $service->expectedSignatureForRow($stable);

        $db = new AdvancedToolsRepairWpdb();
        $db->rows = [$drift, $stable];
        $GLOBALS['wpdb'] = $db;

        $preview = $this->postTool('rebuild_selected', 'preview', ['roster_ids' => '11,12']);
        $this->assertSame('info', $preview['type'], $preview['message']);
        $this->assertSame(1, (int) $preview['result']['counts']['changed']);
        $this->assertCount(1, $preview['result']['rows']);
        $this->assertSame(11, (int) $preview['result']['rows'][0]['id']);
        $this->assertSame('stale-signature', $preview['result']['rows'][0]['before']['event_signature']);
        $this->assertSame($expected, $preview['result']['rows'][0]['after']['event_signature']);
        $this->assertSame(0, $db->writes);

        $applied = $this->postTool('rebuild_selected', 'apply', [
            'roster_ids' => '11,12',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertSame('success', $applied['type'], $applied['message']);
        $this->assertSame($expected, $db->rows[0]['event_signature']);
        $this->assertSame($stable['event_signature'], $db->rows[1]['event_signature']);

        $db->rows[0]['event_signature'] = 'edited-after-apply';
        $index = intersoccer_rr_repair_state_get('intersoccer_rr_repair_backup_index', []);
        $backup = intersoccer_rr_repair_read_backup((string) $index[0]['file']);
        $this->assertSame($expected, $backup['rows'][0]['after']['event_signature']);
        $rolled = $this->postTool('rollback', 'rollback', [
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_backup' => (string) $index[0]['file'],
        ]);
        $this->assertStringContainsString('Conflicts left untouched: 1', $rolled['message']);
        $this->assertSame('edited-after-apply', $db->rows[0]['event_signature']);
    }

    public function test_financial_backfill_and_placeholders_have_no_rollback_backup(): void {
        $this->assertSame(100, intersoccer_rr_tool_financial_batch_size(''));
        $this->assertSame(100, intersoccer_rr_tool_financial_batch_size('0'));
        $this->assertSame(100, intersoccer_rr_tool_financial_batch_size('abc'));
        $this->assertSame(100, intersoccer_rr_tool_financial_batch_size('  '));
        $this->assertSame(50, intersoccer_rr_tool_financial_batch_size('50'));
        $this->assertSame(1, intersoccer_rr_tool_financial_batch_size('1'));
        $this->assertSame(500, intersoccer_rr_tool_financial_batch_size('900'));
        $_POST = ['batch_size' => ''];
        $scope = intersoccer_rr_advanced_tools_scope_from_post('financial_backfill');
        $this->assertSame(100, intersoccer_rr_tool_financial_batch_size($scope['batch_size'] ?? ''));

        $definitions = intersoccer_rr_advanced_tools_definitions();
        $this->assertTrue((bool) $definitions['financial_backfill']['no_rollback']);
        $this->assertTrue((bool) $definitions['sync_placeholders']['no_rollback']);
        $this->assertStringContainsString('No rollback', (string) $definitions['financial_backfill']['description']);
        $this->assertStringContainsString('No rollback', (string) $definitions['sync_placeholders']['description']);
        $this->assertFalse(intersoccer_rr_repair_tool_supports_rollback('financial_backfill'));
        $this->assertFalse(intersoccer_rr_repair_tool_supports_rollback('sync_placeholders'));
        $this->assertTrue(intersoccer_rr_repair_tool_supports_rollback('normalize_language'));
        $this->assertTrue(intersoccer_rr_repair_tool_supports_rollback('rebuild_selected'));

        $GLOBALS['intersoccer_rr_test_tool_runner'] = static function ($tool, $dry_run) {
            return [
                'summary' => 'one row',
                'counts' => ['changed' => 1, 'added' => 0, 'updated' => 1, 'deleted' => 0],
                'rows' => [[
                    'id' => 4,
                    'before' => ['event_signature' => 'a'],
                    'after' => ['event_signature' => 'b'],
                ]],
                'wrote' => !$dry_run,
            ];
        };
        $this->postTool('sync_placeholders', 'preview');
        $placeholders = $this->postTool('sync_placeholders', 'apply', [
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_global' => 'GLOBAL',
        ]);
        $this->assertSame('success', $placeholders['type'], $placeholders['message']);
        $this->assertStringContainsString('no rollback', strtolower($placeholders['message']));
        intersoccer_rr_repair_reset_state();
        $this->postTool('financial_backfill', 'preview', [
            'start_date' => '2026-01-01',
            'batch_size' => '',
        ]);
        $financial = $this->postTool('financial_backfill', 'apply', [
            'start_date' => '2026-01-01',
            'batch_size' => '',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertSame('success', $financial['type'], $financial['message']);
        $this->assertStringContainsString('no rollback', strtolower($financial['message']));
        $this->assertSame([], intersoccer_rr_repair_state_get('intersoccer_rr_repair_backup_index', []));
        unset($GLOBALS['intersoccer_rr_test_tool_runner']);

        intersoccer_rr_repair_state_set('intersoccer_rr_repair_backup_index', [
            ['file' => 'financial.json', 'tool' => 'financial_backfill', 'created_at' => 't1'],
            ['file' => 'placeholders.json', 'tool' => 'sync_placeholders', 'created_at' => 't2'],
            ['file' => 'normalize.json', 'tool' => 'normalize_language', 'created_at' => 't3'],
        ]);
        ob_start();
        intersoccer_rr_render_repair_rollback_form();
        $form = ob_get_clean();
        $this->assertStringNotContainsString('financial.json', $form);
        $this->assertStringNotContainsString('placeholders.json', $form);
        $this->assertStringContainsString('normalize.json', $form);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        ob_start();
        intersoccer_rr_render_advanced_tools_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('InterSoccer Advanced tools: Rosters &amp; reports', $html);
        $this->assertStringNotContainsString('page=intersoccer-pv-advanced-tools', $html);
        $this->assertStringContainsString('Financial Attribution Backfill, and Sync All Placeholders have no rollback', $html);

        global $submenu;
        $previous_submenu = $submenu ?? null;
        $submenu = [
            'woocommerce' => [
                ['Products and orders', 'manage_woocommerce', 'intersoccer-pv-advanced-tools'],
            ],
        ];
        ob_start();
        intersoccer_rr_render_advanced_tools_page();
        $linked = ob_get_clean();
        $submenu = $previous_submenu;
        $this->assertStringContainsString('InterSoccer Advanced tools: Products &amp; orders', $linked);
        $this->assertStringContainsString('admin.php?page=intersoccer-pv-advanced-tools', $linked);

        intersoccer_test_set_user_caps(['manage_options' => true]);
        $submenu = [
            'woocommerce' => [
                ['Products and orders', 'manage_woocommerce', 'intersoccer-pv-advanced-tools'],
            ],
        ];
        $this->assertSame('', intersoccer_rr_advanced_tools_products_orders_url());
        $submenu = $previous_submenu;

        $menu = (string) file_get_contents(dirname(__DIR__, 2) . '/classes/Admin/menu-manager.php');
        $this->assertStringContainsString('InterSoccer Advanced tools: Rosters & reports', $menu);
        $this->assertStringContainsString("'intersoccer-advanced-tools'", $menu);

        $content = sys_get_temp_dir() . '/rr-wp-content-' . uniqid('', true);
        mkdir($content);
        $outside = intersoccer_rr_repair_backup_dir_choose($content);
        $this->assertSame(dirname($content) . '/intersoccer-rr-repair-backups', $outside);
        $fallback = intersoccer_rr_repair_backup_dir_choose('/proc/rr-missing-content/wp-content');
        $this->assertStringContainsString('intersoccer-rr-repair-backups', $fallback);
        $this->assertStringNotContainsString('/proc/', $fallback);

        intersoccer_rr_repair_log([
            'user_id' => 1,
            'tool' => 'fix_issues',
            'mode' => 'preview',
            'scope' => 'year=2026',
            'counts' => [
                'changed' => 2,
                'fixed_missing_in_rosters' => 2,
                'fixed_missing_in_woo_meta' => 1,
                'quarantined_missing_in_woo' => 1,
            ],
            'backup' => '',
        ]);
        ob_start();
        intersoccer_rr_render_repair_run_log();
        $log = ob_get_clean();
        $this->assertStringNotContainsString('fixed_missing_in_rosters', $log);
        $this->assertStringContainsString('Roster rows to insert: 2', $log);
        $this->assertStringContainsString('Order-line details to fill in: 1', $log);
        $this->assertStringContainsString('Roster rows to set aside: 1', $log);
    }

    private function rosterRow(int $id, int $order_item_id, string $course_day): array {
        return [
            'id' => $id,
            'order_item_id' => $order_item_id,
            'product_id' => 10,
            'variation_id' => 11,
            'activity_type' => 'Course',
            'venue' => 'Zurich',
            'booking_type' => 'Course',
            'selected_days' => '',
            'course_day' => $course_day,
            'camp_terms' => '',
            'times' => '10:00',
            'season' => '2026',
            'city' => 'Zurich',
            'canton_region' => 'ZH',
            'girls_only' => 0,
            'age_group' => '9-10y',
            'start_date' => '',
            'event_signature' => 'sig-' . $id,
            'product_name' => 'Course',
        ];
    }
}
