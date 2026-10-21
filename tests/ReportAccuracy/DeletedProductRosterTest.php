<?php
/**
 * Repro (failing tests): a paid line whose product AND variation were deleted from the catalogue.
 *
 * Decision (2026-10-17): the line still counts as a place. Its roster row is built from what is stored on the
 * order item (item name, variation attribute meta, item meta), keeps the stored product/variation IDs, and
 * carries event_details.product_deleted = true. It must not be dropped and must not vanish from Final Numbers.
 *
 * Synthetic fixture, mirroring what WooCommerce does for a deleted product (checked read-only on the restored
 * copy): wc_get_product() is false for both IDs, the posts do not exist, WC_Order_Item_Product::get_product_id()
 * and get_variation_id() return 0 (WC drops an id whose post is not a product) and get_meta('_product_id')
 * returns '' (internal key, hidden). The stored values are still in the order item meta table: they are
 * returned by wc_get_order_item_meta() and by an itemmeta SELECT on $wpdb.
 *
 * Rows are captured through a RosterRepository double whose create() does what RosterRepository::create()
 * does before the INSERT (roster-repository.php:487-503): new Roster($data), skip-age flag, validate().
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\FinancialReportService;
use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService as Attr;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\ProductTypeClassifierService;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;

require_once __DIR__ . '/ReportAccuracyFixtures.php';

/** WC_Meta_Data stand-in (get_data()). */
class FakeMeta {
    private $key; private $value;
    public function __construct(string $key, $value) { $this->key = $key; $this->value = $value; }
    public function get_data() { return ['id' => 0, 'key' => $this->key, 'value' => $this->value]; }
}

/** Order line as WooCommerce loads it: visible meta via get_meta(); internal _product_id/_variation_id hidden. */
class StoredLineItem extends FakeLineItem {
    /** @var array<string,mixed> visible meta */
    private $visible = [];
    private $wc_product_id; private $wc_variation_id; private $product;

    public function __construct(int $item_id, string $name, int $stored_pid, int $stored_vid, array $visible, bool $product_exists, float $total, ?bool $variation_exists = null) {
        $variation_exists = $variation_exists ?? $product_exists;
        parent::__construct($item_id, ['name' => $name, 'subtotal' => $total, 'total' => $total, 'quantity' => 1,
            'product_id' => 0, 'variation_id' => 0]);
        $this->visible = $visible;
        // WC_Order_Item_Product::set_product_id() rejects ids whose post is not a product -> 0 when deleted.
        $this->wc_product_id = $product_exists ? $stored_pid : 0;
        $this->wc_variation_id = $variation_exists ? $stored_vid : 0;
        $this->product = $variation_exists ? DeletedProductFixture::product($stored_vid)
            : ($product_exists ? DeletedProductFixture::product($stored_pid) : null);
        // Stored order item meta table (what wc_get_order_item_meta() / an itemmeta SELECT return).
        ReportAccuracyStore::setItemMeta($item_id, '_product_id', (string) $stored_pid, false);
        ReportAccuracyStore::setItemMeta($item_id, '_variation_id', (string) $stored_vid, false);
        ReportAccuracyStore::setItemMeta($item_id, '_qty', '1', false);
        ReportAccuracyStore::setItemMeta($item_id, '_line_subtotal', (string) $total, false);
        ReportAccuracyStore::setItemMeta($item_id, '_line_total', (string) $total, false);
        foreach ($visible as $k => $v) {
            ReportAccuracyStore::setItemMeta($item_id, $k, $v, false);
        }
    }
    public function get_product_id() { return $this->wc_product_id; }
    public function get_variation_id() { return $this->wc_variation_id; }
    public function get_product() { return $this->product ?: false; }
    public function get_meta_data() {
        $out = [];
        foreach ($this->visible as $k => $v) { $out[] = new FakeMeta($k, $v); }
        return $out;
    }
    public function get_meta($key, $single = true) {
        return array_key_exists($key, $this->visible) ? $this->visible[$key] : ($single ? '' : []);
    }
}

/** FakeOrder plus WC_Order::get_item() (used by intersoccer_sync_product_ids_from_order_item). */
class StoredOrder extends FakeOrder {
    public function get_item($item_id, $load_only_line_items = true) {
        foreach ($this->get_items('line_item') as $id => $item) {
            if ((int) $id === (int) $item_id) {
                return $item;
            }
        }
        return false;
    }
}

class FakeVariation extends \WC_Product_Variation {
    private $parent;
    public function __construct(int $id, int $parent, array $attributes, string $name) {
        parent::__construct($id);
        $this->parent = $parent;
        $this->data = ['name' => $name, 'attributes' => $attributes];
    }
    public function get_parent_id() { return $this->parent; }
    public function get_attribute($name) { $a = $this->data['attributes']; return $a[$name] ?? ($a['pa_' . $name] ?? ''); }
    public function get_variation_attributes() {
        $out = [];
        foreach ($this->data['attributes'] as $k => $v) { $out['attribute_' . $k] = $v; }
        return $out;
    }
    public function get_meta($key, $single = true) { return $single ? '' : []; }
    public function get_title() { return $this->data['name']; }
    public function get_name() { return $this->data['name']; }
}

class FakeSimpleProduct extends \WC_Product {
    public function __construct(int $id, string $name) { parent::__construct($id); $this->data = ['name' => $name]; }
    public function get_parent_id() { return 0; }
    public function get_attribute($name) { return ''; }
    public function get_type() { return 'variable'; }
    public function is_type($t) { return $t === 'variable'; }
    public function get_meta($key, $single = true) { return $single ? '' : []; }
    public function get_title() { return $this->data['name']; }
    public function get_name() { return $this->data['name']; }
}

final class DeletedProductFixture {
    public const DELETED_PID = 4101;
    public const DELETED_VID = 4102;
    public const LIVE_PID = 4201;
    public const LIVE_VID = 4202;
    public const DELETED_VARIATION_ONLY = 4302;
    /** @var array<int,object> catalogue that still exists */
    public static $catalogue = [];

    public static function product(int $vid) {
        return self::$catalogue[$vid] ?? null;
    }

    public static function resetCatalogue(): void {
        self::$catalogue = [
            self::LIVE_PID => new FakeSimpleProduct(self::LIVE_PID, 'Synthetic Sunday Course'),
            self::LIVE_VID => new FakeVariation(self::LIVE_VID, self::LIVE_PID, [
                'pa_intersoccer-venues' => 'venue-a', 'pa_age-group' => '5-12y', 'pa_course-day' => 'sunday',
            ], 'Synthetic Sunday Course - Venue A'),
        ];
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) {
            return self::$catalogue[(int) $id] ?? false;
        };
    }

    /** Course line meta as stored by checkout (keys as on the real deleted-product lines). */
    public static function courseMeta(): array {
        return [
            'Activity Type' => 'Course',
            'Sites InterSoccer' => 'Venue A',
            'Season' => 'Winter 2026',
            'Age Group' => '5-12y',
            'Booking Type' => 'Full Term',
            'Start Date' => 'January 11, 2026',
            'End Date' => 'February 22, 2026',
            'Course Times' => '11:15-12:45',
            'Remaining Sessions' => '6',
            'Assigned Attendee' => 'Synthetic Player',
            'assigned_player' => '0',
            'pa_intersoccer-venues' => 'venue-a',
            'pa_age-group' => '5-12y',
            'pa_course-day' => 'sunday',
            'pa_course-times' => '1115-1245',
        ];
    }
}

/** wpdb for the builder + reconcile + Final Numbers: no roster rows yet, no posts for the deleted IDs. */
class DeletedProductWpdb extends FakeBookingWpdb {
    /** @var array<int,array<string,mixed>> roster rows Final Numbers should see */
    public $roster_rows = [];

    public function get_row($query = null, $output = OBJECT, $y = 0) {
        $this->queries[] = (string) $query;
        if (preg_match('/FROM \S*posts WHERE ID = (\d+)/', (string) $query, $m)) {
            $id = (int) $m[1];
            $obj = DeletedProductFixture::$catalogue[$id] ?? null;
            if (!$obj) {
                return null; // post does not exist
            }
            return (object) ['ID' => $id, 'post_parent' => method_exists($obj, 'get_parent_id') ? $obj->get_parent_id() : 0,
                'post_type' => $obj instanceof \WC_Product_Variation ? 'product_variation' : 'product', 'post_status' => 'publish'];
        }
        return null;
    }

    public function get_var($query = null, $x = 0, $y = 0) {
        $this->queries[] = (string) $query;
        if (preg_match("/woocommerce_order_itemmeta WHERE order_item_id = (\\d+) AND meta_key = '([^']+)'/", (string) $query, $m)) {
            $v = ReportAccuracyStore::getItemMeta((int) $m[1], $m[2]);
            return $v === '' ? null : $v;
        }
        return null; // COUNT(*) of roster rows etc.
    }

    public function get_results($query = null, $output = OBJECT) {
        if (strpos((string) $query, 'intersoccer_rosters r') !== false) {
            $this->queries[] = (string) $query;
            return $this->roster_rows;
        }
        if (preg_match("/SELECT order_item_id, order_id FROM \\S*intersoccer_rosters/", (string) $query)) {
            return [];
        }
        if (preg_match('/SELECT ID, post_type FROM \\S+posts WHERE ID IN \\(([^)]+)\\)/', (string) $query, $m)) {
            $this->queries[] = (string) $query;
            $rows = [];
            foreach (array_map('intval', explode(',', $m[1])) as $id) {
                $obj = DeletedProductFixture::$catalogue[$id] ?? null;
                if (!$obj) {
                    continue;
                }
                $type = $obj instanceof \WC_Product_Variation ? 'product_variation' : 'product';
                $rows[] = ['ID' => $id, 'post_type' => $type];
            }
            return $output === ARRAY_A ? $rows : array_map(static function ($r) { return (object) $r; }, $rows);
        }
        return parent::get_results($query, $output);
    }
}

class DeletedProductRosterTest extends TestCase {

    /** @var DeletedProductWpdb */
    private $wpdb;
    /** @var array<int,array<string,mixed>> rows that passed Roster::validate() (would be INSERTed) */
    private $created = [];
    /** @var string[] */
    private $create_errors = [];
    private $previous_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        $previous_log = ini_set('error_log', '/dev/null');
        foreach (['utils.php', 'order-meta-keys.php', 'final-reports-totals.php', 'final-reports-aggregation.php', 'reports-data.php'] as $f) {
            if (file_exists($includes . '/' . $f)) {
                require_once $includes . '/' . $f;
            }
        }
        ini_set('error_log', (string) $previous_log);
        ReportAccuracyStore::reset();
        DeletedProductFixture::resetCatalogue();
        $this->wpdb = new DeletedProductWpdb();
        $this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->created = [];
        $this->create_errors = [];
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previous_wpdb;
        unset($GLOBALS['intersoccer_test_wc_get_product_callback'], $GLOBALS['intersoccer_test_wc_get_orders_callback']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ wiring

    private function order(int $order_id, StoredLineItem $item): StoredOrder {
        $order = (new StoredOrder($order_id, 'completed', '2026-01-12 10:00:00'))->addLine($item)
            ->setMeta(Attr::META_ORDER_ATTRIBUTION_AT, '2026-01-12 10:00:05');
        ReportAccuracyStore::$orders[$order_id] = $order;
        return $order;
    }

    private function deletedLine(int $item_id = 5101): StoredLineItem {
        return new StoredLineItem($item_id, 'Synthetic Sunday Course - Venue A - Winter 2026',
            DeletedProductFixture::DELETED_PID, DeletedProductFixture::DELETED_VID, DeletedProductFixture::courseMeta(), false, 300.0);
    }

    private function liveLine(int $item_id = 5201): StoredLineItem {
        return new StoredLineItem($item_id, 'Synthetic Sunday Course - Venue A - Winter 2026',
            DeletedProductFixture::LIVE_PID, DeletedProductFixture::LIVE_VID, DeletedProductFixture::courseMeta(), true, 300.0);
    }

    private function logger(?Logger $override = null): Logger {
        if ($override !== null) {
            return $override;
        }
        $logger = $this->createMock(Logger::class);
        if (getenv('DP_DEBUG')) {
            foreach (['error', 'warning', 'info', 'debug'] as $lvl) {
                $logger->method($lvl)->willReturnCallback(static function ($m, $c = []) use ($lvl) {
                    fwrite(STDERR, "[$lvl] $m " . substr(json_encode($c), 0, 600) . "\n");
                });
            }
        }
        return $logger;
    }

    private function builder(): RosterBuilder {
        $logger = $this->logger();
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);

        $player = new Player([
            'customer_id' => 1, 'player_index' => 0, 'first_name' => 'Synthetic', 'last_name' => 'Player',
            'dob' => '2018-03-01', 'gender' => 'male', 'medical_conditions' => '', 'dietary_needs' => '',
        ]);
        $players = new PlayersCollection([$player]);
        $player_repo = $this->createMock(PlayerRepository::class);
        $player_repo->method('getPlayersByCustomerId')->willReturn($players);
        $matcher = $this->createMock(PlayerMatcher::class);
        $matcher->method('resolveAssignedPlayers')->willReturn([
            'players' => $players,
            'strategy' => 'metadata_uuid',
            'ambiguous' => false,
            'fallback_candidate_count' => 0,
        ]);
        $validator = $this->createMock(DataValidator::class);
        $validator->method('validateRosterData')->willReturn(['valid' => true, 'errors' => []]);

        $repo = $this->createMock(RosterRepository::class);
        $repo->method('first')->willReturn(null);
        $repo->method('count')->willReturn(0);
        $repo->method('where')->willReturn(new RostersCollection());
        $repo->method('create')->willReturnCallback(function (array $data) {
            $skip = !empty($data['_skip_age_group_validation']);
            unset($data['_skip_age_group_validation']);
            $roster = new Roster($data);
            if ($skip) {
                $roster->setSkipAgeGroupValidation(true);
            }
            try {
                $roster->validate();
            } catch (\Throwable $e) {
                $errors = method_exists($e, 'getValidationErrors') ? $e->getValidationErrors() : [];
                $this->create_errors[] = $e->getMessage() . ' ' . json_encode($errors);
                throw $e;
            }
            $roster->setKey(count($this->created) + 1);
            $this->created[] = $roster->toArray();
            return $roster;
        });

        return new RosterBuilder($logger, $database, $player_repo, $repo, $validator,
            $this->createMock(EventMatcher::class), $matcher);
    }

    private function processor(RosterBuilder $builder): OrderProcessor {
        $repo = $this->createMock(RosterRepository::class);
        $repo->method('count')->willReturn(0);
        return new OrderProcessor($this->logger(), $repo, $builder);
    }

    private static function eventDetails(array $row): array {
        $ed = $row['event_details'] ?? [];
        if (is_string($ed)) {
            $ed = json_decode($ed, true) ?: [];
        }
        return is_array($ed) ? $ed : [];
    }

    private function why(): string {
        return $this->create_errors ? ' (swallowed: ' . implode(' | ', $this->create_errors) . ')' : '';
    }

    /** Fields that must come from the stored item, compared deleted vs control. */
    private const SHAPE = ['activity_type', 'venue', 'season', 'age_group', 'booking_type', 'start_date', 'end_date', 'course_day', 'times', 'product_name'];

    private static function shape(array $row): array {
        $out = [];
        foreach (self::SHAPE as $k) {
            $v = $row[$k] ?? null;
            $out[$k] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
        }
        return $out;
    }

    private function buildControlRow(): array {
        $order = $this->order(9201, $this->liveLine());
        $this->processor($this->builder())->processOrder($order);
        $this->assertCount(1, $this->created, 'precondition: control line (product exists) creates 1 row' . $this->why());
        $row = $this->created[0];
        $this->created = [];
        $this->create_errors = [];
        return $row;
    }

    // ------------------------------------------------------------------ (1) live hook path

    /**
     * (1) woocommerce_order_status_completed (hooks-manager.php:53) -> OrderProcessor::processOrder
     * -> RosterBuilder::buildRosterFromOrder -> processOrderItems -> processOrderItem creates 1 row.
     */
    public function test_live_hook_path_creates_one_row_for_deleted_product_line(): void {
        $order = $this->order(9101, $this->deletedLine());

        $this->processor($this->builder())->processOrder($order);

        $this->assertCount(1, $this->created,
            'Live hook: expected 1 roster row for the paid deleted-product line; master created ' . count($this->created) . $this->why());
    }

    // ------------------------------------------------------------------ (2) reconcile path

    public function test_reconcile_path_creates_one_row_for_deleted_product_line(): void {
        $this->order(9102, $this->deletedLine(5102));
        $GLOBALS['intersoccer_test_wc_get_orders_callback'] = static function ($args) {
            return (int) ($args['offset'] ?? 0) === 0 ? [9102] : [];
        };

        $result = $this->builder()->reconcile(['delete_obsolete' => false, 'batch_size' => 50]);

        $this->assertSame(1, count($this->created),
            'Reconcile: expected 1 roster row; master created ' . count($this->created)
            . ' (synced=' . $result['synced'] . ', errors=' . $result['errors'] . ')' . $this->why());
    }

    // ------------------------------------------------------------------ (3) stored ids + item data

    public function test_row_keeps_stored_product_and_variation_ids(): void {
        $order = $this->order(9103, $this->deletedLine(5103));
        $this->processor($this->builder())->processOrder($order);

        $row = $this->created[0] ?? null;
        $got = $row ? [(int) $row['product_id'], (int) ($row['variation_id'] ?? 0)] : 'no row';
        $this->assertSame([DeletedProductFixture::DELETED_PID, DeletedProductFixture::DELETED_VID], $got,
            'Stored ids: expected product/variation ' . DeletedProductFixture::DELETED_PID . '/' . DeletedProductFixture::DELETED_VID
            . ' (as on the item meta); master gives ' . (is_array($got) ? implode('/', $got) : $got) . $this->why());
    }

    public function test_row_event_fields_come_from_item_data(): void {
        $control = self::shape($this->buildControlRow());

        $order = $this->order(9104, $this->deletedLine(5104));
        $this->processor($this->builder())->processOrder($order);
        $row = $this->created[0] ?? null;

        $this->assertNotNull($row, 'Item data: expected a row built from the item data; master created none' . $this->why());
        $shape = self::shape($row);
        // product_name: no product -> the stored item name.
        $control['product_name'] = 'Synthetic Sunday Course - Venue A - Winter 2026';
        $this->assertSame($control, $shape, 'Item data: deleted-product row fields should match the control row built from the same item meta');
    }

    // ------------------------------------------------------------------ (4) flag

    public function test_event_details_flags_product_deleted(): void {
        $order = $this->order(9105, $this->deletedLine(5105));
        $this->processor($this->builder())->processOrder($order);
        $row = $this->created[0] ?? null;

        $this->assertNotNull($row, 'Flag: expected a row with event_details.product_deleted = true; master created none' . $this->why());
        $this->assertTrue(self::eventDetails($row)['product_deleted'] ?? false,
            'Flag: expected event_details.product_deleted = true; got ' . json_encode(self::eventDetails($row)));
    }

    // ------------------------------------------------------------------ (5) Final Numbers + Booking Report count it

    private function finalNumbersCourseRegistrations(array $rows): int {
        $data = $this->finalNumbersCourseReport($rows);
        $totals = intersoccer_calculate_final_reports_totals(is_array($data) ? $data : [], 'Course');
        return (int) ($totals['all']['registrations'] ?? 0);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function finalNumbersCourseReport(array $rows): array {
        $this->wpdb->roster_rows = $rows;
        $previous_log = ini_set('error_log', '/dev/null');
        try {
            $data = intersoccer_get_final_reports_data(2026, 'Course');
        } finally {
            ini_set('error_log', (string) $previous_log);
        }
        return is_array($data) ? $data : [];
    }

    private function courseReportHasDeletedProductMarker(array $data): bool {
        foreach ($data as $season => $regions) {
            if ($season === '__player_registration_totals__' || !is_array($regions)) {
                continue;
            }
            foreach ($regions as $region_rows) {
                if (!is_array($region_rows)) {
                    continue;
                }
                foreach ($region_rows as $row) {
                    if (is_array($row) && !empty($row['has_deleted_product'])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function finalNumbersCampReport(array $rows): array {
        $this->wpdb->roster_rows = $rows;
        $previous_log = ini_set('error_log', '/dev/null');
        try {
            $data = intersoccer_get_final_reports_data(2026, 'Camp');
        } finally {
            ini_set('error_log', (string) $previous_log);
        }
        return is_array($data) ? $data : [];
    }

    private function campReportHasDeletedProductMarker(array $data): bool {
        foreach ($data as $week => $cantons) {
            if ($week === '__player_registration_totals__' || !is_array($cantons)) {
                continue;
            }
            foreach ($cantons as $venues) {
                if (!is_array($venues)) {
                    continue;
                }
                foreach ($venues as $venue_data) {
                    if (!is_array($venue_data)) {
                        continue;
                    }
                    if (function_exists('intersoccer_reports_camp_venue_metrics_has_deleted_product')
                        && intersoccer_reports_camp_venue_metrics_has_deleted_product($venue_data)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Final Numbers query row (reports-data.php course SELECT) for a stored roster row. */
    private static function finalRow(array $r, int $id): array {
        $d = static function ($v) { return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v; };
        $out = [
            'roster_row_id' => $id, 'order_item_id' => $r['order_item_id'], 'order_id' => $r['order_id'],
            'product_id' => $r['product_id'], 'variation_id' => $r['variation_id'] ?? 0, 'product_name' => $r['product_name'] ?? '',
            'canton' => $r['canton_region'] ?? ($r['region'] ?? ''), 'venue' => $r['venue'] ?? '', 'course_day' => $r['course_day'] ?? '',
            'times' => $r['times'] ?? '', 'season' => $r['season'] ?? '', 'roster_season' => $r['season'] ?? '',
            'activity_type' => $r['activity_type'] ?? '', 'roster_activity_type' => $r['activity_type'] ?? '',
            'start_date' => $d($r['start_date'] ?? ''), 'roster_start_date' => $d($r['start_date'] ?? ''),
            'end_date' => $d($r['end_date'] ?? ''), 'roster_end_date' => $d($r['end_date'] ?? ''),
            'girls_only' => $r['girls_only'] ?? 0, 'booking_type' => $r['booking_type'] ?? '', 'age_group' => $r['age_group'] ?? '',
            'order_item_name' => 'Synthetic Sunday Course - Venue A - Winter 2026', 'post_date' => '2026-01-12 10:00:00',
            'line_subtotal' => '300', 'line_total' => '300',
        ];
        if (array_key_exists('event_details', $r)) {
            $out['event_details'] = $r['event_details'];
        }
        return $out;
    }

    /** @param array<string,mixed> $overrides */
    private static function finalCampRow(array $overrides = []): array {
        return array_merge([
            'order_item_id' => 6201,
            'order_id' => 62001,
            'activity_type' => 'Camp',
            'canton' => 'Geneva',
            'venue' => 'Placement Venue',
            'season' => 'Summer 2026',
            'camp_terms' => 'Week 1',
            'booking_type' => 'Full Week',
            'selected_days' => '',
            'age_group' => '5-13y Full Day',
            'product_id' => DeletedProductFixture::DELETED_PID,
            'variation_id' => DeletedProductFixture::DELETED_VID,
            'product_name' => 'Synthetic Camp Fixture',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-10',
            'line_subtotal' => '250',
            'line_total' => '250',
            'post_date' => '2026-06-01 10:00:00',
            'program_year' => '2026',
        ], $overrides);
    }

    /** (5a) End to end: the deleted-product line is a place in Final Numbers (via the row the build path writes). */
    public function test_final_numbers_counts_deleted_product_place(): void {
        $order = $this->order(9106, $this->deletedLine(5106));
        $this->processor($this->builder())->processOrder($order);
        $rows = [];
        foreach ($this->created as $i => $r) { $rows[] = self::finalRow($r, $i + 1); }

        $n = $this->finalNumbersCourseRegistrations($rows);

        $this->assertSame(1, $n, 'Final Numbers: expected 1 course registration for the paid line; master counts ' . $n
            . ' (roster rows written: ' . count($this->created) . ')' . $this->why());
    }

    /**
     * (5a guard) Once such a row exists (stored ids, product gone, flag set), Final Numbers must not filter it out.
     * Uses the control row's shape with the deleted ids and the flag.
     */
    public function test_final_numbers_keeps_flagged_row_whose_product_is_gone(): void {
        $row = $this->buildControlRow();
        $row['order_id'] = 9107;
        $row['order_item_id'] = 5107;
        $row['product_id'] = DeletedProductFixture::DELETED_PID;
        $row['variation_id'] = DeletedProductFixture::DELETED_VID;
        $row['event_details'] = json_encode(self::eventDetails($row) + ['product_deleted' => true]);

        $n = $this->finalNumbersCourseRegistrations([self::finalRow($row, 7)]);

        $this->assertSame(1, $n, 'Final Numbers: a flagged deleted-product row must still count; counted ' . $n);
    }

    public function test_final_numbers_marker_when_catalog_missing_without_stored_flag(): void {
        $row = $this->buildControlRow();
        $row['product_id'] = DeletedProductFixture::DELETED_PID;
        $row['variation_id'] = DeletedProductFixture::DELETED_VID;
        unset($row['event_details']);

        $data = $this->finalNumbersCourseReport([self::finalRow($row, 71)]);
        $this->assertTrue($this->courseReportHasDeletedProductMarker($data));
        $this->assertSame(1, $this->finalNumbersCourseRegistrations([self::finalRow($row, 71)]));
    }

    public function test_final_numbers_marker_when_event_details_flag_set(): void {
        $row = $this->buildControlRow();
        $row['event_details'] = json_encode(['product_deleted' => true]);

        $data = $this->finalNumbersCourseReport([self::finalRow($row, 72)]);
        $this->assertTrue($this->courseReportHasDeletedProductMarker($data));
    }

    public function test_final_numbers_no_marker_for_live_catalog_product(): void {
        $row = $this->buildControlRow();
        $data = $this->finalNumbersCourseReport([self::finalRow($row, 73)]);
        $this->assertFalse($this->courseReportHasDeletedProductMarker($data));
    }

    public function test_final_numbers_camp_marker_when_catalog_missing(): void {
        $data = $this->finalNumbersCampReport([self::finalCampRow()]);
        $this->assertTrue($this->campReportHasDeletedProductMarker($data));
    }

    public function test_final_numbers_totals_unchanged_by_deleted_product_marker(): void {
        $missing = $this->buildControlRow();
        $missing['product_id'] = DeletedProductFixture::DELETED_PID;
        $missing['variation_id'] = DeletedProductFixture::DELETED_VID;
        $live = $this->buildControlRow();
        $live['order_item_id'] = 5174;
        $live['order_id'] = 9174;

        $missing_regs = $this->finalNumbersCourseRegistrations([self::finalRow($missing, 74)]);
        $live_regs = $this->finalNumbersCourseRegistrations([self::finalRow($live, 75)]);
        $this->assertSame($live_regs, $missing_regs);
        $this->assertSame(1, $missing_regs);
    }

    /** (5b) Booking Report counts the place (reads _product_id from item meta, not the catalogue). */
    public function test_booking_report_counts_deleted_product_place(): void {
        $order = $this->order(9108, $this->deletedLine(5108));
        ReportAccuracyStore::addOrder($order); // Booking SQL reads the stored line meta
        ReportAccuracyStore::setItemMeta(5108, '_product_id', (string) DeletedProductFixture::DELETED_PID, false);
        ReportAccuracyStore::setItemMeta(5108, '_variation_id', (string) DeletedProductFixture::DELETED_VID, false);

        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);
        $classifier = $this->createMock(ProductTypeClassifierService::class);
        $classifier->method('classifyOrderItem')->willReturn(ProductTypeClassifierService::TYPE_COURSE);
        $result = (new FinancialReportService($this->createMock(Logger::class), $database, $classifier))
            ->getFinancialBookingReport('2025-01-01', '2026-10-13');

        $this->assertSame(1, $result['totals']['bookings'], 'Booking Report: expected 1 booking; got ' . $result['totals']['bookings']);
        $this->assertSame('300.00', $result['data'][0]['final_price'] ?? null);
    }

    // ------------------------------------------------------------------ (3b) variation deleted, product kept

    /**
     * (3b) Only the variation was deleted (parent product still exists): the row must keep the stored
     * variation id instead of 0.
     */
    public function test_variation_deleted_row_keeps_stored_variation_id(): void {
        $item = new StoredLineItem(5301, 'Synthetic Sunday Course - Venue A - Winter 2026',
            DeletedProductFixture::LIVE_PID, DeletedProductFixture::DELETED_VARIATION_ONLY, DeletedProductFixture::courseMeta(), true, 300.0, false);
        $order = $this->order(9301, $item);
        $this->processor($this->builder())->processOrder($order);
        $row = $this->created[0] ?? null;

        $this->assertNotNull($row, 'Variation deleted: expected 1 row' . $this->why());
        $this->assertSame([DeletedProductFixture::LIVE_PID, DeletedProductFixture::DELETED_VARIATION_ONLY],
            [(int) $row['product_id'], (int) ($row['variation_id'] ?? 0)],
            'Variation deleted: stored variation id should be kept; master writes ' . (int) ($row['variation_id'] ?? 0));
    }

    public function test_deleted_product_build_logs_error_with_catalogue_ids_only(): void {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->equalTo('Roster row built for deleted catalogue product'),
                $this->callback(static function ($context) {
                    return is_array($context)
                        && !isset($context['order_id'], $context['order_item_id'])
                        && isset($context['product_id'], $context['variation_id']);
                })
            );

        $order = $this->order(9401, $this->deletedLine(5401));
        $this->processor($this->builderWithLogger($logger))->processOrder($order);
        $this->assertCount(1, $this->created, 'expected row despite deleted product' . $this->why());
    }

    public function test_failed_roster_insert_logs_error_and_build_stats(): void {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                $this->equalTo('Roster insert failed'),
                $this->callback(static function ($context) {
                    return is_array($context)
                        && (int) ($context['order_id'] ?? 0) === 9501
                        && (int) ($context['order_item_id'] ?? 0) === 5501;
                })
            );

        $builder = $this->builderWithLogger($logger, static function () {
            return null;
        });
        $order = $this->order(9501, $this->liveLine(5501));
        $this->processor($builder)->processOrder($order);
        $this->assertSame(0, count($this->created));
        $prop = new \ReflectionProperty(RosterBuilder::class, 'build_stats');
        $prop->setAccessible(true);
        $stats = $prop->getValue($builder);
        $this->assertNotEmpty($stats['errors'] ?? []);
    }

    private function builderWithLogger(Logger $logger, ?callable $create_callback = null): RosterBuilder {
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);

        $player = new Player([
            'customer_id' => 1, 'player_index' => 0, 'first_name' => 'Synthetic', 'last_name' => 'Player',
            'dob' => '2018-03-01', 'gender' => 'male', 'medical_conditions' => '', 'dietary_needs' => '',
        ]);
        $players = new PlayersCollection([$player]);
        $player_repo = $this->createMock(PlayerRepository::class);
        $player_repo->method('getPlayersByCustomerId')->willReturn($players);
        $matcher = $this->createMock(PlayerMatcher::class);
        $matcher->method('resolveAssignedPlayers')->willReturn([
            'players' => $players,
            'strategy' => 'metadata_uuid',
            'ambiguous' => false,
            'fallback_candidate_count' => 0,
        ]);
        $validator = $this->createMock(DataValidator::class);
        $validator->method('validateRosterData')->willReturn(['valid' => true, 'errors' => []]);

        $repo = $this->createMock(RosterRepository::class);
        $repo->method('first')->willReturn(null);
        $repo->method('count')->willReturn(0);
        $repo->method('where')->willReturn(new RostersCollection());
        if ($create_callback !== null) {
            $repo->method('create')->willReturnCallback($create_callback);
        } else {
            $repo->method('create')->willReturnCallback(function (array $data) {
                $roster = new Roster($data);
                $roster->setKey(count($this->created) + 1);
                $this->created[] = $roster->toArray();
                return $roster;
            });
        }

        return new RosterBuilder($logger, $database, $player_repo, $repo, $validator,
            $this->createMock(EventMatcher::class), $matcher);
    }

    // ------------------------------------------------------------------ (6) control

    public function test_deleted_product_uses_english_name_when_wpml_resolves(): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$rest) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id') {
                return DeletedProductFixture::LIVE_VID;
            }
            return $value;
        };
        $french = 'Cours dominical synthétique - Site A - Hiver 2026';
        $builder = $this->builder();
        $method = new \ReflectionMethod($builder, 'resolveEnglishProductNameForDeletedLine');
        $method->setAccessible(true);
        $resolved = $method->invoke($builder, [
            'product_name' => $french,
            'product_id' => DeletedProductFixture::DELETED_PID,
            'variation_id' => DeletedProductFixture::DELETED_VID,
        ]);
        $this->assertSame('Synthetic Sunday Course - Venue A', $resolved['product_name']);
        $this->assertFalse($resolved['name_not_english']);
        unset($GLOBALS['intersoccer_test_apply_filters_callback']);
    }

    public function test_deleted_product_empty_stored_name_uses_placeholder_and_flags_not_english(): void {
        $builder = $this->builder();
        $method = new \ReflectionMethod($builder, 'resolveEnglishProductNameForDeletedLine');
        $method->setAccessible(true);
        $resolved = $method->invoke($builder, [
            'product_name' => '',
            'product_id' => 4242,
            'variation_id' => 0,
        ]);
        $this->assertSame('Deleted product #4242', $resolved['product_name']);
        $this->assertTrue($resolved['name_not_english']);
    }

    public function test_deleted_product_without_wpml_flags_name_not_english_on_fallback(): void {
        $prev_filters = $GLOBALS['intersoccer_test_apply_filters_callback'] ?? null;
        unset($GLOBALS['intersoccer_test_apply_filters_callback']);
        $german = 'Einzelner Tage Camp - Testort';
        $builder = $this->builder();
        $method = new \ReflectionMethod($builder, 'resolveEnglishProductNameForDeletedLine');
        $method->setAccessible(true);
        $resolved = $method->invoke($builder, [
            'product_name' => $german,
            'product_id' => DeletedProductFixture::DELETED_PID,
            'variation_id' => DeletedProductFixture::DELETED_VID,
        ]);
        $this->assertSame($german, $resolved['product_name']);
        $this->assertTrue($resolved['name_not_english']);
        if ($prev_filters !== null) {
            $GLOBALS['intersoccer_test_apply_filters_callback'] = $prev_filters;
        }
    }

    public function test_deleted_product_french_fallback_sets_name_not_english_flag(): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$rest) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id') {
                return DeletedProductFixture::DELETED_VID;
            }
            return $value;
        };
        $french = 'Cours dominical synthétique - Site A - Hiver 2026';
        $builder = $this->builder();
        $method = new \ReflectionMethod($builder, 'resolveEnglishProductNameForDeletedLine');
        $method->setAccessible(true);
        $resolved = $method->invoke($builder, [
            'product_name' => $french,
            'product_id' => DeletedProductFixture::DELETED_PID,
            'variation_id' => DeletedProductFixture::DELETED_VID,
        ]);
        $this->assertSame($french, $resolved['product_name']);
        $this->assertTrue($resolved['name_not_english']);
        unset($GLOBALS['intersoccer_test_apply_filters_callback']);
    }

    /** (6) Control: same line with the product present -> 1 row, live ids, same shape, no product_deleted flag. */
    public function test_control_existing_product_line_same_shape_without_flag(): void {
        $row = $this->buildControlRow();

        $this->assertSame([DeletedProductFixture::LIVE_PID, DeletedProductFixture::LIVE_VID], [(int) $row['product_id'], (int) $row['variation_id']]);
        foreach (['activity_type', 'venue', 'season', 'age_group', 'booking_type', 'start_date', 'end_date'] as $k) {
            $this->assertNotEmpty(self::shape($row)[$k] ?? null, "control: $k set from the item meta");
        }
        $this->assertArrayNotHasKey('product_deleted', self::eventDetails($row));
    }
}
