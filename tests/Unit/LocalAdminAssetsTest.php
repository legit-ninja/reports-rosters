<?php

use PHPUnit\Framework\TestCase;

class LocalAdminAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['intersoccer_test_registered_scripts'] = [];
        $GLOBALS['intersoccer_test_registered_styles']  = [];

        if (!function_exists('wp_register_script')) {
            function wp_register_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false) {
                $GLOBALS['intersoccer_test_registered_scripts'][$handle] = [
                    'src' => $src,
                    'ver' => $ver,
                ];
            }
        }
        if (!function_exists('wp_register_style')) {
            function wp_register_style($handle, $src = '', $deps = [], $ver = false, $media = 'all') {
                $GLOBALS['intersoccer_test_registered_styles'][$handle] = [
                    'src' => $src,
                    'ver' => $ver,
                ];
            }
        }

        require_once dirname(__DIR__, 2) . '/includes/local-admin-assets.php';
    }

    public function test_chart_js_registered_from_plugin_path(): void
    {
        intersoccer_rr_register_local_admin_vendor_assets();

        $this->assertArrayHasKey('chart-js', $GLOBALS['intersoccer_test_registered_scripts']);
        $src = $GLOBALS['intersoccer_test_registered_scripts']['chart-js']['src'];
        $this->assertStringContainsString('js/vendor/chart.js/chart.min.js', $src);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $src);
        $this->assertSame('3.9.1', $GLOBALS['intersoccer_test_registered_scripts']['chart-js']['ver']);
    }

    public function test_jquery_ui_theme_registered_from_plugin_path(): void
    {
        intersoccer_rr_register_local_admin_vendor_assets();

        $this->assertArrayHasKey('jquery-ui-css', $GLOBALS['intersoccer_test_registered_styles']);
        $src = $GLOBALS['intersoccer_test_registered_styles']['jquery-ui-css']['src'];
        $this->assertStringContainsString('css/vendor/jquery-ui/jquery-ui.css', $src);
        $this->assertStringNotContainsString('code.jquery.com', $src);
        $this->assertSame('1.12.1', $GLOBALS['intersoccer_test_registered_styles']['jquery-ui-css']['ver']);
    }
}
