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
        $this->assertSame(2, $first['needing_rename_merge']);
        $this->assertSame(2, $first['updated']);
        $this->assertSame(0, $first['skipped_not_evergreen']);
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
        $this->assertSame(0, $second['needing_rename_merge']);
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
        $this->assertSame(2, $result['needing_rename_merge']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped_not_evergreen']);
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
        $this->assertSame(2, $result['needing_rename_merge']);
        $this->assertSame(2, $result['updated']);
        $this->assertCount(2, $db->updates);
    }

    /**
     * Unscoped season pass must not re-sign year-qualified-term drift.
     */
    public function test_unscoped_reconcile_leaves_year_qualified_term_drift_untouched(): void {
        global $wpdb;
        $year_product = 6101;
        $evergreen_product = 6102;
        $this->seedProductSeasonTerm($year_product, 'Winter 2026', 'winter-2026', 9301);
        $this->seedProductSeasonTerm($evergreen_product, 'Winter Courses', 'winter-2027', 9302);

        $stale_year_sig = md5('stale-year-qualified-drift');
        $stale_evergreen_sig = md5('stale-evergreen');
        $db = new SeasonRenameReconcileWpdb([
            [
                'id' => 31,
                'order_id' => 401,
                'order_item_id' => 4001,
                'product_id' => $year_product,
                'variation_id' => 0,
                'activity_type' => 'Camp',
                'venue' => 'geneva-venue',
                'age_group' => '5-12',
                'camp_terms' => 'summer-week-1',
                'course_day' => '',
                'times' => '0900-1200',
                'season' => 'Winter 2026',
                'girls_only' => 0,
                'city' => '',
                'canton_region' => '',
                'start_date' => '',
                'event_signature' => $stale_year_sig,
            ],
            [
                'id' => 32,
                'order_id' => 402,
                'order_item_id' => 4002,
                'product_id' => $evergreen_product,
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
                'event_signature' => $stale_evergreen_sig,
            ],
        ]);
        $wpdb = $db;
        $GLOBALS['intersoccer_test_wc_order_item_meta'][4002] = ['Year' => '2027'];

        $result = intersoccer_reconcile_roster_season_signatures(['scope_all' => true]);
        $this->assertSame(1, $result['needing_rename_merge']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['skipped_not_evergreen']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertCount(1, $db->updates);
        $this->assertSame(32, (int) $db->updates[0]['where']['id']);
        $this->assertSame($stale_year_sig, $db->rows[0]['event_signature']);
        $this->assertSame('Winter 2026', $db->rows[0]['season']);
    }

    /**
     * Drifted rows on products with no pa_program-season term stay untouched.
     */
    public function test_drifted_row_with_no_season_term_is_untouched(): void {
        global $wpdb;
        $stale = md5('no-term-drift');
        $db = new SeasonRenameReconcileWpdb([
            [
                'id' => 41,
                'order_id' => 501,
                'order_item_id' => 5001,
                'product_id' => 7200,
                'variation_id' => 0,
                'activity_type' => 'Camp',
                'venue' => 'geneva-venue',
                'age_group' => '5-12',
                'camp_terms' => 'summer-week-1',
                'course_day' => '',
                'times' => '0900-1200',
                'season' => 'Winter 2027',
                'girls_only' => 0,
                'city' => '',
                'canton_region' => '',
                'start_date' => '',
                'event_signature' => $stale,
            ],
        ]);
        $wpdb = $db;

        $result = intersoccer_reconcile_roster_season_signatures([
            'product_ids' => [7200],
        ]);
        $this->assertSame(0, $result['needing_rename_merge']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped_not_evergreen']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame([], $db->updates);
        $this->assertSame($stale, $db->rows[0]['event_signature']);
        $this->assertSame('Winter 2027', $db->rows[0]['season']);
    }

    /**
     * Evergreen rename candidates still merge under unscoped reconcile; second run is a no-op.
     */
    public function test_rename_only_pass_merges_dimanche_and_sunday_to_one_signature(): void {
        global $wpdb;
        $product_id = 5556;
        $this->seedProductSeasonTerm($product_id, 'Winter Courses', 'winter-2027', 9402);
        $base = [
            'order_id' => 0,
            'order_item_id' => 0,
            'product_id' => $product_id,
            'variation_id' => 60979,
            'activity_type' => 'Course',
            'venue' => 'pully',
            'age_group' => '5-12',
            'camp_terms' => '',
            'times' => '1000-1130',
            'season' => 'Winter Courses',
            'girls_only' => 0,
            'city' => 'pully',
            'canton_region' => '',
            'start_date' => '',
        ];
        $en = array_merge($base, ['id' => 61, 'order_id' => 701, 'order_item_id' => 7001, 'course_day' => 'Sunday', 'event_signature' => md5('en-stale')]);
        $fr = array_merge($base, ['id' => 62, 'order_id' => 702, 'order_item_id' => 7002, 'course_day' => 'Dimanche', 'season' => 'Winter 2027', 'event_signature' => md5('fr-stale')]);
        $db = new SeasonRenameReconcileWpdb([$en, $fr]);
        $wpdb = $db;
        $GLOBALS['intersoccer_test_wc_order_item_meta'][7001] = ['Year' => '2027'];
        $GLOBALS['intersoccer_test_wc_order_item_meta'][7002] = ['Year' => '2027'];

        $first = intersoccer_reconcile_roster_season_signatures(['scope_all' => true]);
        $this->assertCount(2, $db->updates);
        $by_id = [];
        foreach ($db->updates as $update) {
            $by_id[(int) $update['where']['id']] = $update['data'];
        }
        $this->assertSame($by_id[61]['event_signature'], $by_id[62]['event_signature']);
        $this->assertSame('Sunday', $by_id[62]['course_day']);
        $this->assertArrayNotHasKey('course_day', $by_id[61]);

        // Same value the order sync computes for this course from FR or EN line meta.
        $sync_payload = array_merge($base, ['course_day' => 'Dimanche', 'Year' => '2027', 'order_item_id' => 7002]);
        $this->assertSame($by_id[62]['event_signature'], intersoccer_event_signature_from_event_data($sync_payload));
        $sync_payload['course_day'] = 'Sunday';
        $this->assertSame($by_id[62]['event_signature'], intersoccer_event_signature_from_event_data($sync_payload));

        foreach ($db->updates as $update) {
            foreach ($db->rows as &$row) {
                if ((int) $row['id'] === (int) $update['where']['id']) {
                    $row = array_merge($row, $update['data']);
                }
            }
            unset($row);
        }
        $db->updates = [];
        $second = intersoccer_reconcile_roster_season_signatures(['scope_all' => true]);
        $this->assertSame(0, $second['needing_rename_merge']);
        $this->assertCount(0, $db->updates);
        $this->assertSame(2, $first['updated']);
    }

    public function test_unscoped_evergreen_rows_merge_and_second_run_changes_zero(): void {
        global $wpdb;
        $product_id = 5555;
        $this->seedProductSeasonTerm($product_id, 'Winter Courses', 'winter-2027', 9401);
        $db = new SeasonRenameReconcileWpdb([
            [
                'id' => 51,
                'order_id' => 601,
                'order_item_id' => 6001,
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
                'event_signature' => md5('old-label'),
            ],
            [
                'id' => 52,
                'order_id' => 602,
                'order_item_id' => 6002,
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
                'event_signature' => md5('new-label'),
            ],
        ]);
        $wpdb = $db;
        $GLOBALS['intersoccer_test_wc_order_item_meta'][6001] = ['Year' => '2027'];
        $GLOBALS['intersoccer_test_wc_order_item_meta'][6002] = ['Year' => '2027'];

        $first = intersoccer_reconcile_roster_season_signatures(['scope_all' => true]);
        $this->assertSame(2, $first['needing_rename_merge']);
        $this->assertSame(2, $first['updated']);
        $this->assertSame(0, $first['skipped_not_evergreen']);
        $this->assertCount(2, $db->updates);
        $this->assertSame(
            $db->updates[0]['data']['event_signature'],
            $db->updates[1]['data']['event_signature']
        );

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

        $second = intersoccer_reconcile_roster_season_signatures(['scope_all' => true]);
        $this->assertSame(0, $second['needing_rename_merge']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame([], $db->updates);
    }

    public function test_weekday_repair_merges_mercredi_in_named_season_and_skips_other_seasons(): void {
        $product = 30713;
        $other_product = 60933;
        $this->seedProductSeasonTerm($product, 'Autumn 2026', 'autumn-2026', 856);
        $this->seedProductSeasonTerm($other_product, 'Winter Courses', 'winter-courses', 983);

        $base = [
            'product_id' => $product,
            'variation_id' => 30714,
            'activity_type' => 'Course',
            'venue' => 'versoix-venue',
            'age_group' => '3-7y',
            'camp_terms' => '',
            'times' => '1600-1700',
            'season' => 'Autumn 2026',
            'girls_only' => 0,
            'city' => 'Versoix',
            'canton_region' => 'Geneva',
            'start_date' => '2026-08-26',
        ];
        $canonical = intersoccer_event_signature_from_event_data(array_merge($base, [
            'course_day' => 'Wednesday',
            'order_item_id' => 8001,
        ]));
        $en = array_merge($base, [
            'id' => 71,
            'order_id' => 801,
            'order_item_id' => 8001,
            'course_day' => 'Wednesday',
            'event_signature' => $canonical,
        ]);
        $fr = array_merge($base, [
            'id' => 72,
            'order_id' => 802,
            'order_item_id' => 8002,
            'course_day' => 'Mercredi',
            'event_signature' => md5('fr-stale'),
        ]);
        $other = array_merge($base, [
            'id' => 73,
            'order_id' => 803,
            'order_item_id' => 8003,
            'product_id' => $other_product,
            'variation_id' => 60979,
            'season' => 'Winter Courses',
            'course_day' => 'Dimanche',
            'event_signature' => md5('winter-stale'),
        ]);

        global $wpdb;
        $wpdb = new SeasonRenameReconcileWpdb([$en, $fr, $other]);

        $first = intersoccer_reconcile_roster_season_signatures([
            'season' => 'Autumn 2026',
            'weekday_repair' => true,
        ]);
        $this->assertSame(2, $first['examined']);
        $this->assertSame(1, $first['needing_rename_merge']);
        $this->assertSame(1, $first['updated']);
        $this->assertSame(1, $first['unchanged']);
        $this->assertSame(0, $first['skipped_not_evergreen']);
        $this->assertSame('Autumn 2026', $first['season']);
        $this->assertTrue($first['weekday_repair']);

        $by_id = [];
        foreach ($wpdb->updates as $update) {
            $by_id[(int) $update['where']['id']] = $update['data'];
        }
        $this->assertArrayHasKey(72, $by_id);
        $this->assertArrayNotHasKey(71, $by_id);
        $this->assertArrayNotHasKey(73, $by_id);
        $this->assertSame('Wednesday', $by_id[72]['course_day']);
        $this->assertSame('Autumn 2026', $by_id[72]['season']);
        $this->assertSame($canonical, $by_id[72]['event_signature']);
        $this->assertNotSame(md5('fr-stale'), $by_id[72]['event_signature']);

        // Apply the first update to the in-memory table and confirm a second run is a no-op.
        foreach ($wpdb->rows as $i => $row) {
            if ((int) $row['id'] === 72) {
                $wpdb->rows[$i]['course_day'] = $by_id[72]['course_day'];
                $wpdb->rows[$i]['event_signature'] = $by_id[72]['event_signature'];
                $wpdb->rows[$i]['season'] = $by_id[72]['season'];
            }
        }
        $wpdb->updates = [];
        $second = intersoccer_reconcile_roster_season_signatures([
            'season' => 'Autumn 2026',
            'weekday_repair' => true,
        ]);
        $this->assertSame(0, $second['needing_rename_merge']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame([], $wpdb->updates);
    }

    public function test_weekday_repair_dry_run_writes_nothing(): void {
        $this->seedProductSeasonTerm(30713, 'Autumn 2026', 'autumn-2026', 856);
        $row = [
            'id' => 81,
            'order_id' => 901,
            'order_item_id' => 9001,
            'product_id' => 30713,
            'variation_id' => 30714,
            'activity_type' => 'Course',
            'venue' => 'versoix-venue',
            'age_group' => '3-7y',
            'camp_terms' => '',
            'course_day' => 'Mercredi',
            'times' => '1600-1700',
            'season' => 'Autumn 2026',
            'girls_only' => 0,
            'city' => 'Versoix',
            'canton_region' => 'Geneva',
            'start_date' => '2026-08-26',
            'event_signature' => md5('stale'),
        ];
        global $wpdb;
        $wpdb = new SeasonRenameReconcileWpdb([$row]);
        $result = intersoccer_reconcile_roster_season_signatures([
            'season' => 'Autumn 2026',
            'dry_run' => true,
        ]);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['needing_rename_merge']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame([], $wpdb->updates);
        $this->assertSame('Mercredi', $result['changes'][0]['old_course_day']);
        $this->assertSame('Wednesday', $result['changes'][0]['new_course_day']);
        $this->assertSame('Autumn 2026', $result['changes'][0]['new_season']);
    }

    public function test_weekday_repair_does_not_merge_rows_that_differ_only_by_time(): void {
        $this->seedProductSeasonTerm(52686, 'Autumn 2026', 'autumn-2026', 856);
        $base = [
            'product_id' => 52686,
            'variation_id' => 52687,
            'activity_type' => 'Course',
            'venue' => 'zug-eizmoos',
            'age_group' => '3-8y',
            'camp_terms' => '',
            'course_day' => 'Tuesday',
            'season' => 'Autumn 2026',
            'girls_only' => 0,
            'city' => 'Zug',
            'canton_region' => 'Zug',
            'start_date' => '2026-08-18',
        ];
        $early = array_merge($base, [
            'id' => 91,
            'order_id' => 1001,
            'order_item_id' => 10001,
            'times' => '1630-1730',
            'event_signature' => md5('early'),
        ]);
        $late = array_merge($base, [
            'id' => 92,
            'order_id' => 1002,
            'order_item_id' => 10002,
            'times' => '1615-1715',
            'event_signature' => md5('late'),
        ]);
        global $wpdb;
        $wpdb = new SeasonRenameReconcileWpdb([$early, $late]);
        $result = intersoccer_reconcile_roster_season_signatures([
            'season' => 'Autumn 2026',
            'weekday_repair' => true,
        ]);
        $this->assertSame(0, $result['needing_rename_merge']);
        $this->assertSame(2, $result['unchanged']);
        $this->assertSame([], $wpdb->updates);
        $this->assertCount(1, $result['remaining_splits']);
        $this->assertSame(52687, $result['remaining_splits'][0]['variation_id']);
        $this->assertSame(52686, $result['remaining_splits'][0]['product_id']);
        $this->assertSame(2, $result['remaining_splits'][0]['groups']);
        $this->assertSame(['1630-1730', '1615-1715'], $result['remaining_splits'][0]['times']);
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
        if (preg_match("/season = '([^']*)'/", $sql, $m)) {
            $wanted = $m[1];
            $filtered = array_values(array_filter($filtered, static function ($row) use ($wanted) {
                return (string) ($row['season'] ?? '') === $wanted;
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
