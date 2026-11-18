<?php
/**
 * Compressed cache values must stay valid option text, and a cache write
 * must not abort an enclosing database transaction.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use ArrayObject;
use Brain\Monkey\Functions;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\CacheManager;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class CacheManagerBinarySafeTest extends TestCase {

    private function logger() {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug')->andReturn(null);
        $logger->shouldReceive('info')->andReturn(null);
        $logger->shouldReceive('warning')->andReturn(null);
        $logger->shouldReceive('error')->andReturn(null);

        return $logger;
    }

    private function compressedCache(): CacheManager {
        return new CacheManager($this->logger(), [
            'backend' => 'transients',
            'compression' => true,
            'cleanup_probability' => 0,
        ]);
    }

    public function test_compressed_round_trip_stores_utf8_text(): void {
        $holder = new \stdClass();
        $holder->stored = null;
        $holder->store = [];

        Functions\when('set_transient')->alias(function ($key, $value) use ($holder) {
            $holder->stored = $value;
            $holder->store[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(function ($key) use ($holder) {
            return array_key_exists($key, $holder->store) ? $holder->store[$key] : false;
        });

        $payload = [
            'course' => 'Frühlings-/Sommerkurse',
            'winter' => "Cours d'hiver",
            'bucket' => new ArrayObject([
                'label' => 'Frühlings-/Sommerkurse',
                'rows' => ["Cours d'hiver"],
            ]),
        ];

        $cache = $this->compressedCache();
        $this->assertTrue($cache->set('roster-query', $payload, 'roster_data', 60));
        $this->assertEquals($payload, $cache->get('roster-query', 'roster_data'));

        $this->assertIsString($holder->stored);
        $this->assertStringStartsWith('rrz1:', $holder->stored);
        $this->assertTrue(mb_check_encoding($holder->stored, 'UTF-8'));
        $this->assertSame(1, preg_match('/^rrz1:[A-Za-z0-9+\/=]+$/', $holder->stored));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function unreadable_cache_payloads(): array {
        return [
            'legacy gzip' => [gzcompress(serialize(['label' => "Cours d'hiver"]), 6)],
            'random binary' => ["\x00\xFF\xFE\x80cache"],
            'plain string' => ['not-a-cache-payload'],
            'prefixed junk' => ['rrz1:!!!!'],
            'false' => [false],
        ];
    }

    /**
     * @dataProvider unreadable_cache_payloads
     */
    public function test_unreadable_stored_value_is_a_cache_miss($payload): void {
        Functions\when('get_transient')->alias(function () use ($payload) {
            return $payload;
        });

        $cache = $this->compressedCache();
        $warnings = [];
        set_error_handler(static function ($severity, $message) use (&$warnings) {
            $warnings[] = [$severity, $message];
            return true;
        });
        try {
            $result = $cache->get('roster-query', 'roster_data', 'cache-miss');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame('cache-miss', $result);
    }

    /**
     * Regression: a transient write of compressed bytes used to leave
     * wpdb::last_error set, and Database::transaction() rolled the rebuild back.
     */
    public function test_transaction_commits_when_cache_set_is_last_action(): void {
        $wpdb = $this->utf8RejectingWpdb();
        $cache = $this->compressedCache();
        $database = new Database($this->logger());

        Functions\when('set_transient')->alias(function ($key, $value) use ($wpdb) {
            $sql = "INSERT INTO {$wpdb->options} (option_name, option_value) VALUES ('_transient_{$key}', '{$value}')";
            return $wpdb->query($sql) !== false;
        });

        $expected = ['processed' => 2, 'errors' => 0];
        $result = $database->transaction(function () use ($cache, $expected) {
            $cache->set('roster-query', [
                'winter' => "Cours d'hiver",
            ], 'roster_data', 30);
            return $expected;
        });

        $this->assertSame($expected, $result);
        $this->assertSame(0, $wpdb->rejected);
        $this->assertSame('', $wpdb->last_error);
        $this->assertContains('COMMIT', $wpdb->statements);
        $this->assertNotContains('ROLLBACK', $wpdb->statements);
        $this->assertNotFalse($this->cacheInsert($wpdb));
        $this->assertStringContainsString('rrz1:', $this->cacheInsert($wpdb));
    }

    /**
     * A failed cache write must not leave last_error set for the enclosing transaction.
     */
    public function test_failed_cache_write_does_not_poison_transaction(): void {
        $wpdb = $this->utf8RejectingWpdb();
        $wpdb->fail_inserts = true;
        $cache = $this->compressedCache();
        $database = new Database($this->logger());

        Functions\when('set_transient')->alias(function ($key, $value) use ($wpdb) {
            $sql = "INSERT INTO {$wpdb->options} (option_name, option_value) VALUES ('_transient_{$key}', '{$value}')";
            return $wpdb->query($sql) !== false;
        });

        $expected = ['processed' => 1, 'errors' => 0];
        $result = $database->transaction(function () use ($cache, $expected) {
            $cache->set('roster-query', ['winter' => "Cours d'hiver"], 'roster_data', 30);
            return $expected;
        });

        $this->assertSame($expected, $result);
        $this->assertSame('', $wpdb->last_error);
        $this->assertContains('COMMIT', $wpdb->statements);
        $this->assertNotContains('ROLLBACK', $wpdb->statements);
    }

    private function cacheInsert($wpdb): string {
        foreach ($wpdb->statements as $sql) {
            if (is_string($sql) && strpos($sql, 'INSERT INTO') !== false) {
                return $sql;
            }
        }

        return '';
    }

    private function utf8RejectingWpdb() {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $options = 'wp_options';
            public $last_error = '';
            public $rejected = 0;
            public $fail_inserts = false;
            public $statements = [];

            public function query($sql) {
                $this->statements[] = $sql;
                $invalid = !is_string($sql) || !mb_check_encoding($sql, 'UTF-8');
                $insert = is_string($sql) && strpos($sql, 'INSERT INTO') !== false;
                if ($invalid || ($this->fail_inserts && $insert)) {
                    $this->rejected++;
                    $this->last_error = 'Could not perform query because it contains invalid data.';
                    return false;
                }

                return true;
            }
        };

        return $wpdb;
    }
}
