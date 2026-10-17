<?php
/**
 * Roster details hydration must backfill player names in memory only (no $wpdb writes).
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterDetailsService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterDetailsHydratePlayerNameReadOnlyTest extends TestCase {

    /** @var object */
    private $wpdbStub;
    /** @var object|null */
    private $previousWpdb;

    protected function setUp(): void {
        parent::setUp();
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (!function_exists('intersoccer_roster_backfill_player_name_fields')) {
            require_once $utils;
        }
        if (!function_exists('get_post')) {
            eval('function get_post($id = null) { return null; }');
        }

        global $wpdb;
        $this->previousWpdb = $wpdb ?? null;
        $this->wpdbStub = new class {
            public $prefix = 'wp_';
            public $writes = [];
            public function update($table, $data, $where, $f = null, $wf = null) {
                $this->writes[] = ['UPDATE', $table, $data, $where];
                return 1;
            }
            public function insert($table, $data, $f = null) {
                $this->writes[] = ['INSERT', $table, $data, []];
                return 1;
            }
            public function query($sql) {
                if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', (string) $sql)) {
                    $this->writes[] = ['QUERY', (string) $sql, [], []];
                }
                return 0;
            }
        };
        $wpdb = $this->wpdbStub;
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previousWpdb;
        parent::tearDown();
    }

    private function hydrate(array $rowData): array {
        $model = new class($rowData) {
            public $order_id;
            private $d;
            public function __construct(array $d) {
                $this->d = $d;
                $this->order_id = $d['order_id'];
            }
            public function toArray() {
                return $this->d;
            }
        };
        $service = new RosterDetailsService(
            Mockery::mock(Logger::class)->shouldIgnoreMissing(),
            Mockery::mock(RosterRepository::class)->shouldIgnoreMissing(),
            Mockery::mock(Database::class)->shouldIgnoreMissing()
        );
        $m = new \ReflectionMethod($service, 'hydrateAndSortRosters');
        $m->setAccessible(true);
        return $m->invoke($service, [$model], 'order_date', 'desc');
    }

    public function test_hydrate_backfills_player_names_in_memory_without_db_writes(): void {
        $rosters = $this->hydrate([
            'id' => 700101,
            'order_id' => 800101,
            'order_item_id' => 810101,
            'first_name' => '',
            'last_name' => '',
            'player_first_name' => 'Qaone',
            'player_last_name' => 'Qatest',
            'player_name' => 'Qaone Qatest',
            'age' => 9,
            'activity_type' => 'Camp',
            'girls_only' => 0,
        ]);

        $this->assertCount(0, $this->wpdbStub->writes);
        $this->assertSame('Qaone', $rosters[0]->first_name);
        $this->assertSame('Qatest', $rosters[0]->last_name);
    }
}
