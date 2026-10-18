<?php

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterBuilderInsertFailureTest extends TestCase {

    public function test_insert_failure_log_and_stats_exclude_player_pii_and_use_fresh_db_error(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->last_error = 'stale error from earlier query';

        $logger = Mockery::mock(Logger::class);
        $captured = null;
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('error')->andReturnUsing(function ($message, $context = []) use (&$captured) {
            if ($message === 'Roster insert failed') {
                $captured = $context;
            }
        });

        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('first')->andReturn(null);
        $rosterRepository->shouldReceive('create')->andReturnUsing(function () use ($wpdb) {
            $wpdb->last_error = 'fresh insert error';
            return null;
        });

        $builder = new RosterBuilder(
            $logger,
            Mockery::mock(Database::class),
            Mockery::mock(PlayerRepository::class),
            $rosterRepository,
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        $method = new \ReflectionMethod(RosterBuilder::class, 'createOrUpdateRoster');
        $method->setAccessible(true);
        $method->invoke($builder, [
            'order_id' => 42,
            'order_item_id' => 99,
            'player_name' => 'Sensitive Name',
            'player_dob' => '2015-01-01',
        ], [
            'skip_duplicates' => false,
            'update_existing' => false,
            'validate_data' => false,
        ]);

        $statsProp = new \ReflectionProperty(RosterBuilder::class, 'build_stats');
        $statsProp->setAccessible(true);
        $stats = $statsProp->getValue($builder);
        $this->assertNotEmpty($stats['errors']);
        $this->assertStringNotContainsString('Sensitive', $stats['errors'][0]);
        $this->assertStringNotContainsString('2015', $stats['errors'][0]);
        $this->assertStringContainsString('fresh insert error', $stats['errors'][0]);
        $this->assertStringNotContainsString('stale error', $stats['errors'][0]);

        $this->assertIsArray($captured);
        $this->assertSame(42, $captured['order_id']);
        $this->assertSame(99, $captured['order_item_id']);
        $this->assertArrayNotHasKey('player', $captured);
        $this->assertStringContainsString('fresh insert error', (string) $captured['reason']);
    }
}
