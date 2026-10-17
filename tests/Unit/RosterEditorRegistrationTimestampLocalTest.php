<?php
/**
 * datetime-local often posts YYYY-MM-DDTHH:MM without seconds; must not clear an existing timestamp.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterEditorRegistrationTimestampLocalTest extends TestCase {

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

    public function test_full_form_save_does_not_rewrite_timestamp_when_browser_omits_seconds(): void {
        $existing = [
            'id' => 700202,
            'player_name' => 'Qaone Qatest',
            'first_name' => 'Qaone',
            'last_name' => 'Qatest',
            'player_index' => '0',
            'age' => '8',
            'gender' => 'male',
            'venue' => 'QA Venue',
            'activity_type' => 'Course',
            'booking_type' => 'Full Term',
            'selected_days' => null,
            'day_presence' => null,
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
            'medical_conditions' => '',
            'dietary_needs' => '',
            'player_medical' => '',
            'player_dietary' => null,
            'parent_email' => 'parent@example.test',
            'parent_phone' => '+41000000000',
            'emergency_contact' => 'N/A',
            'emergency_phone' => 'N/A',
            'avs_number' => '756.0000.0000.00',
            'player_dob' => '2018-05-10',
            'product_name' => 'QA Course',
            'canton_region' => null,
            'registration_timestamp' => '2020-01-02 03:04:00',
            'girls_only' => '0',
        ];

        $updates = [];
        global $wpdb;
        $wpdb = new class($existing, $updates) {
            public $prefix = 'wp_';
            public $updates;
            private $row;
            public function __construct(array $row, array &$updates) {
                $this->row = $row;
                $this->updates = &$updates;
            }
            public function prepare($q, ...$a) {
                return $q;
            }
            public function get_row($q, $o = null) {
                return $this->row;
            }
            public function update($table, $data, $where, $f = null, $wf = null) {
                $this->updates[] = $data;
                return 1;
            }
        };

        $_POST = [
            'action' => 'intersoccer_update_roster_entry',
            'nonce' => 'x',
            'roster_id' => (string) $existing['id'],
            'registration_timestamp' => '2020-01-02T03:04',
            'shirt_size' => 'QA-M',
        ];

        ob_start();
        intersoccer_ajax_update_roster_entry();
        ob_end_clean();

        $this->assertCount(1, $updates);
        $this->assertArrayNotHasKey('registration_timestamp', $updates[0]);
        $this->assertSame('QA-M', $updates[0]['shirt_size'] ?? null);
    }
}
