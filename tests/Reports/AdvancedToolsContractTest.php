<?php
/**
 * Advanced tools: capability, nonce, preview/apply gate, backup, rollback.
 */

use PHPUnit\Framework\TestCase;

class AdvancedToolsContractTest extends TestCase {

    private $backupDir;

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 2) . '/includes/advanced-tools-admin.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-list-filter-persistence.php';
    }

    protected function setUp(): void {
        intersoccer_rr_repair_reset_state();
        $this->backupDir = sys_get_temp_dir() . '/rr-repair-' . getmypid() . '-' . uniqid('', true);
        $GLOBALS['intersoccer_rr_test_backup_dir'] = $this->backupDir;
        $GLOBALS['intersoccer_rr_test_writes'] = 0;
        unset($GLOBALS['intersoccer_rr_test_nonce_valid'], $GLOBALS['intersoccer_rr_test_tool_runner']);
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        intersoccer_test_set_user_caps(['intersoccer_run_repairs' => true]);
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_rr_test_tool_runner'], $GLOBALS['intersoccer_rr_test_nonce_valid']);
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    private function runner(array &$state): void {
        $GLOBALS['intersoccer_rr_test_tool_runner'] = static function ($tool, $dry_run, $scope) use (&$state) {
            if (!$dry_run) {
                $GLOBALS['intersoccer_rr_test_writes']++;
                $state['changed'] = 0;
            }
            $changed = $dry_run ? (int) $state['changed'] : 1;
            return [
                'summary' => 'rows ' . $changed,
                'counts' => ['changed' => $changed, 'added' => $changed, 'updated' => 0, 'deleted' => (int) ($state['deleted'] ?? 0)],
                'rows' => [[
                    'id' => 5,
                    'before' => ['season' => 'A'],
                    'after' => ['season' => 'B'],
                ]],
                'wrote' => !$dry_run,
            ];
        };
    }

    private function postTool(string $mode, array $extra = []): array {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array_merge([
            'intersoccer_rr_repair_tool' => 'reconcile_rosters',
            'intersoccer_rr_repair_mode' => $mode,
            '_wpnonce' => 'test-nonce',
            'intersoccer_rr_repair_phrase' => '',
            'intersoccer_rr_repair_global' => '',
            'intersoccer_rr_repair_delete' => '',
        ], $extra);
        return intersoccer_rr_advanced_tools_handle_request();
    }

    public function test_administrator_can_render_and_shop_manager_cannot(): void {
        ob_start();
        intersoccer_rr_render_advanced_tools_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('Advanced tools', $html);
        $this->assertStringContainsString('Preview (dry run)', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Rebuild Rosters/', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Rebuild Event Signatures/', $html);
        $this->assertStringNotContainsString('intersoccer_rebuild_rosters_and_reports', $html);
        $this->assertStringNotContainsString('value="intersoccer_rebuild_event_signatures"', $html);

        intersoccer_test_set_user_caps([
            'manage_woocommerce' => true,
            'intersoccer_view_roster_admin' => true,
        ]);
        $this->expectException(Exception::class);
        intersoccer_rr_render_advanced_tools_page();
    }

    public function test_settings_and_handlers_do_not_expose_global_rebuilds(): void {
        $settings = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/advanced.php');
        $this->assertStringNotContainsString('intersoccer-rebuild-button', $settings);
        $this->assertStringNotContainsString('intersoccer-rebuild-signatures-button', $settings);
        $this->assertStringNotContainsString('intersoccer_rebuild_rosters_and_reports', $settings);
        $this->assertStringNotContainsString('intersoccer_rebuild_event_signatures', $settings);

        $ajax = (string) file_get_contents(dirname(__DIR__, 2) . '/classes/Ajax/roster-ajax-handler.php');
        $this->assertStringContainsString("intersoccer_rr_repair_block_legacy_ajax('rebuild_rosters')", $ajax);
        $this->assertStringContainsString("intersoccer_rr_repair_block_legacy_ajax('rebuild_event_signatures')", $ajax);

        ob_start();
        intersoccer_rr_repair_block_legacy_ajax('rebuild_rosters');
        $json = ob_get_clean();
        $this->assertStringContainsString('not available in admin', $json);
    }

    public function test_shop_manager_cannot_preview_or_apply(): void {
        $state = ['changed' => 1];
        $this->runner($state);
        intersoccer_test_set_user_caps(['manage_woocommerce' => true, 'intersoccer_view_roster_admin' => true]);
        try {
            $this->postTool('apply', [
                'intersoccer_rr_repair_phrase' => 'APPLY',
                'intersoccer_rr_repair_global' => 'GLOBAL',
            ]);
            $this->fail('Shop manager apply should be rejected.');
        } catch (Exception $e) {
            $this->assertStringContainsString('permission', strtolower($e->getMessage()));
        }
        $this->assertSame(0, $GLOBALS['intersoccer_rr_test_writes']);
    }

    public function test_missing_and_bad_nonce_reject_without_writes(): void {
        $state = ['changed' => 1];
        $this->runner($state);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'intersoccer_rr_repair_tool' => 'reconcile_rosters',
            'intersoccer_rr_repair_mode' => 'preview',
        ];
        try {
            intersoccer_rr_advanced_tools_handle_request();
            $this->fail('Missing nonce should be rejected.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Security check failed', $e->getMessage());
        }

        $GLOBALS['intersoccer_rr_test_nonce_valid'] = false;
        try {
            $this->postTool('preview');
            $this->fail('Bad nonce should be rejected.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Security check failed', $e->getMessage());
        }
        unset($GLOBALS['intersoccer_rr_test_nonce_valid']);
        $this->assertSame(0, $GLOBALS['intersoccer_rr_test_writes']);
    }

    public function test_preview_writes_nothing_and_apply_requires_preview_phrase_and_global(): void {
        $state = ['changed' => 1];
        $this->runner($state);

        $preview = $this->postTool('preview');
        $this->assertSame('info', $preview['type']);
        $this->assertStringContainsString('Nothing was written', $preview['message']);
        $this->assertSame(0, $GLOBALS['intersoccer_rr_test_writes']);

        intersoccer_rr_repair_reset_state();
        $noPreview = $this->postTool('apply', [
            'intersoccer_rr_repair_phrase' => 'APPLY',
            'intersoccer_rr_repair_global' => 'GLOBAL',
        ]);
        $this->assertSame('no_preview', $this->codeFromMessage($noPreview['message']));
        $this->assertSame(0, $GLOBALS['intersoccer_rr_test_writes']);

        $this->postTool('preview');
        $noPhrase = $this->postTool('apply', ['intersoccer_rr_repair_global' => 'GLOBAL']);
        $this->assertSame('apply_phrase', $this->codeFromMessage($noPhrase['message']));
        $this->assertSame(0, $GLOBALS['intersoccer_rr_test_writes']);

        $noGlobal = $this->postTool('apply', ['intersoccer_rr_repair_phrase' => 'APPLY']);
        $this->assertSame('global_phrase', $this->codeFromMessage($noGlobal['message']));
        $this->assertSame(0, $GLOBALS['intersoccer_rr_test_writes']);
    }

    public function test_scoped_apply_does_not_require_global_and_creates_backup(): void {
        $state = ['changed' => 1];
        $this->runner($state);
        $this->postTool('preview', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $applied = $this->postTool('apply', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertSame('success', $applied['type']);
        $this->assertSame(1, $GLOBALS['intersoccer_rr_test_writes']);
        $log = intersoccer_rr_repair_log_entries();
        $apply = end($log);
        $this->assertSame('apply', $apply['mode']);
        $this->assertNotSame('', $apply['backup']);
        $this->assertFileExists($this->backupDir . '/' . $apply['backup']);
        $raw = json_decode((string) file_get_contents($this->backupDir . '/' . $apply['backup']), true);
        $this->assertSame('B', $raw['rows'][0]['after']['season']);
    }

    public function test_second_apply_after_empty_preview_is_refused(): void {
        $state = ['changed' => 1];
        $this->runner($state);
        $this->postTool('preview', ['date_from' => '2026-03-01']);
        $this->postTool('apply', [
            'date_from' => '2026-03-01',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertSame(1, $GLOBALS['intersoccer_rr_test_writes']);
        $this->postTool('preview', ['date_from' => '2026-03-01']);
        $again = $this->postTool('apply', [
            'date_from' => '2026-03-01',
            'intersoccer_rr_repair_phrase' => 'APPLY',
        ]);
        $this->assertSame('empty', $this->codeFromMessage($again['message']));
        $this->assertSame(1, $GLOBALS['intersoccer_rr_test_writes']);
    }

    public function test_rollback_restores_matching_rows_and_reports_conflicts(): void {
        $store = [5 => ['season' => 'B']];
        $backup = [
            'rows' => [[
                'id' => 5,
                'before' => ['season' => 'A'],
                'after' => ['season' => 'B'],
            ]],
        ];
        $read = static function ($id) use (&$store) {
            return $store[$id];
        };
        $write = static function ($id, $before) use (&$store) {
            $store[$id] = $before;
        };
        $restored = intersoccer_rr_repair_rollback_rows($backup, $read, $write);
        $this->assertSame(1, $restored['restored']);
        $this->assertSame([], $restored['conflicts']);
        $this->assertSame('A', $store[5]['season']);

        $store[5] = ['season' => 'C'];
        $conflict = intersoccer_rr_repair_rollback_rows($backup, $read, $write);
        $this->assertSame(0, $conflict['restored']);
        $this->assertSame([5], $conflict['conflicts']);
        $this->assertSame('C', $store[5]['season']);
    }

    public function test_listing_reconcile_get_does_not_write(): void {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/roster-list-filter-persistence.php');
        $fn = substr($src, (int) strpos($src, 'function intersoccer_rosters_maybe_run_page_reconcile_action'));
        $this->assertStringNotContainsString('intersoccer_reconcile_rosters(', $fn);
        $lists = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/rosters.php');
        $this->assertStringNotContainsString('action=reconcile', $lists);
        $this->assertStringNotContainsString('Reconcile Rosters', $lists);

        $_GET = ['action' => 'reconcile', 'page' => 'intersoccer-rosters', '_wpnonce' => 'x'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['intersoccer_test_is_admin'] = true;
        intersoccer_test_set_user_caps(['manage_options' => true, 'intersoccer_run_repairs' => true]);
        intersoccer_rosters_maybe_run_page_reconcile_action();
        $this->assertSame(0, $GLOBALS['intersoccer_rr_test_writes']);
    }

    public function test_repair_cap_is_granted_to_administrator_only(): void {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/repair-contract.php');
        $this->assertStringContainsString("define('INTERSOCCER_RR_REPAIR_CAP', 'intersoccer_run_repairs')", $src);
        $this->assertStringContainsString("get_role('administrator')", $src);
        $grant = substr($src, (int) strpos($src, 'function intersoccer_rr_repair_grant_administrator_cap'));
        $grant = substr($grant, 0, (int) strpos($grant, 'function intersoccer_rr_user_can_run_repairs'));
        $this->assertStringNotContainsString('shop_manager', $grant);
        $this->assertStringNotContainsString('manage_woocommerce', $grant);
    }

    private function codeFromMessage(string $message): string {
        $map = [
            'Run Preview before Apply.' => 'no_preview',
            'Type APPLY to confirm.' => 'apply_phrase',
            'This scope is shop-wide. Type GLOBAL as well as APPLY.' => 'global_phrase',
            'Preview has no changes. Apply was not run.' => 'empty',
            'The change set no longer matches the Preview. Run Preview again.' => 'mismatch',
            'Preview includes row deletes. Type DELETE to confirm those deletes.' => 'delete_phrase',
        ];
        return $map[$message] ?? $message;
    }
}
