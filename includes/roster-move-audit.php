<?php
/**
 * Hidden audit trail for successful roster moves (order item meta + private order note).
 *
 * @package InterSoccer\ReportsRosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_roster_move_audit_latest_meta_keys')) {
    /**
     * Latest-move hidden meta keys (underscore-prefixed; not shown on emails/invoices).
     *
     * @return array<string,string> logical => meta_key
     */
    function intersoccer_roster_move_audit_latest_meta_keys(): array {
        return [
            'from_variation_id' => '_intersoccer_roster_move_from_variation_id',
            'to_variation_id'   => '_intersoccer_roster_move_to_variation_id',
            'user_id'           => '_intersoccer_roster_move_user_id',
            'moved_at_utc'      => '_intersoccer_roster_move_at_utc',
        ];
    }
}

if (!function_exists('intersoccer_roster_move_audit_history_meta_key')) {
    function intersoccer_roster_move_audit_history_meta_key(): string {
        return '_intersoccer_roster_move_history';
    }
}

if (!function_exists('intersoccer_roster_move_audit_all_meta_keys')) {
    /**
     * @return array<int,string>
     */
    function intersoccer_roster_move_audit_all_meta_keys(): array {
        return array_merge(
            array_values(intersoccer_roster_move_audit_latest_meta_keys()),
            [intersoccer_roster_move_audit_history_meta_key()]
        );
    }
}

if (!function_exists('intersoccer_roster_move_audit_actor_label')) {
    /**
     * Admin label for order notes (display name or numeric user id only).
     */
    function intersoccer_roster_move_audit_actor_label(int $user_id): string {
        if ($user_id <= 0) {
            return 'user 0';
        }

        if (function_exists('get_userdata')) {
            $user = get_userdata($user_id);
            if ($user && !empty($user->display_name)) {
                return (string) $user->display_name;
            }
        }

        return 'user ' . $user_id;
    }
}

if (!function_exists('intersoccer_roster_move_audit_decode_history')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_roster_move_audit_decode_history($raw): array {
        if ($raw === '' || $raw === null) {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('intersoccer_roster_move_audit_build_entry')) {
    /**
     * @return array{from_variation_id:int,to_variation_id:int,user_id:int,moved_at_utc:string}
     */
    function intersoccer_roster_move_audit_build_entry(
        int $from_variation_id,
        int $to_variation_id,
        int $user_id,
        string $moved_at_utc
    ): array {
        return [
            'from_variation_id' => $from_variation_id,
            'to_variation_id'   => $to_variation_id,
            'user_id'           => $user_id,
            'moved_at_utc'      => $moved_at_utc,
        ];
    }
}

if (!function_exists('intersoccer_roster_move_record_successful_move')) {
    /**
     * Persist hidden item meta and a private order note after a verified successful move.
     *
     * History: appends each success to _intersoccer_roster_move_history (JSON array) while
     * latest-move keys always reflect the most recent move.
     */
    function intersoccer_roster_move_record_successful_move(
        int $order_id,
        int $item_id,
        int $from_variation_id,
        int $to_variation_id,
        ?int $user_id = null,
        ?string $moved_at_utc = null,
        string $player_label = '',
        string $from_roster_label = '',
        string $to_roster_label = '',
        array $confirmed_reason_codes = []
    ): void {
        if ($order_id <= 0 || $item_id <= 0 || $to_variation_id <= 0) {
            return;
        }

        $user_id = $user_id ?? (int) (function_exists('get_current_user_id') ? get_current_user_id() : 0);
        $moved_at_utc = $moved_at_utc ?? gmdate('Y-m-d\TH:i:s\Z');

        $entry = intersoccer_roster_move_audit_build_entry(
            $from_variation_id,
            $to_variation_id,
            $user_id,
            $moved_at_utc
        );

        $latest_keys = intersoccer_roster_move_audit_latest_meta_keys();
        $history_key = intersoccer_roster_move_audit_history_meta_key();

        $item = new WC_Order_Item_Product($item_id);
        $existing_history = intersoccer_roster_move_audit_decode_history(
            $item->get_meta($history_key, true)
        );
        $existing_history[] = $entry;

        $item->update_meta_data($latest_keys['from_variation_id'], (string) $from_variation_id);
        $item->update_meta_data($latest_keys['to_variation_id'], (string) $to_variation_id);
        $item->update_meta_data($latest_keys['user_id'], (string) $user_id);
        $item->update_meta_data($latest_keys['moved_at_utc'], $moved_at_utc);
        $item->update_meta_data(
            $history_key,
            function_exists('wp_json_encode')
                ? wp_json_encode($existing_history)
                : json_encode($existing_history)
        );
        $item->save();

        if ($order_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && method_exists($order, 'add_order_note')) {
                $actor = intersoccer_roster_move_audit_actor_label($user_id);
                $order_number = method_exists($order, 'get_order_number')
                    ? (string) $order->get_order_number()
                    : (string) $order_id;
                $moved_at_site = function_exists('current_time')
                    ? current_time('Y-m-d H:i:s')
                    : gmdate('Y-m-d H:i:s');
                $reason_list = array_values(array_unique(array_filter(array_map('strval', $confirmed_reason_codes))));
                $reason_suffix = $reason_list !== [] ? ' Override reason(s): ' . implode(', ', $reason_list) . '.' : '';
                $note = sprintf(
                    'Roster move at %s (site time): player %s, order #%s, from %s (variation %d) to %s (variation %d) by %s.%s',
                    $moved_at_site,
                    $player_label !== '' ? $player_label : 'attendee',
                    $order_number,
                    $from_roster_label !== '' ? $from_roster_label : 'source roster',
                    $from_variation_id,
                    $to_roster_label !== '' ? $to_roster_label : 'target roster',
                    $to_variation_id,
                    $actor,
                    $reason_suffix
                );
                $order->add_order_note($note, false, true);
            }
        }
    }
}
