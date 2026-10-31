<?php
/**
 * Criterion 17 partial/all-failed payloads, retry, and move-flow logging privacy.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersAjaxPartialFailureTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    private static bool $rosterRowFilterRegistered = false;

    private const ORDER_ID = 89001;
    private const ITEM_OK = 89011;
    private const ITEM_ON_TARGET = 89012;
    private const ITEM_NO_ROSTER = 89013;
    private const PARENT_ID = 89000;
    private const SOURCE_VAR = 89010;
    private const TARGET_VAR = 89020;

    private ?string $previousErrorLog = null;
    private ?string $tempErrorLog = null;
    /** @var callable|null */
    private $moveLogCaptureHook = null;
    /** @var list<string> */
    private array $capturedMoveLogs = [];

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        $this->resetMovePlayersTestGlobals();
        $this->ensureCourseMetaStub();

        global $intersoccer_test_product_type_safe_callback, $intersoccer_test_get_product_type_callback;
        $intersoccer_test_product_type_safe_callback = static function () {
            return 'course';
        };
        $intersoccer_test_get_product_type_callback = static function () {
            return 'course';
        };

        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-item-renormalize-suppress.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__) . '/stubs/get-product-type-stub.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-reason-messages.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-admin-target.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-audit.php';
        require_once dirname(__DIR__, 2) . '/includes/advanced.php';

        if (!self::$rosterRowFilterRegistered) {
            add_filter(
                'intersoccer_roster_move_order_item_has_roster_row',
                static function ($has, $order_item_id) {
                    if ($has !== null) {
                        return $has;
                    }
                    $map = $GLOBALS['intersoccer_test_roster_row_filter_map'] ?? null;
                    if (!is_array($map) || !array_key_exists((int) $order_item_id, $map)) {
                        return null;
                    }

                    return (bool) $map[(int) $order_item_id];
                },
                10,
                2
            );
            self::$rosterRowFilterRegistered = true;
        }
    }

    protected function tearDown(): void {
        if ($this->moveLogCaptureHook !== null) {
            remove_action('intersoccer_roster_move_log', $this->moveLogCaptureHook, 10);
            $this->moveLogCaptureHook = null;
        }
        $this->capturedMoveLogs = [];
        if ($this->previousErrorLog !== null) {
            ini_set('error_log', $this->previousErrorLog);
        }
        if ($this->tempErrorLog !== null && is_file($this->tempErrorLog)) {
            unlink($this->tempErrorLog);
        }
        unset(
            $GLOBALS['intersoccer_test_emit_wp_hooks'],
            $GLOBALS['intersoccer_test_roster_row_filter_map']
        );
        $this->resetMovePlayersTestGlobals();
        $_POST = [];
        parent::tearDown();
    }

    private function ensureCourseMetaStub(): void {
        if (!function_exists('intersoccer_get_course_meta')) {
            eval(
                'function intersoccer_get_course_meta($variation_id, $key, $default = null) {
                    $map = $GLOBALS["intersoccer_test_course_meta_by_variation"][(int) $variation_id] ?? [];
                    return array_key_exists($key, $map) ? $map[$key] : $default;
                }'
            );
        }
    }

    private function beginLogCapture(): void {
        $this->capturedMoveLogs = [];
        $this->moveLogCaptureHook = function (string $message): void {
            $this->capturedMoveLogs[] = $message;
        };
        add_action('intersoccer_roster_move_log', $this->moveLogCaptureHook, 10, 1);
        $this->tempErrorLog = tempnam(sys_get_temp_dir(), 'rr-move-log-');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->tempErrorLog);
    }

    private function combinedLogText(): string {
        $parts = $this->capturedMoveLogs;
        if ($this->tempErrorLog && is_readable($this->tempErrorLog)) {
            $parts[] = (string) file_get_contents($this->tempErrorLog);
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function invokeMoveAjax(array $post): array {
        $_POST = array_merge(
            [
                'action' => 'intersoccer_move_players',
                'nonce' => wp_create_nonce('intersoccer_move_nonce'),
            ],
            $post
        );
        $GLOBALS['intersoccer_test_json_success_throws'] = true;
        $GLOBALS['intersoccer_test_json_error_throws'] = true;
        try {
            ob_start();
            intersoccer_move_players_ajax();
            $json = ob_get_clean();
        } catch (\RuntimeException $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $json = $e->getMessage();
        } finally {
            unset($GLOBALS['intersoccer_test_json_success_throws'], $GLOBALS['intersoccer_test_json_error_throws']);
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function installWorld(array $options = []): void {
        global $wpdb, $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_data;
        global $intersoccer_test_wc_get_order_callback, $intersoccer_test_wc_get_product_callback;
        global $intersoccer_test_post_status_map;

        $roster_rows = $options['roster_row_ids'] ?? [
            self::ITEM_OK => 901,
            self::ITEM_ON_TARGET => 902,
            self::ITEM_NO_ROSTER => 0,
        ];

        $GLOBALS['intersoccer_test_roster_row_filter_map'] = array_map(
            static function ($row_id) {
                return (int) $row_id > 0;
            },
            $roster_rows
        );

        $intersoccer_test_post_status_map = [
            self::SOURCE_VAR => 'publish',
            self::TARGET_VAR => 'publish',
            self::PARENT_ID => 'publish',
        ];

        $item_configs = [
            self::ITEM_OK => ['variation_id' => self::SOURCE_VAR, 'first' => 'Alex'],
            self::ITEM_ON_TARGET => ['variation_id' => self::TARGET_VAR, 'first' => 'Sam'],
            self::ITEM_NO_ROSTER => ['variation_id' => self::SOURCE_VAR, 'first' => 'Zoe'],
        ];

        $intersoccer_test_wc_order_item_meta = [];
        $intersoccer_test_wc_order_item_data = [];
        foreach ($item_configs as $item_id => $cfg) {
            $intersoccer_test_wc_order_item_meta[$item_id] = [
                'Activity Type' => 'Course',
                'Assigned Attendee' => $cfg['first'] . ' Lastname',
            ];
            $intersoccer_test_wc_order_item_data[$item_id] = [
                'product_id' => self::PARENT_ID,
                'variation_id' => $cfg['variation_id'],
                'subtotal' => 100.00,
                'total' => 100.00,
                'name' => 'Course line',
            ];
        }

        $items = [];
        foreach (array_keys($item_configs) as $item_id) {
            $items[$item_id] = new \WC_Order_Item_Product($item_id);
        }
        $order = new \WC_Order(self::ORDER_ID);
        $order->set_items($items);

        $intersoccer_test_wc_get_order_callback = static function ($id) use ($order) {
            return (int) $id === self::ORDER_ID ? $order : false;
        };

        $source = $this->makeVariationMock(self::SOURCE_VAR);
        $target = $this->makeVariationMock(self::TARGET_VAR, ['price' => '', 'is_purchasable' => false]);

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_id')->andReturn(self::PARENT_ID);
        $parent->shouldReceive('get_catalog_visibility')->andReturn('visible');
        $parent->shouldReceive('get_meta')->with('capacity', true)->andReturn('');
        $parent->shouldReceive('get_name')->andReturn('Parent course');
        $parent->shouldReceive('get_attribute')->andReturn('');
        $parent->shouldReceive('get_attributes')->andReturn([]);

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($source, $target, $parent) {
            $id = (int) $id;
            if ($id === self::SOURCE_VAR) {
                return $source;
            }
            if ($id === self::TARGET_VAR) {
                return $target;
            }
            if ($id === self::PARENT_ID) {
                return $parent;
            }

            return false;
        };

        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query, ...$args) {
            $flat_args = [];
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    $flat_args = array_merge($flat_args, $arg);
                } else {
                    $flat_args[] = $arg;
                }
            }
            $safe_args = array_map(static function ($v) {
                return is_string($v) ? addslashes($v) : $v;
            }, $flat_args);

            return @vsprintf(str_replace(['%s', '%d', '%f'], ["'%s'", '%d', '%f'], (string) $query), $safe_args) ?: (string) $query;
        });
        $wpdb->shouldReceive('get_results')->andReturn([]);
        $wpdb->shouldReceive('get_row')->andReturn(null);
        $wpdb->shouldReceive('update')->andReturn(1);
        $wpdb->shouldReceive('query')->andReturn(true);
        $wpdb->shouldReceive('get_var')->andReturnUsing(static function ($sql) {
            if (!is_string($sql)) {
                return null;
            }
            if (strpos($sql, 'player_first_name') !== false) {
                foreach ([self::ITEM_OK => 'Alex', self::ITEM_ON_TARGET => 'Sam', self::ITEM_NO_ROSTER => 'Zoe'] as $id => $name) {
                    if (strpos($sql, (string) $id) !== false) {
                        return $name;
                    }
                }
            }
            if (strpos($sql, 'woocommerce_order_items') !== false) {
                return (string) self::ORDER_ID;
            }
            if (strpos($sql, 'intersoccer_rosters') !== false && strpos($sql, 'order_item_id') !== false) {
                return '99';
            }
            if (strpos($sql, 'event_completed') !== false) {
                return '0';
            }
            if (strpos($sql, 'COUNT(*)') !== false) {
                return '0';
            }

            return '0';
        });
    }

    private function makeVariationMock(int $variation_id, array $overrides = []) {
        $variation = Mockery::mock(\WC_Product_Variation::class);
        $variation->shouldReceive('get_id')->andReturn($variation_id);
        $variation->shouldReceive('get_parent_id')->andReturn(self::PARENT_ID);
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_name')->andReturn('Target variation');
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_attributes')->andReturn([]);
        $variation->shouldReceive('get_meta')->with('capacity', true)->andReturn($overrides['capacity'] ?? '');
        $variation->shouldReceive('get_price')->andReturn($overrides['price'] ?? '100');
        $variation->shouldReceive('is_purchasable')->andReturn($overrides['is_purchasable'] ?? true);
        $variation->shouldReceive('is_type')->with('variation')->andReturn(true);
        $variation->shouldReceive('managing_stock')->andReturn($overrides['managing_stock'] ?? true);
        $variation->shouldReceive('backorders_allowed')->andReturn($overrides['backorders_allowed'] ?? false);
        $variation->shouldReceive('get_stock_quantity')->andReturn($overrides['stock_quantity'] ?? 0);
        $variation->shouldReceive('get_stock_status')->andReturn($overrides['stock_status'] ?? 'outofstock');
        $variation->shouldReceive('set_stock_quantity')->never();
        $variation->shouldReceive('set_stock_status')->never();
        $variation->shouldReceive('save')->never();

        return $variation;
    }

    public function test_partial_batch_structured_failed_payload_and_partial_summary(): void {
        $this->installWorld();
        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_OK, self::ITEM_NO_ROSTER],
            'confirm_warnings' => '1',
        ]);

        $this->assertTrue($response['success'] ?? false);
        $data = $response['data'] ?? [];
        $this->assertSame(1, (int) ($data['moved_count'] ?? 0));
        $this->assertSame(2, (int) ($data['total_count'] ?? 0));
        $this->assertNotSame('', (string) ($data['partial_summary'] ?? ''));
        $this->assertStringContainsString('players moved. These couldn\'t be moved:', (string) $data['partial_summary']);
        $this->assertStringContainsString('1 of 2', (string) $data['partial_summary']);
        $this->assertSame([self::ITEM_OK], $data['moved_order_item_ids'] ?? []);
        $failed = $data['failed'] ?? [];
        $this->assertCount(1, $failed);
        $this->assertSame(self::ITEM_NO_ROSTER, (int) $failed[0]['order_item_id']);
        $this->assertSame(self::ORDER_ID, (int) $failed[0]['order_id']);
        $this->assertSame('Zoe', $failed[0]['player_first_name']);
        $this->assertStringNotContainsString('Lastname', (string) $failed[0]['player_first_name']);
        $this->assertStringContainsString('roster row', strtolower((string) $failed[0]['reason']));
    }

    public function test_retry_moves_only_failed_items_after_roster_row_fixed(): void {
        $this->installWorld();
        $first = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_OK, self::ITEM_NO_ROSTER],
            'confirm_warnings' => '1',
        ]);
        $this->assertTrue($first['success'] ?? false);
        $failed_id = (int) ($first['data']['failed'][0]['order_item_id'] ?? 0);
        $this->assertSame(self::ITEM_NO_ROSTER, $failed_id);

        $GLOBALS['intersoccer_test_roster_row_filter_map'][self::ITEM_NO_ROSTER] = true;

        $retry = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [$failed_id],
            'confirm_warnings' => '1',
        ]);
        $this->assertTrue($retry['success'] ?? false);
        $this->assertSame(1, (int) ($retry['data']['moved_count'] ?? 0));
        $this->assertSame(self::TARGET_VAR, (int) ($GLOBALS['intersoccer_test_wc_order_item_data'][$failed_id]['variation_id'] ?? 0));
        $this->assertSame(self::TARGET_VAR, (int) ($GLOBALS['intersoccer_test_wc_order_item_data'][self::ITEM_OK]['variation_id'] ?? 0));
    }

    public function test_all_failed_batch_returns_structured_payload(): void {
        $this->installWorld([
            'roster_row_ids' => [
                self::ITEM_OK => 0,
                self::ITEM_NO_ROSTER => 0,
            ],
        ]);
        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_OK, self::ITEM_NO_ROSTER],
            'confirm_warnings' => '1',
        ]);

        $this->assertTrue($response['success'] ?? false);
        $data = $response['data'] ?? [];
        $this->assertSame(0, (int) ($data['moved_count'] ?? -1));
        $this->assertSame(2, (int) ($data['failed_count'] ?? 0));
        $this->assertNotSame('', (string) ($data['partial_summary'] ?? ''));
        $this->assertStringContainsString('0 of 2', (string) $data['partial_summary']);
        $this->assertSame([], $data['moved_order_item_ids'] ?? null);
        $this->assertCount(2, $data['failed'] ?? []);
    }

    public function test_partial_batch_with_only_non_retryable_failures_marks_retryable_false(): void {
        $this->installWorld();
        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_OK, self::ITEM_ON_TARGET],
            'confirm_warnings' => '1',
        ]);

        $this->assertTrue($response['success'] ?? false);
        $failed = $response['data']['failed'] ?? [];
        $this->assertNotEmpty($failed);
        foreach ($failed as $entry) {
            $this->assertFalse($entry['retryable'] ?? true);
        }
    }

    public function test_retry_moves_only_preflight_failed_item_via_filter(): void {
        $this->installWorld();
        $block_once = true;
        add_filter(
            'intersoccer_roster_move_preflight_failure',
            static function ($failure, $item_id) use (&$block_once) {
                if ($block_once && (int) $item_id === self::ITEM_NO_ROSTER) {
                    $block_once = false;

                    return [
                        'reason' => __('Temporary move block.', 'intersoccer-reports-rosters'),
                        'retryable' => true,
                    ];
                }

                return $failure;
            },
            10,
            4
        );

        $first = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_OK, self::ITEM_NO_ROSTER],
            'confirm_warnings' => '1',
        ]);
        $this->assertTrue($first['success'] ?? false);
        $failed_id = (int) ($first['data']['failed'][0]['order_item_id'] ?? 0);
        $this->assertSame(self::ITEM_NO_ROSTER, $failed_id);
        $this->assertTrue($first['data']['failed'][0]['retryable'] ?? false);

        $GLOBALS['intersoccer_test_roster_row_filter_map'][self::ITEM_NO_ROSTER] = true;

        $retry = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [$failed_id],
            'confirm_warnings' => '1',
        ]);
        $this->assertTrue($retry['success'] ?? false);
        $this->assertSame(1, (int) ($retry['data']['moved_count'] ?? 0));
        $this->assertSame(self::TARGET_VAR, (int) ($GLOBALS['intersoccer_test_wc_order_item_data'][$failed_id]['variation_id'] ?? 0));
    }

    public function test_mixed_batch_records_same_variation_skip_as_non_retryable_failure(): void {
        $this->installWorld();
        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_OK, self::ITEM_ON_TARGET],
            'confirm_warnings' => '1',
        ]);

        $this->assertTrue($response['success'] ?? false);
        $data = $response['data'] ?? [];
        $this->assertSame(1, (int) ($data['moved_count'] ?? 0));
        $this->assertNotSame('', (string) ($data['partial_summary'] ?? ''));
        $failed = $data['failed'] ?? [];
        $this->assertCount(1, $failed);
        $this->assertSame(self::ITEM_ON_TARGET, (int) $failed[0]['order_item_id']);
        $this->assertSame('Sam', $failed[0]['player_first_name']);
        $this->assertSame(__('Already on this roster.', 'intersoccer-reports-rosters'), $failed[0]['reason']);
        $this->assertFalse($failed[0]['retryable'] ?? true);
    }

    public function test_malformed_order_item_ids_returns_invalid_input_without_php_warnings(): void {
        $this->installWorld();
        $warnings = [];
        set_error_handler(static function ($severity, $message) use (&$warnings) {
            $warnings[] = $message;
            return true;
        });

        $response = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => ['abc', 0, ''],
        ]);

        restore_error_handler();
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame(__('Invalid input.', 'intersoccer-reports-rosters'), $response['data']['message'] ?? '');
        $this->assertSame([], $warnings);
    }

    public function test_move_logs_never_contain_nonce_or_player_pii(): void {
        $this->installWorld();
        $this->beginLogCapture();
        $nonce = wp_create_nonce('intersoccer_move_nonce');

        $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_OK],
            'confirm_warnings' => '1',
            'nonce' => $nonce,
        ]);

        $fail = $this->invokeMoveAjax([
            'target_variation_id' => self::TARGET_VAR,
            'order_item_ids' => [self::ITEM_NO_ROSTER],
            'confirm_warnings' => '1',
            'nonce' => $nonce,
        ]);
        $this->assertTrue($fail['success'] ?? false);

        $log = $this->combinedLogText();
        $this->assertStringNotContainsString($nonce, $log);
        $this->assertStringNotContainsString('Alex', $log);
        $this->assertStringNotContainsString('Lastname', $log);
        $this->assertStringNotContainsString('Zoe', $log);
        $this->assertStringNotContainsString('2013-', $log);
    }
}
