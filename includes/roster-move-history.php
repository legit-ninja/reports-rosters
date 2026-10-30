<?php
/**
 * Append-only per order-line roster history (hidden order item meta).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') || exit;

const INTERSOCCER_ROSTER_HISTORY_SCHEMA_VERSION = 1;
const INTERSOCCER_ROSTER_HISTORY_META = '_intersoccer_roster_history';

if (!function_exists('intersoccer_roster_history_meta_key')) {
    function intersoccer_roster_history_meta_key(): string {
        return INTERSOCCER_ROSTER_HISTORY_META;
    }
}

if (!function_exists('intersoccer_roster_history_register_hidden_meta')) {
    function intersoccer_roster_history_register_hidden_meta(): void {
        add_filter(
            'woocommerce_hidden_order_itemmeta',
            static function ($hidden) {
                if (!is_array($hidden)) {
                    $hidden = [];
                }
                $hidden[] = intersoccer_roster_history_meta_key();

                return $hidden;
            }
        );
    }
    intersoccer_roster_history_register_hidden_meta();
}

if (!function_exists('intersoccer_roster_history_decode_entries')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_roster_history_decode_entries($raw): array {
        if ($raw === '' || $raw === null) {
            return [];
        }
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        if (isset($decoded['entries']) && is_array($decoded['entries'])) {
            return $decoded['entries'];
        }

        return array_values($decoded);
    }
}

if (!function_exists('intersoccer_roster_history_encode_entries')) {
    /**
     * @param array<int,array<string,mixed>> $entries
     */
    function intersoccer_roster_history_encode_entries(array $entries): string {
        $payload = [
            'v' => INTERSOCCER_ROSTER_HISTORY_SCHEMA_VERSION,
            'entries' => array_values($entries),
        ];

        return function_exists('wp_json_encode')
            ? (string) wp_json_encode($payload)
            : (string) json_encode($payload);
    }
}

if (!function_exists('intersoccer_roster_history_read_raw')) {
    function intersoccer_roster_history_read_raw(int $order_item_id): string {
        if ($order_item_id <= 0) {
            return '';
        }

        return (string) wc_get_order_item_meta($order_item_id, intersoccer_roster_history_meta_key(), true);
    }
}

if (!function_exists('intersoccer_roster_history_write_entries')) {
    /**
     * @param array<int,array<string,mixed>> $entries
     */
    function intersoccer_roster_history_write_entries(int $order_item_id, array $entries): void {
        if ($order_item_id <= 0) {
            return;
        }
        $item = new WC_Order_Item_Product($order_item_id);
        $item->update_meta_data(intersoccer_roster_history_meta_key(), intersoccer_roster_history_encode_entries($entries));
        $item->save();
    }
}

if (!function_exists('intersoccer_roster_history_has_origin')) {
    function intersoccer_roster_history_has_origin(array $entries): bool {
        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['type'] ?? '') === 'origin') {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('intersoccer_roster_history_next_seq')) {
    function intersoccer_roster_history_next_seq(array $entries): int {
        $max = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $max = max($max, (int) ($entry['seq'] ?? 0));
        }

        return $max + 1;
    }
}

if (!function_exists('intersoccer_roster_history_resolve_variation_id')) {
    function intersoccer_roster_history_resolve_variation_id(int $variation_id): int {
        if ($variation_id <= 0) {
            return 0;
        }
        if (function_exists('intersoccer_resolve_variation_for_roster')) {
            return (int) intersoccer_resolve_variation_for_roster($variation_id);
        }

        return $variation_id;
    }
}

if (!function_exists('intersoccer_roster_history_roster_row_for_item')) {
    /**
     * @return array<string,mixed>|null
     */
    function intersoccer_roster_history_roster_row_for_item(int $order_item_id): ?array {
        if ($order_item_id <= 0) {
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_item_id = %d LIMIT 1",
                $order_item_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('intersoccer_roster_history_order_date_ymd')) {
    function intersoccer_roster_history_order_date_ymd(int $order_id, array $roster_row = []): string {
        if (!empty($roster_row['order_date'])) {
            $ts = strtotime((string) $roster_row['order_date']);

            return $ts ? gmdate('Y-m-d', $ts) : (string) $roster_row['order_date'];
        }
        if ($order_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && method_exists($order, 'get_date_created')) {
                $created = $order->get_date_created();
                if ($created && method_exists($created, 'date')) {
                    return gmdate('Y-m-d', strtotime($created->date('Y-m-d H:i:s')));
                }
            }
        }

        return '';
    }
}

if (!function_exists('intersoccer_roster_history_format_dates_label')) {
    /**
     * @param array<string,mixed> $row
     */
    function intersoccer_roster_history_format_dates_label(array $row): string {
        $parts = [];
        $start = trim((string) ($row['start_date'] ?? ''));
        $end = trim((string) ($row['end_date'] ?? ''));
        if ($start !== '' && $start !== '1970-01-01') {
            $parts[] = 'Start ' . $start;
        }
        if ($end !== '' && $end !== '1970-01-01') {
            $parts[] = 'End ' . $end;
        }
        $booking = trim((string) ($row['booking_type'] ?? ''));
        $selected = trim((string) ($row['selected_days'] ?? $row['days_selected'] ?? ''));
        if ($selected !== '') {
            $parts[] = 'Days ' . $selected;
        } elseif ($booking !== '') {
            $parts[] = $booking;
        }
        $course_day = trim((string) ($row['course_day'] ?? ''));
        if ($course_day !== '') {
            $parts[] = 'Course day ' . $course_day;
        }
        $camp_terms = trim((string) ($row['camp_terms'] ?? ''));
        if ($camp_terms !== '') {
            $parts[] = 'Camp term ' . $camp_terms;
        }

        return implode('; ', $parts);
    }
}

if (!function_exists('intersoccer_roster_history_build_label_from_row')) {
    /**
     * @param array<string,mixed> $row
     * @return array{product:string,variation:string,dates:string}
     */
    function intersoccer_roster_history_build_label_from_row(array $row): array {
        $product = trim((string) ($row['product_name'] ?? ''));
        if ($product === '' && !empty($row['product_id']) && function_exists('intersoccer_get_english_product_name')) {
            $product = (string) intersoccer_get_english_product_name('', (int) $row['product_id']);
        }
        if ($product === '' && !empty($row['variation_id']) && function_exists('wc_get_product')) {
            $variation = wc_get_product((int) $row['variation_id']);
            if ($variation && method_exists($variation, 'get_name')) {
                $parent_id = method_exists($variation, 'get_parent_id') ? (int) $variation->get_parent_id() : 0;
                $product = function_exists('intersoccer_get_english_product_name')
                    ? (string) intersoccer_get_english_product_name($variation->get_name(), $parent_id)
                    : (string) $variation->get_name();
            }
        }
        if ($product === '') {
            $product = 'Roster product';
        }

        $variation_parts = [];
        foreach (['venue', 'age_group', 'times', 'course_day', 'camp_terms', 'season'] as $field) {
            $val = trim((string) ($row[$field] ?? ''));
            if ($val !== '' && strcasecmp($val, 'N/A') !== 0) {
                $variation_parts[] = $val;
            }
        }
        $variation_label = $variation_parts !== [] ? implode(' | ', $variation_parts) : 'Variation attributes';

        return [
            'product'   => $product,
            'variation' => $variation_label,
            'dates'     => intersoccer_roster_history_format_dates_label($row),
        ];
    }
}

if (!function_exists('intersoccer_roster_history_roster_side_from_row')) {
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    function intersoccer_roster_history_roster_side_from_row(array $row): array {
        $variation_id = intersoccer_roster_history_resolve_variation_id((int) ($row['variation_id'] ?? 0));

        return [
            'variation_id'    => $variation_id,
            'product_id'      => (int) ($row['product_id'] ?? 0),
            'event_signature' => (string) ($row['event_signature'] ?? ''),
            'booking_type'    => (string) ($row['booking_type'] ?? ''),
            'selected_days'   => (string) ($row['selected_days'] ?? ''),
            'days_selected'   => (string) ($row['days_selected'] ?? ''),
            'start_date'      => (string) ($row['start_date'] ?? ''),
            'end_date'        => (string) ($row['end_date'] ?? ''),
            'camp_terms'      => (string) ($row['camp_terms'] ?? ''),
            'course_day'      => (string) ($row['course_day'] ?? ''),
            'label'           => intersoccer_roster_history_build_label_from_row($row),
        ];
    }
}

if (!function_exists('intersoccer_roster_history_snapshot_from_item')) {
    /**
     * @return array<string,mixed>|null
     */
    function intersoccer_roster_history_snapshot_from_item(int $order_item_id): ?array {
        $row = intersoccer_roster_history_roster_row_for_item($order_item_id);
        if (!$row) {
            return null;
        }

        return intersoccer_roster_history_roster_side_from_row($row);
    }
}

if (!function_exists('intersoccer_roster_history_legacy_backfill_context')) {
    /**
     * @return array{origin_variation_id:int,note:string}
     */
    function intersoccer_roster_history_legacy_backfill_context(
        int $order_item_id,
        int $exclude_stock_delta_from_variation_id = 0
    ): array {
        $origin_variation_id = 0;
        $note = '';

        if (function_exists('intersoccer_roster_move_read_stock_delta')) {
            $delta = intersoccer_roster_move_read_stock_delta($order_item_id);
            if ($delta && (int) ($delta['from_variation_id'] ?? 0) > 0) {
                $delta_from = intersoccer_roster_history_resolve_variation_id((int) $delta['from_variation_id']);
                $exclude = $exclude_stock_delta_from_variation_id > 0
                    ? intersoccer_roster_history_resolve_variation_id($exclude_stock_delta_from_variation_id)
                    : 0;
                if ($exclude <= 0 || $delta_from !== $exclude) {
                    $origin_variation_id = $delta_from;
                    $note = 'Origin variation inferred from an earlier roster move stock delta (pre-move side).';
                }
            }
        }

        if ($origin_variation_id <= 0 && function_exists('intersoccer_roster_move_audit_latest_meta_keys')) {
            $keys = intersoccer_roster_move_audit_latest_meta_keys();
            $from = (int) wc_get_order_item_meta($order_item_id, $keys['from_variation_id'], true);
            if ($from > 0) {
                $origin_variation_id = intersoccer_roster_history_resolve_variation_id($from);
                $note = 'Origin variation inferred from latest roster move audit meta (from variation).';
            }
        }

        if ($origin_variation_id <= 0 && function_exists('intersoccer_roster_move_audit_history_meta_key')
            && function_exists('intersoccer_roster_move_audit_decode_history')) {
            $history = intersoccer_roster_move_audit_decode_history(
                wc_get_order_item_meta($order_item_id, intersoccer_roster_move_audit_history_meta_key(), true)
            );
            if ($history !== [] && isset($history[0]['from_variation_id'])) {
                $origin_variation_id = intersoccer_roster_history_resolve_variation_id((int) $history[0]['from_variation_id']);
                $note = 'Origin variation inferred from roster move audit history (first move from side).';
            }
        }

        return [
            'origin_variation_id' => $origin_variation_id,
            'note'                => $note,
        ];
    }
}

if (!function_exists('intersoccer_roster_history_build_origin_entry')) {
    /**
     * @param array<string,mixed> $roster_row
     * @return array<string,mixed>
     */
    function intersoccer_roster_history_build_origin_entry(
        int $order_id,
        int $order_item_id,
        array $roster_row,
        bool $backfilled,
        string $note = ''
    ): array {
        $side = intersoccer_roster_history_roster_side_from_row($roster_row);
        if ($backfilled) {
            $legacy = intersoccer_roster_history_legacy_backfill_context($order_item_id);
            if ((int) $legacy['origin_variation_id'] > 0) {
                $side['variation_id'] = (int) $legacy['origin_variation_id'];
                if ($note === '' && $legacy['note'] !== '') {
                    $note = $legacy['note'];
                }
            }
        }

        return [
            'v'               => INTERSOCCER_ROSTER_HISTORY_SCHEMA_VERSION,
            'seq'             => 1,
            'type'            => 'origin',
            'at'              => gmdate('Y-m-d\TH:i:s\Z'),
            'order_id'        => $order_id,
            'order_item_id'   => $order_item_id,
            'product_id'      => (int) ($roster_row['product_id'] ?? 0),
            'variation_id'    => (int) $side['variation_id'],
            'event_signature' => (string) $side['event_signature'],
            'booking_type'    => (string) $side['booking_type'],
            'selected_days'   => (string) $side['selected_days'],
            'days_selected'   => (string) $side['days_selected'],
            'start_date'      => (string) $side['start_date'],
            'end_date'        => (string) $side['end_date'],
            'camp_terms'      => (string) $side['camp_terms'],
            'course_day'      => (string) $side['course_day'],
            'order_date'      => intersoccer_roster_history_order_date_ymd($order_id, $roster_row),
            'backfilled'      => $backfilled,
            'note'            => $note,
            'label'           => $side['label'],
        ];
    }
}

if (!function_exists('intersoccer_roster_history_build_origin_entry_from_side')) {
    /**
     * Origin entry from a pre-captured roster side (preserves frozen label and IDs as-is).
     *
     * @param array<string,mixed> $from_side
     * @param array<string,mixed> $roster_row_for_order_date
     * @return array<string,mixed>
     */
    function intersoccer_roster_history_build_origin_entry_from_side(
        int $order_id,
        int $order_item_id,
        array $from_side,
        bool $backfilled,
        string $note = '',
        array $roster_row_for_order_date = []
    ): array {
        $label = is_array($from_side['label'] ?? null) ? $from_side['label'] : [];

        return [
            'v'               => INTERSOCCER_ROSTER_HISTORY_SCHEMA_VERSION,
            'seq'             => 1,
            'type'            => 'origin',
            'at'              => gmdate('Y-m-d\TH:i:s\Z'),
            'order_id'        => $order_id,
            'order_item_id'   => $order_item_id,
            'product_id'      => (int) ($from_side['product_id'] ?? 0),
            'variation_id'    => (int) ($from_side['variation_id'] ?? 0),
            'event_signature' => (string) ($from_side['event_signature'] ?? ''),
            'booking_type'    => (string) ($from_side['booking_type'] ?? ''),
            'selected_days'   => (string) ($from_side['selected_days'] ?? ''),
            'days_selected'   => (string) ($from_side['days_selected'] ?? ''),
            'start_date'      => (string) ($from_side['start_date'] ?? ''),
            'end_date'        => (string) ($from_side['end_date'] ?? ''),
            'camp_terms'      => (string) ($from_side['camp_terms'] ?? ''),
            'course_day'      => (string) ($from_side['course_day'] ?? ''),
            'order_date'      => intersoccer_roster_history_order_date_ymd($order_id, $roster_row_for_order_date),
            'backfilled'      => $backfilled,
            'note'            => $note,
            'label'           => [
                'product'   => (string) ($label['product'] ?? ''),
                'variation' => (string) ($label['variation'] ?? ''),
                'dates'     => (string) ($label['dates'] ?? ''),
            ],
        ];
    }
}

if (!function_exists('intersoccer_roster_history_roster_row_from_side')) {
    /**
     * @param array<string,mixed> $side
     * @return array<string,mixed>
     */
    function intersoccer_roster_history_roster_row_from_side(array $side, int $order_id, int $order_item_id): array {
        $label = is_array($side['label'] ?? null) ? $side['label'] : [];
        $row = [
            'order_id'        => $order_id,
            'order_item_id'   => $order_item_id,
            'variation_id'    => (int) ($side['variation_id'] ?? 0),
            'product_id'      => (int) ($side['product_id'] ?? 0),
            'event_signature' => (string) ($side['event_signature'] ?? ''),
            'booking_type'    => (string) ($side['booking_type'] ?? ''),
            'selected_days'   => (string) ($side['selected_days'] ?? ''),
            'days_selected'   => (string) ($side['days_selected'] ?? ''),
            'start_date'      => (string) ($side['start_date'] ?? ''),
            'end_date'        => (string) ($side['end_date'] ?? ''),
            'camp_terms'      => (string) ($side['camp_terms'] ?? ''),
            'course_day'      => (string) ($side['course_day'] ?? ''),
        ];
        $product = trim((string) ($label['product'] ?? ''));
        if ($product !== '') {
            $row['product_name'] = $product;
        }

        return $row;
    }
}

if (!function_exists('intersoccer_roster_history_roster_row_for_variation_inference')) {
    /**
     * Build a roster-shaped row for an earlier variation (no mixing with the current from-side row).
     *
     * @param array<string,mixed> $anchor_row Current roster row on the order line (order ids, booking context).
     * @return array<string,mixed>|null Null when the variation cannot be loaded.
     */
    function intersoccer_roster_history_roster_row_for_variation_inference(
        int $variation_id,
        int $order_item_id,
        array $anchor_row
    ): ?array {
        $resolved = intersoccer_roster_history_resolve_variation_id($variation_id);
        if ($resolved <= 0 || !function_exists('wc_get_product')) {
            return null;
        }
        $variation = wc_get_product($resolved);
        if (!$variation || !method_exists($variation, 'is_type') || !$variation->is_type('variation')) {
            return null;
        }

        $parent_id = (int) $variation->get_parent_id();
        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $peer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE variation_id = %d AND order_item_id != %d ORDER BY updated_at DESC, id DESC LIMIT 1",
                $resolved,
                $order_item_id
            ),
            ARRAY_A
        );

        $row = is_array($peer) ? $peer : [];
        if ($row === []) {
            $row = $anchor_row;
        }

        $row['order_id'] = (int) ($anchor_row['order_id'] ?? $row['order_id'] ?? 0);
        $row['order_item_id'] = $order_item_id;
        $row['variation_id'] = $resolved;
        $row['product_id'] = $parent_id;

        if (trim((string) ($row['event_signature'] ?? '')) === '') {
            $existing_signature = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT event_signature FROM {$table} WHERE variation_id = %d AND event_signature != '' AND order_item_id != %d ORDER BY updated_at DESC, id DESC LIMIT 1",
                    $resolved,
                    $order_item_id
                )
            );
            if (!empty($existing_signature)) {
                $row['event_signature'] = (string) $existing_signature;
            }
        }

        if (trim((string) ($row['product_name'] ?? '')) === '') {
            $row['product_name'] = function_exists('intersoccer_get_english_product_name')
                ? (string) intersoccer_get_english_product_name($variation->get_name(), $parent_id)
                : (string) $variation->get_name();
        }

        foreach (['venue', 'age_group', 'times', 'course_day', 'camp_terms', 'season'] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                continue;
            }
            if (!method_exists($variation, 'get_meta')) {
                continue;
            }
            $meta_val = trim((string) $variation->get_meta($field, true));
            if ($meta_val !== '') {
                $row[$field] = $meta_val;
            }
        }

        return $row;
    }
}

if (!function_exists('intersoccer_roster_history_roster_side_from_variation_inference')) {
    /**
     * @param array<string,mixed> $anchor_row
     * @return array<string,mixed>|null
     */
    function intersoccer_roster_history_roster_side_from_variation_inference(
        int $variation_id,
        int $order_item_id,
        array $anchor_row
    ): ?array {
        $row = intersoccer_roster_history_roster_row_for_variation_inference($variation_id, $order_item_id, $anchor_row);
        if ($row === null) {
            return null;
        }

        return intersoccer_roster_history_roster_side_from_row($row);
    }
}

if (!function_exists('intersoccer_roster_history_first_move_origin_context')) {
    /**
     * Note and backfill flags for the origin entry written on the first roster move.
     *
     * @param array<string,mixed> $from_side Pre-move roster side captured for the move.
     * @return array{backfilled:bool,note:string,from_side:array<string,mixed>}
     */
    function intersoccer_roster_history_first_move_origin_context(int $order_item_id, array $from_side): array {
        $exclude_variation = (int) ($from_side['variation_id'] ?? 0);
        $legacy = intersoccer_roster_history_legacy_backfill_context($order_item_id, $exclude_variation);
        $backfilled = false;
        $origin_side = $from_side;
        $note = '';

        $legacy_variation = (int) ($legacy['origin_variation_id'] ?? 0);
        if ($legacy_variation > 0) {
            $backfilled = true;
            $from_variation = intersoccer_roster_history_resolve_variation_id($exclude_variation);
            $resolved_legacy = intersoccer_roster_history_resolve_variation_id($legacy_variation);
            $label_source = $from_side;

            if ($resolved_legacy !== $from_variation) {
                $anchor_row = intersoccer_roster_history_roster_row_for_item($order_item_id);
                if (!is_array($anchor_row) || $anchor_row === []) {
                    $order_id = 0;
                    if (function_exists('wc_get_order_id_by_order_item_id')) {
                        $order_id = (int) wc_get_order_id_by_order_item_id($order_item_id);
                    }
                    $anchor_row = intersoccer_roster_history_roster_row_from_side($from_side, $order_id, $order_item_id);
                }
                $rebuilt = intersoccer_roster_history_roster_side_from_variation_inference(
                    $resolved_legacy,
                    $order_item_id,
                    $anchor_row
                );
                if ($rebuilt !== null) {
                    $origin_side = $rebuilt;
                    $label_source = $rebuilt;
                }
            }

            $label_line = intersoccer_roster_history_format_label_line($label_source);
            $note = $label_line !== ''
                ? 'Original booking inferred from an earlier move: ' . $label_line
                : 'Original booking inferred from an earlier move.';
        } else {
            $label_line = intersoccer_roster_history_format_label_line($from_side);
            $note = $label_line !== ''
                ? 'Original booking before first roster move: ' . $label_line
                : 'Original booking before first roster move.';
        }

        return [
            'backfilled' => $backfilled,
            'note'       => $note,
            'from_side'  => $origin_side,
        ];
    }
}

if (!function_exists('intersoccer_roster_history_persist_backfill_origin_from_side')) {
    /**
     * Write a single origin from the pre-move roster side (first move on the line).
     *
     * @param array<string,mixed> $from_side
     */
    function intersoccer_roster_history_persist_backfill_origin_from_side(
        int $order_item_id,
        int $order_id,
        array $from_side
    ): void {
        if ($order_item_id <= 0 || $from_side === []) {
            return;
        }
        $entries = intersoccer_roster_history_decode_entries(intersoccer_roster_history_read_raw($order_item_id));
        if (intersoccer_roster_history_has_origin($entries)) {
            return;
        }
        $row = intersoccer_roster_history_roster_row_from_side($from_side, $order_id, $order_item_id);
        $context = intersoccer_roster_history_first_move_origin_context($order_item_id, $from_side);
        $entries[] = intersoccer_roster_history_build_origin_entry_from_side(
            $order_id,
            $order_item_id,
            $context['from_side'],
            (bool) $context['backfilled'],
            (string) $context['note'],
            $row
        );
        intersoccer_roster_history_write_entries($order_item_id, $entries);
    }
}

if (!function_exists('intersoccer_roster_history_get_entries')) {
    /**
     * Persisted history entries only (never writes).
     *
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_roster_history_get_entries(int $order_item_id): array {
        if ($order_item_id <= 0) {
            return [];
        }

        return intersoccer_roster_history_decode_entries(intersoccer_roster_history_read_raw($order_item_id));
    }
}

if (!function_exists('intersoccer_roster_history_get_entries_for_display')) {
    /**
     * Stored entries plus a computed (non-persisted) origin when none exists yet.
     *
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_roster_history_get_entries_for_display(int $order_item_id): array {
        $entries = intersoccer_roster_history_get_entries($order_item_id);
        if (intersoccer_roster_history_has_origin($entries)) {
            return $entries;
        }
        $row = intersoccer_roster_history_roster_row_for_item($order_item_id);
        if (!$row) {
            return $entries;
        }
        $order_id = (int) ($row['order_id'] ?? 0);
        if ($order_id <= 0 && function_exists('wc_get_order_id_by_order_item_id')) {
            $order_id = (int) wc_get_order_id_by_order_item_id($order_item_id);
        }
        $virtual = intersoccer_roster_history_build_origin_entry($order_id, $order_item_id, $row, false, '');
        $moves = array_values(array_filter($entries, static function ($entry) {
            return is_array($entry) && (($entry['type'] ?? '') !== 'origin');
        }));

        return array_merge([$virtual], $moves);
    }
}

if (!function_exists('intersoccer_roster_history_append_move')) {
    /**
     * @param array<string,mixed>|null $from_side
     * @param array<string,mixed>|null $to_side
     * @param array<string,mixed>      $context
     */
    function intersoccer_roster_history_append_move(
        int $order_item_id,
        int $order_id,
        ?array $from_side,
        ?array $to_side,
        array $context = []
    ): void {
        if ($order_item_id <= 0 || !$from_side || !$to_side) {
            return;
        }
        if (!intersoccer_roster_history_has_origin(
            intersoccer_roster_history_decode_entries(intersoccer_roster_history_read_raw($order_item_id))
        )) {
            intersoccer_roster_history_persist_backfill_origin_from_side($order_item_id, $order_id, $from_side);
        }
        $entries = intersoccer_roster_history_get_entries($order_item_id);
        $user_id = (int) ($context['actor_user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0));
        $display_name = (string) ($context['actor_display_name'] ?? '');
        if ($display_name === '' && $user_id > 0 && function_exists('get_userdata')) {
            $user = get_userdata($user_id);
            if ($user && !empty($user->display_name)) {
                $display_name = (string) $user->display_name;
            }
        }
        if ($display_name === '' && $user_id > 0) {
            $display_name = 'user ' . $user_id;
        }
        $overridden = $context['overridden_reasons'] ?? [];
        if (!is_array($overridden)) {
            $overridden = [];
        }
        $overridden = array_values(array_unique(array_filter(array_map('strval', $overridden))));
        $move_anyway = (bool) ($context['move_anyway'] ?? ($overridden !== []));
        $stock = $context['stock'] ?? null;
        if (!is_array($stock) && isset($context['applied_from'], $context['applied_to'])) {
            $stock = [
                'applied_from' => (int) $context['applied_from'],
                'applied_to'   => (int) $context['applied_to'],
            ];
        }
        if (!is_array($stock)) {
            $stock = ['applied_from' => 0, 'applied_to' => 0];
        }

        $entries[] = [
            'v'                  => INTERSOCCER_ROSTER_HISTORY_SCHEMA_VERSION,
            'seq'                => intersoccer_roster_history_next_seq($entries),
            'type'               => 'move',
            'at'                 => (string) ($context['at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            'order_id'           => $order_id,
            'order_item_id'      => $order_item_id,
            'from'               => $from_side,
            'to'                 => $to_side,
            'actor_user_id'      => $user_id,
            'actor_display_name' => $display_name,
            'move_anyway'        => $move_anyway,
            'overridden_reasons' => $overridden,
            'stock'              => [
                'applied_from' => (int) ($stock['applied_from'] ?? 0),
                'applied_to'   => (int) ($stock['applied_to'] ?? 0),
            ],
        ];
        intersoccer_roster_history_write_entries($order_item_id, $entries);
    }
}

if (!function_exists('intersoccer_roster_history_render_admin_cell')) {
    function intersoccer_roster_history_render_admin_cell(int $order_item_id): string {
        if ($order_item_id <= 0) {
            return '';
        }
        if (function_exists('intersoccer_user_can_view_roster_admin')
            && !intersoccer_user_can_view_roster_admin()) {
            return '';
        }
        $entries = intersoccer_roster_history_get_entries_for_display($order_item_id);
        if ($entries === []) {
            return '<span class="isrr-roster-history-empty">—</span>';
        }

        $html = '<details class="isrr-roster-history">';
        $html .= '<summary>History</summary>';
        $html .= '<ol class="isrr-roster-history-list">';
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = (string) ($entry['type'] ?? 'entry');
            $html .= '<li>';
            $html .= '<strong>' . esc_html(ucfirst($type)) . '</strong> ';
            $html .= esc_html((string) ($entry['at'] ?? ''));
            if ($type === 'origin') {
                $html .= '<div>' . esc_html(intersoccer_roster_history_format_origin_summary($entry)) . '</div>';
            } elseif ($type === 'move') {
                $html .= '<div>' . esc_html(intersoccer_roster_history_format_move_summary($entry)) . '</div>';
            } else {
                $html .= '<div>' . esc_html('Recorded entry type ' . $type) . '</div>';
            }
            $html .= '</li>';
        }
        $html .= '</ol></details>';

        return $html;
    }
}

if (!function_exists('intersoccer_roster_history_format_label_line')) {
    /**
     * @param array<string,mixed> $side
     */
    function intersoccer_roster_history_format_label_line(array $side): string {
        $label = is_array($side['label'] ?? null) ? $side['label'] : [];
        $product = trim((string) ($label['product'] ?? ''));
        $variation = trim((string) ($label['variation'] ?? ''));
        $dates = trim((string) ($label['dates'] ?? ''));
        $parts = [];
        if ($product !== '') {
            $parts[] = $product;
        }
        if ($variation !== '') {
            $parts[] = $variation;
        }
        if ($dates !== '') {
            $parts[] = $dates;
        }
        if ($parts === []) {
            $parts[] = 'Variation ' . (int) ($side['variation_id'] ?? 0);
        }

        return implode(' — ', $parts);
    }
}

if (!function_exists('intersoccer_roster_history_format_origin_summary')) {
    /**
     * @param array<string,mixed> $entry
     */
    function intersoccer_roster_history_format_origin_summary(array $entry): string {
        $parts = [
            'Order ' . (int) ($entry['order_id'] ?? 0),
            'Item ' . (int) ($entry['order_item_id'] ?? 0),
            'Variation ' . (int) ($entry['variation_id'] ?? 0),
        ];
        if (!empty($entry['backfilled'])) {
            $parts[] = 'Backfilled';
        }
        if (!empty($entry['note'])) {
            $parts[] = (string) $entry['note'];
        }
        $parts[] = intersoccer_roster_history_format_label_line($entry);

        return implode('. ', $parts);
    }
}

if (!function_exists('intersoccer_roster_history_format_move_summary')) {
    /**
     * @param array<string,mixed> $entry
     */
    function intersoccer_roster_history_format_move_summary(array $entry): string {
        $from = is_array($entry['from'] ?? null) ? $entry['from'] : [];
        $to = is_array($entry['to'] ?? null) ? $entry['to'] : [];
        $stock = is_array($entry['stock'] ?? null) ? $entry['stock'] : [];
        $parts = [
            'From ' . intersoccer_roster_history_format_label_line($from),
            'To ' . intersoccer_roster_history_format_label_line($to),
            'By ' . (string) ($entry['actor_display_name'] ?? ''),
        ];
        if (!empty($entry['move_anyway'])) {
            $parts[] = 'Move anyway';
        }
        $reasons = $entry['overridden_reasons'] ?? [];
        if (is_array($reasons) && $reasons !== []) {
            $parts[] = 'Reasons ' . implode(', ', array_map('strval', $reasons));
        }
        $parts[] = sprintf(
            'Stock from %+d to %+d',
            (int) ($stock['applied_from'] ?? 0),
            (int) ($stock['applied_to'] ?? 0)
        );

        return implode('. ', $parts);
    }
}
