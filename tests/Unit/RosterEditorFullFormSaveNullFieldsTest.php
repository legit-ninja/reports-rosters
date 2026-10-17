<?php
/**
 * Repro (failing on purpose): an admin full-form save on a roster row turns untouched NULL
 * fields into '' and registration_timestamp NULL into '' (stored by MySQL as 0000-00-00 00:00:00).
 *
 * Path on origin/master:
 *   form       includes/roster-editor.php:1191-1192 intersoccer_render_form_field() renders NULL as value=""
 *              and the save JS (:1142-1150) posts every input/select/textarea of #roster-edit-form.
 *   handler    includes/roster-editor-ajax.php:251 intersoccer_ajax_update_roster_entry()
 *              (wp_ajax_intersoccer_update_roster_entry, classes/Ajax/roster-editor-ajax-handler.php:19/27)
 *              full-form branch :316-337 writes every posted allowlisted field; $existing (:268) is
 *              never compared, so unchanged fields are rewritten.
 *   sanitize   includes/roster-editor-ajax.php:385 intersoccer_sanitize_roster_field(): empty value ->
 *              '' (:401) for text fields, and for registration_timestamp too, because the empty branch
 *              (:387-402) only special-cases names containing 'date' and runs before the datetime
 *              branch (:432-442, which would return NULL).
 *   write      $wpdb->update() :337.
 *
 * Mirrors QA sandbox row 285551 (synthetic values here; no real data).
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterEditorFullFormSaveNullFieldsTest extends TestCase {

    /** The six NULL columns QA saw become '' on row 285551. */
    private const NULL_FIELDS = ['player_dietary', 'selected_days', 'camp_terms', 'late_pickup_days', 'day_presence', 'canton_region'];

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

    /** @var object */
    private $wpdbStub;
    /** @var object|null */
    private $previousWpdb;
    /** @var array */
    private $previousPost;

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_ajax_update_roster_entry')) {
            require_once dirname(__DIR__, 2) . '/includes/roster-editor-ajax.php';
        }
        if (!function_exists('sanitize_textarea_field')) {
            eval('function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }');
        }
        global $wpdb;
        $this->previousWpdb = $wpdb ?? null;
        $this->previousPost = $_POST;
        $GLOBALS['intersoccer_test_current_user_caps'] = ['manage_options' => true];
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previousWpdb;
        $_POST = $this->previousPost;
        unset($GLOBALS['intersoccer_test_current_user_caps']);
        parent::tearDown();
    }

    private function existingRow(array $over = []): array {
        return array_merge([
            'id' => 700551, 'order_id' => 800551, 'order_item_id' => 810551,
            'player_name' => 'Qaone Qatest', 'first_name' => 'Qaone', 'last_name' => 'Qatest', 'player_index' => '0',
            'age' => '8', 'gender' => 'male', 'venue' => 'QA Venue', 'activity_type' => 'Course', 'booking_type' => 'Full Term',
            'selected_days' => null, 'day_presence' => null, 'age_group' => '5-13y (Full Day)', 'season' => 'Autumn 2026',
            'start_date' => '2026-09-05', 'end_date' => '2026-12-12', 'camp_terms' => null, 'course_day' => 'Saturday',
            'times' => '10:00-11:00', 'shirt_size' => 'N/A', 'shorts_size' => 'N/A', 'late_pickup' => 'No', 'late_pickup_days' => null,
            'medical_conditions' => 'QA-MEDICAL-FAKE-TEXT', 'dietary_needs' => '', 'player_medical' => 'QA-MEDICAL-FAKE-TEXT', 'player_dietary' => null,
            'parent_email' => 'parent@example.test', 'parent_phone' => '+41000000000', 'emergency_contact' => 'N/A', 'emergency_phone' => 'N/A',
            'avs_number' => '756.0000.0000.00', 'player_dob' => '2018-05-10', 'product_name' => 'QA Course', 'canton_region' => null,
            'registration_timestamp' => null, 'girls_only' => '0',
        ], $over);
    }

    /** Installs a wpdb stub that returns $existing for the SELECT and records the UPDATE. */
    private function installWpdb(array $existing): void {
        global $wpdb;
        $this->wpdbStub = new class($existing) {
            public $prefix = 'wp_';
            public $last_error = '';
            public $updates = [];
            private $row;
            public function __construct(array $row) { $this->row = $row; }
            public function prepare($q, ...$a) { return $q; }
            public function get_row($q, $o = null) { return $this->row; }
            public function update($table, $data, $where, $f = null, $wf = null) { $this->updates[] = ['data' => $data, 'where' => $where]; return 1; }
        };
        $wpdb = $this->wpdbStub;
    }

    /** Builds the POST the editor UI sends: every field, NULL rendered as '' (roster-editor.php:1192). */
    private function uiFormPost(array $existing, array $edits): array {
        $post = ['action' => 'intersoccer_update_roster_entry', 'nonce' => 'x', 'roster_id' => (string) $existing['id']];
        foreach (self::EDITABLE as $f) {
            $v = $existing[$f] ?? null;
            if ($f === 'registration_timestamp' && is_string($v) && strpos($v, ' ') !== false) {
                $v = str_replace(' ', 'T', $v); // datetime-local rendering
            }
            $post[$f] = $v === null ? '' : (string) $v;
        }
        return array_merge($post, $edits);
    }

    private function save(array $existing, array $edits): array {
        $this->installWpdb($existing);
        $_POST = $this->uiFormPost($existing, $edits);
        ob_start();
        intersoccer_ajax_update_roster_entry();
        ob_end_clean();
        $this->assertCount(1, $this->wpdbStub->updates, 'expected exactly one UPDATE from the full-form save');
        return $this->wpdbStub->updates[0]['data'];
    }

    /** Value the column would hold after the update (absent from the update = unchanged). */
    private function after(array $existing, array $update, string $f) {
        return array_key_exists($f, $update) ? $update[$f] : $existing[$f];
    }

    public function test_full_form_save_keeps_untouched_null_fields_null(): void {
        $existing = $this->existingRow();
        $update = $this->save($existing, ['shirt_size' => 'QA-M']);

        $changed = [];
        foreach (self::NULL_FIELDS as $f) {
            $after = $this->after($existing, $update, $f);
            if ($after !== null) {
                $changed[$f] = $after;
            }
        }
        $this->assertSame([], $changed, 'Untouched NULL fields were rewritten: ' . json_encode($changed));
    }

    public function test_full_form_save_preserves_null_registration_timestamp(): void {
        $existing = $this->existingRow();
        $update = $this->save($existing, ['shirt_size' => 'QA-M']);

        $after = $this->after($existing, $update, 'registration_timestamp');
        // '' into a DATETIME column is stored by MySQL (non-strict, as wpdb runs) as '0000-00-00 00:00:00'.
        $this->assertNull($after, 'registration_timestamp NULL was written as ' . var_export($after, true) . " (MySQL stores '' as 0000-00-00 00:00:00)");
    }

    /** Control (passes today): a populated registration_timestamp round-trips unchanged. */
    public function test_control_full_form_save_round_trips_populated_registration_timestamp(): void {
        $existing = $this->existingRow(['registration_timestamp' => '2026-08-01 09:15:00']);
        $update = $this->save($existing, ['shirt_size' => 'QA-M']);

        $this->assertSame('2026-08-01 09:15:00', $this->after($existing, $update, 'registration_timestamp'));
    }

    /** Control (passes today): the field the admin actually edited is saved. */
    public function test_control_full_form_save_writes_the_edited_field(): void {
        $existing = $this->existingRow();
        $update = $this->save($existing, ['shirt_size' => 'QA-M']);

        $this->assertSame('QA-M', $update['shirt_size'] ?? null);
    }

    /** Control (passes today): the admin can deliberately set a previously NULL field. */
    public function test_control_full_form_save_writes_deliberate_value_into_null_field(): void {
        $existing = $this->existingRow();
        $update = $this->save($existing, ['canton_region' => 'QA-Canton']);

        $this->assertSame('QA-Canton', $update['canton_region'] ?? null);
    }
}
