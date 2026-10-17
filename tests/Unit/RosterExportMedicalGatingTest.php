<?php

use PHPUnit\Framework\TestCase;

class RosterExportMedicalGatingTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_current_user'],
            $GLOBALS['intersoccer_medical_access_test_filters']
        );
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
    }

    public function test_denied_user_export_headers_omit_dob_and_medical(): void
    {
        intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
        $headers = intersoccer_rr_roster_export_personal_headers();
        $this->assertNotContains('Date of Birth', $headers);
        $this->assertNotContains('Medical/Dietary Conditions', $headers);
        $this->assertSame('AVS Number', $headers[array_key_last($headers)]);
    }

    public function test_allowed_user_export_headers_include_dob_and_medical(): void
    {
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $headers = intersoccer_rr_roster_export_personal_headers();
        $this->assertContains('Date of Birth', $headers);
        $this->assertContains('Medical/Dietary Conditions', $headers);
        $row = intersoccer_rr_roster_export_personal_row_values(
            ['first_name' => 'A', 'last_name' => 'B', 'gender' => 'Male', 'parent_email' => 'a@b.c', 'age' => 8, 'avs_number' => '1'],
            '079',
            '01/01/2015',
            'Peanuts'
        );
        $this->assertCount(count($headers), $row);
        $this->assertSame('01/01/2015', $row[6]);
        $this->assertSame('Peanuts', $row[7]);
    }

    public function test_denied_user_export_row_has_no_sensitive_cells(): void
    {
        intersoccer_test_set_user_caps(['coach' => true]);
        $headers = intersoccer_rr_roster_export_personal_headers();
        $row = intersoccer_rr_roster_export_personal_row_values(
            ['first_name' => 'A', 'last_name' => 'B', 'gender' => 'Male', 'parent_email' => 'a@b.c', 'age' => 8, 'avs_number' => '1'],
            '079',
            '01/01/2015',
            'Secret'
        );
        $this->assertCount(count($headers), $row);
        $this->assertNotContains('01/01/2015', $row);
        $this->assertNotContains('Secret', $row);
    }
}
