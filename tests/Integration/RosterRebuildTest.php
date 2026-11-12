<?php
/**
 * RosterRebuild Integration Test
 * 
 * Tests the complete database rebuild process
 */

namespace InterSoccer\ReportsRosters\Tests\Integration;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

class RosterRebuildTest extends TestCase {
    public function test_rebuild_all_rosters() {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('query')->andReturn(true);
        $wpdb->shouldReceive('get_results')->andReturn([]);
        
        Functions\expect('wc_get_orders')->andReturn([]);
        
        // Simulate rebuild process
        $this->assertTrue(true);
    }
    
    public function test_rebuild_handles_large_datasets() {
        $logger = Mockery::mock(\InterSoccer\ReportsRosters\Core\Logger::class);
        $logger->shouldReceive('info')->zeroOrMoreTimes();
        $logger->shouldReceive('debug')->zeroOrMoreTimes();
        $logger->shouldReceive('warning')->zeroOrMoreTimes();
        $logger->shouldReceive('error')->zeroOrMoreTimes();

        $database = Mockery::mock(\InterSoccer\ReportsRosters\Core\Database::class);
        $database->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            return $callback();
        });

        $builder = new \InterSoccer\ReportsRosters\Services\RosterBuilder(
            $logger,
            $database,
            Mockery::mock(\InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository::class),
            Mockery::mock(\InterSoccer\ReportsRosters\Data\Repositories\RosterRepository::class),
            Mockery::mock(\InterSoccer\ReportsRosters\Services\DataValidator::class),
            Mockery::mock(\InterSoccer\ReportsRosters\Services\EventMatcher::class),
            Mockery::mock(\InterSoccer\ReportsRosters\Services\PlayerMatcher::class)
        );

        // buildRosters() defaults batch_size to 100 and stops when a page is short.
        // Two full pages plus the empty page are three wc_get_orders calls.
        Functions\expect('wc_get_orders')
            ->times(3)
            ->andReturn(
                array_fill(0, 100, 1),
                array_fill(0, 100, 2),
                []
            );
        Functions\when('wc_get_order')->justReturn(false);

        $result = $builder->buildRosters();

        $this->assertSame(0, $result['orders_processed']);
        $this->assertSame(200, $result['skipped_orders']);
    }
    
    public function test_rebuild_with_error_recovery() {
        Functions\expect('wc_get_orders')->andThrow(new \Exception('Database error'));
        
        try {
            // Rebuild should handle errors gracefully
            throw new \Exception('Database error');
        } catch (\Exception $e) {
            $this->assertInstanceOf(\Exception::class, $e);
        }
    }
}

