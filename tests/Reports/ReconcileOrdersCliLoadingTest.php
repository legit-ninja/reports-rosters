<?php
/**
 * WP-CLI / cron loading of roster reconcile (includes/db.php is admin-only otherwise).
 */

use PHPUnit\Framework\TestCase;

class ReconcileOrdersCliLoadingTest extends TestCase {

    private function src(string $rel): string {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_loader_requires_db_php_and_exposes_reconcile(): void {
        require_once dirname(__DIR__, 2) . '/includes/order-meta-repair-hook.php';
        $this->assertTrue(function_exists('intersoccer_rr_load_reconcile'));
        $this->assertTrue(intersoccer_rr_load_reconcile());
        $this->assertTrue(function_exists('intersoccer_reconcile_rosters'));
        $this->assertTrue(function_exists('intersoccer_reconcile_roster_season_signatures'));
    }

    public function test_pv_season_hook_loads_reconcile_instead_of_silently_returning(): void {
        $src = $this->src('includes/order-meta-repair-hook.php');
        $start = strpos($src, 'function intersoccer_handle_pv_order_season_labels_repaired');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 1200);
        $this->assertStringContainsString('intersoccer_rr_load_reconcile()', $body);
        $this->assertStringNotContainsString("if (!function_exists('intersoccer_reconcile_rosters')) {\n        return;", $body);
    }

    public function test_cli_loads_reconcile_and_supports_all_season_renames(): void {
        $src = $this->src('includes/wp-cli/roster-cli-commands.php');
        $this->assertStringContainsString('intersoccer_rr_load_reconcile()', $src);
        $this->assertStringNotContainsString("'intersoccer_reconcile_rosters is not available.'", $src);
        $this->assertMatchesRegularExpression('/\*\s+\[--all-season-renames\]/', $src);
        $this->assertStringContainsString("'scope_all' => true", $src);
        $this->assertStringContainsString('Nothing to reconcile. Usage:', $src);
        $this->assertStringContainsString('not both', $src);
    }

    public function test_cli_synopsis_value_tokens_are_valid(): void {
        $src = $this->src('includes/wp-cli/roster-cli-commands.php');
        preg_match_all('/\*\s+\[--[a-z-]+=(<[^>]*>)\]/', $src, $m);
        foreach ($m[1] as $token) {
            $this->assertMatchesRegularExpression('/^<[a-z_-]+>$/', $token);
        }
    }
}
