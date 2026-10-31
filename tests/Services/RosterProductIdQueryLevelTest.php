<?php
/**
 * Query-level coverage: roster listing GROUP BY, details/export filters by canonical product_id + WPML twins.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;

/**
 * In-memory wpdb for roster product_id filter and listing queries.
 */
class RosterProductIdQueryLevelWpdb extends \InterSoccerTestWpdb {

    /** @var array<int,array<string,mixed>> */
    public array $roster_rows = [];

    /** @var string[] */
    public array $captured_queries = [];

    /** @var array<int,array<string,mixed>>|null */
    public ?array $listing_results_override = null;

    public function get_col($query = null, $x = 0) {
        $q = (string) $query;
        $this->captured_queries[] = $q;

        if (stripos($q, 'DESCRIBE') !== false) {
            return ['id', 'product_id', 'product_name', 'event_signature', 'activity_type', 'season', 'venue'];
        }

        if (preg_match("/SELECT DISTINCT product_id FROM .* WHERE product_name = '([^']+)'/", $q, $m)) {
            $name = stripcslashes($m[1]);
            $ids = [];
            foreach ($this->roster_rows as $row) {
                if (($row['product_name'] ?? '') === $name && (int) ($row['product_id'] ?? 0) > 0) {
                    $ids[(int) $row['product_id']] = (int) $row['product_id'];
                }
            }

            return array_map('strval', array_values($ids));
        }

        if (stripos($q, 'SELECT DISTINCT event_signature') !== false) {
            return $this->eventSignaturesMatchingProductFilter($q);
        }

        return parent::get_col($q, $x);
    }

    public function get_results($query = null, $output = OBJECT) {
        $q = (string) $query;
        $this->captured_queries[] = $q;
        $this->last_query = $q;

        if ($this->listing_results_override !== null && stripos($q, 'FROM ' . $this->prefix . 'intersoccer_rosters r') !== false) {
            $rows = $this->listing_results_override;
            if ($output === ARRAY_A) {
                return $rows;
            }

            return array_map(static function ($row) {
                return (object) $row;
            }, $rows);
        }

        return parent::get_results($q, $output);
    }

    /**
     * @return string[]
     */
    private function eventSignaturesMatchingProductFilter(string $sql): array {
        if (!preg_match('/product_id IN \\(([^)]+)\\)/', $sql, $m)) {
            if (preg_match("/product_name = '([^']+)'/", $sql, $nm)) {
                $name = stripcslashes($nm[1]);
                $sigs = [];
                foreach ($this->roster_rows as $row) {
                    if (($row['product_name'] ?? '') === $name && ($row['event_signature'] ?? '') !== '') {
                        $sigs[(string) $row['event_signature']] = (string) $row['event_signature'];
                    }
                }

                return array_values($sigs);
            }

            return [];
        }

        $id_strings = array_map('trim', explode(',', $m[1]));
        $allowed = [];
        foreach ($id_strings as $part) {
            $allowed[(int) $part] = true;
        }

        $sigs = [];
        foreach ($this->roster_rows as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid > 0 && isset($allowed[$pid]) && ($row['event_signature'] ?? '') !== '') {
                $sigs[(string) $row['event_signature']] = (string) $row['event_signature'];
            }
        }

        return array_values($sigs);
    }
}

class RosterProductIdQueryLevelTest extends TestCase {

    private const EN_PID = 9003;
    private const FR_PID = 9103;
    private const EN_NAME = 'SYNTH Course C EN';
    private const EVENT_SIG = 'sig_rr140_query_level';

    /** @var RosterProductIdQueryLevelWpdb|null */
    private $wpdb = null;

    /** @var mixed */
    private $saved_wpdb = null;

    protected function setUp(): void {
        parent::setUp();

        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
        require_once dirname(__DIR__, 2) . '/includes/roster-data.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-export-all-handler.php';

        global $wpdb;
        $this->saved_wpdb = $wpdb;
        $this->wpdb = new RosterProductIdQueryLevelWpdb();
        $wpdb = $this->wpdb;

        $this->seedTwinProductRows();
        $this->stubWpmlAndEnglishCatalogue();
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->saved_wpdb;
        unset(
            $GLOBALS['intersoccer_test_apply_filters_callback'],
            $GLOBALS['intersoccer_test_wc_get_product_callback']
        );
        parent::tearDown();
    }

    private function stubWpmlAndEnglishCatalogue(): void {
        $map = [self::FR_PID => self::EN_PID, self::EN_PID => self::EN_PID];
        $en_pid = self::EN_PID;
        $fr_pid = self::FR_PID;
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) use ($map, $en_pid, $fr_pid) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id' && isset($args[0]) && $args[0] === 'product') {
                $id = (int) $value;
                $lang = $args[2] ?? null;
                if ($lang === 'fr' && $id === $en_pid) {
                    return $fr_pid;
                }
                if ($lang === 'en' && isset($map[$id])) {
                    return (int) $map[$id];
                }
                if (isset($map[$id])) {
                    return (int) $map[$id];
                }

                return $id;
            }
            if ($hook === 'wpml_active_languages') {
                return ['en' => [], 'fr' => []];
            }

            return $value;
        };

        $en_name = self::EN_NAME;
        $en_pid = self::EN_PID;
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($en_name, $en_pid) {
            $id = (int) $id;
            if ($id === $en_pid) {
                return new class ($en_name) {
                    private $title;

                    public function __construct($title) {
                        $this->title = $title;
                    }

                    public function get_name() {
                        return $this->title;
                    }
                };
            }

            return false;
        };
    }

    private function seedTwinProductRows(): void {
        $base = [
            'product_name' => self::EN_NAME,
            'venue' => 'Geneva',
            'age_group' => '5-12y',
            'times' => '1000-1130',
            'start_date' => '2026-01-05',
            'event_signature' => self::EVENT_SIG,
            'variation_ids' => '',
            'total_players' => 1,
            'activity_type' => 'Course',
            'course_day' => 'Monday',
            'camp_terms' => '',
        ];

        $this->wpdb->roster_rows = [
            $base + [
                'product_id' => self::FR_PID,
                'order_item_id' => 88001,
            ],
            $base + [
                'product_id' => self::EN_PID,
                'order_item_id' => 88002,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listingRowCourseAggregated(): array {
        return [[
            'product_id' => self::EN_PID,
            'product_name' => self::EN_NAME,
            'course_day' => 'Monday',
            'venue' => 'Geneva',
            'age_group' => '5-12y',
            'times' => '1000-1130',
            'start_date' => '2026-01-05',
            'event_signature' => self::EVENT_SIG,
            'total_players' => 2,
            'order_item_ids' => '88001,88002',
            'variation_ids' => '',
        ]];
    }

    /**
     * @param callable(): array<string,array<string,mixed>> $invoke
     * @param string                                          $activity_snippet
     */
    private function assertListingSqlAndSingleEnglishGroup(callable $invoke, string $activity_snippet): void {
        $this->wpdb->listing_results_override = $this->listingRowCourseAggregated();
        $this->wpdb->captured_queries = [];

        $grouped = $invoke();
        $this->assertCount(1, $grouped, 'Expected one listing group for EN+FR rows sharing canonical facets.');
        $first = reset($grouped);
        $this->assertSame(self::EN_NAME, $first['product_name']);

        $listing_sql = '';
        foreach ($this->wpdb->captured_queries as $q) {
            if (stripos($q, 'FROM ' . $this->wpdb->prefix . 'intersoccer_rosters r') !== false) {
                $listing_sql = $q;
                break;
            }
        }
        $this->assertNotSame('', $listing_sql, 'Listing query should hit fake wpdb.');
        $this->assertStringNotContainsString('product_name', stristr($listing_sql, 'GROUP BY') ?: '', 'GROUP BY must not use product_name.');
        $this->assertStringContainsString('GROUP BY r.event_signature, r.product_id', $listing_sql);
        $this->assertStringContainsString($activity_snippet, $listing_sql);
    }

    public function test_camp_variations_query_groups_one_english_label(): void {
        $camp_row = [[
            'product_id' => self::EN_PID,
            'product_name' => self::EN_NAME,
            'camp_terms' => 'Week 1',
            'venue' => 'Geneva',
            'age_group' => '5-12y',
            'times' => '1000-1130',
            'start_date' => '2026-01-05',
            'event_signature' => self::EVENT_SIG,
            'total_players' => 2,
            'order_item_ids' => '88003,88004',
            'variation_ids' => '',
        ]];
        $this->wpdb->listing_results_override = $camp_row;
        $grouped = intersoccer_pe_get_camp_variations([]);
        $this->assertCount(1, $grouped);
        $this->assertSame(self::EN_NAME, reset($grouped)['product_name']);
    }

    public function test_course_variations_query_groups_one_english_label(): void {
        $this->assertListingSqlAndSingleEnglishGroup(
            static function () {
                return intersoccer_pe_get_course_variations([]);
            },
            "r.activity_type = 'Course'"
        );
    }

    public function test_girls_only_variations_query_groups_one_english_label(): void {
        $this->wpdb->listing_results_override = [[
            'product_id' => self::EN_PID,
            'product_name' => self::EN_NAME,
            'camp_terms' => 'Week 1',
            'venue' => 'Geneva',
            'age_group' => '5-12y',
            'times' => '1000-1130',
            'shirt_size' => 'M',
            'shorts_size' => 'M',
            'start_date' => '2026-01-05',
            'event_signature' => self::EVENT_SIG,
            'total_players' => 2,
            'order_item_ids' => '88004',
            'variation_ids' => '',
        ]];
        $grouped = intersoccer_pe_get_girls_only_variations([]);
        $this->assertCount(1, $grouped);
        $this->assertSame(self::EN_NAME, reset($grouped)['product_name']);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function filterRowsLikeRosterDetails(int $filter_product_id, string $legacy_product_name = ''): array {
        $where_clauses = [];
        $query_params = [];
        intersoccer_roster_append_product_id_where_clause(
            $where_clauses,
            $query_params,
            $filter_product_id,
            $legacy_product_name,
            'r.product_id'
        );

        $allowed = [];
        foreach ($query_params as $param) {
            if (is_int($param) || (is_string($param) && ctype_digit($param))) {
                $allowed[(int) $param] = true;
            }
        }

        if ($allowed === [] && $legacy_product_name !== '' && $where_clauses !== []) {
            $last = end($where_clauses);
            if (is_string($last) && strpos($last, 'r.product_name') !== false) {
                return array_values(array_filter(
                    $this->wpdb->roster_rows,
                    static function ($row) use ($legacy_product_name) {
                        return ($row['product_name'] ?? '') === $legacy_product_name;
                    }
                ));
            }
        }

        return array_values(array_filter(
            $this->wpdb->roster_rows,
            static function ($row) use ($allowed) {
                $pid = (int) ($row['product_id'] ?? 0);

                return $pid > 0 && isset($allowed[$pid]);
            }
        ));
    }

    public function test_roster_details_style_filter_by_en_product_id_returns_both_twins(): void {
        $rows = $this->filterRowsLikeRosterDetails(self::EN_PID);
        $this->assertCount(2, $rows);
        $pids = array_map(static function ($r) {
            return (int) $r['product_id'];
        }, $rows);
        sort($pids);
        $this->assertSame([self::EN_PID, self::FR_PID], $pids);

        $where = [];
        $params = [];
        intersoccer_roster_append_product_id_where_clause($where, $params, self::EN_PID, '', 'r.product_id');
        $this->assertSame('r.product_id IN (%d,%d)', $where[0]);
        $this->assertNotContains('r.product_name = %s', $where);
    }

    public function test_roster_details_style_filter_by_fr_twin_product_id_returns_both_twins(): void {
        $rows = $this->filterRowsLikeRosterDetails(self::FR_PID);
        $this->assertCount(2, $rows);
    }

    public function test_roster_details_legacy_product_name_filter_maps_to_product_id_in(): void {
        $rows = $this->filterRowsLikeRosterDetails(0, self::EN_NAME);
        $this->assertCount(2, $rows);

        $where = [];
        $params = [];
        intersoccer_roster_append_product_id_where_clause($where, $params, 0, self::EN_NAME, 'r.product_id');
        $this->assertStringContainsString('IN', $where[0]);
        $this->assertNotSame('r.product_name = %s', $where[0]);
    }

    public function test_export_all_signatures_filter_by_product_id_includes_both_twins(): void {
        $this->wpdb->captured_queries = [];
        $sigs = intersoccer_export_all_rosters_signatures_sql(['product_id' => (string) self::EN_PID], []);
        $this->assertSame([self::EVENT_SIG], $sigs);

        $sql = end($this->wpdb->captured_queries);
        $this->assertIsString($sql);
        $this->assertStringContainsString('product_id IN', $sql);
        $this->assertStringNotContainsString('product_name =', $sql);
    }

    public function test_export_other_events_signatures_filter_by_fr_twin_product_id(): void {
        $sigs = intersoccer_export_other_events_signatures_sql(['product_id' => (string) self::FR_PID], []);
        $this->assertContains(self::EVENT_SIG, $sigs);
    }
}
