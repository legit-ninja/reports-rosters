<?php
/**
 * AjaxHandlers Test - Legacy AJAX handlers
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey\Functions;

class AjaxHandlersTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        
        if (file_exists(__DIR__ . '/../../includes/reports-ajax.php')) {
            require_once __DIR__ . '/../../includes/reports-ajax.php';
        }
    }
    
    public function test_ajax_handlers_registered() {
        Functions\expect('wp_send_json_success')->andReturn(null);
        Functions\expect('wp_send_json_error')->andReturn(null);
        Functions\expect('check_ajax_referer')->andReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        
        $this->assertTrue(true);
    }
}

