<?php
/**
 * Booking report AJAX totals markup must keep nested summary sections.
 */

use PHPUnit\Framework\TestCase;

class BookingReportTotalsHtmlTest extends TestCase {
    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 2) . '/includes/reports-ajax.php';
    }

    public function test_nested_financial_summary_is_not_cut_at_the_first_inner_div() {
        $html = '<div id="intersoccer-report-totals" class="report-totals">'
            . '<div class="report-summary"><h3>Financial Summary</h3>'
            . '<div class="summary-item"><strong>Total Bookings:</strong> 2</div>'
            . '<div class="summary-item"><strong>Net Revenue:</strong> CHF 10.00</div>'
            . '</div></div>'
            . '<div id="intersoccer-report-table"><p>rows</p></div>';

        $block = intersoccer_extract_balanced_div_by_id($html, 'intersoccer-report-totals');
        $this->assertTrue($block['found']);
        $this->assertStringContainsString('Financial Summary', $block['inner']);
        $this->assertStringContainsString('Net Revenue', $block['inner']);
        $this->assertStringNotContainsString('id="intersoccer-report-totals"', $block['inner']);

        $table = str_replace($block['outer'], '', $html);
        $this->assertStringNotContainsString('Financial Summary', $table);
        $this->assertStringContainsString('id="intersoccer-report-table"', $table);
        $this->assertStringContainsString('rows', $table);
    }
}
