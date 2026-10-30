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
        $this->assertSame($expected, $this->prepareRosterVenueFromMeta($meta));
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
        $this->assertSame($expected, $this->prepareRosterTimesFromMeta($meta));
    }

    public static function prepareRosterTimesProvider(): array {
        return [
            'human label wins over pa slug' => [
                [
                    'Course Times' => 'Morning session',
                    'attribute_pa_course-times' => 'afternoon-slug',
                ],
                'Morning session',
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
        $this->assertSame($expected, $this->prepareRosterTournamentDayFromMeta($meta));
    }

    public static function prepareRosterTournamentDayProvider(): array {
        return [
            'human tournament day first' => [
                ['Tournament Day' => 'Sunday', 'attribute_pa_tournament-day' => 'saturday-slug'],
                'Sunday',
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

    /** @dataProvider updateRosterEntryFacetProvider */
    public function test_update_roster_entry_event_facet_reads(array $meta, string $field, string $expected): void {
        $resolved = $this->updateRosterEntryFacetsFromMeta($meta);
        $this->assertSame($expected, $resolved[$field]);
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
            'times human label after pa miss' => [
                ['Camp Times' => 'Afternoon camp', 'attribute_pa_camp-times' => 'morning-camp'],
                'times',
                'morning-camp',
            ],
            'tournament day attribute only' => [
                ['attribute_pa_tournament-day' => 'sunday-slug'],
                'tournament_day',
                'sunday-slug',
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
     * Mirrors intersoccer_prepare_roster_entry venue read (pa helper only; no product fallback).
     *
     * @param array<string,string> $order_item_meta
     */
    private function prepareRosterVenueFromMeta(array $order_item_meta): string {
        return intersoccer_get_order_item_pa_taxonomy_meta_value($order_item_meta, 'pa_intersoccer-venues');
    }

    /**
     * Mirrors intersoccer_prepare_roster_entry times read after RR #104 human-label-first fix.
     *
     * @param array<string,string> $order_item_meta
     */
    private function prepareRosterTimesFromMeta(array $order_item_meta): string {
        $read_pa_meta = static function (string $taxonomy) use ($order_item_meta): string {
            return intersoccer_get_order_item_pa_taxonomy_meta_value($order_item_meta, $taxonomy);
        };
        $times = $order_item_meta['Course Times'] ?? $order_item_meta['Camp Times'] ?? $order_item_meta['Tournament Time'] ?? null;
        if (!$times) {
            $times = $read_pa_meta('pa_course-times');
            if ($times === '') {
                $times = $read_pa_meta('pa_camp-times');
            }
            if ($times === '') {
                $times = $read_pa_meta('pa_tournament-time');
            }
        }

        return (string) $times;
    }

    /**
     * Mirrors intersoccer_prepare_roster_entry tournament day branch.
     *
     * @param array<string,string> $order_item_meta
     */
    private function prepareRosterTournamentDayFromMeta(array $order_item_meta): string {
        $course_day = $order_item_meta['Tournament Day'] ?? '';
        if ($course_day === '') {
            $course_day = intersoccer_get_order_item_pa_taxonomy_meta_value($order_item_meta, 'pa_tournament-day');
        }

        return (string) $course_day;
    }

    /**
     * Mirrors intersoccer_update_roster_entry event facet reads (utils.php).
     *
     * @param array<string,string> $item_meta
     * @return array{venue:string,times:string,tournament_day:string}
     */
    private function updateRosterEntryFacetsFromMeta(array $item_meta): array {
        $read_pa_item_meta = static function (string $taxonomy) use ($item_meta): string {
            return intersoccer_get_order_item_pa_taxonomy_meta_value($item_meta, $taxonomy);
        };
        $venue = $read_pa_item_meta('pa_intersoccer-venues');
        if ($venue === '') {
            $venue = $item_meta['Sites InterSoccer'] ?? $item_meta['InterSoccer Venues'] ?? '';
        }
        $times = $read_pa_item_meta('pa_camp-times');
        if ($times === '') {
            $times = $read_pa_item_meta('pa_course-times');
        }
        if ($times === '') {
            $times = $read_pa_item_meta('pa_tournament-time');
        }
        if ($times === '') {
            $times = $item_meta['Camp Times'] ?? $item_meta['Course Times'] ?? $item_meta['Tournament Time'] ?? '';
        }
        $tournament_day = $item_meta['Tournament Day'] ?? '';
        if ($tournament_day === '') {
            $tournament_day = $read_pa_item_meta('pa_tournament-day');
        }

        return [
            'venue' => $venue,
            'times' => $times,
            'tournament_day' => $tournament_day,
        ];
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
