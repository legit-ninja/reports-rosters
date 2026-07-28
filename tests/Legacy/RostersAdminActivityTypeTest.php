<?php
/**
 * Unified Rosters admin helpers (Activity Type + legacy redirects).
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RostersAdminActivityTypeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $this->ensureWpHelpers();

        $rosters_file = __DIR__ . '/../../includes/rosters.php';
        if (file_exists($rosters_file)) {
            require_once $rosters_file;
        }
    }

    private function ensureWpHelpers(): void {
        if (!function_exists('wp_unslash')) {
            eval('function wp_unslash($value) { return $value; }');
        }
        if (!function_exists('sanitize_key')) {
            eval('function sanitize_key($key) { return strtolower(preg_replace("/[^a-z0-9_\\-]/", "", (string) $key)); }');
        }
        if (!function_exists('sanitize_text_field')) {
            eval('function sanitize_text_field($str) { return trim(strip_tags((string) $str)); }');
        }
        if (!function_exists('__')) {
            eval('function __($text, $domain = null) { return $text; }');
        }
        if (!function_exists('admin_url')) {
            eval('function admin_url($path = "") { return "https://example.test/wp-admin/" . ltrim((string) $path, "/"); }');
        }
        if (!function_exists('add_query_arg')) {
            eval('function add_query_arg($args, $url = "") {
                if (is_array($args)) {
                    $query = http_build_query($args);
                    $sep = (strpos((string) $url, "?") === false) ? "?" : "&";
                    return (string) $url . $sep . $query;
                }
                return (string) $url;
            }');
        }
    }

    public function test_activity_type_parser_defaults_to_camps(): void {
        if (!function_exists('intersoccer_rosters_admin_activity_type_from_request')) {
            $this->markTestSkipped('activity type helper not loaded');
        }

        unset($_GET['activity_type'], $_REQUEST['activity_type']);
        $this->assertSame('camps', intersoccer_rosters_admin_activity_type_from_request());
    }

    public function test_activity_type_parser_accepts_allowed_values(): void {
        if (!function_exists('intersoccer_rosters_admin_activity_type_from_request')) {
            $this->markTestSkipped('activity type helper not loaded');
        }

        foreach (['camps', 'courses', 'tournaments'] as $type) {
            $_GET['activity_type'] = $type;
            unset($_GET['girls_only_mode'], $_REQUEST['girls_only_mode']);
            $this->assertSame($type, intersoccer_rosters_admin_activity_type_from_request());
        }

        $_GET['activity_type'] = 'birthday';
        $this->assertSame('camps', intersoccer_rosters_admin_activity_type_from_request());
        unset($_GET['activity_type']);
    }

    public function test_obsolete_girls_only_activity_type_remaps_to_camps_with_yes_filter(): void {
        if (!function_exists('intersoccer_rosters_admin_activity_type_from_request')) {
            $this->markTestSkipped('activity type helper not loaded');
        }

        unset($_GET['girls_only_mode'], $_REQUEST['girls_only_mode']);
        $_GET['activity_type'] = 'girls_only';
        $this->assertSame('camps', intersoccer_rosters_admin_activity_type_from_request());
        $this->assertSame('yes', $_GET['girls_only_mode']);
        unset($_GET['activity_type'], $_GET['girls_only_mode'], $_REQUEST['girls_only_mode']);
    }

    public function test_legacy_page_activity_type_map(): void {
        if (!function_exists('intersoccer_rosters_legacy_page_activity_type_map')) {
            $this->markTestSkipped('legacy map helper not loaded');
        }

        $map = intersoccer_rosters_legacy_page_activity_type_map();
        $this->assertSame('camps', $map['intersoccer-camps']);
        $this->assertSame('courses', $map['intersoccer-courses']);
        $this->assertArrayNotHasKey('intersoccer-girls-only', $map);
        $this->assertSame('tournaments', $map['intersoccer-tournaments']);
    }

    public function test_unified_url_preserves_filters(): void {
        if (!function_exists('intersoccer_rosters_unified_url')) {
            $this->markTestSkipped('unified url helper not loaded');
        }

        $url = intersoccer_rosters_unified_url('courses', [
            'page' => 'intersoccer-courses',
            'season' => 'autumn-2026',
            'venue' => 'geneva',
            'girls_only_mode' => 'yes',
            'evil' => 'nope',
        ]);

        $this->assertStringContainsString('page=intersoccer-rosters', $url);
        $this->assertStringContainsString('activity_type=courses', $url);
        $this->assertStringContainsString('season=autumn-2026', $url);
        $this->assertStringContainsString('venue=geneva', $url);
        $this->assertStringContainsString('girls_only_mode=yes', $url);
        $this->assertStringNotContainsString('evil=', $url);
    }

    public function test_unified_url_girls_only_activity_becomes_camps_yes(): void {
        if (!function_exists('intersoccer_rosters_unified_url')) {
            $this->markTestSkipped('unified url helper not loaded');
        }

        $url = intersoccer_rosters_unified_url('girls_only', []);
        $this->assertStringContainsString('activity_type=camps', $url);
        $this->assertStringContainsString('girls_only_mode=yes', $url);
    }

    public function test_activity_type_filter_labels(): void {
        if (!function_exists('intersoccer_rosters_admin_activity_type_filter_label')) {
            $this->markTestSkipped('label helper not loaded');
        }

        $this->assertNotSame('', intersoccer_rosters_admin_activity_type_filter_label('camps'));
        $this->assertSame('Camps', intersoccer_rosters_admin_activity_type_filter_label('girls_only'));
    }

    public function test_girls_only_mode_parser_accepts_yes_no_all(): void {
        if (!function_exists('intersoccer_rosters_admin_girls_only_mode_from_request')) {
            $this->markTestSkipped('girls only mode helper not loaded');
        }

        unset($_GET['girls_only_mode']);
        $this->assertSame('all', intersoccer_rosters_admin_girls_only_mode_from_request());

        $_GET['girls_only_mode'] = 'yes';
        $this->assertSame('girls_only', intersoccer_rosters_admin_girls_only_mode_from_request());
        $_GET['girls_only_mode'] = 'no';
        $this->assertSame('mixed', intersoccer_rosters_admin_girls_only_mode_from_request());
        $_GET['girls_only_mode'] = 'all';
        $this->assertSame('all', intersoccer_rosters_admin_girls_only_mode_from_request());
        unset($_GET['girls_only_mode']);
    }

    public function test_reconcile_page_slugs_omit_retired_all_rosters(): void {
        $persist = __DIR__ . '/../../includes/roster-list-filter-persistence.php';
        if (file_exists($persist)) {
            require_once $persist;
        }
        if (!function_exists('intersoccer_rosters_reconcile_page_slugs')) {
            $this->markTestSkipped('reconcile page slugs helper not loaded');
        }

        $slugs = intersoccer_rosters_reconcile_page_slugs();
        $this->assertContains('intersoccer-rosters', $slugs);
        $this->assertNotContains('intersoccer-all-rosters', $slugs);
    }
}
