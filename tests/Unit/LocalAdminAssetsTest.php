<?php

use PHPUnit\Framework\TestCase;

class LocalAdminAssetsTest extends TestCase
{
    private string $pluginRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginRoot = dirname(__DIR__, 2);
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

        require_once $this->pluginRoot . '/includes/local-admin-assets.php';
    }

    private function assertNoExternalAssetHost(string $src): void
    {
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $src);
        $this->assertStringNotContainsString('code.jquery.com', $src);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $src);
        if (preg_match('#^https?://([^/]+)/#', $src, $m)) {
            $host = $m[1];
            $this->assertMatchesRegularExpression('/example\.(com|test)$/', $host, 'Unexpected external host: ' . $host);
        }
    }

    public function test_chart_js_registered_from_plugin_path(): void
    {
        intersoccer_rr_register_local_admin_vendor_assets();

        $handle = 'intersoccer-rr-chart-js';
        $main = intersoccer_rr_plugin_main_file();
        $this->assertArrayHasKey($handle, $GLOBALS['intersoccer_test_registered_scripts']);
        $src = $GLOBALS['intersoccer_test_registered_scripts'][$handle]['src'];
        $this->assertSame(plugins_url('js/vendor/chart.js/chart.min.js', $main), $src);
        $this->assertStringContainsString('js/vendor/chart.js/chart.min.js', $src);
        $this->assertNoExternalAssetHost($src);
        $this->assertFileExists($this->pluginRoot . '/js/vendor/chart.js/chart.min.js');
        $this->assertSame('3.9.1', $GLOBALS['intersoccer_test_registered_scripts'][$handle]['ver']);
    }

    public function test_jquery_ui_theme_registered_from_plugin_path(): void
    {
        intersoccer_rr_register_local_admin_vendor_assets();

        $handle = 'intersoccer-rr-jquery-ui-css';
        $main = intersoccer_rr_plugin_main_file();
        $this->assertArrayHasKey($handle, $GLOBALS['intersoccer_test_registered_styles']);
        $src = $GLOBALS['intersoccer_test_registered_styles'][$handle]['src'];
        $this->assertSame(plugins_url('css/vendor/jquery-ui/jquery-ui.css', $main), $src);
        $this->assertStringContainsString('css/vendor/jquery-ui/jquery-ui.css', $src);
        $this->assertNoExternalAssetHost($src);
        $this->assertFileExists($this->pluginRoot . '/css/vendor/jquery-ui/jquery-ui.css');
        $this->assertFileExists($this->pluginRoot . '/css/vendor/jquery-ui/images/ui-icons_444444_256x240.png');
        $this->assertSame('1.12.1', $GLOBALS['intersoccer_test_registered_styles'][$handle]['ver']);
    }
}
