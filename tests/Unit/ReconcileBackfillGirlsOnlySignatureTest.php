<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Reconcile girls_only backfill must recompute event_signature so mis-flagged rows rejoin their event.
 */
class ReconcileBackfillGirlsOnlySignatureTest extends TestCase {
    private const SHARED_FACETS = [
        'venue' => 'geneva-venue',
        'age_group' => '5-12',
        'camp_terms' => '',
        'course_day' => 'monday',
        'times' => '0900-1200',
        'season' => 'Summer 2026',
        'city' => 'geneva',
        'canton_region' => 'ge',
        'product_id' => 4242,
        'start_date' => null,
        'end_date' => null,
    ];

    /** @var object */
    private $wpdb;

    /** @var list<array<string,mixed>> */
    public $capturedUpdates = [];

    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(true);
        }

        $this->capturedUpdates = [];
        $this->wpdb = new class($this) {
            /** @var ReconcileBackfillGirlsOnlySignatureTest */
            private $test;

            /** @var list<array<string,mixed>> */
            public $candidate_rows = [];

            public $prefix = 'wp_';

            public function __construct(ReconcileBackfillGirlsOnlySignatureTest $test) {
                $this->test = $test;
            }

            public function prepare($query, ...$args) {
                return $query;
            }

            public function get_results($query, $output_mode = OBJECT) {
                return $this->candidate_rows;
            }

            public function update($table, $data, $where, $format = null, $where_format = null) {
                $this->test->capturedUpdates[] = [
                    'table' => $table,
                    'data' => $data,
                    'where' => $where,
                ];
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_wc_get_product_callback']);
        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(false);
        }
        parent::tearDown();
    }

    public function test_backfill_recomputes_signature_so_event_stays_unified(): void {
        if (!function_exists('intersoccer_reconcile_backfill_girls_only_flags')
            || !function_exists('intersoccer_event_signature_branch_pipeline')) {
            $this->fail('Backfill and branch pipeline helpers must be loaded');
        }

        $order_path_signature = intersoccer_event_signature_branch_pipeline(array_merge(self::SHARED_FACETS, [
            'activity_type' => 'Course, Filles uniquement',
            'girls_only' => 1,
        ]));

        $stale_signature = intersoccer_event_signature_branch_pipeline(array_merge(self::SHARED_FACETS, [
            'activity_type' => 'Course, Filles uniquement',
            'girls_only' => 0,
        ]));
        $this->assertNotSame($order_path_signature, $stale_signature, 'mis-flagged row must have differed before backfill');

        $this->wpdb->candidate_rows = [
            array_merge(self::SHARED_FACETS, [
                'id' => 101,
                'order_item_id' => 5001,
                'activity_type' => 'Course, Filles uniquement',
                'product_name' => 'Geneva Girls Course',
                'variation_id' => 0,
                'girls_only' => 0,
                'event_signature' => $stale_signature,
            ]),
            array_merge(self::SHARED_FACETS, [
                'id' => 102,
                'order_item_id' => 5002,
                'activity_type' => 'Camp',
                'product_name' => 'Girls Academy Camp',
                'variation_id' => 0,
                'girls_only' => 0,
                'event_signature' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ]),
        ];

        $count = intersoccer_reconcile_backfill_girls_only_flags();
        $this->assertSame(1, $count);
        $this->assertCount(1, $this->capturedUpdates);
        $this->assertSame(101, (int) $this->capturedUpdates[0]['where']['id']);
        $this->assertSame(1, (int) $this->capturedUpdates[0]['data']['girls_only']);
        $this->assertSame($order_path_signature, $this->capturedUpdates[0]['data']['event_signature']);

        $correct_sibling_signature = intersoccer_event_signature_branch_pipeline(array_merge(self::SHARED_FACETS, [
            'activity_type' => 'Course, Filles uniquement',
            'girls_only' => 1,
        ]));
        $this->assertSame($correct_sibling_signature, $this->capturedUpdates[0]['data']['event_signature']);
    }

    public function test_backfill_skips_rows_that_do_not_need_girls_only_change(): void {
        $this->wpdb->candidate_rows = [
            array_merge(self::SHARED_FACETS, [
                'id' => 201,
                'order_item_id' => 6001,
                'activity_type' => 'Camp',
                'product_name' => 'Girls Academy Camp',
                'variation_id' => 0,
                'girls_only' => 0,
                'event_signature' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            ]),
        ];

        $count = intersoccer_reconcile_backfill_girls_only_flags();
        $this->assertSame(0, $count);
        $this->assertCount(0, $this->capturedUpdates);
    }

    public function test_backfill_detects_girls_only_from_product_attribute_without_label_hint(): void {
        if (!function_exists('intersoccer_reconcile_backfill_girls_only_flags')
            || !function_exists('intersoccer_event_signature_branch_pipeline')) {
            $this->fail('Backfill and branch pipeline helpers must be loaded');
        }

        $product_stub = new class {
            public function get_attribute($name) {
                return $name === 'pa_girls-only' ? 'yes' : '';
            }
            public function get_attributes() {
                return [];
            }
        };
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function () use ($product_stub) {
            return $product_stub;
        };

        $expected_signature = intersoccer_event_signature_branch_pipeline(array_merge(self::SHARED_FACETS, [
            'activity_type' => 'Camp',
            'girls_only' => 1,
        ]));
        $stale_signature = intersoccer_event_signature_branch_pipeline(array_merge(self::SHARED_FACETS, [
            'activity_type' => 'Camp',
            'girls_only' => 0,
        ]));

        $this->wpdb->candidate_rows = [
            array_merge(self::SHARED_FACETS, [
                'id' => 301,
                'order_item_id' => 7001,
                'activity_type' => 'Camp',
                'product_name' => 'Plain Camp Title',
                'variation_id' => 8801,
                'girls_only' => 0,
                'event_signature' => $stale_signature,
            ]),
        ];

        $count = intersoccer_reconcile_backfill_girls_only_flags();
        $this->assertSame(1, $count);
        $this->assertSame(1, (int) $this->capturedUpdates[0]['data']['girls_only']);
        $this->assertSame($expected_signature, $this->capturedUpdates[0]['data']['event_signature']);
    }

    public function test_backfill_signature_recompute_is_required_for_correct_hash(): void {
        $payload = array_merge(self::SHARED_FACETS, [
            'activity_type' => 'Course, Filles uniquement',
            'girls_only' => 1,
        ]);
        $expected = intersoccer_event_signature_branch_pipeline($payload);
        $payload_wrong_flag = $payload;
        $payload_wrong_flag['girls_only'] = 0;
        $wrong = intersoccer_event_signature_branch_pipeline($payload_wrong_flag);
        $this->assertNotSame($expected, $wrong);
        $this->assertSame($expected, intersoccer_event_signature_branch_pipeline(array_merge($payload, ['girls_only' => 1])));
    }
}
