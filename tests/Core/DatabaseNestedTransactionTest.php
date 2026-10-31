<?php

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class DatabaseNestedTransactionTest extends TestCase {

    /** @var list<string> */
    private $sql = [];

    /** @var Database */
    private $database;

    protected function setUp(): void {
        parent::setUp();
        $this->sql = [];

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug', 'info', 'warning', 'error')->andReturn(null);

        global $wpdb;
        $wpdb = new class($this) {
            public $prefix = 'wp_';
            public $last_error = '';
            /** @var DatabaseNestedTransactionTest */
            private $test;
            public function __construct(DatabaseNestedTransactionTest $test) {
                $this->test = $test;
            }
            public function query($query) {
                $this->last_error = '';
                $this->test->recordSql((string) $query);
                return true;
            }
        };

        $this->database = new Database($logger);
    }

    public function recordSql(string $sql): void {
        $this->sql[] = trim($sql);
    }

    public function test_nested_commit_uses_savepoints_and_single_outer_commit(): void {
        $result = $this->database->transaction(function () {
            return $this->database->transaction(function () {
                return 'ok';
            });
        });

        $this->assertSame('ok', $result);
        $this->assertSame(
            [
                'START TRANSACTION',
                'SAVEPOINT intersoccer_sp_2',
                'RELEASE SAVEPOINT intersoccer_sp_2',
                'COMMIT',
            ],
            $this->sql
        );
    }

    public function test_nested_rollback_restores_level_without_outer_rollback(): void {
        $outer = $this->database->transaction(function () {
            $inner = $this->database->transaction(function () {
                return false;
            });
            $this->assertFalse($inner);
            return 'outer-continues';
        });

        $this->assertSame('outer-continues', $outer);
        $this->assertSame(
            [
                'START TRANSACTION',
                'SAVEPOINT intersoccer_sp_2',
                'ROLLBACK TO SAVEPOINT intersoccer_sp_2',
                'COMMIT',
            ],
            $this->sql
        );
    }

    public function test_nested_throw_rolls_back_to_savepoint_then_outer_can_commit(): void {
        $result = $this->database->transaction(function () {
            try {
                $this->database->transaction(function () {
                    throw new \RuntimeException('inner');
                });
            } catch (\Throwable $e) {
                // Simulates caller that swallows — level must still be 1 inside outer tx.
            }
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(
            [
                'START TRANSACTION',
                'SAVEPOINT intersoccer_sp_2',
                'ROLLBACK TO SAVEPOINT intersoccer_sp_2',
                'COMMIT',
            ],
            $this->sql
        );
    }
}
