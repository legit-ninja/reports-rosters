<?php
/**
 * Admin roster move: machine reason codes and translatable confirm copy (single source for PHP + AJAX).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * @return string[]
 */
function intersoccer_roster_move_reason_codes(): array {
    return [
        'private',
        'hidden',
        'disabled',
        'no_price',
        'out_of_stock',
        'at_capacity',
        'closed_for_sale',
        'past_end_date',
        'cross_gender',
    ];
}

/**
 * Build warning context used when formatting confirm messages (capacity counts, etc.).
 *
 * @param WC_Product_Variation $variation
 *
 * @return array<string,mixed>
 */
function intersoccer_roster_move_warning_context_for_variation(WC_Product_Variation $variation): array {
    $variation_id = (int) $variation->get_id();
    $parent_id = (int) $variation->get_parent_id();
    $context = [
        'variation_id' => $variation_id,
        'parent_id' => $parent_id,
        'capacity_active' => 0,
        'capacity_max' => 0,
    ];

    $capacity = (int) $variation->get_meta('capacity', true);
    if ($capacity <= 0 && $parent_id > 0 && function_exists('wc_get_product')) {
        $parent_product = wc_get_product($parent_id);
        if ($parent_product) {
            $capacity = (int) $parent_product->get_meta('capacity', true);
        }
    }
    if ($capacity > 0) {
        $context['capacity_max'] = $capacity;
        $context['capacity_active'] = intersoccer_roster_move_active_roster_count($variation_id);
    }

    return $context;
}

/**
 * Human-readable confirm line for one reason code.
 *
 * @param string               $code
 * @param array<string,mixed>  $context From intersoccer_roster_move_warning_context_for_variation().
 */
function intersoccer_roster_move_reason_message(string $code, array $context = []): string {
    switch ($code) {
        case 'private':
            return __('This destination is not publicly available (private or unpublished status). Move anyway?', 'intersoccer-reports-rosters');
        case 'hidden':
            return __('This destination is hidden from the shop catalog. Move anyway?', 'intersoccer-reports-rosters');
        case 'disabled':
            return __('This destination variation is disabled in the product catalog. Move anyway?', 'intersoccer-reports-rosters');
        case 'no_price':
            return __('This destination has no price set (not purchasable on the storefront). Move anyway?', 'intersoccer-reports-rosters');
        case 'out_of_stock':
            return __('This destination is out of stock. Move anyway?', 'intersoccer-reports-rosters');
        case 'at_capacity':
            $active = (int) ($context['capacity_active'] ?? 0);
            $max = (int) ($context['capacity_max'] ?? 0);
            if ($max > 0) {
                return sprintf(
                    __('This roster is full (%1$d/%2$d). Move anyway?', 'intersoccer-reports-rosters'),
                    $active,
                    $max
                );
            }

            return __('This roster is at capacity. Move anyway?', 'intersoccer-reports-rosters');
        case 'closed_for_sale':
            return __('This roster is closed for sale. Move anyway?', 'intersoccer-reports-rosters');
        case 'past_end_date':
            return __('This destination end date is in the past. Move anyway?', 'intersoccer-reports-rosters');
        case 'cross_gender':
            return __('You are moving between Girls Only and regular rosters. Move anyway?', 'intersoccer-reports-rosters');
        default:
            return sprintf(
                __('The destination has a warning (%s). Move anyway?', 'intersoccer-reports-rosters'),
                $code
            );
    }
}

/**
 * @param string[]             $reason_codes
 * @param array<string,mixed>  $context
 *
 * @return string[]
 */
function intersoccer_roster_move_reason_messages_list(array $reason_codes, array $context = []): array {
    $messages = [];
    foreach ($reason_codes as $code) {
        $code = (string) $code;
        if ($code === '') {
            continue;
        }
        $messages[] = intersoccer_roster_move_reason_message($code, $context);
    }

    return $messages;
}

/**
 * AJAX payload fragment for confirm step (no writes).
 *
 * @param string[]             $reason_codes
 * @param array<string,mixed>  $context
 *
 * @return array{needs_confirm:bool,needs_confirmation:bool,reason_codes:string[],confirm_messages:string[],warnings:string[],message:string}
 */
function intersoccer_roster_move_build_needs_confirm_payload(array $reason_codes, array $context = []): array {
    $reason_codes = array_values(array_unique(array_map('strval', $reason_codes)));
    $confirm_messages = intersoccer_roster_move_reason_messages_list($reason_codes, $context);

    return [
        'needs_confirm' => true,
        'needs_confirmation' => true,
        'reason_codes' => $reason_codes,
        'confirm_messages' => $confirm_messages,
        'warnings' => $reason_codes,
        'message' => __('The destination has warnings. Confirm to proceed with the move.', 'intersoccer-reports-rosters'),
    ];
}
