<?php
/**
 * Roster editor form fields must escape values once (no esc_attr + esc_textarea double escape).
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterEditorFormFieldEscapeTest extends TestCase {

    private static bool $escTextareaStubbed = false;

    private const DAY_PRESENCE = '{"Monday":"Yes","Tuesday":"No"} & \' < >';
    private const PLAYER_MEDICAL = 'Allergic to "nuts" & dairy; it\'s <severe>';
    private const VENUE_INPUT = 'QA "Arena" & O\'Reilly <north>';

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

    private function renderField(string $field, string $label, string $type, string $value): string {
        ob_start();
        intersoccer_render_form_field([$field => $value], $field, $label, $type);
        return (string) ob_get_clean();
    }

    private function assertEscapedOnce(string $htmlFragment, string $original): void {
        $this->assertSame(
            $original,
            html_entity_decode($htmlFragment, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'Expected single-pass HTML escaping'
        );
        $this->assertStringNotContainsString('&amp;quot;', $htmlFragment);
        $this->assertStringNotContainsString('&amp;#039;', $htmlFragment);
        $this->assertStringNotContainsString('&amp;amp;', $htmlFragment);
        $this->assertStringNotContainsString('&amp;lt;', $htmlFragment);
    }

    private function textareaContent(string $html, string $fieldName): string {
        if (!preg_match(
            '/<textarea[^>]*name="' . preg_quote($fieldName, '/') . '"[^>]*>(.*)<\/textarea>/s',
            $html,
            $m
        )) {
            $this->fail('textarea not found for ' . $fieldName);
        }
        return $m[1];
    }

    private function inputValueAttribute(string $html, string $fieldName): string {
        if (!preg_match(
            '/<input[^>]*name="' . preg_quote($fieldName, '/') . '"[^>]*value="([^"]*)"/',
            $html,
            $m
        )) {
            $this->fail('input value not found for ' . $fieldName);
        }
        return $m[1];
    }

    public function test_textarea_day_presence_is_escaped_once(): void {
        $html = $this->renderField('day_presence', 'Day presence', 'textarea', self::DAY_PRESENCE);
        $content = $this->textareaContent($html, 'day_presence');
        $this->assertEscapedOnce($content, self::DAY_PRESENCE);
    }

    public function test_textarea_player_medical_is_escaped_once(): void {
        $html = $this->renderField('player_medical', 'Player medical', 'textarea', self::PLAYER_MEDICAL);
        $content = $this->textareaContent($html, 'player_medical');
        $this->assertEscapedOnce($content, self::PLAYER_MEDICAL);
    }

    public function test_text_input_value_is_escaped_once(): void {
        $html = $this->renderField('venue', 'Venue', 'text', self::VENUE_INPUT);
        $attr = $this->inputValueAttribute($html, 'venue');
        $this->assertEscapedOnce($attr, self::VENUE_INPUT);
    }

    public function test_full_form_save_round_trip_day_presence_without_update(): void {
        $this->assertFullFormRoundTripNoUpdate('day_presence', self::DAY_PRESENCE);
    }

    public function test_full_form_save_round_trip_player_medical_without_update(): void {
        $this->assertFullFormRoundTripNoUpdate('player_medical', self::PLAYER_MEDICAL);
    }

    private function assertFullFormRoundTripNoUpdate(string $field, string $stored): void {
        $html = $this->renderField($field, 'Label', 'textarea', $stored);
        $posted = html_entity_decode($this->textareaContent($html, $field), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame($stored, $posted);

        $existing = [
            'id' => 700303,
            $field => $stored,
        ];

        global $wpdb;
        $wpdb = new class($existing) {
            public $prefix = 'wp_';
            public $updateCalls = [];
            private $row;
            public function __construct(array $row) {
                $this->row = $row;
            }
            public function prepare($q, ...$a) {
                return $q;
            }
            public function get_row($q, $o = null) {
                return $this->row;
            }
            public function update($table, $data, $where, $f = null, $wf = null) {
                $this->updateCalls[] = $data;
                return 1;
            }
        };

        $_POST = [
            'action' => 'intersoccer_update_roster_entry',
            'nonce' => 'x',
            'roster_id' => (string) $existing['id'],
            $field => $posted,
        ];

        ob_start();
        intersoccer_ajax_update_roster_entry();
        $json = ob_get_clean();

        $this->assertSame([], $wpdb->updateCalls, 'Unchanged textarea must not trigger $wpdb->update. Response: ' . $json);
        $this->assertSame($stored, $posted);
    }
}
