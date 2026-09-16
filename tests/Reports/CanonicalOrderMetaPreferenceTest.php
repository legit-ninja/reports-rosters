<?php
/**
 * Readers prefer language-neutral `_intersoccer_canonical_*` keys over display labels.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Campaign\FacetNormalizer;
use InterSoccer\ReportsRosters\Tests\TestCase;

class CanonicalOrderMetaPreferenceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        if (file_exists($includes . '/order-meta-keys.php')) {
            require_once $includes . '/order-meta-keys.php';
        }
        if (file_exists($includes . '/utils.php')) {
            require_once $includes . '/utils.php';
        }
    }

    public function test_canonical_field_map_matches_taxonomy_standard() {
        if (!function_exists('intersoccer_get_canonical_order_meta_field_map')) {
            $this->markTestSkipped('canonical field map not loaded');
        }

        $map = intersoccer_get_canonical_order_meta_field_map();
        $this->assertSame('activity_type', $map['_intersoccer_canonical_activity_type']);
        $this->assertSame('girls_only', $map['_intersoccer_canonical_girls_only']);
        $this->assertSame('booking_type', $map['_intersoccer_canonical_booking_type']);
        $this->assertSame('venue', $map['_intersoccer_canonical_venue']);
        $this->assertSame('region', $map['_intersoccer_canonical_canton']);
        $this->assertSame('age_group', $map['_intersoccer_canonical_age_group']);
        $this->assertSame('event_type', $map['_intersoccer_canonical_camp_terms']);
        $this->assertTrue(intersoccer_order_item_meta_key_is_canonical('_intersoccer_canonical_venue'));
        $this->assertFalse(intersoccer_order_item_meta_key_skip_for_reports('_intersoccer_canonical_venue'));
        $this->assertTrue(intersoccer_order_item_meta_key_skip_for_reports('_intersoccer_item_discounts'));
    }

    public function test_field_value_prefers_canonical_over_display_label() {
        if (!function_exists('intersoccer_get_order_item_meta_field_value')) {
            $this->markTestSkipped('meta field helper not loaded');
        }

        $meta = [
            'Activity Type' => 'Camp, Girls Only',
            '_intersoccer_canonical_activity_type' => 'camp',
            'Booking Type' => 'Saison',
            '_intersoccer_canonical_booking_type' => 'full-week',
            'Sites InterSoccer' => 'Lieux InterSoccer',
            '_intersoccer_canonical_venue' => 'stade-de-vessy',
        ];

        $this->assertSame('camp', intersoccer_get_order_item_meta_field_value($meta, 'activity_type'));
        $this->assertSame('full-week', intersoccer_get_order_item_meta_field_value($meta, 'booking_type'));
        $this->assertSame('stade-de-vessy', intersoccer_get_order_item_meta_field_value($meta, 'venue'));
    }

    public function test_field_value_falls_back_to_display_label_for_old_orders() {
        if (!function_exists('intersoccer_get_order_item_meta_field_value')) {
            $this->markTestSkipped('meta field helper not loaded');
        }

        $meta = [
            'Activity Type' => 'Course',
            'Booking Type' => 'Full Week',
        ];
        $this->assertSame('Course', intersoccer_get_order_item_meta_field_value($meta, 'activity_type'));
        $this->assertSame('Full Week', intersoccer_get_order_item_meta_field_value($meta, 'booking_type'));
    }

    public function test_apply_canonical_overwrites_display_and_keeps_girls_only_separate() {
        if (!function_exists('intersoccer_apply_canonical_order_item_meta_to_data')) {
            $this->markTestSkipped('canonical overlay not loaded');
        }

        $data = intersoccer_apply_canonical_order_item_meta_to_data(
            [
                'activity_type' => 'Camp, Girls Only',
                'booking_type' => 'Saison',
                'venue' => 'Genève - Stade de Vessy',
                'region' => 'Genève',
            ],
            [
                '_intersoccer_canonical_activity_type' => 'camp',
                '_intersoccer_canonical_girls_only' => '1',
                '_intersoccer_canonical_booking_type' => 'full-week',
                '_intersoccer_canonical_venue' => 'stade-de-vessy',
                '_intersoccer_canonical_canton' => 'geneva',
                '_intersoccer_canonical_age_group' => '6-9y-full-day',
                '_intersoccer_canonical_camp_terms' => 'autumn-week-1',
            ]
        );

        $this->assertSame('Camp', $data['activity_type']);
        $this->assertSame('1', $data['girls_only']);
        $this->assertSame('Full Week', $data['booking_type']);
        $this->assertSame('stade-de-vessy', $data['venue']);
        $this->assertSame('geneva', $data['region']);
        $this->assertSame('geneva', $data['canton']);
        $this->assertSame('6-9y-full-day', $data['age_group']);
        $this->assertSame('autumn-week-1', $data['event_type']);
        $this->assertSame('autumn-week-1', $data['camp_terms']);
    }

    public function test_facet_normalizer_prefers_canonical_over_localized_labels() {
        $n = new FacetNormalizer();
        $facets = $n->normalize_from_meta([
            'Activity Type' => 'Camp, Girls Only',
            'Booking Type' => 'Journée complète',
            'Sites InterSoccer' => 'Genève - Stade de Vessy (Champel)',
            'Canton / Region' => 'Genf',
            'Age Group' => 'Groupe d\'âge 6-9',
            'Camp Terms' => 'Semaine d\'été 5',
            '_intersoccer_canonical_activity_type' => 'camp',
            '_intersoccer_canonical_girls_only' => '0',
            '_intersoccer_canonical_booking_type' => 'single-days',
            '_intersoccer_canonical_venue' => 'frontenex',
            '_intersoccer_canonical_canton' => 'vaud',
            '_intersoccer_canonical_age_group' => '10-13y-full-day',
            '_intersoccer_canonical_camp_terms' => 'autumn-week-2',
        ]);

        $this->assertSame('camp', $facets['activity_type']);
        $this->assertSame(0, $facets['girls_only']);
        $this->assertSame('single-days', $facets['booking_type']);
        $this->assertSame('Frontenex', $facets['venue']);
        $this->assertSame('Vaud', $facets['region']);
        $this->assertSame('10-13y-full-day', $facets['age_group']);
        $this->assertSame('autumn_week_2', $facets['camp_week']);
    }

    public function test_sql_candidates_list_canonical_keys_first() {
        if (!function_exists('intersoccer_reports_sql_meta_key_candidates')) {
            $this->markTestSkipped('sql candidates not loaded');
        }

        $this->assertSame(
            '_intersoccer_canonical_activity_type',
            intersoccer_reports_sql_meta_key_candidates('activity_type')[0]
        );
        $this->assertSame(
            '_intersoccer_canonical_booking_type',
            intersoccer_reports_sql_meta_key_candidates('booking_type')[0]
        );
        $this->assertContains('Activity Type', intersoccer_reports_sql_meta_key_candidates('activity_type'));
        $this->assertSame(['Days Selected', 'Days of Week'], intersoccer_reports_sql_meta_key_candidates('selected_days'));
    }
}
