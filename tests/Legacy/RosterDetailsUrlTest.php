<?php
/**
 * Roster Details URL helpers for order line items.
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Brain\Monkey\Functions;

class RosterDetailsUrlTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        if (file_exists(__DIR__ . '/../../includes/utils.php')) {
            require_once __DIR__ . '/../../includes/utils.php';
        }
        if (file_exists(__DIR__ . '/../../includes/roster-details.php')) {
            require_once __DIR__ . '/../../includes/roster-details.php';
        }

        Functions\when('wpml_get_current_language')->justReturn('en');
        Functions\when('wpml_get_default_language')->justReturn('en');
        Functions\when('do_action')->justReturn();
        Functions\when('get_terms')->justReturn([]);
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('admin_url')->alias(function ($path = '') {
            return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
        });
        Functions\when('add_query_arg')->alias(function ($args, $url) {
            $query = is_array($args) ? http_build_query($args) : (string) $args;
            $sep = strpos((string) $url, '?') === false ? '?' : '&';
            return (string) $url . $sep . $query;
        });
    }

    public function test_roster_details_from_page_for_course() {
        if (!function_exists('intersoccer_roster_details_from_page_for_row')) {
            $this->markTestSkipped('intersoccer_roster_details_from_page_for_row not loaded');
        }

        $this->assertSame(
            'courses',
            intersoccer_roster_details_from_page_for_row(['activity_type' => 'Course', 'girls_only' => 0])
        );
        $this->assertSame(
            'girls-only',
            intersoccer_roster_details_from_page_for_row(['activity_type' => 'Course, Girls Only', 'girls_only' => 1])
        );
        $this->assertSame(
            'camps',
            intersoccer_roster_details_from_page_for_row(['activity_type' => 'Camp', 'girls_only' => 0])
        );
        $this->assertSame(
            'tournaments',
            intersoccer_roster_details_from_page_for_row(['activity_type' => 'Tournament', 'girls_only' => 0])
        );
    }

    public function test_collect_consolidated_order_item_ids_groups_fr_and_en_course_rows() {
        if (!function_exists('intersoccer_collect_consolidated_order_item_ids_for_roster_row')
            || !function_exists('intersoccer_consolidated_roster_group_key')) {
            $this->markTestSkipped('consolidated roster helpers not loaded');
        }

        $en = [
            'order_item_id' => 4760,
            'product_id' => 25232,
            'variation_id' => 35888,
            'activity_type' => 'Course',
            'venue' => 'Geneva',
            'course_day' => 'Tuesday',
            'age_group' => '5-6y',
            'times' => '17:00',
            'season' => 'Spring/Summer 2026',
            'girls_only' => 0,
        ];
        $fr = $en;
        $fr['order_item_id'] = 5127;
        $fr['course_day'] = 'Mardi';
        $fr['venue'] = 'Genève';

        $ids = intersoccer_collect_consolidated_order_item_ids_for_roster_row($en, [$en, $fr], 'course');

        $this->assertContains(4760, $ids);
        $this->assertContains(5127, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_collect_consolidated_order_item_ids_always_includes_anchor() {
        if (!function_exists('intersoccer_collect_consolidated_order_item_ids_for_roster_row')) {
            $this->markTestSkipped('intersoccer_collect_consolidated_order_item_ids_for_roster_row not loaded');
        }

        $anchor = [
            'order_item_id' => 100,
            'product_id' => 1,
            'variation_id' => 10,
            'activity_type' => 'Course',
            'venue' => 'Zurich',
            'course_day' => 'Monday',
            'age_group' => '7-8y',
            'times' => '10:00',
            'season' => '2026',
        ];

        $ids = intersoccer_collect_consolidated_order_item_ids_for_roster_row($anchor, [], 'course');

        $this->assertSame([100], $ids);
    }

    public function test_get_roster_details_url_for_order_item_returns_null_without_rows() {
        if (!function_exists('intersoccer_get_roster_details_url_for_order_item')) {
            $this->markTestSkipped('intersoccer_get_roster_details_url_for_order_item not loaded');
        }

        global $wpdb;
        $wpdb = new class() {
            public $prefix = 'wp_';

            public function prepare($query, ...$args) {
                return $query;
            }

            public function get_results($query, $output = OBJECT) {
                return [];
            }
        };

        $this->assertNull(intersoccer_get_roster_details_url_for_order_item(99999));
    }

    public function test_get_roster_details_url_for_order_item_builds_consolidated_course_link() {
        if (!function_exists('intersoccer_get_roster_details_url_for_order_item')) {
            $this->markTestSkipped('intersoccer_get_roster_details_url_for_order_item not loaded');
        }

        $shared_signature = 'abc123def456789012345678901234ab';

        $anchor = [
            'id' => 1,
            'order_item_id' => 4760,
            'product_id' => 25232,
            'variation_id' => 35888,
            'activity_type' => 'Course',
            'venue' => 'Geneva',
            'course_day' => 'Tuesday',
            'age_group' => '5-6y',
            'times' => '17:00',
            'season' => 'Spring/Summer 2026',
            'girls_only' => 0,
            'event_signature' => $shared_signature,
            'first_name' => 'Ezra',
            'last_name' => 'Test',
        ];
        $sibling = $anchor;
        $sibling['id'] = 2;
        $sibling['order_item_id'] = 5127;

        global $wpdb;
        $wpdb = new class($anchor, $sibling) {
            public $prefix = 'wp_';
            private $anchor;
            private $sibling;
            private $call = 0;

            public function __construct($anchor, $sibling) {
                $this->anchor = $anchor;
                $this->sibling = $sibling;
            }

            public function prepare($query, ...$args) {
                return $query;
            }

            public function get_results($query, $output = OBJECT) {
                $this->call++;
                if ($this->call === 1) {
                    return [$this->anchor];
                }
                return [$this->anchor, $this->sibling];
            }
        };

        $url = intersoccer_get_roster_details_url_for_order_item(4760);

        $this->assertIsString($url);
        $this->assertStringContainsString('page=intersoccer-roster-details', $url);
        $this->assertStringContainsString('from=courses', $url);
        $this->assertStringContainsString('event_signature=' . rawurlencode($shared_signature), $url);
        $this->assertStringNotContainsString('order_item_ids=', $url);
    }

    public function test_get_roster_details_url_for_order_item_falls_back_to_order_item_ids_without_signature() {
        if (!function_exists('intersoccer_get_roster_details_url_for_order_item')) {
            $this->markTestSkipped('intersoccer_get_roster_details_url_for_order_item not loaded');
        }

        $anchor = [
            'id' => 1,
            'order_item_id' => 4760,
            'product_id' => 25232,
            'variation_id' => 35888,
            'activity_type' => 'Course',
            'venue' => 'Geneva',
            'course_day' => 'Tuesday',
            'age_group' => '5-6y',
            'times' => '17:00',
            'season' => 'Spring/Summer 2026',
            'girls_only' => 0,
            'event_signature' => '',
            'first_name' => 'Ezra',
            'last_name' => 'Test',
        ];
        $sibling = $anchor;
        $sibling['id'] = 2;
        $sibling['order_item_id'] = 5127;

        global $wpdb;
        $wpdb = new class($anchor, $sibling) {
            public $prefix = 'wp_';
            private $anchor;
            private $sibling;
            private $call = 0;

            public function __construct($anchor, $sibling) {
                $this->anchor = $anchor;
                $this->sibling = $sibling;
            }

            public function prepare($query, ...$args) {
                return $query;
            }

            public function get_results($query, $output = OBJECT) {
                $this->call++;
                if ($this->call === 1) {
                    return [$this->anchor];
                }
                return [$this->anchor, $this->sibling];
            }
        };

        $url = intersoccer_get_roster_details_url_for_order_item(4760);

        $this->assertIsString($url);
        $this->assertStringContainsString('order_item_ids=4760%2C5127', $url);
        $this->assertStringNotContainsString('event_signature=', $url);
    }
}
