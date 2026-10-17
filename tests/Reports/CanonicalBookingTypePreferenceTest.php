<?php

namespace InterSoccer\Reports\Tests\Reports;

use PHPUnit\Framework\TestCase;

class CanonicalBookingTypePreferenceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        $keys = dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        if (file_exists($keys)) {
            require_once $keys;
        }
    }

    public function test_canonical_other_does_not_overwrite_resolved_full_week_from_display_meta() {
        if (!function_exists('intersoccer_apply_canonical_order_item_meta_to_data')) {
            $this->markTestSkipped('intersoccer_apply_canonical_order_item_meta_to_data not loaded');
        }

        $data = [
            'booking_type' => 'Full Week',
            'Booking Type' => 'ganze-woche',
        ];
        $raw = [
            '_intersoccer_canonical_booking_type' => 'other',
            'Booking Type' => 'ganze-woche',
        ];

        $out = intersoccer_apply_canonical_order_item_meta_to_data($data, $raw);
        $this->assertSame('Full Week', $out['booking_type']);
    }
}
