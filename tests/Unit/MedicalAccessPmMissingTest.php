<?php

use PHPUnit\Framework\TestCase;

/**
 * PM absent: intersoccer_user_can_view_medical must not exist in this process.
 */
class MedicalAccessPmMissingTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_admin_and_shop_manager_denied_when_pm_filter_function_missing(): void
    {
        $this->assertFalse(function_exists('intersoccer_user_can_view_medical'));

        require_once dirname(__DIR__, 2) . '/includes/medical-access.php';

        intersoccer_test_set_user_caps(['manage_options' => true]);
        $this->assertFalse(intersoccer_rr_current_user_can_view_medical());

        intersoccer_test_set_user_caps(['manage_woocommerce' => true]);
        $this->assertFalse(intersoccer_rr_current_user_can_view_medical());
    }
}
