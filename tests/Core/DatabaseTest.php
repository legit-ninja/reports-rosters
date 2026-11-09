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
        $this->wpdb->last_error = '';
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
        $created = [];
        $this->wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$created) {
            if (is_string($sql) && preg_match('/^CREATE TABLE (\S+)/', $sql, $matches)) {
                $created[$matches[1]] = $sql;
                return true;
            }
            return true;
        });
        $this->wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use (&$created) {
            if (preg_match("/SHOW TABLES LIKE '([^']+)'/", (string) $sql, $matches)) {
                return isset($created[$matches[1]]) ? $matches[1] : null;
            }
            return null;
        });

        $this->assertTrue($this->database->create_tables());
        $this->assertArrayHasKey('wp_intersoccer_rosters', $created);
        $this->assertStringContainsString('order_id', $created['wp_intersoccer_rosters']);
    }

    public function test_get_table_name() {
        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->with("SHOW TABLES LIKE 'wp_intersoccer_rosters'")
            ->andReturn('wp_intersoccer_rosters');

        $this->assertTrue($this->database->table_exists('intersoccer_rosters'));
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
        $result = $this->database->commit_transaction();
        
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
        $result = $this->database->rollback_transaction();
        
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
        
        $result = $this->database->transaction(function() {
            throw new \Exception('Test exception');
        });

        $this->assertFalse($result);
    }
    
    public function test_nested_transactions() {
        $this->wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(true);
        
        // A nested transaction() uses a savepoint inside the outer transaction (#167).
        $this->wpdb->shouldReceive('query')
            ->with('SAVEPOINT intersoccer_sp_2')
            ->once()
            ->andReturn(true);

        $this->wpdb->shouldReceive('query')
            ->with('RELEASE SAVEPOINT intersoccer_sp_2')
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
        $this->wpdb->shouldReceive('prepare')->andReturn('SELECT 0');
        $this->wpdb->shouldReceive('get_var')->andReturn('0');
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with('wp_intersoccer_rosters', Mockery::on(function ($data) {
                return ($data['order_id'] ?? null) === 10
                    && ($data['order_item_id'] ?? null) === 20
                    && ($data['product_id'] ?? null) === 30
                    && ($data['customer_id'] ?? null) === 40;
            }))
            ->andReturn(1);
        $this->wpdb->insert_id = 123;

        $result = $this->database->insert_roster_entry([
            'order_id' => 10,
            'order_item_id' => 20,
            'product_id' => 30,
            'customer_id' => 40,
        ]);

        $this->assertSame(123, $result);
    }
    
    public function test_update_query() {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_intersoccer_rosters',
                Mockery::on(function ($data) {
                    return ($data['first_name'] ?? null) === 'Ada';
                }),
                ['id' => 1]
            )
            ->andReturn(1);

        $this->assertTrue($this->database->update_roster_entry([
            'id' => 1,
            'order_id' => 10,
            'order_item_id' => 20,
            'product_id' => 30,
            'customer_id' => 40,
            'first_name' => 'Ada',
        ]));
    }
    
    public function test_delete_query() {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_intersoccer_rosters', ['id' => 1])
            ->andReturn(1);

        $this->assertSame(1, $this->database->delete_roster_entries(['id' => 1]));
    }
    
    public function test_select_query() {
        $seen = '';
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturnUsing(function ($sql) use (&$seen) {
                $seen = (string) $sql;
                return [
                    ['id' => 1, 'first_name' => 'Ada'],
                ];
            });

        $results = $this->database->get_roster_entries();

        $this->assertStringContainsString('SELECT * FROM wp_intersoccer_rosters', $seen);
        $this->assertCount(1, $results);
        $this->assertSame('Ada', $results[0]['first_name']);
    }
    
    public function test_get_schema_version() {
        $this->wpdb->shouldReceive('get_var')->andReturn(null);

        $validation = $this->database->validate_table_schema('intersoccer_rosters');

        $this->assertFalse($validation['exists']);
        $this->assertSame([], $validation['columns']);
    }
    
    public function test_update_schema_version() {
        $this->wpdb->shouldReceive('get_var')->andReturn('wp_intersoccer_rosters');
        $this->wpdb->shouldReceive('get_results')->andReturn([]);

        $validation = $this->database->validate_table_schema('intersoccer_rosters');

        $this->assertTrue($validation['exists']);
        $this->assertContains('order_id', $validation['missing_columns']);
        $this->assertContains('customer_id', $validation['missing_columns']);
    }
}

