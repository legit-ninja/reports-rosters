<?php
/**
 * Cleanup for roster-row keys copied into event_details (data bug).
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Services\EventDetailsCleanupService;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/includes/event-details-cleanup-service.php';

class EventDetailsCleanupServiceTest extends TestCase {

    /** @var object|null */
    private $previous_wpdb;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->previous_wpdb = $wpdb ?? null;
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previous_wpdb;
        parent::tearDown();
    }

    public function test_clean_decoded_removes_only_copied_row_keys_and_keeps_legit_keys(): void {
        $svc = new EventDetailsCleanupService();
        $raw = [
            'season' => 'SYNTH_KEEP_SEASON',
            'is_buyclub' => true,
            'order_id' => 111,
            'player_first_name' => 'SYNTH_SHOULD_DROP',
            'event_details' => [
                'region' => 'SYNTH_KEEP_REGION',
                'customer_id' => 222,
            ],
        ];
        $result = $svc->cleanDecodedEventDetails($raw);
        $this->assertTrue($result['changed']);
        $this->assertSame(
            [
                'season' => 'SYNTH_KEEP_SEASON',
                'region' => 'SYNTH_KEEP_REGION',
                'is_buyclub' => true,
            ],
            $result['cleaned']
        );
        $this->assertContains('order_id', $result['removed_keys']);
        $this->assertContains('player_first_name', $result['removed_keys']);
        $this->assertContains('event_details', $result['removed_keys']);
    }

    public function test_second_clean_is_no_op(): void {
        $svc = new EventDetailsCleanupService();
        $first = $svc->cleanDecodedEventDetails([
            'city' => 'SYNTH_CITY',
            'venue' => 'SYNTH_VENUE_DROP',
        ]);
        $second = $svc->cleanDecodedEventDetails($first['cleaned']);
        $this->assertFalse($second['changed']);
        $this->assertSame(['city' => 'SYNTH_CITY'], $second['cleaned']);
    }

    public function test_process_batch_dry_run_does_not_write(): void {
        global $wpdb;
        $writes = [];
        $wpdb = new class($writes) {
            /** @var array<int,array<string,mixed>> */
            public $writes;

            /** @param array<int,array<string,mixed>> $writes */
            public function __construct(&$writes) {
                $this->writes = &$writes;
            }

            public $prefix = 'wp_';

            public function prepare($query, ...$args) {
                return $query;
            }

            public function get_results($query, $mode = null) {
                return [
                    [
                        'id' => 5,
                        'event_details' => wp_json_encode([
                            'season' => 'SYNTH_S',
                            'order_id' => 9,
                            'parent_email' => 'synthetic@example.invalid',
                        ]),
                    ],
                ];
            }

            public function update(...$args) {
                $this->writes[] = $args;
                return 1;
            }
        };

        $svc = new EventDetailsCleanupService();
        $batch = $svc->processBatch(['int_cursor' => 0, 'chunk' => 10, 'apply' => false]);
        $this->assertSame(1, $batch['scanned']);
        $this->assertSame(1, $batch['rows_would_change']);
        $this->assertSame(0, $batch['rows_changed']);
        $this->assertSame([], $writes);
        $this->assertArrayHasKey('order_id', $batch['removed_key_counts']);
    }

    public function test_process_batch_apply_strips_copied_keys(): void {
        global $wpdb;
        $writes = [];
        $wpdb = new class($writes) {
            /** @var array<int,array<string,mixed>> */
            public $writes;

            /** @param array<int,array<string,mixed>> $writes */
            public function __construct(&$writes) {
                $this->writes = &$writes;
            }

            public $prefix = 'wp_';

            public function prepare($query, ...$args) {
                return $query;
            }

            public function get_results($query, $mode = null) {
                return [
                    [
                        'id' => 8,
                        'event_details' => wp_json_encode([
                            'booking_type' => 'Full Week',
                            'player_last_name' => 'SYNTH_DROP',
                        ]),
                    ],
                ];
            }

            public function update($table, $data, $where, $format = null, $where_format = null) {
                $this->writes[] = ['data' => $data, 'where' => $where];
                return 1;
            }
        };

        $svc = new EventDetailsCleanupService();
        $batch = $svc->processBatch(['int_cursor' => 0, 'chunk' => 10, 'apply' => true]);
        $this->assertSame(1, $batch['rows_changed']);
        $this->assertCount(1, $writes);
        $decoded = json_decode((string) $writes[0]['data']['event_details'], true);
        $this->assertSame(['booking_type' => 'Full Week'], $decoded);
        $output_blob = wp_json_encode($batch);
        $this->assertStringNotContainsString('SYNTH_DROP', $output_blob);
    }
}
