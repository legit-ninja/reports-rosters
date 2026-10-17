<?php
/**
 * Grade-format age groups skip numeric age validation but still create roster rows.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Services\CacheManager;
use Mockery;

class GradeAgeGroupRosterTest extends TestCase {

    private const START_DATE = '2026-09-02';
    private const DOB = '2017-05-01';

    public function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    private function gradeCourseRosterData(string $age_group): array {
        return [
            'order_id' => 900101,
            'order_item_id' => 900102,
            'product_id' => 900103,
            'variation_id' => 900104,
            'customer_id' => 900105,
            'player_index' => 0,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'dob' => self::DOB,
            'gender' => 'female',
            'activity_type' => 'Course',
            'venue' => 'Geneva',
            'age_group' => $age_group,
            'start_date' => self::START_DATE,
            'end_date' => '2026-12-16',
            'booking_type' => 'Full Term',
            'order_status' => 'completed',
            'girls_only' => 0,
        ];
    }

    private function repositoryWithFakeDb(): RosterRepository {
        $logger = Mockery::mock(Logger::class);
        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            $logger->shouldReceive($level)->byDefault();
        }
        $database = Mockery::mock(Database::class);
        $database->shouldReceive('insert_roster_entry')->andReturn(900999)->byDefault();
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('set', 'forget', 'forgetPattern')->byDefault();

        $repo = Mockery::mock(RosterRepository::class, [$logger, $database, $cache])->makePartial();
        $repo->shouldReceive('first')->andReturn(null)->byDefault();
        return $repo;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function gradeCourseProvider(): array {
        return [
            'G1-G5' => ['G1-G5'],
            'Pre-K to G5' => ['Pre-K to G5'],
            'GR2-5' => ['GR2-5'],
        ];
    }

    /**
     * @dataProvider gradeCourseProvider
     */
    public function test_roster_model_validates_grade_course(string $age_group): void {
        $roster = new Roster($this->gradeCourseRosterData($age_group));
        try {
            $this->assertTrue($roster->validate());
        } catch (\Exception $e) {
            $this->fail("Roster::validate() rejected grade age_group '{$age_group}': " . $e->getMessage());
        }
    }

    public function test_nine_year_old_eligible_for_grade_course(): void {
        $player = new Player(['dob' => self::DOB, 'gender' => 'female']);
        $this->assertTrue($player->isEligibleForAgeGroup('G1-G5', self::START_DATE));
    }

    /**
     * @dataProvider gradeCourseProvider
     */
    public function test_repository_create_inserts_grade_course_roster(string $age_group): void {
        $repo = $this->repositoryWithFakeDb();
        try {
            $roster = $repo->create($this->gradeCourseRosterData($age_group));
        } catch (\Exception $e) {
            $this->fail(
                "RosterRepository::create() produced no roster row for grade age_group '{$age_group}': "
                . get_class($e) . ': ' . $e->getMessage()
            );
        }
        $this->assertInstanceOf(Roster::class, $roster);
        $this->assertSame($age_group, $roster->age_group);
    }
}
