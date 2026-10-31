<?php
/**
 * Repro (failing on purpose): viewing roster details in the admin WRITES avs_number to the DB.
 *
 * Path on origin/master:
 *   admin page render  includes/roster-details.php:468 intersoccer_render_roster_details_page()
 *     -> :576 RosterDetailsService::getRosterContext() -> classes/services/roster-details-service.php:194
 *        hydrateAndSortRosters() :659-662
 *          -> includes/utils.php:1830 intersoccer_roster_backfill_avs_number_from_user_meta()
 *             (fills a missing/'N/A' avs_number from the customer's intersoccer_players user meta,
 *              by player_index, else by normalized first/last name)
 *          -> includes/utils.php:1895 intersoccer_roster_persist_avs_number() -> $wpdb->update() :1906
 *     -> then again per row in the render loop includes/roster-details.php:976-983.
 *
 * Expected: a GET / render is read-only. Display may show a derived value, but nothing is written.
 * All data synthetic; AVS values are obviously fake (756.0000.0000.0x).
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterDetailsService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterDetailsViewAvsWriteTest extends TestCase {

    private const FAKE_AVS_PROFILE = '756.0000.0000.00';
    private const FAKE_AVS_ROW = '756.0000.0000.01';
    private const CUSTOMER = 900001;

    /** @var object */
    private $wpdbStub;
    /** @var object|null */
    private $previousWpdb;

    protected function setUp(): void {
        parent::setUp();
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (!function_exists('intersoccer_roster_backfill_avs_number_from_user_meta')) {
            require_once $utils;
        }
        if (!function_exists('maybe_unserialize')) {
            eval('function maybe_unserialize($v) { return is_string($v) && @unserialize($v) !== false ? unserialize($v) : $v; }');
        }
        if (!function_exists('get_post')) {
            eval('function get_post($id = null) { return null; }');
        }

        global $wpdb;
        $this->previousWpdb = $wpdb ?? null;
        $this->wpdbStub = new class {
            public $prefix = 'wp_';
            public $last_error = '';
            public $writes = [];
            public function update($table, $data, $where, $f = null, $wf = null) { $this->writes[] = ['UPDATE', $table, $data, $where]; return 1; }
            public function insert($table, $data, $f = null) { $this->writes[] = ['INSERT', $table, $data, []]; return 1; }
            public function replace($table, $data, $f = null) { $this->writes[] = ['REPLACE', $table, $data, []]; return 1; }
            public function delete($table, $where, $f = null) { $this->writes[] = ['DELETE', $table, [], $where]; return 1; }
            public function query($sql) {
                if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', (string) $sql)) { $this->writes[] = ['QUERY', (string) $sql, [], []]; }
                return 0;
            }
            public function prepare($q, ...$a) { return $q; }
            public function get_var($q) { return null; }
            public function get_row($q, $o = null) { return null; }
            public function get_results($q, $o = null) { return []; }
            public function get_col($q) { return []; }
        };
        $wpdb = $this->wpdbStub;

        // Customer profile has an AVS for player_index 0 (synthetic).
        $GLOBALS['intersoccer_test_user_meta'] = [
            self::CUSTOMER => ['intersoccer_players' => [
                ['first_name' => 'Qaone', 'last_name' => 'Qatest', 'avs_number' => self::FAKE_AVS_PROFILE],
                ['first_name' => 'Qatwo', 'last_name' => 'Qatest', 'avs_number' => ''],
            ]],
        ];
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previousWpdb;
        unset($GLOBALS['intersoccer_test_user_meta']);
        parent::tearDown();
    }

    private function row(array $over): object {
        $data = array_merge([
            'id' => 700001, 'order_id' => 800001, 'order_item_id' => 810001, 'customer_id' => self::CUSTOMER,
            'player_index' => 0, 'first_name' => 'Qaone', 'last_name' => 'Qatest', 'player_name' => 'Qaone Qatest',
            'avs_number' => 'N/A', 'age' => 9, 'activity_type' => 'Camp', 'girls_only' => 0,
        ], $over);
        return new class($data) {
            public $order_id; private $d;
            public function __construct(array $d) { $this->d = $d; $this->order_id = $d['order_id']; }
            public function toArray() { return $this->d; }
        };
    }

    /** Runs the same hydration step the admin details page (and single export) uses on a GET. */
    private function view(array $models): array {
        $service = new RosterDetailsService(
            Mockery::mock(Logger::class)->shouldIgnoreMissing(),
            Mockery::mock(RosterRepository::class)->shouldIgnoreMissing(),
            Mockery::mock(Database::class)->shouldIgnoreMissing()
        );
        $m = new \ReflectionMethod($service, 'hydrateAndSortRosters');
        $m->setAccessible(true);
        return $m->invoke($service, $models, 'order_date', 'desc');
    }

    private function avsWrites(): array {
        return array_values(array_filter($this->wpdbStub->writes, static function ($w) {
            return array_key_exists('avs_number', (array) $w[2]) || stripos((string) $w[1], 'avs_number') !== false;
        }));
    }

    /** Row with missing AVS + profile AVS (the 285538-285543 shape): viewing must not write avs_number. */
    public function test_viewing_row_with_missing_avs_does_not_write_avs_from_profile(): void {
        $this->view([$this->row(['avs_number' => 'N/A'])]);
        $this->assertSame([], $this->avsWrites(), 'Viewing roster details wrote avs_number: ' . json_encode($this->avsWrites()));
    }

    public function test_viewing_row_with_empty_string_avs_does_not_write_avs_from_profile(): void {
        $this->view([$this->row(['avs_number' => ''])]);
        $this->assertSame([], $this->avsWrites(), 'Viewing roster details wrote avs_number: ' . json_encode($this->avsWrites()));
    }

    /** A row that already has an AVS must not get an UPDATE either (currently a redundant write per view). */
    public function test_viewing_row_with_existing_avs_does_not_issue_update(): void {
        $this->view([$this->row(['avs_number' => self::FAKE_AVS_ROW])]);
        $this->assertSame([], $this->avsWrites(), 'Viewing a row that already has an AVS issued an UPDATE: ' . json_encode($this->avsWrites()));
    }

    /** Overall: a page view performs zero DB writes of any kind (also catches the player-name persist at :656). */
    public function test_viewing_roster_details_performs_zero_db_writes(): void {
        $this->view([$this->row(['avs_number' => 'N/A']), $this->row(['id' => 700002, 'player_index' => 1, 'first_name' => 'Qatwo', 'avs_number' => 'N/A'])]);
        $this->assertCount(0, $this->wpdbStub->writes, 'DB writes during view: ' . json_encode(array_map(static function ($w) {
            return [$w[0], array_keys((array) $w[2])];
        }, $this->wpdbStub->writes)));
    }

    /** Control (passes today): missing AVS and no AVS in the profile -> nothing to fill, no avs write. */
    public function test_control_missing_avs_without_profile_avs_does_not_write(): void {
        $this->view([$this->row(['id' => 700003, 'player_index' => 1, 'first_name' => 'Qatwo', 'avs_number' => 'N/A'])]);
        $this->assertSame([], $this->avsWrites());
    }
}
