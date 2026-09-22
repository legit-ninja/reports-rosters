<?php
/**
 * Tests for MenuManager Final Numbers redirect URL building.
 *
 * @package InterSoccer\ReportsRosters\Tests\Admin
 */

namespace InterSoccer\ReportsRosters\Tests\Admin;

use InterSoccer\ReportsRosters\Admin\MenuManager;
use InterSoccer\ReportsRosters\Tests\TestCase;

class MenuManagerFinalNumbersRedirectTest extends TestCase {

    private MenuManager $manager;

    protected function setUp(): void {
        parent::setUp();
        $this->manager = new MenuManager(__DIR__ . '/../../intersoccer-reports-rosters.php');
    }

    public function test_camp_redirect_maps_activity_type(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', []);
        $this->assertStringContainsString('page=intersoccer-reports', $url);
        $this->assertStringContainsString('tab=final-reports', $url);
        $this->assertStringContainsString('activity_type=Camp', $url);
    }

    public function test_course_redirect_maps_activity_type(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-course-reports', []);
        $this->assertStringContainsString('page=intersoccer-reports', $url);
        $this->assertStringContainsString('tab=final-reports', $url);
        $this->assertStringContainsString('activity_type=Course', $url);
    }

    public function test_preserves_year_param(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', ['year' => '2025']);
        $this->assertStringContainsString('year=2025', $url);
    }

    public function test_preserves_live_mode(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', ['live' => '1']);
        $this->assertStringContainsString('live=1', $url);
    }

    public function test_preserves_region_filter(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', ['region' => 'Zurich']);
        $this->assertStringContainsString('region=Zurich', $url);
    }

    public function test_preserves_season_type(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', ['season_type' => 'Summer']);
        $this->assertStringContainsString('season_type=Summer', $url);
    }

    public function test_preserves_urgency_only(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', ['urgency_only' => '1']);
        $this->assertStringContainsString('urgency_only=1', $url);
    }

    public function test_preserves_exclude_buyclub(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', ['exclude_buyclub' => '1']);
        $this->assertStringContainsString('exclude_buyclub=1', $url);
    }

    public function test_preserves_multiple_params(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', [
            'year' => '2025',
            'season_type' => 'Summer',
            'live' => '1',
            'urgency_only' => '1',
        ]);
        $this->assertStringContainsString('year=2025', $url);
        $this->assertStringContainsString('season_type=Summer', $url);
        $this->assertStringContainsString('live=1', $url);
        $this->assertStringContainsString('urgency_only=1', $url);
        $this->assertStringContainsString('activity_type=Camp', $url);
    }

    public function test_ignores_unknown_params(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', [
            'year' => '2025',
            'unknown_param' => 'should_be_ignored',
        ]);
        $this->assertStringNotContainsString('unknown_param', $url);
    }

    public function test_ignores_empty_params(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', [
            'year' => '2025',
            'region' => '',
        ]);
        $this->assertStringNotContainsString('region=', $url);
    }

    public function test_does_not_preserve_original_page_param(): void {
        $url = $this->manager->build_final_numbers_hub_url('intersoccer-final-camp-reports', [
            'page' => 'intersoccer-final-camp-reports',
            'year' => '2025',
        ]);
        $this->assertStringNotContainsString('page=intersoccer-final-camp-reports', $url);
        $this->assertStringContainsString('page=intersoccer-reports', $url);
    }
}
