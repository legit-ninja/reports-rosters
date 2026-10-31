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

    /** @var string|null Exact SQL that should return false from wpdb::query */
    private $fail_query = null;

    protected function setUp(): void {
        parent::setUp();
        $this->sql = [];
        $this->fail_query = null;

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
                $sql = trim((string) $query);
                $fail = $this->test->getFailQuery();
                if ($fail !== null && $sql === $fail) {
                    $this->last_error = 'synthetic failure';
                    $this->test->recordSql($sql);
                    return false;
                }
                $this->test->recordSql($sql);
                return true;
            }
        };

        $this->database = new Database($logger);
    }

    public function getFailQuery(): ?string {
        return $this->fail_query;
    }

    public function setFailQuery(?string $sql): void {
        $this->fail_query = $sql;
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
        $this->assertSame(0, $this->database->get_transaction_level());
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
        $this->assertSame(0, $this->database->get_transaction_level());
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
        $this->assertSame(0, $this->database->get_transaction_level());
    }

    public function test_release_savepoint_failure_leaves_outer_transaction_open(): void {
        $this->setFailQuery('RELEASE SAVEPOINT intersoccer_sp_2');

        $result = $this->database->transaction(function () {
            $inner = $this->database->transaction(function () {
                return 'inner-ok';
            });
            $this->assertFalse($inner);
            $this->assertSame(1, $this->database->get_transaction_level());
            $this->database->get_wpdb()->query('OUTER WRITE STILL IN TX');
            return 'outer-done';
        });

        $this->assertSame('outer-done', $result);
        $this->assertSame(
            [
                'START TRANSACTION',
                'SAVEPOINT intersoccer_sp_2',
                'RELEASE SAVEPOINT intersoccer_sp_2',
                'ROLLBACK TO SAVEPOINT intersoccer_sp_2',
                'OUTER WRITE STILL IN TX',
                'COMMIT',
            ],
            $this->sql
        );
        $this->assertSame(0, $this->database->get_transaction_level());
    }

    public function test_rollback_to_savepoint_failure_forces_outer_rollback(): void {
        $this->setFailQuery('ROLLBACK TO SAVEPOINT intersoccer_sp_2');

        $result = $this->database->transaction(function () {
            $inner = $this->database->transaction(function () {
                return false;
            });
            $this->assertFalse($inner);
            $this->assertSame(1, $this->database->get_transaction_level());
            return 'outer-continues';
        });

        $this->assertFalse($result);
        $this->assertSame(
            [
                'START TRANSACTION',
                'SAVEPOINT intersoccer_sp_2',
                'ROLLBACK TO SAVEPOINT intersoccer_sp_2',
                'ROLLBACK',
            ],
            $this->sql
        );
        $this->assertSame(0, $this->database->get_transaction_level());

        $this->sql = [];
        $this->setFailQuery(null);
        $this->database->get_wpdb()->last_error = '';
        $second = $this->database->transaction(function () {
            return 'fresh';
        });
        $this->assertSame('fresh', $second);
        $this->assertSame(
            [
                'START TRANSACTION',
                'COMMIT',
            ],
            $this->sql
        );
        $this->assertSame(0, $this->database->get_transaction_level());
    }

    public function test_outer_transaction_failure_preserves_wpdb_last_error(): void {
        global $wpdb;
        $result = $this->database->transaction(function () use ($wpdb) {
            $wpdb->last_error = 'persistent outer failure';
            return false;
        });

        $this->assertFalse($result);
        $this->assertSame('persistent outer failure', $wpdb->last_error);
        $this->assertSame(0, $this->database->get_transaction_level());
    }

    public function test_outer_rollback_failure_resets_level_for_fresh_transaction(): void {
        $this->setFailQuery('ROLLBACK');

        $first = $this->database->transaction(function () {
            throw new \RuntimeException('abort outer');
        });
        $this->assertFalse($first);
        $this->assertSame(0, $this->database->get_transaction_level());
        $this->assertSame(
            [
                'START TRANSACTION',
                'ROLLBACK',
            ],
            $this->sql
        );

        $this->sql = [];
        $this->database->get_wpdb()->last_error = '';
        $second = $this->database->transaction(function () {
            return 'fresh';
        });
        $this->assertSame('fresh', $second);
        $this->assertSame(
            [
                'START TRANSACTION',
                'COMMIT',
            ],
            $this->sql
        );
        $this->assertSame(0, $this->database->get_transaction_level());
    }

    public function test_commit_failure_triggers_rollback_and_clears_level(): void {
        $this->setFailQuery('COMMIT');

        $result = $this->database->transaction(function () {
            return 'should-not-commit';
        });

        $this->assertFalse($result);
        $this->assertSame(
            [
                'START TRANSACTION',
                'COMMIT',
                'ROLLBACK',
            ],
            $this->sql
        );
        $this->assertSame(0, $this->database->get_transaction_level());
    }
}
