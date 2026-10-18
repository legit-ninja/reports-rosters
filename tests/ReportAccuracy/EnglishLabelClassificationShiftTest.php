<?php
/**
 * Guard: English fee labels whose report bucket shifts vs 548885d (canonical-before-PV path).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/includes/financial-attribution-helpers.php';

class EnglishLabelClassificationShiftTest extends TestCase {

    /**
     * Pins each documented label shift to its new report bucket (classify → map).
     *
     * @dataProvider englishLabelNewBucketProvider
     */
    public function test_english_label_maps_to_expected_report_bucket(string $label, string $expected_bucket): void {
        $type = intersoccer_classify_discount_fee($label);
        $bucket = intersoccer_map_discount_type_to_report_bucket($type);
        $this->assertSame($expected_bucket, $bucket, 'label "' . $label . '" classified as ' . $type);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function englishLabelNewBucketProvider(): array {
        return [
            'tournament sibling fee label' => ['Tournament Sibling Discount', 'sibling'],
            'camp promo fee label' => ['Camp Summer Promo', 'coupon'],
            'promotional camp fee label' => ['Promotional Camp Fee', 'coupon'],
        ];
    }
}
