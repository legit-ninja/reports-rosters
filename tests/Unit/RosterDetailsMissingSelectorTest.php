<?php
/**
 * Roster details must not load the roster table when no selector is present.
 *
 * A variation id, variation id list, event signature, or order line is required.
 * Order status alone used to keep the query running across every roster row.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit {

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterDetailsQueryCountingWpdb extends \InterSoccerTestWpdb {
    /** @var array<int,array{0:string,1:string}> */
    public $calls = [];

    public function prepare($query, ...$args) {
        $this->calls[] = ['prepare', (string) $query];
        return parent::prepare($query, ...$args);
    }

    public function get_results($query = null, $output = OBJECT) {
        $this->calls[] = ['get_results', (string) $query];
        return [];
    }

    public function get_col($query = null, $x = 0) {
        $this->calls[] = ['get_col', (string) $query];
        return [];
    }

    public function get_var($query = null, $x = 0, $y = 0) {
        $this->calls[] = ['get_var', (string) $query];
        return null;
    }

    public function query($query) {
        $this->calls[] = ['query', (string) $query];
        return true;
    }
}

class RosterDetailsMissingSelectorTest extends TestCase {

    private const NOTICE = 'A variation, event signature, or order line is required to open roster details.';

    /** @var RosterDetailsQueryCountingWpdb */
    private $db;

    /** @var array */
    private $saved_get;

    protected function setUp(): void {
        parent::setUp();
        $this->saved_get = $_GET;
        $_GET = [];
        require_once dirname(__DIR__, 2) . '/includes/roster-details.php';
        if (!function_exists('intersoccer_roster_move_merge_empty_sibling_variation_targets')) {
            require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
        }
        $this->installDatabase();
    }

    protected function tearDown(): void {
        $_GET = $this->saved_get;
        unset($GLOBALS['intersoccer_test_current_user_caps'], $GLOBALS['intersoccer_test_current_user']);
        parent::tearDown();
    }

    /**
     * @dataProvider missingSelectorProvider
     * @param array<string,string> $query
     */
    public function test_missing_selector_shows_notice_and_skips_roster_load(array $query): void {
        $_GET = $query;
        $this->installDatabase();

        $html = $this->render();

        $this->assertSame([], $this->db->calls);
        $this->assertStringContainsString(self::NOTICE, $html);
        $this->assertStringContainsString('notice notice-warning', $html);
        $this->assertStringContainsString('Roster Details', $html);
        $this->assertStringNotContainsString('No rosters found for the provided parameters.', $html);
    }

    /**
     * @return array<string,array{0:array<string,string>}>
     */
    public function missingSelectorProvider(): array {
        return [
            'no query params' => [[]],
            'variation id absent' => [[
                'page' => 'intersoccer-roster-details',
                'from' => 'camps',
            ]],
            'variation id zero' => [['variation_id' => '0']],
            'variation id negative' => [['variation_id' => '-4']],
            'variation id not numeric' => [['variation_id' => 'nope']],
            'variation id empty' => [['variation_id' => '']],
            'other selectors invalid' => [[
                'variation_id' => '0',
                'variation_ids' => '0,-8,abc',
                'order_item_ids' => '0',
                'event_signature' => 'N/A',
                'event_signatures' => 'N/A,',
                'product_id' => '42',
                'venue' => 'Example Hall',
                'season' => 'Autumn',
            ]],
        ];
    }

    /**
     * @dataProvider selectorProvider
     * @param array<string,string> $query
     */
    public function test_selector_uses_the_roster_load_and_skips_the_notice(array $query, string $needle): void {
        $_GET = $query;
        $this->installDatabase();

        $html = $this->render();

        $this->assertNotSame([], $this->db->calls);
        $joined = '';
        foreach ($this->db->calls as $call) {
            $joined .= ' ' . $call[0] . ' ' . $call[1];
        }
        $this->assertStringContainsString('get_results', $joined);
        $this->assertStringContainsString($needle, $joined);
        $this->assertStringNotContainsString(self::NOTICE, $html);
        $this->assertStringContainsString('No rosters found for the provided parameters.', $html);
    }

    /**
     * @return array<string,array{0:array<string,string>,1:string}>
     */
    public function selectorProvider(): array {
        return [
            'variation id' => [['variation_id' => '50101'], '50101'],
            'variation ids' => [['variation_ids' => '50102,50103'], '50102'],
            'event signature' => [['event_signature' => 'fixture-signature-501'], 'fixture-signature-501'],
            'event signatures' => [['event_signatures' => 'fixture-sig-a,fixture-sig-b'], 'fixture-sig-a'],
            'order line' => [['order_item_ids' => '60101'], '60101'],
        ];
    }

    public function test_unauthorized_user_is_still_denied_without_a_roster_load(): void {
        intersoccer_test_set_user_caps(['read' => true]);
        $_GET = ['variation_id' => '50101'];
        $this->installDatabase();

        try {
            $this->render();
            $this->fail('Expected permission denial.');
        } catch (\Exception $e) {
            $this->assertSame('Permission denied.', $e->getMessage());
        }

        $this->assertSame([], $this->db->calls);
        $this->assertStringNotContainsString(self::NOTICE, $this->renderCaptured);
    }

    /** @var string */
    private $renderCaptured = '';

    private function render(): string {
        ob_start();
        try {
            intersoccer_render_roster_details_page();
        } finally {
            $this->renderCaptured = (string) ob_get_clean();
        }

        return $this->renderCaptured;
    }

    private function installDatabase(): void {
        global $wpdb;
        $this->db = new RosterDetailsQueryCountingWpdb();
        $wpdb = $this->db;
    }
}
}

namespace {
    if (!function_exists('wp_get_referer')) {
        function wp_get_referer() {
            return '';
        }
    }
}
