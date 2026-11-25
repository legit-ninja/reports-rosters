<?php
/**
 * Admin "Season rename repair" page: scoped reconcile without WP-CLI.
 */

use PHPUnit\Framework\TestCase;

class SeasonRenameReconcileAdminTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 2) . '/includes/order-meta-repair-hook.php';
        require_once dirname(__DIR__, 2) . '/includes/season-rename-reconcile-admin.php';
    }

    private function src(): string {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/includes/season-rename-reconcile-admin.php');
    }

    public function test_product_ids_keep_only_evergreen_products(): void {
        $evergreen = [36509 => false, 60933 => true, 61056 => true, 37928 => false];
        $ids = intersoccer_rr_season_rename_product_ids(
            static function ($pid) use ($evergreen) { return !empty($evergreen[$pid]); },
            ['61056', 37928, 60933, 36509, 0, 60933]
        );
        $this->assertSame([60933, 61056], $ids);
    }

    public function test_preview_is_dry_run_without_order_sync_or_deletes(): void {
        $seen = null;
        $run = intersoccer_rr_season_rename_reconcile(true, [60933, 61056], static function ($o) use (&$seen) {
            $seen = $o;
            return ['synced' => 0, 'season_signature' => ['dry_run' => true]];
        });
        $this->assertSame([60933, 61056], $seen['product_ids']);
        $this->assertTrue($seen['dry_run']);
        $this->assertTrue($seen['skip_order_sync']);
        $this->assertFalse($seen['delete_obsolete']);
        $this->assertFalse($seen['include_product_siblings']);
        $this->assertSame('', $run['error']);
    }

    public function test_apply_resyncs_scoped_orders_and_never_deletes(): void {
        $seen = null;
        intersoccer_rr_season_rename_reconcile(false, [60933], static function ($o) use (&$seen) {
            $seen = $o;
            return [];
        });
        $this->assertFalse($seen['dry_run']);
        $this->assertFalse($seen['skip_order_sync']);
        $this->assertFalse($seen['delete_obsolete']);
        $this->assertSame([60933], $seen['product_ids']);
    }

    public function test_no_evergreen_products_does_not_call_runner(): void {
        $called = false;
        $run = intersoccer_rr_season_rename_reconcile(false, [], static function () use (&$called) {
            $called = true;
            return [];
        });
        $this->assertFalse($called);
        $this->assertNotSame('', $run['error']);
    }

    public function test_group_summary_counts_distinct_signatures(): void {
        $rows = intersoccer_rr_season_rename_group_summary([
            'group_counts_before' => [60933 => ['a' => 20, 'b' => 9]],
            'group_counts_after' => [60933 => ['a' => 29]],
        ]);
        $this->assertSame([['product_id' => 60933, 'groups_before' => 2, 'groups_after' => 1, 'rows' => 29]], $rows);
    }

    public function test_page_has_nonce_capability_and_confirm_and_no_global_rebuild(): void {
        $src = $this->src();
        $this->assertStringContainsString('INTERSOCCER_RR_REPAIR_CAP', $src);
        $this->assertStringContainsString('current_user_can(INTERSOCCER_RR_REPAIR_CAP)', $src);
        $this->assertStringContainsString('intersoccer_rr_repair_verify_nonce(INTERSOCCER_RR_SEASON_RENAME_NONCE)', $src);
        $this->assertStringContainsString('wp_create_nonce(INTERSOCCER_RR_SEASON_RENAME_NONCE)', $src);
        $this->assertStringContainsString('intersoccer_rr_repair_phrase', $src);
        $this->assertStringContainsString('Type APPLY to confirm Apply', $src);
        $this->assertStringNotContainsString('manage_woocommerce', $src);
        $this->assertStringNotContainsString('add_submenu_page', $src);
        $this->assertStringNotContainsString('rebuild_event_signatures', $src);
        $this->assertStringNotContainsString('TRUNCATE', $src);
        $this->assertStringNotContainsString('->delete(', $src);
    }

    public function test_main_plugin_loads_admin_page(): void {
        $main = (string) file_get_contents(dirname(__DIR__, 2) . '/intersoccer-reports-rosters.php');
        $this->assertStringContainsString("includes/season-rename-reconcile-admin.php", $main);
    }

    public function test_reconcile_order_sync_flushes_runtime_cache_in_small_batches(): void {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/classes/services/roster-builder.php');
        $this->assertStringContainsString('const RECONCILE_CACHE_FLUSH_EVERY = 50;', $src);
        $this->assertStringContainsString('wp_cache_flush_runtime()', $src);
        $start = strpos($src, 'public function reconcile(array $options = [])');
        $body = substr($src, $start, 12000);
        $this->assertStringContainsString('% self::RECONCILE_CACHE_FLUSH_EVERY === 0', $body);
        $this->assertStringContainsString('$this->flushReconcileRuntimeCaches();', $body);
    }

    public function test_admin_run_reports_peak_memory(): void {
        $run = intersoccer_rr_season_rename_reconcile(true, [60933], static function () {
            return [];
        });
        $this->assertIsFloat($run['peak_memory_mb']);
        $this->assertStringContainsString('Peak memory: %7$s MB', $this->src());
    }

    public function test_season_label_list_trims_and_drops_blanks(): void {
        $labels = intersoccer_rr_season_repair_labels(['Autumn 2026', '', 'Winter Courses', 'Autumn 2026', '  ']);
        $this->assertSame(['Autumn 2026', 'Winter Courses'], $labels);
    }

    public function test_weekday_preview_scopes_to_one_season_and_never_syncs_or_deletes(): void {
        $seen = null;
        $run = intersoccer_rr_season_weekday_reconcile(true, 'Autumn 2026', static function ($o) use (&$seen) {
            $seen = $o;
            return ['dry_run' => true, 'needing_rename_merge' => 1, 'updated' => 0, 'changes' => []];
        });
        $this->assertSame('Autumn 2026', $seen['season']);
        $this->assertTrue($seen['weekday_repair']);
        $this->assertTrue($seen['dry_run']);
        $this->assertTrue($seen['skip_order_sync']);
        $this->assertFalse($seen['delete_obsolete']);
        $this->assertFalse($seen['include_product_siblings']);
        $this->assertFalse($seen['scope_all']);
        $this->assertSame('', $run['error']);
        $this->assertSame(0, $run['result']['synced']);
        $this->assertSame(0, $run['result']['deleted']);
        $this->assertSame(1, $run['result']['season_signature']['needing_rename_merge']);
    }

    public function test_weekday_apply_stays_dry_run_false_without_order_sync(): void {
        $seen = null;
        intersoccer_rr_season_weekday_reconcile(false, 'Autumn 2026', static function ($o) use (&$seen) {
            $seen = $o;
            return ['dry_run' => false, 'updated' => 1];
        });
        $this->assertFalse($seen['dry_run']);
        $this->assertTrue($seen['skip_order_sync']);
        $this->assertFalse($seen['delete_obsolete']);
        $this->assertSame('Autumn 2026', $seen['season']);
    }

    public function test_weekday_reconcile_requires_a_season(): void {
        $called = false;
        $run = intersoccer_rr_season_weekday_reconcile(false, '  ', static function () use (&$called) {
            $called = true;
            return [];
        });
        $this->assertFalse($called);
        $this->assertNotSame('', $run['error']);
    }

    public function test_page_has_season_selector_and_does_not_use_global_rebuild(): void {
        $src = $this->src();
        $this->assertStringContainsString('intersoccer_rr_repair_season', $src);
        $this->assertStringContainsString('intersoccer_rr_season_weekday_reconcile', $src);
        $this->assertStringContainsString('does not re-sync orders', $src);
        $this->assertStringContainsString('does not change session times', $src);
        $this->assertStringContainsString('Rebuild Event Signatures', $src);
        $this->assertStringNotContainsString('rebuild_event_signatures', $src);
        $this->assertStringNotContainsString('TRUNCATE', $src);
        $this->assertStringNotContainsString('->delete(', $src);
    }

}
