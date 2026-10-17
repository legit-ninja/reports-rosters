<?php

use PHPUnit\Framework\TestCase;

class PlayerCampStatusDobGatingTest extends TestCase
{
    private const SAMPLE_DOB = '2015-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        intersoccer_test_ensure_pm_filter_stub();
        require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
        require_once dirname(__DIR__, 2) . '/includes/player-camp-status.php';
        intersoccer_player_camp_status_reset_dob_gate_cache();
    }

    protected function tearDown(): void
    {
        intersoccer_player_camp_status_reset_dob_gate_cache();
        unset(
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_current_user'],
            $GLOBALS['intersoccer_medical_access_test_filters']
        );
        parent::tearDown();
    }

    private function sampleJoinedRow(): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'user_email' => 'ada@example.com',
            'user_id' => 10,
            'dob' => self::SAMPLE_DOB,
            'age' => 11,
            'booked' => true,
            'bookings_display' => 'Camp A · Geneva',
        ];
    }

    public function test_page_dob_display_denied_shows_restricted_not_raw_value(): void
    {
        intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
        $display = intersoccer_player_camp_status_format_dob_for_display(self::SAMPLE_DOB);
        $this->assertSame('Restricted', $display);
        $this->assertNotSame(self::SAMPLE_DOB, $display);
    }

    public function test_page_dob_display_allowed_shows_raw_value(): void
    {
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $this->assertSame(self::SAMPLE_DOB, intersoccer_player_camp_status_format_dob_for_display(self::SAMPLE_DOB));
    }

    public function test_export_denied_omits_dob_column_and_values(): void
    {
        intersoccer_test_set_user_caps(['coach' => true]);
        $headers = intersoccer_player_camp_status_export_headers();
        $this->assertNotContains('DOB', $headers);
        $row = intersoccer_player_camp_status_export_row($this->sampleJoinedRow());
        $this->assertCount(count($headers), $row);
        $this->assertNotContains(self::SAMPLE_DOB, $row);
    }

    public function test_export_allowed_includes_dob_column_and_value(): void
    {
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $headers = intersoccer_player_camp_status_export_headers();
        $this->assertContains('DOB', $headers);
        $row = intersoccer_player_camp_status_export_row($this->sampleJoinedRow());
        $this->assertCount(count($headers), $row);
        $dob_index = array_search('DOB', $headers, true);
        $this->assertSame(self::SAMPLE_DOB, $row[$dob_index]);
    }
}
