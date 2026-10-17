<?php

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\RosterExportService;
use Mockery;
use PHPUnit\Framework\TestCase;

class RosterExportAllMedicalGatingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        unset(
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_current_user'],
            $GLOBALS['intersoccer_medical_access_test_filters']
        );
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
    }

    public function test_denied_user_export_all_row_has_no_sensitive_columns(): void
    {
        intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
        $row = [
            'first_name' => 'Amy',
            'player_dob' => '2012-04-04',
            'medical_conditions' => 'Peanuts',
            'player_medical' => 'Peanuts',
            'dietary_needs' => 'Gluten free',
            'venue' => 'Geneva',
        ];
        $stripped = intersoccer_rr_strip_sensitive_fields_from_export_row($row);
        $headers = array_keys($stripped);
        foreach (intersoccer_rr_sensitive_export_field_keys() as $key) {
            $this->assertNotContains($key, $headers, 'Sensitive key should be removed: ' . $key);
        }
        $this->assertContains('first_name', $headers);
        $this->assertContains('venue', $headers);
    }

    public function test_allowed_user_export_all_row_keeps_sensitive_columns(): void
    {
        intersoccer_test_set_user_caps(['manage_options' => true]);
        $row = [
            'first_name' => 'Amy',
            'player_dob' => '2012-04-04',
            'medical_conditions' => 'Peanuts',
            'venue' => 'Geneva',
        ];
        $kept = intersoccer_rr_strip_sensitive_fields_from_export_row($row);
        $this->assertSame($row, $kept);
        $headers = array_keys($kept);
        $this->assertContains('player_dob', $headers);
        $this->assertContains('medical_conditions', $headers);
    }

    public function test_get_export_rows_strips_sensitive_fields_for_denied_user(): void
    {
        intersoccer_test_set_user_caps(['coach' => true]);
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static function ($value) {
            return (string) $value;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return (string) $sql;
        });
        $wpdb->shouldReceive('get_results')->andReturn([
            [
                'first_name' => 'Amy',
                'player_dob' => '2012-04-04',
                'medical_conditions' => 'Secret',
                'venue' => 'Bern',
            ],
        ]);

        $database = Mockery::mock(Database::class);
        $database->shouldReceive('get_wpdb')->andReturn($wpdb);

        $service = new RosterExportService(new Logger(), $database);
        $rows = $service->getExportRows(['variation_ids' => [42]]);

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('player_dob', $rows[0]);
        $this->assertArrayNotHasKey('medical_conditions', $rows[0]);
        $this->assertSame('Amy', $rows[0]['first_name']);
    }
}
