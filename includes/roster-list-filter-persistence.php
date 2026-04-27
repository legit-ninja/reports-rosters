<?php
/**
 * Persist roster list filters per user; show one-shot admin notices after bulk actions.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * @param string $list_slug Short slug, e.g. camps, courses.
 */
function intersoccer_rosters_filter_meta_key(string $list_slug): string {
    return 'isrr_list_filters_' . preg_replace('/[^a-z0-9_-]/i', '', $list_slug);
}

/**
 * Restore saved GET filters when the request has no filter params (except page), or save when filters are present.
 * Call with the roster list slug and the GET keys used on that screen. Pass $include_consolidated for camps/courses.
 *
 * @param string[] $get_keys
 */
function intersoccer_rosters_bootstrap_saved_list_filters(string $list_slug, array $get_keys, bool $include_consolidated = false): void {
    if (!is_admin() || !is_user_logged_in()) {
        return;
    }
    $uid = get_current_user_id();
    $meta_key = intersoccer_rosters_filter_meta_key($list_slug);

    if (!empty($_GET['clear_isrr_filters'])) {
        delete_user_meta($uid, $meta_key);
        return;
    }

    $incoming = [];
    foreach ($get_keys as $k) {
        if (isset($_GET[$k]) && (string) wp_unslash($_GET[$k]) !== '') {
            $incoming[$k] = sanitize_text_field(wp_unslash((string) $_GET[$k]));
        }
    }
    if ($include_consolidated && array_key_exists('consolidated', $_GET)) {
        $incoming['consolidated'] = sanitize_text_field(wp_unslash((string) $_GET['consolidated']));
    }

    $has_filter_payload = $incoming !== [];
    if ($has_filter_payload) {
        update_user_meta($uid, $meta_key, $incoming);

        return;
    }

    $saved = get_user_meta($uid, $meta_key, true);
    if (!is_array($saved) || $saved === []) {
        return;
    }

    foreach ($get_keys as $k) {
        if (!isset($_GET[$k]) && isset($saved[$k]) && (string) $saved[$k] !== '') {
            $_GET[$k] = $saved[$k];
            $_REQUEST[$k] = $saved[$k];
        }
    }
    if ($include_consolidated && !isset($_GET['consolidated']) && isset($saved['consolidated'])) {
        $_GET['consolidated'] = $saved['consolidated'];
        $_REQUEST['consolidated'] = $saved['consolidated'];
    }
}

/**
 * Queue a dismissible admin notice for the current user (shown on roster admin screens).
 */
function intersoccer_rosters_flash_admin_notice(string $message): void {
    if (!is_user_logged_in()) {
        return;
    }
    set_transient('isrr_admin_notice_' . get_current_user_id(), $message, 2 * MINUTE_IN_SECONDS);
}

/**
 * @return string[]
 */
function intersoccer_rosters_flash_notice_allowed_pages(): array {
    return [
        'intersoccer-camps',
        'intersoccer-courses',
        'intersoccer-girls-only',
        'intersoccer-tournaments',
        'intersoccer-other-events',
        'intersoccer-birthdays',
        'intersoccer-all-rosters',
        'intersoccer-roster-details',
        'intersoccer-signature-drift',
        'intersoccer-reports-rosters',
    ];
}

function intersoccer_rosters_register_flash_notice_hook(): void {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    add_action('admin_notices', static function (): void {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        if ($page === '' || !in_array($page, intersoccer_rosters_flash_notice_allowed_pages(), true)) {
            return;
        }
        if (!current_user_can('manage_options') && !current_user_can('coach')) {
            return;
        }
        $uid = get_current_user_id();
        if (!$uid) {
            return;
        }
        $key = 'isrr_admin_notice_' . $uid;
        $msg = get_transient($key);
        if (!is_string($msg) || $msg === '') {
            return;
        }
        delete_transient($key);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    });
}

intersoccer_rosters_register_flash_notice_hook();
