<?php
/**
 * Migration and roster language normalization tests.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey\Functions;

class MigrationLanguageNormalizationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_normalize_roster_facets_for_storage')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }
    }

    public function test_format_attribute_for_storage_uses_get_term_name() {
        if (!function_exists('intersoccer_format_attribute_for_storage')) {
            $this->markTestSkipped('intersoccer_format_attribute_for_storage not found');
        }

        Functions\when('taxonomy_exists')->justReturn(true);
        Functions\expect('intersoccer_get_term_name')
            ->once()
            ->with('geneva-centre', 'pa_intersoccer-venues')
            ->andReturn('Geneva Centre');

        $result = intersoccer_format_attribute_for_storage('pa_intersoccer-venues', 'geneva-centre');
        $this->assertSame('Geneva Centre', $result);
    }

    public function test_resolve_variation_for_roster_delegates_to_wpml_helper() {
        if (!function_exists('intersoccer_resolve_variation_for_roster')) {
            $this->markTestSkipped('intersoccer_resolve_variation_for_roster not found');
        }

        Functions\expect('intersoccer_get_default_language_variation_id')
            ->once()
            ->with(999)
            ->andReturn(100);

        $this->assertSame(100, intersoccer_resolve_variation_for_roster(999));
    }

    public function test_normalize_roster_facets_for_storage_calls_signature_normalizer() {
        if (!function_exists('intersoccer_normalize_roster_facets_for_storage')) {
            $this->markTestSkipped('intersoccer_normalize_roster_facets_for_storage not found');
        }

        Functions\expect('intersoccer_normalize_event_data_for_signature')
            ->once()
            ->andReturn([
                'venue' => 'Geneva Centre',
                'age_group' => '5-13y (Full Day)',
                'camp_terms' => '',
                'course_day' => '',
                'times' => '',
                'season' => 'Summer 2025',
                'activity_type' => 'Camp',
                'city' => '',
                'canton_region' => '',
                'girls_only' => 0,
                'product_id' => 1,
            ]);

        Functions\when('intersoccer_get_term_name')->alias(function ($value) {
            return $value;
        });
        Functions\when('intersoccer_canonical_activity_type_for_roster')->alias(function ($value) {
            return $value;
        });

        $normalized = intersoccer_normalize_roster_facets_for_storage([
            'venue' => 'Genève Centre',
            'age_group' => "5-13a (Journée complète)",
            'season' => 'Été 2025',
            'activity_type' => 'Camp',
            'product_id' => 1,
        ]);

        $this->assertSame('Geneva Centre', $normalized['venue']);
        $this->assertSame('Summer 2025', $normalized['season']);
    }

    public function test_build_roster_facet_db_update_includes_facet_columns() {
        if (!function_exists('intersoccer_build_roster_facet_db_update')) {
            $this->markTestSkipped('intersoccer_build_roster_facet_db_update not found');
        }

        Functions\expect('intersoccer_generate_event_signature')
            ->once()
            ->andReturn('abc123');

        $update = intersoccer_build_roster_facet_db_update(
            [
                'venue' => 'Geneva Centre',
                'age_group' => '5-13y',
                'camp_terms' => 'Week 1',
                'course_day' => 'Monday',
                'times' => '9:00am-4:00pm',
                'season' => 'Summer 2025',
                'city' => 'Geneva',
                'canton_region' => 'Geneva',
                'activity_type' => 'Camp',
            ],
            [],
            'Summer Camp Product'
        );

        $this->assertSame('abc123', $update['event_signature']);
        $this->assertSame('Geneva Centre', $update['venue']);
        $this->assertSame('Summer Camp Product', $update['product_name']);
    }

    public function test_human_meta_from_normalized_facets_uses_english_labels() {
        if (!function_exists('intersoccer_human_meta_from_normalized_facets')) {
            $this->markTestSkipped('intersoccer_human_meta_from_normalized_facets not found');
        }

        $human = intersoccer_human_meta_from_normalized_facets([
            'venue' => 'Geneva Centre',
            'age_group' => '5-13y',
            'activity_type' => 'course',
            'times' => '17:45-19:00',
        ]);

        $this->assertArrayHasKey('intersoccer_venues', $human);
        $this->assertSame('Geneva Centre', $human['intersoccer_venues']);
        $this->assertArrayHasKey('course_times', $human);
    }

    public function test_normalize_booking_type_for_storage_french_label() {
        if (!function_exists('intersoccer_normalize_booking_type_for_storage')) {
            $this->markTestSkipped('intersoccer_normalize_booking_type_for_storage not found');
        }

        $this->assertSame('Full Week', intersoccer_normalize_booking_type_for_storage('Semaine complète'));
        $this->assertSame('Single Day(s)', intersoccer_normalize_booking_type_for_storage('Jours sélectionnés'));
    }

    public function test_normalize_selected_days_for_storage_french_weekdays() {
        if (!function_exists('intersoccer_normalize_selected_days_for_storage')) {
            $this->markTestSkipped('intersoccer_normalize_selected_days_for_storage not found');
        }

        $this->assertSame(
            'Monday, Wednesday',
            intersoccer_normalize_selected_days_for_storage('Lundi, Mercredi')
        );
    }

    public function test_build_roster_booking_db_update_includes_booking_columns() {
        if (!function_exists('intersoccer_build_roster_booking_db_update')) {
            $this->markTestSkipped('intersoccer_build_roster_booking_db_update not found');
        }

        $update = intersoccer_build_roster_booking_db_update([
            'booking_type'  => 'Full Week',
            'selected_days' => 'Monday, Tuesday',
            'day_presence'  => wp_json_encode(['Monday' => 'Yes']),
        ]);

        $this->assertSame('Full Week', $update['booking_type']);
        $this->assertSame('Monday, Tuesday', $update['selected_days']);
        $this->assertSame('Monday, Tuesday', $update['days_selected']);
    }
}
