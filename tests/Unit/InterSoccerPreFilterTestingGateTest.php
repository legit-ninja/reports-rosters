<?php
/**
 * RR #104: intersoccer_pre_* filters run only when INTERSOCCER_TESTING is true.
 *
 * Ungated behaviour is verified in child PHP processes that deliberately omit
 * INTERSOCCER_TESTING (see tests/scripts/pre-filter-gate-no-testing-*.php).
 * intersoccer_get_product_type_safe loads includes/utils.php in those children;
 * the other three require the real production files that define each gated function.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class InterSoccerPreFilterTestingGateTest extends TestCase {

    /**
     * @param string $scriptBasename File under tests/scripts/
     */
    private function assertNoTestingChildScriptPasses(string $scriptBasename): void {
        $root = dirname(__DIR__, 2);
        $script = $root . '/tests/scripts/' . $scriptBasename;
        $this->assertFileExists($script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, $script],
            $descriptors,
            $pipes,
            $root
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame(0, $exit, trim($stderr . "\n" . $stdout));
        $this->assertStringContainsString('OK', (string) $stdout);
    }

    public function test_pre_order_admin_is_wp_admin_request_ignored_without_testing_constant(): void {
        $this->assertNoTestingChildScriptPasses('pre-filter-gate-no-testing-order-admin-is-wp-admin.php');
    }

    public function test_pre_should_dedupe_ignored_without_testing_constant(): void {
        $this->assertNoTestingChildScriptPasses('pre-filter-gate-no-testing-should-dedupe.php');
    }

    public function test_pre_roster_move_post_status_ignored_without_testing_constant(): void {
        $this->assertNoTestingChildScriptPasses('pre-filter-gate-no-testing-roster-move-post-status.php');
    }

    public function test_pre_get_product_type_safe_ignored_without_testing_constant(): void {
        $this->assertNoTestingChildScriptPasses('pre-filter-gate-no-testing-get-product-type-safe.php');
    }

    public function test_pre_order_admin_is_wp_admin_request_short_circuits_under_testing(): void {
        require_once dirname(__DIR__, 2) . '/includes/order-admin-line-item-meta-display.php';

        $filter = static function ($pre) {
            return true;
        };
        add_filter('intersoccer_pre_order_admin_is_wp_admin_request', $filter, 10, 1);
        try {
            $this->assertTrue(intersoccer_order_admin_is_wp_admin_request());
        } finally {
            remove_filter('intersoccer_pre_order_admin_is_wp_admin_request', $filter, 10);
        }
    }

    public function test_pre_should_dedupe_short_circuits_under_testing(): void {
        require_once dirname(__DIR__, 2) . '/includes/order-admin-line-item-meta-display.php';

        $filter = static function ($pre) {
            return true;
        };
        add_filter('intersoccer_pre_should_dedupe_admin_order_item_formatted_meta', $filter, 10, 1);
        try {
            $this->assertTrue(intersoccer_should_dedupe_admin_order_item_formatted_meta());
        } finally {
            remove_filter('intersoccer_pre_should_dedupe_admin_order_item_formatted_meta', $filter, 10);
        }
    }

    public function test_pre_roster_move_post_status_short_circuits_under_testing(): void {
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';

        $filter = static function ($pre, $post_id) {
            return (int) $post_id === 4242 ? 'trash' : $pre;
        };
        add_filter('intersoccer_pre_roster_move_post_status', $filter, 1, 2);
        try {
            $this->assertSame('trash', intersoccer_roster_move_get_post_status(4242));
        } finally {
            remove_filter('intersoccer_pre_roster_move_post_status', $filter, 1);
        }
    }

    public function test_pre_get_product_type_safe_short_circuits_under_testing(): void {
        require_once dirname(__DIR__, 2) . '/includes/utils.php';

        global $intersoccer_test_product_type_safe_callback;
        $prior_callback = $intersoccer_test_product_type_safe_callback ?? null;
        $intersoccer_test_product_type_safe_callback = null;

        $filter = static function ($pre, $product_id, $variation_id) {
            return 'birthday';
        };
        add_filter('intersoccer_pre_get_product_type_safe', $filter, 1, 3);
        try {
            $this->assertSame('birthday', intersoccer_get_product_type_safe(1, null));
        } finally {
            remove_filter('intersoccer_pre_get_product_type_safe', $filter, 1);
            $intersoccer_test_product_type_safe_callback = $prior_callback;
        }
    }
}
