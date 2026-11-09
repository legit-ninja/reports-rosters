<?php
/**
 * PHPUnit / QA-only helpers (never loaded in production).
 *
 * @package InterSoccer_Reports_Rosters
 */

if (!defined('INTERSOCCER_TESTING') || !INTERSOCCER_TESTING) {
    return;
}

if (!function_exists('intersoccer_migration_seed_order_item_pa_slug_meta_from_variation')) {
    /**
     * QA helper: align stored pa_* keys with Woo variation attribute slugs.
     */
    function intersoccer_migration_seed_order_item_pa_slug_meta_from_variation(int $item_id, int $variation_id): void {
        if ($item_id <= 0 || $variation_id <= 0 || !function_exists('wc_get_product')) {
            return;
        }
        $variation = wc_get_product($variation_id);
        if (!$variation instanceof WC_Product_Variation) {
            return;
        }
        $slug_meta = [];
        if (function_exists('intersoccer_build_migration_meta_updates')) {
            $payload = intersoccer_build_migration_meta_updates($variation);
            $slug_meta = $payload['slug_meta'] ?? [];
        }
        if (function_exists('intersoccer_migration_normalize_slug_meta_for_variation')) {
            $slug_meta = intersoccer_migration_normalize_slug_meta_for_variation($slug_meta, $variation);
        }
        if ($slug_meta !== []) {
            intersoccer_migration_apply_slug_meta_for_move($item_id, $slug_meta);
        }
    }
}
