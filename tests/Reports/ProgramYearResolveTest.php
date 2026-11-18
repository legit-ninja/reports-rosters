<?php

namespace InterSoccer\Reports\Tests\Reports;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\ReportsRostersDiagnosticsService;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Program Year is read before Season text. Signature strings for seasons that
 * already contain a year are frozen from master 81c249b.
 */
class ProgramYearResolveTest extends TestCase {
    private const CAMP_FIXTURE = [
        'activity_type' => 'Camp',
        'venue' => 'geneva-venue',
        'age_group' => '5-12',
        'camp_terms' => 'summer-week-1',
        'course_day' => 'monday',
        'times' => '0900-1200',
        'city' => 'geneva',
        'canton_region' => 'ge',
        'product_id' => 4242,
        'girls_only' => 0,
    ];

    /**
     * Master generate() input for Season "Winter 2027" with no Program Year.
     * md5 matches intersoccer_generate_event_signature() on 81c249b.
     */
    private const MASTER_WINTER_2027_STRING = 'activity_type:camp|venue:geneva-venue|age_group:5-12|camp_terms:summer-week-1|course_day:|times:0900-1200|season:winter-2027|girls_only:0|city:geneva|canton_region:|product_id:4242';

    private const MASTER_WINTER_2027_HASH = '57b028120183a43369cf0316fbd2e351';

    private const MASTER_WINTER_STRING = 'activity_type:camp|venue:geneva-venue|age_group:5-12|camp_terms:summer-week-1|course_day:|times:0900-1200|season:winter|girls_only:0|city:geneva|canton_region:|product_id:4242';

    private const MASTER_WINTER_HASH = '35a9015602dc00e8399b6e7934f6d88f';

    private const EVERGREEN_2026_STRING = self::MASTER_WINTER_STRING . '|program_year:2026';

    private const EVERGREEN_2026_HASH = '64f406760132d927e0f64ec21ab46ad7';

    private const EVERGREEN_2027_STRING = self::MASTER_WINTER_STRING . '|program_year:2027';

    private const EVERGREEN_2027_HASH = '8f1a0307b9404568332846abe4bfe2cc';

    /** @var mixed */
    private $wpdb_backup;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->wpdb_backup = $wpdb;
        if (!function_exists('intersoccer_extract_year_from_season')) {
            require_once dirname(__DIR__, 2) . '/includes/reports-data.php';
        }
        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(true);
        }
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->wpdb_backup;
        $GLOBALS['intersoccer_test_wc_order_item_meta'] = [];
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = null;
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = null;
        if (class_exists('InterSoccerRRTestMeta')) {
            \InterSoccerRRTestMeta::reset();
        }
        if (class_exists(\InterSoccer\ReportsRosters\Tests\ReportAccuracy\ReportAccuracyStore::class, false)) {
            unset(\InterSoccer\ReportsRosters\Tests\ReportAccuracy\ReportAccuracyStore::$item_meta[7701]);
        }
        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(false);
        }
        parent::tearDown();
    }

    public function test_helper_prefers_order_meta_then_variation_then_product_then_season(): void {
        $this->assertSame(
            2027,
            intersoccer_resolve_program_year(['Year' => '2027'], 0, 0, 'Winter 2024')
        );
        $this->assertSame(
            2028,
            intersoccer_resolve_program_year(['Année' => '2028'], 0, 0, 'Winter')
        );
        $this->assertSame(
            2026,
            intersoccer_resolve_program_year(['pa_program-year' => '2026'], 0, 0, '')
        );
        $this->assertSame(
            2025,
            intersoccer_resolve_program_year(['attribute_pa_program-year' => ['2025']], 0, 0, 'Winter')
        );

        \InterSoccerRRTestMeta::$data[55]['attribute_pa_program-year'] = '2026';
        \InterSoccerRRTestMeta::$data[66]['attribute_pa_program-year'] = '2025';
        $this->assertSame(
            2026,
            intersoccer_resolve_program_year([], 55, 66, 'Winter 2024')
        );
        $this->assertSame(
            2025,
            intersoccer_resolve_program_year([], 0, 66, 'Winter 2024')
        );

        $GLOBALS['intersoccer_test_wc_order_item_meta'][77] = ['Year' => '2027'];
        $this->assertSame(2027, intersoccer_resolve_program_year(77, 55, 66, 'Winter'));

        $this->assertSame(2027, intersoccer_resolve_program_year([], 0, 0, 'Winter 2027'));
        $this->assertSame(
            intersoccer_extract_year_from_season('Winter 2027'),
            intersoccer_resolve_program_year([], 0, 0, 'Winter 2027')
        );
        $this->assertNull(intersoccer_resolve_program_year([], 0, 0, 'Winter'));
        $this->assertNull(intersoccer_resolve_program_year([], 0, 0, 'N/A'));
    }

    public function test_year_in_season_signature_matches_master_even_when_program_year_is_present(): void {
        $row = self::CAMP_FIXTURE;
        $row['season'] = 'Winter 2027';

        $this->assertSame(self::MASTER_WINTER_2027_STRING, intersoccer_event_signature_input_string($row));
        $this->assertSame(self::MASTER_WINTER_2027_HASH, intersoccer_generate_event_signature($row));
        $this->assertSame(self::MASTER_WINTER_2027_HASH, intersoccer_event_signature_from_event_data($row));

        $row['Year'] = '2028';
        $row['variation_id'] = 55;
        \InterSoccerRRTestMeta::$data[55]['attribute_pa_program-year'] = '2026';
        $this->assertSame(self::MASTER_WINTER_2027_STRING, intersoccer_event_signature_input_string($row));
        $this->assertSame(self::MASTER_WINTER_2027_HASH, intersoccer_generate_event_signature($row));
        $this->assertSame(self::MASTER_WINTER_2027_HASH, intersoccer_event_signature_from_event_data($row));
    }

    public function test_evergreen_winter_program_years_do_not_share_a_signature(): void {
        $bare = self::CAMP_FIXTURE;
        $bare['season'] = 'Winter';
        $this->assertSame(self::MASTER_WINTER_STRING, intersoccer_event_signature_input_string($bare));
        $this->assertSame(self::MASTER_WINTER_HASH, intersoccer_generate_event_signature($bare));

        $y2026 = $bare;
        $y2026['Year'] = '2026';
        $y2026['product_id'] = 4242;
        $y2027 = $bare;
        $y2027['program_year'] = '2027';
        $y2027['product_id'] = 4242;

        $this->assertSame(self::EVERGREEN_2026_STRING, intersoccer_event_signature_input_string($y2026));
        $this->assertSame(self::EVERGREEN_2026_HASH, intersoccer_generate_event_signature($y2026));
        $this->assertSame(self::EVERGREEN_2027_STRING, intersoccer_event_signature_input_string($y2027));
        $this->assertSame(self::EVERGREEN_2027_HASH, intersoccer_generate_event_signature($y2027));
        $this->assertNotSame(
            intersoccer_generate_event_signature($y2026),
            intersoccer_generate_event_signature($y2027)
        );
        $this->assertNotSame(
            intersoccer_event_signature_from_event_data($y2026),
            intersoccer_event_signature_from_event_data($y2027)
        );
    }

    public function test_camp_date_fill_in_uses_program_year_before_season_then_order_date(): void {
        list($start, $end) = intersoccer_parse_camp_dates_fixed(
            'week-1-march-22-25-4-days',
            'Winter',
            2027,
            true
        );
        $this->assertSame('2027-03-22', $start);
        $this->assertSame('2027-03-25', $end);

        list($season_wins) = intersoccer_parse_camp_dates_fixed(
            'week-1-march-22-25-4-days',
            'Winter 2026',
            2024,
            false
        );
        $this->assertSame('2026-03-22', $season_wins);

        $GLOBALS['intersoccer_test_wc_order_item_meta'][901] = ['Year' => '2027'];
        list($start, $end) = intersoccer_reports_resolve_camp_schedule(0, 901, 'week-1-march-22-25-4-days', 'Winter');
        $this->assertSame('2027-03-22', $start);
        $this->assertSame('2027-03-25', $end);

        $this->assertSame(2027, intersoccer_camp_terms_parse_year(['Year' => '2027'], 0, 0, 'Winter', '2024-11-02'));
        $this->assertSame(2026, intersoccer_camp_terms_parse_year([], 0, 0, 'Winter 2026', '2024-11-02'));
        $this->assertSame(2024, intersoccer_camp_terms_parse_year([], 0, 0, 'Winter', '2024-11-02'));
    }

    public function test_diagnostics_season_year_matches_season_text_and_program_year_meta(): void {
        global $wpdb;
        $wpdb = new ProgramYearDiagWpdb();
        $wpdb->responses = [
            'season' => [11, 99],
            'year' => [22],
            'product' => [],
            'terms' => [],
        ];
        $orders = [
            11 => 'completed',
            22 => 'completed',
            99 => 'cancelled',
            33 => 'completed',
        ];
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = static function ($order_id) use ($orders) {
            $order_id = (int) $order_id;
            if (!isset($orders[$order_id])) {
                return false;
            }
            return new ProgramYearDiagOrder($orders[$order_id]);
        };

        $service = new ReportsRostersDiagnosticsService();
        $method = new ReflectionMethod($service, 'resolveOrderIdsBySeasonYear');
        $method->setAccessible(true);
        $both = $method->invoke($service, 2027, ['wc-completed']);
        sort($both);
        $this->assertSame([11, 22], $both);
        $this->assertNotEmpty($wpdb->queries);
        $this->assertStringContainsString('pa_program-season', $wpdb->queries[0]);
        $this->assertStringContainsString('LIKE', $wpdb->queries[0]);

        $wpdb->responses = [
            'season' => [],
            'year' => [],
            'product' => [33],
            'terms' => [],
        ];
        $wpdb->queries = [];
        $service = new ReportsRostersDiagnosticsService();
        $method = new ReflectionMethod($service, 'resolveOrderIdsBySeasonYear');
        $method->setAccessible(true);
        $this->assertSame([33], $method->invoke($service, 2027, ['wc-completed']));

        $wpdb->responses = [
            'season' => [],
            'year' => [],
            'product' => [],
            'terms' => [44],
        ];
        $orders[44] = 'completed';
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = static function ($order_id) use ($orders) {
            $order_id = (int) $order_id;
            if (!isset($orders[$order_id])) {
                return false;
            }
            return new ProgramYearDiagOrder($orders[$order_id]);
        };
        $service = new ReportsRostersDiagnosticsService();
        $method = new ReflectionMethod($service, 'resolveOrderIdsBySeasonYear');
        $method->setAccessible(true);
        $this->assertSame([44], $method->invoke($service, 2027, ['wc-completed']));
    }

    /**
     * Product has no Program Year attribute. The order item does. A rebuild that
     * only reads the roster table must hash the same signature as the full rebuild.
     */
    public function test_roster_table_rebuild_matches_full_rebuild_when_only_order_item_has_program_year(): void {
        $order_item_id = 7701;
        $product_id = 4242;
        $variation_id = 5501;
        $GLOBALS['intersoccer_test_wc_order_item_meta'][$order_item_id] = [
            'Program Year' => '2027',
        ];
        // Report-accuracy fixtures define a namespaced wc_get_order_item_meta that
        // RosterBuilder resolves first. Seed that store when it is loaded.
        if (class_exists(\InterSoccer\ReportsRosters\Tests\ReportAccuracy\ReportAccuracyStore::class, false)) {
            \InterSoccer\ReportsRosters\Tests\ReportAccuracy\ReportAccuracyStore::setItemMeta(
                $order_item_id,
                'Program Year',
                '2027',
                false
            );
        }
        \InterSoccerRRTestMeta::$data[$product_id] = [];
        \InterSoccerRRTestMeta::$data[$variation_id] = [];
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function () {
            return new ProgramYearBareProduct();
        };

        $row = self::CAMP_FIXTURE;
        $row['season'] = 'Winter';
        $row['variation_id'] = $variation_id;
        $row['order_item_id'] = $order_item_id;
        $row['start_date'] = '2027-03-22';

        $with_year = $row;
        $with_year['Program Year'] = '2027';
        $expected = intersoccer_event_signature_from_event_data($with_year);
        $this->assertSame(self::EVERGREEN_2027_HASH, $expected);
        $this->assertNotSame(self::MASTER_WINTER_HASH, $expected);

        $roster = new Roster($row);
        $roster->id = 81;
        $repo = new class($roster) extends RosterRepository {
            /** @var Roster */
            private $roster;

            /** @var string */
            public $signature = '';

            public function __construct(Roster $roster) {
                parent::__construct(new Logger());
                $this->roster = $roster;
            }

            public function all(array $columns = ['*']) {
                return new RostersCollection([$this->roster]);
            }

            public function updateEventSignature($id, string $signature) {
                $this->signature = $signature;
                return true;
            }
        };

        $builder = new RosterBuilder(new Logger(), null, null, $repo);
        $table_only = $builder->rebuildSignatures();
        $this->assertSame(1, $table_only['updated']);
        $this->assertSame(0, $table_only['errors']);
        $this->assertSame($expected, $repo->signature);

        if (!function_exists('intersoccer_rebuild_event_signatures')) {
            require_once dirname(__DIR__, 2) . '/includes/db.php';
        }

        global $wpdb;
        $db = new ProgramYearRebuildWpdb();
        $db->records = [[
            'id' => 81,
            'order_item_id' => $order_item_id,
            'activity_type' => $row['activity_type'],
            'venue' => $row['venue'],
            'age_group' => $row['age_group'],
            'camp_terms' => $row['camp_terms'],
            'course_day' => $row['course_day'],
            'times' => $row['times'],
            'season' => $row['season'],
            'girls_only' => $row['girls_only'],
            'city' => $row['city'],
            'canton_region' => $row['canton_region'],
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'product_name' => 'Synthetic Camp',
            'start_date' => '2027-03-22',
        ]];
        $wpdb = $db;

        $full = intersoccer_rebuild_event_signatures();
        $this->assertSame('success', $full['status']);
        $this->assertSame(1, $full['updated']);
        $this->assertArrayHasKey('event_signature', $db->updates[0]);
        $this->assertSame($repo->signature, $db->updates[0]['event_signature']);
    }
}

class ProgramYearDiagOrder {
    /** @var string */
    private $status;

    public function __construct($status) {
        $this->status = (string) $status;
    }

    public function get_status() {
        return $this->status;
    }
}

class ProgramYearDiagWpdb extends \InterSoccerTestWpdb {
    /** @var array<string,array<int,int>> */
    public $responses = [];

    /** @var array<int,string> */
    public $queries = [];

    public function get_col($query = null, $x = 0) {
        $sql = (string) $query;
        $this->queries[] = $sql;
        if (strpos($sql, "taxonomy = 'pa_program-year'") !== false) {
            return $this->responses['terms'] ?? [];
        }
        if (strpos($sql, 'wp_postmeta') !== false) {
            return $this->responses['product'] ?? [];
        }
        if (strpos($sql, 'pa_program-season') !== false) {
            return $this->responses['season'] ?? [];
        }
        if (strpos($sql, 'pa_program-year') !== false || strpos($sql, "'Year'") !== false) {
            return $this->responses['year'] ?? [];
        }
        return [];
    }
}

/**
 * Product with no Program Year attribute.
 */
class ProgramYearBareProduct {
    public function get_attribute($name) {
        return '';
    }

    public function get_parent_id() {
        return 0;
    }

    public function get_name() {
        return 'Synthetic Camp';
    }

    public function get_id() {
        return 4242;
    }
}

class ProgramYearRebuildWpdb extends \InterSoccerTestWpdb {
    /** @var array<int,array<string,mixed>> */
    public $records = [];

    /** @var array<int,array<string,mixed>> */
    public $updates = [];

    public function get_results($query = null, $output = OBJECT) {
        $sql = (string) $query;
        if (strpos($sql, 'intersoccer_rosters') !== false) {
            return $this->records;
        }
        return [];
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $this->updates[] = $data;
        return 1;
    }
}
