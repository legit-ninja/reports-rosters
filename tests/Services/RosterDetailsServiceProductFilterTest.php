<?php
/**
 * OOP roster details: product_id WPML twins, legacy product_name filter, destination grouping by canonical id.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterDetailsService;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterDetailsServiceProductFilterTest extends TestCase {

    private const EN_PID = 9003;
    private const FR_PID = 9103;
    private const EN_NAME = 'SYNTH Course C EN';
    private const VAR_CURRENT = 5001;
    private const VAR_OTHER = 5002;

    /** @var mixed */
    private $saved_wpdb = null;

    protected function setUp(): void {
        parent::setUp();
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
        global $wpdb;
        $this->saved_wpdb = $wpdb ?? null;
        $enName = self::EN_NAME;
        $enPid = self::EN_PID;
        $frPid = self::FR_PID;
        $wpdb = new class($enName, $enPid, $frPid) {
            public $prefix = 'wp_';
            private $enName;
            private $enPid;
            private $frPid;

            public function __construct($enName, $enPid, $frPid) {
                $this->enName = $enName;
                $this->enPid = $enPid;
                $this->frPid = $frPid;
            }

            public function prepare($query, ...$args) {
                if ($args === []) {
                    return $query;
                }
                $i = 0;

                return preg_replace_callback('/%[sd]/', function () use ($args, &$i) {
                    $v = $args[$i++];

                    return is_int($v) || (is_string($v) && ctype_digit($v))
                        ? (string) (int) $v
                        : "'" . addslashes((string) $v) . "'";
                }, (string) $query);
            }

            public function get_col($query) {
                if (strpos((string) $query, $this->enName) !== false) {
                    return [(string) $this->enPid, (string) $this->frPid];
                }

                return [];
            }
        };
        $this->stub_wpml_map([self::FR_PID => self::EN_PID, self::EN_PID => self::EN_PID]);
        if (!function_exists('get_post')) {
            eval('function get_post($id) { return (object) ["post_date" => "2026-01-01 12:00:00"]; }');
        }
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->saved_wpdb;
        unset($GLOBALS['intersoccer_test_apply_filters_callback']);
        parent::tearDown();
    }

    private function stub_wpml_map(array $map): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) use ($map) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id' && isset($args[0]) && $args[0] === 'product') {
                $id = (int) $value;
                $lang = $args[2] ?? null;
                if ($lang === 'fr') {
                    foreach ($map as $twin => $canon) {
                        if ((int) $canon === $id && (int) $twin !== (int) $canon) {
                            return (int) $twin;
                        }
                    }
                }

                return $map[$id] ?? $id;
            }
            if ($hook === 'wpml_active_languages') {
                return ['en' => [], 'fr' => []];
            }

            return $value;
        };
    }

    /**
     * @param array<string,mixed> $over
     */
    private function rosterRow(array $over): Roster {
        return new Roster(array_merge([
            'id' => 7001,
            'order_id' => 8001,
            'order_item_id' => 8101,
            'variation_id' => self::VAR_CURRENT,
            'product_id' => self::EN_PID,
            'product_name' => self::EN_NAME,
            'activity_type' => 'Course',
            'venue' => 'Geneva',
            'age_group' => '5-12y',
            'course_day' => 'Monday',
            'times' => '1000-1130',
            'season' => 'Winter 2026',
            'girls_only' => 0,
            'is_placeholder' => 0,
            'player_name' => 'Synth Player',
            'first_name' => 'Synth',
            'last_name' => 'Player',
            'customer_id' => 900001,
        ], $over));
    }

    /**
     * @return array{0:RosterDetailsService,1:object}
     */
    private function serviceWithRepo(array $primary, array $summaryPool): array {
        $holder = (object) ['criteria' => []];
        $primaryCollection = new RostersCollection($primary);
        $summaryCollection = new RostersCollection($summaryPool);

        $repo = Mockery::mock(RosterRepository::class);
        $repo->shouldReceive('clearQueryCache')->andReturnNull();
        $repo->shouldReceive('where')->andReturnUsing(function (array $criteria) use ($holder, $primaryCollection, $summaryCollection, $primary) {
            $holder->criteria[] = $criteria;
            if (isset($criteria['activity_type']) && !isset($criteria['product_id']) && !isset($criteria['order_item_id']) && !isset($criteria['id'])) {
                return $summaryCollection;
            }
            if (isset($criteria['order_item_id'])) {
                $wanted = array_map('intval', (array) $criteria['order_item_id']);
                $match = array_values(array_filter($primary, static function (Roster $row) use ($wanted) {
                    return in_array((int) $row->order_item_id, $wanted, true);
                }));

                return new RostersCollection($match !== [] ? $match : $primary);
            }

            return $primaryCollection;
        });

        $service = new RosterDetailsService(
            Mockery::mock(Logger::class)->shouldIgnoreMissing(),
            $repo,
            Mockery::mock(\InterSoccer\ReportsRosters\Core\Database::class)->shouldIgnoreMissing()
        );

        return [$service, $holder];
    }

    private function primaryProductCriteria(RosterDetailsService $service, array $filters, array $context): array {
        $build = new \ReflectionMethod($service, 'buildCriteria');
        $build->setAccessible(true);

        return $build->invoke($service, $this->normaliseFilters($service, $filters), $context, false);
    }

    private function normaliseFilters(RosterDetailsService $service, array $filters): array {
        $normalise = new \ReflectionMethod($service, 'normaliseFilters');
        $normalise->setAccessible(true);

        return $normalise->invoke($service, $filters);
    }

    private function baseFilters(int $product_id = 0, string $product_name = ''): array {
        return [
            'product_id' => $product_id,
            'product_name' => $product_name,
            'variation_id' => self::VAR_CURRENT,
            'variation_ids' => [],
            'order_item_ids' => [],
            'event_signature' => '',
            'event_signatures' => [],
            'camp_terms' => '',
            'course_day' => 'Monday',
            'venue' => 'Geneva',
            'age_group' => '5-12y',
            'times' => '1000-1130',
            'season' => 'Winter 2026',
            'girls_only' => false,
        ];
    }

    private function baseContext(): array {
        return [
            'is_from_camps_page' => false,
            'is_from_courses_page' => true,
            'is_from_girls_only_page' => false,
            'is_from_tournaments_page' => false,
            'sort_by' => 'order_date',
            'sort_order' => 'desc',
        ];
    }

    public function test_fr_product_id_filter_uses_wpml_twin_in_criteria_and_returns_both_bookings(): void {
        $primary = [
            $this->rosterRow(['id' => 7001, 'order_item_id' => 8101, 'product_id' => self::EN_PID]),
            $this->rosterRow(['id' => 7002, 'order_item_id' => 8102, 'product_id' => self::FR_PID]),
        ];
        [$service] = $this->serviceWithRepo($primary, []);

        $context = $this->baseContext();
        $criteria = $this->primaryProductCriteria($service, $this->baseFilters(self::FR_PID), $context);
        $this->assertSame([self::EN_PID, self::FR_PID], $criteria['product_id']);

        $result = $service->getRosterContext(
            $this->baseFilters(self::FR_PID),
            $context
        );

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['rosters']);
    }

    public function test_legacy_product_name_filter_maps_to_twin_product_ids(): void {
        $primary = [
            $this->rosterRow(['id' => 7001, 'order_item_id' => 8101, 'product_id' => self::EN_PID]),
            $this->rosterRow(['id' => 7002, 'order_item_id' => 8102, 'product_id' => self::FR_PID]),
        ];
        [$service] = $this->serviceWithRepo($primary, []);

        $filters = $this->baseFilters(0, self::EN_NAME);
        $context = $this->baseContext();
        $criteria = $this->primaryProductCriteria($service, $filters, $context);
        $this->assertSame([self::EN_PID, self::FR_PID], $criteria['product_id']);

        $result = $service->getRosterContext($filters, $context);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['rosters']);
    }

    public function test_destination_grouping_collapses_wpml_twins_to_one_canonical_entry(): void {
        $primary = [
            $this->rosterRow(['id' => 7001, 'order_item_id' => 8101, 'product_id' => self::EN_PID]),
        ];
        $summaryPool = [
            $this->rosterRow([
                'id' => 7010,
                'order_item_id' => 8110,
                'variation_id' => self::VAR_OTHER,
                'product_id' => self::EN_PID,
            ]),
            $this->rosterRow([
                'id' => 7011,
                'order_item_id' => 8111,
                'variation_id' => self::VAR_OTHER,
                'product_id' => self::FR_PID,
            ]),
        ];
        [$service] = $this->serviceWithRepo($primary, $summaryPool);

        $result = $service->getRosterContext(
            $this->baseFilters(self::FR_PID),
            $this->baseContext()
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['available_rosters']);
        $this->assertSame(self::EN_PID, (int) $result['available_rosters'][0]->product_id);
        $this->assertSame(2, (int) $result['available_rosters'][0]->current_players);
    }

    public function test_roster_details_legacy_sql_selects_max_product_name(): void {
        $path = dirname(__DIR__, 2) . '/includes/roster-details.php';
        $src = (string) file_get_contents($path);
        $this->assertSame(
            2,
            substr_count($src, 'MAX(r.product_name) AS product_name')
        );
    }

    public function test_other_events_view_link_uses_product_id(): void {
        $path = dirname(__DIR__, 2) . '/includes/rosters.php';
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString(
            "&product_id=' . urlencode((string) \$event_product_id)",
            $src
        );
        $this->assertDoesNotMatchRegularExpression(
            '/intersoccer-roster-details&variation_ids=[^\']*product_name=/',
            $src
        );
    }
}
