<?php

use PHPUnit\Framework\TestCase;

class AdminEnglishI18nTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/i18n.php';
        intersoccer_rr_register_admin_english_filters();
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        $GLOBALS['wp_locale_switcher'] = new class {
            public function is_switched() {
                return !empty($GLOBALS['intersoccer_test_is_locale_switched']);
            }
        };
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_wp_doing_ajax'],
            $GLOBALS['intersoccer_test_is_locale_switched'],
            $GLOBALS['wp_locale_switcher'],
            $_SERVER['HTTP_REFERER'],
            $GLOBALS['intersoccer_test_emit_wp_hooks']
        );
        parent::tearDown();
    }

    public function test_admin_context_forces_english_gettext(): void
    {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_wp_doing_ajax'] = false;

        $result = apply_filters(
            'gettext_intersoccer-reports-rosters',
            'Jours sélectionnés',
            'Selected Days',
            'intersoccer-reports-rosters'
        );

        $this->assertSame('Selected Days', $result);
    }

    public function test_frontend_ajax_keeps_translation(): void
    {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_wp_doing_ajax'] = true;
        $_SERVER['HTTP_REFERER'] = home_url('/shop/cart/');

        $result = apply_filters(
            'gettext_intersoccer-reports-rosters',
            'Jours sélectionnés',
            'Selected Days',
            'intersoccer-reports-rosters'
        );

        $this->assertSame('Jours sélectionnés', $result);
    }

    public function test_switched_locale_keeps_translation_in_admin(): void
    {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_wp_doing_ajax'] = false;
        $GLOBALS['intersoccer_test_is_locale_switched'] = true;

        $result = apply_filters(
            'gettext_intersoccer-reports-rosters',
            'Ausgewählte Tage',
            'Selected Days',
            'intersoccer-reports-rosters'
        );

        $this->assertSame('Ausgewählte Tage', $result);
    }

    public function test_admin_ajax_with_admin_referer_forces_english(): void
    {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_wp_doing_ajax'] = true;
        $_SERVER['HTTP_REFERER'] = admin_url('admin.php?page=intersoccer-rosters');

        $result = apply_filters(
            'gettext_intersoccer-reports-rosters',
            'Jours sélectionnés',
            'Selected Days',
            'intersoccer-reports-rosters'
        );

        $this->assertSame('Selected Days', $result);
    }

    public function test_is_admin_english_context_respects_filter_override(): void
    {
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value) {
            if ($hook === 'intersoccer_rr_admin_force_english') {
                return true;
            }
            return $value;
        };

        $this->assertTrue(intersoccer_rr_is_admin_english_context());

        unset($GLOBALS['intersoccer_test_apply_filters_callback']);
    }

    public function test_gettext_filter_safe_when_locale_switcher_missing(): void
    {
        unset($GLOBALS['wp_locale_switcher']);
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_wp_doing_ajax'] = false;

        $result = apply_filters(
            'gettext_intersoccer-reports-rosters',
            'Jours sélectionnés',
            'Selected Days',
            'intersoccer-reports-rosters'
        );

        $this->assertSame('Selected Days', $result);
    }

    public function test_gettext_with_context_safe_when_locale_switcher_missing(): void
    {
        unset($GLOBALS['wp_locale_switcher']);
        $GLOBALS['intersoccer_test_is_admin'] = true;

        $result = apply_filters(
            'gettext_with_context_intersoccer-reports-rosters',
            'Jours sélectionnés',
            'Selected Days',
            'column header',
            'intersoccer-reports-rosters'
        );

        $this->assertSame('Selected Days', $result);
    }

    public function test_is_locale_switched_false_without_switcher_global(): void
    {
        unset($GLOBALS['wp_locale_switcher']);
        $this->assertFalse(intersoccer_rr_is_locale_switched());
    }
}
