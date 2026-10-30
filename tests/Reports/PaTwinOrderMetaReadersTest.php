<?php
/**
 * RR #104 — order-item meta readers accept pa_* or attribute_pa_* (single-key lines).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class PaTwinOrderMetaReadersTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
    }

    public function test_pa_taxonomy_helper_accepts_bare_taxonomy_slug(): void {
        $meta = ['pa_age-group' => '5-12'];
        $this->assertSame('5-12', intersoccer_get_order_item_pa_taxonomy_meta_value($meta, 'age-group'));
        $this->assertSame('5-12', intersoccer_get_order_item_pa_taxonomy_meta_value($meta, 'pa_age-group'));
    }

    public function test_pa_taxonomy_helper_reads_attribute_only_booking_type(): void {
        $meta = ['attribute_pa_booking-type' => 'full-term'];
        $this->assertSame('full-term', intersoccer_get_order_item_pa_taxonomy_meta_value($meta, 'pa_booking-type'));
    }

    public function test_pa_taxonomy_helper_reads_pa_only_booking_type(): void {
        $meta = ['pa_booking-type' => 'single-days'];
        $this->assertSame('single-days', intersoccer_get_order_item_pa_taxonomy_meta_value($meta, 'pa_booking-type'));
    }

    public function test_read_order_item_booking_fields_reads_attribute_pa_booking_type_only(): void {
        global $intersoccer_test_wc_order_item_meta;
        $item_id = 88101;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'attribute_pa_booking-type' => 'full-term',
        ];
        $fields = intersoccer_read_order_item_booking_fields($item_id);
        $this->assertSame('full-term', $fields['booking_type']);
    }

    public function test_read_order_item_booking_fields_reads_pa_booking_type_only(): void {
        global $intersoccer_test_wc_order_item_meta;
        $item_id = 88102;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'pa_booking-type' => 'single-days',
        ];
        $fields = intersoccer_read_order_item_booking_fields($item_id);
        $this->assertSame('single-days', $fields['booking_type']);
    }

    public function test_prepare_roster_entry_read_pa_path_resolves_attribute_only_venue(): void {
        $order_item_meta = ['attribute_pa_intersoccer-venues' => 'geneva-stade-chenois-thonex'];
        $venue = intersoccer_get_order_item_pa_taxonomy_meta_value($order_item_meta, 'pa_intersoccer-venues');
        $this->assertSame('geneva-stade-chenois-thonex', $venue);
    }

    public function test_prepare_roster_entry_read_pa_path_resolves_pa_only_venue(): void {
        $order_item_meta = ['pa_intersoccer-venues' => 'geneva-college-sismondi-nations'];
        $venue = intersoccer_get_order_item_pa_taxonomy_meta_value($order_item_meta, 'pa_intersoccer-venues');
        $this->assertSame('geneva-college-sismondi-nations', $venue);
    }

    public function test_update_roster_entry_event_block_uses_pa_taxonomy_helper(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/utils.php');
        $start = (int) strpos($source, '// Event details with fallbacks');
        $this->assertGreaterThan(0, $start);
        $chunk = substr($source, $start, 2500);
        $this->assertStringContainsString('intersoccer_get_order_item_pa_taxonomy_meta_value', $chunk);
        $this->assertStringContainsString("\$read_pa_item_meta('pa_intersoccer-venues')", $chunk);
        $this->assertStringContainsString("\$read_pa_item_meta('pa_booking-type')", $chunk);
    }

    public function test_display_booking_report_sql_coalesces_pa_and_attribute_booking_type(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/reports-data.php');
        $start = (int) strpos($source, 'function intersoccer_display_booking_report');
        $this->assertGreaterThan(0, $start);
        $chunk = substr($source, $start, 4500);
        $this->assertStringContainsString("om_booking_type_attr.meta_key = 'attribute_pa_booking-type'", $chunk);
        $this->assertStringContainsString('COALESCE(NULLIF(om_booking_type.meta_value', $chunk);
        $this->assertStringContainsString("om_age_group_attr.meta_key = 'attribute_pa_age-group'", $chunk);
        $this->assertStringContainsString("om_venue_attr.meta_key = 'attribute_pa_intersoccer-venues'", $chunk);
    }

    public function test_get_final_reports_data_woo_fallback_sql_includes_attribute_pa_joins(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/reports-data.php');
        $needle = 'COALESCE(om_canonical_activity.meta_value, om_activity_type.meta_value, om_activity_type_pa.meta_value';
        $this->assertStringContainsString($needle, $source);
        $this->assertStringContainsString("om_season_alt_attr.meta_key = 'attribute_pa_program-season'", $source);
        $this->assertStringContainsString("om_year_alt_attr.meta_key = 'attribute_pa_program-year'", $source);
    }
}
