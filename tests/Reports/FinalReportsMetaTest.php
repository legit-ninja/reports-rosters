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
        $this->assertFalse(intersoccer_order_item_meta_key_is_internal('Days Selected'));
    }
}
