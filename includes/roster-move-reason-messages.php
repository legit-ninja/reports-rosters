<?php
/**
 * Admin roster move: reason codes, UX copy (English), and AJAX payloads.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Purchasability override reason codes (criteria + UX spec).
 *
 * @return string[]
 */
function intersoccer_roster_move_purchasability_reason_codes(): array {
    return [
        'private_or_disabled',
        'no_price',
        'course_ended',
        'full',
        'out_of_stock',
        'hidden',
        'closed',
        'unknown',
    ];
}

/**
 * All translatable UI strings for the roster move flow (English only until DE/FR from Pixel).
 *
 * @return array<string,string>
 */
function intersoccer_roster_move_ui_strings(): array {
    return [
        'heading' => __('This roster isn\'t open for purchase', 'intersoccer-reports-rosters'),
        'private_or_disabled' => __('This roster\'s variation is private or disabled.', 'intersoccer-reports-rosters'),
        'no_price' => __('This roster has no price set.', 'intersoccer-reports-rosters'),
        'course_ended' => __('This course has ended.', 'intersoccer-reports-rosters'),
        'out_of_stock' => __('This roster is marked out of stock.', 'intersoccer-reports-rosters'),
        'hidden' => __('This roster is hidden from the shop.', 'intersoccer-reports-rosters'),
        'closed' => __('This roster is closed for sale.', 'intersoccer-reports-rosters'),
        'unknown' => __('This roster can\'t be bought right now.', 'intersoccer-reports-rosters'),
        'consequence' => __('Moving still works. The players keep their details and orders. Customers still can\'t buy this roster.', 'intersoccer-reports-rosters'),
        'move_anyway' => __('Move anyway', 'intersoccer-reports-rosters'),
        'cancel' => __('Cancel', 'intersoccer-reports-rosters'),
        'checking' => __('Checking...', 'intersoccer-reports-rosters'),
        'apply' => __('Apply', 'intersoccer-reports-rosters'),
        'gender_block' => __('This roster is a different type (Girls Only or Regular). Tick the option above to move these players.', 'intersoccer-reports-rosters'),
        'try_again' => __('Try again', 'intersoccer-reports-rosters'),
        'dismiss' => __('Dismiss', 'intersoccer-reports-rosters'),
        'select_destination' => __('Please select a destination roster.', 'intersoccer-reports-rosters'),
        'no_players_selected' => __('No players selected.', 'intersoccer-reports-rosters'),
        'target_missing' => __('The destination roster no longer exists or was deleted.', 'intersoccer-reports-rosters'),
        'all_failed' => __('No players were moved.', 'intersoccer-reports-rosters'),
    ];
}

/**
 * Strings for wp_localize_script / inline JS (includes _n success forms).
 *
 * @return array<string,mixed>
 */
function intersoccer_roster_move_js_localize_strings(): array {
    $strings = intersoccer_roster_move_ui_strings();
    $strings['success_one'] = _n(
        'Moved %1$s player to %2$s.',
        'Moved %1$s players to %2$s.',
        1,
        'intersoccer-reports-rosters'
    );
    $strings['success_many'] = _n(
        'Moved %1$s player to %2$s.',
        'Moved %1$s players to %2$s.',
        2,
        'intersoccer-reports-rosters'
    );
    $partial_summary = _n(
        '%1$s of %2$s player moved. These couldn\'t be moved:',
        '%1$s of %2$s players moved. These couldn\'t be moved:',
        1,
        'intersoccer-reports-rosters'
    );
    $strings['partial_one'] = $partial_summary;
    $strings['partial_many'] = _n(
        '%1$s of %2$s players moved. These couldn\'t be moved:',
        '%1$s of %2$s players moved. These couldn\'t be moved:',
        2,
        'intersoccer-reports-rosters'
    );
    $strings['failed_player_line'] = __('%1$s: %2$s', 'intersoccer-reports-rosters');
    $strings['ajax_error'] = __('An error occurred. Please try again.', 'intersoccer-reports-rosters');
    $strings['permission_denied'] = __('Permission denied. Please refresh the page and try again.', 'intersoccer-reports-rosters');
    $strings['unknown_error'] = __('Unknown error occurred.', 'intersoccer-reports-rosters');

    return $strings;
}

/**
 * Test-friendly move log (captures to $GLOBALS['intersoccer_test_captured_roster_move_logs'] when set).
 */
function intersoccer_roster_move_log(string $message): void {
    if (!empty($GLOBALS['intersoccer_test_capture_roster_move_logs'])) {
        if (!isset($GLOBALS['intersoccer_test_captured_roster_move_logs'])
            || !is_array($GLOBALS['intersoccer_test_captured_roster_move_logs'])) {
            $GLOBALS['intersoccer_test_captured_roster_move_logs'] = [];
        }
        $GLOBALS['intersoccer_test_captured_roster_move_logs'][] = $message;
    }
    if (!empty($GLOBALS['intersoccer_test_error_log_file']) && is_string($GLOBALS['intersoccer_test_error_log_file'])) {
        file_put_contents($GLOBALS['intersoccer_test_error_log_file'], $message . "\n", FILE_APPEND);
    }
    if (!empty($GLOBALS['intersoccer_test_capture_roster_move_logs'])) {
        return;
    }
    error_log($message);
}

/**
 * Resolve roster capacity for admin-move warnings.
 *
 * Source order:
 * 1. Explicit `capacity` post meta on the variation, then on the parent product (Program Manager / legacy).
 * 2. When WooCommerce manages stock on the variation: capacity = active roster places + remaining stock
 *    (Woo stock quantity is places left, not headcount already booked).
 *
 * Campaign `_isrr_campaign_capacity` is not used here — it applies to marketing campaigns, not roster variations.
 *
 * @return int 0 when capacity is unknown.
 */
function intersoccer_roster_move_resolve_variation_capacity(WC_Product_Variation $variation, ?int $taken = null): int {
    $variation_id = (int) $variation->get_id();
    $taken = $taken ?? intersoccer_roster_move_active_roster_count($variation_id);

    $capacity = (int) $variation->get_meta('capacity', true);
    $parent_id = (int) $variation->get_parent_id();
    if ($capacity <= 0 && $parent_id > 0 && function_exists('wc_get_product')) {
        $parent_product = wc_get_product($parent_id);
        if ($parent_product) {
            $capacity = (int) $parent_product->get_meta('capacity', true);
        }
    }

    if ($capacity <= 0 && $variation->managing_stock()) {
        $remaining = (int) $variation->get_stock_quantity();
        $derived = $taken + $remaining;
        if ($derived > 0) {
            $capacity = $derived;
        }
    }

    return max(0, $capacity);
}

/**
 * Player first name only (roster row, then Assigned Attendee token).
 */
function intersoccer_roster_move_order_item_player_first_name(int $order_item_id): string {
    global $wpdb;
    if ($order_item_id <= 0) {
        return '';
    }

    if (isset($GLOBALS['intersoccer_test_player_first_name_by_item'])
        && is_array($GLOBALS['intersoccer_test_player_first_name_by_item'])) {
        $mapped = $GLOBALS['intersoccer_test_player_first_name_by_item'][(int) $order_item_id] ?? null;
        if (is_string($mapped) && $mapped !== '') {
            return $mapped;
        }
    }

    $table = $wpdb->prefix . 'intersoccer_rosters';
    $first = $wpdb->get_var($wpdb->prepare(
        "SELECT player_first_name FROM {$table} WHERE order_item_id = %d LIMIT 1",
        $order_item_id
    ));
    if (is_string($first) && trim($first) !== '') {
        return trim($first);
    }

    if (function_exists('wc_get_order_item_meta')) {
        $assigned = wc_get_order_item_meta($order_item_id, 'Assigned Attendee', true);
        if (is_string($assigned) && trim($assigned) !== '') {
            $parts = preg_split('/\s+/', trim($assigned), 2);

            return (string) ($parts[0] ?? '');
        }
    }

    return '';
}

/**
 * @param array<int,array<string,mixed>> $failures
 */
function intersoccer_roster_move_append_migration_failure(
    array &$failures,
    int $order_item_id,
    int $order_id,
    string $reason,
    bool $retryable = true
): void {
    $failures[] = [
        'order_item_id' => $order_item_id,
        'order_id' => $order_id,
        'player_first_name' => intersoccer_roster_move_order_item_player_first_name($order_item_id),
        'reason' => $reason,
        'retryable' => $retryable,
    ];
}

/**
 * @param array<int,array<string,mixed>|string> $errors
 *
 * @return array<int,array<string,mixed>>
 */
function intersoccer_roster_move_map_failures_for_ajax(array $errors): array {
    return array_values(array_map(static function ($err) {
        if (!is_array($err)) {
            return [
                'order_item_id' => 0,
                'order_id' => 0,
                'player_first_name' => '',
                'reason' => (string) $err,
                'retryable' => true,
            ];
        }

        return [
            'order_item_id' => (int) ($err['order_item_id'] ?? 0),
            'order_id' => (int) ($err['order_id'] ?? 0),
            'player_first_name' => (string) ($err['player_first_name'] ?? ''),
            'reason' => (string) ($err['reason'] ?? ''),
            'retryable' => !isset($err['retryable']) || (bool) $err['retryable'],
        ];
    }, $errors));
}

/**
 * @param WC_Product_Variation|object $variation
 *
 * @return array<string,mixed>
 */
function intersoccer_roster_move_build_move_ajax_success_payload(
    int $moved_count,
    int $total_count,
    array $errors,
    array $moved_item_ids,
    int $target_variation_id,
    $variation,
    array $reason_codes = []
): array {
    $failed = intersoccer_roster_move_map_failures_for_ajax($errors);
    $failed_count = count($failed);
    $target_display_name = intersoccer_roster_move_target_display_name_for_variation($target_variation_id);
    if ($target_display_name === '' && is_object($variation) && method_exists($variation, 'get_name')) {
        $target_display_name = (string) $variation->get_name();
    }

    $message = '';
    if ($moved_count > 0 && function_exists('intersoccer_roster_move_format_success_message')) {
        $message = intersoccer_roster_move_format_success_message($moved_count, $target_display_name);
    }

    $partial_summary = '';
    if (($moved_count < $total_count || $failed_count > 0) && function_exists('intersoccer_roster_move_format_partial_summary')) {
        $partial_summary = intersoccer_roster_move_format_partial_summary($moved_count, $total_count, $failed_count);
    }

    $payload = [
        'message' => $message,
        'partial_summary' => $partial_summary,
        'moved_count' => $moved_count,
        'total_count' => $total_count,
        'failed_count' => $failed_count,
        'target_roster_name' => $target_display_name,
        'moved_order_item_ids' => array_values(array_map('intval', $moved_item_ids)),
        'failed' => $failed,
        'partial' => $moved_count < $total_count || $failed_count > 0,
    ];
    if ($reason_codes !== []) {
        $payload['reason_codes'] = $reason_codes;
    }

    return $payload;
}

/**
 * Human destination label (product + season + schedule + venue + age + time), matching roster-details picker.
 */
function intersoccer_roster_move_target_display_name_for_variation(int $variation_id): string {
    global $wpdb;
    $variation_id = (int) $variation_id;
    if ($variation_id <= 0) {
        return '';
    }

    $table = $wpdb->prefix . 'intersoccer_rosters';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT product_name, season, course_day, camp_terms, venue, age_group, times
         FROM {$table} WHERE variation_id = %d LIMIT 1",
        $variation_id
    ), ARRAY_A);

    $product = is_array($row) ? trim((string) ($row['product_name'] ?? '')) : '';
    if ($product === '' && function_exists('wc_get_product')) {
        $variation = wc_get_product($variation_id);
        if ($variation && method_exists($variation, 'get_name')) {
            $product = trim((string) $variation->get_name());
        }
    }
    if ($product === '') {
        $product = __('Untitled roster', 'intersoccer-reports-rosters');
    }

    $parts = [$product];
    if (is_array($row)) {
        $season = trim((string) ($row['season'] ?? ''));
        if ($season !== '' && strcasecmp($season, 'N/A') !== 0) {
            $parts[] = $season;
        }
        $schedule = trim((string) ($row['course_day'] ?? ''));
        if ($schedule === '') {
            $schedule = trim((string) ($row['camp_terms'] ?? ''));
        }
        if ($schedule !== '' && strcasecmp($schedule, 'N/A') !== 0) {
            $parts[] = $schedule;
        }
        $venue = trim((string) ($row['venue'] ?? ''));
        if ($venue !== '' && strcasecmp($venue, 'N/A') !== 0) {
            $parts[] = $venue;
        }
        $age = trim((string) ($row['age_group'] ?? ''));
        if ($age !== '' && strcasecmp($age, 'N/A') !== 0) {
            $parts[] = $age;
        }
        $times = trim((string) ($row['times'] ?? ''));
        if ($times !== '' && strcasecmp($times, 'N/A') !== 0) {
            $parts[] = $times;
        }
    }

    return implode(', ', $parts);
}

function intersoccer_roster_move_format_success_message(int $moved_count, string $target_display_name): string {
    $moved_count = max(0, $moved_count);
    $template = _n(
        'Moved %1$s player to %2$s.',
        'Moved %1$s players to %2$s.',
        $moved_count,
        'intersoccer-reports-rosters'
    );

    $count_label = function_exists('number_format_i18n')
        ? number_format_i18n($moved_count)
        : (string) $moved_count;

    return sprintf($template, $count_label, $target_display_name);
}

function intersoccer_roster_move_format_partial_summary(int $moved, int $total, int $failed): string {
    $total = max(1, $total);
    $template = _n(
        '%1$s of %2$s player moved. These couldn\'t be moved:',
        '%1$s of %2$s players moved. These couldn\'t be moved:',
        $total,
        'intersoccer-reports-rosters'
    );

    $format_count = static function (int $value): string {
        return function_exists('number_format_i18n') ? number_format_i18n($value) : (string) $value;
    };

    return sprintf(
        $template,
        $format_count($moved),
        $format_count($total)
    );
}

/**
 * Full-roster reason line (pluralizes on capacity).
 */
function intersoccer_roster_move_format_full_line(int $taken, int $capacity): string {
    $template = _n(
        'This roster is full: %1$d of %2$d place taken.',
        'This roster is full: %1$d of %2$d places taken.',
        $capacity,
        'intersoccer-reports-rosters'
    );

    return sprintf($template, $taken, $capacity);
}

/**
 * Over-capacity warning line (pluralizes on places over capacity).
 */
function intersoccer_roster_move_format_over_capacity_line(int $over_n): string {
    $template = _n(
        'Moving will put it over capacity by %d place.',
        'Moving will put it over capacity by %d places.',
        $over_n,
        'intersoccer-reports-rosters'
    );

    return sprintf($template, $over_n);
}

/**
 * @param string              $key
 * @param array<string,mixed> $replacements
 */
function intersoccer_roster_move_ui_string(string $key, array $replacements = []): string {
    if ($key === 'full' && count($replacements) >= 2) {
        return intersoccer_roster_move_format_full_line((int) $replacements[0], (int) $replacements[1]);
    }
    if ($key === 'over_capacity' && $replacements !== []) {
        return intersoccer_roster_move_format_over_capacity_line((int) $replacements[0]);
    }

    $strings = intersoccer_roster_move_ui_strings();
    $template = $strings[$key] ?? $key;
    if ($replacements === []) {
        return $template;
    }

    return vsprintf($template, array_values($replacements));
}

/**
 * Build warning context (capacity, taken, over-capacity count).
 *
 * @param WC_Product_Variation $variation
 * @param int                  $moving_player_count
 *
 * @return array<string,mixed>
 */
function intersoccer_roster_move_warning_context_for_variation(WC_Product_Variation $variation, int $moving_player_count = 1): array {
    $variation_id = (int) $variation->get_id();
    $parent_id = (int) $variation->get_parent_id();
    $taken = intersoccer_roster_move_active_roster_count($variation_id);
    $capacity = intersoccer_roster_move_resolve_variation_capacity($variation, $taken);
    $moving_player_count = max(1, $moving_player_count);
    $over_capacity_n = 0;
    if ($capacity > 0 && ($taken + $moving_player_count) > $capacity) {
        $over_capacity_n = ($taken + $moving_player_count) - $capacity;
    }

    return [
        'variation_id' => $variation_id,
        'parent_id' => $parent_id,
        'capacity' => $capacity,
        'taken' => $taken,
        'moving_player_count' => $moving_player_count,
        'over_capacity_n' => $over_capacity_n,
    ];
}

/**
 * Human-readable lines for confirm panel (one per applicable reason).
 *
 * @param string[]             $reason_codes
 * @param array<string,mixed>  $context
 *
 * @return string[]
 */
function intersoccer_roster_move_reason_lines_for_codes(array $reason_codes, array $context = []): array {
    $lines = [];
    $taken = (int) ($context['taken'] ?? 0);
    $capacity = (int) ($context['capacity'] ?? 0);
    $over_n = (int) ($context['over_capacity_n'] ?? 0);

    foreach ($reason_codes as $code) {
        switch ($code) {
            case 'private_or_disabled':
                $lines[] = intersoccer_roster_move_ui_string('private_or_disabled');
                break;
            case 'no_price':
                $lines[] = intersoccer_roster_move_ui_string('no_price');
                break;
            case 'course_ended':
                $lines[] = intersoccer_roster_move_ui_string('course_ended');
                break;
            case 'full':
                if ($capacity > 0) {
                    $lines[] = intersoccer_roster_move_format_full_line($taken, $capacity);
                    if ($over_n > 0) {
                        $lines[] = intersoccer_roster_move_format_over_capacity_line($over_n);
                    }
                } else {
                    $lines[] = intersoccer_roster_move_ui_string('out_of_stock');
                }
                break;
            case 'out_of_stock':
                $lines[] = intersoccer_roster_move_ui_string('out_of_stock');
                break;
            case 'hidden':
                $lines[] = intersoccer_roster_move_ui_string('hidden');
                break;
            case 'closed':
                $lines[] = intersoccer_roster_move_ui_string('closed');
                break;
            case 'unknown':
                $lines[] = intersoccer_roster_move_ui_string('unknown');
                break;
        }
    }

    return array_values(array_unique($lines));
}

/**
 * AJAX payload when admin must confirm (no writes).
 *
 * @param string[]             $reason_codes
 * @param array<string,mixed>  $context
 *
 * @return array<string,mixed>
 */
function intersoccer_roster_move_build_needs_confirm_payload(array $reason_codes, array $context = []): array {
    $reason_codes = array_values(array_unique(array_filter(array_map('strval', $reason_codes))));
    $reason_lines = intersoccer_roster_move_reason_lines_for_codes($reason_codes, $context);
    $strings = intersoccer_roster_move_ui_strings();

    return [
        'needs_confirm' => true,
        'needs_confirmation' => true,
        'purchasable' => false,
        'reason_codes' => $reason_codes,
        'reason_lines' => $reason_lines,
        'confirm_messages' => $reason_lines,
        'warnings' => $reason_codes,
        'capacity' => (int) ($context['capacity'] ?? 0),
        'taken' => (int) ($context['taken'] ?? 0),
        'over_capacity_n' => (int) ($context['over_capacity_n'] ?? 0),
        'heading' => $strings['heading'],
        'consequence' => $strings['consequence'],
        'move_anyway' => $strings['move_anyway'],
        'cancel' => $strings['cancel'],
    ];
}

/**
 * @param string[]            $reason_codes
 * @param array<string,mixed> $context
 *
 * @return string[]
 */
function intersoccer_roster_move_reason_messages_list(array $reason_codes, array $context = []): array {
    return intersoccer_roster_move_reason_lines_for_codes($reason_codes, $context);
}

/**
 * @param array<string,mixed> $context
 */
function intersoccer_roster_move_reason_message(string $reason_code, array $context = []): string {
    $lines = intersoccer_roster_move_reason_lines_for_codes([$reason_code], $context);
    return $lines[0] ?? '';
}
