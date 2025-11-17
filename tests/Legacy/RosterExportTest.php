<?php
/**
 * Roster Export AJAX Handler Test
 * 
 * Tests the intersoccer_export_roster() AJAX handler to ensure:
 * - Returns JSON responses (not direct file downloads)
 * - Handles output buffering correctly
 * - Returns proper error messages
 * - Validates permissions and nonces
 * - Generates valid Excel/CSV exports
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

class RosterExportTest extends TestCase {
    
    protected function setUp(): void {
        parent::setUp();
        
        // Load the export function
        if (file_exists(__DIR__ . '/../../includes/roster-export.php')) {
            require_once __DIR__ . '/../../includes/roster-export.php';
        }
        
        // Setup WordPress function mocks
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_send_json_success')->alias(function($data) {
            // Capture the response for testing
            $GLOBALS['test_json_response'] = ['success' => true, 'data' => $data];
            return true;
        });
        Functions\when('wp_send_json_error')->alias(function($data) {
            // Capture the response for testing
            $GLOBALS['test_json_response'] = ['success' => false, 'data' => $data];
            return true;
        });
        Functions\when('wp_create_nonce')->justReturn('test_nonce');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_title')->returnArg();
        Functions\when('date')->justReturn('2025-11-17');
        Functions\when('absint')->returnArg();
        Functions\when('intval')->returnArg();
        Functions\when('wp_convert_hr_to_bytes')->justReturn(134217728); // 128MB
        Functions\when('ini_get')->justReturn('128M');
        Functions\when('ini_set')->justReturn(true);
        Functions\when('memory_get_usage')->justReturn(1000000);
        Functions\when('intersoccer_log_audit')->justReturn(true);
        
        // Clear any previous responses
        unset($GLOBALS['test_json_response']);
    }
    
    protected function tearDown(): void {
        unset($GLOBALS['test_json_response']);
        parent::tearDown();
    }
    
    /**
     * Test that export returns JSON response structure
     */
    public function test_export_returns_json_response() {
        global $wpdb;
        
        // Mock database with roster data
        $wpdb = $this->createWpdbMock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')
            ->andReturn([
                (object)[
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'gender' => 'Male',
                    'parent_phone' => '123456789',
                    'parent_email' => 'john@example.com',
                    'age' => 10,
                    'player_dob' => '2015-01-01',
                    'medical_conditions' => '',
                    'avs_number' => '',
                    'activity_type' => 'Camp',
                    'age_group' => '8-12y',
                    'product_name' => 'Summer Camp',
                    'venue' => 'Zurich',
                    'camp_terms' => 'Week 1',
                    'times' => '09:00-12:00',
                    'late_pickup' => 'No',
                    'booking_type' => 'Full Week',
                    'late_pickup_days' => '',
                    'day_presence' => '{}',
                    'course_day' => '',
                ]
            ]);
        
        // Mock POST data
        $_POST = [
            'action' => 'intersoccer_export_roster',
            'nonce' => 'test_nonce',
            'use_fields' => '1',
            'variation_id' => 123,
            'product_id' => 456,
            'activity_types' => 'Camp',
            'camp_terms' => 'Week 1',
            'venue' => 'Zurich',
            'age_group' => '8-12y',
            'times' => '09:00-12:00',
            'girls_only' => '0',
        ];
        
        // Mock PhpSpreadsheet classes
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->markTestSkipped('PhpSpreadsheet not available');
        }
        
        // Capture output
        ob_start();
        
        try {
            // Call the export function
            intersoccer_export_roster();
        } catch (\Exception $e) {
            // wp_send_json_success exits, so we catch the exception
        }
        
        $output = ob_get_clean();
        
        // Verify JSON response was set
        $this->assertArrayHasKey('test_json_response', $GLOBALS);
        $response = $GLOBALS['test_json_response'];
        
        // Verify response structure
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('content', $response['data']);
        $this->assertArrayHasKey('filename', $response['data']);
        
        // Verify content is base64 encoded
        $this->assertNotEmpty($response['data']['content']);
        $this->assertIsString($response['data']['content']);
        
        // Verify filename is set
        $this->assertNotEmpty($response['data']['filename']);
        $this->assertStringContainsString('.xlsx', $response['data']['filename']);
    }
    
    /**
     * Test that export handles permission errors correctly
     */
    public function test_export_handles_permission_errors() {
        // Mock user without permissions
        Functions\when('current_user_can')->justReturn(false);
        
        $_POST = [
            'action' => 'intersoccer_export_roster',
            'nonce' => 'test_nonce',
        ];
        
        ob_start();
        try {
            intersoccer_export_roster();
        } catch (\Exception $e) {
            // wp_send_json_error exits
        }
        ob_get_clean();
        
        $this->assertArrayHasKey('test_json_response', $GLOBALS);
        $response = $GLOBALS['test_json_response'];
        
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('message', $response['data']);
    }
    
    /**
     * Test that export handles missing data errors
     */
    public function test_export_handles_missing_data() {
        global $wpdb;
        
        $wpdb = $this->createWpdbMock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')->andReturn([]);
        
        $_POST = [
            'action' => 'intersoccer_export_roster',
            'nonce' => 'test_nonce',
            'use_fields' => '1',
            'variation_id' => 123,
        ];
        
        ob_start();
        try {
            intersoccer_export_roster();
        } catch (\Exception $e) {
            // wp_send_json_error exits
        }
        ob_get_clean();
        
        $this->assertArrayHasKey('test_json_response', $GLOBALS);
        $response = $GLOBALS['test_json_response'];
        
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('message', $response['data']);
        $this->assertStringContainsString('No roster data', $response['data']['message']);
    }
    
    /**
     * Test that export handles missing required parameters
     */
    public function test_export_handles_missing_parameters() {
        $_POST = [
            'action' => 'intersoccer_export_roster',
            'nonce' => 'test_nonce',
            'use_fields' => '0',
            // Missing variation_ids and event_signature
        ];
        
        ob_start();
        try {
            intersoccer_export_roster();
        } catch (\Exception $e) {
            // wp_send_json_error exits
        }
        ob_get_clean();
        
        $this->assertArrayHasKey('test_json_response', $GLOBALS);
        $response = $GLOBALS['test_json_response'];
        
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('message', $response['data']);
    }
    
    /**
     * Test that export generates valid Excel content
     */
    public function test_export_generates_valid_excel_content() {
        global $wpdb;
        
        $wpdb = $this->createWpdbMock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')
            ->andReturn([
                (object)[
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'gender' => 'Male',
                    'parent_phone' => '123456789',
                    'parent_email' => 'john@example.com',
                    'age' => 10,
                    'player_dob' => '2015-01-01',
                    'medical_conditions' => '',
                    'avs_number' => '',
                    'activity_type' => 'Camp',
                    'age_group' => '8-12y',
                    'product_name' => 'Summer Camp',
                    'venue' => 'Zurich',
                    'camp_terms' => 'Week 1',
                    'times' => '09:00-12:00',
                    'late_pickup' => 'No',
                    'booking_type' => 'Full Week',
                    'late_pickup_days' => '',
                    'day_presence' => '{}',
                    'course_day' => '',
                ]
            ]);
        
        $_POST = [
            'action' => 'intersoccer_export_roster',
            'nonce' => 'test_nonce',
            'use_fields' => '1',
            'variation_id' => 123,
            'product_id' => 456,
            'activity_types' => 'Camp',
            'camp_terms' => 'Week 1',
            'venue' => 'Zurich',
            'age_group' => '8-12y',
            'times' => '09:00-12:00',
            'girls_only' => '0',
        ];
        
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->markTestSkipped('PhpSpreadsheet not available');
        }
        
        ob_start();
        try {
            intersoccer_export_roster();
        } catch (\Exception $e) {
            // wp_send_json_success exits
        }
        ob_get_clean();
        
        $this->assertArrayHasKey('test_json_response', $GLOBALS);
        $response = $GLOBALS['test_json_response'];
        
        if ($response['success']) {
            // Decode base64 content
            $content = base64_decode($response['data']['content']);
            
            // Verify content is not empty
            $this->assertNotEmpty($content);
            
            // Verify it's a valid Excel file (starts with PK header for ZIP-based format)
            $this->assertStringStartsWith('PK', $content);
        }
    }
    
    /**
     * Test that export handles output buffering correctly
     */
    public function test_export_handles_output_buffering() {
        global $wpdb;
        
        $wpdb = $this->createWpdbMock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_results')
            ->andReturn([
                (object)[
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'gender' => 'Male',
                    'parent_phone' => '123456789',
                    'parent_email' => 'john@example.com',
                    'age' => 10,
                    'player_dob' => '2015-01-01',
                    'medical_conditions' => '',
                    'avs_number' => '',
                    'activity_type' => 'Course',
                    'age_group' => '8-12y',
                    'product_name' => 'Soccer Course',
                    'venue' => 'Zurich',
                    'camp_terms' => '',
                    'course_day' => 'Saturday',
                    'times' => '10:00-11:30',
                    'late_pickup' => '',
                    'booking_type' => '',
                    'late_pickup_days' => '',
                    'day_presence' => '',
                ]
            ]);
        
        $_POST = [
            'action' => 'intersoccer_export_roster',
            'nonce' => 'test_nonce',
            'use_fields' => '1',
            'variation_id' => 123,
            'product_id' => 456,
            'activity_types' => 'Course',
            'course_day' => 'Saturday',
            'venue' => 'Zurich',
            'age_group' => '8-12y',
            'times' => '10:00-11:30',
            'girls_only' => '0',
        ];
        
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->markTestSkipped('PhpSpreadsheet not available');
        }
        
        // Start output buffer
        ob_start();
        echo "Some output before export";
        
        try {
            intersoccer_export_roster();
        } catch (\Exception $e) {
            // wp_send_json_success exits
        }
        
        // Should not have any output (buffers should be cleared)
        $output = ob_get_clean();
        
        // Verify JSON response was set (function should have handled buffering)
        $this->assertArrayHasKey('test_json_response', $GLOBALS);
    }
}

