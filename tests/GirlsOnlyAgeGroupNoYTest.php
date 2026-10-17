<?php
/**
 * Repro: girls-only courses whose pa_age-group term is stored without a trailing "y"
 * (e.g. "5-12") never get a roster row.
 *
 * Live path (order completed):
 *   OrderProcessor -> RosterBuilder::buildRosterFromOrder() (no skip_age_group_validation)
 *   -> processOrderItem() -> createOrUpdateRoster() -> RosterRepository::create()
 *   -> Roster::validate() -> Roster::validateAgeGroup()
 *   -> Player::isEligibleForAgeGroup() -> Player::parseAgeGroup()
 *
 * Player::parseAgeGroup() only accepts the dynamic form '/(\d+)-(\d+)y/', so "5-12"
 * parses to null, isEligibleForAgeGroup() returns false, Roster::validateAgeGroup()
 * throws ValidationException and RosterRepository::create() never inserts the row.
 * RosterBuilder::processOrderItem() swallows the exception, so the item silently has no roster.
 *
 * Controls ("5-12y", girls-only and mixed) must pass. The no-"y" cases are expected to FAIL
 * until the parser is fixed; they assert the behaviour we want (a parsed 5..12 range and a
 * created roster row for an eligible 9-year-old girl).
 */

namespace InterSoccer\ReportsRosters\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Services\CacheManager;
use Mockery;

class GirlsOnlyAgeGroupNoYTest extends PHPUnitTestCase {

    /** Event start used for age-at-event; player DOB makes her 9 on that date. */
    private const START_DATE = '2026-09-02';
    private const DOB = '2017-05-01';

    public function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    private function courseRosterData(string $age_group, int $girls_only): array {
        return [
            'order_id' => 900001,
            'order_item_id' => 900002,
            'product_id' => 900003,
            'variation_id' => 900004,
            'customer_id' => 900005,
            'player_index' => 0,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'dob' => self::DOB,
            'gender' => $girls_only ? 'female' : 'male',
            'activity_type' => 'Course',
            'venue' => 'Geneva',
            'age_group' => $age_group,
            'start_date' => self::START_DATE,
            'end_date' => '2026-12-16',
            'booking_type' => 'Full Term',
            'order_status' => 'completed',
            'girls_only' => $girls_only,
        ];
    }

    private function repositoryWithFakeDb(): RosterRepository {
        $logger = Mockery::mock(Logger::class);
        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            $logger->shouldReceive($level)->byDefault();
        }
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('insert_roster_entry')->andReturn(285999)->byDefault();
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->byDefault();

        $repo = Mockery::mock(RosterRepository::class, [$logger, $database, $cache])->makePartial();
        // No existing row for this order item.
        $repo->shouldReceive('first')->andReturn(null)->byDefault();
        return $repo;
    }

    private function parseAgeGroup(string $age_group) {
        $player = new Player(['dob' => self::DOB]);
        $m = new \ReflectionMethod(Player::class, 'parseAgeGroup');
        $m->setAccessible(true);
        return $m->invoke($player, $age_group);
    }

    // ---------------------------------------------------------------- cases

    public function controlProvider(): array {
        return [
            'control: girls-only 5-12y' => ['5-12y', 1],
            'control: mixed course 5-12y' => ['5-12y', 0],
        ];
    }

    public function noYProvider(): array {
        return [
            'girls-only 5-12' => ['5-12', 1],
            'girls-only "5 - 12"' => ['5 - 12', 1],
            'girls-only "5-12 ans" (FR)' => ['5-12 ans', 1],
            'girls-only "5-12 Jahre" (DE)' => ['5-12 Jahre', 1],
            'scope: mixed course 5-12 (not girls-specific)' => ['5-12', 0],
        ];
    }

    public function allProvider(): array {
        return array_merge($this->controlProvider(), $this->noYProvider());
    }

    /**
     * @dataProvider allProvider
     */
    public function test_age_group_parses_to_5_12_range(string $age_group, int $girls_only): void {
        $this->assertSame(
            ['min' => 5, 'max' => 12],
            $this->parseAgeGroup($age_group),
            "Player::parseAgeGroup('{$age_group}') should yield a 5..12 range"
        );
    }

    /**
     * @dataProvider allProvider
     */
    public function test_eligible_player_is_eligible(string $age_group, int $girls_only): void {
        $player = new Player(['dob' => self::DOB, 'gender' => $girls_only ? 'female' : 'male']);
        $this->assertTrue(
            $player->isEligibleForAgeGroup($age_group, self::START_DATE),
            "A 9-year-old should be eligible for '{$age_group}'"
        );
    }

    /**
     * @dataProvider allProvider
     */
    public function test_roster_model_validates(string $age_group, int $girls_only): void {
        $roster = new Roster($this->courseRosterData($age_group, $girls_only));
        try {
            $this->assertTrue($roster->validate());
        } catch (\Exception $e) {
            $this->fail("Roster::validate() rejected age_group '{$age_group}' (girls_only={$girls_only}): " . $e->getMessage());
        }
    }

    /**
     * @dataProvider allProvider
     */
    public function test_repository_create_inserts_roster_row(string $age_group, int $girls_only): void {
        $repo = $this->repositoryWithFakeDb();
        try {
            $roster = $repo->create($this->courseRosterData($age_group, $girls_only));
        } catch (\Exception $e) {
            $this->fail("RosterRepository::create() produced no roster row for age_group '{$age_group}' (girls_only={$girls_only}): " . get_class($e) . ': ' . $e->getMessage());
        }
        $this->assertInstanceOf(Roster::class, $roster);
        $this->assertSame($age_group, $roster->age_group);
        $this->assertSame($girls_only, (int) $roster->girls_only);
    }
}
