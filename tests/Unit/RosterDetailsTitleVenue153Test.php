<?php
/**
 * Regression: roster details title uses the variation venue, not the first row (#153).
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterDetailsTitleVenue153Test extends TestCase {

    private const VARIATION_ID = 4401;

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/roster-details.php';
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_wc_get_product_callback']);
        unset($GLOBALS['intersoccer_test_terms_by_slug']);
        unset($GLOBALS['intersoccer_test_terms_by_id']);
        unset($GLOBALS['intersoccer_test_terms_by_name']);
        parent::tearDown();
    }

    public function test_title_uses_variation_venue_when_first_row_differs(): void {
        $this->registerVenueTerm('north-pitch', 'North Pitch');
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) {
            if ((int) $id !== self::VARIATION_ID) {
                return false;
            }
            $product = new class {
                public function get_attribute($name) {
                    if ($name === 'pa_intersoccer-venues') {
                        return 'north-pitch';
                    }
                    return '';
                }
            };
            return $product;
        };

        $first = (object) [
            'variation_id' => self::VARIATION_ID,
            'venue' => 'South Hall',
            'player_name' => 'Example One',
        ];
        $second = (object) [
            'variation_id' => self::VARIATION_ID,
            'venue' => 'North Pitch',
            'player_name' => 'Example Two',
        ];

        $title_venue = intersoccer_roster_details_title_venue(
            self::VARIATION_ID,
            [],
            [$first, $second],
            '',
            $first
        );

        $this->assertSame('North Pitch', $title_venue);
        $this->assertSame('South Hall', $first->venue);
        $this->assertSame('North Pitch', $second->venue);
        $this->assertStringNotContainsString('South Hall', $title_venue);
    }

    public function test_page_assigns_title_venue_from_the_helper(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/roster-details.php');
        $this->assertStringContainsString('$display_venue = intersoccer_roster_details_title_venue(', $source);
        $this->assertStringNotContainsString('$display_venue = $base_roster->venue', $source);
        $this->assertStringContainsString("esc_html(\$display_venue)", $source);
    }

    private function registerVenueTerm(string $slug, string $name): void {
        $term = (object) [
            'term_id' => 44010,
            'slug' => $slug,
            'name' => $name,
        ];
        $GLOBALS['intersoccer_test_terms_by_slug']['pa_intersoccer-venues'] = [
            $slug => $term,
        ];
        $GLOBALS['intersoccer_test_terms_by_id'][44010] = $term;
        $GLOBALS['intersoccer_test_terms_by_name']['pa_intersoccer-venues'] = [
            strtolower($name) => $term,
        ];
    }
}
