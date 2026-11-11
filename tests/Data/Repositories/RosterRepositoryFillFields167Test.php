<?php
/**
 * RosterRepository::fillFields(): validate and write only the filled fields (#167 dry run).
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Repositories;

use InterSoccer\ReportsRosters\Services\CacheManager;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterRepositoryFillFields167Test extends TestCase {
    /** @var array<int,array> */
    private $writes = [];

    /** A legacy row: no customer_id and no dob, so whole-row validation fails. */
    private function legacyRow(): Roster {
        $roster = new Roster([
            'order_id' => 501,
            'order_item_id' => 601,
            'product_id' => 701,
            'player_index' => 0,
            'first_name' => 'Legacy',
            'last_name' => 'Row',
            'gender' => 'male',
            'activity_type' => 'Camp',
            'order_status' => 'completed',
            'venue' => '',
            'start_date' => '2025-07-21',
        ]);
        $roster->setKey(42);
        $roster->setExists(true);
        $roster->syncOriginal();

        return $roster;
    }

    private function repository(Roster $row): RosterRepository {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug', 'info', 'warning', 'error')->andReturn(null);
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('get')->andReturn($row);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->andReturn(true);
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('update_roster_entry')->andReturnUsing(function (array $data) {
            $this->writes[] = $data;
            return true;
        });

        return new RosterRepository($logger, $database, $cache);
    }

    public function test_whole_row_validation_fails_for_the_legacy_row(): void {
        $this->expectException(ValidationException::class);
        $this->legacyRow()->validate();
    }

    public function test_fill_validates_and_writes_only_the_filled_fields(): void {
        $repo = $this->repository($this->legacyRow());

        $roster = $repo->fillFields(42, ['venue' => 'Etoy', 'end_date' => '2025-07-25', '_skip_age_group_validation' => true]);

        $this->assertInstanceOf(Roster::class, $roster);
        $this->assertCount(1, $this->writes);
        $written = $this->writes[0];
        $this->assertSame(42, $written['id']);
        $this->assertSame('Etoy', $written['venue']);
        $this->assertSame('2025-07-25', $written['end_date']);
        $this->assertArrayHasKey('updated_at', $written);
        $keys = array_keys($written);
        sort($keys);
        $this->assertSame(['end_date', 'id', 'updated_at', 'venue'], $keys, 'no other column is written');
    }

    public function test_filled_field_is_still_validated(): void {
        $repo = $this->repository($this->legacyRow());

        $this->expectException(ValidationException::class);
        try {
            $repo->fillFields(42, ['venue' => str_repeat('x', 201)]);
        } finally {
            $this->assertSame([], $this->writes);
        }
    }

    public function test_filled_end_date_before_start_date_is_refused(): void {
        $repo = $this->repository($this->legacyRow());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('End date cannot be before start date');
        try {
            $repo->fillFields(42, ['end_date' => '2025-07-01']);
        } finally {
            $this->assertSame([], $this->writes);
        }
    }
}
