<?php

use PHPUnit\Framework\TestCase;

class RosterSingleExportSplitPermissionsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_current_user'],
            $GLOBALS['intersoccer_rr_hook_test_filters'],
            $GLOBALS['intersoccer_medical_access_test_filters']
        );
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        intersoccer_test_ensure_pm_filter_stub();
        require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-export.php';
        intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
    }

    private function setSplitPermissionFilters(bool $medical_allowed, bool $dob_allowed): void
    {
        $GLOBALS['intersoccer_rr_hook_test_filters'] = [
            'intersoccer_can_view_medical' => [
                static function ($allowed, $user_id) use ($medical_allowed) {
                    return $medical_allowed;
                },
            ],
            'intersoccer_can_view_dob' => [
                static function ($allowed, $user_id) use ($dob_allowed) {
                    return $dob_allowed;
                },
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function samplePlayer(): array
    {
        return [
            'first_name' => 'A',
            'last_name' => 'B',
            'gender' => 'Male',
            'parent_email' => 'a@b.c',
            'age' => 8,
            'avs_number' => '1',
            'player_dob' => '2015-06-01',
            'medical_conditions' => 'Peanuts',
        ];
    }

    public function test_single_export_dob_allowed_medical_denied_includes_birth_not_medical(): void
    {
        $this->setSplitPermissionFilters(false, true);
        $player = $this->samplePlayer();
        $sensitive = intersoccer_rr_single_roster_export_sensitive_personal_values($player);

        $this->assertSame('01/06/2015', $sensitive['formatted_birth_date']);
        $this->assertSame('', $sensitive['export_medical']);

        $headers = intersoccer_rr_roster_export_personal_headers();
        $row = intersoccer_rr_roster_export_personal_row_values(
            $player,
            '079',
            $sensitive['formatted_birth_date'],
            $sensitive['export_medical']
        );
        $this->assertContains('Date of Birth', $headers);
        $this->assertNotContains('Medical/Dietary Conditions', $headers);
        $this->assertCount(count($headers), $row);
        $this->assertContains('01/06/2015', $row);
        $this->assertNotContains('Peanuts', $row);
    }

    public function test_single_export_medical_allowed_dob_denied_includes_medical_not_birth(): void
    {
        $this->setSplitPermissionFilters(true, false);
        $player = $this->samplePlayer();
        $sensitive = intersoccer_rr_single_roster_export_sensitive_personal_values($player);

        $this->assertSame('N/A', $sensitive['formatted_birth_date']);
        $this->assertSame('Peanuts', $sensitive['export_medical']);

        $headers = intersoccer_rr_roster_export_personal_headers();
        $row = intersoccer_rr_roster_export_personal_row_values(
            $player,
            '079',
            $sensitive['formatted_birth_date'],
            $sensitive['export_medical']
        );
        $this->assertNotContains('Date of Birth', $headers);
        $this->assertContains('Medical/Dietary Conditions', $headers);
        $this->assertCount(count($headers), $row);
        $this->assertContains('Peanuts', $row);
        $this->assertNotContains('01/06/2015', $row);
    }
}
