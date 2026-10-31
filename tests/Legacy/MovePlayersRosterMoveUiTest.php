<?php
/**
 * Roster move admin UI: no browser dialogs in move flow; ARIA roles present.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class MovePlayersRosterMoveUiTest extends TestCase {

    public function test_roster_move_flow_js_has_no_alert_confirm_or_prompt(): void {
        $path = dirname(__DIR__, 2) . '/includes/roster-details.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);

        if (!preg_match("/applyBulk\\.on\\('click',\\s*function\\(\\)\\s*\\{(.+?)\\n\\s*\\}\\);\\s*\\n\\s*\\/\\/ Add keyboard shortcuts/s", $source, $matches)) {
            $this->fail('Could not extract roster move click handler from roster-details.php');
        }

        $handler = $matches[1];
        $this->assertDoesNotMatchRegularExpression('/\balert\s*\(/', $handler);
        $this->assertDoesNotMatchRegularExpression('/\bconfirm\s*\(/', $handler);
        $this->assertDoesNotMatchRegularExpression('/\bprompt\s*\(/', $handler);
    }

    public function test_roster_move_flow_uses_inline_panel_roles(): void {
        $path = dirname(__DIR__, 2) . '/includes/roster-details.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->assertStringContainsString("showRosterMovePanel(", $source);
        $this->assertStringContainsString("'alert'", $source);
        $this->assertStringContainsString("'status'", $source);
        $this->assertStringContainsString('rosterMoveInlinePanel', $source);
        $this->assertStringContainsString('isrrRosterMoveL10n', $source);
        $this->assertStringContainsString('roster-move-admin-target.php', $source);
        $this->assertStringContainsString('rosterMoveConfirmHeading', $source);
    }

    public function test_partial_panel_try_again_only_when_retryable_failures_exist(): void {
        $handler = $this->extractRosterMoveClickHandler();
        $this->assertStringContainsString('retryableFailedIds.length > 0', $handler);
        $this->assertStringContainsString('id="rosterMoveTryAgain"', $handler);
    }

    public function test_run_move_ajax_does_not_post_empty_retry_item_list(): void {
        $handler = $this->extractRosterMoveClickHandler();
        $this->assertStringContainsString('if (!retryIds.length)', $handler);
        $this->assertStringContainsString('return;', $handler);
        $this->assertStringNotContainsString('Permission denied. Please refresh', $handler);
        $this->assertStringContainsString("rosterMoveL10n('permission_denied')", $handler);
    }

    private function extractRosterMoveClickHandler(): string {
        $path = dirname(__DIR__, 2) . '/includes/roster-details.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);

        if (!preg_match("/applyBulk\\.on\\('click',\\s*function\\(\\)\\s*\\{(.+?)\\n\\s*\\}\\);\\s*\\n\\s*\\/\\/ Add keyboard shortcuts/s", $source, $matches)) {
            $this->fail('Could not extract roster move click handler from roster-details.php');
        }

        return $matches[1];
    }
}
