<?php

use PHPUnit\Framework\TestCase;

class MedicalAccessTest extends TestCase
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
        intersoccer_test_ensure_pm_filter_stub();
        require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
    }

    public function test_admin_manage_options_can_view_medical(): void
    {
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $this->assertTrue(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_shop_manager_can_view_medical(): void
    {
        intersoccer_test_set_user_caps(['manage_woocommerce' => true]);
        $this->assertTrue(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_coach_only_role_denied(): void
    {
        intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
        $this->assertFalse(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_filter_can_grant_medical_access(): void
    {
        intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
        $GLOBALS['intersoccer_medical_access_test_filters'] = [
            function ($allowed, $user_id) {
                return true;
            },
        ];
        $this->assertTrue(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_filter_truthy_without_strict_true_does_not_grant(): void
    {
        intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
        $GLOBALS['intersoccer_medical_access_test_filters'] = [
            function ($allowed, $user_id) {
                return 1;
            },
        ];
        $this->assertFalse(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_filter_can_revoke_medical_access(): void
    {
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $GLOBALS['intersoccer_medical_access_test_filters'] = [
            function ($allowed, $user_id) {
                return false;
            },
        ];
        $this->assertFalse(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_coach_view_shows_restricted_not_secret_medical(): void
    {
        intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
        $display = intersoccer_rr_format_medical_display_value('SECRET peanut allergy');
        $this->assertSame('Restricted', $display);
        $this->assertStringNotContainsString('peanut', strtolower($display));
        $this->assertStringNotContainsString('SECRET', $display);
    }

    public function test_allowed_user_empty_medical_shows_na(): void
    {
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $this->assertSame('N/A', intersoccer_rr_format_medical_display_value(''));
    }

    public function test_camp_medical_cell_states(): void
    {
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $none = intersoccer_rr_camp_medical_cell('');
        $this->assertSame('none', $none['state']);

        intersoccer_test_set_user_caps(['coach' => true]);
        $restricted = intersoccer_rr_camp_medical_cell('Asthma');
        $this->assertSame('restricted', $restricted['state']);
        $this->assertSame('Restricted', $restricted['text']);
    }

    public function test_redact_row_replaces_medical_fields_for_denied_user(): void
    {
        intersoccer_test_set_user_caps(['coach' => true]);
        $row = [
            'player_name' => 'Kid',
            'medical_conditions' => 'Asthma',
            'player_dietary' => 'Gluten free',
            'player_dob' => '2015-01-01',
        ];
        $out = intersoccer_rr_redact_medical_in_roster_row($row);
        $this->assertSame('Restricted', $out['medical_conditions']);
        $this->assertSame('Restricted', $out['player_dietary']);
        $this->assertSame('Restricted', $out['player_dob']);
        $this->assertSame('Kid', $out['player_name']);
    }

    public function test_strip_medical_removes_fields_on_save_for_denied_user(): void
    {
        intersoccer_test_set_user_caps(['coach' => true]);
        $update = [
            'parent_phone' => '123',
            'medical_conditions' => '',
            'player_medical' => 'Hacked',
            'player_dob' => '1970-01-01',
        ];
        $stripped = intersoccer_rr_strip_medical_from_update_data($update);
        $this->assertSame(['parent_phone' => '123'], $stripped);
    }
}
