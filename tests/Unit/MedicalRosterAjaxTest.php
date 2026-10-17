<?php

use PHPUnit\Framework\TestCase;

class MedicalRosterAjaxTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_POST,
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_current_user'],
            $GLOBALS['intersoccer_medical_access_test_filters'],
            $GLOBALS['intersoccer_test_json_error_throws'],
            $GLOBALS['intersoccer_test_wpdb_row']
        );
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $GLOBALS['intersoccer_medical_access_test_filters'] = [
            static function ($allowed, $user_id) {
                return false;
            },
        ];

        require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
        if (!function_exists('intersoccer_ajax_update_roster_entry')) {
            require_once dirname(__DIR__, 2) . '/includes/roster-editor-ajax.php';
        }
    }

    public function test_medical_field_update_rejected_when_medical_access_denied(): void
    {
        $GLOBALS['intersoccer_test_wpdb_row'] = [
            'id' => 7,
            'medical_conditions' => 'Keep',
        ];
        global $wpdb;
        $wpdb = new class extends InterSoccerTestWpdb {
            public function get_row($query = null, $output = OBJECT, $y = 0) {
                return $GLOBALS['intersoccer_test_wpdb_row'];
            }
        };

        $_POST = [
            'nonce' => 'test',
            'roster_id' => '7',
            'field' => 'medical_conditions',
            'value' => 'Hacked',
        ];

        $GLOBALS['intersoccer_test_json_error_throws'] = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This field cannot be edited');

        intersoccer_ajax_update_roster_entry();
    }

    public function test_dob_field_update_rejected_when_medical_access_denied(): void
    {
        $GLOBALS['intersoccer_test_wpdb_row'] = [
            'id' => 7,
            'player_dob' => '2012-03-04',
        ];
        global $wpdb;
        $wpdb = new class extends InterSoccerTestWpdb {
            public function get_row($query = null, $output = OBJECT, $y = 0) {
                return $GLOBALS['intersoccer_test_wpdb_row'];
            }
        };

        $_POST = [
            'nonce' => 'test',
            'roster_id' => '7',
            'field' => 'player_dob',
            'value' => '1970-01-01',
        ];

        $GLOBALS['intersoccer_test_json_error_throws'] = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This field cannot be edited');

        intersoccer_ajax_update_roster_entry();
    }

    public function test_strip_medical_on_full_update_when_medical_access_denied(): void
    {
        $GLOBALS['intersoccer_test_wpdb_row'] = [
            'id' => 7,
            'medical_conditions' => 'Keep',
            'parent_phone' => '111',
            'player_dob' => '2012-03-04',
        ];
        global $wpdb;
        $wpdb = new class extends InterSoccerTestWpdb {
            public $last_update = null;

            public function get_row($query = null, $output = OBJECT, $y = 0) {
                return $GLOBALS['intersoccer_test_wpdb_row'];
            }

            public function update($table, $data, $where, $format = null, $where_format = null) {
                $this->last_update = $data;
                return 1;
            }
        };

        $_POST = [
            'nonce' => 'test',
            'roster_id' => '7',
            'parent_phone' => '222',
            'medical_conditions' => 'Hacked',
            'player_dob' => '1970-01-01',
        ];

        ob_start();
        intersoccer_ajax_update_roster_entry();
        ob_end_clean();

        $this->assertSame(['parent_phone' => '222'], $wpdb->last_update);
        $this->assertArrayNotHasKey('medical_conditions', $wpdb->last_update ?? []);
        $this->assertArrayNotHasKey('player_dob', $wpdb->last_update ?? []);
    }

    public function test_get_roster_entry_redacts_medical_and_dob(): void
    {
        $GLOBALS['intersoccer_test_wpdb_row'] = [
            'id' => 9,
            'player_name' => 'Kid',
            'medical_conditions' => 'Secret',
            'player_dob' => '2011-05-05',
        ];
        global $wpdb;
        $wpdb = new class extends InterSoccerTestWpdb {
            public function get_row($query = null, $output = OBJECT, $y = 0) {
                return $GLOBALS['intersoccer_test_wpdb_row'];
            }
        };

        $_POST = ['nonce' => 'test', 'roster_id' => '9'];

        ob_start();
        intersoccer_ajax_get_roster_entry();
        $json = ob_get_clean();
        $payload = json_decode($json, true);
        $this->assertTrue($payload['success']);
        $this->assertSame('Restricted', $payload['data']['roster']['medical_conditions']);
        $this->assertSame('Restricted', $payload['data']['roster']['player_dob']);
    }

    public function test_get_roster_entry_returns_real_medical_and_dob_for_allowed_user(): void
    {
        unset($GLOBALS['intersoccer_medical_access_test_filters']);
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $GLOBALS['intersoccer_test_wpdb_row'] = [
            'id' => 10,
            'player_name' => 'Kid',
            'medical_conditions' => 'Asthma',
            'player_dob' => '2011-05-05',
        ];
        global $wpdb;
        $wpdb = new class extends InterSoccerTestWpdb {
            public function get_row($query = null, $output = OBJECT, $y = 0) {
                return $GLOBALS['intersoccer_test_wpdb_row'];
            }
        };

        $_POST = ['nonce' => 'test', 'roster_id' => '10'];

        ob_start();
        intersoccer_ajax_get_roster_entry();
        $json = ob_get_clean();
        $payload = json_decode($json, true);
        $this->assertTrue($payload['success']);
        $this->assertSame('Asthma', $payload['data']['roster']['medical_conditions']);
        $this->assertSame('2011-05-05', $payload['data']['roster']['player_dob']);
    }

    public function test_camps_row_redaction_matches_ajax_payload_shape(): void
    {
        unset($GLOBALS['intersoccer_medical_access_test_filters']);
        intersoccer_test_set_user_caps(['coach' => true]);
        $row = [
            'first_name' => 'A',
            'medical_conditions' => 'Secret',
            'player_dob' => '2012-02-02',
        ];
        $redacted = intersoccer_rr_redact_medical_in_roster_row($row);
        $this->assertSame('Restricted', $redacted['medical_conditions']);
        $this->assertSame('Restricted', $redacted['player_dob']);
        $this->assertSame('A', $redacted['first_name']);
    }
}
