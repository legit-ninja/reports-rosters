<?php
/**
 * WooCommerce order admin: dedupe line-item attribute meta in display only (stored meta unchanged).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') || exit;

if (!function_exists('intersoccer_order_admin_order_items_ajax_actions')) {
    /**
     * WooCommerce admin-ajax handlers that re-render order line items (WC_AJAX::add_ajax_events).
     *
     * @return array<int,string> Action names without the woocommerce_ prefix.
     */
    function intersoccer_order_admin_order_items_ajax_actions(): array {
        return [
            'save_order_items',
            'load_order_items',
            'add_order_item',
            'remove_order_item',
            'calc_line_taxes',
            'add_order_fee',
            'add_order_shipping',
            'add_coupon_discount',
            'remove_order_coupon',
            'delete_refund',
            'refund_line_items',
        ];
    }
}

if (!function_exists('intersoccer_order_admin_is_allowlisted_order_items_ajax_action')) {
    function intersoccer_order_admin_is_allowlisted_order_items_ajax_action(string $action): bool {
        $action = strtolower(trim($action));
        if ($action === '') {
            return false;
        }
        if (strpos($action, 'woocommerce_') === 0) {
            $action = substr($action, strlen('woocommerce_'));
        }

        return in_array($action, intersoccer_order_admin_order_items_ajax_actions(), true);
    }
}

if (!function_exists('intersoccer_order_admin_hidden_slug_attribute_meta_keys')) {
    /**
     * Exact meta keys hidden from admin display when a visible twin exists (data kept in DB).
     *
     * @return array<int,string>
     */
    function intersoccer_order_admin_hidden_slug_attribute_meta_keys(): array {
        static $keys = null;
        if ($keys !== null) {
            return $keys;
        }

        $keys = ['girls_only'];
        $taxonomies = function_exists('intersoccer_migration_pa_taxonomy_keys_for_move')
            ? intersoccer_migration_pa_taxonomy_keys_for_move()
            : [
                'pa_intersoccer-venues',
                'pa_age-group',
                'pa_camp-terms',
                'pa_course-day',
                'pa_course-times',
                'pa_camp-times',
                'pa_activity-type',
                'pa_program-season',
                'pa_booking-type',
                'pa_canton-region',
                'pa_city',
                'pa_girls-only',
            ];

        foreach ($taxonomies as $taxonomy) {
            $taxonomy = (string) $taxonomy;
            if ($taxonomy === '') {
                continue;
            }
            $keys[] = $taxonomy;
            $keys[] = 'attribute_' . $taxonomy;
        }

        return $keys;
    }
}

if (!function_exists('intersoccer_order_admin_is_hidden_slug_attribute_meta_key')) {
    function intersoccer_order_admin_is_hidden_slug_attribute_meta_key(string $meta_key): bool {
        $meta_key = (string) $meta_key;
        if ($meta_key === '') {
            return false;
        }

        return in_array($meta_key, intersoccer_order_admin_hidden_slug_attribute_meta_keys(), true);
    }
}

if (!function_exists('intersoccer_order_admin_formatted_meta_slug_display_priority')) {
    /**
     * Lower number = preferred visible slug row when only slug keys share a display label.
     */
    function intersoccer_order_admin_formatted_meta_slug_display_priority(string $meta_key): int {
        if (preg_match('/^attribute_pa_/i', $meta_key)) {
            return 40;
        }
        if (preg_match('/^pa_/i', $meta_key)) {
            return 30;
        }

        return 35;
    }
}

if (!function_exists('intersoccer_order_admin_pa_taxonomy_to_canonical_key')) {
    /**
     * @return array<string,string>
     */
    function intersoccer_order_admin_pa_taxonomy_to_canonical_key(): array {
        return [
            'pa_intersoccer-venues' => 'intersoccer_venues',
            'pa_age-group'            => 'age_group',
            'pa_camp-terms'           => 'camp_terms',
            'pa_course-day'           => 'course_day',
            'pa_course-times'         => 'course_times',
            'pa_camp-times'           => 'camp_times',
            'pa_activity-type'        => 'activity_type',
            'pa_program-season'       => 'season',
            'pa_booking-type'         => 'booking_type',
            'pa_canton-region'        => 'canton_region',
            'pa_city'                 => 'city',
            'pa_girls-only'           => 'girls_only',
        ];
    }
}

if (!function_exists('intersoccer_order_admin_stored_meta_keys_for_migration_canonical')) {
    /**
     * Stored order-item meta_key strings that represent the human facet (EN/FR/DE + field map).
     *
     * @return array<int,string>
     */
    function intersoccer_order_admin_stored_meta_keys_for_migration_canonical(string $canonical): array {
        static $cache = [];
        if (isset($cache[$canonical])) {
            return $cache[$canonical];
        }

        $keys = [];
        if (function_exists('intersoccer_migration_human_alias_map')) {
            $aliases = intersoccer_migration_human_alias_map();
            if (!empty($aliases[$canonical]) && is_array($aliases[$canonical])) {
                $keys = array_merge($keys, $aliases[$canonical]);
            }
        }
        if (function_exists('intersoccer_migration_human_alias_map_static_fallback')) {
            $keys = array_merge($keys, intersoccer_migration_human_alias_map_static_fallback($canonical));
        }
        if (function_exists('intersoccer_migration_english_meta_label')) {
            $keys[] = intersoccer_migration_english_meta_label($canonical);
        }

        $facet = '';
        if (function_exists('intersoccer_migration_canonical_to_facet_key')) {
            $facet = (string) (intersoccer_migration_canonical_to_facet_key()[$canonical] ?? '');
        }

        $field_names_for_canonical = [
            'intersoccer_venues' => ['venue'],
            'age_group'            => ['age_group'],
            'camp_terms'           => ['event_type'],
            'course_day'           => ['course_day'],
            'course_times'         => ['course_times'],
            'camp_times'           => ['camp_times'],
            'activity_type'        => ['activity_type'],
            'season'               => ['season', 'program_year'],
            'booking_type'         => ['booking_type'],
            'canton_region'        => ['region'],
            'city'                 => ['city'],
            'girls_only'           => ['girls_only'],
        ];
        $want_fields = $field_names_for_canonical[$canonical] ?? [];
        if ($facet !== '' && !in_array($facet, $want_fields, true)) {
            $want_fields[] = $facet;
        }
        if ($want_fields !== [] && function_exists('intersoccer_get_order_meta_field_map')) {
            foreach (intersoccer_get_order_meta_field_map() as $meta_key => $field) {
                if (in_array($field, $want_fields, true)) {
                    $keys[] = (string) $meta_key;
                }
            }
        }

        if (function_exists('intersoccer_get_order_meta_manual_aliases')
            && function_exists('intersoccer_migration_english_meta_label')) {
            $english = intersoccer_migration_english_meta_label($canonical);
            $manual = intersoccer_get_order_meta_manual_aliases();
            if ($english !== '' && isset($manual[$english])) {
                $keys[] = $english;
            }
        }

        $keys = array_values(array_unique(array_filter(
            $keys,
            static function ($key) {
                return is_string($key) && $key !== '';
            }
        )));

        $cache[$canonical] = $keys;

        return $keys;
    }
}

if (!function_exists('intersoccer_order_admin_stored_meta_keys_for_pa_taxonomy')) {
    /**
     * @return array<int,string>
     */
    function intersoccer_order_admin_stored_meta_keys_for_pa_taxonomy(string $taxonomy): array {
        $canonical = intersoccer_order_admin_pa_taxonomy_to_canonical_key()[$taxonomy] ?? '';
        if ($canonical === '') {
            return [];
        }

        return intersoccer_order_admin_stored_meta_keys_for_migration_canonical($canonical);
    }
}

if (!function_exists('intersoccer_order_admin_line_has_human_facet_meta_key')) {
    function intersoccer_order_admin_line_has_human_facet_meta_key(array $keys_present, string $taxonomy): bool {
        foreach (intersoccer_order_admin_stored_meta_keys_for_pa_taxonomy($taxonomy) as $human_key) {
            if (isset($keys_present[$human_key])) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('intersoccer_order_admin_remove_pa_slug_formatted_meta_when_human_label_present')) {
    /**
     * @param array<int|mixed,object> $formatted_meta
     * @return array<int|mixed,object>
     */
    function intersoccer_order_admin_remove_pa_slug_formatted_meta_when_human_label_present(array $formatted_meta): array {
        if ($formatted_meta === []) {
            return $formatted_meta;
        }

        $keys_present = [];
        foreach ($formatted_meta as $meta) {
            if (!is_object($meta)) {
                continue;
            }
            $keys_present[(string) ($meta->key ?? '')] = true;
        }

        if (!function_exists('intersoccer_order_admin_pa_taxonomy_to_canonical_key')) {
            return $formatted_meta;
        }

        foreach (intersoccer_order_admin_pa_taxonomy_to_canonical_key() as $taxonomy => $canonical) {
            if (!intersoccer_order_admin_line_has_human_facet_meta_key($keys_present, $taxonomy)) {
                continue;
            }
            foreach ($formatted_meta as $meta_id => $meta) {
                if (!is_object($meta)) {
                    continue;
                }
                $key = (string) ($meta->key ?? '');
                if (!intersoccer_order_admin_is_hidden_slug_attribute_meta_key($key)) {
                    continue;
                }
                if ($key === $taxonomy || $key === 'attribute_' . $taxonomy || ($canonical === 'girls_only' && $key === 'girls_only')) {
                    unset($formatted_meta[$meta_id]);
                }
            }
        }

        return $formatted_meta;
    }
}

if (!function_exists('intersoccer_dedupe_admin_order_item_formatted_meta')) {
    /**
     * @param array<int|mixed,object> $formatted_meta WooCommerce formatted meta objects.
     * @return array<int|mixed,object>
     */
    function intersoccer_dedupe_admin_order_item_formatted_meta(array $formatted_meta): array {
        if ($formatted_meta === []) {
            return $formatted_meta;
        }

        $by_label = [];
        foreach ($formatted_meta as $meta_id => $meta) {
            if (!is_object($meta)) {
                continue;
            }
            $display_key = isset($meta->display_key) ? (string) $meta->display_key : '';
            $label = strtolower(trim($display_key));
            if ($label === '') {
                continue;
            }
            $by_label[$label][] = $meta_id;
        }

        foreach ($by_label as $meta_ids) {
            if (count($meta_ids) <= 1) {
                continue;
            }

            $non_slug_ids = [];
            $slug_ids = [];
            foreach ($meta_ids as $meta_id) {
                $meta = $formatted_meta[$meta_id] ?? null;
                if (!is_object($meta)) {
                    continue;
                }
                $key = isset($meta->key) ? (string) $meta->key : '';
                if (intersoccer_order_admin_is_hidden_slug_attribute_meta_key($key)) {
                    $slug_ids[] = $meta_id;
                } else {
                    $non_slug_ids[] = $meta_id;
                }
            }

            if ($non_slug_ids !== []) {
                foreach ($slug_ids as $slug_id) {
                    unset($formatted_meta[$slug_id]);
                }
                continue;
            }

            if (count($slug_ids) <= 1) {
                continue;
            }

            $keep_id = null;
            $keep_priority = PHP_INT_MAX;
            foreach ($slug_ids as $slug_id) {
                $key = (string) ($formatted_meta[$slug_id]->key ?? '');
                $priority = intersoccer_order_admin_formatted_meta_slug_display_priority($key);
                if ($priority < $keep_priority) {
                    $keep_priority = $priority;
                    $keep_id = $slug_id;
                }
            }

            if ($keep_id === null) {
                continue;
            }

            foreach ($slug_ids as $slug_id) {
                if ($slug_id !== $keep_id) {
                    unset($formatted_meta[$slug_id]);
                }
            }
        }

        return intersoccer_order_admin_remove_pa_slug_formatted_meta_when_human_label_present($formatted_meta);
    }
}

if (!function_exists('intersoccer_order_admin_is_wp_admin_request')) {
    function intersoccer_order_admin_is_wp_admin_request(): bool {
        if (array_key_exists('intersoccer_test_is_admin', $GLOBALS)) {
            return (bool) $GLOBALS['intersoccer_test_is_admin'];
        }

        return function_exists('is_admin') && is_admin();
    }
}

if (!function_exists('intersoccer_should_dedupe_admin_order_item_formatted_meta')) {
    /**
     * True only on WooCommerce order edit screens in wp-admin (not emails, storefront, or REST).
     */
    function intersoccer_should_dedupe_admin_order_item_formatted_meta(): bool {
        if (!empty($GLOBALS['intersoccer_test_force_admin_order_item_meta_dedupe'])) {
            return true;
        }

        if (!intersoccer_order_admin_is_wp_admin_request()) {
            return false;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        if (!empty($GLOBALS['intersoccer_test_woocommerce_email_header_fired'])) {
            return false;
        }

        if (function_exists('did_action') && did_action('woocommerce_email_header')) {
            return false;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            $action = isset($_REQUEST['action']) ? (string) wp_unslash($_REQUEST['action']) : '';
            if (strpos($action, 'woocommerce_') === 0) {
                $action = substr($action, strlen('woocommerce_'));
            }
            if (intersoccer_order_admin_is_allowlisted_order_items_ajax_action($action)) {
                return function_exists('current_user_can') && current_user_can('edit_shop_orders');
            }

            return false;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && isset($screen->id)) {
                if ($screen->id === 'shop_order' || $screen->id === 'woocommerce_page_wc-orders') {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('intersoccer_order_admin_filter_duplicate_line_item_formatted_meta')) {
    /**
     * @param array<int|mixed,object> $formatted_meta
     * @param mixed                   $item
     * @return array<int|mixed,object>
     */
    function intersoccer_order_admin_filter_duplicate_line_item_formatted_meta($formatted_meta, $item) {
        unset($item);
        if (!is_array($formatted_meta) || !intersoccer_should_dedupe_admin_order_item_formatted_meta()) {
            return $formatted_meta;
        }

        return intersoccer_dedupe_admin_order_item_formatted_meta($formatted_meta);
    }
}

if (!function_exists('intersoccer_order_admin_register_line_item_meta_display_hooks')) {
    function intersoccer_order_admin_register_line_item_meta_display_hooks(): void {
        if (!empty($GLOBALS['intersoccer_order_admin_line_item_meta_filter_registered'])) {
            return;
        }
        $GLOBALS['intersoccer_order_admin_line_item_meta_filter_registered'] = true;

        add_filter(
            'woocommerce_order_item_get_formatted_meta_data',
            'intersoccer_order_admin_filter_duplicate_line_item_formatted_meta',
            20,
            2
        );
    }
    intersoccer_order_admin_register_line_item_meta_display_hooks();
}
