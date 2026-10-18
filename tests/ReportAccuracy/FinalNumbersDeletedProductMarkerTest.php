<?php
/**
 * Final Numbers read-time deleted-product marker (#125) via intersoccer_get_final_reports_data.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/ReportAccuracyFixtures.php';
require_once __DIR__ . '/DeletedProductRosterTest.php';

class MarkerTestWpdb extends DeletedProductWpdb {
    public function get_results($query = null, $output = OBJECT) {
        if (preg_match('/SELECT ID, post_type FROM \S+posts WHERE ID IN/', (string) $query)) {
            $this->queries[] = (string) $query;
            $rows = [];
            if (preg_match_all('/\b(\d{4,})\b/', (string) $query, $m)) {
                foreach (array_unique(array_map('intval', $m[1])) as $id) {
                    $obj = DeletedProductFixture::$catalogue[$id] ?? null;
                    if ($obj) {
                        $type = $obj instanceof \WC_Product_Variation ? 'product_variation' : 'product';
                        $rows[] = ['ID' => $id, 'post_type' => $type];
                    }
                }
            }
            return $rows;
        }
        return parent::get_results($query, $output);
    }
}

class FinalNumbersDeletedProductMarkerTest extends TestCase {

    /** @var MarkerTestWpdb */
    private $wpdb;
    private $previous_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        $previous_log = ini_set('error_log', '/dev/null');
        foreach (['utils.php', 'order-meta-keys.php', 'final-reports-totals.php', 'final-reports-aggregation.php', 'reports-data.php'] as $f) {
            if (file_exists($includes . '/' . $f)) {
                require_once $includes . '/' . $f;
            }
        }
        ini_set('error_log', (string) $previous_log);
        ReportAccuracyStore::reset();
        DeletedProductFixture::resetCatalogue();
        $this->wpdb = new MarkerTestWpdb();
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previous_wpdb;
        parent::tearDown();
    }

    public function test_course_missing_catalogue_post_sets_has_deleted_product_without_stored_flag(): void {
        $row = $this->courseRosterRow(DeletedProductFixture::DELETED_PID, DeletedProductFixture::DELETED_VID, null);
        $this->wpdb->roster_rows = [$row];

        $data = $this->finalReportsData('Course');
        $this->assertTrue($this->courseReportHasDeletedMarker($data));
        $this->assertSame(1, $this->courseRegistrations($data));
    }

    public function test_course_stored_product_deleted_flag_sets_has_deleted_product(): void {
        $row = $this->courseRosterRow(DeletedProductFixture::LIVE_PID, DeletedProductFixture::LIVE_VID, ['product_deleted' => true]);
        $this->wpdb->roster_rows = [$row];

        $data = $this->finalReportsData('Course');
        $this->assertTrue($this->courseReportHasDeletedMarker($data));
    }

    public function test_course_live_catalogue_row_has_no_deleted_marker(): void {
        $row = $this->courseRosterRow(DeletedProductFixture::LIVE_PID, DeletedProductFixture::LIVE_VID, null);
        $this->wpdb->roster_rows = [$row];

        $data = $this->finalReportsData('Course');
        $this->assertFalse($this->courseReportHasDeletedMarker($data));
    }

    public function test_camp_missing_catalogue_post_sets_has_deleted_product(): void {
        $row = $this->campRosterRow(DeletedProductFixture::DELETED_PID, DeletedProductFixture::DELETED_VID, null);
        $this->wpdb->roster_rows = [$row];

        $data = $this->finalReportsData('Camp');
        $this->assertTrue($this->campReportHasDeletedMarker($data));
        $this->assertSame(1, $this->campTotalPlaces($data));
    }

    public function test_deleted_marker_does_not_change_course_registration_totals(): void {
        $missing = $this->courseRosterRow(DeletedProductFixture::DELETED_PID, DeletedProductFixture::DELETED_VID, null);
        $live = $this->courseRosterRow(DeletedProductFixture::LIVE_PID, DeletedProductFixture::LIVE_VID, null);
        $live['order_item_id'] = 62002;
        $live['order_id'] = 96202;

        $this->wpdb->roster_rows = [$missing];
        $with_marker = $this->courseRegistrations($this->finalReportsData('Course'));

        $this->wpdb->roster_rows = [$live];
        $without_marker = $this->courseRegistrations($this->finalReportsData('Course'));

        $this->assertSame($with_marker, $without_marker);
        $this->assertSame(1, $with_marker);
    }

    /**
     * @param array<string,mixed>|null $event_details_extra
     * @return array<string,mixed>
     */
    private function courseRosterRow(int $product_id, int $variation_id, ?array $event_details_extra): array {
        $event_details = $event_details_extra !== null ? json_encode($event_details_extra) : null;
        return [
            'roster_row_id' => 1,
            'order_item_id' => 62001,
            'order_id' => 96201,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'product_name' => 'Synthetic Sunday Course',
            'canton' => 'Geneva',
            'venue' => 'Venue A',
            'course_day' => 'sunday',
            'times' => '11:15-12:45',
            'season' => 'Winter 2026',
            'roster_season' => 'Winter 2026',
            'activity_type' => 'Course',
            'roster_activity_type' => 'Course',
            'start_date' => '2026-01-11',
            'roster_start_date' => '2026-01-11',
            'end_date' => '2026-02-22',
            'roster_end_date' => '2026-02-22',
            'girls_only' => 0,
            'booking_type' => 'Full Term',
            'age_group' => '5-12y',
            'event_details' => $event_details,
            'order_item_name' => 'Synthetic Sunday Course',
            'post_date' => '2026-01-12 10:00:00',
            'line_subtotal' => '300',
            'line_total' => '300',
            'line_quantity' => '1',
        ];
    }

    /**
     * @param array<string,mixed>|null $event_details_extra
     * @return array<string,mixed>
     */
    private function campRosterRow(int $product_id, int $variation_id, ?array $event_details_extra): array {
        $event_details = $event_details_extra !== null ? json_encode($event_details_extra) : null;
        return [
            'order_item_id' => 63001,
            'order_id' => 96301,
            'canton' => 'Geneva',
            'region' => 'Geneva',
            'venue' => 'Placement Venue',
            'camp_terms' => 'Week 1',
            'season' => 'Summer 2026',
            'booking_type' => 'Full Week',
            'selected_days' => '',
            'age_group' => '5-13y Full Day',
            'activity_type' => 'Camp',
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'product_name' => 'Synthetic Camp',
            'girls_only' => 0,
            'event_details' => $event_details,
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-10',
            'line_subtotal' => '250',
            'line_total' => '250',
            'line_quantity' => '1',
            'program_year' => '2026',
            'post_date' => '2026-06-01 10:00:00',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function finalReportsData(string $activity_type): array {
        $previous_log = ini_set('error_log', '/dev/null');
        try {
            $data = intersoccer_get_final_reports_data(2026, $activity_type);
        } finally {
            ini_set('error_log', (string) $previous_log);
        }
        return is_array($data) ? $data : [];
    }

    private function courseRegistrations(array $data): int {
        $totals = intersoccer_calculate_final_reports_totals($data, 'Course');
        return (int) ($totals['all']['registrations'] ?? 0);
    }

    private function campTotalPlaces(array $data): int {
        $totals = intersoccer_calculate_final_reports_totals($data, 'Camp');
        return (int) ($totals['all']['total'] ?? 0);
    }

    private function courseReportHasDeletedMarker(array $data): bool {
        foreach ($data as $season => $regions) {
            if ($season === '__player_registration_totals__' || !is_array($regions)) {
                continue;
            }
            foreach ($regions as $region_rows) {
                if (!is_array($region_rows)) {
                    continue;
                }
                foreach ($region_rows as $row) {
                    if (is_array($row) && !empty($row['has_deleted_product'])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function campReportHasDeletedMarker(array $data): bool {
        foreach ($data as $week => $cantons) {
            if ($week === '__player_registration_totals__' || !is_array($cantons)) {
                continue;
            }
            foreach ($cantons as $venues) {
                if (!is_array($venues)) {
                    continue;
                }
                foreach ($venues as $venue_data) {
                    if (!is_array($venue_data)) {
                        continue;
                    }
                    foreach ($venue_data as $metrics) {
                        if (is_array($metrics) && !empty($metrics['has_deleted_product'])) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }
}
