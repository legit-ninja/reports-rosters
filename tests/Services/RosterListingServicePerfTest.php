<?php
/**
 * Roster listing aggregate SQL / service performance helpers.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterListingService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;
use ReflectionMethod;

class RosterListingServicePerfTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        if (!function_exists('sanitize_text_field')) {
            eval('function sanitize_text_field($str) { return trim((string) $str); }');
        }
        if (!function_exists('sanitize_key')) {
            eval('function sanitize_key($key) { return strtolower(preg_replace("/[^a-z0-9_-]/", "", (string) $key)); }');
        }
        if (!function_exists('intersoccer_normalize_season_for_display')) {
            eval('function intersoccer_normalize_season_for_display($season) { return (string) $season; }');
        }
        if (!function_exists('get_term_by')) {
            eval('function get_term_by($field, $value, $taxonomy = "", $output = OBJECT, $filter = "raw") { return false; }');
        }
    }

    public function test_listing_allowed_order_statuses() {
        $db = new Database(new Logger());
        $statuses = $db->get_listing_allowed_order_statuses();
        $this->assertContains('wc-completed', $statuses);
        $this->assertContains('wc-processing', $statuses);
        $this->assertContains('wc-pending', $statuses);
        $this->assertContains('wc-on-hold', $statuses);
    }

    public function test_listing_aggregates_sql_joins_posts_and_groups_by_signature() {
        global $wpdb;

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->last_error = '';

        $captured_sql = '';
        $captured_values = [];
        $wpdb->shouldReceive('query')
            ->with(Mockery::pattern('/group_concat_max_len/'))
            ->once()
            ->andReturn(true);
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function ($sql, ...$args) use (&$captured_sql, &$captured_values) {
                $captured_sql = (string) $sql;
                // wpdb::prepare($sql, $array) passes one array arg.
                $captured_values = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;
                return $sql;
            });
        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        $db = new Database($logger);
        $rows = $db->get_roster_listing_aggregates([
            'is_placeholder' => 0,
            'activity_type' => ['Camp'],
            'venue' => 'Geneva',
            'event_completed' => 0,
        ]);

        $this->assertSame([], $rows);
        $this->assertStringContainsString('INNER JOIN', $captured_sql);
        $this->assertStringContainsString('wp_posts', $captured_sql);
        $this->assertStringContainsString('p.post_status IN', $captured_sql);
        $this->assertStringContainsString('GROUP BY', $captured_sql);
        $this->assertStringContainsString('r.venue = %s', $captured_sql);
        $this->assertStringContainsString('r.event_completed = %s', $captured_sql);
        $this->assertContains('wc-completed', $captured_values);
        $this->assertContains('wc-processing', $captured_values);
        $this->assertContains('Geneva', $captured_values);
    }

    public function test_map_listing_aggregates_expands_csv_ids() {
        $repository = Mockery::mock(RosterRepository::class);
        $service = new RosterListingService(null, $repository);

        $method = new ReflectionMethod(RosterListingService::class, 'mapListingAggregatesToRows');
        $method->setAccessible(true);
        $rows = $method->invoke($service, [[
            'group_key' => 'sig-1',
            'event_signature' => 'sig-1',
            'player_count' => 2,
            'product_id' => 10,
            'product_name' => 'Camp',
            'venue' => 'Geneva',
            'city' => 'Geneva',
            'age_group' => '5-13y',
            'camp_terms' => 'Week 1',
            'course_day' => '',
            'times' => '09:00',
            'season' => 'Summer 2026',
            'activity_type' => 'Camp',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-05',
            'event_completed' => 0,
            'girls_only' => 0,
            'order_item_ids_csv' => '101,102',
            'variation_ids_csv' => '55',
            'event_signatures_csv' => 'sig-1',
        ]]);

        $this->assertCount(1, $rows);
        $this->assertTrue(!empty($rows[0]['_listing_aggregate']));
        $this->assertSame([101, 102], $rows[0]['order_item_ids']);
        $this->assertSame([55], $rows[0]['variation_ids']);
        $this->assertSame(2, $rows[0]['player_count']);
    }

    public function test_aggregate_camp_groups_accepts_listing_aggregate_rows() {
        $repository = Mockery::mock(RosterRepository::class);
        $service = new RosterListingService(null, $repository);

        $method = new ReflectionMethod(RosterListingService::class, 'aggregateCampGroups');
        $method->setAccessible(true);
        $result = $method->invoke($service, [[
            '_listing_aggregate' => true,
            'event_signature' => 'sig-agg',
            'order_item_ids' => [201, 202, 203],
            'variation_ids' => [9],
            'merged_event_signatures' => ['sig-agg'],
            'venue' => 'Zurich',
            'camp_terms' => 'Week 2',
            'season' => 'Summer 2026',
            'city' => 'Zurich',
            'age_group' => '5-13y',
            'times' => '09:00',
            'product_name' => 'Camp',
            'girls_only' => 0,
            'event_completed' => 0,
            'start_date' => '2026-07-08',
            'end_date' => '2026-07-12',
            'product_id' => 1,
        ]], false, false);

        $groups = array_values($result['groups']);
        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]['total_players']);
        $this->assertSame([201, 202, 203], $groups[0]['order_item_ids']);
    }

    public function test_empty_camp_response_shape() {
        $repository = Mockery::mock(RosterRepository::class);
        $repository->shouldReceive('getListingAggregates')->andReturn([]);
        $service = new RosterListingService(null, $repository);
        $result = $service->getCampListings([], [], false);

        $this->assertSame([], $result['all_groups']);
        $this->assertSame([], $result['display_groups']);
        $this->assertSame([], $result['grouped']);
        $this->assertArrayHasKey('seasons', $result['filters']);
    }
}
