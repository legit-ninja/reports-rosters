<?php

use PHPUnit\Framework\TestCase;

class MedicalAccessSplitPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        intersoccer_test_ensure_pm_filter_stub();
        require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
        $this->resetSplitPermissionFilters();
        intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
    }

    protected function tearDown(): void
    {
        $this->resetSplitPermissionFilters();
        unset(
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_current_user']
        );
        parent::tearDown();
    }

    private function resetSplitPermissionFilters(): void
    {
        unset(
            $GLOBALS['intersoccer_rr_hook_test_filters'],
            $GLOBALS['intersoccer_medical_access_test_filters']
        );
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
     * @return array<string,string>
     */
    private function sampleSensitiveRow(): array
    {
        $row = ['player_name' => 'Kid'];
        foreach (intersoccer_rr_medical_roster_field_keys() as $key) {
            $row[$key] = 'medical-' . $key;
        }
        foreach (intersoccer_rr_medical_dob_field_keys() as $key) {
            $row[$key] = '2015-06-01';
        }

        return $row;
    }

    public function test_case_a_medical_allowed_dob_denied_redacts_only_dob_keys(): void
    {
        $this->setSplitPermissionFilters(true, false);
        $this->assertTrue(intersoccer_rr_current_user_can_view_medical());
        $this->assertFalse(intersoccer_rr_current_user_can_view_dob());

        $out = intersoccer_rr_redact_medical_in_roster_row($this->sampleSensitiveRow());

        foreach (intersoccer_rr_medical_roster_field_keys() as $key) {
            $this->assertSame('medical-' . $key, $out[$key]);
        }
        foreach (intersoccer_rr_medical_dob_field_keys() as $key) {
            $this->assertSame('Restricted', $out[$key]);
        }
    }

    public function test_case_a_medical_allowed_dob_denied_strip_keeps_medical_removes_dob(): void
    {
        $this->setSplitPermissionFilters(true, false);

        $update = array_merge(
            ['parent_phone' => '123'],
            array_fill_keys(intersoccer_rr_medical_roster_field_keys(), 'keep-me'),
            array_fill_keys(intersoccer_rr_medical_dob_field_keys(), '1970-01-01')
        );

        $stripped = intersoccer_rr_strip_medical_from_update_data($update);

        $this->assertSame('123', $stripped['parent_phone']);
        foreach (intersoccer_rr_medical_roster_field_keys() as $key) {
            $this->assertSame('keep-me', $stripped[$key]);
        }
        foreach (intersoccer_rr_medical_dob_field_keys() as $key) {
            $this->assertArrayNotHasKey($key, $stripped);
        }
    }

    public function test_case_b_dob_allowed_medical_denied_redacts_only_medical_keys(): void
    {
        $this->setSplitPermissionFilters(false, true);
        $this->assertFalse(intersoccer_rr_current_user_can_view_medical());
        $this->assertTrue(intersoccer_rr_current_user_can_view_dob());

        $out = intersoccer_rr_redact_medical_in_roster_row($this->sampleSensitiveRow());

        foreach (intersoccer_rr_medical_roster_field_keys() as $key) {
            $this->assertSame('Restricted', $out[$key]);
        }
        foreach (intersoccer_rr_medical_dob_field_keys() as $key) {
            $this->assertSame('2015-06-01', $out[$key]);
        }
    }

    public function test_case_b_dob_allowed_medical_denied_strip_removes_medical_keeps_dob(): void
    {
        $this->setSplitPermissionFilters(false, true);

        $update = array_merge(
            ['parent_phone' => '123'],
            array_fill_keys(intersoccer_rr_medical_roster_field_keys(), 'drop-me'),
            array_fill_keys(intersoccer_rr_medical_dob_field_keys(), '2015-06-01')
        );

        $stripped = intersoccer_rr_strip_medical_from_update_data($update);

        $this->assertSame('123', $stripped['parent_phone']);
        foreach (intersoccer_rr_medical_roster_field_keys() as $key) {
            $this->assertArrayNotHasKey($key, $stripped);
        }
        foreach (intersoccer_rr_medical_dob_field_keys() as $key) {
            $this->assertSame('2015-06-01', $stripped[$key]);
        }
    }

    public function test_case_a_export_includes_medical_column_not_dob(): void
    {
        $this->setSplitPermissionFilters(true, false);

        $headers = intersoccer_rr_roster_export_personal_headers();
        $this->assertContains('Medical/Dietary Conditions', $headers);
        $this->assertNotContains('Date of Birth', $headers);

        $row = intersoccer_rr_roster_export_personal_row_values(
            ['first_name' => 'A', 'last_name' => 'B', 'gender' => 'Male', 'parent_email' => 'a@b.c', 'age' => 8, 'avs_number' => '1'],
            '079',
            '01/01/2015',
            'Peanuts'
        );
        $this->assertCount(count($headers), $row);
        $this->assertContains('Peanuts', $row);
        $this->assertNotContains('01/01/2015', $row);
    }

    public function test_case_b_export_includes_dob_column_not_medical(): void
    {
        $this->setSplitPermissionFilters(false, true);

        $headers = intersoccer_rr_roster_export_personal_headers();
        $this->assertContains('Date of Birth', $headers);
        $this->assertNotContains('Medical/Dietary Conditions', $headers);

        $row = intersoccer_rr_roster_export_personal_row_values(
            ['first_name' => 'A', 'last_name' => 'B', 'gender' => 'Male', 'parent_email' => 'a@b.c', 'age' => 8, 'avs_number' => '1'],
            '079',
            '01/01/2015',
            'Peanuts'
        );
        $this->assertCount(count($headers), $row);
        $this->assertContains('01/01/2015', $row);
        $this->assertNotContains('Peanuts', $row);
    }
}
