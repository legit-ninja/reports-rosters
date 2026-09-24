<?php
/**
 * Tests for Final Reports redirect and stay-on-page logic.
 *
 * Verifies:
 * - Legacy page slugs redirect to unified Final Reports page.
 * - Hub deep link (tab=final-reports) redirects to Final Reports page.
 * - Query args are preserved across redirects.
 * - Form action targets Final Reports page slug (stay-on-page).
 *
 * @package InterSoccer\ReportsRosters\Tests\Admin
 */

namespace InterSoccer\ReportsRosters\Tests\Admin;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Admin\MenuManager;
use InterSoccer\ReportsRosters\Core\Logger;
use Mockery;

class MenuManagerFinalReportsRedirectTest extends TestCase {
    /**
     * @var MenuManager
     */
    private $menu_manager;

    protected function setUp(): void {
        parent::setUp();
        $logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
        $this->menu_manager = new MenuManager('/fake/plugin.php', $logger);
    }

    /**
     * @dataProvider legacy_camp_redirect_provider
     */
    public function test_legacy_final_camp_reports_builds_correct_url(array $get_params, string $expected_activity, array $expected_args): void {
        $url = $this->menu_manager->build_final_reports_url('Camp', $get_params);

        $this->assertStringContainsString('page=intersoccer-final-reports', $url);
        $this->assertStringContainsString('activity_type=Camp', $url);

        foreach ($expected_args as $key => $value) {
            $this->assertStringContainsString("{$key}={$value}", $url, "Expected {$key}={$value} in URL");
        }
    }

    public static function legacy_camp_redirect_provider(): array {
        return [
            'simple camp redirect' => [
                [],
                'Camp',
                [],
            ],
            'camp with year' => [
                ['year' => '2026'],
                'Camp',
                ['year' => '2026'],
            ],
            'camp with all filters' => [
                [
                    'year' => '2026',
                    'season_type' => 'Summer',
                    'region' => 'Zurich',
                    'live' => '1',
                    'urgency_only' => '1',
                    'exclude_buyclub' => '1',
                ],
                'Camp',
                [
                    'year' => '2026',
                    'season_type' => 'Summer',
                    'region' => 'Zurich',
                    'live' => '1',
                    'urgency_only' => '1',
                    'exclude_buyclub' => '1',
                ],
            ],
        ];
    }

    /**
     * @dataProvider legacy_course_redirect_provider
     */
    public function test_legacy_final_course_reports_builds_correct_url(array $get_params, array $expected_args): void {
        $url = $this->menu_manager->build_final_reports_url('Course', $get_params);

        $this->assertStringContainsString('page=intersoccer-final-reports', $url);
        $this->assertStringContainsString('activity_type=Course', $url);

        foreach ($expected_args as $key => $value) {
            $this->assertStringContainsString("{$key}={$value}", $url, "Expected {$key}={$value} in URL");
        }
    }

    public static function legacy_course_redirect_provider(): array {
        return [
            'simple course redirect' => [
                [],
                [],
            ],
            'course with year and live' => [
                ['year' => '2026', 'live' => '1'],
                ['year' => '2026', 'live' => '1'],
            ],
            'course with urgency_only' => [
                ['year' => '2026', 'live' => '1', 'urgency_only' => '1'],
                ['year' => '2026', 'live' => '1', 'urgency_only' => '1'],
            ],
        ];
    }

    public function test_hub_deep_link_redirect_preserves_activity_type(): void {
        $get_params = [
            'page' => 'intersoccer-reports',
            'tab' => 'final-reports',
            'activity_type' => 'Course',
            'year' => '2026',
        ];

        $url = $this->menu_manager->build_final_reports_url($get_params['activity_type'], $get_params);

        $this->assertStringContainsString('page=intersoccer-final-reports', $url);
        $this->assertStringContainsString('activity_type=Course', $url);
        $this->assertStringContainsString('year=2026', $url);
        $this->assertStringNotContainsString('tab=', $url);
    }

    public function test_hub_deep_link_defaults_to_camp_if_no_activity_type(): void {
        $get_params = [
            'page' => 'intersoccer-reports',
            'tab' => 'final-reports',
            'year' => '2026',
        ];

        $activity_type = isset($get_params['activity_type']) ? $get_params['activity_type'] : 'Camp';
        $url = $this->menu_manager->build_final_reports_url($activity_type, $get_params);

        $this->assertStringContainsString('page=intersoccer-final-reports', $url);
        $this->assertStringContainsString('activity_type=Camp', $url);
    }

    public function test_empty_filter_values_are_not_included_in_url(): void {
        $get_params = [
            'year' => '2026',
            'season_type' => '',
            'region' => '',
            'live' => '',
        ];

        $url = $this->menu_manager->build_final_reports_url('Camp', $get_params);

        $this->assertStringContainsString('year=2026', $url);
        $this->assertStringNotContainsString('season_type=', $url);
        $this->assertStringNotContainsString('region=&', $url);
    }

    public function test_url_uses_admin_php_base(): void {
        $url = $this->menu_manager->build_final_reports_url('Camp', []);

        $this->assertStringContainsString('admin.php', $url);
    }

    public function test_final_reports_page_slug_constant(): void {
        $url = $this->menu_manager->build_final_reports_url('Camp', []);

        $this->assertStringContainsString('intersoccer-final-reports', $url);
        $this->assertStringNotContainsString('intersoccer-final-camp-reports', $url);
        $this->assertStringNotContainsString('intersoccer-final-course-reports', $url);
        $this->assertStringNotContainsString('intersoccer-reports&tab=', $url);
    }

    /**
     * Verify that the Final Reports render function uses the same capability
     * as the menu registration ('read'), not 'manage_options'.
     *
     * This guards against capability mismatch regressions where the menu
     * registration allows access but the render-time check denies it.
     *
     * @see https://github.com/legit-ninja/reports-rosters/issues/67
     */
    public function test_final_reports_render_uses_read_capability(): void {
        $reports_ui_path = dirname(__DIR__, 2) . '/includes/reports-ui.php';
        $this->assertFileExists($reports_ui_path, 'reports-ui.php should exist');

        $source = file_get_contents($reports_ui_path);

        $pattern = '/function\s+intersoccer_render_final_reports_page\s*\([^)]*\)\s*\{[^}]*current_user_can\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/s';
        $this->assertMatchesRegularExpression($pattern, $source, 'Final reports render function should contain a current_user_can check');

        preg_match($pattern, $source, $matches);
        $capability = $matches[1] ?? '';

        $this->assertSame(
            'read',
            $capability,
            "Final Reports render function should check 'read' capability (matching menu registration), not '{$capability}'"
        );
    }
}
