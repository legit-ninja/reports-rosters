<?php
/**
 * RR #104 — order-item meta readers accept pa_* or attribute_pa_* (single-key lines).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class PaTwinOrderMetaReadersTest extends TestCase {

    private const ORDER_ID = 88200;
    private const ITEM_ID = 88201;
    private const PARENT_ID = 88210;
    private const VARIATION_ID = 88211;

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__, 2) . '/includes/db.php';
        if (!function_exists('intersoccer_get_enhanced_booking_report')) {
            require_once dirname(__DIR__, 2) . '/includes/reporting-discounts.php';
        }
        if (!function_exists('intersoccer_get_final_reports_data')) {
            require_once dirname(__DIR__, 2) . '/includes/reports-data.php';
        }
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

    public function test_order_meta_field_map_includes_attribute_pa_program_year(): void {
        $map = intersoccer_get_order_meta_field_map();
        $this->assertSame('program_year', $map['attribute_pa_program-year'] ?? null);
        $final = intersoccer_get_order_meta_canonical_to_final_report_row_keys();
        $this->assertSame('program_year', $final['attribute_pa_program-year'] ?? null);
    }

    /** @dataProvider prepareRosterVenueProvider */
    public function test_prepare_roster_entry_venue_read_resolves_pa_shape(array $meta, string $expected): void {
        $entry = $this->invokePrepareRosterEntry($meta);
        $this->assertSame($expected, $entry['venue']);
    }

    public static function prepareRosterVenueProvider(): array {
        return [
            'attribute only' => [
                ['attribute_pa_intersoccer-venues' => 'geneva-stade-chenois-thonex'],
                'geneva-stade-chenois-thonex',
            ],
            'pa only' => [
                ['pa_intersoccer-venues' => 'geneva-college-sismondi-nations'],
                'geneva-college-sismondi-nations',
            ],
        ];
    }

    /** @dataProvider prepareRosterTimesProvider */
    public function test_prepare_roster_entry_times_read(array $meta, string $expected): void {
        $entry = $this->invokePrepareRosterEntry($meta);
        $this->assertSame($expected, $entry['times']);
    }

    public static function prepareRosterTimesProvider(): array {
        return [
            'human label wins over pa slug' => [
                [
                    'Course Times' => 'Morning session',
                    'attribute_pa_course-times' => 'afternoon-slug',
                ],
                'morning-session',
            ],
            'attribute pa when no human label' => [
                ['attribute_pa_camp-times' => 'full-day'],
                'full-day',
            ],
            'pa only when no human label' => [
                ['pa_tournament-time' => '09-00-12-00'],
                '09-00-12-00',
            ],
        ];
    }

    /** @dataProvider prepareRosterTournamentDayProvider */
    public function test_prepare_roster_entry_tournament_day_read(array $meta, string $expected): void {
        $entry = $this->invokePrepareRosterEntry($meta, 'Tournament');
        $this->assertSame($expected, $entry['course_day']);
    }

    public static function prepareRosterTournamentDayProvider(): array {
        return [
            'human tournament day first' => [
                ['Tournament Day' => 'Sunday', 'attribute_pa_tournament-day' => 'saturday-slug'],
                'sunday',
            ],
            'attribute pa only' => [
                ['attribute_pa_tournament-day' => 'saturday-slug'],
                'saturday-slug',
            ],
            'pa only' => [
                ['pa_tournament-day' => 'friday-slug'],
                'friday-slug',
            ],
        ];
    }

    /** @dataProvider prepareRosterBookingTypeProvider */
    public function test_prepare_roster_entry_booking_type_read(array $meta, string $expected): void {
        $entry = $this->invokePrepareRosterEntry($meta);
        $this->assertSame($expected, $entry['booking_type']);
    }

    public static function prepareRosterBookingTypeProvider(): array {
        return [
            'attribute pa only' => [
                ['attribute_pa_booking-type' => 'full-term'],
                'full-term',
            ],
            'pa only' => [
                ['pa_booking-type' => 'single-days'],
                'single-days',
            ],
        ];
    }

    /** @dataProvider updateRosterEntryFacetProvider */
    public function test_update_roster_entry_event_facet_reads(array $meta, string $field, string $expected, string $product_type = 'camp'): void {
        $row = $this->invokeUpdateRosterEntry($meta, $product_type);
        $this->assertSame($expected, $row[$field]);
    }

    public static function updateRosterEntryFacetProvider(): array {
        return [
            'venue attribute only' => [
                ['attribute_pa_intersoccer-venues' => 'lausanne-centre'],
                'venue',
                'lausanne-centre',
            ],
            'venue pa only' => [
                ['pa_intersoccer-venues' => 'geneva-nations'],
                'venue',
                'geneva-nations',
            ],
            'times attribute only' => [
                ['attribute_pa_camp-times' => 'morning-camp'],
                'times',
                'morning-camp',
            ],
            'times prefers pa slug when attribute_pa_camp-times is present' => [
                ['Camp Times' => 'Afternoon camp', 'attribute_pa_camp-times' => 'morning-camp'],
                'times',
                'morning-camp',
            ],
            'tournament day attribute only' => [
                ['attribute_pa_tournament-day' => 'sunday-slug'],
                'course_day',
                'sunday-slug',
                'tournament',
            ],
            'booking type attribute only' => [
                ['attribute_pa_booking-type' => 'full-term'],
                'booking_type',
                'Full Term',
            ],
            'booking type pa only' => [
                ['pa_booking-type' => 'single-days'],
                'booking_type',
                'Single Day(s)',
            ],
        ];
    }

    public function test_enhanced_booking_report_sql_uses_one_join_per_meta_key(): void {
        $sql = $this->captureEnhancedBookingReportSql();
        $this->assertEnhancedReportSqlStructure($sql);
        $this->assertStringContainsString('activity_type_human.meta_key = \'Activity Type\'', $sql);
        $this->assertStringContainsString('activity_type_pa.meta_key = \'pa_activity-type\'', $sql);
        $this->assertStringContainsString('venue_sites.meta_key = \'Sites InterSoccer\'', $sql);
        $this->assertStringContainsString('canton_human.meta_key = \'Canton / Region\'', $sql);
        $this->assertDoesNotMatchRegularExpression(
            '/LEFT JOIN[^;]+meta_key = \'[^\']+\' OR [^\']+\'/',
            $sql
        );
    }

    public function test_final_reports_data_fallback_sql_uses_coalesced_pa_joins(): void {
        $sql = $this->extractFinalReportsWooFallbackSql();
        $this->assertStringContainsString("om_booking_type_attr.meta_key = 'attribute_pa_booking-type'", $sql);
        $this->assertStringContainsString('COALESCE(om_canonical_booking.meta_value, om_booking_type.meta_value, om_booking_type_attr.meta_value)', $sql);
        $this->assertDoesNotMatchRegularExpression(
            '/LEFT JOIN[^;]+meta_key = \'[^\']+\' OR [^\']+\'/',
            $sql
        );
    }

    public function test_merge_final_numbers_not_on_roster_sql_coalesces_venue_and_season(): void {
        $sql = $this->extractMergeFinalNumbersThinQuerySql();
        $this->assertStringContainsString("om_venue_attr.meta_key = 'attribute_pa_intersoccer-venues'", $sql);
        $this->assertStringContainsString(
            'COALESCE(om_season.meta_value, om_season_alt.meta_value, om_season_alt_attr.meta_value)',
            $sql
        );
    }

    /**
     * @param array<string,string> $meta
     * @return array<string,mixed>
     */
    private function invokePrepareRosterEntry(array $meta, string $activity_type = 'Camp'): array {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_product_callback;

        $this->installEmptyAttributeProductStub();

        $intersoccer_test_wc_order_item_data[self::ITEM_ID] = [
            'product_id' => self::PARENT_ID,
            'variation_id' => self::VARIATION_ID,
        ];
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = array_merge(
            [
                'Assigned Attendee' => 'Meta Reader Player',
                'Activity Type' => $activity_type,
            ],
            $meta
        );

        $item = new \WC_Order_Item_Product(self::ITEM_ID);
        $order = new \WC_Order(self::ORDER_ID);
        $order->set_items([self::ITEM_ID => $item]);

        set_error_handler(static function (int $severity, string $message): bool {
            if ($severity === E_WARNING && str_contains($message, 'Undefined variable $')) {
                return true;
            }

            return false;
        });
        try {
            $entry = intersoccer_prepare_roster_entry(
                $order,
                $item,
                self::ITEM_ID,
                self::ORDER_ID,
                '2026-01-15 12:00:00',
                []
            );
        } finally {
            restore_error_handler();
        }
        $this->assertIsArray($entry, 'intersoccer_prepare_roster_entry should return roster row data');

        return $entry;
    }

    /**
     * @param array<string,string> $meta
     * @return array<string,mixed>
     */
    private function invokeUpdateRosterEntry(array $meta, string $product_type = 'camp'): array {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_product_type_safe_callback;

        $this->installEmptyAttributeProductStub();
        $intersoccer_test_product_type_safe_callback = static function () use ($product_type) {
            return $product_type;
        };

        $intersoccer_test_wc_order_item_data[self::ITEM_ID] = [
            'product_id' => self::PARENT_ID,
            'variation_id' => self::VARIATION_ID,
            'subtotal' => 100.00,
            'total' => 100.00,
            'name' => 'Facet reader product',
        ];
        $intersoccer_test_wc_order_item_meta[self::ITEM_ID] = array_merge(
            [
                'Assigned Attendee' => 'Meta Reader Player',
                'Activity Type' => ucfirst($product_type),
            ],
            $meta
        );

        $item = new \WC_Order_Item_Product(self::ITEM_ID);
        $order = new \WC_Order(self::ORDER_ID);
        $order->set_items([self::ITEM_ID => $item]);
        $intersoccer_test_wc_get_order_callback = static function ($order_id) use ($order) {
            return (int) $order_id === self::ORDER_ID ? $order : false;
        };

        $capture = new class extends \InterSoccerTestWpdb {
            /** @var array<string,mixed> */
            public array $captured_replace = [];

            public function get_row($query = null, $output = OBJECT, $y = 0) {
                return null;
            }

            public function get_var($query = null, $x = 0, $y = 0) {
                return '0';
            }

            public function replace($table, $data, $format = null) {
                $this->captured_replace = is_array($data) ? $data : [];

                return 1;
            }
        };
        $wpdb = $capture;

        $this->assertTrue(
            intersoccer_update_roster_entry(self::ORDER_ID, self::ITEM_ID),
            'intersoccer_update_roster_entry should upsert using order-item meta readers'
        );
        $this->assertNotEmpty($capture->captured_replace);

        return $capture->captured_replace;
    }

    private function installEmptyAttributeProductStub(): void {
        global $intersoccer_test_wc_get_product_callback;

        $make_product = static function (int $id) {
            $product = Mockery::mock(\WC_Product::class);
            $product->shouldReceive('get_id')->andReturn($id);
            $product->shouldReceive('get_name')->andReturn('Stub product');
            $product->shouldReceive('get_attribute')->andReturn('');
            $product->shouldReceive('get_attributes')->andReturn([]);
            $product->shouldReceive('is_type')->andReturn(false);

            return $product;
        };

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($make_product) {
            $id = (int) $id;
            if (in_array($id, [self::PARENT_ID, self::VARIATION_ID], true)) {
                return $make_product($id);
            }

            return false;
        };
    }

    private function captureEnhancedBookingReportSql(): string {
        global $wpdb;
        $capture = new class extends \InterSoccerTestWpdb {
            public string $captured_sql = '';

            public function get_results($query = null, $output = OBJECT) {
                $this->captured_sql = (string) $query;
                return [];
            }
        };
        $wpdb = $capture;
        intersoccer_get_enhanced_booking_report('2026-01-01', '2026-01-31');
        return $capture->captured_sql;
    }

    private function assertEnhancedReportSqlStructure(string $sql): void {
        $this->assertStringContainsString('NULLIF(activity_type_human.meta_value', $sql);
        $this->assertStringContainsString('NULLIF(venue_pa.meta_value', $sql);
        $this->assertStringContainsString('NULLIF(canton_pa.meta_value', $sql);
    }

    private function extractFinalReportsWooFallbackSql(): string {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/reports-data.php');
        $anchor = '$woo_where_clause = implode';
        $anchor_pos = (int) strpos($source, $anchor);
        $this->assertGreaterThan(0, $anchor_pos);
        $query_pos = (int) strpos($source, '$query = $wpdb->prepare(', $anchor_pos);
        $this->assertGreaterThan(0, $query_pos);
        return substr($source, $query_pos, 6500);
    }

    private function extractMergeFinalNumbersThinQuerySql(): string {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/reports-data.php');
        $start = (int) strpos($source, 'function intersoccer_reports_merge_final_numbers_not_on_roster_rows');
        $this->assertGreaterThan(0, $start);
        $chunk = substr($source, $start, 6000);
        $thin = (int) strpos($chunk, '$thin_query = $wpdb->prepare(');
        $this->assertGreaterThan(0, $thin);
        return substr($chunk, $thin, 2500);
    }
}
