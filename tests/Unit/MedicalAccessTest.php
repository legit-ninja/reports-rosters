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
        if (!function_exists('get_current_user_id')) {
            function get_current_user_id() {
                $user = wp_get_current_user();
                return isset($user->ID) ? (int) $user->ID : 0;
            }
        }
        if (!function_exists('__')) {
            function __($text, $domain = null) {
                return $text;
            }
        }
        require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
    }

    private function setCaps(array $caps): void
    {
        $GLOBALS['intersoccer_test_current_user_caps'] = $caps;
        $user = new stdClass();
        $user->ID = 42;
        $user->roles = !empty($caps['coach']) ? ['coach'] : [];
        $GLOBALS['intersoccer_test_current_user'] = $user;
    }

    public function test_admin_manage_options_can_view_medical(): void
    {
        $this->setCaps(['manage_options' => true]);
        $this->assertTrue(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_shop_manager_can_view_medical(): void
    {
        $this->setCaps(['manage_woocommerce' => true]);
        $this->assertTrue(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_coach_only_role_denied(): void
    {
        $this->setCaps(['coach' => true, 'read' => true]);
        $this->assertFalse(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_filter_can_grant_medical_access(): void
    {
        $this->setCaps(['coach' => true, 'read' => true]);
        $GLOBALS['intersoccer_medical_access_test_filters'] = [
            function ($allowed, $user_id) {
                return true;
            },
        ];
        $this->assertTrue(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_filter_can_revoke_medical_access(): void
    {
        $this->setCaps(['manage_options' => true]);
        $GLOBALS['intersoccer_medical_access_test_filters'] = [
            function ($allowed, $user_id) {
                return false;
            },
        ];
        $this->assertFalse(intersoccer_rr_current_user_can_view_medical());
    }

    public function test_coach_view_shows_restricted_not_secret_medical(): void
    {
        $this->setCaps(['coach' => true, 'read' => true]);
        $display = intersoccer_rr_format_medical_display_value('SECRET peanut allergy');
        $this->assertSame('Restricted', $display);
        $this->assertStringNotContainsString('peanut', strtolower($display));
        $this->assertStringNotContainsString('SECRET', $display);
    }

    public function test_redact_row_replaces_medical_fields_for_denied_user(): void
    {
        $this->setCaps(['coach' => true]);
        $row = [
            'player_name' => 'Kid',
            'medical_conditions' => 'Asthma',
            'player_dietary' => 'Gluten free',
        ];
        $out = intersoccer_rr_redact_medical_in_roster_row($row);
        $this->assertSame('Restricted', $out['medical_conditions']);
        $this->assertSame('Restricted', $out['player_dietary']);
        $this->assertSame('Kid', $out['player_name']);
    }

    public function test_strip_medical_preserves_existing_on_save_for_denied_user(): void
    {
        $this->setCaps(['coach' => true]);
        $existing = [
            'medical_conditions' => 'Keep me',
            'player_medical' => 'Keep me',
        ];
        $update = [
            'parent_phone' => '123',
            'medical_conditions' => '',
            'player_medical' => 'Hacked',
        ];
        $stripped = intersoccer_rr_strip_medical_from_update_data($update, $existing);
        $this->assertSame(['parent_phone' => '123'], $stripped);
    }
}
