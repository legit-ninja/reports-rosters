<?php
/**
 * Order admin line-item meta display dedupe (stored meta unchanged).
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class OrderAdminLineItemMetaDisplayTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/order-admin-line-item-meta-display.php';
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe'],
            $GLOBALS['intersoccer_test_is_admin'],
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_woocommerce_email_header_fired']
        );
        parent::tearDown();
    }

    /**
     * Unmoved checkout shape: PV human meta_key + Woo core pa_* only (no attribute_pa_*).
     *
     * @return array<int,object>
     */
    private function unmovedCheckoutFormattedMetaAllTaxonomies(): array {
        $pairs = [
            ['Sites InterSoccer', 'pa_intersoccer-venues', 'Sites InterSoccer', 'InterSoccer Venues'],
            ['Age Group', 'pa_age-group', 'Age Group', 'Age Group'],
            ['Camp Terms', 'pa_camp-terms', 'Camp Terms', 'Camp Terms'],
            ['Course Day', 'pa_course-day', 'Course Day', 'Course Day'],
            ['Course Times', 'pa_course-times', 'Course Times', 'Course Times'],
            ['Camp Times', 'pa_camp-times', 'Camp Times', 'Camp Times'],
            ['Activity Type', 'pa_activity-type', 'Activity Type', 'Activity Type'],
            ['Season', 'pa_program-season', 'Season', 'Season'],
            ['Booking Type', 'pa_booking-type', 'Booking Type', 'Booking Type'],
            ['Canton / Region', 'pa_canton-region', 'Canton / Region', 'Canton / Region'],
            ['City', 'pa_city', 'City', 'City'],
            ['Girls Only', 'pa_girls-only', 'Girls Only', 'Girls Only'],
        ];

        $formatted = [];
        $id = 100;
        foreach ($pairs as [$human_key, $pa_key, $human_display, $pa_display]) {
            $formatted[$id++] = (object) [
                'key' => $human_key,
                'value' => 'sample',
                'display_key' => $human_display,
                'display_value' => 'Sample',
            ];
            $formatted[$id++] = (object) [
                'key' => $pa_key,
                'value' => 'sample-slug',
                'display_key' => $pa_display,
                'display_value' => 'Sample',
            ];
        }

        return $formatted;
    }

    /**
     * @return array<int,object>
     */
    private function sampleFormattedMeta(): array {
        return [
            1 => (object) [
                'key' => 'Sites InterSoccer',
                'value' => 'Geneva Centre',
                'display_key' => 'Sites InterSoccer',
                'display_value' => 'Geneva Centre',
            ],
            2 => (object) [
                'key' => 'pa_intersoccer-venues',
                'value' => 'geneva-centre',
                'display_key' => 'InterSoccer Venues',
                'display_value' => 'Geneva Centre',
            ],
            3 => (object) [
                'key' => 'attribute_pa_intersoccer-venues',
                'value' => 'geneva-centre',
                'display_key' => 'InterSoccer Venues',
                'display_value' => 'Geneva Centre',
            ],
            4 => (object) [
                'key' => 'Age Group',
                'value' => '6-8',
                'display_key' => 'Age Group',
                'display_value' => '6-8',
            ],
            5 => (object) [
                'key' => 'pa_age-group',
                'value' => '6-8',
                'display_key' => 'Age Group',
                'display_value' => '6-8',
            ],
            6 => (object) [
                'key' => 'attribute_pa_age-group',
                'value' => '6-8',
                'display_key' => 'Age Group',
                'display_value' => '6-8',
            ],
            7 => (object) [
                'key' => 'Girls Only',
                'value' => 'No',
                'display_key' => 'Girls Only',
                'display_value' => 'No',
            ],
            8 => (object) [
                'key' => 'girls_only',
                'value' => 'No',
                'display_key' => 'Girls Only',
                'display_value' => 'No',
            ],
            9 => (object) [
                'key' => 'pa_girls-only',
                'value' => 'no',
                'display_key' => 'Girls Only',
                'display_value' => 'No',
            ],
        ];
    }

    /**
     * @param array<int,object> $formatted
     * @return list<string>
     */
    private function displayLabels(array $formatted): array {
        $labels = [];
        foreach ($formatted as $meta) {
            $labels[] = (string) $meta->display_key;
        }

        return $labels;
    }

    public function test_admin_dedupe_shows_each_attribute_label_once(): void {
        $GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe'] = true;
        $item = new \WC_Order_Item_Product(88001);
        $baseline = $this->sampleFormattedMeta();
        $filtered = intersoccer_order_admin_filter_duplicate_line_item_formatted_meta($baseline, $item);

        $labels = $this->displayLabels($filtered);
        $this->assertSame(
            ['Sites InterSoccer', 'Age Group', 'Girls Only'],
            $labels
        );
        $this->assertCount(3, $filtered);
    }

    public function test_frontend_path_leaves_formatted_meta_unchanged(): void {
        unset($GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe']);
        $GLOBALS['intersoccer_test_is_admin'] = false;
        $item = new \WC_Order_Item_Product(88002);
        $baseline = $this->sampleFormattedMeta();
        $filtered = intersoccer_order_admin_filter_duplicate_line_item_formatted_meta($baseline, $item);

        $this->assertSame($baseline, $filtered);
        $this->assertCount(9, $filtered);
        unset($GLOBALS['intersoccer_test_is_admin']);
    }

    public function test_unmoved_checkout_human_plus_raw_pa_shows_each_facet_once(): void {
        $GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe'] = true;
        $baseline = $this->unmovedCheckoutFormattedMetaAllTaxonomies();
        $this->assertCount(24, $baseline);

        $item = new \WC_Order_Item_Product(88010);
        $filtered = intersoccer_order_admin_filter_duplicate_line_item_formatted_meta($baseline, $item);

        $this->assertCount(12, $filtered);
        $labels = $this->displayLabels($filtered);
        $this->assertSame(
            [
                'Sites InterSoccer',
                'Age Group',
                'Camp Terms',
                'Course Day',
                'Course Times',
                'Camp Times',
                'Activity Type',
                'Season',
                'Booking Type',
                'Canton / Region',
                'City',
                'Girls Only',
            ],
            $labels
        );
    }

    /**
     * FR stored meta_key Lieux InterSoccer — migration-meta.php static fallback line 89.
     */
    public function test_fr_human_meta_key_hides_raw_pa_slug(): void {
        $formatted = [
            1 => (object) [
                'key' => 'Lieux InterSoccer',
                'value' => 'geneva',
                'display_key' => 'Lieux InterSoccer',
                'display_value' => 'Geneva',
            ],
            2 => (object) [
                'key' => 'pa_intersoccer-venues',
                'value' => 'geneva',
                'display_key' => 'InterSoccer Venues',
                'display_value' => 'Geneva',
            ],
        ];
        $deduped = intersoccer_dedupe_admin_order_item_formatted_meta($formatted);
        $this->assertCount(1, $deduped);
        $this->assertSame('Lieux InterSoccer', $deduped[1]->display_key);
    }

    /**
     * FR Type d'activité — migration-meta.php line 95; DE Aktivitätstyp — PV order-meta-contract.php f548e83 ~2243.
     */
    public function test_fr_and_de_activity_type_human_keys_hide_pa_slug(): void {
        foreach (
            [
                ["Type d'activité", 'pa_activity-type'],
                ['Aktivitätstyp', 'pa_activity-type'],
            ] as [$human_key, $pa_key]
        ) {
            $formatted = [
                1 => (object) [
                    'key' => $human_key,
                    'value' => 'camp',
                    'display_key' => $human_key,
                    'display_value' => 'Camp',
                ],
                2 => (object) [
                    'key' => $pa_key,
                    'value' => 'camp',
                    'display_key' => 'Activity Type',
                    'display_value' => 'Camp',
                ],
            ];
            $deduped = intersoccer_dedupe_admin_order_item_formatted_meta($formatted);
            $this->assertCount(1, $deduped, $human_key);
            $this->assertSame($human_key, reset($deduped)->display_key);
        }
    }

    public function test_stored_meta_keys_unchanged_after_admin_filter(): void {
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[88003] = [
            'Sites InterSoccer' => 'Geneva Centre',
            'pa_intersoccer-venues' => 'geneva-centre',
            'attribute_pa_intersoccer-venues' => 'geneva-centre',
            'Age Group' => '6-8',
            'pa_age-group' => '6-8',
            'attribute_pa_age-group' => '6-8',
        ];
        $before = $intersoccer_test_wc_order_item_meta[88003];

        $GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe'] = true;
        $item = new \WC_Order_Item_Product(88003);
        intersoccer_order_admin_filter_duplicate_line_item_formatted_meta($this->sampleFormattedMeta(), $item);

        $this->assertSame($before, $intersoccer_test_wc_order_item_meta[88003]);
    }

    public function test_pa_only_attribute_stays_visible_when_no_human_label(): void {
        $formatted = [
            10 => (object) [
                'key' => 'pa_course-day',
                'value' => 'saturday',
                'display_key' => 'Course Day',
                'display_value' => 'Saturday',
            ],
        ];
        $deduped = intersoccer_dedupe_admin_order_item_formatted_meta($formatted);
        $this->assertCount(1, $deduped);
        $this->assertSame('Course Day', $deduped[10]->display_key);
    }

    public function test_legacy_backfill_note_skips_stock_delta_from_same_move(): void {
        require_once dirname(__DIR__, 2) . '/includes/roster-move-stock.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-history.php';

        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[88004] = [
            intersoccer_roster_move_stock_delta_meta_key() => wp_json_encode([
                'from_variation_id' => 500,
                'to_variation_id' => 600,
                'applied_from' => 1,
                'applied_to' => 1,
            ]),
        ];

        $legacy = intersoccer_roster_history_legacy_backfill_context(88004, 500);
        $this->assertSame('', $legacy['note']);

        $earlier = intersoccer_roster_history_legacy_backfill_context(88004, 0);
        $this->assertStringContainsString('earlier roster move stock delta', $earlier['note']);
    }

    public function test_bootstrap_load_registers_formatted_meta_filter_without_roster_move_modules(): void {
        unset(
            $GLOBALS['intersoccer_order_admin_line_item_meta_filter_registered'],
            $GLOBALS['intersoccer_test_wp_hooks']
        );
        require_once dirname(__DIR__, 2) . '/includes/roster-move-item-renormalize-suppress.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        intersoccer_order_admin_register_line_item_meta_display_hooks();

        $this->assertTrue(function_exists('intersoccer_dedupe_admin_order_item_formatted_meta'));
        $hooks = $GLOBALS['intersoccer_test_wp_hooks']['filters']['woocommerce_order_item_get_formatted_meta_data'] ?? [];
        $found = false;
        foreach ($hooks as $callbacks) {
            foreach ($callbacks as $entry) {
                if (($entry['callback'] ?? null) === 'intersoccer_order_admin_filter_duplicate_line_item_formatted_meta') {
                    $found = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($found);
    }

    public function test_two_non_slug_rows_with_same_display_label_both_remain(): void {
        $formatted = [
            20 => (object) [
                'key' => 'Note',
                'value' => 'A',
                'display_key' => 'Custom Label',
                'display_value' => 'A',
            ],
            21 => (object) [
                'key' => 'Custom Label',
                'value' => 'B',
                'display_key' => 'Custom Label',
                'display_value' => 'B',
            ],
        ];
        $deduped = intersoccer_dedupe_admin_order_item_formatted_meta($formatted);
        $this->assertCount(2, $deduped);
    }

    public function test_allowlisted_order_items_ajax_dedupes_when_user_can_edit_shop_orders(): void {
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
        $_REQUEST['action'] = 'woocommerce_load_order_items';
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_current_user_caps'] = ['edit_shop_orders' => true];
        unset($GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe']);

        $this->assertTrue(intersoccer_should_dedupe_admin_order_item_formatted_meta());

        $item = new \WC_Order_Item_Product(88005);
        $filtered = intersoccer_order_admin_filter_duplicate_line_item_formatted_meta($this->sampleFormattedMeta(), $item);
        $this->assertCount(3, $filtered);

        unset($GLOBALS['intersoccer_test_is_admin'], $GLOBALS['intersoccer_test_current_user_caps'], $_REQUEST['action']);
    }

    public function test_non_allowlisted_ajax_does_not_dedupe(): void {
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
        $_REQUEST['action'] = 'heartbeat';
        $GLOBALS['intersoccer_test_is_admin'] = true;
        unset($GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe']);
        $this->assertFalse(intersoccer_should_dedupe_admin_order_item_formatted_meta());
        unset($GLOBALS['intersoccer_test_is_admin'], $_REQUEST['action']);
    }

    public function test_email_context_does_not_dedupe(): void {
        $GLOBALS['intersoccer_test_woocommerce_email_header_fired'] = true;
        $GLOBALS['intersoccer_test_is_admin'] = true;
        unset($GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe']);
        $this->assertFalse(intersoccer_should_dedupe_admin_order_item_formatted_meta());
        unset($GLOBALS['intersoccer_test_woocommerce_email_header_fired'], $GLOBALS['intersoccer_test_is_admin']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_rest_request_does_not_dedupe(): void {
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/order-admin-line-item-meta-display.php';
        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }
        $GLOBALS['intersoccer_test_is_admin'] = true;
        unset($GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe']);
        $this->assertFalse(intersoccer_should_dedupe_admin_order_item_formatted_meta());

        $item = new \WC_Order_Item_Product(88011);
        $baseline = $this->sampleFormattedMeta();
        $filtered = intersoccer_order_admin_filter_duplicate_line_item_formatted_meta($baseline, $item);
        $this->assertSame($baseline, $filtered);
    }
}
