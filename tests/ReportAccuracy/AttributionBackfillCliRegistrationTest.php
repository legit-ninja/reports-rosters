<?php
/**
 * Regression: WP-CLI attribution-backfill subcommand registration and synopsis.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class AttributionBackfillCliRegistrationTest extends TestCase {

    /** @var array<string,mixed> */
    private static $registered_commands = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!defined('WP_CLI')) {
            define('WP_CLI', true);
        }
        if (!class_exists('WP_CLI')) {
            eval(<<<'PHP'
class WP_CLI {
    public static $add_command_callback;
    public static function log($msg) {
        if (isset($GLOBALS['intersoccer_test_wp_cli_log']) && is_array($GLOBALS['intersoccer_test_wp_cli_log'])) {
            $GLOBALS['intersoccer_test_wp_cli_log'][] = $msg;
        }
    }
    public static function success($msg) {}
    public static function error($msg) { throw new \RuntimeException((string) $msg); }
    public static function confirm($msg) {}
    public static function add_command($name, $callable) {
        if (is_callable(self::$add_command_callback)) {
            (self::$add_command_callback)($name, $callable);
        }
    }
}
PHP
            );
        }

        \WP_CLI::$add_command_callback = static function ($name, $callable) {
            self::$registered_commands[$name] = $callable;
        };

        require_once dirname(__DIR__, 2) . '/includes/wp-cli/roster-cli-commands.php';
    }

    public function test_attribution_backfill_docblock_declares_subcommand_alias_and_flags(): void {
        $doc = (string) (new \ReflectionMethod(\Intersoccer_Rr_Wp_Cli::class, 'attribution_backfill'))->getDocComment();
        $this->assertStringContainsString('@subcommand attribution-backfill', $doc);
        $this->assertStringContainsString('@alias attribution_backfill', $doc);
        $this->assertStringContainsString('[--yes]', $doc);
        $this->assertStringContainsString('[--apply]', $doc);
        $this->assertStringContainsString('[--from=<date>]', $doc);
        $this->assertStringContainsString('[--to=<date>]', $doc);
        $this->assertStringContainsString('[--retry-skipped]', $doc);
        $this->assertStringContainsString('[--list-orders]', $doc);
    }

    public function test_attribution_backfill_registered_on_intersoccer_rr_command(): void {
        $this->assertArrayHasKey('intersoccer-rr', self::$registered_commands);
        $this->assertSame(\Intersoccer_Rr_Wp_Cli::class, self::$registered_commands['intersoccer-rr']);
    }
}
