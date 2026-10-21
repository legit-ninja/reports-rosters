<?php
/**
 * InterSoccer Reports & Rosters i18n: locale loading, display labels, admin English.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/** English export column id (not passed through __(); admin exports stay English). */
const INTERSOCCER_RR_EXPORT_FIELD_SELECTED_DAYS = 'Selected Days';

/** Legacy/alternate order-item meta key for selected days (storage; not translated). */
const INTERSOCCER_RR_ORDER_META_SELECTED_DAYS = 'Selected Days';

/**
 * Customer-facing / form label for selected days column.
 */
function intersoccer_rr_label_selected_days(): string {
    return __('Selected Days', 'intersoccer-reports-rosters');
}

/**
 * Customer-facing / form label for days selected (order meta display name).
 */
function intersoccer_rr_label_days_selected(): string {
    return __('Days Selected', 'intersoccer-reports-rosters');
}

/**
 * Load plugin .mo for the current locale (fr_FR / de_DE, with regional fallbacks).
 *
 * @param string $plugin_file Main plugin file path.
 */
function intersoccer_rr_load_plugin_textdomain(string $plugin_file): void {
    $domain = 'intersoccer-reports-rosters';
    $plugin_rel_path = dirname(plugin_basename($plugin_file));
    $plugin_lang_dir = plugin_dir_path($plugin_file) . 'languages/';
    $locale = function_exists('determine_locale') ? determine_locale() : 'en_US';
    $attempts = [];
    $locale_parts = preg_split('/[_-]/', (string) $locale);

    $attempts[] = $locale;
    if (!empty($locale_parts[0])) {
        $base_lang = $locale_parts[0];
        if ($base_lang !== $locale) {
            $attempts[] = $base_lang;
        }
        foreach (glob($plugin_lang_dir . $domain . '-' . $base_lang . '_*.mo') ?: [] as $regional_file) {
            $regional_locale = str_replace($domain . '-', '', basename($regional_file, '.mo'));
            if ($regional_locale && $regional_locale !== $locale) {
                $attempts[] = $regional_locale;
            }
        }
    }
    $attempts[] = '';
    $attempts = array_unique($attempts);

    foreach ($attempts as $attempt_locale) {
        $candidate = $attempt_locale !== ''
            ? $plugin_lang_dir . $domain . '-' . $attempt_locale . '.mo'
            : $plugin_lang_dir . $domain . '.mo';

        if (is_readable($candidate) && load_textdomain($domain, $candidate)) {
            return;
        }
    }

    load_plugin_textdomain($domain, false, $plugin_rel_path . '/languages');
}

/**
 * True when admin-originated RR UI should show English strings for this text domain.
 */
function intersoccer_rr_is_admin_english_context(): bool {
    $forced = apply_filters('intersoccer_rr_admin_force_english', null);
    if ($forced !== null) {
        return (bool) $forced;
    }

    if (!is_admin()) {
        return false;
    }

    if (function_exists('is_locale_switched') && is_locale_switched()) {
        return false;
    }

    if (intersoccer_rr_is_frontend_ajax_request()) {
        return false;
    }

    return true;
}

/**
 * AJAX from the shop (admin-ajax.php with a non-admin referer) keeps translated strings.
 */
function intersoccer_rr_is_frontend_ajax_request(): bool {
    if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
        return false;
    }

    $referer = isset($_SERVER['HTTP_REFERER']) ? wp_unslash((string) $_SERVER['HTTP_REFERER']) : '';
    if ($referer === '') {
        return false;
    }

    $admin_url = admin_url();
    return strpos($referer, $admin_url) !== 0;
}

/**
 * Register gettext filters so admin RR screens stay English.
 */
function intersoccer_rr_register_admin_english_filters(): void {
    $domain = 'intersoccer-reports-rosters';

    add_filter("gettext_{$domain}", 'intersoccer_rr_filter_gettext_admin_english', 10, 3);
    add_filter("ngettext_{$domain}", 'intersoccer_rr_filter_ngettext_admin_english', 10, 5);
    add_filter("gettext_with_context_{$domain}", 'intersoccer_rr_filter_gettext_with_context_admin_english', 10, 4);
    add_filter("ngettext_with_context_{$domain}", 'intersoccer_rr_filter_ngettext_with_context_admin_english', 10, 6);
}

/**
 * @param string $translated
 * @param string $text
 * @param string $domain
 * @return string
 */
function intersoccer_rr_filter_gettext_admin_english($translated, $text, $domain) {
    if ($domain !== 'intersoccer-reports-rosters' || !intersoccer_rr_is_admin_english_context()) {
        return $translated;
    }

    return $text;
}

/**
 * @param string $translated
 * @param string $single
 * @param string $plural
 * @param int    $number
 * @param string $domain
 * @return string
 */
function intersoccer_rr_filter_ngettext_admin_english($translated, $single, $plural, $number, $domain) {
    if ($domain !== 'intersoccer-reports-rosters' || !intersoccer_rr_is_admin_english_context()) {
        return $translated;
    }

    return (int) $number === 1 ? $single : $plural;
}

/**
 * @param string $translated
 * @param string $text
 * @param string $context
 * @param string $domain
 * @return string
 */
function intersoccer_rr_filter_gettext_with_context_admin_english($translated, $text, $context, $domain) {
    if ($domain !== 'intersoccer-reports-rosters' || !intersoccer_rr_is_admin_english_context()) {
        return $translated;
    }

    return $text;
}

/**
 * @param string $translated
 * @param string $single
 * @param string $plural
 * @param int    $number
 * @param string $context
 * @param string $domain
 * @return string
 */
function intersoccer_rr_filter_ngettext_with_context_admin_english($translated, $single, $plural, $number, $context, $domain) {
    if ($domain !== 'intersoccer-reports-rosters' || !intersoccer_rr_is_admin_english_context()) {
        return $translated;
    }

    return (int) $number === 1 ? $single : $plural;
}

add_action('plugins_loaded', 'intersoccer_rr_register_admin_english_filters', 0);
