<?php
/**
 * Roster move round-trip helpers: booking, days, city, day_presence preservation.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterMoveRosterDataHelpersTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
    }

    public function test_empty_booking_override_keeps_record_full_term(): void {
        $record = [
            'booking_type' => 'Full Term',
            'activity_type' => 'Course',
            'day_presence' => '',
        ];
        $booking = intersoccer_normalize_roster_booking_columns($record, [
            'booking_type' => '',
            'selected_days' => '',
            'late_pickup_days' => '',
        ]);
        $this->assertSame('Full Term', $booking['booking_type']);
        $this->assertSame('', $booking['day_presence']);
    }

    public function test_canonical_booking_meta_maps_to_english_full_term(): void {
        global $intersoccer_test_wc_order_item_meta;
        $item_id = 77001;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            '_intersoccer_canonical_booking_type' => 'full-term',
            'Type de réservation' => 'Trimestre complet',
        ];
        $fields = intersoccer_read_order_item_booking_fields($item_id);
        $this->assertSame('Full Term', $fields['booking_type']);
    }

    public function test_german_full_term_label_maps_to_english(): void {
        $this->assertSame('full-term', intersoccer_normalize_booking_type_slug_for_reports('Voller Begriff'));
        $this->assertSame('Full Term', intersoccer_normalize_booking_type_for_storage('Voller Begriff'));
    }

    public function test_french_trimestre_complet_maps_to_full_term(): void {
        $this->assertSame('Full Term', intersoccer_normalize_booking_type_for_storage('Trimestre complet'));
    }

    public function test_unmapped_localized_booking_keeps_record_english_value(): void {
        $record = ['booking_type' => 'Full Term', 'activity_type' => 'Course'];
        $booking = intersoccer_normalize_roster_booking_columns($record, [
            'booking_type' => 'Etiquette locale inconnue',
            'selected_days' => '',
            'late_pickup_days' => '',
        ]);
        $this->assertSame('Full Term', $booking['booking_type']);
    }

    public function test_empty_selected_days_db_update_keeps_days_selected_column(): void {
        $update = intersoccer_build_roster_booking_db_update(
            [
                'booking_type' => 'Full Term',
                'selected_days' => '',
                'day_presence' => '',
            ],
            ['days_selected' => '', 'selected_days' => '']
        );
        $this->assertSame('', $update['days_selected']);
        $this->assertArrayNotHasKey('N/A', $update);
    }

    public function test_course_does_not_recompute_day_presence(): void {
        $record = [
            'activity_type' => 'Course',
            'day_presence' => '',
            'booking_type' => 'Full Term',
        ];
        $booking = intersoccer_normalize_roster_booking_columns($record, [
            'booking_type' => 'Full Term',
            'selected_days' => '',
            'late_pickup_days' => '',
        ]);
        $this->assertSame('', $booking['day_presence']);
    }

    public function test_camp_still_computes_day_presence_for_single_days(): void {
        $record = [
            'activity_type' => 'Camp',
            'day_presence' => '',
            'booking_type' => 'Single Day(s)',
        ];
        $booking = intersoccer_normalize_roster_booking_columns($record, [
            'booking_type' => 'Single Day(s)',
            'selected_days' => 'Monday, Wednesday',
            'late_pickup_days' => '',
        ]);
        $this->assertNotSame('', $booking['day_presence']);
        $decoded = json_decode($booking['day_presence'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('Yes', $decoded['Monday'] ?? null);
        $this->assertSame('Yes', $decoded['Wednesday'] ?? null);
    }

    public function test_facet_update_does_not_blank_city_when_variation_missing_pa_city(): void {
        $update = intersoccer_build_roster_facet_db_update(
            ['city' => '', 'canton_region' => '', 'activity_type' => 'Course'],
            ['city' => 'Geneva', 'canton_region' => 'Geneva'],
            'Course EN'
        );
        $this->assertSame('Geneva', $update['city']);
        $this->assertSame('Geneva', $update['canton_region']);
    }
}
