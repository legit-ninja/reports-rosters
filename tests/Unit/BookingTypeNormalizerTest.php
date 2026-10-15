<?php
/**
 * Central booking-type normalizer: slugs and multilingual labels for roster UI and export.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/utils.php';

class BookingTypeNormalizerTest extends TestCase {

    public static function setUpBeforeClass(): void {
        if (!function_exists('remove_accents')) {
            function remove_accents($string) {
                if (!is_string($string) || !class_exists('\Normalizer')) {
                    return $string;
                }
                $n = \Normalizer::normalize($string, \Normalizer::FORM_D);
                return $n === false ? $string : (preg_replace('/\p{Mn}+/u', '', $n) ?? $string);
            }
        }
    }

    /**
     * @dataProvider singleDayRawValues
     */
    public function test_slug_and_storage_label_for_single_day_variants($raw) {
        $this->assertSame('single-days', intersoccer_normalize_booking_type_slug_for_reports($raw));
        $this->assertSame('Single Day(s)', intersoccer_normalize_booking_type_for_storage($raw));
    }

    public function singleDayRawValues() {
        return [
            ['einzelner-tage'],
            ['single-day-s'],
            ['Einzelne(r) Tag(e)'],
            ['Einzelne Tag(e)'],
            ['Jours sélectionnés'],
            ['jour(s) unique(s)'],
        ];
    }

    public function test_full_week_variants_stay_full_week() {
        $this->assertSame('full-week', intersoccer_normalize_booking_type_slug_for_reports('Full Week'));
        $this->assertSame('Full Week', intersoccer_normalize_booking_type_for_storage('Ganze Woche'));
        $this->assertSame('Full Week', intersoccer_normalize_booking_type_for_storage('Journée complète'));
        $this->assertSame('full-week', intersoccer_normalize_booking_type_slug_for_reports('semaine-complete'));
    }

    public function test_label_for_reports_delegates_to_storage_normalizer() {
        if (!function_exists('intersoccer_normalize_booking_type_label_for_reports')) {
            require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        }
        $this->assertSame('Single Day(s)', intersoccer_normalize_booking_type_label_for_reports('einzelner-tage'));
    }
}
