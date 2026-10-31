<?php
/**
 * RR #104 QA: roster rebuild uses migration blank-field variation fill (via RosterBuilder hook).
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterBuilderMigrationBlankFillTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_wc_get_product_callback']);
        parent::tearDown();
    }

    private function registerGenevaTerms(): void {
        $GLOBALS['intersoccer_test_terms_by_slug'] = [
            'pa_booking-type' => [
                'full-week' => (object) ['term_id' => 501, 'slug' => 'full-week', 'name' => 'Full Week'],
            ],
            'pa_city' => [
                'geneva' => (object) ['term_id' => 502, 'slug' => 'geneva', 'name' => 'Geneva'],
            ],
        ];
        $GLOBALS['intersoccer_test_terms_by_id'] = [
            501 => (object) ['term_id' => 501, 'slug' => 'full-week', 'name' => 'Full Week'],
            502 => (object) ['term_id' => 502, 'slug' => 'geneva', 'name' => 'Geneva'],
        ];
        $GLOBALS['intersoccer_test_wpml_term_map'] = [];
    }

    private function stubVariation(int $variation_id, array $attrs): \WC_Product_Variation {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($variation_id);
        $variation->shouldReceive('get_parent_id')->andReturn(900);
        $variation->shouldReceive('get_variation_attributes')->andReturn($attrs);
        $variation->shouldReceive('get_attribute')->andReturnUsing(static function ($name) use ($attrs) {
            $key = 'attribute_' . ltrim((string) $name, 'attribute_');
            if (isset($attrs[$key])) {
                return $attrs[$key];
            }
            if (isset($attrs[$name])) {
                return $attrs[$name];
            }

            return '';
        });
        $variation->shouldReceive('get_attributes')->andReturn([]);

        return $variation;
    }

    public function test_apply_blank_roster_fill_fills_booking_type_and_city_from_variation(): void {
        $this->registerGenevaTerms();
        $variation = $this->stubVariation(88001, [
            'attribute_pa_booking-type' => 'full-week',
            'attribute_pa_city' => 'geneva',
            'attribute_pa_girls-only' => 'no',
        ]);

        $row = [
            'activity_type' => 'Camp',
            'booking_type' => '',
            'city' => '',
            'girls_only' => 0,
        ];

        $filled = intersoccer_migration_apply_blank_roster_fill_from_variation($row, $variation);

        $this->assertSame('Full Week', $filled['booking_type']);
        $this->assertSame('Geneva', $filled['city']);
    }

    public function test_apply_blank_roster_fill_does_not_overwrite_existing_booking_and_city(): void {
        $this->registerGenevaTerms();
        $variation = $this->stubVariation(88002, [
            'attribute_pa_booking-type' => 'full-week',
            'attribute_pa_city' => 'geneva',
        ]);

        $row = [
            'activity_type' => 'Camp',
            'booking_type' => 'Single Day(s)',
            'city' => 'Zurich',
        ];

        $filled = intersoccer_migration_apply_blank_roster_fill_from_variation($row, $variation);

        $this->assertSame('Single Day(s)', $filled['booking_type']);
        $this->assertSame('Zurich', $filled['city']);
    }

    public function test_roster_builder_class_calls_migration_blank_fill_helper(): void {
        $path = dirname(__DIR__, 2) . '/classes/services/roster-builder.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->assertStringContainsString(
            'intersoccer_migration_apply_blank_roster_fill_from_variation',
            $source
        );
    }
}
