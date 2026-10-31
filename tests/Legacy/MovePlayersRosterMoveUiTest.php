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

    public function test_roster_details_markup_includes_show_empty_rosters_and_data_players(): void {
        $path = dirname(__DIR__, 2) . '/includes/roster-details.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->assertStringContainsString('id="showEmptyRosters"', $source);
        $this->assertStringContainsString("esc_html__('Show empty rosters', 'intersoccer-reports-rosters')", $source);
        $this->assertStringContainsString('data-players="', $source);
        $this->assertStringContainsString("$('#showEmptyRosters')", $source);
    }

    public function test_repair_day_presence_handler_strings_are_translatable(): void {
        $path = dirname(__DIR__, 2) . '/includes/roster-details.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);

        if (!preg_match("/#repair-day-presence-btn', function\\(e\\)\\s*\\{(.+?)\\n\\s*\\}\\);\\s*\\n\\s*function runRosterStatusAjax/s", $source, $matches)) {
            $this->fail('Could not extract repair day presence handler from roster-details.php');
        }

        $handler = $matches[1];
        $this->assertStringNotContainsString("text('Missing event signature.')", $handler);
        $this->assertStringContainsString("__('Missing event signature.', 'intersoccer-reports-rosters')", $handler);
        $this->assertStringContainsString("__('Repairing...', 'intersoccer-reports-rosters')", $handler);
        $this->assertStringContainsString("__('Repair completed.', 'intersoccer-reports-rosters')", $handler);
        $this->assertStringContainsString("__('Repair failed.', 'intersoccer-reports-rosters')", $handler);
        $this->assertStringContainsString("__('Repair failed (network/server error).', 'intersoccer-reports-rosters')", $handler);
    }

    public function test_close_and_reopen_handlers_have_no_alert_or_confirm(): void {
        $path = dirname(__DIR__, 2) . '/includes/roster-details.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);

        $this->assertStringContainsString('function runRosterStatusAjax', $source);
        $this->assertStringContainsString('showRosterStatusConfirmPanel', $source);
        $this->assertStringContainsString('showRosterStatusNotice', $source);
        $this->assertStringContainsString('rosterStatusInlinePanel', $source);
        $this->assertStringContainsString('complete: function()', $source);

        $this->assertDoesNotMatchRegularExpression('/close-roster-btn[\s\S]{0,2500}\bconfirm\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/reopen-roster-btn[\s\S]{0,2500}\bconfirm\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/runRosterStatusAjax[\s\S]{0,3500}\balert\s*\(/', $source);
        $this->assertStringContainsString('var originalLabel = $btn.text()', $source);
        $this->assertStringContainsString('completedSuccessfully = true', $source);
        $this->assertStringContainsString('if (!completedSuccessfully)', $source);
        $this->assertStringContainsString('$btn.prop(\'disabled\', false).text(originalLabel)', $source);
    }

    public function test_show_empty_rosters_bypasses_cascade_until_time_selected(): void {
        $path = dirname(__DIR__, 2) . '/includes/roster-details.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->assertStringContainsString('function showEmptyRostersEnabled()', $source);
        $this->assertStringContainsString('function emptyPlayerDestinations(', $source);
        $this->assertStringContainsString('showEmpty && !targetTimeSelect.val()', $source);
        $this->assertStringContainsString('fillDestinationRosterSelect', $source);
        $this->assertStringContainsString('migrationTexts.chooseDestination', $source);
    }

    public function test_show_empty_rosters_respects_chosen_filters_without_time(): void {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/roster-details.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('function emptyDestinationsMatchingFilters(options, chosen)', $source);
        $this->assertStringContainsString("const keys = ['season', 'schedule', 'venue', 'ageGroup', 'time'];", $source);
        $this->assertStringContainsString('!chosen[key] || option[key] === chosen[key]', $source);
        $this->assertStringContainsString('emptyDestinationsMatchingFilters(eligible, {', $source);
        $this->assertStringNotContainsString('showEmpty ? emptyPlayerDestinations(eligible)', $source);
    }
}
