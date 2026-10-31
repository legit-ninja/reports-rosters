<?php
/**
 * WP-CLI registration for closed roster product_name backfill.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class ClosedRosterProductNameBackfillCliTest extends TestCase {

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if (!defined('WP_CLI')) {
            define('WP_CLI', true);
        }
        if (!class_exists('WP_CLI')) {
            eval(<<<'PHP'
class WP_CLI {
    public static function log($msg) {}
    public static function success($msg) {}
    public static function error($msg) { throw new \RuntimeException((string) $msg); }
    public static function add_command($name, $callable) {}
}
PHP
            );
        }
        require_once dirname(__DIR__, 2) . '/includes/wp-cli/roster-cli-commands.php';
    }

    public function test_backfill_subcommand_docblock_declares_flags(): void {
        if (!class_exists('Intersoccer_Rosters_Wp_Cli')) {
            $this->markTestSkipped('CLI class not loaded');
        }
        $doc = (string) (new \ReflectionMethod(\Intersoccer_Rosters_Wp_Cli::class, 'closed_line_product_name_backfill'))->getDocComment();
        $this->assertStringContainsString('@subcommand closed-line-product-name-backfill', $doc);
        $this->assertStringContainsString('[--apply]', $doc);
        $this->assertStringContainsString('[--id-cursor', $doc);
        $this->assertStringContainsString('[--chunk', $doc);
    }
}
