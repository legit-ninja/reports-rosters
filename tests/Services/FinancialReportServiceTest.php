<?php

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/includes/financial-attribution-helpers.php';

class FinancialReportServiceTest extends TestCase {

    public function test_discount_type_breakdown_includes_referral_buckets(): void {
        $report_data = [
            [
                'discount_amount' => '40.00',
                'discount_breakdown' => [
                    ['type' => 'referral_first_order', 'amount' => 10.0],
                    ['type' => 'referral_points', 'amount' => 30.0],
                ],
            ],
            [
                'discount_amount' => '25.00',
                'discount_breakdown' => [
                    ['type' => 'camp_sibling', 'amount' => 25.0],
                ],
            ],
        ];

        $totals = intersoccer_calculate_discount_type_breakdown($report_data);

        $this->assertEquals(10.0, $totals['referral_first_order']);
        $this->assertEquals(30.0, $totals['referral_points']);
        $this->assertEquals(25.0, $totals['sibling']);
        $this->assertEquals(0.0, $totals['coupon']);
    }

    public function test_map_discount_type_to_report_bucket(): void {
        $this->assertSame('referral_first_order', intersoccer_map_discount_type_to_report_bucket('referral_first_order'));
        $this->assertSame('referral_points', intersoccer_map_discount_type_to_report_bucket('referral_points'));
        $this->assertSame('sibling', intersoccer_map_discount_type_to_report_bucket('camp_sibling'));
        $this->assertSame('coupon', intersoccer_map_discount_type_to_report_bucket('coupon'));
    }

    public function test_determine_discount_type_from_name_recognizes_referral_labels(): void {
        $this->assertSame('referral_first_order', intersoccer_determine_discount_type_from_name('Referral Discount'));
        $this->assertSame('referral_points', intersoccer_determine_discount_type_from_name('Referral Credits Discount'));
    }

    public function test_parse_legacy_discount_amount_from_html(): void {
        $html = '<span class="woocommerce-Price-amount amount"><bdi>54.00</bdi></span>';
        $this->assertEquals(54.0, intersoccer_parse_legacy_discount_amount($html));
    }

    public function test_line_final_price_does_not_double_subtract_attributed_discount(): void {
        $this->assertEquals(420.0, intersoccer_financial_line_final_price(420.0, 420.0, 105.0));
    }

    public function test_line_final_price_unchanged_without_discount(): void {
        $this->assertEquals(945.0, intersoccer_financial_line_final_price(945.0, 945.0, 0.0));
    }

    public function test_line_final_price_subtracts_discount_when_subtotal_is_pre_discount(): void {
        $this->assertEquals(420.0, intersoccer_financial_line_final_price(525.0, 420.0, 105.0));
    }
}
