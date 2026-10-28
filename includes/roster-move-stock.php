<?php
/**
 * WooCommerce stock adjustments for admin roster moves.
 *
 * Chained moves: only the latest A→B record is stored. A move back (B→A matching the
 * recorded pair) reverses the recorded applied deltas exactly, then clears the record.
 * Any other destination (B→C) applies a fresh source +1 / destination decrease without
 * reversing an older record (prior sessions keep their earlier stock deltas).
 */

defined('ABSPATH') || exit;

const INTERSOCCER_ROSTER_MOVE_STOCK_DELTA_META = '_intersoccer_roster_move_stock_delta';

if (!function_exists('intersoccer_roster_move_stock_delta_meta_key')) {
    function intersoccer_roster_move_stock_delta_meta_key(): string {
        return INTERSOCCER_ROSTER_MOVE_STOCK_DELTA_META;
    }
}

if (!function_exists('intersoccer_roster_move_read_stock_delta')) {
    /**
     * @return array{from_variation_id:int,to_variation_id:int,applied_from:int,applied_to:int}|null
     */
    function intersoccer_roster_move_read_stock_delta(int $order_item_id): ?array {
        if ($order_item_id <= 0) {
            return null;
        }
        $raw = wc_get_order_item_meta($order_item_id, intersoccer_roster_move_stock_delta_meta_key(), true);
        if ($raw === '' || $raw === null) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'from_variation_id' => (int) ($decoded['from_variation_id'] ?? 0),
            'to_variation_id'   => (int) ($decoded['to_variation_id'] ?? 0),
            'applied_from'      => (int) ($decoded['applied_from'] ?? 0),
            'applied_to'        => (int) ($decoded['applied_to'] ?? 0),
        ];
    }
}

if (!function_exists('intersoccer_roster_move_write_stock_delta')) {
    /**
     * @param array{from_variation_id:int,to_variation_id:int,applied_from:int,applied_to:int} $delta
     */
    function intersoccer_roster_move_write_stock_delta(int $order_item_id, array $delta): void {
        if ($order_item_id <= 0) {
            return;
        }
        $payload = [
            'from_variation_id' => (int) ($delta['from_variation_id'] ?? 0),
            'to_variation_id'   => (int) ($delta['to_variation_id'] ?? 0),
            'applied_from'      => (int) ($delta['applied_from'] ?? 0),
            'applied_to'        => (int) ($delta['applied_to'] ?? 0),
        ];
        $item = new WC_Order_Item_Product($order_item_id);
        $item->update_meta_data(intersoccer_roster_move_stock_delta_meta_key(), wp_json_encode($payload));
        $item->save();
    }
}

if (!function_exists('intersoccer_roster_move_clear_stock_delta')) {
    function intersoccer_roster_move_clear_stock_delta(int $order_item_id): void {
        if ($order_item_id <= 0) {
            return;
        }
        $item = new WC_Order_Item_Product($order_item_id);
        $item->delete_meta_data(intersoccer_roster_move_stock_delta_meta_key());
        $item->save();
    }
}

if (!function_exists('intersoccer_roster_move_resolve_variation_for_stock')) {
    function intersoccer_roster_move_resolve_variation_for_stock(int $variation_id): int {
        if ($variation_id <= 0) {
            return 0;
        }
        if (function_exists('intersoccer_resolve_variation_for_roster')) {
            return (int) intersoccer_resolve_variation_for_roster($variation_id);
        }

        return $variation_id;
    }
}

if (!function_exists('intersoccer_roster_move_stock_increase_applied')) {
    function intersoccer_roster_move_stock_increase_applied(WC_Product_Variation $variation): int {
        if (!$variation->managing_stock()) {
            return 0;
        }

        return 1;
    }
}

if (!function_exists('intersoccer_roster_move_stock_decrease_applied')) {
    /**
     * @return int 0 or -1 (actual delta applied to destination stock)
     */
    function intersoccer_roster_move_stock_decrease_applied(WC_Product_Variation $variation): int {
        if (!$variation->managing_stock()) {
            return 0;
        }
        if ($variation->backorders_allowed()) {
            return -1;
        }
        $remaining = (int) $variation->get_stock_quantity();
        if ($remaining <= 0) {
            return 0;
        }

        return -1;
    }
}

if (!function_exists('intersoccer_roster_move_wc_update_stock')) {
    function intersoccer_roster_move_wc_update_stock(WC_Product_Variation $variation, int $amount, string $mode): bool {
        if ($amount === 0 || !function_exists('wc_update_product_stock')) {
            return true;
        }
        $result = wc_update_product_stock($variation, abs($amount), $mode);

        return $result !== false && $result !== null;
    }
}

if (!function_exists('intersoccer_roster_move_apply_stock_delta_on_variation')) {
    /**
     * @param int $signed_units Positive = increase stock, negative = decrease.
     */
    function intersoccer_roster_move_apply_stock_delta_on_variation(int $variation_id, int $signed_units): bool {
        if ($variation_id <= 0 || $signed_units === 0) {
            return true;
        }
        $product = wc_get_product($variation_id);
        if (!$product instanceof WC_Product_Variation) {
            return false;
        }
        if (!$product->managing_stock()) {
            return true;
        }
        $mode = $signed_units > 0 ? 'increase' : 'decrease';

        return intersoccer_roster_move_wc_update_stock($product, abs($signed_units), $mode);
    }
}

if (!function_exists('intersoccer_roster_move_reverse_stock_delta')) {
    /**
     * @param array{from_variation_id:int,to_variation_id:int,applied_from:int,applied_to:int} $delta
     */
    function intersoccer_roster_move_reverse_stock_delta(array $delta): bool {
        $ok = true;
        if ((int) ($delta['applied_from'] ?? 0) > 0) {
            $ok = intersoccer_roster_move_apply_stock_delta_on_variation(
                (int) $delta['from_variation_id'],
                -(int) $delta['applied_from']
            ) && $ok;
        }
        if ((int) ($delta['applied_to'] ?? 0) < 0) {
            $ok = intersoccer_roster_move_apply_stock_delta_on_variation(
                (int) $delta['to_variation_id'],
                -(int) $delta['applied_to']
            ) && $ok;
        }

        return $ok;
    }
}

if (!function_exists('intersoccer_roster_move_apply_stock_for_completed_move')) {
    /**
     * Adjust managed stock after a successful roster move. Returns false when WC stock update fails.
     */
    function intersoccer_roster_move_apply_stock_for_completed_move(
        int $order_item_id,
        int $original_variation_id,
        int $target_variation_id
    ): bool {
        $from_id = intersoccer_roster_move_resolve_variation_for_stock($original_variation_id);
        $to_id = intersoccer_roster_move_resolve_variation_for_stock($target_variation_id);
        if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) {
            return true;
        }

        $existing = intersoccer_roster_move_read_stock_delta($order_item_id);
        if ($existing
            && (int) $existing['to_variation_id'] === $from_id
            && (int) $existing['from_variation_id'] === $to_id) {
            $ok = intersoccer_roster_move_reverse_stock_delta($existing);
            if ($ok) {
                intersoccer_roster_move_clear_stock_delta($order_item_id);
            }

            return $ok;
        }

        $from_product = wc_get_product($from_id);
        $to_product = wc_get_product($to_id);
        if (!$from_product instanceof WC_Product_Variation || !$to_product instanceof WC_Product_Variation) {
            return false;
        }

        $applied_from = intersoccer_roster_move_stock_increase_applied($from_product);
        $applied_to = intersoccer_roster_move_stock_decrease_applied($to_product);

        if ($applied_from > 0) {
            if (!intersoccer_roster_move_wc_update_stock($from_product, $applied_from, 'increase')) {
                return false;
            }
        }
        if ($applied_to < 0) {
            if (!intersoccer_roster_move_wc_update_stock($to_product, abs($applied_to), 'decrease')) {
                if ($applied_from > 0) {
                    intersoccer_roster_move_apply_stock_delta_on_variation($from_id, -$applied_from);
                }

                return false;
            }
        }

        intersoccer_roster_move_write_stock_delta($order_item_id, [
            'from_variation_id' => $from_id,
            'to_variation_id'   => $to_id,
            'applied_from'      => $applied_from,
            'applied_to'        => $applied_to,
        ]);

        return true;
    }
}
