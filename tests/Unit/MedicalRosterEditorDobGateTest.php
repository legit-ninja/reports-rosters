<?php

use PHPUnit\Framework\TestCase;

class MedicalRosterEditorDobGateTest extends TestCase
{
    public function test_roster_editor_player_dob_field_uses_dob_permission_not_medical(): void
    {
        $path = dirname(__DIR__, 2) . '/includes/roster-editor.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);

        $marker = 'Player Date of Birth';
        $pos = strpos($source, $marker);
        $this->assertNotFalse($pos, 'Expected player DOB field in roster editor template');

        $snippet = substr($source, max(0, $pos - 250), 500);
        $this->assertStringContainsString('intersoccer_rr_current_user_can_view_dob()', $snippet);
        $this->assertStringNotContainsString('intersoccer_rr_current_user_can_view_medical()', $snippet);
    }
}
