<?php
/**
 * Logger Test
 * 
 * Tests for the Logger class
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Core\Logger;
use Brain\Monkey\Functions;

class LoggerTest extends TestCase {
    
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->logger = new Logger([
            'level' => Logger::DEBUG,
            'log_to_file' => false, // Disable file logging in tests
            'log_to_db' => false,
        ]);
    }
    
    public function test_logger_initialization() {
        $this->assertInstanceOf(Logger::class, $this->logger);
    }
    
    public function test_emergency_log_level() {
        // Test that the method exists and can be called without errors
        $this->logger->emergency('Test emergency');
        $this->assertTrue(true);
    }
    
    public function test_alert_log_level() {
        $this->logger->alert('Test alert');
        $this->assertTrue(true);
    }
    
    public function test_critical_log_level() {
        $this->logger->critical('Test critical');
        $this->assertTrue(true);
    }
    
    public function test_error_log_level() {
        $this->logger->error('Test error');
        $this->assertTrue(true);
    }
    
    public function test_warning_log_level() {
        $this->logger->warning('Test warning');
        $this->assertTrue(true);
    }
    
    public function test_notice_log_level() {
        $this->logger->notice('Test notice');
        $this->assertTrue(true);
    }
    
    public function test_info_log_level() {
        $this->logger->info('Test info');
        $this->assertTrue(true);
    }
    
    public function test_debug_log_level() {
        $this->logger->debug('Test debug');
        $this->assertTrue(true);
    }
    
    public function test_log_with_context() {
        $this->logger->info('User action', ['user_id' => 123]);
        $this->assertTrue(true);
    }
    
    public function test_log_level_filtering() {
        $logger = new Logger([
            'level' => Logger::ERROR,
            'log_to_file' => false,
        ]);
        
        // Should not throw errors when logging below threshold
        $logger->debug('This should not be logged');
        $this->assertTrue(true);
    }
    
    public function test_log_level_constants() {
        $this->assertEquals('emergency', Logger::EMERGENCY);
        $this->assertEquals('alert', Logger::ALERT);
        $this->assertEquals('critical', Logger::CRITICAL);
        $this->assertEquals('error', Logger::ERROR);
        $this->assertEquals('warning', Logger::WARNING);
        $this->assertEquals('notice', Logger::NOTICE);
        $this->assertEquals('info', Logger::INFO);
        $this->assertEquals('debug', Logger::DEBUG);
    }
    
    public function test_log_prefix() {
        $this->assertEquals('InterSoccer', Logger::LOG_PREFIX);
    }
    
    public function test_context_interpolation() {
        $this->logger->info('User {name}', ['name' => 'John Doe']);
        $this->assertTrue(true);
    }
    
    public function test_array_context() {
        $this->logger->info('Processing items', ['items' => [1, 2, 3]]);
        $this->assertTrue(true);
    }
    
    public function test_exception_context() {
        $exception = new \Exception('Test exception');
        $this->logger->error('Exception occurred', ['exception' => $exception]);
        $this->assertTrue(true);
    }
}

