<?php
/**
 * Database Test
 * 
 * Tests for the Database class
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use Brain\Monkey\Functions;
use Mockery;

class DatabaseTest extends TestCase {
    
    private $database;
    private $logger;
    private $wpdb;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->logger = Mockery::mock(Logger::class);
        $this->logger->shouldReceive('debug')->andReturn(null);
        $this->logger->shouldReceive('info')->andReturn(null);
        $this->logger->shouldReceive('warning')->andReturn(null);
        $this->logger->shouldReceive('error')->andReturn(null);
        
        // Create wpdb mock
        global $wpdb;
        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->shouldReceive('get_charset_collate')->andReturn('');
        $wpdb = $this->wpdb;
        
        Functions\when('get_bloginfo')->justReturn('6.0');
        
        $this->database = new Database($this->logger);
    }
    
    public function test_database_initialization() {
        $this->assertInstanceOf(Database::class, $this->database);
        $this->logger->shouldHaveReceived('debug')->with('Database class initialized');
    }
    
    public function test_table_exists_check() {
        $this->wpdb->shouldReceive('get_var')
            ->with(Mockery::pattern('/SHOW TABLES/'))
            ->andReturn('wp_intersoccer_rosters');
        
        $exists = $this->database->table_exists('intersoccer_rosters');
        
        $this->assertTrue($exists);
    }
    
    public function test_table_does_not_exist() {
        $this->wpdb->shouldReceive('get_var')
            ->with(Mockery::pattern('/SHOW TABLES/'))
            ->andReturn(null);
        
        $exists = $this->database->table_exists('non_existent_table');
        
        $this->assertFalse($exists);
    }
    
    public function test_create_table() {
        Functions\expect('dbDelta')->once()->andReturn(['Created table']);
        
        $result = $this->database->create_table('test_table', [
            'id' => 'int(11) NOT NULL AUTO_INCREMENT',
            'name' => 'varchar(100)',
        ], 'id');
        
        $this->assertTrue($result);
    }
    
    public function test_get_table_name() {
        $table_name = $this->database->get_table_name('intersoccer_rosters');
        $this->assertEquals('wp_intersoccer_rosters', $table_name);
    }
    
    public function test_transaction_start() {
        $this->wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(true);
        
        $result = $this->database->begin_transaction();
        
        $this->assertTrue($result);
    }
    
    public function test_transaction_commit() {
        $this->wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(true);
        
        $this->wpdb->shouldReceive('query')
            ->with('COMMIT')
            ->once()
            ->andReturn(true);
        
        $this->database->begin_transaction();
        $result = $this->database->commit();
        
        $this->assertTrue($result);
    }
    
    public function test_transaction_rollback() {
        $this->wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(true);
        
        $this->wpdb->shouldReceive('query')
            ->with('ROLLBACK')
            ->once()
            ->andReturn(true);
        
        $this->database->begin_transaction();
        $result = $this->database->rollback();
        
        $this->assertTrue($result);
    }
    
    public function test_transaction_callback() {
        $this->wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(true);
        
        $this->wpdb->shouldReceive('query')
            ->with('COMMIT')
            ->once()
            ->andReturn(true);
        
        $result = $this->database->transaction(function() {
            return 'success';
        });
        
        $this->assertEquals('success', $result);
    }
    
    public function test_transaction_callback_with_exception() {
        $this->wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(true);
        
        $this->wpdb->shouldReceive('query')
            ->with('ROLLBACK')
            ->once()
            ->andReturn(true);
        
        $this->expectException(\Exception::class);
        
        $this->database->transaction(function() {
            throw new \Exception('Test exception');
        });
    }
    
    public function test_nested_transactions() {
        $this->wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(true);
        
        $this->wpdb->shouldReceive('query')
            ->with('COMMIT')
            ->once()
            ->andReturn(true);
        
        $result = $this->database->transaction(function() {
            return $this->database->transaction(function() {
                return 'nested success';
            });
        });
        
        $this->assertEquals('nested success', $result);
    }
    
    public function test_insert_query() {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with('wp_test_table', ['name' => 'Test'], ['%s'])
            ->andReturn(1);
        
        $this->wpdb->insert_id = 123;
        
        $result = $this->database->insert('test_table', ['name' => 'Test']);
        
        $this->assertEquals(123, $result);
    }
    
    public function test_update_query() {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with('wp_test_table', ['name' => 'Updated'], ['id' => 1], ['%s'], ['%d'])
            ->andReturn(1);
        
        $result = $this->database->update('test_table', ['name' => 'Updated'], ['id' => 1]);
        
        $this->assertEquals(1, $result);
    }
    
    public function test_delete_query() {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_test_table', ['id' => 1], ['%d'])
            ->andReturn(1);
        
        $result = $this->database->delete('test_table', ['id' => 1]);
        
        $this->assertEquals(1, $result);
    }
    
    public function test_select_query() {
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->with(Mockery::pattern('/SELECT.*FROM wp_test_table/'))
            ->andReturn([
                (object)['id' => 1, 'name' => 'Test']
            ]);
        
        $results = $this->database->select('test_table');
        
        $this->assertIsArray($results);
        $this->assertCount(1, $results);
    }
    
    public function test_get_schema_version() {
        Functions\expect('get_option')
            ->once()
            ->with('intersoccer_db_version', '1.0.0')
            ->andReturn('2.0.0');
        
        $version = $this->database->get_schema_version();
        
        $this->assertEquals('2.0.0', $version);
    }
    
    public function test_update_schema_version() {
        Functions\expect('update_option')
            ->once()
            ->with('intersoccer_db_version', '2.1.0')
            ->andReturn(true);
        
        $result = $this->database->update_schema_version('2.1.0');
        
        $this->assertTrue($result);
    }
}

