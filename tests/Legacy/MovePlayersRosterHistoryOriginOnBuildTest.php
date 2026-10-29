<?php
/**
 * Roster history origin is written on new roster create without admin-only includes preloaded.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

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

class MovePlayersRosterHistoryOriginOnBuildTest extends TestCase {

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_new_roster_create_writes_origin_without_preloaded_history_module(): void {
        require_once dirname(__DIR__) . '/bootstrap.php';
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

        $this->assertFalse(function_exists('intersoccer_roster_history_record_origin_on_roster_build'));

        global $wpdb, $intersoccer_test_wc_order_item_meta;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->last_error = '';
        $intersoccer_test_wc_order_item_meta = [];

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('warning')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $created = (object) ['id' => 88001];
        $rosterRepository = Mockery::mock(RosterRepository::class);
        $rosterRepository->shouldReceive('first')->andReturn(null);
        $rosterRepository->shouldReceive('create')->andReturn($created);

        $builder = new RosterBuilder(
            $logger,
            Mockery::mock(Database::class),
            Mockery::mock(PlayerRepository::class),
            $rosterRepository,
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        $roster_data = [
            'order_id' => 88000,
            'order_item_id' => 88011,
            'product_id' => 88010,
            'variation_id' => 88012,
            'event_signature' => 'origin-on-build-sig',
            'booking_type' => 'Full Term',
            'product_name' => 'Origin On Build Course',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-01',
        ];

        $method = new \ReflectionMethod(RosterBuilder::class, 'createOrUpdateRoster');
        $method->setAccessible(true);
        $method->invoke($builder, $roster_data, [
            'skip_duplicates' => false,
            'update_existing' => false,
            'validate_data' => false,
        ]);

        $this->assertTrue(function_exists('intersoccer_roster_history_get_entries'));
        $entries = intersoccer_roster_history_get_entries(88011);
        $this->assertCount(1, $entries);
        $this->assertSame('origin', $entries[0]['type']);
        $this->assertFalse($entries[0]['backfilled']);
        $this->assertSame(88012, (int) ($entries[0]['variation_id'] ?? 0));
        $this->assertSame('origin-on-build-sig', (string) ($entries[0]['event_signature'] ?? ''));
    }
}
