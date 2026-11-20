<?php
/**
 * Campaign Analytics menu href and current-item highlighting.
 *
 * The page is registered with a null parent, so a bare submenu slug is
 * rendered as wp-admin/<slug>. Slot [2] must be the admin.php?page= URL.
 *
 * @package InterSoccer\ReportsRosters\Tests\Admin
 */

namespace InterSoccer\ReportsRosters\Tests\Admin;

use InterSoccer\ReportsRosters\Admin\MenuManager;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MenuManagerCampaignAnalyticsMenuTest extends TestCase {

    /**
     * @var MenuManager
     */
    private $menu_manager;

    protected function setUp(): void {
        parent::setUp();
        $logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
        $this->menu_manager = new MenuManager('/fake/plugin.php', $logger);
    }

    protected function tearDown(): void {
        unset($GLOBALS['plugin_page'], $_GET['page']);
        parent::tearDown();
    }

    public function test_inject_uses_admin_php_href_and_drops_bare_slug_row(): void {
        global $submenu;

        $analytics = 'wc-admin&path=/analytics/overview';
        $submenu = [
            $analytics => [
                ['Overview', 'view_woocommerce_reports', 'wc-admin&path=/analytics/overview', 'Overview'],
            ],
            '' => [
                ['Campaign Analytics', 'manage_options', 'intersoccer-campaign-analytics', 'Campaign Analytics'],
                ['Win-back report', 'manage_options', 'intersoccer-winback-report', 'Win-back report'],
            ],
        ];

        $this->menu_manager->inject_campaign_analytics_into_wc_analytics_submenu();

        $this->assertCount(2, $submenu[$analytics]);
        $injected = $submenu[$analytics][1];
        $this->assertSame('admin.php?page=intersoccer-campaign-analytics', $injected[2]);
        $this->assertNotSame('intersoccer-campaign-analytics', $injected[2]);
        $this->assertSame('manage_options', $injected[1]);

        $remaining = array_values($submenu['']);
        $this->assertCount(1, $remaining);
        $this->assertSame('intersoccer-winback-report', $remaining[0][2]);
    }

    public function test_inject_noop_when_analytics_submenu_missing(): void {
        global $submenu;

        $submenu = [
            'index.php' => [
                ['Home', 'read', 'index.php', 'Dashboard'],
            ],
        ];
        $before = $submenu;

        $this->menu_manager->inject_campaign_analytics_into_wc_analytics_submenu();

        $this->assertSame($before, $submenu);
        $this->assertArrayNotHasKey('wc-admin&path=/analytics/overview', $submenu);
    }

    public function test_inject_noop_when_submenu_global_is_unset(): void {
        global $submenu;

        $submenu = null;

        $this->menu_manager->inject_campaign_analytics_into_wc_analytics_submenu();

        $this->assertNull($submenu);
    }

    public function test_highlight_filters_select_analytics_parent_and_menu_href(): void {
        $GLOBALS['plugin_page'] = 'intersoccer-campaign-analytics';
        unset($_GET['page']);

        $this->assertSame(
            'wc-admin&path=/analytics/overview',
            $this->menu_manager->filter_campaign_analytics_parent_file('index.php')
        );
        $this->assertSame(
            'admin.php?page=intersoccer-campaign-analytics',
            $this->menu_manager->filter_campaign_analytics_submenu_file('index.php', 'index.php')
        );
    }

    public function test_highlight_filters_use_request_page_when_plugin_page_unset(): void {
        unset($GLOBALS['plugin_page']);
        $_GET['page'] = 'intersoccer-campaign-analytics';

        $this->assertSame(
            'wc-admin&path=/analytics/overview',
            $this->menu_manager->filter_campaign_analytics_parent_file('')
        );
        $this->assertSame(
            'admin.php?page=intersoccer-campaign-analytics',
            $this->menu_manager->filter_campaign_analytics_submenu_file(null)
        );
    }

    public function test_highlight_filters_leave_other_menus_unchanged(): void {
        $GLOBALS['plugin_page'] = 'intersoccer-reports';
        $_GET['page'] = 'intersoccer-campaign-analytics';

        $this->assertSame(
            'woocommerce',
            $this->menu_manager->filter_campaign_analytics_parent_file('woocommerce')
        );
        $this->assertSame(
            'wc-admin&path=/analytics/overview',
            $this->menu_manager->filter_campaign_analytics_submenu_file('wc-admin&path=/analytics/overview', 'woocommerce')
        );
    }
}
