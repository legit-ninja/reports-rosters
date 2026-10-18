<?php
/**
 * Guard: English fee labels vs 548885d PV-first classify path (pv-release-2.10.17 precise types).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/includes/financial-attribution-helpers.php';

class EnglishLabelClassificationShiftTest extends TestCase {

    /**
     * @dataProvider englishLabelNewTypeAndBucketProvider
     */
    public function test_english_label_maps_to_expected_type_and_bucket(
        string $label,
        string $expected_type,
        string $expected_bucket
    ): void {
        $type = intersoccer_classify_discount_fee($label);
        $bucket = intersoccer_map_discount_type_to_report_bucket($type);
        $this->assertSame($expected_type, $type, 'label "' . $label . '"');
        $this->assertSame($expected_bucket, $bucket, 'label "' . $label . '" type ' . $type);
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function englishLabelNewTypeAndBucketProvider(): array {
        return [
            'plain sibling' => ['Sibling Discount', 'sibling', 'sibling'],
            '2nd child' => ['2nd Child Discount', 'sibling', 'sibling'],
            'same season without course' => ['Same Season Discount', 'same_season', 'same_season'],
            '50% same season' => ['50% same season', 'same_season', 'same_season'],
            'plain coupon' => ['Coupon', 'coupon', 'coupon'],
            'promo code' => ['Promo Code', 'coupon', 'coupon'],
            '10% promo' => ['10% promo', 'coupon', 'coupon'],
            'course promo' => ['Course Promo', 'coupon', 'coupon'],
            'tournament same season' => ['Tournament Same Season', 'same_season', 'same_season'],
            'tournament sibling' => ['Tournament Sibling Discount', 'sibling', 'sibling'],
            'camp summer promo' => ['Camp Summer Promo', 'coupon', 'coupon'],
            'promotional camp fee' => ['Promotional Camp Fee', 'coupon', 'coupon'],
            'camp sibling type only' => ['Camp Sibling Discount', 'sibling', 'sibling'],
            'course sibling type only' => ['Course Sibling Discount', 'sibling', 'sibling'],
            'course same season type only' => ['Course Same Season Discount', 'same_season', 'same_season'],
        ];
    }
}
