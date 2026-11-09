<?php
/**
 * Roster query helper tests.
 *
 * @package InterSoccer\ReportsRosters\Tests\Integration
 */

namespace InterSoccer\ReportsRosters\Tests\Integration;

use Mockery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/roster-data.php';

class RosterQueryHelperTest extends TestCase
{
    /**
     * @var \Mockery\MockInterface
     */
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = $wpdb->prefix . 'posts';
        $wpdb->last_error = '';
        $wpdb->last_query = '';

        $wpdb->shouldReceive('esc_like')
            ->andReturnUsing(function ($value) {
                return addcslashes($value, '_%\\');
            });

        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(function ($query, ...$args) {
                if (!empty($args) && is_array($args[0]) && count($args) === 1) {
                    $args = $args[0];
                }

                foreach ($args as $arg) {
                    if (is_int($arg)) {
                        $query = preg_replace('/%d/', (string) $arg, $query, 1);
                    } else {
                        $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
                    }
                }

                return $query;
            });

        $this->wpdb = $wpdb;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fetch_rosters_prefers_event_signature()
    {
        $rows = [
            ['player_name' => 'Test Player']
        ];

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturnUsing(function ($sql, $output) use ($rows) {
                global $wpdb;
                $wpdb->last_query = $sql;
                return $rows;
            });

        $result = intersoccer_fetch_roster_entries([
            'output' => ARRAY_A,
            'event_signature' => 'abc123',
        ]);

        $this->assertSame('signature', $result['source'], 'Should use signature lookup first.');
        $this->assertCount(1, $result['results'], 'Should return the signature results.');
        $this->assertSame($rows, $result['results']);
    }

    public function test_fetch_rosters_falls_back_to_field_filters()
    {
        $responses = [
            [], // signature exact
            [], // signature like
            [['player_name' => 'Fallback Player']],
        ];

        $this->wpdb->shouldReceive('get_results')
            ->times(3)
            ->andReturnUsing(function ($sql, $output) use (&$responses) {
                global $wpdb;
                $wpdb->last_query = $sql;
                $response = array_shift($responses);
                return $response;
            });

        $result = intersoccer_fetch_roster_entries([
            'output' => ARRAY_A,
            'event_signature' => 'missing',
            'product_name' => 'Camp Alpha',
            'camp_terms' => 'Summer',
            'venue' => 'Lausanne',
            'age_group' => 'U10',
            'is_camps_page' => true,
        ]);

        $this->assertSame('fallback', $result['source'], 'Should use fallback when signature lookup fails.');
        $this->assertCount(1, $result['results'], 'Fallback should return available rows.');
        $this->assertSame('Fallback Player', $result['results'][0]['player_name']);
    }
}

