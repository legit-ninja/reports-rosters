<?php
/**
 * Roster editor activity type select uses the canonical English labels.
 *
 * Synthetic data only.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterEditorActivityTypeOptionsTest extends TestCase {

    /** @var object|null */
    private $previousWpdb;

    /** @var array */
    private $previousPost;

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

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_roster_editor_activity_type_options')) {
            require_once dirname(__DIR__, 2) . '/includes/roster-editor.php';
        }
        if (!function_exists('intersoccer_rr_strip_medical_from_update_data')) {
            require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
        }
        if (!function_exists('intersoccer_ajax_update_roster_entry')) {
            require_once dirname(__DIR__, 2) . '/includes/roster-editor-ajax.php';
        }
        if (!function_exists('sanitize_textarea_field')) {
            eval('function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }');
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

    public function test_editor_activity_type_options_match_canonical_types(): void {
        $options = intersoccer_roster_editor_activity_type_options();

        $this->assertSame(array_merge([''], Roster::CANONICAL_ACTIVITY_TYPES), $options);
        $this->assertContains('Birthday Party', $options);
        $this->assertContains('Event', $options);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function keptActivityTypes(): array {
        return [
            'Birthday Party' => ['Birthday Party'],
            'Event' => ['Event'],
        ];
    }

    /**
     * A full-form save of a Birthday Party or Event row keeps that activity type.
     *
     * @dataProvider keptActivityTypes
     */
    public function test_full_form_save_keeps_canonical_activity_type(string $activity_type): void {
        $existing = $this->existingRow(['activity_type' => $activity_type]);
        $update = $this->save($existing, ['shirt_size' => 'QA-M']);

        $this->assertSame('QA-M', $update['shirt_size'] ?? null);
        $this->assertSame($activity_type, $this->after($existing, $update, 'activity_type'));
        $this->assertArrayNotHasKey('activity_type', $update);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function existingRow(array $over = []): array {
        return array_merge([
            'id' => 700551,
            'order_id' => 800551,
            'order_item_id' => 810551,
            'player_name' => 'Example Player',
            'first_name' => 'Example',
            'last_name' => 'Player',
            'player_index' => '0',
            'age' => '8',
            'gender' => 'male',
            'venue' => 'Example Venue',
            'activity_type' => 'Course',
            'booking_type' => 'Full Term',
            'selected_days' => null,
            'day_presence' => null,
            'age_group' => '5-13y (Full Day)',
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
            'medical_conditions' => 'Example note',
            'dietary_needs' => '',
            'player_medical' => 'Example note',
            'player_dietary' => null,
            'parent_email' => 'parent@example.test',
            'parent_phone' => '+41000000000',
            'emergency_contact' => 'N/A',
            'emergency_phone' => 'N/A',
            'avs_number' => '756.0000.0000.00',
            'player_dob' => '2018-05-10',
            'product_name' => 'Example Course',
            'canton_region' => null,
            'registration_timestamp' => null,
            'girls_only' => '0',
        ], $over);
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function installWpdb(array $existing): void {
        global $wpdb;
        $wpdb = new class($existing) {
            public $prefix = 'wp_';
            public $last_error = '';
            public $updates = [];
            private $row;

            public function __construct(array $row) {
                $this->row = $row;
            }

            public function prepare($q, ...$a) {
                unset($a);
                return $q;
            }

            public function get_row($q, $o = null) {
                unset($q, $o);
                return $this->row;
            }

            public function update($table, $data, $where, $f = null, $wf = null) {
                unset($table, $f, $wf);
                $this->updates[] = ['data' => $data, 'where' => $where];
                return 1;
            }
        };
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, string> $edits
     * @return array<string, string>
     */
    private function uiFormPost(array $existing, array $edits): array {
        $post = [
            'action' => 'intersoccer_update_roster_entry',
            'nonce' => 'x',
            'roster_id' => (string) $existing['id'],
        ];
        foreach (self::EDITABLE as $field) {
            $value = $existing[$field] ?? null;
            if ($field === 'registration_timestamp' && is_string($value) && strpos($value, ' ') !== false) {
                $value = str_replace(' ', 'T', $value);
            }
            $post[$field] = $value === null ? '' : (string) $value;
        }
        return array_merge($post, $edits);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, string> $edits
     * @return array<string, mixed>
     */
    private function save(array $existing, array $edits): array {
        $this->installWpdb($existing);
        $_POST = $this->uiFormPost($existing, $edits);
        ob_start();
        intersoccer_ajax_update_roster_entry();
        ob_end_clean();
        global $wpdb;
        $this->assertCount(1, $wpdb->updates, 'expected exactly one UPDATE from the full-form save');
        return $wpdb->updates[0]['data'];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $update
     * @return mixed
     */
    private function after(array $existing, array $update, string $field) {
        return array_key_exists($field, $update) ? $update[$field] : $existing[$field];
    }
}
