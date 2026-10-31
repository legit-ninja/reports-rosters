<?php

namespace InterSoccer\Reports\Tests\Reports;

use PHPUnit\Framework\TestCase;

class CanonicalBookingTypePreferenceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        $root = dirname(__DIR__, 2);
        $keys = $root . '/includes/order-meta-keys.php';
        if (file_exists($keys)) {
            require_once $keys;
        }
        $utils = $root . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
    }

    public function test_canonical_other_does_not_overwrite_resolved_full_week_from_display_meta() {
        if (!function_exists('intersoccer_apply_canonical_order_item_meta_to_data')) {
            $this->fail('intersoccer_apply_canonical_order_item_meta_to_data must be loaded');
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

    public function test_canonical_other_resolves_buchungstyp_case_insensitive_alias() {
        if (!function_exists('intersoccer_apply_canonical_order_item_meta_to_data')) {
            $this->fail('intersoccer_apply_canonical_order_item_meta_to_data must be loaded');
        }

        $data = ['booking_type' => 'other'];
        $raw = [
            '_intersoccer_canonical_booking_type' => 'other',
            'Buchungstyp' => 'ganze-woche',
        ];

        $out = intersoccer_apply_canonical_order_item_meta_to_data($data, $raw);
        $this->assertSame('Full Week', $out['booking_type']);
    }
}
