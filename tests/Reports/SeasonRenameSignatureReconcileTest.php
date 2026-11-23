<?php

namespace InterSoccer\Reports\Tests\Reports;

use PHPUnit\Framework\TestCase;

/**
 * Rename-proof season signatures + scoped season/signature reconcile.
 *
 * Master 294cc6c strings stay byte-identical when the product's current
 * pa_program-season term name is unchanged (or the product has no season term).
 */
class SeasonRenameSignatureReconcileTest extends TestCase {
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

    private const COURSE_FIXTURE = [
        'activity_type' => 'Course',
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

    private const TOURNAMENT_FIXTURE = [
        'activity_type' => 'Tournament',
        'venue' => 'geneva-venue',
        'age_group' => '5-12',
        'camp_terms' => 'summer-week-1',
        'course_day' => 'monday',
        'times' => '0900-1200',
        'city' => 'geneva',
        'canton_region' => 'ge',
        'product_id' => 4242,
        'girls_only' => 0,
        'start_date' => '2026-06-15',
    ];

    /** @var mixed */
    private $wpdb_backup;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->wpdb_backup = $wpdb;
        if (!function_exists('intersoccer_extract_year_from_season')) {
            require_once dirname(__DIR__, 2) . '/includes/reports-data.php';
        }
        if (!function_exists('intersoccer_reconcile_roster_season_signatures')) {
            require_once dirname(__DIR__, 2) . '/includes/db.php';
        }
        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(true);
        }
        $GLOBALS['intersoccer_test_product_terms'] = [];
        $GLOBALS['intersoccer_test_terms_by_slug'] = [];
        $GLOBALS['intersoccer_test_terms_by_name'] = [];
        $GLOBALS['intersoccer_test_terms_by_id'] = [];
        $GLOBALS['intersoccer_test_wc_order_item_meta'] = [];
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->wpdb_backup;
        $GLOBALS['intersoccer_test_product_terms'] = [];
        $GLOBALS['intersoccer_test_terms_by_slug'] = [];
        $GLOBALS['intersoccer_test_terms_by_name'] = [];
        $GLOBALS['intersoccer_test_terms_by_id'] = [];
        $GLOBALS['intersoccer_test_wc_order_item_meta'] = [];
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = null;
        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(false);
        }
        parent::tearDown();
    }

    /**
     * @param int    $product_id
     * @param string $name
     * @param string $slug
     * @param int    $term_id
     */
    private function seedProductSeasonTerm($product_id, $name, $slug, $term_id = 9001): void {
        $term = (object) [
            'term_id' => $term_id,
            'name' => $name,
            'slug' => $slug,
            'taxonomy' => 'pa_program-season',
        ];
        $GLOBALS['intersoccer_test_product_terms'][(int) $product_id]['pa_program-season'] = [$term];
        $GLOBALS['intersoccer_test_terms_by_id'][$term_id] = $term;
        $GLOBALS['intersoccer_test_terms_by_slug']['pa_program-season'][strtolower($slug)] = $term;
        $name_key = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
        $GLOBALS['intersoccer_test_terms_by_name']['pa_program-season'][$name_key] = $term;
    }

    public function test_year_qualified_term_unchanged_is_byte_identical_to_master_camp(): void {
        $this->seedProductSeasonTerm(4242, 'Winter 2026', 'winter-2026', 9101);
        $row = self::CAMP_FIXTURE;
        $row['season'] = 'Winter 2026';
        $expected = 'activity_type:camp|venue:geneva-venue|age_group:5-12|camp_terms:summer-week-1|course_day:|times:0900-1200|season:winter-2026|girls_only:0|city:geneva|canton_region:|product_id:4242';
        $this->assertSame($expected, intersoccer_event_signature_input_string($row));
        $this->assertSame(md5($expected), intersoccer_generate_event_signature($row));
    }

    public function test_year_qualified_term_unchanged_is_byte_identical_for_course_and_tournament(): void {
        $this->seedProductSeasonTerm(4242, 'Winter 2026', 'winter-2026', 9102);

        $course = self::COURSE_FIXTURE;
        $course['season'] = 'Winter 2026';
        $course_expected = 'activity_type:course|venue:geneva-venue|age_group:5-12|camp_terms:|course_day:monday|times:0900-1200|season:winter-2026|girls_only:0|city:geneva|canton_region:|product_id:4242';
        $this->assertSame($course_expected, intersoccer_event_signature_input_string($course));

        $tournament = self::TOURNAMENT_FIXTURE;
        $tournament['season'] = 'Winter 2026';
        $tournament_expected = 'activity_type:tournament|venue:geneva-venue|age_group:5-12|camp_terms:|course_day:|times:0900-1200|season:winter-2026|girls_only:0|city:geneva|canton_region:ge|product_id:4242|start_date:2026-06-15';
        $this->assertSame($tournament_expected, intersoccer_event_signature_input_string($tournament));
    }

    public function test_no_product_season_term_keeps_text_based_master_string(): void {
        $row = self::CAMP_FIXTURE;
        $row['season'] = 'Winter 2027';
        $expected = 'activity_type:camp|venue:geneva-venue|age_group:5-12|camp_terms:summer-week-1|course_day:|times:0900-1200|season:winter-2027|girls_only:0|city:geneva|canton_region:|product_id:4242';
        $this->assertSame($expected, intersoccer_event_signature_input_string($row));
    }

    public function test_fr_de_saved_season_with_unchanged_product_term_matches_en_hash(): void {
        $this->seedProductSeasonTerm(4242, 'Winter 2026', 'winter-2026', 9103);
        $en = self::CAMP_FIXTURE;
        $en['season'] = 'Winter 2026';
        $fr = self::CAMP_FIXTURE;
        $fr['season'] = 'Hiver 2026';
        $de = self::CAMP_FIXTURE;
        $de['season'] = 'Winter 2026';
        $this->assertSame(
            intersoccer_event_signature_input_string($en),
            intersoccer_event_signature_input_string($fr)
        );
        $this->assertSame(
            intersoccer_generate_event_signature($en),
            intersoccer_generate_event_signature($de)
        );
    }

    public function test_renamed_evergreen_term_merges_old_and_new_season_rows(): void {
        $product_id = 5555;
        $this->seedProductSeasonTerm($product_id, 'Winter Courses', 'winter-2027', 9201);

        $old = self::COURSE_FIXTURE;
        $old['product_id'] = $product_id;
        $old['season'] = 'Winter 2027';
        $old['Year'] = '2027';

        $new = self::COURSE_FIXTURE;
        $new['product_id'] = $product_id;
        $new['season'] = 'Winter Courses';
        $new['Year'] = '2027';

        $old_string = intersoccer_event_signature_input_string($old);
        $new_string = intersoccer_event_signature_input_string($new);
        $this->assertSame($old_string, $new_string);
        $this->assertStringContainsString('season:winter-2027', $old_string);
        $this->assertStringContainsString('program_year:2027', $old_string);
        $this->assertSame('Winter Courses', intersoccer_roster_season_name_for_storage($old, 'Winter 2027'));
        $this->assertSame('Winter Courses', intersoccer_roster_season_name_for_storage($new, 'Winter Courses'));
    }

    public function test_scoped_reconcile_updates_only_listed_orders_and_is_idempotent(): void {
        global $wpdb;
        $product_id = 5555;
        $this->seedProductSeasonTerm($product_id, 'Winter Courses', 'winter-2027', 9202);

        $old_sig = md5('stale-old');
        $new_sig_seed = md5('stale-new');
        $db = new SeasonRenameReconcileWpdb([
            [
                'id' => 1,
                'order_id' => 100,
                'order_item_id' => 1001,
                'product_id' => $product_id,
                'variation_id' => 0,
                'activity_type' => 'Course',
                'venue' => 'geneva-venue',
                'age_group' => '5-12',
                'camp_terms' => '',
                'course_day' => 'monday',
                'times' => '0900-1200',
                'season' => 'Winter 2027',
                'girls_only' => 0,
                'city' => 'geneva',
                'canton_region' => '',
                'start_date' => '',
                'event_signature' => $old_sig,
            ],
            [
                'id' => 2,
                'order_id' => 200,
                'order_item_id' => 2001,
                'product_id' => $product_id,
                'variation_id' => 0,
                'activity_type' => 'Course',
                'venue' => 'geneva-venue',
                'age_group' => '5-12',
                'camp_terms' => '',
                'course_day' => 'monday',
                'times' => '0900-1200',
                'season' => 'Winter Courses',
                'girls_only' => 0,
                'city' => 'geneva',
                'canton_region' => '',
                'start_date' => '',
                'event_signature' => $new_sig_seed,
            ],
            [
                'id' => 3,
                'order_id' => 300,
                'order_item_id' => 3001,
                'product_id' => 9999,
                'variation_id' => 0,
                'activity_type' => 'Course',
                'venue' => 'geneva-venue',
                'age_group' => '5-12',
                'camp_terms' => '',
                'course_day' => 'monday',
                'times' => '0900-1200',
                'season' => 'Autumn 2026',
                'girls_only' => 0,
                'city' => 'geneva',
                'canton_region' => '',
                'start_date' => '',
                'event_signature' => md5('other'),
            ],
        ]);
        $wpdb = $db;

        $GLOBALS['intersoccer_test_wc_order_item_meta'][1001] = ['Year' => '2027'];
        $GLOBALS['intersoccer_test_wc_order_item_meta'][2001] = ['Year' => '2027'];

        $first = intersoccer_reconcile_roster_season_signatures([
            'order_ids' => [100, 200],
            'include_product_siblings' => false,
        ]);
        $this->assertSame(2, $first['updated']);
        $this->assertSame(0, $first['unchanged']);
        $this->assertCount(2, $db->updates);
        foreach ($db->updates as $update) {
            $this->assertArrayHasKey('season', $update['data']);
            $this->assertArrayHasKey('event_signature', $update['data']);
            $this->assertSame('Winter Courses', $update['data']['season']);
            $this->assertArrayNotHasKey('first_name', $update['data']);
        }
        $this->assertSame(
            $db->updates[0]['data']['event_signature'],
            $db->updates[1]['data']['event_signature']
        );

        // Apply written values so a second pass is idempotent.
        foreach ($db->updates as $update) {
            $id = (int) $update['where']['id'];
            foreach ($db->rows as &$row) {
                if ((int) $row['id'] === $id) {
                    $row['season'] = $update['data']['season'];
                    $row['event_signature'] = $update['data']['event_signature'];
                }
            }
            unset($row);
        }
        $db->updates = [];

        $second = intersoccer_reconcile_roster_season_signatures([
            'order_ids' => [100, 200],
            'include_product_siblings' => false,
        ]);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame([], $db->updates);

        // Product 9999 row must remain untouched.
        $this->assertSame(md5('other'), $db->rows[2]['event_signature']);
    }

    public function test_dry_run_writes_nothing_and_reports_group_counts(): void {
        global $wpdb;
        $product_id = 5555;
        $this->seedProductSeasonTerm($product_id, 'Winter Courses', 'winter-2027', 9203);
        $db = new SeasonRenameReconcileWpdb([
            [
                'id' => 11,
                'order_id' => 100,
                'order_item_id' => 1001,
                'product_id' => $product_id,
                'variation_id' => 0,
                'activity_type' => 'Course',
                'venue' => 'geneva-venue',
                'age_group' => '5-12',
                'camp_terms' => '',
                'course_day' => 'monday',
                'times' => '0900-1200',
                'season' => 'Winter 2027',
                'girls_only' => 0,
                'city' => 'geneva',
                'canton_region' => '',
                'start_date' => '',
                'event_signature' => md5('a'),
            ],
            [
                'id' => 12,
                'order_id' => 101,
                'order_item_id' => 1002,
                'product_id' => $product_id,
                'variation_id' => 0,
                'activity_type' => 'Course',
                'venue' => 'geneva-venue',
                'age_group' => '5-12',
                'camp_terms' => '',
                'course_day' => 'monday',
                'times' => '0900-1200',
                'season' => 'Winter Courses',
                'girls_only' => 0,
                'city' => 'geneva',
                'canton_region' => '',
                'start_date' => '',
                'event_signature' => md5('b'),
            ],
        ]);
        $wpdb = $db;
        $GLOBALS['intersoccer_test_wc_order_item_meta'][1001] = ['Year' => '2027'];
        $GLOBALS['intersoccer_test_wc_order_item_meta'][1002] = ['Year' => '2027'];

        $result = intersoccer_reconcile_roster_season_signatures([
            'product_ids' => [$product_id],
            'dry_run' => true,
        ]);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame([], $db->updates);
        $this->assertCount(2, $result['group_counts_before'][$product_id]);
        $this->assertCount(1, $result['group_counts_after'][$product_id]);
        $this->assertSame(md5('a'), $db->rows[0]['event_signature']);
    }

    public function test_product_siblings_option_expands_scope_from_orders(): void {
        global $wpdb;
        $product_id = 5555;
        $this->seedProductSeasonTerm($product_id, 'Winter Courses', 'winter-2027', 9204);
        $db = new SeasonRenameReconcileWpdb([
            [
                'id' => 21,
                'order_id' => 100,
                'order_item_id' => 1001,
                'product_id' => $product_id,
                'variation_id' => 0,
                'activity_type' => 'Course',
                'venue' => 'geneva-venue',
                'age_group' => '5-12',
                'camp_terms' => '',
                'course_day' => 'monday',
                'times' => '0900-1200',
                'season' => 'Winter 2027',
                'girls_only' => 0,
                'city' => 'geneva',
                'canton_region' => '',
                'start_date' => '',
                'event_signature' => md5('x'),
            ],
            [
                'id' => 22,
                'order_id' => 999,
                'order_item_id' => 9001,
                'product_id' => $product_id,
                'variation_id' => 0,
                'activity_type' => 'Course',
                'venue' => 'geneva-venue',
                'age_group' => '5-12',
                'camp_terms' => '',
                'course_day' => 'monday',
                'times' => '0900-1200',
                'season' => 'Winter Courses',
                'girls_only' => 0,
                'city' => 'geneva',
                'canton_region' => '',
                'start_date' => '',
                'event_signature' => md5('y'),
            ],
        ]);
        $wpdb = $db;
        $GLOBALS['intersoccer_test_wc_order_item_meta'][1001] = ['Year' => '2027'];
        $GLOBALS['intersoccer_test_wc_order_item_meta'][9001] = ['Year' => '2027'];

        $result = intersoccer_reconcile_roster_season_signatures([
            'order_ids' => [100],
            'include_product_siblings' => true,
        ]);
        $this->assertSame(2, $result['updated']);
        $this->assertCount(2, $db->updates);
    }
}

class SeasonRenameReconcileWpdb extends \InterSoccerTestWpdb {
    /** @var list<array<string,mixed>> */
    public $rows = [];

    /** @var list<array{data:array<string,mixed>,where:array<string,mixed>}> */
    public $updates = [];

    public function __construct(array $rows) {
        $this->rows = $rows;
        $this->prefix = 'wp_';
    }

    public function get_col($query = null, $x = 0) {
        $sql = (string) $query;
        if (stripos($sql, 'DISTINCT product_id') !== false) {
            $ids = [];
            foreach ($this->rows as $row) {
                if ((int) ($row['order_id'] ?? 0) === 100 || stripos($sql, 'order_id') === false) {
                    $ids[] = (int) ($row['product_id'] ?? 0);
                }
            }
            // Prefer parsing IN list when present.
            if (preg_match('/order_id IN \(([0-9,\s]+)\)/', $sql, $m)) {
                $wanted = array_map('intval', preg_split('/\s*,\s*/', $m[1]) ?: []);
                $ids = [];
                foreach ($this->rows as $row) {
                    if (in_array((int) ($row['order_id'] ?? 0), $wanted, true)) {
                        $ids[] = (int) ($row['product_id'] ?? 0);
                    }
                }
            }
            return array_values(array_unique(array_filter($ids)));
        }
        return [];
    }

    public function get_results($query = null, $output = OBJECT) {
        $sql = (string) $query;
        $filtered = $this->rows;
        if (preg_match('/order_id IN \(([0-9,\s]+)\)/', $sql, $m)) {
            $wanted = array_map('intval', preg_split('/\s*,\s*/', $m[1]) ?: []);
            $filtered = array_values(array_filter($filtered, static function ($row) use ($wanted) {
                return in_array((int) ($row['order_id'] ?? 0), $wanted, true);
            }));
        }
        if (preg_match('/product_id IN \(([0-9,\s]+)\)/', $sql, $m)) {
            $wanted = array_map('intval', preg_split('/\s*,\s*/', $m[1]) ?: []);
            $filtered = array_values(array_filter($filtered, static function ($row) use ($wanted) {
                return in_array((int) ($row['product_id'] ?? 0), $wanted, true);
            }));
        }
        if (stripos($sql, 'SELECT product_id, event_signature') !== false) {
            return array_map(static function ($row) {
                return [
                    'product_id' => $row['product_id'],
                    'event_signature' => $row['event_signature'],
                ];
            }, $filtered);
        }
        return $filtered;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $this->updates[] = [
            'data' => $data,
            'where' => $where,
        ];
        return 1;
    }
}
