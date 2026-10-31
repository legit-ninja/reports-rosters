<?php
/**
 * Roster editor AJAX must wp_unslash() posted values before compare/sanitize (magic quotes).
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterEditorPostUnslashTest extends TestCase {

    private static bool $escTextareaStubbed = false;

    private const DAY_PRESENCE = '{"Monday":"Yes","Tuesday":"No"}';
    private const PLAYER_MEDICAL = 'Allergic to "nuts" & dairy; it\'s severe';
    private const PRODUCT_NAME = "Stage d'automne";
    private const SINGLE_FIELD_VALUE = 'Stage d\'automne "A"';

    private const EDITABLE = [
        'player_name', 'first_name', 'last_name', 'player_index', 'age', 'gender',
        'venue', 'activity_type', 'booking_type', 'selected_days', 'day_presence',
        'age_group', 'season', 'start_date', 'end_date', 'camp_terms', 'course_day',
        'times', 'shirt_size', 'shorts_size', 'late_pickup', 'late_pickup_days',
        'medical_conditions', 'dietary_needs', 'player_medical', 'player_dietary',
        'parent_email', 'parent_phone', 'emergency_contact', 'emergency_phone',
        'avs_number', 'player_dob', 'product_name', 'canton_region',
        'registration_timestamp', 'girls_only',
    ];

    /** @var object|null */
    private $previousWpdb;
    /** @var array */
    private $previousPost;

    protected function setUp(): void {
        parent::setUp();
        if (!self::$escTextareaStubbed && !\function_exists('esc_textarea')) {
            eval('namespace { function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES, "UTF-8"); } }');
            self::$escTextareaStubbed = true;
        }
        if (!function_exists('intersoccer_render_form_field')) {
            require_once dirname(__DIR__, 2) . '/includes/roster-editor.php';
        }
        if (!function_exists('intersoccer_rr_strip_medical_from_update_data')) {
            require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
        }
        if (!function_exists('intersoccer_ajax_update_roster_entry')) {
            require_once dirname(__DIR__, 2) . '/includes/roster-editor-ajax.php';
        }
        if (!function_exists('sanitize_textarea_field')) {
            eval('function sanitize_textarea_field($s) { return trim((string) $s); }');
        }
        global $wpdb;
        $this->previousWpdb = $wpdb ?? null;
        $this->previousPost = $_POST ?? [];
        $GLOBALS['intersoccer_test_current_user_caps'] = ['manage_options' => true];
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previousWpdb;
        $_POST = $this->previousPost;
        unset($GLOBALS['intersoccer_test_current_user_caps']);
        parent::tearDown();
    }

    private function baselineRow(): array {
        return [
            'id' => 700401,
            'order_id' => 800401,
            'order_item_id' => 810401,
            'player_name' => 'Qaone Qatest',
            'first_name' => 'Qaone',
            'last_name' => 'Qatest',
            'player_index' => '0',
            'age' => '8',
            'gender' => 'Male',
            'venue' => 'QA Venue',
            'activity_type' => 'Course',
            'booking_type' => 'Full Term',
            'selected_days' => null,
            'day_presence' => self::DAY_PRESENCE,
            'age_group' => '5-13y',
            'season' => 'Autumn 2026',
            'start_date' => '2026-09-05',
            'end_date' => '2026-12-12',
            'camp_terms' => null,
            'course_day' => 'Saturday',
            'times' => '10:00-11:00',
            'shirt_size' => 'N/A',
            'shorts_size' => 'N/A',
            'late_pickup' => 'No',
            'late_pickup_days' => null,
            'medical_conditions' => self::PLAYER_MEDICAL,
            'dietary_needs' => '',
            'player_medical' => self::PLAYER_MEDICAL,
            'player_dietary' => null,
            'parent_email' => 'parent@example.test',
            'parent_phone' => '+41000000000',
            'emergency_contact' => 'N/A',
            'emergency_phone' => 'N/A',
            'avs_number' => '756.0000.0000.00',
            'player_dob' => '2018-05-10',
            'product_name' => self::PRODUCT_NAME,
            'canton_region' => null,
            'registration_timestamp' => null,
            'girls_only' => '0',
        ];
    }

    private function renderFieldValue(string $field, string $type, string $value): string {
        ob_start();
        intersoccer_render_form_field([$field => $value], $field, 'Label', $type);
        $html = (string) ob_get_clean();
        if ($type === 'textarea') {
            if (!preg_match(
                '/<textarea[^>]*name="' . preg_quote($field, '/') . '"[^>]*>(.*)<\/textarea>/s',
                $html,
                $m
            )) {
                $this->fail('textarea not found for ' . $field);
            }
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!preg_match(
            '/<input[^>]*name="' . preg_quote($field, '/') . '"[^>]*value="([^"]*)"/',
            $html,
            $m
        )) {
            $this->fail('input not found for ' . $field);
        }
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string,mixed> $post */
    private function applyMagicQuotes(array $post): array {
        $out = [];
        foreach ($post as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->applyMagicQuotes($value);
                continue;
            }
            $out[$key] = addslashes((string) $value);
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function buildFullFormPost(array $row): array {
        $post = [
            'action' => 'intersoccer_update_roster_entry',
            'nonce' => 'x',
            'roster_id' => (string) $row['id'],
        ];
        foreach (self::EDITABLE as $field) {
            $v = $row[$field] ?? null;
            if ($field === 'day_presence' || $field === 'player_medical' || $field === 'medical_conditions') {
                $post[$field] = $this->renderFieldValue($field, 'textarea', $v === null ? '' : (string) $v);
                continue;
            }
            if ($field === 'product_name') {
                $post[$field] = $this->renderFieldValue($field, 'text', $v === null ? '' : (string) $v);
                continue;
            }
            if ($field === 'registration_timestamp' && is_string($v) && strpos($v, ' ') !== false) {
                $v = str_replace(' ', 'T', $v);
            }
            $post[$field] = $v === null ? '' : (string) $v;
        }
        return $this->applyMagicQuotes($post);
    }

    /** @return object wpdb stub with mutable row */
    private function installStatefulWpdb(array $row): object {
        global $wpdb;
        $wpdb = new class($row) {
            public $prefix = 'wp_';
            /** @var array<string,mixed> */
            public $row;
            /** @var list<array<string,mixed>> */
            public $updateCalls = [];

            /** @param array<string,mixed> $row */
            public function __construct(array $row) {
                $this->row = $row;
            }

            public function prepare($q, ...$a) {
                return $q;
            }

            public function get_row($q, $o = null) {
                return $this->row;
            }

            /** @param array<string,mixed> $data */
            public function update($table, $data, $where, $f = null, $wf = null) {
                $this->updateCalls[] = $data;
                foreach ($data as $key => $value) {
                    $this->row[$key] = $value;
                }
                return 1;
            }
        };
        return $wpdb;
    }

    private function invokeUpdateAjax(): void {
        ob_start();
        intersoccer_ajax_update_roster_entry();
        ob_end_clean();
    }

    private function assertStoredBytesUnchanged(object $wpdb, string $day, string $medical, string $product): void {
        $this->assertSame($day, $wpdb->row['day_presence']);
        $this->assertSame($medical, $wpdb->row['player_medical']);
        $this->assertSame($product, $wpdb->row['product_name']);
    }

    public function test_full_form_no_edit_save_twice_with_magic_quotes_does_not_update(): void {
        $wpdb = $this->installStatefulWpdb($this->baselineRow());
        $post = $this->buildFullFormPost($wpdb->row);

        $_POST = $post;
        $this->invokeUpdateAjax();
        $this->assertSame([], $wpdb->updateCalls);
        $this->assertStoredBytesUnchanged($wpdb, self::DAY_PRESENCE, self::PLAYER_MEDICAL, self::PRODUCT_NAME);

        $_POST = $post;
        $this->invokeUpdateAjax();
        $this->assertSame([], $wpdb->updateCalls);
        $this->assertStoredBytesUnchanged($wpdb, self::DAY_PRESENCE, self::PLAYER_MEDICAL, self::PRODUCT_NAME);
    }

    public function test_full_form_edit_other_field_preserves_quoted_fields_with_magic_quotes(): void {
        $wpdb = $this->installStatefulWpdb($this->baselineRow());
        $post = $this->buildFullFormPost($wpdb->row);
        $post['shirt_size'] = addslashes('QA-M');

        $_POST = $post;
        $this->invokeUpdateAjax();

        $this->assertCount(1, $wpdb->updateCalls);
        $this->assertSame(['shirt_size' => 'QA-M'], $wpdb->updateCalls[0]);
        $this->assertStoredBytesUnchanged($wpdb, self::DAY_PRESENCE, self::PLAYER_MEDICAL, self::PRODUCT_NAME);
    }

    public function test_single_field_save_with_magic_quotes_stores_unslashed_bytes(): void {
        $wpdb = $this->installStatefulWpdb($this->baselineRow());

        $_POST = $this->applyMagicQuotes([
            'action' => 'intersoccer_update_roster_entry',
            'nonce' => 'x',
            'roster_id' => (string) $wpdb->row['id'],
            'field' => 'product_name',
            'value' => self::SINGLE_FIELD_VALUE,
        ]);

        $this->invokeUpdateAjax();

        $this->assertCount(1, $wpdb->updateCalls);
        $this->assertSame(self::SINGLE_FIELD_VALUE, $wpdb->row['product_name']);
        $this->assertSame(self::SINGLE_FIELD_VALUE, $wpdb->updateCalls[0]['product_name']);
    }
}
