<?php
/**
 * Admin roster move: target variation checks (not customer purchasability).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

require_once __DIR__ . '/roster-move-reason-messages.php';

/**
 * Meta keys that mark a course/camp product or variation as cancelled (first truthy wins).
 *
 * @return string[]
 */
function intersoccer_roster_move_cancelled_meta_keys(): array {
    $keys = [
        '_intersoccer_cancelled',
        '_course_cancelled',
        '_camp_cancelled',
        '_event_cancelled',
    ];

    /**
     * @param string[] $keys
     */
    return apply_filters('intersoccer_roster_move_cancelled_meta_keys', $keys);
}

/**
 * @param int $post_id Product or variation post ID.
 */
function intersoccer_roster_move_post_is_cancelled(int $post_id): bool {
    if ($post_id <= 0) {
        return false;
    }

    foreach (intersoccer_roster_move_cancelled_meta_keys() as $key) {
        $val = get_post_meta($post_id, $key, true);
        if ($val === 'yes' || $val === '1' || $val === 1 || $val === true) {
            return true;
        }
    }

    return false;
}

/**
 * Post status for admin-target checks (filterable in tests).
 *
 * @param int $post_id
 */
function intersoccer_roster_move_get_post_status(int $post_id): string {
    global $intersoccer_test_post_status_map;
    if (defined('INTERSOCCER_TESTING') && INTERSOCCER_TESTING
        && is_array($intersoccer_test_post_status_map)
        && array_key_exists($post_id, $intersoccer_test_post_status_map)
    ) {
        return (string) $intersoccer_test_post_status_map[$post_id];
    }

    $status = function_exists('get_post_status') ? get_post_status($post_id) : 'publish';
    if ($status === false) {
        $status = '';
    }
    $status = (string) $status;

    /**
     * @param string $status
     * @param int    $post_id
     */
    return (string) apply_filters('intersoccer_roster_move_post_status_for_admin_target', $status, $post_id);
}

/**
 * @param int $post_id
 */
function intersoccer_roster_move_post_is_trashed(int $post_id): bool {
    if ($post_id <= 0) {
        return false;
    }
    return intersoccer_roster_move_get_post_status($post_id) === 'trash';
}

/**
 * Normalize activity type labels for move compatibility (course ↔ course, camp ↔ camp).
 *
 * @param mixed $activity_type
 */
function intersoccer_roster_move_normalize_activity_type($activity_type): string {
    $raw = trim((string) $activity_type);
    if ($raw === '') {
        return '';
    }
    if (function_exists('intersoccer_canonical_activity_type_for_roster')) {
        $raw = (string) intersoccer_canonical_activity_type_for_roster($raw);
    }
    return strtolower($raw);
}

/**
 * Resolve activity type slug for a variation's parent product.
 *
 * @param WC_Product_Variation $variation
 */
function intersoccer_roster_move_target_activity_type(WC_Product_Variation $variation): string {
    $parent_id = (int) $variation->get_parent_id();
    $variation_id = (int) $variation->get_id();
    if (function_exists('intersoccer_get_product_type_safe')) {
        $type = intersoccer_get_product_type_safe($parent_id, $variation_id);
    } elseif (function_exists('intersoccer_get_product_type')) {
        $type = intersoccer_get_product_type($parent_id);
    } else {
        $type = '';
    }

    return intersoccer_roster_move_normalize_activity_type($type);
}

/**
 * Resolve source activity type from the first order line item in a move batch.
 *
 * @param int[] $order_item_ids
 */
function intersoccer_roster_move_source_activity_type(array $order_item_ids): string {
    global $wpdb;

    foreach ($order_item_ids as $item_id) {
        $item_id = (int) $item_id;
        if ($item_id <= 0) {
            continue;
        }

        $order_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d",
            $item_id
        ));
        if ($order_id <= 0) {
            continue;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            continue;
        }

        foreach ($order->get_items('line_item') as $order_item) {
            if ((int) $order_item->get_id() !== $item_id) {
                continue;
            }

            $variation_id = (int) $order_item->get_variation_id();
            $product_id = (int) $order_item->get_product_id();
            if (function_exists('intersoccer_get_product_type_safe')) {
                $type = intersoccer_get_product_type_safe($product_id, $variation_id);
            } elseif (function_exists('intersoccer_get_product_type')) {
                $type = intersoccer_get_product_type($variation_id > 0 ? $variation_id : $product_id);
            } else {
                $type = '';
            }

            return intersoccer_roster_move_normalize_activity_type($type);
        }
    }

    return '';
}

/**
 * Map pa_girls-only slug to a roster gender bucket for cross-gender checks.
 *
 * @return int|null 1 girls-only, 0 explicit regular, null mixed/unset/unknown.
 */
function intersoccer_roster_move_gender_bucket_from_pa_slug(string $slug): ?int {
    $slug = strtolower(trim($slug));
    if ($slug === '' || in_array($slug, ['mixed', 'open', 'any'], true)) {
        return null;
    }
    if (function_exists('intersoccer_pa_girls_only_slug_is_yes') && intersoccer_pa_girls_only_slug_is_yes($slug)) {
        return 1;
    }
    if (in_array($slug, ['yes', 'girls-only', 'girls'], true)) {
        return 1;
    }
    if (in_array($slug, ['no', 'regular', 'mixed-gender', 'not-girls-only'], true)) {
        return 0;
    }

    return null;
}

/**
 * Whether the line item is already on the target variation (no-op move).
 */
function intersoccer_roster_move_is_same_variation_target(int $current_variation_id, int $target_variation_id): bool {
    return $current_variation_id > 0 && $current_variation_id === $target_variation_id;
}

/**
 * Move-only unknown tristate when parent is girls-only but variation pa is unset/mixed.
 *
 * Does not use WC attribute scans (master flag handles those); avoids product-attribute test regressions.
 *
 * @param array<string,mixed> $context
 */
function intersoccer_roster_move_girls_only_tristate_unknown(array $context): bool {
    $normalize_val = static function ($val) {
        if (is_array($val)) {
            $val = $val[0] ?? reset($val);
        }
        return $val;
    };

    $variation_pa_slug = !empty($context['variation_pa_girls-only'])
        ? strtolower(trim((string) $normalize_val($context['variation_pa_girls-only'])))
        : '';
    if ($variation_pa_slug === '' && !empty($context['attribute_pa_girls-only'])) {
        $variation_pa_slug = strtolower(trim((string) $normalize_val($context['attribute_pa_girls-only'])));
    }

    $parent_pa_slug = !empty($context['parent_pa_girls-only'])
        ? strtolower(trim((string) $normalize_val($context['parent_pa_girls-only'])))
        : '';

    if ($variation_pa_slug !== '') {
        return false;
    }

    $parent_face = $parent_pa_slug !== '' && function_exists('intersoccer_roster_girls_only_pa_slug_to_tristate')
        ? intersoccer_roster_girls_only_pa_slug_to_tristate($parent_pa_slug)
        : '';

    $variation_name_signal = !empty($context['product_name'])
        && function_exists('intersoccer_text_indicates_girls_only')
        && intersoccer_text_indicates_girls_only($context['product_name']);

    $product_type_signal = false;
    $product_type = strtolower(trim((string) ($context['product_type'] ?? '')));
    if ($product_type !== '' && strpos($product_type, 'girls') !== false) {
        $product_type_signal = true;
    }
    if (!$product_type_signal) {
        $parent_id = (int) ($context['product_id'] ?? 0);
        $variation_id = (int) ($context['variation_id'] ?? 0);
        if ($parent_id > 0 && function_exists('intersoccer_get_product_type_safe')) {
            $safe_type = strtolower((string) intersoccer_get_product_type_safe($parent_id, $variation_id > 0 ? $variation_id : null));
            if ($safe_type !== '' && strpos($safe_type, 'girls') !== false) {
                $product_type_signal = true;
            }
        }
    }

    return $parent_face === 'yes' && ($product_type_signal || (!$variation_name_signal && !empty($context['parent_product_name'])
        && function_exists('intersoccer_text_indicates_girls_only')
        && intersoccer_text_indicates_girls_only($context['parent_product_name'])));
}

/**
 * Whether admins should confirm moving into a girls-only (or possibly girls-only) target.
 *
 * Warn only when the target resolves to yes or unknown and the source is not girls-only.
 * Girls-only → mixed (e.g. 30712) never warns. allow_cross_gender skips the warning.
 */
function intersoccer_roster_move_cross_gender_warning_needed(string $source_tristate, string $target_tristate, bool $allow_cross_gender): bool {
    if ($allow_cross_gender) {
        return false;
    }
    if ($source_tristate === 'yes') {
        return false;
    }

    return $target_tristate === 'yes' || $target_tristate === 'unknown';
}

/**
 * @param array{block:bool,message?:string,warnings:string[],needs_confirmation:bool} $assessment
 *
 * @return array{block:bool,message?:string,warnings:string[],needs_confirmation:bool}
 */
function intersoccer_roster_move_apply_cross_gender_warnings(
    array $assessment,
    array $order_item_ids,
    WC_Product_Variation $variation,
    bool $allow_cross_gender
): array {
    $source_tristate = intersoccer_roster_move_resolve_source_girls_only_tristate($order_item_ids);
    $target_tristate = intersoccer_roster_move_resolve_variation_girls_only_tristate($variation);
    if (intersoccer_roster_move_cross_gender_warning_needed($source_tristate, $target_tristate, $allow_cross_gender)) {
        $assessment['gender_block'] = true;
    }

    return $assessment;
}

/**
 * Context array for intersoccer_resolve_roster_girls_only_flag() from a target variation.
 *
 * @return array<string,mixed>
 */
function intersoccer_roster_move_variation_girls_only_resolver_context(WC_Product_Variation $variation): array {
    $variation_id = (int) $variation->get_id();
    $parent_id = (int) $variation->get_parent_id();
    $context = [
        'product_id' => $parent_id,
        'variation_id' => $variation_id,
        'product_name' => method_exists($variation, 'get_name') ? $variation->get_name() : '',
    ];

    if ($parent_id > 0 && function_exists('intersoccer_get_product_type_safe')) {
        $context['product_type'] = intersoccer_get_product_type_safe($parent_id, $variation_id);
    }

    foreach ($variation->get_variation_attributes() as $attribute_key => $attribute_value) {
        $clean_key = str_replace('attribute_', '', (string) $attribute_key);
        $val = is_array($attribute_value) ? reset($attribute_value) : $attribute_value;
        if ($clean_key === 'pa_girls-only' || $clean_key === 'girls-only') {
            $context['variation_pa_girls-only'] = $val;
            $context['pa_girls-only'] = $val;
            $context['attribute_pa_girls-only'] = $val;
        }
        if ($clean_key === 'pa_activity-type') {
            $context['activity_type'] = $val;
            $context['raw_activity_type'] = $val;
            $context['pa_activity-type'] = $val;
            $context['Activity Type'] = $val;
        }
    }

    if (is_callable([$variation, 'get_attribute'])) {
        foreach (['pa_girls-only', 'girls-only', 'pa_activity-type'] as $attr_name) {
            $attr_value = $variation->get_attribute($attr_name);
            if ($attr_value === '' || $attr_value === null) {
                continue;
            }
            if ($attr_name === 'pa_activity-type') {
                $context['activity_type'] = $context['activity_type'] ?? $attr_value;
                $context['raw_activity_type'] = $context['raw_activity_type'] ?? $attr_value;
                $context['pa_activity-type'] = $context['pa_activity-type'] ?? $attr_value;
                $context['Activity Type'] = $context['Activity Type'] ?? $attr_value;
            } elseif ($attr_name === 'pa_girls-only' || $attr_name === 'girls-only') {
                $context['variation_pa_girls-only'] = $context['variation_pa_girls-only'] ?? $attr_value;
                $context['pa_girls-only'] = $context['variation_pa_girls-only'];
                $context['attribute_pa_girls-only'] = $context['variation_pa_girls-only'];
            }
        }
    }

    if ($parent_id > 0 && function_exists('wc_get_product')) {
        $parent = wc_get_product($parent_id);
        if ($parent) {
            $context['parent_product_name'] = method_exists($parent, 'get_name') ? $parent->get_name() : '';
            if (is_callable([$parent, 'get_attribute'])) {
                foreach (['pa_girls-only', 'girls-only', 'pa_activity-type'] as $attr_name) {
                    $attr_value = $parent->get_attribute($attr_name);
                    if ($attr_value === '' || $attr_value === null) {
                        continue;
                    }
                    if ($attr_name === 'pa_activity-type') {
                        if (empty($context['activity_type'])) {
                            $context['activity_type'] = $attr_value;
                            $context['raw_activity_type'] = $attr_value;
                            $context['pa_activity-type'] = $attr_value;
                            $context['Activity Type'] = $attr_value;
                        }
                    } else {
                        $context['parent_pa_girls-only'] = $attr_value;
                    }
                }
            }
        }
    }

    return $context;
}

/**
 * Map unified girls-only resolution to cross-gender bucket.
 *
 * @return int|null 1 girls-only, null mixed/unknown/unset
 */
function intersoccer_roster_move_gender_bucket_from_girls_only_tristate(string $tristate): ?int {
    if ($tristate === 'yes') {
        return 1;
    }

    return null;
}

/**
 * pa_girls-only slug to write on order lines from unified resolution.
 */
function intersoccer_roster_move_pa_girls_only_slug_from_tristate(string $tristate): string {
    if ($tristate === 'yes') {
        return 'yes';
    }
    if ($tristate === 'mixed') {
        return 'no';
    }

    return '';
}

/**
 * @param array<string,mixed> $extra_context
 */
function intersoccer_roster_move_resolve_variation_girls_only_tristate(WC_Product_Variation $variation, array $extra_context = []): string {
    if (!function_exists('intersoccer_resolve_roster_girls_only_tristate')) {
        return intersoccer_roster_move_variation_girls_only_legacy($variation) === 1 ? 'yes' : 'mixed';
    }

    $context = array_merge(
        intersoccer_roster_move_variation_girls_only_resolver_context($variation),
        $extra_context
    );

    return intersoccer_resolve_roster_girls_only_tristate($context);
}

/**
 * Resolved girls_only flag (0|1) for roster writes and legacy callers.
 *
 * @param array<string,mixed> $extra_context
 */
function intersoccer_roster_move_resolve_variation_girls_only_flag(WC_Product_Variation $variation, array $extra_context = []): int {
    $tristate = intersoccer_roster_move_resolve_variation_girls_only_tristate($variation, $extra_context);

    return $tristate === 'yes' ? 1 : 0;
}

/**
 * pa_girls-only slug from resolver context array (order-item meta keys).
 *
 * @param array<string,mixed> $context
 */
function intersoccer_roster_move_context_pa_girls_only_slug(array $context): string {
    foreach (['pa_girls-only', 'attribute_pa_girls-only', 'Girls Only'] as $key) {
        if (!empty($context[$key])) {
            return strtolower(trim((string) $context[$key]));
        }
    }

    return '';
}

/**
 * pa_girls-only slug from variation attributes, then variation, then parent product.
 */
function intersoccer_roster_move_variation_pa_girls_only_slug(WC_Product_Variation $variation): string {
    foreach ($variation->get_variation_attributes() as $attribute_key => $attribute_value) {
        $clean_key = str_replace('attribute_', '', (string) $attribute_key);
        if ($clean_key === 'pa_girls-only' || $clean_key === 'girls-only') {
            $val = is_array($attribute_value) ? reset($attribute_value) : $attribute_value;

            return strtolower(trim((string) $val));
        }
    }

    if (is_callable([$variation, 'get_attribute'])) {
        foreach (['pa_girls-only', 'girls-only'] as $attr_name) {
            $attr_value = $variation->get_attribute($attr_name);
            if ($attr_value !== '' && $attr_value !== null) {
                return strtolower(trim((string) $attr_value));
            }
        }
    }

    $parent_id = (int) $variation->get_parent_id();
    if ($parent_id > 0 && function_exists('wc_get_product')) {
        $parent = wc_get_product($parent_id);
        if ($parent && is_callable([$parent, 'get_attribute'])) {
            foreach (['pa_girls-only', 'girls-only'] as $attr_name) {
                $attr_value = $parent->get_attribute($attr_name);
                if ($attr_value !== '' && $attr_value !== null) {
                    return strtolower(trim((string) $attr_value));
                }
            }
        }
    }

    return '';
}

/**
 * Map resolver context to cross-gender bucket (1 / 0 / null).
 *
 * @param array<string,mixed> $context
 * @param bool               $attribute_only When true, only pa_girls-only counts (target variations).
 */
function intersoccer_roster_move_gender_bucket_from_resolver_context(array $context, bool $attribute_only = false): ?int {
    if (!function_exists('intersoccer_resolve_roster_girls_only_tristate')) {
        return null;
    }

    return intersoccer_roster_move_gender_bucket_from_girls_only_tristate(
        intersoccer_resolve_roster_girls_only_tristate($context)
    );
}

/**
 * Gender bucket for cross-gender warnings: 1 girls-only, 0 regular, null mixed/unset.
 */
function intersoccer_roster_move_variation_gender_bucket(WC_Product_Variation $variation): ?int {
    return intersoccer_roster_move_gender_bucket_from_girls_only_tristate(
        intersoccer_roster_move_resolve_variation_girls_only_tristate($variation)
    );
}

/**
 * Legacy 0|1 flag when the shared resolver is unavailable.
 *
 * @param WC_Product_Variation $variation
 */
function intersoccer_roster_move_variation_girls_only_legacy(WC_Product_Variation $variation): int {
    $parent_id = (int) $variation->get_parent_id();
    if ($parent_id > 0 && function_exists('intersoccer_get_product_type_safe')) {
        $type = strtolower((string) intersoccer_get_product_type_safe($parent_id, (int) $variation->get_id()));
        if (strpos($type, 'girls') !== false) {
            return 1;
        }
    }

    return 0;
}

/**
 * @param WC_Product_Variation $variation
 */
function intersoccer_roster_move_variation_girls_only(WC_Product_Variation $variation): int {
    return intersoccer_roster_move_resolve_variation_girls_only_flag($variation);
}

/**
 * @param int[] $order_item_ids
 */
function intersoccer_roster_move_resolve_source_girls_only_tristate(array $order_item_ids): string {
    global $wpdb;

    foreach ($order_item_ids as $item_id) {
        $item_id = (int) $item_id;
        if ($item_id <= 0) {
            continue;
        }

        $activity = wc_get_order_item_meta($item_id, 'Activity Type', true);
        $context = [
            'Activity Type' => $activity,
            'activity_type' => $activity,
            'raw_activity_type' => $activity,
        ];
        foreach (['Girls Only', 'girls_only', 'pa_girls-only', 'attribute_pa_girls-only'] as $meta_key) {
            $val = wc_get_order_item_meta($item_id, $meta_key, true);
            if ($val === '' || $val === null) {
                continue;
            }
            $context[$meta_key] = $val;
            if ($meta_key === 'pa_girls-only' || $meta_key === 'attribute_pa_girls-only') {
                $context['line_pa_girls-only'] = $val;
            }
        }

        $order_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d",
            $item_id
        ));
        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                foreach ($order->get_items('line_item') as $order_item) {
                    if ((int) $order_item->get_id() !== $item_id) {
                        continue;
                    }
                    $variation_id = (int) $order_item->get_variation_id();
                    if ($variation_id > 0) {
                        $variation = wc_get_product($variation_id);
                        if ($variation instanceof WC_Product_Variation) {
                            $context = array_merge(
                                $context,
                                intersoccer_roster_move_variation_girls_only_resolver_context($variation)
                            );
                        }
                    }
                    break;
                }
            }
        }

        if (!function_exists('intersoccer_resolve_roster_girls_only_tristate')) {
            return 'mixed';
        }

        return intersoccer_resolve_roster_girls_only_tristate($context);
    }

    return 'mixed';
}

/**
 * Source girls-only from order item roster meta / variation.
 *
 * @param int[] $order_item_ids
 */
function intersoccer_roster_move_source_gender_bucket(array $order_item_ids): ?int {
    foreach ($order_item_ids as $item_id) {
        $item_id = (int) $item_id;
        if ($item_id <= 0) {
            continue;
        }

        return intersoccer_roster_move_gender_bucket_from_girls_only_tristate(
            intersoccer_roster_move_resolve_source_girls_only_tristate([$item_id])
        );
    }

    return null;
}

/**
 * @param int[] $order_item_ids
 */
function intersoccer_roster_move_source_girls_only(array $order_item_ids): int {
    $bucket = intersoccer_roster_move_source_gender_bucket($order_item_ids);

    return $bucket === 1 ? 1 : 0;
}

/**
 * Count roster rows for capacity checks (excludes cancelled/refunded order_status).
 *
 * @param int $variation_id
 */
function intersoccer_roster_move_active_roster_count(int $variation_id): int {
    global $wpdb;
    if ($variation_id <= 0) {
        return 0;
    }

    $table = $wpdb->prefix . 'intersoccer_rosters';
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table}
         WHERE variation_id = %d
           AND (order_status IS NULL OR order_status NOT IN ('cancelled', 'refunded', 'wc-cancelled', 'wc-refunded'))",
        $variation_id
    ));

    return (int) $count;
}

/**
 * Resolve Y-m-d end date for warning (course _end_date, camp schedule meta).
 *
 * @param WC_Product_Variation $variation
 */
function intersoccer_roster_move_variation_end_date_ymd(WC_Product_Variation $variation): string {
    $variation_id = (int) $variation->get_id();
    $end = '';

    if (function_exists('intersoccer_get_camp_schedule_meta')) {
        $camp = intersoccer_get_camp_schedule_meta($variation_id);
        if (!empty($camp['end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $camp['end'])) {
            $end = (string) $camp['end'];
        }
    }

    if ($end === '') {
        $meta_end = get_post_meta($variation_id, '_camp_end_date', true);
        if (is_string($meta_end) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $meta_end)) {
            $end = $meta_end;
        }
    }

    if ($end === '') {
        $course_end = get_post_meta($variation_id, '_end_date', true);
        if (is_string($course_end) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $course_end)) {
            $end = $course_end;
        }
    }

    return $end;
}

/**
 * Whether a roster table row exists for this order line (required before admin move mutates the order).
 *
 * @param int $order_item_id
 */
function intersoccer_roster_move_variation_is_closed_for_sale(int $variation_id): bool {
    global $wpdb;
    if ($variation_id <= 0) {
        return false;
    }

    $table = $wpdb->prefix . 'intersoccer_rosters';
    $flag = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(event_completed) FROM {$table} WHERE variation_id = %d",
        $variation_id
    ));

    return (int) $flag === 1;
}

function intersoccer_roster_move_order_item_has_roster_row(int $order_item_id): bool {
    global $wpdb;
    if ($order_item_id <= 0) {
        return false;
    }

    $filtered = apply_filters('intersoccer_roster_move_order_item_has_roster_row', null, $order_item_id);
    if ($filtered !== null) {
        return (bool) $filtered;
    }

    $table = $wpdb->prefix . 'intersoccer_rosters';
    $id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE order_item_id = %d LIMIT 1",
        $order_item_id
    ));

    return $id > 0;
}

/**
 * Assess admin move target: hard blocks vs confirmable warnings.
 *
 * @param WC_Product_Variation $variation
 * @param string               $source_activity_type Normalized slug (course, camp, …).
 *
 * @return array{block:bool,message?:string,reason_codes:string[],warnings:string[],needs_confirmation:bool,purchasable:bool,warning_context?:array<string,mixed>}
 */
function intersoccer_assess_roster_move_admin_target(
    WC_Product_Variation $variation,
    string $source_activity_type,
    int $moving_player_count = 1
): array {
    $result = [
        'block' => false,
        'reason_codes' => [],
        'warnings' => [],
        'needs_confirmation' => false,
        'purchasable' => true,
        'warning_context' => [],
    ];

    $variation_id = (int) $variation->get_id();
    $parent_id = (int) $variation->get_parent_id();
    $moving_player_count = max(1, $moving_player_count);

    if ($parent_id <= 0) {
        $result['block'] = true;
        $result['message'] = intersoccer_roster_move_ui_string('target_missing');
        return $result;
    }

    $parent_product = function_exists('wc_get_product') ? wc_get_product($parent_id) : null;
    if (!$parent_product) {
        $result['block'] = true;
        $result['message'] = intersoccer_roster_move_ui_string('target_missing');
        return $result;
    }

    if (intersoccer_roster_move_post_is_trashed($variation_id) || intersoccer_roster_move_post_is_trashed($parent_id)) {
        $result['block'] = true;
        $result['message'] = intersoccer_roster_move_ui_string('target_missing');
        return $result;
    }

    $target_activity = intersoccer_roster_move_target_activity_type($variation);
    if ($source_activity_type === '' || $target_activity === '') {
        $result['block'] = true;
        $result['message'] = __('Could not verify activity type for source and target.', 'intersoccer-reports-rosters');
        return $result;
    }

    if ($source_activity_type !== $target_activity) {
        $result['block'] = true;
        $result['message'] = __('Target variation must be the same activity type as the source (e.g. course to course).', 'intersoccer-reports-rosters');
        return $result;
    }

    $reasons = [];
    $variation_status = intersoccer_roster_move_get_post_status($variation_id);
    $parent_status = intersoccer_roster_move_get_post_status($parent_id);
    if ($variation_status !== 'publish' || $parent_status !== 'publish') {
        $reasons[] = 'private_or_disabled';
    }

    if (method_exists($parent_product, 'get_catalog_visibility')) {
        $visibility = (string) $parent_product->get_catalog_visibility();
        if ($visibility === 'hidden') {
            $reasons[] = 'hidden';
        }
    }

    $price_empty = '' === (string) $variation->get_price();
    if ($price_empty) {
        $reasons[] = 'no_price';
    }

    $warning_context = intersoccer_roster_move_warning_context_for_variation($variation, $moving_player_count);
    $capacity = (int) ($warning_context['capacity'] ?? 0);
    $taken = (int) ($warning_context['taken'] ?? 0);
    $stock_status = (string) $variation->get_stock_status();

    if ($capacity > 0 && $taken >= $capacity) {
        $reasons[] = 'full';
    } elseif ($capacity <= 0 && $stock_status === 'outofstock') {
        $reasons[] = 'out_of_stock';
    }

    if (intersoccer_roster_move_post_is_cancelled($variation_id) || intersoccer_roster_move_post_is_cancelled($parent_id)) {
        $reasons[] = 'closed';
    }

    if (intersoccer_roster_move_variation_is_closed_for_sale($variation_id)) {
        if (!in_array('closed', $reasons, true)) {
            $reasons[] = 'closed';
        }
    }

    $end_ymd = intersoccer_roster_move_variation_end_date_ymd($variation);
    $course_ended = false;
    if ($end_ymd !== '' && function_exists('intersoccer_pm_camp_end_is_past')) {
        $course_ended = intersoccer_pm_camp_end_is_past($end_ymd);
    } elseif ($end_ymd !== '') {
        $today = gmdate('Y-m-d');
        if (function_exists('current_time')) {
            $site_today = current_time('Y-m-d');
            if (is_string($site_today) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $site_today)) {
                $today = $site_today;
            }
        }
        $course_ended = $end_ymd < $today;
    }
    if ($course_ended) {
        $reasons[] = 'course_ended';
    }

    $reasons = array_values(array_unique($reasons));
    $is_purchasable = method_exists($variation, 'is_purchasable') ? (bool) $variation->is_purchasable() : true;
    if (!$is_purchasable && $reasons === []) {
        $reasons[] = 'unknown';
    }

    $result['reason_codes'] = $reasons;
    $result['warnings'] = $reasons;
    $result['warning_context'] = $warning_context;
    $result['purchasable'] = $reasons === [] && $is_purchasable;
    $result['needs_confirmation'] = $reasons !== [];

    return $result;
}

/**
 * Include sibling variations with zero roster rows so admins can move players back to an emptied roster.
 *
 * @param object[] $available_rows Rows from roster-details destination query.
 * @param int      $product_id     Parent product ID.
 * @param int      $exclude_variation_id Current roster variation (never list as target).
 * @param string   $activity_type  Activity label stored on roster rows.
 * @param int      $girls_only     0|1 girls_only facet for same-gender group.
 *
 * @return object[]
 */
function intersoccer_roster_move_merge_empty_sibling_variation_targets(
    array $available_rows,
    int $product_id,
    int $exclude_variation_id,
    string $activity_type,
    int $girls_only
): array {
    if ($product_id <= 0 || !function_exists('wc_get_product')) {
        return $available_rows;
    }

    $parent = wc_get_product($product_id);
    if (!$parent || !is_callable([$parent, 'get_children'])) {
        return $available_rows;
    }

    $indexed = [];
    foreach ($available_rows as $row) {
        $vid = (int) ($row->variation_id ?? 0);
        if ($vid > 0) {
            $indexed[$vid] = $row;
        }
    }

    foreach ((array) $parent->get_children() as $child_id) {
        $child_id = (int) $child_id;
        if ($child_id <= 0 || $child_id === $exclude_variation_id || isset($indexed[$child_id])) {
            continue;
        }

        $variation = wc_get_product($child_id);
        if (!$variation || !method_exists($variation, 'is_type') || !$variation->is_type('variation')) {
            continue;
        }

        if (intersoccer_roster_move_active_roster_count($child_id) > 0) {
            continue;
        }

        $row_girls = function_exists('intersoccer_roster_move_resolve_variation_girls_only_flag')
            ? intersoccer_roster_move_resolve_variation_girls_only_flag($variation)
            : 0;
        if ((int) $girls_only !== (int) $row_girls) {
            continue;
        }

        $indexed[$child_id] = (object) [
            'product_id' => $product_id,
            'variation_id' => $child_id,
            'product_name' => method_exists($variation, 'get_name') ? $variation->get_name() : '',
            'venue' => '',
            'age_group' => '',
            'activity_type' => $activity_type,
            'camp_terms' => '',
            'course_day' => '',
            'times' => '',
            'season' => '',
            'girls_only' => $row_girls,
            'current_players' => 0,
        ];
    }

    return array_values($indexed);
}
