<?php
/**
 * Final Reports FR/DE meta normalization (shared order-meta-keys helpers).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FinalReportsMetaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (file_exists(dirname(__DIR__, 2) . '/includes/utils.php')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }
    }

    public function test_normalize_order_item_meta_key_maps_pa_booking_type_alias() {
        if (!function_exists('intersoccer_normalize_order_item_meta_key')) {
            $this->markTestSkipped('intersoccer_normalize_order_item_meta_key not loaded');
        }
        $this->assertSame('Booking Type', intersoccer_normalize_order_item_meta_key('pa_booking-type'));
        $this->assertSame('Days Selected', intersoccer_normalize_order_item_meta_key('Days of Week'));
    }

    public function test_normalize_order_item_meta_key_maps_venue_labels() {
        if (!function_exists('intersoccer_normalize_order_item_meta_key')) {
            $this->markTestSkipped('intersoccer_normalize_order_item_meta_key not loaded');
        }
        $this->assertSame('Sites InterSoccer', intersoccer_normalize_order_item_meta_key('Sites InterSoccer'));
        $this->assertSame('Sites InterSoccer', intersoccer_normalize_order_item_meta_key('InterSoccer Venues'));
    }

    public function test_normalize_booking_type_label_for_reports_french_full_week() {
        if (!function_exists('intersoccer_normalize_booking_type_label_for_reports')) {
            $this->markTestSkipped();
        }
        $this->assertSame('Full Week', intersoccer_normalize_booking_type_label_for_reports('Journée complète'));
    }

    public function test_normalize_selected_days_string_for_reports_french_weekdays() {
        if (!function_exists('intersoccer_normalize_selected_days_string_for_reports')
            || !function_exists('intersoccer_normalize_weekday_token')) {
            $this->markTestSkipped();
        }
        $out = intersoccer_normalize_selected_days_string_for_reports('lundi, mardi');
        $this->assertStringContainsString('Monday', $out);
        $this->assertStringContainsString('Tuesday', $out);
    }

    public function test_enrich_and_normalize_batch_does_not_require_woocommerce_for_empty_item() {
        if (!function_exists('intersoccer_reports_enrich_and_normalize_final_report_rows')) {
            $this->markTestSkipped();
        }
        $rows = [
            [
                'order_item_id' => 0,
                'booking_type' => '',
                'selected_days' => 'lundi',
            ],
        ];
        intersoccer_reports_enrich_and_normalize_final_report_rows($rows);
        $this->assertSame('Monday', $rows[0]['selected_days']);
    }

    public function test_normalize_final_reports_row_booking_and_days_applies_english_labels() {
        if (!function_exists('intersoccer_normalize_final_reports_row_booking_and_days')) {
            $this->markTestSkipped();
        }
        $row = [
            'booking_type' => 'ganze woche',
            'selected_days' => 'Montag, Dienstag',
        ];
        intersoccer_normalize_final_reports_row_booking_and_days($row);
        $this->assertSame('Full Week', $row['booking_type']);
        $this->assertStringContainsString('Monday', $row['selected_days']);
        $this->assertStringContainsString('Tuesday', $row['selected_days']);
    }

    public function test_scalarize_order_item_meta_value_handles_nested_discount_breakdown(): void {
        if (!function_exists('intersoccer_scalarize_order_item_meta_value')) {
            $this->markTestSkipped();
        }

        $nested = [
            [
                'name' => 'Referral Discount',
                'type' => 'referral_first_order',
                'amount' => 50.0,
            ],
        ];

        $this->assertSame('', intersoccer_scalarize_order_item_meta_value($nested, '_intersoccer_item_discounts'));
        $this->assertSame('Mon, Tue', intersoccer_scalarize_order_item_meta_value(['Mon', 'Tue'], 'Days Selected'));
    }

    public function test_order_item_meta_key_is_internal_for_attribution_keys(): void {
        if (!function_exists('intersoccer_order_item_meta_key_is_internal')) {
            $this->markTestSkipped();
        }

        $this->assertTrue(intersoccer_order_item_meta_key_is_internal('_intersoccer_item_discounts'));
        $this->assertTrue(intersoccer_order_item_meta_key_is_internal('_intersoccer_canonical_booking_type'));
        $this->assertFalse(intersoccer_order_item_meta_key_is_internal('Days Selected'));
    }

    public function test_canonical_order_meta_value_to_string_decodes_json_array(): void {
        if (!function_exists('intersoccer_canonical_order_meta_value_to_string')) {
            $this->markTestSkipped();
        }
        $this->assertSame('full-week', intersoccer_canonical_order_meta_value_to_string('["full-week"]'));
        $this->assertSame('3-5y-half-day, 5-13y-full-day', intersoccer_canonical_order_meta_value_to_string('["3-5y-half-day","5-13y-full-day"]'));
    }

    public function test_apply_canonical_order_item_meta_to_data_prefers_machine_keys(): void {
        if (!function_exists('intersoccer_apply_canonical_order_item_meta_to_data')) {
            $this->markTestSkipped();
        }
        $data = intersoccer_apply_canonical_order_item_meta_to_data([
            'booking_type' => '',
            'venue' => '',
            'activity_type' => '',
        ], [
            '_intersoccer_canonical_booking_type' => '["full-week"]',
            '_intersoccer_canonical_venue' => '["geneva"]',
            '_intersoccer_canonical_activity_type' => 'camp',
            '_intersoccer_canonical_girls_only' => '1',
        ]);

        $this->assertSame('full-week', $data['booking_type']);
        $this->assertSame('geneva', $data['venue']);
        $this->assertSame('camp', $data['activity_type']);
        $this->assertSame(1, $data['girls_only']);
        $this->assertSame('girls-only', $data['pa_girls-only']);
    }

    public function test_apply_canonical_maps_canton_and_camp_terms_to_roster_fields(): void {
        if (!function_exists('intersoccer_apply_canonical_order_item_meta_to_data')) {
            $this->markTestSkipped();
        }
        $data = intersoccer_apply_canonical_order_item_meta_to_data([
            'canton_region' => '',
            'camp_terms' => '',
        ], [
            '_intersoccer_canonical_canton' => '["vaud"]',
            '_intersoccer_canonical_camp_terms' => '["week-1"]',
        ]);

        $this->assertSame('vaud', $data['canton_region']);
        $this->assertSame('week-1', $data['camp_terms']);
        $this->assertArrayNotHasKey('region', $data);
        $this->assertArrayNotHasKey('event_type', $data);
    }
}
