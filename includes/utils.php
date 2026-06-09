<?php
/**
 * Utility functions for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.2
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_normalize_attribute')) {
    /**
     * Normalize attribute values for comparison, preserving "Activity Type" as-is.
     *
     * @param mixed $value Attribute value (string or array).
     * @param string $key The key of the attribute (e.g., from order item meta).
     * @return string Normalized value or empty string if invalid.
     */
    function intersoccer_normalize_attribute($value, $key = '') {
        // Preserve "Activity Type" as-is without normalization
        if ($key === 'Activity Type') {
            return is_string($value) ? trim($value) : (string)$value;
        }

        if (is_array($value)) {
            return implode(', ', array_map('trim', $value));
        } elseif (is_string($value) && strpos($value, 'a:') === 0) {
            $unserialized = maybe_unserialize($value);
            return is_array($unserialized) ? implode(', ', $unserialized) : $value;
        }
        return trim(strtolower($value ?? ''));
    }
}

error_log('InterSoccer: Loaded utils.php');

if (!function_exists('intersoccer_normalize_comparison_string')) {
    /**
     * Normalize a string for reliable comparisons across languages and formatting variants.
     *
     * Used as a shared building block for:
     * - order item meta key normalization
     * - translated term comparisons
     *
     * @param mixed $value
     * @return string
     */
    function intersoccer_normalize_comparison_string($value) {
        $normalized = strtolower(trim((string) ($value ?? '')));
        if ($normalized === '') {
            return '';
        }

        if (function_exists('remove_accents')) {
            $normalized = remove_accents($normalized);
        } elseif (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $normalized);
            if ($trans !== false) {
                $normalized = $trans;
            }
        }

        // Treat common separators as whitespace.
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        // Remove punctuation (keep slashes for compound labels like "Canton / Region")
        $normalized = preg_replace('/[^a-z0-9\/ ]+/u', '', $normalized);

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }
}

if (!function_exists('intersoccer_normalize_meta_key_for_lookup')) {
    /**
     * Normalize a metadata key for comparison/lookup.
     *
     * @param mixed $key
     * @return string
     */
    function intersoccer_normalize_meta_key_for_lookup($key) {
        $normalized = intersoccer_normalize_comparison_string($key);
        if ($normalized === '') {
            return '';
        }

        // Drop WooCommerce attribute prefix if present.
        $normalized = preg_replace('/^attribute\s+/', '', $normalized);

        return $normalized;
    }
}

if (!function_exists('intersoccer_get_term_def_cache_version')) {
    /**
     * Transient key version for default-language term lookups (bumped on term changes).
     *
     * @return int
     */
    function intersoccer_get_term_def_cache_version() {
        return (int) get_option('intersoccer_term_def_cache_version', 1);
    }
}

if (!function_exists('intersoccer_invalidate_term_def_cache')) {
    /**
     * Invalidate cached default-language term lookups after taxonomy changes.
     */
    function intersoccer_invalidate_term_def_cache() {
        $version = intersoccer_get_term_def_cache_version();
        update_option('intersoccer_term_def_cache_version', $version + 1, false);
    }
}

if (!function_exists('intersoccer_get_cached_term_in_default_language')) {
    /**
     * Resolve a term in default language with request-level and transient caching.
     *
     * @param string $value Translated name or slug.
     * @param string $taxonomy
     * @return WP_Term|null
     */
    function intersoccer_get_cached_term_in_default_language($value, $taxonomy) {
        if (empty($value) || $value === 'N/A' || empty($taxonomy)) {
            return null;
        }

        $value = (string) $value;
        static $request_cache = [];

        $request_key = md5($taxonomy . "\0" . $value);
        if (array_key_exists($request_key, $request_cache)) {
            return $request_cache[$request_key];
        }

        $transient_key = 'is_term_def_' . intersoccer_get_term_def_cache_version() . '_' . $request_key;
        $cached_term_id = get_transient($transient_key);
        if ($cached_term_id !== false) {
            if ((int) $cached_term_id === 0) {
                $request_cache[$request_key] = null;
                return null;
            }
            $term = get_term((int) $cached_term_id, $taxonomy);
            $request_cache[$request_key] = ($term && !is_wp_error($term)) ? $term : null;
            return $request_cache[$request_key];
        }

        $term = null;
        if (function_exists('intersoccer_get_term_by_translated_name')) {
            $term = intersoccer_get_term_by_translated_name($value, $taxonomy);
            if ($term && !is_wp_error($term)) {
                set_transient($transient_key, (int) $term->term_id, 6 * HOUR_IN_SECONDS);
                $request_cache[$request_key] = $term;
                return $term;
            }
        }

        $term = get_term_by('slug', $value, $taxonomy);
        if ($term && !is_wp_error($term)) {
            set_transient($transient_key, (int) $term->term_id, 6 * HOUR_IN_SECONDS);
            $request_cache[$request_key] = $term;
            return $term;
        }

        $term = get_term_by('name', $value, $taxonomy);
        if ($term && !is_wp_error($term)) {
            set_transient($transient_key, (int) $term->term_id, 6 * HOUR_IN_SECONDS);
            $request_cache[$request_key] = $term;
            return $term;
        }

        set_transient($transient_key, 0, 15 * MINUTE_IN_SECONDS);
        $request_cache[$request_key] = null;
        return null;
    }
}

if (!function_exists('intersoccer_get_term_in_default_language')) {
    /**
     * Resolve a term from a translated name/slug and return the term object in default language context.
     *
     * @param string $value Translated name or slug.
     * @param string $taxonomy
     * @return WP_Term|null
     */
    function intersoccer_get_term_in_default_language($value, $taxonomy) {
        if (function_exists('intersoccer_with_wpml_default_language')) {
            return intersoccer_with_wpml_default_language(static function () use ($value, $taxonomy) {
                return intersoccer_get_cached_term_in_default_language($value, $taxonomy);
            });
        }

        return intersoccer_get_cached_term_in_default_language($value, $taxonomy);
    }
}

add_action('edited_term', 'intersoccer_invalidate_term_def_cache', 10, 0);
add_action('created_term', 'intersoccer_invalidate_term_def_cache', 10, 0);
add_action('delete_term', 'intersoccer_invalidate_term_def_cache', 10, 0);

/**
 * Helper function to safely get term name in English for display
 * 
 * Gets the human-readable term name, ensuring it's in English (default language)
 * even if the stored value is a French slug or name.
 * 
 * @param string $value Term slug or name (may be in any language)
 * @param string $taxonomy Taxonomy slug (e.g., 'pa_city', 'pa_intersoccer-venues')
 * @return string English term name, or original value if not found
 */
function intersoccer_get_term_name($value, $taxonomy) {
    if (empty($value) || $value === 'N/A') {
        return 'N/A';
    }

    if (function_exists('intersoccer_get_term_in_default_language')) {
        $term = intersoccer_get_term_in_default_language($value, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return $term->name;
        }
    }

    $resolve_in_default_language = static function ($lookup_value, $tax) {
        $current_lang = function_exists('wpml_get_current_language') ? wpml_get_current_language() : null;
        $default_lang = function_exists('wpml_get_default_language') ? wpml_get_default_language() : null;
        if ($current_lang && $default_lang && $current_lang !== $default_lang) {
            do_action('wpml_switch_language', $default_lang);
        }
        try {
            $term = get_term_by('slug', $lookup_value, $tax);
            if (!$term || is_wp_error($term)) {
                $term = get_term_by('name', $lookup_value, $tax);
            }
            return ($term && !is_wp_error($term)) ? $term->name : null;
        } finally {
            if ($current_lang && $default_lang && $current_lang !== $default_lang) {
                do_action('wpml_switch_language', $current_lang);
            }
        }
    };

    $resolved = $resolve_in_default_language($value, $taxonomy);
    return $resolved !== null ? $resolved : $value;
}

/**
 * Get English product name for display
 * 
 * Normalizes product names to English for consistent display across roster pages.
 * Uses WPML to get the default language (English) version of the product name.
 * 
 * @param string $product_name Product name (may be in any language)
 * @param int $product_id Optional product ID to look up if name not found
 * @return string English product name
 */
function intersoccer_get_english_product_name($product_name, $product_id = 0) {
    if (empty($product_name) || $product_name === 'N/A') {
        return $product_name ?: 'N/A';
    }
    
    // If WPML is active, try to get the English version
    if (function_exists('apply_filters')) {
        $default_lang = apply_filters('wpml_default_language', null);
        if (empty($default_lang)) {
            // Fallback: return original name if we can't determine default language
            return $product_name;
        }
        
        // Try to get product by ID if provided
        if ($product_id > 0) {
            // First, normalize product_id to default language version (same logic as event signature generation)
            $normalized_product_id = $product_id;
            
            // Get the product to check if it's a variation
            $product = wc_get_product($product_id);
            if ($product && method_exists($product, 'get_parent_id')) {
                $parent_id = $product->get_parent_id();
                if ($parent_id > 0) {
                    // Use parent product ID instead of variation ID
                    $normalized_product_id = $parent_id;
                }
            }
            
            // Now normalize the product_id to the default language version
            // Try with return_original_if_missing = false first for strict translation
            $original_product_id = apply_filters('wpml_object_id', $normalized_product_id, 'product', false, $default_lang);
            if (!$original_product_id || $original_product_id == $normalized_product_id) {
                // If not found or same ID, try with return_original_if_missing = true
                $original_product_id = apply_filters('wpml_object_id', $normalized_product_id, 'product', true, $default_lang);
            }
            
            // As a last resort, try to find by TRID if direct object_id lookup fails
            if ($original_product_id == $normalized_product_id && function_exists('wpml_get_element_trid')) {
                $trid = apply_filters('wpml_get_element_trid', null, $normalized_product_id, 'post_product');
                if ($trid) {
                    $translated_id_by_trid = apply_filters('wpml_get_object_id_by_trid', null, $trid, 'post_product', $default_lang);
                    if ($translated_id_by_trid && $translated_id_by_trid != $normalized_product_id) {
                        $original_product_id = $translated_id_by_trid;
                    }
                }
            }
            
            $lookup_id = ($original_product_id && $original_product_id != $product_id)
                ? (int) $original_product_id
                : (int) $normalized_product_id;

            if ($lookup_id > 0 && function_exists('intersoccer_with_wpml_default_language')) {
                $english_name = intersoccer_with_wpml_default_language(static function () use ($lookup_id) {
                    $english_product = wc_get_product($lookup_id);
                    return ($english_product && $english_product->get_name()) ? $english_product->get_name() : '';
                });
                if (!empty($english_name)) {
                    return $english_name;
                }
            }
        }
    }
    
    // Fallback: return original name if we can't normalize it
    return $product_name;
}

if (!function_exists('intersoccer_reports_final_course_display_name')) {
    /**
     * Course/product label for final reports when get_the_title() is empty (common with WPML).
     *
     * @param int    $product_id      Product or variation ID from the order line.
     * @param int    $variation_id    Variation ID when set.
     * @param string $order_item_name Snapshot from woocommerce_order_items.order_item_name.
     * @return string Display name or 'Unknown'.
     */
    function intersoccer_reports_final_course_display_name($product_id, $variation_id = 0, $order_item_name = '') {
        $pid = (int) $product_id;
        $vid = (int) $variation_id;
        $product = null;
        if ($vid > 0) {
            $product = wc_get_product($vid);
        }
        if (!$product && $pid > 0) {
            $product = wc_get_product($pid);
        }
        if ($product) {
            $name = $product->get_name();
            if ($name === '' && $product->is_type('variation')) {
                $parent_id = (int) $product->get_parent_id();
                if ($parent_id) {
                    $parent = wc_get_product($parent_id);
                    if ($parent) {
                        $name = $parent->get_name();
                    }
                }
            }
            if ($name !== '') {
                return function_exists('intersoccer_get_english_product_name')
                    ? intersoccer_get_english_product_name($name, (int) $product->get_id())
                    : $name;
            }
        }
        if ($pid > 0) {
            $t = get_the_title($pid);
            if ($t !== '') {
                return function_exists('intersoccer_get_english_product_name')
                    ? intersoccer_get_english_product_name($t, $pid)
                    : $t;
            }
        }
        if ($vid > 0) {
            $t = get_the_title($vid);
            if ($t !== '') {
                return function_exists('intersoccer_get_english_product_name')
                    ? intersoccer_get_english_product_name($t, $vid)
                    : $t;
            }
        }
        $on = is_string($order_item_name) ? trim(wp_strip_all_tags($order_item_name)) : '';
        if ($on !== '') {
            return $on;
        }
        return 'Unknown';
    }
}

/**
 * Get default language variation ID for a given variation ID using WPML
 * 
 * @param int $variation_id Variation ID (may be in any language)
 * @return int|null Default language variation ID, or original if WPML not available or not found
 */
    function intersoccer_get_default_language_variation_id($variation_id) {
    if (empty($variation_id) || !function_exists('wpml_get_default_language') || !function_exists('apply_filters')) {
        return $variation_id;
    }

    $variation_id = (int) $variation_id;
    $default_lang = wpml_get_default_language();
    if (empty($default_lang)) {
        return $variation_id;
    }

    $default_variation_id = (int) apply_filters('wpml_object_id', $variation_id, 'product_variation', true, $default_lang);
    if ($default_variation_id > 0 && $default_variation_id !== $variation_id) {
        return $default_variation_id;
    }

    $trid = apply_filters('wpml_get_element_trid', null, $variation_id, 'post_product_variation');
    if ($trid) {
        $by_trid = (int) apply_filters('wpml_get_object_id_by_trid', null, $trid, 'post_product_variation', $default_lang);
        if ($by_trid > 0 && $by_trid !== $variation_id) {
            return $by_trid;
        }
    }

    return $variation_id;
}

/**
 * Unified date parser that handles all date formats found in order item metadata.
 * 
 * This function is the single source of truth for date parsing across the plugin.
 * It handles:
 * - F j, Y format (e.g., "August 17, 2025") - most common
 * - M j, Y format (e.g., "Aug 17, 2025") - abbreviated month
 * - Y-m-d format (e.g., "2025-08-17") - ISO format
 * - d/m/Y format (e.g., "17/08/2025") - European format
 * - m/d/Y format (e.g., "08/17/2025") - American format
 * - d/m/y format (e.g., "17/08/25") - European 2-digit year
 * - m/d/y format (e.g., "08/17/25") - American 2-digit year
 * - strtotime() fallback for other formats
 * 
 * @param string $date_string The date string to parse
 * @param string $context Optional context for logging (e.g., "order 12345, item 678")
 * @return string|null Parsed date in Y-m-d format, or null if parsing fails
 */
function intersoccer_parse_date_unified($date_string, $context = '') {
    if (empty($date_string) || $date_string === 'N/A' || trim($date_string) === '') {
        return null;
    }
    
    $date_string = trim($date_string);
    
    // Format priority order (most specific/unambiguous first)
    // 1. F j, Y - "August 17, 2025" (most common, unambiguous)
    // 2. M j, Y - "Aug 17, 2025" (abbreviated month)
    // 3. Y-m-d - "2025-08-17" (ISO format, unambiguous)
    // 4. d/m/Y - "17/08/2025" (European format, try before m/d/Y to avoid ambiguity)
    // 5. m/d/Y - "08/17/2025" (American format)
    // 6. d/m/y - "17/08/25" (European 2-digit year - CRITICAL for fixing malformed dates)
    // 7. m/d/y - "08/17/25" (American 2-digit year)
    // 8. j F Y - "17 August 2025" (alternative format)
    // 9. d-m-Y - "17-08-2025" (European with dashes)
    // 10. m-d-Y - "08-17-2025" (American with dashes)
    
    $formats = [
        'F j, Y',      // "August 17, 2025" - most common
        'M j, Y',      // "Aug 17, 2025" - abbreviated month
        'Y-m-d',       // "2025-08-17" - ISO format
        'd/m/Y',       // "17/08/2025" - European (try before m/d/Y)
        'm/d/Y',       // "08/17/2025" - American
        'd/m/y',       // "17/08/25" - European 2-digit year (CRITICAL)
        'm/d/y',       // "08/17/25" - American 2-digit year
        'j F Y',       // "17 August 2025" - alternative format
        'd-m-Y',       // "17-08-2025" - European with dashes
        'm-d-Y',       // "08-17-2025" - American with dashes
        'Y/m/d',       // "2025/08/17" - ISO with slashes
    ];
    
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $date_string);
        
        // Validate that the parsed date matches the input format exactly
        // This prevents false positives (e.g., "09/08/25" matching m/d/Y incorrectly)
        if ($date !== false) {
            $formatted = $date->format($format);
            $parsed_year = (int)$date->format('Y');
            
            // For formats with 2-digit years, we need special handling
            $is_2digit_year = (strpos($format, '/y') !== false || strpos($format, '-y') !== false);
            
            if ($is_2digit_year) {
                // For 2-digit year formats, check if the formatted date matches
                // and handle the year correctly
                if ($formatted === $date_string) {
                    // Check if year was parsed as 2-digit (e.g., 25 → 2025, but might be 0025)
                    if ($parsed_year < 100) {
                        // Year was parsed as 2-digit, assume 20XX
                        $corrected_year = $parsed_year + 2000;
                        // Validate the corrected year is reasonable
                        if ($corrected_year >= 2000 && $corrected_year <= 2099) {
                            $date->setDate($corrected_year, (int)$date->format('m'), (int)$date->format('d'));
                            $parsed_date = $date->format('Y-m-d');
                            if (!empty($context)) {
                                error_log("InterSoccer: Parsed date '$date_string' with format '$format' (2-digit year) to '$parsed_date' ($context)");
                            }
                            return $parsed_date;
                        }
                    } elseif ($parsed_year >= 1900 && $parsed_year <= 2100) {
                        // Year was already parsed correctly as 4-digit
                        $parsed_date = $date->format('Y-m-d');
                        if (!empty($context)) {
                            error_log("InterSoccer: Parsed date '$date_string' with format '$format' to '$parsed_date' ($context)");
                        }
                        return $parsed_date;
                    }
                }
            } else {
                // For 4-digit year formats, strict match
                if ($formatted === $date_string) {
                    // Validate year is reasonable
                    if ($parsed_year >= 1900 && $parsed_year <= 2100) {
                        $parsed_date = $date->format('Y-m-d');
                        if (!empty($context)) {
                            error_log("InterSoccer: Parsed date '$date_string' with format '$format' to '$parsed_date' ($context)");
                        }
                        return $parsed_date;
                    } else {
                        if (!empty($context)) {
                            error_log("InterSoccer: Parsed date '$date_string' has invalid year $parsed_year ($context)");
                        }
                    }
                }
            }
        }
    }
    
    // Try French date formats before strtotime() fallback
    // French month names: janvier, février, mars, avril, mai, juin, juillet, août, septembre, octobre, novembre, décembre
    $french_months = [
        'janvier' => 'January', 'février' => 'February', 'mars' => 'March', 'avril' => 'April',
        'mai' => 'May', 'juin' => 'June', 'juillet' => 'July', 'août' => 'August',
        'septembre' => 'September', 'octobre' => 'October', 'novembre' => 'November', 'décembre' => 'December'
    ];
    
    // French day names: lundi, mardi, mercredi, jeudi, vendredi, samedi, dimanche
    $french_days = [
        'lundi' => 'Monday', 'mardi' => 'Tuesday', 'mercredi' => 'Wednesday', 'jeudi' => 'Thursday',
        'vendredi' => 'Friday', 'samedi' => 'Saturday', 'dimanche' => 'Sunday'
    ];
    
    // Try to replace French month names and day names with English ones
    $english_date_string = $date_string;
    foreach ($french_months as $french => $english) {
        $english_date_string = str_ireplace($french, $english, $english_date_string);
    }
    foreach ($french_days as $french => $english) {
        // Match whole word to avoid partial replacements
        $english_date_string = preg_replace('/\b' . preg_quote($french, '/') . '\b/i', $english, $english_date_string);
    }
    
    // If we replaced a French month, try parsing the English version
    if ($english_date_string !== $date_string) {
        // Try common formats with the English month name
        $french_formats = [
            'l j F Y',      // "Sunday 14 December 2025" (with day name and year)
            'j F Y',        // "14 December 2025" (without day name, with year)
            'F j, Y',       // "December 14, 2025" (comma format)
            'l j F',        // "Sunday 14 December" (with day name, no year - will need year added)
        ];
        
        foreach ($french_formats as $format) {
            $date = DateTime::createFromFormat($format, $english_date_string);
            if ($date !== false) {
                $formatted = $date->format($format);
                // For formats with day names, we need to be more flexible
                if (strpos($format, 'l') !== false) {
                    // Format with day name - check if the date part matches
                    // Extract just the date part (day number and month) for comparison
                    $date_part = $date->format('j F');
                    $input_date_part = preg_replace('/^\w+\s+/', '', $english_date_string);
                    $input_date_part = preg_replace('/\s+\d{4}$/', '', $input_date_part); // Remove year if present
                    
                    // Get parsed year
                    $parsed_year = (int)$date->format('Y');
                    // If no year in format, check if year is in the input string
                    if (strpos($format, 'Y') === false) {
                        // No year in format, try to extract from input
                        if (preg_match('/(\d{4})/', $english_date_string, $year_matches)) {
                            $parsed_year = (int)$year_matches[1];
                            $date->setDate($parsed_year, (int)$date->format('m'), (int)$date->format('d'));
                        } else {
                            // No year found, skip this format
                            continue;
                        }
                    }
                    
                    // Check if the date parts match (ignoring day name and year)
                    // Also check if the formatted date matches the input (allowing for day name variations)
                    if ($date_part === $input_date_part || $formatted === $english_date_string) {
                        if ($parsed_year >= 1900 && $parsed_year <= 2100) {
                            $parsed_date = $date->format('Y-m-d');
                            if (!empty($context)) {
                                error_log("InterSoccer: Parsed French date '$date_string' (translated to '$english_date_string') with format '$format' to '$parsed_date' ($context)");
                            }
                            return $parsed_date;
                        }
                    } else {
                        // If exact match fails, try a more lenient check - just verify the date is valid
                        // This handles cases where DateTime parsed it but formatting doesn't match exactly
                        if ($parsed_year >= 1900 && $parsed_year <= 2100) {
                            // Verify the day and month match what we expect
                            $expected_day = (int)preg_replace('/^\w+\s+(\d+).*/', '$1', $english_date_string);
                            $parsed_day = (int)$date->format('d');
                            if ($expected_day === $parsed_day) {
                                $parsed_date = $date->format('Y-m-d');
                                if (!empty($context)) {
                                    error_log("InterSoccer: Parsed French date '$date_string' (translated to '$english_date_string') with format '$format' to '$parsed_date' (lenient match) ($context)");
                                }
                                return $parsed_date;
                            }
                        }
                    }
                } else {
                    // Standard format check
                    if ($formatted === $english_date_string) {
                        $parsed_year = (int)$date->format('Y');
                        if ($parsed_year >= 1900 && $parsed_year <= 2100) {
                            $parsed_date = $date->format('Y-m-d');
                            if (!empty($context)) {
                                error_log("InterSoccer: Parsed French date '$date_string' (translated to '$english_date_string') with format '$format' to '$parsed_date' ($context)");
                            }
                            return $parsed_date;
                        }
                    }
                }
            }
        }
    }
    
    // Fallback to strtotime() for formats we don't explicitly handle
    // First try with English month names if we translated them
    $strtotime_string = $english_date_string !== $date_string ? $english_date_string : $date_string;
    $timestamp = strtotime($strtotime_string);
    if ($timestamp !== false) {
        $parsed_date = date('Y-m-d', $timestamp);
        $year = (int)date('Y', $timestamp);
        
        // Validate year is reasonable
        if ($year >= 1900 && $year <= 2100) {
            if (!empty($context)) {
                error_log("InterSoccer: Parsed date '$date_string' with strtotime() to '$parsed_date' ($context)");
            }
            return $parsed_date;
        } else {
            if (!empty($context)) {
                error_log("InterSoccer: strtotime() parsed date '$date_string' has invalid year $year ($context)");
            }
        }
    }
    
    // All parsing attempts failed
    if (!empty($context)) {
        error_log("InterSoccer: Failed to parse date '$date_string' ($context)");
    }
    return null;
}

if (!function_exists('intersoccer_normalize_weekday_token')) {
    /**
     * Normalize localized weekday tokens (e.g. "lundi", "Mon.") to canonical English day names.
     *
     * @param mixed $token
     * @return string|null Canonical day (Monday..Sunday) or null if unknown/empty.
     */
    function intersoccer_normalize_weekday_token($token) {
        if (!is_string($token)) {
            return null;
        }

        $value = strtolower(trim($token));
        if ($value === '') {
            return null;
        }

        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }

        // Strip punctuation but keep letters for matching (e.g. "lun." -> "lun")
        $value = preg_replace('/[^a-z]+/u', '', $value);
        if ($value === '') {
            return null;
        }

        static $map = [
            // English
            'monday' => 'Monday', 'mon' => 'Monday',
            'tuesday' => 'Tuesday', 'tue' => 'Tuesday', 'tues' => 'Tuesday',
            'wednesday' => 'Wednesday', 'wed' => 'Wednesday',
            'thursday' => 'Thursday', 'thu' => 'Thursday', 'thur' => 'Thursday', 'thurs' => 'Thursday',
            'friday' => 'Friday', 'fri' => 'Friday',
            'saturday' => 'Saturday', 'sat' => 'Saturday',
            'sunday' => 'Sunday', 'sun' => 'Sunday',

            // French
            'lundi' => 'Monday', 'lun' => 'Monday',
            'mardi' => 'Tuesday', 'mar' => 'Tuesday',
            'mercredi' => 'Wednesday', 'mer' => 'Wednesday',
            'jeudi' => 'Thursday', 'jeu' => 'Thursday',
            'vendredi' => 'Friday', 'ven' => 'Friday',
            'samedi' => 'Saturday', 'sam' => 'Saturday',
            'dimanche' => 'Sunday', 'dim' => 'Sunday',

            // German (future-proof)
            'montag' => 'Monday',
            'dienstag' => 'Tuesday',
            'mittwoch' => 'Wednesday',
            'donnerstag' => 'Thursday',
            'freitag' => 'Friday',
            'samstag' => 'Saturday',
            'sonntag' => 'Sunday',
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        return null;
    }
}

if (!function_exists('intersoccer_normalize_booking_type_slug_for_reports')) {
    /**
     * Normalize booking type from order meta (any language / slug / label) to a canonical slug for reports.
     *
     * @param mixed $booking_type Raw booking type string.
     * @return string One of: full-week, single-days, full-term, other
     */
    function intersoccer_normalize_booking_type_slug_for_reports($booking_type) {
        if (!is_string($booking_type) || $booking_type === '') {
            return 'other';
        }
        $normalized = strtolower(trim($booking_type));
        $normalized = str_replace('_', '-', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized_ascii = function_exists('remove_accents')
            ? strtolower(remove_accents($normalized))
            : $normalized;

        // Attribute slugs use hyphens (e.g. semaine-complete); heuristics below use space-separated phrases.
        $ascii_for_heuristic = trim(preg_replace('/\s+/', ' ', str_replace('-', ' ', $normalized_ascii)));

        static $direct = [
            'full-week' => 'full-week',
            'full week' => 'full-week',
            'semaine-complete' => 'full-week',
            'journee-complete' => 'full-week',
            'single-days' => 'single-days',
            'single day(s)' => 'single-days',
            'single days' => 'single-days',
            'a-la-journee' => 'single-days',
            'a la journee' => 'single-days',
            'la journee' => 'single-days',
            'full-term' => 'full-term',
            'full term' => 'full-term',
        ];
        if (isset($direct[$normalized])) {
            return $direct[$normalized];
        }
        if (isset($direct[$normalized_ascii])) {
            return $direct[$normalized_ascii];
        }

        // Align with RosterBuilder::normalizeBookingTypeValue (FR/DE/EN).
        if (
            strpos($ascii_for_heuristic, 'full week') !== false
            || strpos($ascii_for_heuristic, 'journee complete') !== false
            || strpos($ascii_for_heuristic, 'semaine complete') !== false
            || strpos($ascii_for_heuristic, 'ganze woche') !== false
            || strpos($ascii_for_heuristic, 'komplette woche') !== false
            || strpos($ascii_for_heuristic, 'woche komplett') !== false
        ) {
            return 'full-week';
        }
        if (strpos($ascii_for_heuristic, 'single day') !== false || strpos($ascii_for_heuristic, 'jours selectionnes') !== false
            || strpos($ascii_for_heuristic, 'a la journee') !== false || strpos($ascii_for_heuristic, 'la journee') !== false
            || strpos($ascii_for_heuristic, 'ausgewahlte tage') !== false || strpos($ascii_for_heuristic, 'einzeltag') !== false) {
            return 'single-days';
        }
        if (strpos($ascii_for_heuristic, 'full term') !== false || strpos($ascii_for_heuristic, 'trimestre') !== false
            || strpos($ascii_for_heuristic, 'voller begriff') !== false) {
            return 'full-term';
        }

        // Odd spacing, hyphens, or storefront strings that still mean single-days / full-week (WPML, imports, theme labels).
        if (preg_match('/\bsingle\s*[-_]?\s*days?\b/i', $ascii_for_heuristic)) {
            return 'single-days';
        }
        if (preg_match('/\bfull\s*[-_]?\s*week\b|\bwhole\s*week\b/i', $ascii_for_heuristic)) {
            return 'full-week';
        }

        return 'other';
    }
}

if (!function_exists('intersoccer_normalize_booking_type_for_storage')) {
    /**
     * English booking type label for roster DB and order meta (not player-specific).
     *
     * @param mixed $booking_type Raw value from order or roster row.
     * @return string Full Week|Single Day(s)|Full Term|Unknown|trimmed original
     */
    function intersoccer_normalize_booking_type_for_storage($booking_type) {
        $raw = trim((string) $booking_type);
        if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
            return 'Unknown';
        }

        if (in_array($raw, ['Full Week', 'Single Day(s)', 'Full Term'], true)) {
            return $raw;
        }

        $slug = intersoccer_normalize_booking_type_slug_for_reports($raw);
        static $labels = [
            'full-week'   => 'Full Week',
            'single-days' => 'Single Day(s)',
            'full-term'   => 'Full Term',
        ];

        return $labels[$slug] ?? $raw;
    }
}

if (!function_exists('intersoccer_normalize_selected_days_for_storage')) {
    /**
     * Comma-separated English weekday list for roster storage.
     *
     * @param mixed $selected_days Raw days string (any language).
     * @return string
     */
    function intersoccer_normalize_selected_days_for_storage($selected_days) {
        $raw = trim((string) $selected_days);
        if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
            return '';
        }

        $tokens = preg_split('/\s*,\s*/', $raw);
        $english = [];
        foreach ($tokens as $token) {
            if (!function_exists('intersoccer_normalize_weekday_token')) {
                continue;
            }
            $day = intersoccer_normalize_weekday_token($token);
            if ($day !== null && $day !== '') {
                $english[$day] = $day;
            }
        }

        return implode(', ', array_values($english));
    }
}

if (!function_exists('intersoccer_read_order_item_booking_fields')) {
    /**
     * Read booking-related order line meta (any localized key).
     *
     * @param int $order_item_id
     * @return array{booking_type:string,selected_days:string,late_pickup_days:string}
     */
    function intersoccer_read_order_item_booking_fields($order_item_id) {
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0 || !class_exists('WC_Order_Item_Product')) {
            return [
                'booking_type'     => '',
                'selected_days'    => '',
                'late_pickup_days' => '',
            ];
        }

        $item = new WC_Order_Item_Product($order_item_id);
        $read = static function (array $keys) use ($item) {
            foreach ($keys as $key) {
                $value = $item->get_meta($key, true);
                if ($value === '' || $value === null) {
                    continue;
                }
                return is_array($value)
                    ? implode(', ', array_map('trim', $value))
                    : trim((string) $value);
            }
            return '';
        };

        return [
            'booking_type'     => $read(['pa_booking-type', 'Booking Type', 'Type de réservation', 'Buchungstyp']),
            'selected_days'    => $read(['Days Selected', 'Jours sélectionnés', 'Ausgewählte Tage', 'Selected Days']),
            'late_pickup_days' => $read(['Late Pickup Days', 'Jours de garde prolongée']),
        ];
    }
}

if (!function_exists('intersoccer_normalize_roster_booking_columns')) {
    /**
     * Normalize event booking columns for roster storage (excludes player-specific fields).
     *
     * @param array<string,mixed> $record   Existing roster row.
     * @param array<string,mixed> $overrides Optional raw values from order item meta.
     * @return array{booking_type:string,selected_days:string,late_pickup_days:string,day_presence:string}
     */
    function intersoccer_normalize_roster_booking_columns(array $record, array $overrides = []) {
        $booking_type = $overrides['booking_type'] ?? $record['booking_type'] ?? '';
        $selected_days = $overrides['selected_days'] ?? $record['selected_days'] ?? ($record['days_selected'] ?? '');
        $late_pickup_days = $overrides['late_pickup_days'] ?? $record['late_pickup_days'] ?? '';

        $booking_type_en = intersoccer_normalize_booking_type_for_storage($booking_type);
        $selected_days_en = intersoccer_normalize_selected_days_for_storage($selected_days);
        $late_pickup_days_en = intersoccer_normalize_selected_days_for_storage($late_pickup_days);

        $day_presence = function_exists('intersoccer_roster_compute_camp_day_presence_for_display')
            ? intersoccer_roster_compute_camp_day_presence_for_display($booking_type_en, $selected_days_en)
            : intersoccer_compute_day_presence($booking_type_en, $selected_days_en);

        return [
            'booking_type'     => $booking_type_en,
            'selected_days'    => $selected_days_en,
            'late_pickup_days' => $late_pickup_days_en,
            'day_presence'     => wp_json_encode($day_presence),
        ];
    }
}

if (!function_exists('intersoccer_build_roster_booking_db_update')) {
    /**
     * Roster table columns for normalized booking fields.
     *
     * @param array<string,mixed> $booking From intersoccer_normalize_roster_booking_columns().
     * @return array<string,string>
     */
    function intersoccer_build_roster_booking_db_update(array $booking) {
        $update = [];

        if (isset($booking['booking_type'])) {
            $update['booking_type'] = substr((string) $booking['booking_type'], 0, 50);
        }
        if (array_key_exists('selected_days', $booking)) {
            $update['selected_days'] = (string) $booking['selected_days'];
            $update['days_selected'] = substr(
                (string) ($booking['selected_days'] !== '' ? $booking['selected_days'] : 'N/A'),
                0,
                200
            );
        }
        if (isset($booking['day_presence'])) {
            $update['day_presence'] = (string) $booking['day_presence'];
        }
        if (array_key_exists('late_pickup_days', $booking)) {
            $update['late_pickup_days'] = substr((string) $booking['late_pickup_days'], 0, 255);
        }

        return $update;
    }
}

if (!function_exists('intersoccer_renormalize_order_item_booking_meta')) {
    /**
     * Rewrite booking-related order line meta to English keys/values.
     *
     * @param int                 $order_item_id
     * @param array<string,mixed> $booking Normalized booking columns.
     * @return bool
     */
    function intersoccer_renormalize_order_item_booking_meta($order_item_id, array $booking) {
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0 || !class_exists('WC_Order_Item_Product')) {
            return false;
        }

        $item = new WC_Order_Item_Product($order_item_id);

        if (!empty($booking['booking_type'])) {
            foreach (['Booking Type', 'Type de réservation', 'Buchungstyp'] as $key) {
                $item->delete_meta_data($key);
            }
            $item->add_meta_data('Booking Type', $booking['booking_type'], true);
        }

        if (array_key_exists('selected_days', $booking)) {
            foreach (['Days Selected', 'Jours sélectionnés', 'Ausgewählte Tage', 'Selected Days'] as $key) {
                $item->delete_meta_data($key);
            }
            if ($booking['selected_days'] !== '') {
                $item->add_meta_data('Days Selected', $booking['selected_days'], true);
            }
        }

        if (array_key_exists('late_pickup_days', $booking) && $booking['late_pickup_days'] !== '') {
            foreach (['Late Pickup Days', 'Jours de garde prolongée'] as $key) {
                $item->delete_meta_data($key);
            }
            $item->add_meta_data('Late Pickup Days', $booking['late_pickup_days'], true);
        }

        $item->save();
        return true;
    }
}

if (!function_exists('intersoccer_roster_collect_event_data_from_order_item')) {
    /**
     * Build facet payload from order line meta (localized keys) for normalization.
     *
     * @param int $order_item_id
     * @return array<string,mixed>
     */
    function intersoccer_roster_collect_event_data_from_order_item($order_item_id) {
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0
            || !function_exists('intersoccer_migration_human_alias_map')
            || !function_exists('intersoccer_migration_build_lookup')
            || !function_exists('intersoccer_migration_canonical_to_facet_key')) {
            return [];
        }

        $item = new WC_Order_Item_Product($order_item_id);
        $alias_map = intersoccer_migration_human_alias_map();
        $lookup = intersoccer_migration_build_lookup($alias_map);
        $facet_from_canonical = intersoccer_migration_canonical_to_facet_key();

        $event_data = [
            'activity_type' => '',
            'venue'         => '',
            'age_group'     => '',
            'camp_terms'    => '',
            'course_day'    => '',
            'times'         => '',
            'season'        => '',
            'city'          => '',
            'canton_region' => '',
            'girls_only'    => 0,
            'product_id'    => 0,
            'booking_type'  => '',
            'selected_days' => '',
        ];

        foreach ($item->get_meta_data() as $meta) {
            $normalized_key = intersoccer_migration_normalize_meta_key($meta->key);
            if (!isset($lookup[$normalized_key])) {
                continue;
            }
            $canonical = $lookup[$normalized_key];
            if (!isset($facet_from_canonical[$canonical])) {
                continue;
            }
            $facet = $facet_from_canonical[$canonical];
            $value = is_array($meta->value) ? implode(', ', $meta->value) : trim((string) $meta->value);
            if ($facet === 'times' && !empty($event_data['times'])) {
                continue;
            }
            $event_data[$facet] = $value;
        }

        $booking = intersoccer_read_order_item_booking_fields($order_item_id);
        if ($booking['booking_type'] !== '') {
            $event_data['booking_type'] = $booking['booking_type'];
        }
        if ($booking['selected_days'] !== '') {
            $event_data['selected_days'] = $booking['selected_days'];
        }

        return $event_data;
    }
}

if (!function_exists('intersoccer_roster_compute_camp_day_presence_for_display')) {
    /**
     * Monday–Friday Yes/No map for roster details and Excel export.
     *
     * Canonical booking type wins first: full-week always maps to all weekdays Yes, even when selected_days still
     * lists partial days (legacy denormalized data). Otherwise non-empty selected_days drives single-day parsing; empty
     * falls back to booking_type via intersoccer_compute_day_presence.
     *
     * @param mixed  $booking_type
     * @param string $selected_days_effective Comma-separated weekdays (any supported language tokens).
     * @return array<string,string>
     */
    function intersoccer_roster_compute_camp_day_presence_for_display($booking_type, $selected_days_effective) {
        if (!function_exists('intersoccer_compute_day_presence')) {
            return [
                'Monday' => 'No',
                'Tuesday' => 'No',
                'Wednesday' => 'No',
                'Thursday' => 'No',
                'Friday' => 'No',
            ];
        }
        $slug = function_exists('intersoccer_normalize_booking_type_slug_for_reports')
            ? intersoccer_normalize_booking_type_slug_for_reports((string) $booking_type)
            : 'other';
        if ($slug === 'full-week') {
            return intersoccer_compute_day_presence('full-week', '');
        }
        $sd = trim((string) $selected_days_effective);
        if ($sd !== '') {
            return intersoccer_compute_day_presence('single-days', $sd);
        }
        return intersoccer_compute_day_presence($booking_type, '');
    }
}

if (!function_exists('intersoccer_wpml_canonical_product_id')) {
    /**
     * Resolve a WooCommerce product ID to the default-language (canonical) product for WPML grouping/signatures.
     *
     * @param int $product_id Product or variation ID.
     * @return int Canonical parent product ID in default language, or best-effort input.
     */
    function intersoccer_wpml_canonical_product_id($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return 0;
        }
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product && method_exists($product, 'get_parent_id')) {
                $parent = (int) $product->get_parent_id();
                if ($parent > 0) {
                    $product_id = $parent;
                }
            }
        }
        if (function_exists('apply_filters') && function_exists('wpml_get_default_language')) {
            $def = wpml_get_default_language();
            if ($def) {
                $canon = (int) apply_filters('wpml_object_id', $product_id, 'product', true, $def);
                if ($canon > 0) {
                    return $canon;
                }
            }
        }
        return $product_id;
    }
}

if (!function_exists('intersoccer_roster_facet_for_grouping')) {
    /**
     * Normalize a roster facet (venue, course day, etc.) for cross-language listing aggregation.
     *
     * @param mixed  $value    Raw DB/meta value (any language or slug).
     * @param string $taxonomy Optional WooCommerce attribute taxonomy.
     * @return string Lowercase canonical token for hashing/comparison.
     */
    function intersoccer_roster_facet_for_grouping($value, $taxonomy = '') {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
            return '';
        }

        if ($taxonomy === 'pa_course-day' && function_exists('intersoccer_normalize_weekday_token')) {
            $day = intersoccer_normalize_weekday_token($raw);
            if ($day) {
                return strtolower($day);
            }
        }

        if ($taxonomy !== '' && function_exists('intersoccer_get_term_name')) {
            $name = intersoccer_get_term_name($raw, $taxonomy);
            if ($name !== 'N/A' && $name !== '') {
                return strtolower(trim($name));
            }
        }

        if (function_exists('intersoccer_normalize_term_fallback')) {
            return strtolower(trim((string) intersoccer_normalize_term_fallback($raw)));
        }

        return strtolower($raw);
    }
}

if (!function_exists('intersoccer_roster_sync_product_ids_from_order_line')) {
    /**
     * Prefer WooCommerce order line product/variation IDs over roster table values (fixes FR/DE mistranslation in DB).
     *
     * @param array $row Roster row.
     * @return array
     */
    function intersoccer_roster_sync_product_ids_from_order_line(array $row) {
        $order_id = (int) ($row['order_id'] ?? 0);
        $order_item_id = (int) ($row['order_item_id'] ?? 0);
        if ($order_id <= 0 || $order_item_id <= 0 || !function_exists('wc_get_order')) {
            return $row;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return $row;
        }
        $item = $order->get_item($order_item_id);
        if (!$item || !is_a($item, 'WC_Order_Item_Product')) {
            return $row;
        }
        $line_variation_id = (int) $item->get_variation_id();
        $line_product_id = (int) $item->get_product_id();
        if ($line_variation_id > 0) {
            $row['variation_id'] = $line_variation_id;
        }
        if ($line_product_id > 0) {
            $row['product_id'] = $line_product_id;
        }
        return $row;
    }
}

if (!function_exists('intersoccer_roster_canonicalize_row_product_ids')) {
    /**
     * Resolve roster product/variation IDs to default-language (English) WooCommerce IDs for grouping.
     *
     * @param array $row Roster row.
     * @return array
     */
    function intersoccer_roster_canonicalize_row_product_ids(array $row) {
        $variation_id = (int) ($row['variation_id'] ?? 0);
        if ($variation_id > 0 && function_exists('intersoccer_get_default_language_variation_id')) {
            $default_variation_id = (int) intersoccer_get_default_language_variation_id($variation_id);
            if ($default_variation_id > 0) {
                $row['variation_id'] = $default_variation_id;
            }
        }
        if (!empty($row['variation_id']) && function_exists('wc_get_product')) {
            $variation = wc_get_product((int) $row['variation_id']);
            if ($variation && method_exists($variation, 'get_parent_id')) {
                $parent = (int) $variation->get_parent_id();
                if ($parent > 0) {
                    $row['product_id'] = function_exists('intersoccer_wpml_canonical_product_id')
                        ? intersoccer_wpml_canonical_product_id($parent)
                        : $parent;
                }
            }
        } elseif (!empty($row['product_id']) && function_exists('intersoccer_wpml_canonical_product_id')) {
            $row['product_id'] = intersoccer_wpml_canonical_product_id((int) $row['product_id']);
        }
        return $row;
    }
}

if (!function_exists('intersoccer_roster_backfill_facets_from_variation')) {
    /**
     * Fill missing roster facets from the (canonical) variation attributes when DB rows are incomplete.
     *
     * @param array $row Roster row.
     * @return array
     */
    function intersoccer_roster_backfill_facets_from_variation(array $row) {
        $variation_id = (int) ($row['variation_id'] ?? 0);
        if ($variation_id <= 0 || !function_exists('wc_get_product')) {
            return $row;
        }
        $variation = wc_get_product($variation_id);
        if (!$variation) {
            return $row;
        }
        $attr_map = [
            'pa_program-season' => 'season',
            'pa_age-group' => 'age_group',
            'pa_course-times' => 'times',
            'pa_course-day' => 'course_day',
            'pa_intersoccer-venues' => 'venue',
            'pa_city' => 'city',
            'pa_camp-times' => 'times',
            'pa_camp-terms' => 'camp_terms',
        ];
        foreach ($attr_map as $taxonomy => $field) {
            $current = isset($row[$field]) ? trim((string) $row[$field]) : '';
            if ($current !== '' && strcasecmp($current, 'N/A') !== 0) {
                continue;
            }
            $val = $variation->get_attribute($taxonomy);
            if (is_array($val)) {
                $val = implode(', ', array_map('trim', $val));
            }
            $val = trim((string) $val);
            if ($val !== '') {
                $row[$field] = $val;
            }
        }

        $season = isset($row['season']) ? trim((string) $row['season']) : '';
        if ($season === '' || strcasecmp($season, 'N/A') === 0) {
            $product_id = (int) ($row['product_id'] ?? 0);
            if ($product_id > 0) {
                $parent = wc_get_product($product_id);
                if ($parent) {
                    $val = $parent->get_attribute('pa_program-season');
                    if (is_array($val)) {
                        $val = implode(', ', array_map('trim', $val));
                    }
                    $val = trim((string) $val);
                    if ($val !== '') {
                        $row['season'] = $val;
                    }
                }
            }
        }

        $season = isset($row['season']) ? trim((string) $row['season']) : '';
        if (($season === '' || strcasecmp($season, 'N/A') === 0) && !empty($row['order_item_id'])) {
            $meta_keys = ['Season', 'pa_program-season', 'program-season', 'season'];
            foreach ($meta_keys as $meta_key) {
                $val = wc_get_order_item_meta((int) $row['order_item_id'], $meta_key, true);
                if (is_array($val)) {
                    $val = implode(', ', array_map('trim', $val));
                }
                $val = trim((string) $val);
                if ($val !== '') {
                    $row['season'] = $val;
                    break;
                }
            }
        }

        return $row;
    }
}

if (!function_exists('intersoccer_roster_is_empty_season')) {
    /**
     * @param mixed $season
     */
    function intersoccer_roster_is_empty_season($season) {
        $season = trim((string) ($season ?? ''));
        return $season === '' || strcasecmp($season, 'N/A') === 0;
    }
}

if (!function_exists('intersoccer_roster_course_groups_share_listing_facets')) {
    /**
     * Whether two course listing groups describe the same event aside from season text.
     *
     * @param array $a
     * @param array $b
     */
    function intersoccer_roster_course_groups_share_listing_facets(array $a, array $b) {
        $norm = static function ($v) {
            return strtolower(trim((string) $v));
        };
        foreach (['venue', 'course_day', 'age_group', 'times'] as $field) {
            if ($norm($a[$field] ?? '') !== $norm($b[$field] ?? '')) {
                return false;
            }
        }
        $varsA = array_values(array_unique(array_map('intval', (array) ($a['variation_ids'] ?? []))));
        $varsB = array_values(array_unique(array_map('intval', (array) ($b['variation_ids'] ?? []))));
        sort($varsA);
        sort($varsB);
        return $varsA === $varsB && !empty($varsA);
    }
}

if (!function_exists('intersoccer_roster_merge_two_course_groups')) {
    /**
     * @param array $primary Target group (keeps signature key).
     * @param array $other   Group to fold in.
     */
    function intersoccer_roster_merge_two_course_groups(array $primary, array $other) {
        foreach (['order_item_ids', 'variation_ids'] as $idField) {
            if (empty($other[$idField]) || !is_array($other[$idField])) {
                continue;
            }
            if (!isset($primary[$idField]) || !is_array($primary[$idField])) {
                $primary[$idField] = [];
            }
            foreach ($other[$idField] as $id => $unused) {
                $primary[$idField][(int) $id] = is_int($unused) ? $unused : true;
            }
        }

        if (intersoccer_roster_is_empty_season($primary['season_raw'] ?? $primary['season'] ?? '')) {
            if (!intersoccer_roster_is_empty_season($other['season_raw'] ?? $other['season'] ?? '')) {
                $primary['season'] = $other['season'] ?? '';
                $primary['season_raw'] = $other['season_raw'] ?? $primary['season'];
            }
        }

        if ((empty($primary['product_name']) || $primary['product_name'] === 'N/A')
            && !empty($other['product_name']) && $other['product_name'] !== 'N/A') {
            $primary['product_name'] = $other['product_name'];
        }

        if (!empty($other['event_signature'])) {
            $primary['merged_event_signatures'][$other['event_signature']] = true;
        }
        if (!empty($other['start_dates'])) {
            $primary['start_dates'] = array_merge((array) ($primary['start_dates'] ?? []), (array) $other['start_dates']);
        }
        if (!empty($other['end_dates'])) {
            $primary['end_dates'] = array_merge((array) ($primary['end_dates'] ?? []), (array) $other['end_dates']);
        }

        return $primary;
    }
}

if (!function_exists('intersoccer_roster_merge_course_groups_with_empty_season')) {
    /**
     * Merge consolidated course groups that match on venue/day/age/times/variation but have empty season on one side.
     *
     * @param array<string,array> $groups
     * @return array<string,array>
     */
    function intersoccer_roster_merge_course_groups_with_empty_season(array $groups) {
        $signatures = array_keys($groups);
        foreach ($signatures as $i => $sigA) {
            if (!isset($groups[$sigA])) {
                continue;
            }
            foreach (array_slice($signatures, $i + 1) as $sigB) {
                if (!isset($groups[$sigB])) {
                    continue;
                }
                if (!intersoccer_roster_course_groups_share_listing_facets($groups[$sigA], $groups[$sigB])) {
                    continue;
                }
                $aEmpty = intersoccer_roster_is_empty_season($groups[$sigA]['season_raw'] ?? $groups[$sigA]['season'] ?? '');
                $bEmpty = intersoccer_roster_is_empty_season($groups[$sigB]['season_raw'] ?? $groups[$sigB]['season'] ?? '');
                if (!$aEmpty && !$bEmpty) {
                    continue;
                }
                if ($aEmpty && !$bEmpty) {
                    $groups[$sigB] = intersoccer_roster_merge_two_course_groups($groups[$sigB], $groups[$sigA]);
                    $mergedAway = $sigA;
                } else {
                    $groups[$sigA] = intersoccer_roster_merge_two_course_groups($groups[$sigA], $groups[$sigB]);
                    $mergedAway = $sigB;
                }
                unset($groups[$mergedAway]);
            }
        }
        return $groups;
    }
}

if (!function_exists('intersoccer_roster_is_placeholder_player_name')) {
    /**
     * @param string $name
     * @return bool
     */
    function intersoccer_roster_is_placeholder_player_name($name) {
        $lv = strtolower(trim((string) $name));
        return in_array($lv, ['unknown', 'unknown player', 'unknown attendee', 'n/a', 'na', '-', ''], true);
    }
}

if (!function_exists('intersoccer_roster_parse_attendee_display_name')) {
    /**
     * @param string $attendee
     * @return array{first_name:string,last_name:string,player_name:string}
     */
    function intersoccer_roster_parse_attendee_display_name($attendee) {
        $attendee = preg_replace('/^\d+\s*/', '', trim((string) $attendee));
        if ($attendee === '') {
            return ['first_name' => '', 'last_name' => '', 'player_name' => ''];
        }
        $parts = explode(' ', $attendee, 2);
        $first = trim($parts[0] ?? '');
        $last = trim($parts[1] ?? '');
        if ($last === '') {
            $last = $first;
        }
        return [
            'first_name' => $first,
            'last_name' => $last,
            'player_name' => $attendee,
        ];
    }
}

if (!function_exists('intersoccer_roster_row_names_incomplete')) {
    /**
     * True when roster row cannot show a player first name in admin UI.
     *
     * @param array<string,mixed> $row
     * @return bool
     */
    function intersoccer_roster_row_names_incomplete(array $row) {
        $fn = trim((string) ($row['first_name'] ?? ''));
        return $fn === '' || intersoccer_roster_is_placeholder_player_name($fn);
    }
}

if (!function_exists('intersoccer_roster_row_is_sync_placeholder')) {
    /**
     * True for minimal rows inserted by diagnostics "Fix Sync" (not a full roster build).
     *
     * @param array<string,mixed> $row
     * @return bool
     */
    function intersoccer_roster_row_is_sync_placeholder(array $row) {
        $pn = trim((string) ($row['player_name'] ?? ''));
        if (function_exists('intersoccer_roster_is_placeholder_player_name')
            && !intersoccer_roster_is_placeholder_player_name($pn)) {
            return false;
        }
        $sig = trim((string) ($row['event_signature'] ?? ''));
        return $sig === '' || strcasecmp($sig, 'N/A') === 0;
    }
}

if (!function_exists('intersoccer_roster_row_needs_order_resync')) {
    /**
     * True when a roster row should be rebuilt from WooCommerce (not left as-is).
     *
     * @param array<string,mixed> $row
     * @return bool
     */
    function intersoccer_roster_row_needs_order_resync(array $row) {
        if (function_exists('intersoccer_roster_row_is_sync_placeholder') && intersoccer_roster_row_is_sync_placeholder($row)) {
            return true;
        }
        $sig = trim((string) ($row['event_signature'] ?? ''));
        if ($sig === '' || strcasecmp($sig, 'N/A') === 0) {
            return true;
        }
        return function_exists('intersoccer_roster_row_names_incomplete') && intersoccer_roster_row_names_incomplete($row);
    }
}

if (!function_exists('intersoccer_roster_row_resolve_event_signature_for_url')) {
    /**
     * Resolve event_signature for roster details URLs (stored value or computed from facets).
     *
     * @param array<string,mixed> $row
     * @return string
     */
    function intersoccer_roster_row_resolve_event_signature_for_url(array $row) {
        $sig = trim((string) ($row['event_signature'] ?? ''));
        if ($sig !== '' && strcasecmp($sig, 'N/A') !== 0) {
            return $sig;
        }
        if (!function_exists('intersoccer_event_signature_from_event_data')) {
            return '';
        }
        $payload = [
            'activity_type' => $row['activity_type'] ?? '',
            'venue' => $row['venue'] ?? '',
            'course_day' => $row['course_day'] ?? '',
            'camp_terms' => $row['camp_terms'] ?? '',
            'age_group' => $row['age_group'] ?? '',
            'times' => $row['times'] ?? ($row['course_times'] ?? ($row['camp_times'] ?? '')),
            'season' => $row['season'] ?? '',
            'product_id' => $row['product_id'] ?? 0,
            'variation_id' => $row['variation_id'] ?? 0,
            'girls_only' => $row['girls_only'] ?? 0,
            'start_date' => $row['start_date'] ?? '',
        ];
        $computed = intersoccer_event_signature_from_event_data($payload);
        return is_string($computed) ? trim($computed) : '';
    }
}

if (!function_exists('intersoccer_attempt_roster_build_for_order_item_ids')) {
    /**
     * Build roster rows from WooCommerce orders for the given line item IDs.
     *
     * @param int[] $order_item_ids
     * @return int Number of distinct orders processed
     */
    function intersoccer_attempt_roster_build_for_order_item_ids(array $order_item_ids) {
        $order_item_ids = array_values(array_filter(array_map('intval', $order_item_ids)));
        if ($order_item_ids === []) {
            return 0;
        }

        $order_ids = [];
        foreach ($order_item_ids as $order_item_id) {
            $order_id = function_exists('wc_get_order_id_by_order_item_id')
                ? (int) wc_get_order_id_by_order_item_id($order_item_id)
                : 0;
            if ($order_id > 0) {
                $order_ids[$order_id] = $order_id;
            }
        }

        if ($order_ids === []) {
            return 0;
        }

        $processed = 0;
        foreach ($order_ids as $order_id) {
            try {
                if (function_exists('intersoccer_oop_process_order')) {
                    if (intersoccer_oop_process_order($order_id)) {
                        $processed++;
                    }
                } elseif (function_exists('intersoccer_safe_populate_rosters')) {
                    if (intersoccer_safe_populate_rosters($order_id)) {
                        $processed++;
                    }
                }
            } catch (\Throwable $e) {
                error_log('InterSoccer: attempt_roster_build failed for order ' . $order_id . ': ' . $e->getMessage());
            }
        }

        return $processed;
    }
}

if (!function_exists('intersoccer_roster_display_first_name')) {
    /**
     * @param array|object $row
     * @return string
     */
    function intersoccer_roster_display_first_name($row) {
        $row = is_object($row) ? (array) $row : $row;
        if (function_exists('intersoccer_roster_backfill_player_name_fields')) {
            $row = intersoccer_roster_backfill_player_name_fields($row);
        }
        $fn = trim((string) ($row['first_name'] ?? ''));
        return $fn !== '' ? $fn : 'N/A';
    }
}

if (!function_exists('intersoccer_roster_display_last_name')) {
    /**
     * @param array|object $row
     * @return string
     */
    function intersoccer_roster_display_last_name($row) {
        $row = is_object($row) ? (array) $row : $row;
        if (function_exists('intersoccer_roster_backfill_player_name_fields')) {
            $row = intersoccer_roster_backfill_player_name_fields($row);
        }
        $ln = trim((string) ($row['last_name'] ?? ''));
        return $ln !== '' ? $ln : 'N/A';
    }
}

if (!function_exists('intersoccer_roster_backfill_player_name_fields')) {
    /**
     * Fill first_name, last_name, and player_name from DB columns or localized order item attendee meta.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    function intersoccer_roster_backfill_player_name_fields(array $row) {
        $fn = trim((string) ($row['first_name'] ?? ''));
        $ln = trim((string) ($row['last_name'] ?? ''));
        $pn = trim((string) ($row['player_name'] ?? ''));

        if (($fn === '' || intersoccer_roster_is_placeholder_player_name($fn)) && !empty($row['player_first_name'])) {
            $row['first_name'] = trim((string) $row['player_first_name']);
            $fn = $row['first_name'];
        }
        if (($ln === '' || intersoccer_roster_is_placeholder_player_name($ln)) && !empty($row['player_last_name'])) {
            $row['last_name'] = trim((string) $row['player_last_name']);
            $ln = $row['last_name'];
        }

        if ($fn === '' && $pn !== '' && !intersoccer_roster_is_placeholder_player_name($pn)) {
            $parsed = intersoccer_roster_parse_attendee_display_name($pn);
            $row['first_name'] = $parsed['first_name'];
            $row['last_name'] = $parsed['last_name'];
            $fn = $row['first_name'];
            $ln = $row['last_name'];
        }

        if (($fn === '' || intersoccer_roster_is_placeholder_player_name($fn)) && !empty($row['order_item_id'])
            && function_exists('intersoccer_resolve_assigned_attendee_from_order_item')) {
            $attendee = intersoccer_resolve_assigned_attendee_from_order_item((int) $row['order_item_id']);
            if ($attendee !== '') {
                $parsed = intersoccer_roster_parse_attendee_display_name($attendee);
                if ($fn === '') {
                    $row['first_name'] = $parsed['first_name'];
                    $fn = $row['first_name'];
                }
                if ($ln === '') {
                    $row['last_name'] = $parsed['last_name'];
                    $ln = $row['last_name'];
                }
                if ($pn === '') {
                    $row['player_name'] = $parsed['player_name'];
                    $pn = $row['player_name'];
                }
            }
        }

        if (($pn === '' || intersoccer_roster_is_placeholder_player_name($pn)) && ($fn !== '' || $ln !== '')) {
            $row['player_name'] = trim($fn . ' ' . $ln);
        }

        if (!empty($row['first_name'])) {
            $row['player_first_name'] = $row['first_name'];
        }
        if (!empty($row['last_name'])) {
            $row['player_last_name'] = $row['last_name'];
        }

        return $row;
    }
}

if (!function_exists('intersoccer_roster_persist_player_name_fields')) {
    /**
     * @param array<string,mixed> $row Must include id when updating.
     * @return bool
     */
    function intersoccer_roster_persist_player_name_fields(array $row) {
        global $wpdb;
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || !isset($wpdb) || !is_object($wpdb)) {
            return false;
        }
        if (intersoccer_roster_row_names_incomplete($row)) {
            return false;
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $updated = $wpdb->update(
            $table,
            [
                'first_name' => substr((string) ($row['first_name'] ?? ''), 0, 100),
                'last_name' => substr((string) ($row['last_name'] ?? ''), 0, 100),
                'player_name' => substr((string) ($row['player_name'] ?? ''), 0, 255),
                'player_first_name' => substr((string) ($row['player_first_name'] ?? $row['first_name'] ?? ''), 0, 100),
                'player_last_name' => substr((string) ($row['player_last_name'] ?? $row['last_name'] ?? ''), 0, 100),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }
}

if (!function_exists('intersoccer_roster_normalize_row_facets_for_display')) {
    /**
     * Normalize roster row facets to English for admin listing display and filters.
     *
     * @param array $row Roster row array.
     * @return array
     */
    function intersoccer_roster_normalize_row_facets_for_display(array $row) {
        if (function_exists('intersoccer_roster_backfill_player_name_fields')) {
            $row = intersoccer_roster_backfill_player_name_fields($row);
        }
        if (function_exists('intersoccer_roster_sync_product_ids_from_order_line')) {
            $row = intersoccer_roster_sync_product_ids_from_order_line($row);
        }
        if (function_exists('intersoccer_roster_backfill_facets_from_order_item_meta')) {
            $row = intersoccer_roster_backfill_facets_from_order_item_meta($row);
        }
        if (function_exists('intersoccer_roster_canonicalize_row_product_ids')) {
            $row = intersoccer_roster_canonicalize_row_product_ids($row);
        }
        if (function_exists('intersoccer_roster_backfill_facets_from_variation')) {
            $row = intersoccer_roster_backfill_facets_from_variation($row);
        }
        if (!empty($row['course_day']) && function_exists('intersoccer_normalize_weekday_token')) {
            $day = intersoccer_normalize_weekday_token($row['course_day']);
            if ($day) {
                $row['course_day'] = $day;
            }
        }
        $taxonomy_map = [
            'venue' => 'pa_intersoccer-venues',
            'age_group' => 'pa_age-group',
            'times' => 'pa_course-times',
            'camp_terms' => 'pa_camp-terms',
            'season' => 'pa_program-season',
            'city' => 'pa_city',
        ];
        if (function_exists('intersoccer_get_term_name')) {
            foreach ($taxonomy_map as $field => $taxonomy) {
                if (empty($row[$field])) {
                    continue;
                }
                $name = intersoccer_get_term_name($row[$field], $taxonomy);
                if ($name !== 'N/A' && $name !== '') {
                    $row[$field] = $name;
                }
            }
            if (!empty($row['times'])) {
                $alt = intersoccer_get_term_name($row['times'], 'pa_camp-times');
                if ($alt !== 'N/A' && $alt !== '') {
                    $row['times'] = $alt;
                }
            }
        }
        if (!empty($row['activity_type']) && function_exists('intersoccer_canonical_activity_type_for_roster')) {
            $row['activity_type'] = intersoccer_canonical_activity_type_for_roster($row['activity_type']);
        }
        return $row;
    }
}

if (!function_exists('intersoccer_consolidated_roster_group_key')) {
    /**
     * Stable group key for admin “consolidated (all languages)” listings: canonical product + normalized event facets.
     *
     * @param array  $row  Roster row (product_id, venue, season, etc.).
     * @param string $kind "camp" or "course".
     * @return string MD5 hash used as aggregation bucket key.
     */
    function intersoccer_consolidated_roster_group_key(array $row, $kind) {
        $kind = strtolower((string) $kind) === 'course' ? 'course' : 'camp';
        $pid = intersoccer_wpml_canonical_product_id((int) ($row['product_id'] ?? 0));
        $facet = 'intersoccer_roster_facet_for_grouping';
        $season = $facet($row['season'] ?? '', 'pa_program-season');
        $venue = $facet($row['venue'] ?? '', 'pa_intersoccer-venues');
        $age = $facet($row['age_group'] ?? '', 'pa_age-group');
        $times = $facet($row['times'] ?? '', 'pa_course-times');
        if ($times === '') {
            $times = $facet($row['times'] ?? '', 'pa_camp-times');
        }
        if ($kind === 'course') {
            $day = $facet($row['course_day'] ?? '', 'pa_course-day');
            return md5('course|' . $pid . '|' . $day . '|' . $venue . '|' . $age . '|' . $times . '|' . $season);
        }
        $camp = $facet($row['camp_terms'] ?? '', 'pa_camp-terms');
        $g = isset($row['girls_only']) ? (int) $row['girls_only'] : 0;
        return md5('camp|' . $pid . '|' . $camp . '|' . $venue . '|' . $age . '|' . $times . '|' . $season . '|' . $g);
    }
}

if (!function_exists('intersoccer_resolve_listing_group_event_signatures')) {
    /**
     * Unique event signatures for a camps/courses listing group row.
     *
     * @param array<string,mixed> $group
     * @return array<int,string>
     */
    function intersoccer_resolve_listing_group_event_signatures(array $group) {
        $sigs = [];
        if (!empty($group['merged_event_signatures']) && is_array($group['merged_event_signatures'])) {
            foreach ($group['merged_event_signatures'] as $sig) {
                $sig = trim((string) $sig);
                if ($sig !== '' && strcasecmp($sig, 'N/A') !== 0) {
                    $sigs[$sig] = $sig;
                }
            }
        }

        $primary = trim((string) ($group['event_signature'] ?? ''));
        if ($primary !== '' && strcasecmp($primary, 'N/A') !== 0) {
            $sigs[$primary] = $primary;
        }

        $sigs = array_values($sigs);
        sort($sigs, SORT_STRING);
        return $sigs;
    }
}

if (!function_exists('intersoccer_get_roster_details_url_for_listing_group')) {
    /**
     * Admin URL for roster details from a listing card (camps/courses/girls-only).
     *
     * Prefers event_signature when unified; falls back to order_item_ids only when no signatures exist.
     *
     * @param array<string,mixed> $group
     * @param string              $from camps|courses|girls-only
     * @return string
     */
    function intersoccer_get_roster_details_url_for_listing_group(array $group, $from) {
        $from = sanitize_key((string) $from);
        $params = [
            'page' => 'intersoccer-roster-details',
            'from' => $from,
        ];

        $sigs = intersoccer_resolve_listing_group_event_signatures($group);

        if (count($sigs) === 1) {
            $params['event_signature'] = $sigs[0];
        } elseif (count($sigs) > 1) {
            $params['event_signatures'] = implode(',', $sigs);
        }

        if (!empty($params['event_signature']) || !empty($params['event_signatures'])) {
            $venue = trim((string) ($group['venue'] ?? ''));
            if ($venue !== '' && strcasecmp($venue, 'N/A') !== 0) {
                $params['venue'] = $venue;
            }
            $season = trim((string) ($group['season'] ?? ''));
            if ($season !== '' && strcasecmp($season, 'N/A') !== 0) {
                $params['season'] = $season;
            }
            if ($from === 'courses' || $from === 'girls-only') {
                $course_day = trim((string) ($group['course_day'] ?? ''));
                if ($course_day !== '' && strcasecmp($course_day, 'N/A') !== 0) {
                    $params['course_day'] = $course_day;
                }
            } elseif ($from === 'camps') {
                $camp_terms = trim((string) ($group['camp_terms'] ?? ''));
                if ($camp_terms !== '' && strcasecmp($camp_terms, 'N/A') !== 0) {
                    $params['camp_terms'] = $camp_terms;
                }
            }
        }

        if (empty($params['event_signature']) && empty($params['event_signatures']) && !empty($group['order_item_ids'])) {
            $ids = is_array($group['order_item_ids'])
                ? $group['order_item_ids']
                : array_filter(array_map('intval', explode(',', (string) $group['order_item_ids'])));
            if (!empty($ids)) {
                $params['order_item_ids'] = implode(',', array_map('intval', $ids));
            }
        }

        if (empty($params['event_signature']) && empty($params['event_signatures']) && empty($params['order_item_ids'])) {
            return '';
        }

        return add_query_arg($params, admin_url('admin.php'));
    }
}

if (!function_exists('intersoccer_compute_day_presence')) {
    /**
     * Compute Monday–Friday day_presence array from booking_type + selected_days.
     *
     * @param mixed $booking_type
     * @param mixed $selected_days
     * @return array<string,string> Map of Monday..Friday => Yes/No
     */
    function intersoccer_compute_day_presence($booking_type, $selected_days) {
        $presence = ['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No'];

        $booking_slug = intersoccer_normalize_booking_type_slug_for_reports($booking_type);
        if ($booking_slug === 'full-week') {
            return ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
        }

        $raw = trim((string) $selected_days);
        if ($raw === '') {
            return $presence;
        }

        // Split on comma, semicolon, slash, pipe, and whitespace (handles "Monday Wednesday" / newlines).
        $tokens = preg_split('/[,;\/|\s]+/u', $raw) ?: [];
        $tokens = array_map('trim', $tokens);
        $tokens = array_values(array_filter($tokens, static function ($v) { return $v !== ''; }));

        foreach ($tokens as $token) {
            $canonical = intersoccer_normalize_weekday_token($token);
            if ($canonical && array_key_exists($canonical, $presence)) {
                $presence[$canonical] = 'Yes';
            }
        }

        return $presence;
    }
}

if (!function_exists('intersoccer_reports_region_matches_filter')) {
    /**
     * Whether a camp/course canton value matches the selected region filter (flexible: label vs slug spacing).
     *
     * @param string $entry_canton Canton on the row (may be empty).
     * @param string $filter_region Selected filter (admin).
     * @return bool True if filter is empty or values match.
     */
    function intersoccer_reports_region_matches_filter($entry_canton, $filter_region) {
        if ($filter_region === null || $filter_region === '') {
            return true;
        }
        $a = strtolower(trim((string) $entry_canton));
        $b = strtolower(trim((string) $filter_region));
        if ($a === '' || strcasecmp($a, 'unknown') === 0) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $a2 = preg_replace('/[\s\-_]+/', '', $a);
        $b2 = preg_replace('/[\s\-_]+/', '', $b);
        return $a2 === $b2;
    }
}

if (!function_exists('intersoccer_reports_order_has_buyclub_coupon')) {
    /**
     * Whether the order used a BuyClub-style coupon (substring match, filterable patterns).
     *
     * @param int $order_id WooCommerce order ID.
     * @return bool
     */
    function intersoccer_reports_order_has_buyclub_coupon($order_id) {
        static $cache = [];
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }
        if (array_key_exists($order_id, $cache)) {
            return $cache[$order_id];
        }
        $patterns = apply_filters('intersoccer_reports_buyclub_coupon_patterns', ['buyclub']);
        $codes = [];
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $codes = $order->get_coupon_codes();
            }
        }
        $hit = false;
        foreach ($codes as $code) {
            $lc = strtolower((string) $code);
            foreach ((array) $patterns as $p) {
                $p = (string) $p;
                if ($p !== '' && strpos($lc, strtolower($p)) !== false) {
                    $hit = true;
                    break 2;
                }
            }
        }
        $cache[$order_id] = $hit;
        return $hit;
    }
}

if (!function_exists('intersoccer_reports_row_should_exclude_for_buyclub_option')) {
    /**
     * When admin opts to exclude BuyClub registrations, skip rows that match coupon patterns or zero-net line heuristic.
     *
     * @param array $entry Report row (order_id, is_buyclub, ...).
     * @param bool  $exclude_buyclub Whether exclusion is enabled.
     * @return bool True if this row should be skipped.
     */
    function intersoccer_reports_row_should_exclude_for_buyclub_option(array $entry, $exclude_buyclub) {
        if (!$exclude_buyclub) {
            return false;
        }
        if (!empty($entry['is_buyclub'])) {
            return true;
        }
        $oid = isset($entry['order_id']) ? (int) $entry['order_id'] : 0;
        if ($oid <= 0) {
            return false;
        }
        return intersoccer_reports_order_has_buyclub_coupon($oid);
    }
}

if (!function_exists('intersoccer_reports_enrich_camp_canton_from_product')) {
    /**
     * Fill missing/Unknown canton from product or variation pa_canton-region (English term name when possible).
     *
     * @param array $entry Report row (modified in place).
     * @return void
     */
    function intersoccer_reports_enrich_camp_canton_from_product(array &$entry) {
        $canton = isset($entry['canton']) ? trim((string) $entry['canton']) : '';
        if ($canton !== '' && strcasecmp($canton, 'Unknown') !== 0) {
            return;
        }
        $product_id = isset($entry['product_id']) ? (int) $entry['product_id'] : 0;
        $order_item_id = isset($entry['order_item_id']) ? (int) $entry['order_item_id'] : 0;
        if (!$product_id && $order_item_id) {
            $product_id = (int) wc_get_order_item_meta($order_item_id, '_product_id', true);
            if ($product_id) {
                $entry['product_id'] = $product_id;
            }
        }
        if (!$product_id) {
            return;
        }
        $variation_id = isset($entry['variation_id']) ? (int) $entry['variation_id'] : 0;
        if (!$variation_id && $order_item_id) {
            $variation_id = (int) wc_get_order_item_meta($order_item_id, '_variation_id', true);
        }
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return;
        }
        $attr = $product->get_attribute('pa_canton-region');
        if (($attr === '' || $attr === null) && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $parent = wc_get_product($parent_id);
                if ($parent) {
                    $attr = $parent->get_attribute('pa_canton-region');
                }
            }
        }
        if ($attr === '' || $attr === null) {
            return;
        }
        if (function_exists('intersoccer_get_term_name')) {
            $name = intersoccer_get_term_name($attr, 'pa_canton-region');
            if ($name !== '' && $name !== 'N/A') {
                $entry['canton'] = $name;
                return;
            }
        }
        $entry['canton'] = $attr;
    }
}

if (!function_exists('intersoccer_reports_apply_venue_slug_to_entry')) {
    /**
     * @param array  $entry Course report row (by ref).
     * @param mixed  $slug  Term slug, name, or numeric term ID.
     */
    function intersoccer_reports_apply_venue_slug_to_entry(array &$entry, $slug) {
        if ($slug === '' || $slug === null) {
            return;
        }
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue !== '' && strcasecmp($venue, 'Unknown') !== 0) {
            return;
        }
        if (is_numeric($slug)) {
            $term = get_term((int) $slug, 'pa_intersoccer-venues');
            if ($term && !is_wp_error($term)) {
                $entry['venue'] = $term->name;
            }
            return;
        }
        if (function_exists('intersoccer_get_term_name')) {
            $name = intersoccer_get_term_name((string) $slug, 'pa_intersoccer-venues');
            if ($name !== '' && $name !== 'N/A') {
                $entry['venue'] = $name;
            }
        }
    }
}

if (!function_exists('intersoccer_reports_apply_course_day_slug_to_entry')) {
    /**
     * @param array  $entry Course report row (by ref).
     * @param mixed  $slug  Term slug, name, or numeric term ID.
     */
    function intersoccer_reports_apply_course_day_slug_to_entry(array &$entry, $slug) {
        if ($slug === '' || $slug === null) {
            return;
        }
        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if ($cd !== '' && strcasecmp($cd, 'Unknown') !== 0) {
            return;
        }
        if (is_numeric($slug)) {
            $term = get_term((int) $slug, 'pa_course-day');
            if ($term && !is_wp_error($term)) {
                $entry['course_day'] = $term->name;
            }
            return;
        }
        if (function_exists('intersoccer_get_term_name')) {
            $name = intersoccer_get_term_name((string) $slug, 'pa_course-day');
            if ($name !== '' && $name !== 'N/A') {
                $entry['course_day'] = $name;
                return;
            }
        }
        if ((string) $slug !== '') {
            $entry['course_day'] = (string) $slug;
        }
    }
}

if (!function_exists('intersoccer_reports_enrich_course_venue_and_day')) {
    /**
     * Resolve venue and course day from product/variation when order meta join missed them.
     *
     * Order line meta and post meta are applied before wc_get_product() so rows still resolve
     * when the product object cannot be loaded (e.g. edge cases) or WPML ID mismatch.
     *
     * @param array $entry Course report row.
     * @return void
     */
    function intersoccer_reports_enrich_course_venue_and_day(array &$entry) {
        $order_item_id = isset($entry['order_item_id']) ? (int) $entry['order_item_id'] : 0;
        $product_id = isset($entry['product_id']) ? (int) $entry['product_id'] : 0;
        if (!$product_id && $order_item_id) {
            $product_id = (int) wc_get_order_item_meta($order_item_id, '_product_id', true);
            if ($product_id) {
                $entry['product_id'] = $product_id;
            }
        }
        $variation_id = isset($entry['variation_id']) ? (int) $entry['variation_id'] : 0;
        if (!$variation_id && $order_item_id) {
            $variation_id = (int) wc_get_order_item_meta($order_item_id, '_variation_id', true);
        }
        if ($product_id) {
            $entry['product_id'] = $product_id;
        }
        if ($variation_id) {
            $entry['variation_id'] = $variation_id;
        }

        $venue_in = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        $cd_in = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';

        // Placeholder "Unknown" from SQL/UI must not block resolution (same idea as course_day).
        if ($venue_in !== '' && strcasecmp($venue_in, 'Unknown') === 0) {
            $entry['venue'] = '';
        }
        if ($cd_in !== '' && strcasecmp($cd_in, 'Unknown') === 0) {
            $entry['course_day'] = '';
        }

        $def_pid = $product_id;
        $def_vid = $variation_id;
        if (function_exists('apply_filters')) {
            if ($variation_id) {
                $mapped = apply_filters('wpml_object_id', $variation_id, 'product_variation', true);
                if ($mapped) {
                    $def_vid = (int) $mapped;
                }
            }
            if ($product_id) {
                $mapped = apply_filters('wpml_object_id', $product_id, 'product', true);
                if ($mapped) {
                    $def_pid = (int) $mapped;
                }
            }
        }

        // Venue: order line meta first (does not require WC_Product).
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $order_item_id) {
            $slug = wc_get_order_item_meta($order_item_id, 'pa_intersoccer-venues', true);
            if ($slug === '' || $slug === null) {
                $slug = wc_get_order_item_meta($order_item_id, 'attribute_pa_intersoccer-venues', true);
            }
            if ($slug === '' || $slug === null) {
                foreach (['pa_intersoccer_venues', 'attribute_pa_intersoccer_venues'] as $alt_venue_key) {
                    $slug = wc_get_order_item_meta($order_item_id, $alt_venue_key, true);
                    if ($slug !== '' && $slug !== null) {
                        break;
                    }
                }
            }
            intersoccer_reports_apply_venue_slug_to_entry($entry, $slug);
        }

        // Venue: raw post meta on variation / product / parent defaults (checkout stores these even when SQL join misses).
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $def_vid) {
            intersoccer_reports_apply_venue_slug_to_entry($entry, get_post_meta($def_vid, 'attribute_pa_intersoccer-venues', true));
        }
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $def_pid) {
            intersoccer_reports_apply_venue_slug_to_entry($entry, get_post_meta($def_pid, 'attribute_pa_intersoccer-venues', true));
        }
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $def_vid) {
            $parent_id = (int) wp_get_post_parent_id($def_vid);
            if ($parent_id) {
                $slug = get_post_meta($parent_id, 'attribute_pa_intersoccer-venues', true);
                if ($slug === '' || $slug === null) {
                    $defaults = get_post_meta($parent_id, '_default_attributes', true);
                    if (is_array($defaults) && isset($defaults['pa_intersoccer-venues'])) {
                        $slug = $defaults['pa_intersoccer-venues'];
                    }
                }
                intersoccer_reports_apply_venue_slug_to_entry($entry, $slug);
            }
        }

        // WPML default-language IDs can differ from the order line's variation/product: read attributes on originals too.
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $variation_id && $variation_id !== $def_vid) {
            intersoccer_reports_apply_venue_slug_to_entry($entry, get_post_meta($variation_id, 'attribute_pa_intersoccer-venues', true));
        }
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $product_id && $product_id !== $def_pid) {
            intersoccer_reports_apply_venue_slug_to_entry($entry, get_post_meta($product_id, 'attribute_pa_intersoccer-venues', true));
        }
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $variation_id && $variation_id !== $def_vid) {
            $parent_orig = (int) wp_get_post_parent_id($variation_id);
            if ($parent_orig) {
                $slug = get_post_meta($parent_orig, 'attribute_pa_intersoccer-venues', true);
                if ($slug === '' || $slug === null) {
                    $defaults = get_post_meta($parent_orig, '_default_attributes', true);
                    if (is_array($defaults) && isset($defaults['pa_intersoccer-venues'])) {
                        $slug = $defaults['pa_intersoccer-venues'];
                    }
                }
                intersoccer_reports_apply_venue_slug_to_entry($entry, $slug);
            }
        }

        $product = null;
        if ($def_vid || $def_pid) {
            $product = wc_get_product($def_vid ?: $def_pid);
            if (!$product && $def_vid) {
                $product = wc_get_product($def_vid);
            }
            if (!$product && $def_pid) {
                $product = wc_get_product($def_pid);
            }
        }
        if (!$product && $variation_id && $variation_id !== $def_vid) {
            $product = wc_get_product($variation_id);
        }
        if (!$product && $product_id && $product_id !== $def_pid && (!$variation_id || (int) $product_id !== (int) $variation_id)) {
            $product = wc_get_product($product_id);
        }

        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $product) {
            $slug = $product->get_attribute('pa_intersoccer-venues');
            if (($slug === '' || $slug === null) && $product->is_type('variation')) {
                $parent_id = (int) $product->get_parent_id();
                if ($parent_id) {
                    $parent = wc_get_product($parent_id);
                    if ($parent) {
                        $slug = $parent->get_attribute('pa_intersoccer-venues');
                    }
                }
            }
            intersoccer_reports_apply_venue_slug_to_entry($entry, $slug);
        }

        // Course day: order meta and post meta before relying on product object only.
        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if ($cd === '' || strcasecmp($cd, 'Unknown') === 0) {
            if ($order_item_id) {
                $cd_attr = wc_get_order_item_meta($order_item_id, 'pa_course-day', true);
                if ($cd_attr === '' || $cd_attr === null) {
                    $cd_attr = wc_get_order_item_meta($order_item_id, 'attribute_pa_course-day', true);
                }
                if ($cd_attr === '' || $cd_attr === null) {
                    foreach (['pa_course_day', 'attribute_pa_course_day'] as $alt_day_key) {
                        $cd_attr = wc_get_order_item_meta($order_item_id, $alt_day_key, true);
                        if ($cd_attr !== '' && $cd_attr !== null) {
                            break;
                        }
                    }
                }
                intersoccer_reports_apply_course_day_slug_to_entry($entry, $cd_attr);
            }
        }
        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if (($cd === '' || strcasecmp($cd, 'Unknown') === 0) && $def_vid) {
            intersoccer_reports_apply_course_day_slug_to_entry($entry, get_post_meta($def_vid, 'attribute_pa_course-day', true));
        }
        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if (($cd === '' || strcasecmp($cd, 'Unknown') === 0) && $def_pid) {
            intersoccer_reports_apply_course_day_slug_to_entry($entry, get_post_meta($def_pid, 'attribute_pa_course-day', true));
        }
        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if (($cd === '' || strcasecmp($cd, 'Unknown') === 0) && $def_vid) {
            $parent_id = (int) wp_get_post_parent_id($def_vid);
            if ($parent_id) {
                $cd_attr = get_post_meta($parent_id, 'attribute_pa_course-day', true);
                if ($cd_attr === '' || $cd_attr === null) {
                    $defaults = get_post_meta($parent_id, '_default_attributes', true);
                    if (is_array($defaults) && isset($defaults['pa_course-day'])) {
                        $cd_attr = $defaults['pa_course-day'];
                    }
                }
                intersoccer_reports_apply_course_day_slug_to_entry($entry, $cd_attr);
            }
        }

        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if (($cd === '' || strcasecmp($cd, 'Unknown') === 0) && $variation_id && $variation_id !== $def_vid) {
            intersoccer_reports_apply_course_day_slug_to_entry($entry, get_post_meta($variation_id, 'attribute_pa_course-day', true));
        }
        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if (($cd === '' || strcasecmp($cd, 'Unknown') === 0) && $product_id && $product_id !== $def_pid) {
            intersoccer_reports_apply_course_day_slug_to_entry($entry, get_post_meta($product_id, 'attribute_pa_course-day', true));
        }
        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if (($cd === '' || strcasecmp($cd, 'Unknown') === 0) && $variation_id && $variation_id !== $def_vid) {
            $parent_orig = (int) wp_get_post_parent_id($variation_id);
            if ($parent_orig) {
                $cd_attr = get_post_meta($parent_orig, 'attribute_pa_course-day', true);
                if ($cd_attr === '' || $cd_attr === null) {
                    $defaults = get_post_meta($parent_orig, '_default_attributes', true);
                    if (is_array($defaults) && isset($defaults['pa_course-day'])) {
                        $cd_attr = $defaults['pa_course-day'];
                    }
                }
                intersoccer_reports_apply_course_day_slug_to_entry($entry, $cd_attr);
            }
        }

        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if (($cd === '' || strcasecmp($cd, 'Unknown') === 0) && $product) {
            $cd_attr = $product->get_attribute('pa_course-day');
            if (($cd_attr === '' || $cd_attr === null) && $product->is_type('variation')) {
                $parent_id = (int) $product->get_parent_id();
                if ($parent_id) {
                    $parent = wc_get_product($parent_id);
                    if ($parent) {
                        $cd_attr = $parent->get_attribute('pa_course-day');
                    }
                }
            }
            intersoccer_reports_apply_course_day_slug_to_entry($entry, $cd_attr);
        }

        // Variable product + missing _variation_id: venue/day live on variation posts only (legacy/broken line items).
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $product_id && !$variation_id) {
            $pv = wc_get_product($def_pid ?: $product_id);
            if (!$pv) {
                $pv = wc_get_product($product_id);
            }
            if ($pv && $pv->is_type('variable')) {
                foreach ($pv->get_children() as $cid) {
                    $cid = (int) $cid;
                    $slug = get_post_meta($cid, 'attribute_pa_intersoccer-venues', true);
                    intersoccer_reports_apply_venue_slug_to_entry($entry, $slug);
                    $v_try = trim((string) ($entry['venue'] ?? ''));
                    if ($v_try !== '' && strcasecmp($v_try, 'Unknown') !== 0) {
                        break;
                    }
                }
            }
        }
        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if (($cd === '' || strcasecmp($cd, 'Unknown') === 0) && $product_id && !$variation_id) {
            $pv = wc_get_product($def_pid ?: $product_id);
            if (!$pv) {
                $pv = wc_get_product($product_id);
            }
            if ($pv && $pv->is_type('variable')) {
                foreach ($pv->get_children() as $cid) {
                    $cid = (int) $cid;
                    $cd_attr = get_post_meta($cid, 'attribute_pa_course-day', true);
                    intersoccer_reports_apply_course_day_slug_to_entry($entry, $cd_attr);
                    $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
                    if ($cd !== '' && strcasecmp($cd, 'Unknown') !== 0) {
                        break;
                    }
                }
            }
        }

        // Scan all line-item meta keys (custom checkouts, translated labels, or non-canonical keys).
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        $need_venue = ($venue === '' || strcasecmp($venue, 'Unknown') === 0);
        $need_cd = ($cd === '' || strcasecmp($cd, 'Unknown') === 0);
        if (($need_venue || $need_cd) && $order_item_id && function_exists('wc_get_order')) {
            $order_id = isset($entry['order_id']) ? (int) $entry['order_id'] : 0;
            if (!$order_id) {
                global $wpdb;
                $oi_table = $wpdb->prefix . 'woocommerce_order_items';
                $order_id = (int) $wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$oi_table} WHERE order_item_id = %d LIMIT 1", $order_item_id));
            }
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $item = $order->get_item($order_item_id);
                    if ($item && method_exists($item, 'get_meta_data')) {
                        foreach ($item->get_meta_data() as $md) {
                            $d = $md->get_data();
                            $k = strtolower((string) ($d['key'] ?? ''));
                            $v = $d['value'] ?? null;
                            if ($v === null || $v === '') {
                                continue;
                            }
                            if (is_array($v)) {
                                $v = reset($v);
                            }
                            if ($need_venue) {
                                if ((strpos($k, 'pa_intersoccer') !== false && strpos($k, 'venue') !== false)
                                    || (strpos($k, 'intersoccer') !== false && strpos($k, 'venue') !== false)) {
                                    intersoccer_reports_apply_venue_slug_to_entry($entry, $v);
                                }
                            }
                            $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
                            $need_venue = ($venue === '' || strcasecmp($venue, 'Unknown') === 0);
                            if ($need_cd) {
                                if ((strpos($k, 'course') !== false && strpos($k, 'day') !== false)
                                    || (strpos($k, 'pa_course') !== false)) {
                                    intersoccer_reports_apply_course_day_slug_to_entry($entry, $v);
                                }
                            }
                            $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
                            $need_cd = ($cd === '' || strcasecmp($cd, 'Unknown') === 0);
                            if (!$need_venue && !$need_cd) {
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Last resort: roster table (populated at checkout) when WC line meta / product attributes are empty.
        $venue = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
        if ($venue === '' && $order_item_id) {
            global $wpdb;
            $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
            $rv = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT venue FROM {$rosters_table} WHERE order_item_id = %d AND TRIM(COALESCE(venue, '')) != '' LIMIT 1",
                    $order_item_id
                )
            );
            if ($rv) {
                $rv_trim = trim((string) $rv);
                if ($rv_trim !== '' && strcasecmp($rv_trim, 'Unknown') !== 0 && strcasecmp($rv_trim, 'N/A') !== 0) {
                    $entry['venue'] = $rv;
                }
            }
        }

        $cd = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
        if (($cd === '' || strcasecmp($cd, 'Unknown') === 0) && $order_item_id) {
            global $wpdb;
            $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
            $rcd = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT course_day FROM {$rosters_table} WHERE order_item_id = %d AND TRIM(COALESCE(course_day, '')) != '' LIMIT 1",
                    $order_item_id
                )
            );
            if ($rcd) {
                $rcd_trim = trim((string) $rcd);
                if ($rcd_trim !== '' && strcasecmp($rcd_trim, 'Unknown') !== 0 && strcasecmp($rcd_trim, 'N/A') !== 0) {
                    $entry['course_day'] = $rcd;
                }
            }
        }

        $canton = isset($entry['canton']) ? trim((string) $entry['canton']) : '';
        if ($canton === '' || strcasecmp($canton, 'Unknown') === 0) {
            intersoccer_reports_enrich_camp_canton_from_product($entry);
        }
    }
}

/**
 * Shared function to insert or update a roster entry from an order item.
 * Ensures consistent data extraction and insertion across all population points.
 *
 * @param int $order_id Order ID.
 * @param int $item_id Order item ID.
 * @return bool True if inserted/updated successfully, false otherwise.
 */
function intersoccer_update_roster_entry($order_id, $item_id) {
    // OOP-only: delegate to OrderProcessor
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_oop_update_roster_entry')) {
        $result = intersoccer_oop_update_roster_entry($order_id, $item_id);
        return !empty($result['success']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'intersoccer_rosters';

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('InterSoccer: Invalid order ID ' . $order_id . ' in upsert_roster_entry');
        return false;
    }

    $item = $order->get_item($item_id);
    if (!$item) {
        error_log('InterSoccer: Invalid item ID ' . $item_id . ' in order ' . $order_id . ' for upsert_roster_entry');
        return false;
    }

    $product_id = $item->get_product_id();
    $variation_id = $item->get_variation_id();
    $type_id = $variation_id ?: $product_id;
    $product_type = intersoccer_get_product_type_safe($product_id, $variation_id);
    error_log('InterSoccer: Item ' . $item_id . ' product_type: ' . $product_type . ' (using ID: ' . $type_id . ', parent: ' . $product_id . ', variation: ' . $variation_id . ')');

    if (!in_array($product_type, ['camp', 'course', 'birthday', 'tournament'])) {
        error_log('InterSoccer: Skipping item ' . $item_id . ' in order ' . $order_id . ' - Reason: Non-event product type (' . $product_type . ')');
        return false;
    }

    // Get item metadata - use same robust method as prepare_roster_entry
    $raw_order_item_meta = wc_get_order_item_meta($item_id, '', true);
    error_log('InterSoccer: Raw order item meta for order ' . $order_id . ', item ' . $item_id . ': ' . print_r($raw_order_item_meta, true));

    $item_meta = array_combine(
        array_keys($raw_order_item_meta),
        array_map(function ($value, $key) {
            if ($key !== 'Activity Type' && is_array($value)) {
                return $value[0] ?? implode(', ', array_map('trim', $value));
            }
            return is_array($value) ? $value[0] ?? implode(', ', array_map('trim', $value)) : trim($value);
        }, array_values($raw_order_item_meta), array_keys($raw_order_item_meta))
    );

    // Day-related log for debugging
    $day_related_keys = array_filter($item_meta, function($k) { return stripos($k, 'course') !== false || stripos($k, 'day') !== false; }, ARRAY_FILTER_USE_KEY);
    error_log('InterSoccer: Day-related meta keys for item ' . $item_id . ': ' . print_r($day_related_keys, true));

    // Assigned Attendee
    $assigned_attendee = isset($item_meta['Assigned Attendee']) ? trim($item_meta['Assigned Attendee']) : '';
    if (empty($assigned_attendee)) {
        error_log('InterSoccer: Skipping item ' . $item_id . ' in order ' . $order_id . ' - Reason: No Assigned Attendee found');
        return false;
    }

    // Strip leading numeric prefix + space
    $assigned_attendee = preg_replace('/^\d+\s*/', '', $assigned_attendee);

    // Split name (assuming first last)
    $name_parts = explode(' ', $assigned_attendee, 2);
    $first_name = trim($name_parts[0] ?? 'Unknown');
    $last_name = trim($name_parts[1] ?? 'Unknown');

    // Normalize for matching
    $first_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $first_name) ?? $first_name)));
    $last_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $last_name) ?? $last_name)));

    // Lookup player details from user meta
    $user_id = $order->get_user_id();
    $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
    error_log('InterSoccer: User ' . $user_id . ' players meta: ' . print_r($players, true));
    $player_index = $item_meta['assigned_player'] ?? false;
    $age = isset($item_meta['Player Age']) ? (int)$item_meta['Player Age'] : null;
    $gender = $item_meta['Player Gender'] ?? 'N/A';
    $medical_conditions = $item_meta['Medical Conditions'] ?? '';
    $avs_number = 'N/A';
    $dob = null;
    $matched = false;
    if ($player_index !== false && is_array($players) && isset($players[$player_index])) {
        $player = $players[$player_index];
        $first_name = trim($player['first_name'] ?? $first_name);
        $last_name = trim($player['last_name'] ?? $last_name);
        $dob = $player['dob'] ?? null;
        $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
        $gender = $player['gender'] ?? $gender;
        $medical_conditions = trim($player['medical_conditions'] ?? $medical_conditions);
        $avs_number = $player['avs_number'] ?? 'N/A';
        $matched = true;
    } else {
        foreach ($players as $player) {
            $meta_first_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['first_name'] ?? '') ?? '')));
            $meta_last_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['last_name'] ?? '') ?? '')));
            if ($meta_first_norm === $first_name_norm && $meta_last_norm === $last_name_norm) {
                $first_name = trim($player['first_name'] ?? $first_name);
                $last_name = trim($player['last_name'] ?? $last_name);
                $dob = $player['dob'] ?? null;
                $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                $gender = $player['gender'] ?? $gender;
                $medical_conditions = trim($player['medical_conditions'] ?? $medical_conditions);
                $avs_number = $player['avs_number'] ?? 'N/A';
                $matched = true;
                break;
            }
        }
    }
    if (!$matched) {
        error_log('InterSoccer: No matching player meta for attendee ' . $assigned_attendee . ' in order ' . $order_id . ', item ' . $item_id . ' - Using order meta defaults');
        $dob = $item_meta['Attendee DOB'] ?? null;
        $dob_obj = $dob ? DateTime::createFromFormat('Y-m-d', $dob) : null;
        $age = $dob_obj ? $dob_obj->diff(new DateTime())->y : $age;
        $gender = $item_meta['Attendee Gender'] ?? $gender;
        $medical_conditions = $item_meta['Medical Conditions'] ?? $medical_conditions;
    }

    // Event details with fallbacks
    $venue = $item_meta['pa_intersoccer-venues'] ?? $item_meta['InterSoccer Venues'] ?? '';
    $age_group = $item_meta['pa_age-group'] ?? $item_meta['Age Group'] ?? '';
    $camp_terms = $item_meta['pa_camp-terms'] ?? $item_meta['Camp Terms'] ?? '';
    $times = $item_meta['pa_camp-times'] ?? $item_meta['pa_course-times'] ?? $item_meta['pa_tournament-time'] ?? $item_meta['Camp Times'] ?? $item_meta['Course Times'] ?? $item_meta['Tournament Time'] ?? '';
    $booking_type = $item_meta['pa_booking-type'] ?? $item_meta['Booking Type'] ?? '';
    $selected_days = $item_meta['Days Selected'] ?? '';
    $season = $item_meta['pa_program-season'] ?? $item_meta['Season'] ?? '';
    $canton_region = $item_meta['pa_canton-region'] ?? $item_meta['Canton / Region'] ?? '';
    $city = $item_meta['City'] ?? '';
    $activity_type = $item_meta['pa_activity-type'] ?? $item_meta['Activity Type'] ?? '';
    $start_date = $item_meta['Start Date'] ?? null;
    $end_date = $item_meta['End Date'] ?? null;
    $event_dates = 'N/A';
    // Extract course_day for Courses, tournament_day for Tournaments
    $product_type = intersoccer_get_product_type_safe($product_id, $variation_id);
    $course_day = 'N/A';
    if ($product_type === 'tournament') {
        // For Tournaments, extract Tournament Day from metadata
        $tournament_day = $item_meta['Tournament Day'] ?? $item_meta['pa_tournament-day'] ?? $raw_order_item_meta['Tournament Day'] ?? null;
        if ($tournament_day) {
            // If it's already a readable name (like "Sunday"), use it directly
            if (preg_match('/^[A-Z][a-z]+$/', $tournament_day)) {
                $course_day = $tournament_day;
            } else {
                // Otherwise try to get term name from slug
                $term = get_term_by('slug', $tournament_day, 'pa_tournament-day');
                $course_day = $term ? $term->name : ucfirst($tournament_day);
            }
        }
    } else {
        // For Courses, extract Course Day
        $course_day_slug = $item_meta['pa_course-day'] ?? $item_meta['Course Day'] ?? $raw_order_item_meta['Course Day'] ?? null;
        if ($course_day_slug) {
            $term = get_term_by('slug', $course_day_slug, 'pa_course-day');
            $course_day = $term ? $term->name : ucfirst($course_day_slug);
        }
    }
    $late_pickup = (!empty($item_meta['Late Pickup Type'])) ? 'Yes' : 'No';
    $late_pickup_days = $item_meta['Late Pickup Days'] ?? '';
    
    // Get product name and normalize to English if WPML is active
    $product_name = $item->get_name();
    if (function_exists('intersoccer_with_wpml_default_language')) {
        $product_name = intersoccer_with_wpml_default_language(static function () use ($product_id, $product_name) {
            $product = wc_get_product($product_id);
            return ($product && $product->get_name()) ? $product->get_name() : $product_name;
        });
    } elseif (function_exists('wpml_get_default_language') && function_exists('wpml_get_current_language')) {
        $current_lang = wpml_get_current_language();
        $default_lang = wpml_get_default_language();

        if ($current_lang !== $default_lang) {
            do_action('wpml_switch_language', $default_lang);
            $product = wc_get_product($product_id);
            if ($product) {
                $product_name = $product->get_name();
            }
            do_action('wpml_switch_language', $current_lang);
        }
    }
    
    $shirt_size = 'N/A';
    $shorts_size = 'N/A';

    // Determine girls_only and set activity_type to Camp or Course
    $girls_only = FALSE;
    if (!empty($activity_type)) {
        // Log the raw activity type for debugging
        error_log('InterSoccer: Raw Activity Type for order ' . $order_id . ', item ' . $item_id . ': "' . $activity_type . '"');
        
        // Normalize for comparison - handle apostrophes and case variations
        $normalized_activity = trim(strtolower(html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        
        // Split by comma and check each part
        $activity_parts = array_map('trim', explode(',', $normalized_activity));
        error_log('InterSoccer: Activity Type parts: ' . print_r($activity_parts, true));
        
        foreach ($activity_parts as $part) {
            // Remove apostrophes and check for girls only patterns
            $clean_part = str_replace(["'", '"'], '', $part);
            
            if (strpos($clean_part, 'girls only') !== false ||
                strpos($clean_part, 'girls-only') !== false ||
                strpos($clean_part, 'girlsonly') !== false) {
                
                $girls_only = TRUE;
                error_log('InterSoccer: Set girls_only = TRUE for order ' . $order_id . ', item ' . $item_id . ' based on Activity Type part: "' . $part . '"');
                break;
            }
        }
    }

    // Fallback: Check product name if Activity Type didn't indicate Girls' Only
    if (!$girls_only && !empty($product_name)) {
        $normalized_product_name = trim(strtolower(html_entity_decode($product_name, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $clean_product_name = str_replace(['-', "'", '"'], ' ', $normalized_product_name);
        
        if (strpos($clean_product_name, 'girls only') !== false) {
            $girls_only = TRUE;
            error_log('InterSoccer: Set girls_only = TRUE for order ' . $order_id . ', item ' . $item_id . ' based on product name: "' . $product_name . '"');
        }
    }

    // Apply shirt/shorts size logic for Girls Only events
    if ($girls_only) {
        error_log('InterSoccer: Applying shirt/shorts size logic for Girls Only event');
        $possible_shirt_keys = ['pa_what-size-t-shirt-does-your', 'pa_tshirt-size', 'pa_what-size-t-shirt-does-your-child-wear', 'Shirt Size', 'T-shirt Size'];
        $possible_shorts_keys = ['pa_what-size-shorts-does-your-c', 'pa_what-size-shorts-does-your-child-wear', 'Shorts Size', 'Shorts'];
        
        foreach ($possible_shirt_keys as $key) {
            if (isset($item_meta[$key]) && $item_meta[$key] !== '') {
                $shirt_size = trim($item_meta[$key]);
                error_log('InterSoccer: Found shirt size from ' . $key . ': ' . $shirt_size);
                break;
            }
        }
        
        foreach ($possible_shorts_keys as $key) {
            if (isset($item_meta[$key]) && $item_meta[$key] !== '') {
                $shorts_size = trim($item_meta[$key]);
                error_log('InterSoccer: Found shorts size from ' . $key . ': ' . $shorts_size);
                break;
            }
        }
    }
    // Set activity_type based on product_type
    $activity_type = $product_type === 'camp' ? 'Camp' : ($product_type === 'course' ? 'Course' : ucfirst($product_type));
    error_log('InterSoccer: Set activity_type to ' . $activity_type . ' for order ' . $order_id . ', item ' . $item_id);

    // Parse dates using unified parser
    if ($product_type === 'camp' && !empty($camp_terms) && $camp_terms !== 'N/A') {
        list($start_date, $end_date, $event_dates) = intersoccer_parse_camp_dates_fixed($camp_terms, $season);
    } elseif ($product_type === 'tournament') {
        // For tournaments, get date from product attribute pa_date or Date
        $variation = $variation_id ? wc_get_product($variation_id) : null;
        $parent_product = wc_get_product($product_id);
        
        $tournament_date = null;
        
        // Try to get from variation first, then default language variation, then parent product
        if ($variation) {
            $tournament_date = $variation->get_attribute('pa_date') ?: $variation->get_attribute('Date');
        }
        // If not found in original variation, try default language variation
        if (!$tournament_date && $variation_id && function_exists('intersoccer_get_default_language_variation_id')) {
            $default_variation_id = intersoccer_get_default_language_variation_id($variation_id);
            if ($default_variation_id != $variation_id) {
                $default_variation = wc_get_product($default_variation_id);
                if ($default_variation) {
                    $tournament_date = $default_variation->get_attribute('pa_date') ?: $default_variation->get_attribute('Date');
                    if ($tournament_date) {
                        error_log('InterSoccer: Found tournament date in default language variation ' . $default_variation_id . ' attributes: ' . $tournament_date . ' (order ' . $order_id . ', item ' . $item_id . ')');
                    }
                }
            }
        }
        if (!$tournament_date && $parent_product) {
            $tournament_date = $parent_product->get_attribute('pa_date') ?: $parent_product->get_attribute('Date');
        }
        // Also check order item metadata as fallback - try multiple key variations
        if (!$tournament_date) {
            $possible_keys = ['Date', 'date', 'pa_date', 'Date (fr)', 'Tournament Date'];
            foreach ($possible_keys as $key) {
                if (isset($item_meta[$key]) && !empty($item_meta[$key])) {
                    $tournament_date = is_array($item_meta[$key]) ? ($item_meta[$key][0] ?? null) : $item_meta[$key];
                    if (!empty($tournament_date)) {
                        $tournament_date = trim($tournament_date);
                        break;
                    }
                }
            }
        }
        
        if ($tournament_date) {
            error_log('InterSoccer: Found tournament date attribute for order ' . $order_id . ', item ' . $item_id . ': ' . $tournament_date);
            
            // Clean up the date string (remove extra whitespace, handle slug format)
            $tournament_date = trim($tournament_date);
            
            // Handle slug format like "dimanche-14-decembre" - convert to readable format
            if (preg_match('/^([a-z]+)-(\d+)-([a-z]+)$/i', $tournament_date, $slug_matches)) {
                $day_name = ucfirst($slug_matches[1]);
                $day_num = $slug_matches[2];
                $month_name = ucfirst($slug_matches[3]);
                // Convert French month names to full format
                $french_months = [
                    'janvier' => 'janvier', 'février' => 'février', 'mars' => 'mars', 'avril' => 'avril',
                    'mai' => 'mai', 'juin' => 'juin', 'juillet' => 'juillet', 'août' => 'août',
                    'septembre' => 'septembre', 'octobre' => 'octobre', 'novembre' => 'novembre', 'décembre' => 'décembre'
                ];
                $month_lower = strtolower($month_name);
                if (isset($french_months[$month_lower])) {
                    $month_name = $french_months[$month_lower];
                }
                $tournament_date = $day_name . ' ' . $day_num . ' ' . $month_name;
                error_log('InterSoccer: Converted slug format date to readable format: ' . $tournament_date . ' (order ' . $order_id . ', item ' . $item_id . ')');
            }
            
            // If the date doesn't have a year, try to extract it from the season
            if (!preg_match('/\d{4}/', $tournament_date)) {
                // No year in date string, try to extract from season
                // Handle French season names: "Automne 2025", "Printemps 2025", etc.
                if (preg_match('/(\d{4})/', $season, $matches)) {
                    $year = $matches[1];
                    // Try different formats: with day name, without day name
                    if (preg_match('/^[A-Z][a-z]+\s+\d+\s+[A-Za-zàâäéèêëïîôùûüÿç]+$/', $tournament_date)) {
                        // Format: "Dimanche 14 décembre" - add year at the end
                        $tournament_date = $tournament_date . ' ' . $year;
                    } else {
                        // Format: "14 décembre" - add year at the end
                        $tournament_date = $tournament_date . ' ' . $year;
                    }
                    error_log('InterSoccer: Adding year ' . $year . ' from season "' . $season . '" to date "' . $tournament_date . '" (order ' . $order_id . ', item ' . $item_id . ')');
                }
            }
            
            $context = "order $order_id, item $item_id (tournament date)";
            $parsed_date = intersoccer_parse_date_unified($tournament_date, $context);
            
            if ($parsed_date) {
                // Tournaments are typically one day, so use same date for start and end
                $start_date = $parsed_date;
                $end_date = $parsed_date;
                $event_dates = $start_date;
                error_log('InterSoccer: Parsed tournament date: ' . $start_date);
            } else {
                error_log('InterSoccer: Failed to parse tournament date "' . $tournament_date . '" for order ' . $order_id . ', item ' . $item_id);
                $start_date = '1970-01-01';
                $end_date = '1970-01-01';
                $event_dates = 'N/A';
            }
        } else {
            // Fallback to Start Date/End Date from order item metadata if available
            if (!empty($start_date) && !empty($end_date)) {
                error_log('InterSoccer: Tournament date attribute not found, trying Start Date/End Date from metadata for order ' . $order_id . ', item ' . $item_id);
                $context = "order $order_id, item $item_id";
                $parsed_start = intersoccer_parse_date_unified($start_date, $context . ' (start)');
                $parsed_end = intersoccer_parse_date_unified($end_date, $context . ' (end)');
                
                if ($parsed_start && $parsed_end) {
                    $start_date = $parsed_start;
                    $end_date = $parsed_end;
                    $event_dates = "$start_date to $end_date";
                } else {
                    error_log('InterSoccer: Date parsing failed for order ' . $order_id . ', item ' . $item_id . ' - Using defaults');
                    $start_date = '1970-01-01';
                    $end_date = '1970-01-01';
                    $event_dates = 'N/A';
                }
            } else {
                error_log('InterSoccer: No tournament date found (checked pa_date, Date attribute, and Start Date/End Date metadata) for order ' . $order_id . ', item ' . $item_id . ' - Using defaults');
                $start_date = '1970-01-01';
                $end_date = '1970-01-01';
                $event_dates = 'N/A';
            }
        }
    } elseif ($product_type === 'course' && !empty($start_date) && !empty($end_date)) {
        error_log('InterSoccer: Processing course dates for item ' . $item_id . ' in order ' . $order_id . ' - start_date: ' . var_export($start_date, true) . ', end_date: ' . var_export($end_date, true));
        
        $context = "order $order_id, item $item_id";
        $parsed_start = intersoccer_parse_date_unified($start_date, $context . ' (start)');
        $parsed_end = intersoccer_parse_date_unified($end_date, $context . ' (end)');
        
        if ($parsed_start && $parsed_end) {
            $start_date = $parsed_start;
            $end_date = $parsed_end;
            $event_dates = "$start_date to $end_date";
        } else {
            error_log('InterSoccer: Invalid course date format for item ' . $item_id . ' in order ' . $order_id . ' - Using defaults');
            $start_date = '1970-01-01';
            $end_date = '1970-01-01';
            $event_dates = 'N/A';
        }
    }

    // Day presence
    $day_presence = ['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No'];
    $booking_type_en = function_exists('intersoccer_normalize_booking_type_for_storage')
        ? intersoccer_normalize_booking_type_for_storage($booking_type)
        : (string) $booking_type;
    $selected_days_en = function_exists('intersoccer_normalize_selected_days_for_storage')
        ? intersoccer_normalize_selected_days_for_storage($selected_days)
        : (string) $selected_days;

    $normalized_booking_type_slug = function_exists('intersoccer_normalize_booking_type_slug_for_reports')
        ? intersoccer_normalize_booking_type_slug_for_reports($booking_type_en)
        : strtolower($booking_type_en);

    if ($normalized_booking_type_slug === 'single-days') {
        $days = array_map('trim', explode(',', $selected_days_en));
        foreach ($days as $day) {
            $canonical_day = function_exists('intersoccer_normalize_weekday_token')
                ? intersoccer_normalize_weekday_token($day)
                : $day;

            if ($canonical_day && array_key_exists($canonical_day, $day_presence)) {
                $day_presence[$canonical_day] = 'Yes';
            }
        }
    } elseif ($normalized_booking_type_slug === 'full-week') {
        $day_presence = ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
    }

    // Order and parent info
    $date_created = $order->get_date_created();
    $order_date = $date_created instanceof WC_DateTime ? $date_created->format('Y-m-d H:i:s') : current_time('mysql');
    $parent_phone = $order->get_billing_phone() ?: 'N/A';
    $parent_email = $order->get_billing_email() ?: 'N/A';
    $parent_first_name = $order->get_billing_first_name() ?: 'Unknown';
    $parent_last_name = $order->get_billing_last_name() ?: 'Unknown';

    // Financial data
    $base_price = (float) $item->get_subtotal();
    $final_price = (float) $item->get_total();
    $discount_amount = $base_price - $final_price;
    $reimbursement = 0; // TODO: Calculate from meta if needed
    $discount_codes = implode(',', $order->get_coupon_codes());

    // Normalize event data to English for consistent storage
    // This ensures all roster entries are stored in English regardless of order language
    $original_event_data = [
        'activity_type' => $activity_type,
        'venue' => $venue,
        'age_group' => $age_group,
        'camp_terms' => $camp_terms,
        'course_day' => $course_day,
        'times' => $times,
        'season' => $season,
        'girls_only' => $girls_only,
        'city' => $city,
        'canton_region' => $canton_region,
        'product_id' => $product_id,
        'start_date' => $start_date, // Include date for tournament signature generation
    ];
    
    // Log original event data before normalization
    error_log('InterSoccer: Original event data (Order: ' . $order_id . ', Item: ' . $item_id . '): ' . json_encode($original_event_data));
    
    $normalized_event_data = intersoccer_normalize_event_data_for_signature($original_event_data);
    
    // Add start_date back to normalized data for signature generation (normalization doesn't modify dates)
    $normalized_event_data['start_date'] = $start_date;
    
    // Log normalized event data after normalization
    error_log('InterSoccer: Normalized event data (Order: ' . $order_id . ', Item: ' . $item_id . '): ' . json_encode($normalized_event_data));

    // Use normalized values for storage
    $normalized_venue = $normalized_event_data['venue'] ?? $venue;
    $normalized_age_group = $normalized_event_data['age_group'] ?? $age_group;
    $normalized_camp_terms = $normalized_event_data['camp_terms'] ?? $camp_terms;
    $normalized_course_day = $normalized_event_data['course_day'] ?? $course_day;
    $normalized_times = $normalized_event_data['times'] ?? $times;
    $normalized_season = $normalized_event_data['season'] ?? $season;
    $normalized_city = $normalized_event_data['city'] ?? $city;
    $normalized_canton_region = $normalized_event_data['canton_region'] ?? $canton_region;
    $normalized_activity_type = $normalized_event_data['activity_type'] ?? ($activity_type ?: 'Event');

    $data = [
        'order_id' => $order_id,
        'order_item_id' => $item_id,
        'variation_id' => $variation_id,
        'player_name' => substr((string)($assigned_attendee ?: 'Unknown Player'), 0, 255),
        'first_name' => substr((string)($first_name ?: 'Unknown'), 0, 100),
        'last_name' => substr((string)($last_name ?: 'Unknown'), 0, 100),
        'age' => $age,
        'gender' => substr((string)($gender ?: 'N/A'), 0, 20),
        'booking_type' => substr((string)($booking_type_en ?: 'Unknown'), 0, 50),
        'selected_days' => $selected_days_en,
        'camp_terms' => substr((string)($normalized_camp_terms ?: 'N/A'), 0, 100),
        'venue' => substr((string)($normalized_venue ?: 'Unknown Venue'), 0, 200),
        'parent_phone' => substr((string)($parent_phone ?: 'N/A'), 0, 20),
        'parent_email' => substr((string)($parent_email ?: 'N/A'), 0, 100),
        'medical_conditions' => $medical_conditions,
        'late_pickup' => $late_pickup,
        'late_pickup_days' => $late_pickup_days,
        'day_presence' => json_encode($day_presence),
        'age_group' => substr((string)($normalized_age_group ?: 'N/A'), 0, 50),
        'start_date' => $start_date ?: '1970-01-01',
        'end_date' => $end_date ?: '1970-01-01',
        'event_dates' => substr((string)($event_dates ?: 'N/A'), 0, 100),
        'product_name' => substr((string)($product_name ?: 'Unknown Product'), 0, 255), // Already normalized to English above
        'activity_type' => substr((string)($normalized_activity_type), 0, 50),
        'shirt_size' => substr((string)($shirt_size ?: 'N/A'), 0, 50),
        'shorts_size' => substr((string)($shorts_size ?: 'N/A'), 0, 50),
        'registration_timestamp' => $order_date,
        'course_day' => substr((string)($normalized_course_day ?: 'N/A'), 0, 20),
        'product_id' => $product_id,
        'player_first_name' => substr((string)($first_name ?: 'Unknown'), 0, 100),
        'player_last_name' => substr((string)($last_name ?: 'Unknown'), 0, 100),
        'player_dob' => $dob ?: '1970-01-01',
        'player_gender' => substr((string)($gender ?: 'N/A'), 0, 10),
        'player_medical' => $medical_conditions,
        'player_dietary' => '',
        'parent_first_name' => substr((string)($parent_first_name ?: 'Unknown'), 0, 100),
        'parent_last_name' => substr((string)($parent_last_name ?: 'Unknown'), 0, 100),
        'emergency_contact' => substr((string)($parent_phone ?: 'N/A'), 0, 20),
        'term' => substr((string)(($normalized_camp_terms ?: $normalized_course_day) ?: 'N/A'), 0, 200),
        'times' => substr((string)($normalized_times ?: 'N/A'), 0, 50),
        'days_selected' => substr((string)($selected_days_en ?: 'N/A'), 0, 200),
        'season' => substr((string)($normalized_season ?: 'N/A'), 0, 50),
        'canton_region' => substr((string)($normalized_canton_region ?: ''), 0, 100),
        'city' => substr((string)($normalized_city ?: ''), 0, 100),
        'avs_number' => substr((string)($avs_number ?: 'N/A'), 0, 50),
        'created_at' => current_time('mysql'),
        'base_price' => $base_price,
        'discount_amount' => $discount_amount,
        'final_price' => $final_price,
        'reimbursement' => $reimbursement,
        'discount_codes' => $discount_codes,
        'girls_only' => $girls_only,
        'event_signature' => '',
    ];

    // Generate event signature using the normalized values (same as stored values)
    // This ensures consistency between stored data and event signature
    $data['event_signature'] = intersoccer_event_signature_from_event_data($original_event_data);
    
    // Log final signature with key identifying info
    error_log('InterSoccer Signature: Generated event_signature=' . $data['event_signature'] . ' for Order=' . $order_id . ', Item=' . $item_id . ', Product=' . $product_id . ', Venue=' . $venue . ', Camp/Course=' . ($camp_terms ?: $course_day));

    // Delete any placeholder roster with the same event_signature before inserting real roster
    if (function_exists('intersoccer_delete_placeholder_by_signature')) {
        intersoccer_delete_placeholder_by_signature($data['event_signature']);
    }

    // Check if roster entry already exists and preserve event_completed status
    // First check by order_item_id, then by event_signature (in case event_signature is already generated)
    $existing_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT id, event_completed FROM {$table_name} WHERE order_item_id = %d LIMIT 1",
        $item_id
    ));
    
    // Also check if any roster with the same event_signature is marked as completed
    $event_signature_completed = false;
    if (!empty($data['event_signature'])) {
        $event_signature_completed = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(event_completed) FROM {$table_name} WHERE event_signature = %s",
            $data['event_signature']
        ));
        $event_signature_completed = ($event_signature_completed == 1);
    }
    
    // If entry exists and is marked as completed, or if any roster with same event_signature is completed, preserve that status
    if (($existing_entry && isset($existing_entry->event_completed) && $existing_entry->event_completed == 1) || $event_signature_completed) {
        $data['event_completed'] = 1;
        error_log('InterSoccer: Preserving event_completed=1 for roster entry (order_item_id: ' . $item_id . ', event_signature: ' . $data['event_signature'] . ')');
    } else {
        // For new entries or entries that aren't completed, ensure event_completed is set to 0
        $data['event_completed'] = 0;
    }

    // Insert or update
    $result = $wpdb->replace($table_name, $data);
    $insert_id = $wpdb->insert_id;
    error_log('InterSoccer: Upsert result for order ' . $order_id . ', item ' . $item_id . ': ' . var_export($result, true) . ' | Insert ID: ' . $insert_id . ' | Last DB error: ' . $wpdb->last_error . ' | Last query: ' . $wpdb->last_query);

    if ($result) {
        error_log('InterSoccer: Successfully upserted roster entry for order ' . $order_id . ', item ' . $item_id . ' (ID: ' . $insert_id . ') with event_signature: ' . $data['event_signature']);
        return true;
    } else {
        error_log('InterSoccer: Failed to upsert roster entry for order ' . $order_id . ', item ' . $item_id . ' - Check DB error');
        return false;
    }
}

if (!function_exists('intersoccer_rebuild_event_signature_for_order_item')) {
    /**
     * Recalculate the event signature for a specific roster entry identified by order item ID.
     *
     * @param int $order_item_id
     * @return bool
     */
    function intersoccer_rebuild_event_signature_for_order_item($order_item_id) {
        global $wpdb;

        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$rosters_table} WHERE order_item_id = %d LIMIT 1",
                $order_item_id
            ),
            ARRAY_A
        );

        if (!$record) {
            error_log('InterSoccer: No roster entry found for order item ' . $order_item_id . ' when rebuilding event signature.');
            return false;
        }

        if (function_exists('intersoccer_renormalize_roster_row_language')) {
            return intersoccer_renormalize_roster_row_language((int) $record['id']);
        }

        return false;
    }
}

if (!function_exists('intersoccer_renormalize_order_item_event_meta')) {
    /**
     * Rewrite order line-item event meta to English keys and values.
     *
     * @param int $order_item_id
     * @return bool
     */
    function intersoccer_renormalize_order_item_event_meta($order_item_id) {
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return false;
        }

        if (!function_exists('intersoccer_migration_human_alias_map')
            || !function_exists('intersoccer_migration_build_lookup')) {
            return false;
        }

        $item = new WC_Order_Item_Product($order_item_id);
        $alias_map = intersoccer_migration_human_alias_map();
        $lookup = intersoccer_migration_build_lookup($alias_map);
        $facet_from_canonical = intersoccer_migration_canonical_to_facet_key();

        $event_data = [
            'activity_type' => '',
            'venue'         => '',
            'age_group'     => '',
            'camp_terms'    => '',
            'course_day'    => '',
            'times'         => '',
            'season'        => '',
            'city'          => '',
            'canton_region' => '',
            'girls_only'    => 0,
            'product_id'    => 0,
        ];

        foreach ($item->get_meta_data() as $meta) {
            $key = $meta->key;
            $normalized_key = intersoccer_migration_normalize_meta_key($key);
            if (!isset($lookup[$normalized_key])) {
                continue;
            }
            $canonical = $lookup[$normalized_key];
            if (!isset($facet_from_canonical[$canonical])) {
                continue;
            }
            $facet = $facet_from_canonical[$canonical];
            $value = is_array($meta->value) ? implode(', ', $meta->value) : trim((string) $meta->value);
            if ($facet === 'times' && !empty($event_data['times'])) {
                continue;
            }
            $event_data[$facet] = $value;
        }

        $activity_meta = $item->get_meta('Activity Type', true);
        if ($activity_meta !== '') {
            $event_data['activity_type'] = $activity_meta;
        }

        $booking_raw = intersoccer_read_order_item_booking_fields($order_item_id);
        if ($booking_raw['booking_type'] !== '') {
            $event_data['booking_type'] = $booking_raw['booking_type'];
        }
        if ($booking_raw['selected_days'] !== '') {
            $event_data['selected_days'] = $booking_raw['selected_days'];
        }

        $normalized = intersoccer_normalize_roster_facets_for_storage($event_data);
        $booking = intersoccer_normalize_roster_booking_columns($event_data, $booking_raw);
        $normalized['booking_type'] = $booking['booking_type'];
        $normalized['selected_days'] = $booking['selected_days'];
        $human_meta = intersoccer_human_meta_from_normalized_facets($normalized);

        intersoccer_migration_restore_canonical_meta(
            $order_item_id,
            $alias_map,
            $lookup,
            $human_meta,
            []
        );
        return true;
    }
}

if (!function_exists('intersoccer_renormalize_roster_row_from_variation')) {
    /**
     * Normalize a roster row (placeholder or orphan) using its variation_id.
     *
     * @param int $roster_id
     * @return bool
     */
    function intersoccer_renormalize_roster_row_from_variation($roster_id) {
        global $wpdb;

        $roster_id = (int) $roster_id;
        if ($roster_id <= 0) {
            return false;
        }

        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $record = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$rosters_table} WHERE id = %d LIMIT 1", $roster_id),
            ARRAY_A
        );
        if (!$record || empty($record['variation_id'])) {
            return false;
        }

        $variation_id = function_exists('intersoccer_resolve_variation_for_roster')
            ? intersoccer_resolve_variation_for_roster((int) $record['variation_id'])
            : (int) $record['variation_id'];

        $variation = wc_get_product($variation_id);
        if (!$variation) {
            return false;
        }

        $parent_id = (int) $variation->get_parent_id();
        $event_data = [
            'activity_type' => $record['activity_type'] ?? '',
            'venue'         => '',
            'age_group'     => '',
            'camp_terms'    => '',
            'course_day'    => '',
            'times'         => '',
            'season'        => $record['season'] ?? '',
            'city'          => '',
            'canton_region' => '',
            'girls_only'    => (int) ($record['girls_only'] ?? 0),
            'product_id'    => $parent_id,
        ];

        foreach ($variation->get_variation_attributes() as $attribute_key => $attribute_value) {
            $clean_key = str_replace('attribute_', '', $attribute_key);
            $display = function_exists('intersoccer_format_attribute_for_storage')
                ? intersoccer_format_attribute_for_storage($clean_key, $attribute_value)
                : (string) $attribute_value;
            switch ($clean_key) {
                case 'pa_intersoccer-venues':
                    $event_data['venue'] = $display;
                    break;
                case 'pa_age-group':
                    $event_data['age_group'] = $display;
                    break;
                case 'pa_camp-terms':
                    $event_data['camp_terms'] = $display;
                    break;
                case 'pa_camp-times':
                case 'pa_course-times':
                    $event_data['times'] = $display;
                    break;
                case 'pa_course-day':
                    $event_data['course_day'] = $display;
                    break;
                case 'pa_city':
                    $event_data['city'] = $display;
                    break;
                case 'pa_canton-region':
                    $event_data['canton_region'] = $display;
                    break;
                case 'pa_girls-only':
                    $slug = strtolower(is_array($attribute_value) ? reset($attribute_value) : (string) $attribute_value);
                    $event_data['girls_only'] = in_array($slug, ['girls-only', 'yes', 'girls'], true) ? 1 : 0;
                    break;
            }
        }

        $normalized = intersoccer_normalize_roster_facets_for_storage($event_data);
        $product_name = function_exists('intersoccer_get_english_product_name')
            ? intersoccer_get_english_product_name($variation->get_name(), $parent_id)
            : $variation->get_name();

        $update_data = intersoccer_build_roster_facet_db_update($normalized, $record, $product_name);
        $update_data['variation_id'] = $variation_id;
        $update_data['product_id'] = $parent_id;
        $update_data['girls_only'] = (int) ($normalized['girls_only'] ?? 0);

        if (function_exists('intersoccer_roster_canonicalize_row_product_ids')) {
            $canonical = intersoccer_roster_canonicalize_row_product_ids([
                'product_id'   => $parent_id,
                'variation_id' => $variation_id,
            ]);
            $update_data['product_id'] = (int) ($canonical['product_id'] ?? $parent_id);
            $update_data['variation_id'] = (int) ($canonical['variation_id'] ?? $variation_id);
        }

        $booking = intersoccer_normalize_roster_booking_columns(
            $record,
            intersoccer_read_order_item_booking_fields((int) ($record['order_item_id'] ?? 0))
        );
        $update_data = array_merge($update_data, intersoccer_build_roster_booking_db_update($booking));

        $formats = [];
        foreach ($update_data as $key => $value) {
            $formats[] = in_array($key, ['girls_only', 'variation_id', 'product_id'], true) ? '%d' : '%s';
        }

        $updated = $wpdb->update($rosters_table, $update_data, ['id' => $roster_id], $formats, ['%d']);
        return $updated !== false;
    }
}

if (!function_exists('intersoccer_renormalize_roster_row_language')) {
    /**
     * Normalize all event-specific roster columns to English (facets + booking), not player fields.
     *
     * @param int $roster_id
     * @return bool
     */
    function intersoccer_renormalize_roster_row_language($roster_id) {
        global $wpdb;

        $roster_id = (int) $roster_id;
        if ($roster_id <= 0) {
            return false;
        }

        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $record = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$rosters_table} WHERE id = %d LIMIT 1", $roster_id),
            ARRAY_A
        );
        if (!$record) {
            return false;
        }

        $order_item_id = (int) ($record['order_item_id'] ?? 0);
        $variation_id = (int) ($record['variation_id'] ?? 0);

        if ($variation_id > 0 && function_exists('intersoccer_renormalize_roster_row_from_variation')) {
            $ok = intersoccer_renormalize_roster_row_from_variation($roster_id);
            if (!$ok) {
                return false;
            }
            $record = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$rosters_table} WHERE id = %d LIMIT 1", $roster_id),
                ARRAY_A
            );
            if (!$record) {
                return false;
            }
        } else {
            $event_data = [
                'activity_type' => $record['activity_type'] ?? '',
                'venue'         => $record['venue'] ?? '',
                'age_group'     => $record['age_group'] ?? '',
                'camp_terms'    => $record['camp_terms'] ?? '',
                'course_day'    => $record['course_day'] ?? '',
                'times'         => $record['times'] ?? '',
                'season'        => $record['season'] ?? '',
                'girls_only'    => (int) ($record['girls_only'] ?? 0),
                'city'          => $record['city'] ?? '',
                'canton_region' => $record['canton_region'] ?? '',
                'product_id'    => (int) ($record['product_id'] ?? 0),
                'start_date'    => $record['start_date'] ?? '',
            ];

            if ($order_item_id > 0 && function_exists('intersoccer_roster_collect_event_data_from_order_item')) {
                $from_order = intersoccer_roster_collect_event_data_from_order_item($order_item_id);
                foreach ($from_order as $key => $value) {
                    if ($value !== '' && $value !== null) {
                        $event_data[$key] = $value;
                    }
                }
            }

            $normalized = intersoccer_normalize_roster_facets_for_storage($event_data);
            $product_name = $record['product_name'] ?? '';
            if (!empty($record['product_id']) && function_exists('intersoccer_get_english_product_name')) {
                $product_name = intersoccer_get_english_product_name($product_name, (int) $record['product_id']);
            }

            $update_data = intersoccer_build_roster_facet_db_update($normalized, $record, $product_name);
            $booking = intersoccer_normalize_roster_booking_columns(
                $record,
                $order_item_id > 0 ? intersoccer_read_order_item_booking_fields($order_item_id) : []
            );
            $update_data = array_merge($update_data, intersoccer_build_roster_booking_db_update($booking));

            $formats = [];
            foreach ($update_data as $key => $value) {
                $formats[] = in_array($key, ['girls_only', 'variation_id', 'product_id'], true) ? '%d' : '%s';
            }

            if ($wpdb->update($rosters_table, $update_data, ['id' => $roster_id], $formats, ['%d']) === false) {
                return false;
            }
        }

        if ($order_item_id > 0) {
            $record = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$rosters_table} WHERE id = %d LIMIT 1", $roster_id),
                ARRAY_A
            ) ?: $record;

            if (function_exists('intersoccer_renormalize_order_item_event_meta')) {
                intersoccer_renormalize_order_item_event_meta($order_item_id);
            }
            $booking = intersoccer_normalize_roster_booking_columns(
                $record,
                intersoccer_read_order_item_booking_fields($order_item_id)
            );
            if (function_exists('intersoccer_renormalize_order_item_booking_meta')) {
                intersoccer_renormalize_order_item_booking_meta($order_item_id, $booking);
            }
        }

        return true;
    }
}

if (!function_exists('intersoccer_renormalize_roster_language_batch')) {
    /**
     * Process one batch of roster language normalization.
     *
     * @param int    $offset Zero-based row offset within the phase.
     * @param int    $limit  Max rows per request.
     * @param string $phase  orders|placeholders
     * @return array
     */
    function intersoccer_renormalize_roster_language_batch($offset = 0, $limit = 40, $phase = 'orders') {
        global $wpdb;

        $offset = max(0, (int) $offset);
        $limit = max(1, min(100, (int) $limit));
        $phase = $phase === 'placeholders' ? 'placeholders' : 'orders';

        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

        if ($phase === 'placeholders') {
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$rosters_table} WHERE variation_id > 0 AND (order_item_id IS NULL OR order_item_id = 0)"
            );
            $records = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id FROM {$rosters_table} WHERE variation_id > 0 AND (order_item_id IS NULL OR order_item_id = 0) ORDER BY id ASC LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ),
                ARRAY_A
            ) ?: [];
        } else {
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$rosters_table} WHERE order_item_id > 0"
            );
            $records = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, order_item_id, variation_id FROM {$rosters_table} WHERE order_item_id > 0 ORDER BY id ASC LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ),
                ARRAY_A
            ) ?: [];
        }

        $stats = ['updated' => 0, 'meta_updated' => 0, 'errors' => 0, 'processed' => 0];

        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $run_batch = static function () use ($records, $phase, &$stats) {
            foreach ($records as $row) {
                $stats['processed']++;
                $roster_id = (int) ($row['id'] ?? 0);
                if ($roster_id <= 0) {
                    $stats['errors']++;
                    continue;
                }

                $ok = function_exists('intersoccer_renormalize_roster_row_language')
                    ? intersoccer_renormalize_roster_row_language($roster_id)
                    : false;

                if ($ok) {
                    $stats['updated']++;
                    if ($phase === 'orders' && (int) ($row['order_item_id'] ?? 0) > 0) {
                        $stats['meta_updated']++;
                    }
                } else {
                    $stats['errors']++;
                }
            }
        };

        if (function_exists('intersoccer_with_wpml_default_language')) {
            intersoccer_with_wpml_default_language($run_batch);
        } else {
            $run_batch();
        }

        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(false);
        }

        $next_offset = $offset + count($records);
        $done = $next_offset >= $total || count($records) === 0;

        if ($done && $phase === 'orders') {
            $placeholder_total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$rosters_table} WHERE variation_id > 0 AND (order_item_id IS NULL OR order_item_id = 0)"
            );
            $next_phase = $placeholder_total > 0 ? 'placeholders' : 'complete';
        } elseif ($done) {
            $next_phase = 'complete';
        } else {
            $next_phase = $phase;
        }

        if ($done && $next_phase === 'complete') {
            error_log(
                'InterSoccer: Renormalize roster language phase complete (' . $phase . '): ' .
                wp_json_encode($stats)
            );
        }

        return [
            'updated'      => $stats['updated'],
            'meta_updated' => $stats['meta_updated'],
            'errors'       => $stats['errors'],
            'processed'    => $stats['processed'],
            'offset'       => $offset,
            'next_offset'  => $next_offset,
            'total'        => $total,
            'done'         => $done,
            'phase'        => $phase,
            'next_phase'   => $next_phase,
        ];
    }
}

if (!function_exists('intersoccer_renormalize_roster_language')) {
    /**
     * Normalize all roster rows (single request — prefer batched AJAX for large sites).
     *
     * @return array{updated:int,meta_updated:int,errors:int}
     */
    function intersoccer_renormalize_roster_language() {
        $totals = ['updated' => 0, 'meta_updated' => 0, 'errors' => 0];
        foreach (['orders', 'placeholders'] as $phase) {
            $offset = 0;
            do {
                $batch = intersoccer_renormalize_roster_language_batch($offset, 40, $phase);
                $totals['updated'] += (int) ($batch['updated'] ?? 0);
                $totals['meta_updated'] += (int) ($batch['meta_updated'] ?? 0);
                $totals['errors'] += (int) ($batch['errors'] ?? 0);
                $offset = (int) ($batch['next_offset'] ?? 0);
            } while (empty($batch['done']));
        }
        return $totals;
    }
}

if (!function_exists('intersoccer_align_event_signature_for_variation')) {
    /**
     * Align a roster entry's event signature with existing entries for the same variation.
     *
     * @param int $variation_id
     * @param int $order_item_id
     * @return bool True if aligned with an existing signature, false otherwise.
     */
    function intersoccer_align_event_signature_for_variation($variation_id, $order_item_id) {
        global $wpdb;

        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $existing_signature = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT event_signature 
                 FROM {$rosters_table} 
                 WHERE variation_id = %d 
                   AND order_item_id != %d
                   AND event_signature != ''
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                $variation_id,
                $order_item_id
            )
        );

        if (empty($existing_signature)) {
            return false;
        }

        $updated = $wpdb->update(
            $rosters_table,
            ['event_signature' => $existing_signature],
            ['order_item_id' => $order_item_id],
            ['%s'],
            ['%d']
        );

        if ($updated !== false) {
            error_log(
                sprintf(
                    'InterSoccer: Aligned event signature for order item %d to existing signature %s.',
                    $order_item_id,
                    $existing_signature
                )
            );
            return true;
        }

        error_log(
            sprintf(
                'InterSoccer: Failed to align event signature for order item %d - DB error: %s',
                $order_item_id,
                $wpdb->last_error
            )
        );
        return false;
    }
}

/**
 * Check for required InterSoccer Product Variations plugin dependency
 * This plugin requires intersoccer_get_product_type() and other core functions
 */
add_action('admin_init', function() {
    // Check if the required function exists
if (!function_exists('intersoccer_get_product_type')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><strong>InterSoccer Reports & Rosters:</strong> The <strong>InterSoccer Product Variations</strong> plugin is required and must be activated first.</p>
            </div>
            <?php
        });
        
        // Log the error
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Reports & Rosters: DEPENDENCY ERROR - InterSoccer Product Variations plugin is not active or intersoccer_get_product_type() function is missing');
                }
            }
});

// 2. Enhanced debug function for the Process Orders functionality
function intersoccer_debug_process_orders() {
    error_log('=== InterSoccer: DEBUG PROCESS ORDERS START ===');
    
    // Check if required functions exist
    $required_functions = [
        'intersoccer_get_product_type',
        'intersoccer_update_roster_entry',
        'intersoccer_process_existing_orders'
    ];
    
    foreach ($required_functions as $function) {
        if (function_exists($function)) {
            error_log('InterSoccer: ✓ Function ' . $function . ' exists');
        } else {
            error_log('InterSoccer: ✗ Function ' . $function . ' MISSING');
        }
    }
    
    // Check database table
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rosters_table)) === $rosters_table;
    error_log('InterSoccer: Rosters table exists: ' . ($table_exists ? 'yes' : 'no'));
    
    if ($table_exists) {
        $row_count = $wpdb->get_var("SELECT COUNT(*) FROM $rosters_table");
        error_log('InterSoccer: Rosters table row count: ' . $row_count);
    }
    
    // Check orders to be processed
    $orders = wc_get_orders([
        'limit' => 5, // Just check first 5
        'status' => ['wc-processing', 'wc-on-hold'],
    ]);
    error_log('InterSoccer: Found ' . count($orders) . ' orders with processing/on-hold status');
    
    foreach ($orders as $order) {
        $order_id = $order->get_id();
        error_log('InterSoccer: Order ' . $order_id . ' - Status: ' . $order->get_status() . ', Items: ' . count($order->get_items()));
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $type_id = $variation_id ?: $product_id;
            $product_type = intersoccer_get_product_type_safe($product_id, $variation_id);
            
            $assigned_attendee = wc_get_order_item_meta($item_id, 'Assigned Attendee', true);
            
            error_log('InterSoccer: - Item ' . $item_id . ' - Product: ' . $product_id . ', Variation: ' . $variation_id . ', Type: ' . $product_type . ', Attendee: ' . ($assigned_attendee ?: 'NONE'));
            
            // Only check first item to avoid log spam
            break;
        }
    }
    
    error_log('=== InterSoccer: DEBUG PROCESS ORDERS END ===');
}

// 3. Improved error handling for the update roster entry function
function intersoccer_safe_update_roster_entry($order_id, $item_id) {
    try {
        error_log('InterSoccer: Starting safe_update_roster_entry for order ' . $order_id . ', item ' . $item_id);
        
        // Validate inputs
        if (empty($order_id) || empty($item_id)) {
            error_log('InterSoccer: Invalid parameters - order_id: ' . $order_id . ', item_id: ' . $item_id);
            return false;
        }
        
        // Check if order exists
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('InterSoccer: Order not found: ' . $order_id);
            return false;
        }
        
        // Check if item exists
        $item = $order->get_item($item_id);
        if (!$item) {
            error_log('InterSoccer: Item not found: ' . $item_id . ' in order ' . $order_id);
            return false;
        }
        
        // Check if intersoccer_update_roster_entry function exists
        if (!function_exists('intersoccer_update_roster_entry')) {
            error_log('InterSoccer: intersoccer_update_roster_entry function does not exist');
            return false;
        }
        
        // Call the actual function
        $result = intersoccer_update_roster_entry($order_id, $item_id);
        
        error_log('InterSoccer: safe_update_roster_entry result for order ' . $order_id . ', item ' . $item_id . ': ' . ($result ? 'success' : 'failed'));
        
        return $result;
        
    } catch (Exception $e) {
        error_log('InterSoccer: Exception in safe_update_roster_entry for order ' . $order_id . ', item ' . $item_id . ': ' . $e->getMessage());
        return false;
    }
}

// 4. Add this test function to verify everything is working
function intersoccer_test_process_orders() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    error_log('InterSoccer: Running test_process_orders');
    
    // Run debug check
    intersoccer_debug_process_orders();
    
    // Test with one order
    $orders = wc_get_orders([
        'limit' => 1,
        'status' => ['wc-processing', 'wc-on-hold'],
    ]);
    
    if (empty($orders)) {
        error_log('InterSoccer: No test orders found');
        return;
    }
    
    $order = $orders[0];
    $order_id = $order->get_id();
    
    error_log('InterSoccer: Testing with order ' . $order_id);
    
    foreach ($order->get_items() as $item_id => $item) {
        error_log('InterSoccer: Testing item ' . $item_id);
        $result = intersoccer_safe_update_roster_entry($order_id, $item_id);
        error_log('InterSoccer: Test result: ' . ($result ? 'SUCCESS' : 'FAILED'));
        break; // Only test first item
    }
    
    error_log('InterSoccer: Test completed');
}

// Disabled: was running on every admin load and can cause 500s / performance issues.
// Re-enable only for local debugging by defining INTERSOCCER_RUN_TEST_PROCESS_ORDERS.
if (defined('INTERSOCCER_RUN_TEST_PROCESS_ORDERS') && INTERSOCCER_RUN_TEST_PROCESS_ORDERS) {
    add_action('admin_init', 'intersoccer_test_process_orders');
}

function intersoccer_get_product_type_safe($product_id, $variation_id = null) {
    // Check if the Product Variations plugin function exists
    if (!function_exists('intersoccer_get_product_type')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: CRITICAL - intersoccer_get_product_type function not found from Product Variations plugin');
        }
        return 'unknown';
    }
    
    // Try variation ID first if provided
    if ($variation_id && $variation_id > 0) {
        $type = intersoccer_get_product_type($variation_id);
        if (!empty($type)) {
            return $type;
        }
    }
    
    // Try parent product ID
    $type = intersoccer_get_product_type($product_id);
    if (!empty($type)) {
        return $type;
    }
    
    // Manual fallback if the function fails
    return intersoccer_manual_product_type_detection($product_id, $variation_id);
}

// 2. Manual fallback function based on the Product Variations logic
function intersoccer_manual_product_type_detection($product_id, $variation_id = null) {
    $check_id = $variation_id && $variation_id > 0 ? $variation_id : $product_id;
    
    error_log('InterSoccer: Manual product type detection for ID: ' . $check_id);
    
    // Check existing meta first
    $product_type = get_post_meta($check_id, '_intersoccer_product_type', true);
    if ($product_type) {
        error_log('InterSoccer: Found product type in meta: ' . $product_type);
        return $product_type;
    }
    
    // Check parent meta if this is a variation
    if ($variation_id && $variation_id > 0) {
        $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        if ($product_type) {
            error_log('InterSoccer: Found product type in parent meta: ' . $product_type);
            return $product_type;
        }
    }
    
    // Check categories (use parent product for variations)
    $cat_check_id = $variation_id && $variation_id > 0 ? $product_id : $check_id;
    $categories = wp_get_post_terms($cat_check_id, 'product_cat', array('fields' => 'slugs'));
    
    if (!is_wp_error($categories) && !empty($categories)) {
        error_log('InterSoccer: Categories for product ' . $cat_check_id . ': ' . print_r($categories, true));
        
        if (in_array('camps', $categories, true)) {
            $product_type = 'camp';
        } elseif (in_array('courses', $categories, true)) {
            $product_type = 'course';
        } elseif (in_array('birthdays', $categories, true)) {
            $product_type = 'birthday';
        }
        
        if ($product_type) {
            error_log('InterSoccer: Product type from categories: ' . $product_type);
            // Save to meta for future use
            update_post_meta($check_id, '_intersoccer_product_type', $product_type);
            return $product_type;
        }
    }
    
    // Check product attributes
    $product = wc_get_product($check_id);
    if ($product) {
        $activity_type_attr = $product->get_attribute('pa_activity-type');
        error_log('InterSoccer: Activity type attribute: ' . var_export($activity_type_attr, true));
        
        if (!empty($activity_type_attr)) {
            $normalized = strtolower(trim($activity_type_attr));
            if (strpos($normalized, 'camp') !== false) {
                $product_type = 'camp';
            } elseif (strpos($normalized, 'course') !== false) {
                $product_type = 'course';
            } elseif (strpos($normalized, 'birthday') !== false) {
                $product_type = 'birthday';
            }
            
            if ($product_type) {
                error_log('InterSoccer: Product type from attributes: ' . $product_type);
                update_post_meta($check_id, '_intersoccer_product_type', $product_type);
                return $product_type;
            }
        }
        
        // Try parent product attributes if this is a variation
        if ($variation_id && $variation_id > 0) {
            $parent_product = wc_get_product($product_id);
            if ($parent_product) {
                $parent_activity_type = $parent_product->get_attribute('pa_activity-type');
                error_log('InterSoccer: Parent activity type attribute: ' . var_export($parent_activity_type, true));
                
                if (!empty($parent_activity_type)) {
                    $normalized = strtolower(trim($parent_activity_type));
                    if (strpos($normalized, 'camp') !== false) {
                        $product_type = 'camp';
                    } elseif (strpos($normalized, 'course') !== false) {
                        $product_type = 'course';
                    } elseif (strpos($normalized, 'birthday') !== false) {
                        $product_type = 'birthday';
                    }
                    
                    if ($product_type) {
                        error_log('InterSoccer: Product type from parent attributes: ' . $product_type);
                        update_post_meta($check_id, '_intersoccer_product_type', $product_type);
                        return $product_type;
                    }
                }
            }
        }
    }
    
    error_log('InterSoccer: Could not determine product type manually for ID: ' . $check_id);
    return 'unknown';
}

// 3. Update the intersoccer_update_roster_entry function to use the safe version
// Replace this line in your intersoccer_update_roster_entry function:
// $product_type = intersoccer_get_product_type($type_id);
// With this:
// $product_type = intersoccer_get_product_type_safe($product_id, $variation_id);

// 4. Debug function to test specific problematic products
function intersoccer_debug_specific_products() {
    if (!current_user_can('manage_options')) return;
    
    error_log('=== DEBUG SPECIFIC PRODUCTS ===');
    
    // Test the problematic products from your logs
    $test_products = [
        ['product_id' => 25232, 'variation_id' => 35888], // Ray Cazin - Type: empty
        ['product_id' => 25222, 'variation_id' => 28079], // Murtaja Al-Hamad - Type: empty
        ['product_id' => 25222, 'variation_id' => 28081], // Frederick Mcintire - Type: camp (working)
    ];
    
    foreach ($test_products as $test) {
        error_log('Testing product ' . $test['product_id'] . ', variation ' . $test['variation_id']);
        
        // Test original function if available
        if (function_exists('intersoccer_get_product_type')) {
            $original_result = intersoccer_get_product_type($test['variation_id']);
            error_log('Original function result for variation: ' . var_export($original_result, true));
            
            $original_parent = intersoccer_get_product_type($test['product_id']);
            error_log('Original function result for parent: ' . var_export($original_parent, true));
        }
        
        // Test safe function
        $safe_result = intersoccer_get_product_type_safe($test['product_id'], $test['variation_id']);
        error_log('Safe function result: ' . var_export($safe_result, true));
        
        // Check meta and categories directly
        $variation_meta = get_post_meta($test['variation_id'], '_intersoccer_product_type', true);
        $parent_meta = get_post_meta($test['product_id'], '_intersoccer_product_type', true);
        $categories = wp_get_post_terms($test['product_id'], 'product_cat', array('fields' => 'slugs'));
        
        error_log('Variation meta: ' . var_export($variation_meta, true));
        error_log('Parent meta: ' . var_export($parent_meta, true));
        error_log('Parent categories: ' . print_r($categories, true));
        
        error_log('---');
    }
    
    error_log('=== END DEBUG SPECIFIC PRODUCTS ===');
}

// Uncomment to run the debug test
add_action('admin_init', 'intersoccer_debug_specific_products');

require_once dirname(__FILE__) . '/migration-meta.php';

if (!function_exists('intersoccer_set_bulk_normalize_quiet')) {
    function intersoccer_set_bulk_normalize_quiet($quiet) {
        $GLOBALS['intersoccer_bulk_normalize_quiet'] = (bool) $quiet;
    }
}

if (!function_exists('intersoccer_is_bulk_normalize_quiet')) {
    function intersoccer_is_bulk_normalize_quiet() {
        return !empty($GLOBALS['intersoccer_bulk_normalize_quiet']);
    }
}

if (!function_exists('intersoccer_resolve_variation_for_roster')) {
    /**
     * Resolve a variation ID to the default-language (English) variation for roster reads/writes.
     *
     * @param int $variation_id
     * @return int
     */
    function intersoccer_resolve_variation_for_roster($variation_id) {
        $variation_id = (int) $variation_id;
        if ($variation_id <= 0) {
            return 0;
        }
        if (function_exists('intersoccer_get_default_language_variation_id')) {
            return (int) intersoccer_get_default_language_variation_id($variation_id);
        }
        return $variation_id;
    }
}

if (!function_exists('intersoccer_format_roster_date_for_storage')) {
    /**
     * Format a date string for order/roster storage in fixed English (not locale-dependent).
     *
     * @param string $date_string Parseable date string.
     * @return string
     */
    function intersoccer_format_roster_date_for_storage($date_string) {
        if (empty($date_string)) {
            return '';
        }
        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return '';
        }
        return date('F j, Y', $timestamp);
    }
}

if (!function_exists('intersoccer_with_wpml_default_language')) {
    /**
     * Run a callback while WPML is switched to the default (English) language.
     *
     * @param callable $callback
     * @return mixed
     */
    function intersoccer_with_wpml_default_language(callable $callback) {
        $current_lang = '';
        $default_lang = '';
        if (function_exists('wpml_get_current_language')) {
            $current_lang = wpml_get_current_language();
        }
        if (function_exists('wpml_get_default_language')) {
            $default_lang = wpml_get_default_language();
            if ($default_lang && $current_lang !== $default_lang) {
                do_action('wpml_switch_language', $default_lang);
            }
        }

        try {
            return $callback();
        } finally {
            if ($default_lang && $current_lang && $current_lang !== $default_lang) {
                do_action('wpml_switch_language', $current_lang);
            }
        }
    }
}

if (!function_exists('intersoccer_event_signature_from_event_data')) {
    /**
     * Canonical event signature: normalize facets then hash (preserves start_date).
     *
     * @param array $event_data Raw event characteristics.
     * @return string MD5 signature or empty string when helpers are unavailable.
     */
    function intersoccer_event_signature_from_event_data(array $event_data) {
        if (!function_exists('intersoccer_normalize_event_data_for_signature')
            || !function_exists('intersoccer_generate_event_signature')) {
            return '';
        }
        $normalized = intersoccer_normalize_event_data_for_signature($event_data);
        if (array_key_exists('start_date', $event_data)) {
            $normalized['start_date'] = $event_data['start_date'];
        }
        return intersoccer_generate_event_signature($normalized);
    }
}

if (!function_exists('intersoccer_format_attribute_for_storage')) {
    /**
     * Convert attribute slugs to English display names for roster/order meta storage.
     *
     * @param string $taxonomy Attribute taxonomy (e.g. pa_intersoccer-venues).
     * @param mixed  $value    Attribute value (slug or array of slugs).
     * @return string
     */
    function intersoccer_format_attribute_for_storage($taxonomy, $value) {
        if (is_array($value)) {
            $raw_values = $value;
        } else {
            $raw_values = array_map('trim', explode(',', (string) $value));
        }

        $raw_values = array_filter($raw_values, static function ($val) {
            return $val !== '' && $val !== null;
        });

        if (empty($raw_values)) {
            return '';
        }

        if ($taxonomy === 'pa_girls-only') {
            $slug = strtolower((string) reset($raw_values));
            if (in_array($slug, ['girls-only', 'yes', 'girls'], true)) {
                return 'Yes';
            }
            if (in_array($slug, ['mixed', 'no'], true)) {
                return 'No';
            }
        }

        if (taxonomy_exists($taxonomy) && function_exists('intersoccer_get_term_name')) {
            $names = [];
            foreach ($raw_values as $slug) {
                $name = intersoccer_get_term_name($slug, $taxonomy);
                if ($name !== '' && $name !== 'N/A') {
                    $names[] = $name;
                } else {
                    $names[] = ucwords(str_replace(['-', '_'], ' ', (string) $slug));
                }
            }
            return implode(', ', array_unique($names));
        }

        return implode(', ', array_map(static function ($val) {
            return ucwords(str_replace(['-', '_'], ' ', (string) $val));
        }, $raw_values));
    }
}

if (!function_exists('intersoccer_normalize_roster_facets_for_storage')) {
    /**
     * Normalize event facet fields to English for roster DB and order-item meta storage.
     *
     * @param array $event_data Keys: activity_type, venue, age_group, camp_terms, course_day,
     *                          times, season, girls_only, city, canton_region, product_id, start_date.
     * @return array Normalized facet map (same keys).
     */
    function intersoccer_normalize_roster_facets_for_storage(array $event_data) {
        $payload = [
            'activity_type'  => $event_data['activity_type'] ?? '',
            'venue'          => $event_data['venue'] ?? '',
            'age_group'      => $event_data['age_group'] ?? '',
            'camp_terms'     => $event_data['camp_terms'] ?? ($event_data['event_type'] ?? ''),
            'course_day'     => $event_data['course_day'] ?? '',
            'times'          => $event_data['times'] ?? '',
            'season'         => $event_data['season'] ?? '',
            'girls_only'     => $event_data['girls_only'] ?? 0,
            'city'           => $event_data['city'] ?? '',
            'canton_region'  => $event_data['canton_region'] ?? '',
            'product_id'     => $event_data['product_id'] ?? 0,
            'start_date'     => $event_data['start_date'] ?? '',
        ];

        $normalized = function_exists('intersoccer_normalize_event_data_for_signature')
            ? intersoccer_normalize_event_data_for_signature($payload)
            : $payload;

        $normalized['start_date'] = $payload['start_date'];

        $term_fields = [
            'venue'         => 'pa_intersoccer-venues',
            'age_group'     => 'pa_age-group',
            'camp_terms'    => 'pa_camp-terms',
            'course_day'    => 'pa_course-day',
            'city'          => 'pa_city',
            'canton_region' => 'pa_canton-region',
        ];

        foreach ($term_fields as $field => $taxonomy) {
            if (!empty($normalized[$field]) && function_exists('intersoccer_get_term_name')) {
                $name = intersoccer_get_term_name($normalized[$field], $taxonomy);
                if ($name !== '' && $name !== 'N/A') {
                    $normalized[$field] = $name;
                }
            }
        }

        if (!empty($normalized['times']) && function_exists('intersoccer_get_term_name')) {
            $times = $normalized['times'];
            $name = intersoccer_get_term_name($times, 'pa_camp-times');
            if ($name === 'N/A') {
                $name = intersoccer_get_term_name($times, 'pa_course-times');
            }
            if ($name !== '' && $name !== 'N/A') {
                $normalized['times'] = $name;
            }
        }

        if (!empty($normalized['season']) && function_exists('intersoccer_get_term_name')) {
            $name = intersoccer_get_term_name($normalized['season'], 'pa_program-season');
            if ($name !== '' && $name !== 'N/A') {
                $normalized['season'] = $name;
            }
        }

        if (!empty($normalized['activity_type']) && function_exists('intersoccer_canonical_activity_type_for_roster')) {
            $normalized['activity_type'] = intersoccer_canonical_activity_type_for_roster($normalized['activity_type']);
        }

        if (isset($event_data['girls_only'])) {
            $normalized['girls_only'] = !empty($event_data['girls_only']) ? 1 : 0;
        }

        if (!empty($event_data['product_id'])) {
            $normalized['product_id'] = (int) $event_data['product_id'];
        }

        return $normalized;
    }
}

if (!function_exists('intersoccer_build_roster_facet_db_update')) {
    /**
     * Build roster table column updates from normalized facet data (for rebuild/repair).
     *
     * @param array  $normalized_data Output of intersoccer_normalize_roster_facets_for_storage().
     * @param array  $record          Existing roster row (fallback values).
     * @param string $product_name    English product name.
     * @return array Column => value for $wpdb->update.
     */
    function intersoccer_build_roster_facet_db_update(array $normalized_data, array $record, $product_name = '') {
        $signature_payload = array_merge($normalized_data, [
            'start_date' => $normalized_data['start_date'] ?? ($record['start_date'] ?? ''),
        ]);
        $signature = function_exists('intersoccer_event_signature_from_event_data')
            ? intersoccer_event_signature_from_event_data($signature_payload)
            : '';

        $update = [
            'event_signature' => $signature,
            'venue'           => substr((string) ($normalized_data['venue'] ?? $record['venue'] ?? ''), 0, 200),
            'age_group'       => substr((string) ($normalized_data['age_group'] ?? $record['age_group'] ?? ''), 0, 50),
            'camp_terms'      => substr((string) ($normalized_data['camp_terms'] ?? $record['camp_terms'] ?? ''), 0, 100),
            'course_day'      => substr((string) ($normalized_data['course_day'] ?? $record['course_day'] ?? ''), 0, 20),
            'times'           => substr((string) ($normalized_data['times'] ?? $record['times'] ?? ''), 0, 50),
            'season'          => substr((string) ($normalized_data['season'] ?? $record['season'] ?? ''), 0, 50),
            'city'            => substr((string) ($normalized_data['city'] ?? $record['city'] ?? ''), 0, 100),
            'canton_region'   => substr((string) ($normalized_data['canton_region'] ?? $record['canton_region'] ?? ''), 0, 100),
            'activity_type'   => substr((string) ($normalized_data['activity_type'] ?? $record['activity_type'] ?? ''), 0, 50),
        ];

        if ($product_name !== '') {
            $update['product_name'] = substr((string) $product_name, 0, 255);
        }

        return $update;
    }
}

if (!function_exists('intersoccer_human_meta_from_normalized_facets')) {
    /**
     * Map normalized roster facets to canonical human_meta keys for order item storage.
     *
     * @param array $normalized Facets from intersoccer_normalize_roster_facets_for_storage().
     * @return array<string,string> canonical_key => English value
     */
    function intersoccer_human_meta_from_normalized_facets(array $normalized) {
        $string_value = static function ($value) {
            return is_array($value)
                ? implode(', ', array_map('trim', $value))
                : trim((string) $value);
        };

        $human = [];
        $map = [
            'venue'         => 'intersoccer_venues',
            'age_group'     => 'age_group',
            'camp_terms'    => 'camp_terms',
            'course_day'    => 'course_day',
            'activity_type' => 'activity_type',
            'season'        => 'season',
            'city'          => 'city',
            'canton_region' => 'canton_region',
        ];

        foreach ($map as $facet => $canonical) {
            if (!empty($normalized[$facet])) {
                $human[$canonical] = $string_value($normalized[$facet]);
            }
        }

        if (!empty($normalized['times'])) {
            $activity = strtolower((string) ($normalized['activity_type'] ?? ''));
            $times_key = ($activity === 'camp') ? 'camp_times' : 'course_times';
            $human[$times_key] = $string_value($normalized['times']);
        }

        if (!empty($normalized['booking_type'])) {
            $human['booking_type'] = $string_value($normalized['booking_type']);
        }
        if (!empty($normalized['selected_days'])) {
            $human['selected_days'] = $string_value($normalized['selected_days']);
        }

        return $human;
    }
}

/**
 * Normalizes event data to English for consistent event signature generation.
 * This ensures that orders placed in different languages are grouped with the correct rosters.
 *
 * @param array $event_data Array containing event characteristics
 * @return array Normalized event data in English
 */
function intersoccer_normalize_event_data_for_signature($event_data) {
    $normalize = static function () use ($event_data) {
    $normalized = $event_data;

    try {
        // For taxonomy-based attributes, the order metadata contains translated names
        // We need to find the term by name in current language, then get the name in default language

        // Normalize venue (taxonomy term name)
        if (!empty($event_data['venue'])) {
            $term = function_exists('intersoccer_get_term_in_default_language')
                ? intersoccer_get_term_in_default_language($event_data['venue'], 'pa_intersoccer-venues')
                : intersoccer_get_term_by_translated_name($event_data['venue'], 'pa_intersoccer-venues');

            if ($term && !is_wp_error($term)) {
                $normalized['venue'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['venue'] = intersoccer_normalize_term_fallback($event_data['venue']);
            }
        }

        // Normalize age_group (taxonomy term name)
        if (!empty($event_data['age_group'])) {
            $term = function_exists('intersoccer_get_term_in_default_language')
                ? intersoccer_get_term_in_default_language($event_data['age_group'], 'pa_age-group')
                : intersoccer_get_term_by_translated_name($event_data['age_group'], 'pa_age-group');

            if ($term && !is_wp_error($term)) {
                $normalized['age_group'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['age_group'] = intersoccer_normalize_term_fallback($event_data['age_group']);
            }
        }

        // Normalize camp_terms (taxonomy term name)
        if (!empty($event_data['camp_terms'])) {
            $term = function_exists('intersoccer_get_term_in_default_language')
                ? intersoccer_get_term_in_default_language($event_data['camp_terms'], 'pa_camp-terms')
                : intersoccer_get_term_by_translated_name($event_data['camp_terms'], 'pa_camp-terms');

            if ($term && !is_wp_error($term)) {
                $normalized['camp_terms'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['camp_terms'] = intersoccer_normalize_term_fallback($event_data['camp_terms']);
            }
        }

        // Normalize course_day (taxonomy term name)
        if (!empty($event_data['course_day'])) {
            $term = function_exists('intersoccer_get_term_in_default_language')
                ? intersoccer_get_term_in_default_language($event_data['course_day'], 'pa_course-day')
                : intersoccer_get_term_by_translated_name($event_data['course_day'], 'pa_course-day');

            if ($term && !is_wp_error($term)) {
                $normalized['course_day'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['course_day'] = intersoccer_normalize_term_fallback($event_data['course_day']);
            }
        }

        // Normalize times (taxonomy term name) - try different taxonomies
        if (!empty($event_data['times'])) {
            $term = null;
            $taxonomies = ['pa_camp-times', 'pa_course-times'];
            foreach ($taxonomies as $taxonomy) {
                $term = function_exists('intersoccer_get_term_in_default_language')
                    ? intersoccer_get_term_in_default_language($event_data['times'], $taxonomy)
                    : intersoccer_get_term_by_translated_name($event_data['times'], $taxonomy);
                if ($term && !is_wp_error($term)) {
                    break;
                }
            }
            if ($term && !is_wp_error($term)) {
                $normalized['times'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['times'] = intersoccer_normalize_term_fallback($event_data['times']);
            }
        }

        // Normalize season (taxonomy term name)
        if (!empty($event_data['season'])) {
            $normalized['season'] = $event_data['season'];
            $term = function_exists('intersoccer_get_term_in_default_language')
                ? intersoccer_get_term_in_default_language($event_data['season'], 'pa_program-season')
                : intersoccer_get_term_by_translated_name($event_data['season'], 'pa_program-season');
            if ($term && !is_wp_error($term)) {
                $normalized['season'] = $term->name;
            } else {
                // Use fallback normalization if term not found
                $normalized['season'] = intersoccer_normalize_term_fallback($event_data['season']);
            }
            // Manual normalization as fallback to ensure English (handles unsynchronized terms)
            $normalized['season'] = str_ireplace('Hiver', 'Winter', $normalized['season']);
            $normalized['season'] = str_ireplace('hiver', 'winter', $normalized['season']);
            $normalized['season'] = str_ireplace('Été', 'Summer', $normalized['season']);
            $normalized['season'] = str_ireplace('été', 'summer', $normalized['season']);
            $normalized['season'] = str_ireplace('Printemps', 'Spring', $normalized['season']);
            $normalized['season'] = str_ireplace('printemps', 'spring', $normalized['season']);
            $normalized['season'] = str_ireplace('Automne', 'Autumn', $normalized['season']);
            $normalized['season'] = str_ireplace('automne', 'autumn', $normalized['season']);
            // Capitalize first word
            $normalized['season'] = ucfirst(strtolower($normalized['season']));
        }

        // Normalize city (taxonomy term name) - important for tournaments
        if (!empty($event_data['city'])) {
            $term = function_exists('intersoccer_get_term_in_default_language')
                ? intersoccer_get_term_in_default_language($event_data['city'], 'pa_city')
                : intersoccer_get_term_by_translated_name($event_data['city'], 'pa_city');
            if ($term && !is_wp_error($term)) {
                $normalized['city'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['city'] = intersoccer_normalize_term_fallback($event_data['city']);
            }
        }

        // Normalize canton_region (taxonomy term name) - important for tournaments
        if (!empty($event_data['canton_region'])) {
            $term = function_exists('intersoccer_get_term_in_default_language')
                ? intersoccer_get_term_in_default_language($event_data['canton_region'], 'pa_canton-region')
                : intersoccer_get_term_by_translated_name($event_data['canton_region'], 'pa_canton-region');
            if ($term && !is_wp_error($term)) {
                $normalized['canton_region'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['canton_region'] = intersoccer_normalize_term_fallback($event_data['canton_region']);
            }
        }

        // Normalize activity_type - this might be a direct value, not a taxonomy term
        if (!empty($event_data['activity_type'])) {
            // Check if it's a taxonomy term first
            $term = function_exists('intersoccer_get_term_in_default_language')
                ? intersoccer_get_term_in_default_language($event_data['activity_type'], 'pa_activity-type')
                : intersoccer_get_term_by_translated_name($event_data['activity_type'], 'pa_activity-type');
            if ($term && !is_wp_error($term)) {
                $normalized['activity_type'] = intersoccer_canonical_activity_type_for_roster($term->name);
            } else {
                $normalized['activity_type'] = intersoccer_canonical_activity_type_for_roster(
                    intersoccer_normalize_activity_type($event_data['activity_type'])
                );
            }
        }

        if (!function_exists('intersoccer_is_bulk_normalize_quiet') || !intersoccer_is_bulk_normalize_quiet()) {
            error_log('InterSoccer: Normalized event data for signature: ' . json_encode([
                'original' => $event_data,
                'normalized' => $normalized,
            ]));
        }

    } catch (Exception $e) {
        error_log('InterSoccer: Error normalizing event data: ' . $e->getMessage());
        // Return original data if normalization fails
        $normalized = $event_data;
    }

    return $normalized;
    };

    if (function_exists('intersoccer_with_wpml_default_language')) {
        return intersoccer_with_wpml_default_language($normalize);
    }

    return $normalize();
}

if (!function_exists('intersoccer_resolve_times_slug_for_signature')) {
    /**
     * Resolve a times attribute value to a canonical slug for event signatures.
     *
     * @param string $value Times name or slug (any language).
     * @return string
     */
    function intersoccer_resolve_times_slug_for_signature($value) {
        if ($value === '') {
            return '';
        }
        foreach (['pa_camp-times', 'pa_course-times'] as $tax) {
            $slug = intersoccer_get_term_slug_by_name($value, $tax);
            if ($slug !== '' && $slug !== strtolower($value)) {
                return $slug;
            }
            $term = function_exists('intersoccer_get_term_in_default_language')
                ? intersoccer_get_term_in_default_language($value, $tax) : null;
            if ($term && !is_wp_error($term)) {
                return $term->slug;
            }
        }
        return intersoccer_get_term_slug_by_name($value, 'pa_camp-times');
    }
}

/**
 * Helper function to get term by translated name and return it in default language
 * 
 * Enhanced to handle unsynchronized/mistranslated taxonomy terms by:
 * - Case-insensitive matching
 * - Checking all WPML translations more thoroughly
 * - Partial/fuzzy matching when exact match fails
 * 
 * @param string $translated_name Term name (may be in any language)
 * @param string $taxonomy Taxonomy name
 * @return WP_Term|null Term object in default language, or null if not found
 */
function intersoccer_get_term_by_translated_name($translated_name, $taxonomy) {
    if (empty($translated_name) || empty($taxonomy)) {
        return null;
    }
    
    // Normalize input for comparison (trim, lowercase)
    $normalized_input = strtolower(trim($translated_name));
    
    // First try to find by slug (in case the stored value is a slug)
    $term = get_term_by('slug', $translated_name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        // We found it by slug, now we need to get the English name
        // The term object's name will be in the current language context
        // Since we're already in default language context (called from normalize function),
        // the name should be in English
        error_log('InterSoccer: Found term by slug for "' . $translated_name . '" in taxonomy "' . $taxonomy . '"');
        return $term;
    }
    
    // Try exact name match (case-sensitive first for performance)
    $term = get_term_by('name', $translated_name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        error_log('InterSoccer: Found term by exact name match for "' . $translated_name . '" in taxonomy "' . $taxonomy . '"');
        return $term;
    }
    
    // Get all terms in the taxonomy to check translations and do case-insensitive matching
    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
        'lang' => '' // Get all language versions
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        error_log('InterSoccer: No terms found or error getting terms for taxonomy "' . $taxonomy . '"');
        return null;
    }

    // Find the term that matches the translated name
    foreach ($terms as $term) {
        // Check if this term's name matches exactly
        if ($term->name === $translated_name) {
            error_log('InterSoccer: Found term by exact name match in all terms for "' . $translated_name . '" in taxonomy "' . $taxonomy . '"');
            return $term;
        }
        
        // Check case-insensitive match
        if (strtolower(trim($term->name)) === $normalized_input) {
            error_log('InterSoccer: Found term by case-insensitive name match for "' . $translated_name . '" (matched "' . $term->name . '") in taxonomy "' . $taxonomy . '"');
            return $term;
        }
        
        // Check if slug matches
        if ($term->slug === $translated_name || strtolower($term->slug) === $normalized_input) {
            error_log('InterSoccer: Found term by slug match for "' . $translated_name . '" in taxonomy "' . $taxonomy . '"');
            return $term;
        }

        // Check WPML translations more thoroughly
        if (function_exists('wpml_get_element_translations')) {
            $translations = wpml_get_element_translations($term->term_id, 'tax_' . $taxonomy);
            if ($translations && is_array($translations)) {
                foreach ($translations as $lang_code => $translation) {
                    // Exact match on translation name
                    if (isset($translation->name) && $translation->name === $translated_name) {
                        error_log('InterSoccer: Found term by WPML translation exact match for "' . $translated_name . '" (lang: ' . $lang_code . ') in taxonomy "' . $taxonomy . '"');
                        return $term;
                    }
                    
                    // Case-insensitive match on translation name
                    if (isset($translation->name) && strtolower(trim($translation->name)) === $normalized_input) {
                        error_log('InterSoccer: Found term by WPML translation case-insensitive match for "' . $translated_name . '" (matched "' . $translation->name . '", lang: ' . $lang_code . ') in taxonomy "' . $taxonomy . '"');
                        return $term;
                    }
                }
            }
        }
        
        // Try partial/fuzzy matching as last resort (for unsynchronized terms)
        // Check if the input is contained in the term name or vice versa (case-insensitive)
        $term_name_normalized = strtolower(trim($term->name));
        if (!empty($normalized_input) && !empty($term_name_normalized)) {
            // If one contains the other (for cases like "Genève" vs "Geneva" or partial matches)
            if (strpos($term_name_normalized, $normalized_input) !== false || 
                strpos($normalized_input, $term_name_normalized) !== false) {
                // Only use partial match if the lengths are similar (to avoid false positives)
                $length_diff = abs(strlen($term_name_normalized) - strlen($normalized_input));
                if ($length_diff <= 3) { // Allow up to 3 character difference
                    error_log('InterSoccer: Found term by partial/fuzzy match for "' . $translated_name . '" (matched "' . $term->name . '") in taxonomy "' . $taxonomy . '"');
                    return $term;
                }
            }
        }
    }

    error_log('InterSoccer: WARNING - Could not find term for "' . $translated_name . '" in taxonomy "' . $taxonomy . '" - normalization may fail');
    return null;
}

/**
 * Fallback normalization for taxonomy terms that cannot be found
 * 
 * When a taxonomy term cannot be found (unsynchronized, mistranslated, or missing),
 * this function provides a consistent fallback normalization to ensure signatures
 * remain consistent even when terms are not properly synchronized.
 * 
 * @param string $term_name Term name that couldn't be found
 * @return string Normalized slug format for consistent signatures
 */
function intersoccer_normalize_term_fallback($term_name) {
    if (empty($term_name)) {
        return '';
    }
    
    // Normalize to consistent format: lowercase, trim, remove special characters
    $normalized = strtolower(trim($term_name));
    
    // Remove common special characters and normalize spaces
    $normalized = preg_replace('/[^\w\s-]/', '', $normalized);
    $normalized = preg_replace('/\s+/', '-', $normalized);
    $normalized = trim($normalized, '-');
    
    error_log('InterSoccer: Using fallback normalization for term "' . $term_name . '" -> "' . $normalized . '"');
    
    return $normalized;
}

/**
 * Normalize activity type string to English
 * 
 * Enhanced to handle Tournament/Tournoi and other variations with case-insensitive matching.
 * Note: This function may already be defined in the intersoccer-product-variations plugin.
 * If so, that version will be used (it's more comprehensive).
 */
if (!function_exists('intersoccer_normalize_activity_type')) {
    function intersoccer_normalize_activity_type($activity_type) {
        if (empty($activity_type)) {
            return '';
        }
        
        // Convert to lowercase and remove extra spaces
        $normalized = strtolower(trim($activity_type));

        // Handle common translations (case-insensitive matching)
        // Map: pattern to search for => English result
        $translations = [
            'camp' => 'camp',
            'cours' => 'course', // French for course
            'camp de vacances' => 'camp',
            'stage' => 'course',
            'anniversaire' => 'birthday',
            'birthday' => 'birthday',
            'tournament' => 'tournament',
            'tournoi' => 'tournament', // French for tournament
            'tournois' => 'tournament', // French plural
        ];

        // Check each translation pattern (values are lowercase slugs).
        foreach ($translations as $pattern => $english) {
            if (strpos($normalized, $pattern) !== false) {
                if (function_exists('intersoccer_canonical_activity_type_for_roster')) {
                    return intersoccer_canonical_activity_type_for_roster($english);
                }
                return ucfirst($english);
            }
        }

        if (function_exists('intersoccer_canonical_activity_type_for_roster')) {
            return intersoccer_canonical_activity_type_for_roster($normalized);
        }

        return ucfirst($normalized);
    }
}

if (!function_exists('intersoccer_canonical_activity_type_for_roster')) {
    /**
     * Map any activity type label (EN/FR/DE, slug or name) to the canonical value stored and queried in rosters.
     *
     * @param mixed $activity_type Raw value from order meta, taxonomy, or DB.
     * @return string Canonical value e.g. Course, Camp, Tournament, Girls Only, or best-effort title case.
     */
    function intersoccer_canonical_activity_type_for_roster($activity_type) {
        $raw = trim((string) ($activity_type ?? ''));
        if ($raw === '') {
            return '';
        }

        $already = ['Camp', 'Course', 'Tournament', 'Birthday Party', 'Girls Only', 'Camp, Girls Only', "Camp, Girls' only", 'Course, Girls Only', "Course, Girls' only", 'Event', 'Other'];
        if (in_array($raw, $already, true)) {
            return $raw;
        }

        $norm = function_exists('intersoccer_normalize_comparison_string')
            ? intersoccer_normalize_comparison_string($raw)
            : strtolower($raw);

        $map = [
            'camp' => 'Camp',
            'cours' => 'Course',
            'course' => 'Course',
            'kurs' => 'Course',
            'stage' => 'Course',
            'training' => 'Course',
            'tournament' => 'Tournament',
            'tournoi' => 'Tournament',
            'tournois' => 'Tournament',
            'birthday' => 'Birthday Party',
            'anniversaire' => 'Birthday Party',
            'girls only' => 'Girls Only',
            'camp girls only' => 'Camp, Girls Only',
            'course girls only' => 'Course, Girls Only',
        ];

        foreach ($map as $needle => $canonical) {
            if ($norm === $needle || strpos($norm, $needle) !== false) {
                return $canonical;
            }
        }

        if (preg_match('/\bgirls\b/u', $norm)) {
            if (strpos($norm, 'camp') !== false) {
                return 'Camp, Girls Only';
            }
            if (strpos($norm, 'course') !== false || strpos($norm, 'cours') !== false) {
                return 'Course, Girls Only';
            }
            return 'Girls Only';
        }

        return ucwords($raw);
    }
}

if (!function_exists('intersoccer_roster_listing_activity_types')) {
    /**
     * activity_type values accepted when loading Camps/Courses roster listings (includes legacy FR/DE rows).
     *
     * @param string $kind "camp" or "course".
     * @return string[]
     */
    function intersoccer_roster_listing_activity_types($kind) {
        $kind = strtolower((string) $kind) === 'course' ? 'course' : 'camp';
        if ($kind === 'course') {
            return [
                'Course',
                'Course, Girls Only',
                "Course, Girls' only",
                'course',
                'cours',
                'Cours',
                'kurs',
                'Kurs',
                'stage',
                'Stage',
            ];
        }
        return [
            'Camp',
            'Camp, Girls Only',
            "Camp, Girls' only",
            'camp',
            'campagne',
            'Campagne',
        ];
    }
}

if (!function_exists('intersoccer_roster_row_camp_facets_indicate_camp')) {
    /**
     * True when roster facets look like a camp event (not a course), regardless of activity_type label.
     *
     * @param array<string,mixed> $row
     * @return bool
     */
    function intersoccer_roster_row_camp_facets_indicate_camp(array $row) {
        $camp_terms = trim((string) ($row['camp_terms'] ?? ''));
        $course_day = trim((string) ($row['course_day'] ?? ''));
        $has_camp_terms = $camp_terms !== '' && $camp_terms !== 'N/A';
        $has_course_day = $course_day !== '' && $course_day !== 'N/A';

        if ($has_camp_terms && !$has_course_day) {
            return true;
        }

        $raw = trim((string) ($row['activity_type'] ?? ''));
        if ($has_course_day) {
            return false;
        }

        if ($raw === '') {
            return false;
        }

        $canonical = function_exists('intersoccer_canonical_activity_type_for_roster')
            ? intersoccer_canonical_activity_type_for_roster($raw)
            : $raw;
        $camp_canonical = ['Camp', 'Camp, Girls Only', "Camp, Girls' only"];
        if (in_array($canonical, $camp_canonical, true)) {
            return true;
        }

        $norm = function_exists('intersoccer_normalize_comparison_string')
            ? intersoccer_normalize_comparison_string($canonical)
            : strtolower($canonical);

        return $norm !== ''
            && strpos($norm, 'camp') !== false
            && strpos($norm, 'course') === false
            && strpos($norm, 'cours') === false
            && strpos($norm, 'kurs') === false;
    }
}

if (!function_exists('intersoccer_roster_row_matches_listing_kind')) {
    /**
     * Whether a roster row belongs on the Camps or Courses admin listing.
     *
     * Guards against mis-labeled rows (e.g. birthday parties stored as Camp when camp-shaped
     * order meta is present but Activity Type is missing).
     *
     * @param array  $row  Prepared roster row.
     * @param string $kind "camp" or "course".
     * @return bool
     */
    function intersoccer_roster_row_matches_listing_kind(array $row, $kind) {
        $kind = strtolower((string) $kind) === 'course' ? 'course' : 'camp';

        if (function_exists('intersoccer_get_product_type_safe')) {
            $product_id = (int) ($row['product_id'] ?? 0);
            $variation_id = (int) ($row['variation_id'] ?? 0);
            if ($product_id > 0) {
                $ptype = strtolower((string) intersoccer_get_product_type_safe(
                    $product_id,
                    $variation_id > 0 ? $variation_id : null
                ));
                if ($ptype === 'birthday') {
                    return false;
                }
                if ($kind === 'course' && $ptype === 'course') {
                    if (function_exists('intersoccer_roster_row_camp_facets_indicate_camp')
                        && intersoccer_roster_row_camp_facets_indicate_camp($row)) {
                        return false;
                    }
                    return true;
                }
                if ($kind === 'camp' && $ptype === 'camp') {
                    return true;
                }
                if ($kind === 'camp' && in_array($ptype, ['course', 'tournament'], true)) {
                    return false;
                }
                if ($kind === 'course' && in_array($ptype, ['camp', 'tournament', 'birthday'], true)) {
                    return false;
                }
            }
        }

        if ($kind === 'course' && function_exists('intersoccer_roster_row_camp_facets_indicate_camp')
            && intersoccer_roster_row_camp_facets_indicate_camp($row)) {
            return false;
        }

        $raw = trim((string) ($row['activity_type'] ?? ''));
        $canonical = ($raw !== '' && function_exists('intersoccer_canonical_activity_type_for_roster'))
            ? intersoccer_canonical_activity_type_for_roster($raw)
            : $raw;

        $norm = function_exists('intersoccer_normalize_comparison_string')
            ? intersoccer_normalize_comparison_string($canonical !== '' ? $canonical : $raw)
            : strtolower($canonical !== '' ? $canonical : $raw);

        if ($norm !== '' && (strpos($norm, 'birthday') !== false || strpos($norm, 'anniversaire') !== false)) {
            return false;
        }
        if ($kind === 'camp' && $norm !== '' && strpos($norm, 'tournament') !== false) {
            return false;
        }
        if ($kind === 'camp' && $norm !== '' && strpos($norm, 'camp') === false
            && (strpos($norm, 'course') !== false || strpos($norm, 'cours') !== false || strpos($norm, 'kurs') !== false)) {
            return false;
        }
        if ($kind === 'course' && $norm !== '' && strpos($norm, 'camp') !== false
            && strpos($norm, 'course') === false && strpos($norm, 'cours') === false && strpos($norm, 'kurs') === false) {
            return false;
        }

        $allowed = function_exists('intersoccer_roster_listing_activity_types')
            ? intersoccer_roster_listing_activity_types($kind)
            : ($kind === 'course'
                ? ['Course', 'Course, Girls Only', "Course, Girls' only"]
                : ['Camp', 'Camp, Girls Only', "Camp, Girls' only"]);

        if ($raw !== '' && in_array($raw, $allowed, true)) {
            return true;
        }

        $camp_canonical = ['Camp', 'Camp, Girls Only', "Camp, Girls' only"];
        $course_canonical = ['Course', 'Course, Girls Only', "Course, Girls' only"];

        if ($kind === 'camp') {
            if (in_array($canonical, $camp_canonical, true)) {
                return true;
            }
            return $norm !== '' && strpos($norm, 'camp') !== false && strpos($norm, 'course') === false;
        }

        if (in_array($canonical, $course_canonical, true)) {
            return true;
        }

        return $norm !== '' && (strpos($norm, 'course') !== false || strpos($norm, 'cours') !== false || strpos($norm, 'kurs') !== false);
    }
}

if (!function_exists('intersoccer_roster_row_matches_girls_only_listing')) {
    /**
     * Whether a roster row belongs on the Girls Only admin listing (camps, courses, tournaments only).
     *
     * @param array<string,mixed> $row Prepared roster row.
     * @return bool
     */
    function intersoccer_roster_row_matches_girls_only_listing(array $row) {
        if ((int) ($row['girls_only'] ?? 0) !== 1) {
            return false;
        }

        if (function_exists('intersoccer_roster_row_matches_listing_kind')) {
            if (intersoccer_roster_row_matches_listing_kind($row, 'camp')
                || intersoccer_roster_row_matches_listing_kind($row, 'course')) {
                return true;
            }
        }

        if (function_exists('intersoccer_get_product_type_safe')) {
            $product_id = (int) ($row['product_id'] ?? 0);
            $variation_id = (int) ($row['variation_id'] ?? 0);
            if ($product_id > 0) {
                $ptype = strtolower((string) intersoccer_get_product_type_safe(
                    $product_id,
                    $variation_id > 0 ? $variation_id : null
                ));
                if ($ptype === 'birthday') {
                    return false;
                }
                if (in_array($ptype, ['camp', 'course', 'tournament'], true)) {
                    return true;
                }
            }
        }

        $raw = trim((string) ($row['activity_type'] ?? ''));
        $canonical = ($raw !== '' && function_exists('intersoccer_canonical_activity_type_for_roster'))
            ? intersoccer_canonical_activity_type_for_roster($raw)
            : $raw;
        $norm = function_exists('intersoccer_normalize_comparison_string')
            ? intersoccer_normalize_comparison_string($canonical !== '' ? $canonical : $raw)
            : strtolower($canonical !== '' ? $canonical : $raw);

        if ($norm !== '' && (strpos($norm, 'birthday') !== false || strpos($norm, 'anniversaire') !== false)) {
            return false;
        }

        return $norm !== '' && (strpos($norm, 'tournament') !== false || strpos($norm, 'tournoi') !== false);
    }
}

if (!function_exists('intersoccer_roster_girls_only_listing_bucket')) {
    /**
     * Grouping bucket for Girls Only listings: camp, course, or tournament.
     *
     * @param array<string,mixed> $row
     * @return string Empty when the row should not appear on the page.
     */
    function intersoccer_roster_girls_only_listing_bucket(array $row) {
        if (!function_exists('intersoccer_roster_row_matches_girls_only_listing')
            || !intersoccer_roster_row_matches_girls_only_listing($row)) {
            return '';
        }

        if (function_exists('intersoccer_roster_row_matches_listing_kind')
            && intersoccer_roster_row_matches_listing_kind($row, 'course')) {
            return 'course';
        }

        if (function_exists('intersoccer_roster_row_matches_listing_kind')
            && intersoccer_roster_row_matches_listing_kind($row, 'camp')) {
            return 'camp';
        }

        return 'tournament';
    }
}

if (!function_exists('intersoccer_reports_final_report_order_statuses')) {
    /**
     * WooCommerce order statuses counted in Final Numbers (aligned with roster listing pages).
     *
     * @return string[]
     */
    function intersoccer_reports_final_report_order_statuses() {
        return ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'];
    }
}

if (!function_exists('intersoccer_reports_sql_in_placeholders')) {
    /**
     * @param string[] $values
     * @return string e.g. '%s,%s,%s'
     */
    function intersoccer_reports_sql_in_placeholders(array $values) {
        return implode(',', array_fill(0, count($values), '%s'));
    }
}

/**
 * Generates a stable event signature for roster grouping that doesn't rely on variation_id.
 * This ensures rosters remain properly grouped even when product variations are deleted.
 *
 * @param array $event_data Array containing event characteristics
 * @return string MD5 hash of the event signature
 */
function intersoccer_generate_event_signature($event_data) {
    // Normalize product_id for WPML translations to ensure consistent signatures across languages
    // This ensures French and English versions of the same product generate the same signature
    $product_id = $event_data['product_id'] ?? '';
    if (!empty($product_id) && function_exists('wpml_get_default_language') && function_exists('apply_filters')) {
        $default_lang = wpml_get_default_language();
        if ($default_lang) {
            // First, ensure we have the parent product ID (in case product_id is a variation)
            $product = wc_get_product($product_id);
            if ($product && method_exists($product, 'get_parent_id')) {
                $parent_id = $product->get_parent_id();
                if ($parent_id > 0) {
                    // Use parent product ID instead of variation ID
                    error_log('InterSoccer: Using parent product ID for variation - variation_id: ' . $product_id . ', parent_id: ' . $parent_id);
                    $product_id = $parent_id;
                }
            }
            
            // Now normalize the product_id to the default language version
            // Try with return_original_if_missing = false first to see if translation exists
            $original_product_id = apply_filters('wpml_object_id', $product_id, 'product', false, $default_lang);
            if ($original_product_id && $original_product_id != $product_id) {
                error_log('InterSoccer: Normalizing product_id in signature generation from ' . $product_id . ' to ' . $original_product_id . ' (default_lang: ' . $default_lang . ')');
                $product_id = $original_product_id;
            } else {
                // If no translation found, try with return_original_if_missing = true
                $original_product_id = apply_filters('wpml_object_id', $product_id, 'product', true, $default_lang);
                if ($original_product_id && $original_product_id != $product_id) {
                    error_log('InterSoccer: Normalizing product_id in signature generation from ' . $product_id . ' to ' . $original_product_id . ' (default_lang: ' . $default_lang . ', fallback)');
                    $product_id = $original_product_id;
                } else {
                    // Check if this product might be a translation by checking element type
                    if (function_exists('wpml_get_element_trid')) {
                        $trid = wpml_get_element_trid($product_id, 'post_product');
                        if ($trid) {
                            $translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_product');
                            if ($translations && is_array($translations)) {
                                // Find the default language translation
                                foreach ($translations as $lang_code => $translation) {
                                    if ($lang_code === $default_lang && isset($translation->element_id)) {
                                        $default_product_id = (int)$translation->element_id;
                                        if ($default_product_id != $product_id) {
                                            error_log('InterSoccer: Found default language product via TRID lookup: ' . $product_id . ' -> ' . $default_product_id . ' (default_lang: ' . $default_lang . ')');
                                            $product_id = $default_product_id;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($product_id == ($event_data['product_id'] ?? '')) {
                        error_log('InterSoccer: Product ID ' . $product_id . ' - no translation found or already in default language (' . $default_lang . ')');
                    }
                }
            }
        }
    }
    
    // Normalize translatable term names to slugs for language-agnostic signatures
    $normalized_components = [
        'activity_type' => $event_data['activity_type'] ?? '',
        'venue' => intersoccer_get_term_slug_by_name($event_data['venue'] ?? '', 'pa_intersoccer-venues'),
        'age_group' => intersoccer_get_term_slug_by_name($event_data['age_group'] ?? '', 'pa_age-group'),
        'camp_terms' => intersoccer_get_term_slug_by_name($event_data['camp_terms'] ?? '', 'pa_camp-terms'),
        'course_day' => intersoccer_get_term_slug_by_name($event_data['course_day'] ?? '', 'pa_course-day'),
        'times' => intersoccer_resolve_times_slug_for_signature($event_data['times'] ?? ''),
        'season' => intersoccer_get_term_slug_by_name($event_data['season'] ?? '', 'pa_program-season'),
        'girls_only' => $event_data['girls_only'] ? '1' : '0',
        'city' => intersoccer_get_term_slug_by_name($event_data['city'] ?? '', 'pa_city'),
        'canton_region' => intersoccer_get_term_slug_by_name($event_data['canton_region'] ?? '', 'pa_canton-region'),
        'product_id' => $product_id, // Use normalized product_id
    ];
    // Canonicalize components by activity type so unrelated nullable fields cannot split one event into
    // different signatures (e.g., Camp rows with NULL/na course_day or canton_region drift).
    $activity_kind = strtolower(trim((string) ($normalized_components['activity_type'] ?? '')));
    if ($activity_kind === 'camp') {
        $normalized_components['course_day'] = '';
        $normalized_components['canton_region'] = '';
    } elseif ($activity_kind === 'course') {
        $normalized_components['camp_terms'] = '';
        $normalized_components['canton_region'] = '';
    } elseif ($activity_kind === 'tournament') {
        $normalized_components['course_day'] = '';
        $normalized_components['camp_terms'] = '';
    }

    // For tournaments, include the date in the signature to distinguish between different tournament dates
    // Tournaments are typically one-day events, so we use start_date
    $activity_type = strtolower($normalized_components['activity_type'] ?? '');
    if ($activity_type === 'tournament' && !empty($event_data['start_date'])) {
        // Normalize date to Y-m-d format for consistent signatures
        $date_value = $event_data['start_date'];
        // If date is not already in Y-m-d format, try to parse it
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_value) === 0) {
            $parsed_date = intersoccer_parse_date_unified($date_value, 'event_signature');
            if ($parsed_date) {
                $date_value = $parsed_date;
            }
        }
        $normalized_components['start_date'] = $date_value;
    }

    // Create a normalized string from components
    $signature_string = implode('|', array_map(function($key, $value) {
        return $key . ':' . trim(strtolower($value));
    }, array_keys($normalized_components), $normalized_components));

    // Generate MD5 hash for consistent length and comparison
    $signature = md5($signature_string);

    error_log('InterSoccer: Generated normalized event signature: ' . $signature . ' from components: ' . json_encode($normalized_components));

    return $signature;
}

/**
 * Get term slug by name for normalization
 * 
 * Uses robust translation-aware lookup to ensure consistent slugs across languages.
 * This ensures that "Geneva" and "Genève" both return the same slug.
 */
function intersoccer_get_term_slug_by_name($name, $taxonomy) {
    if (empty($name) || empty($taxonomy)) {
        return $name; // Return as-is if empty
    }

    if (function_exists('intersoccer_get_term_in_default_language')) {
        $term = intersoccer_get_term_in_default_language($name, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return $term->slug;
        }
    }

    // Fallback: try direct lookup (for backwards compatibility)
    $term = get_term_by('slug', $name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        return $term->slug;
    }

    $term = get_term_by('name', $name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        return $term->slug;
    }
    
    // Fallback: use fallback normalization to ensure consistent signatures
    if (function_exists('intersoccer_normalize_term_fallback')) {
        $fallback = intersoccer_normalize_term_fallback($name);
        return $fallback;
    }
    
    // Last resort: return original name (lowercased for consistency)
    return strtolower($name);
}

/**
 * Normalize season for display in English
 */
function intersoccer_normalize_season_for_display($season) {
    if (empty($season)) return $season;

    $normalized = str_ireplace('Hiver', 'Winter', $season);
    $normalized = str_ireplace('hiver', 'winter', $normalized);
    $normalized = str_ireplace('Été', 'Summer', $normalized);
    $normalized = str_ireplace('été', 'summer', $normalized);
    $normalized = str_ireplace('Printemps', 'Spring', $normalized);
    $normalized = str_ireplace('printemps', 'spring', $normalized);
    $normalized = str_ireplace('Automne', 'Autumn', $normalized);
    $normalized = str_ireplace('automne', 'autumn', $normalized);

    return $normalized;
}

/**
 * Whether a roster row/group belongs on the Courses listing (for season label correction).
 *
 * @param array<string,mixed> $row
 * @return bool
 */
function intersoccer_roster_row_is_course_listing_context(array $row) {
    if (!empty($row['course_day']) && $row['course_day'] !== 'N/A') {
        return true;
    }

    $product_name = strtolower((string) ($row['product_name'] ?? ''));
    if ($product_name !== '' && $product_name !== 'n/a') {
        if (strpos($product_name, 'course') !== false || strpos($product_name, 'cours') !== false) {
            return true;
        }
    }

    $activity = strtolower((string) ($row['activity_type'] ?? ''));
    if ($activity !== '' && (strpos($activity, 'course') !== false || strpos($activity, 'cours') !== false)) {
        return true;
    }

    if (!empty($row['product_id']) && function_exists('intersoccer_get_product_type_safe')) {
        return intersoccer_get_product_type_safe((int) $row['product_id']) === 'course';
    }

    return false;
}

/**
 * Resolve a season value to the canonical WooCommerce program-season label (English term name).
 *
 * @param string $season_raw Slug, translated name, or legacy label.
 * @return string
 */
function intersoccer_roster_resolve_season_taxonomy_label($season_raw) {
    $season_raw = trim((string) $season_raw);
    if ($season_raw === '' || strcasecmp($season_raw, 'N/A') === 0) {
        return $season_raw;
    }

    if (function_exists('intersoccer_get_term_name')) {
        $resolved = trim((string) intersoccer_get_term_name($season_raw, 'pa_program-season'));
        if ($resolved !== '' && strcasecmp($resolved, 'N/A') !== 0 && $resolved !== $season_raw) {
            return $resolved;
        }
    }

    // Fallback when taxonomy lookup fails: humanize attribute slugs (e.g. summer-courses-2026).
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/i', $season_raw)) {
        $parts = preg_split('/-+/', strtolower($season_raw)) ?: [];
        $parts = array_values(array_filter($parts, static function ($part) {
            return $part !== '';
        }));
        if (!empty($parts)) {
            return implode(' ', array_map('ucfirst', $parts));
        }
    }

    return $season_raw;
}

/**
 * Normalize season label for the Courses roster page (canonical term name + camp→course correction).
 *
 * @param string              $season_raw
 * @param array<string,mixed> $row
 * @return string
 */
function intersoccer_roster_normalize_course_listing_season($season_raw, array $row = []) {
    $season_raw = trim((string) $season_raw);
    if ($season_raw === '' || strcasecmp($season_raw, 'N/A') === 0) {
        return $season_raw;
    }

    $display = intersoccer_roster_resolve_season_taxonomy_label($season_raw);
    $display = intersoccer_normalize_season_for_display($display);

    if (!intersoccer_roster_row_is_course_listing_context($row)) {
        return $display;
    }

    $needs_camp_fix = (stripos($season_raw, 'camp') !== false || stripos($display, 'camp') !== false);
    if (!$needs_camp_fix) {
        return $display;
    }

    $corrected = preg_replace('/\bcamps\b/i', 'Courses', $display);
    $corrected = preg_replace('/\bcamp\b/i', 'Course', (string) $corrected);
    $corrected = preg_replace('/-camps-/i', '-courses-', (string) $corrected);
    $corrected = preg_replace('/-camp-/i', '-course-', (string) $corrected);

    return trim((string) $corrected);
}

/**
 * Map a persisted/raw season filter value to a dropdown option on the Courses page.
 *
 * @param string              $selected
 * @param array<int,string>   $available_seasons
 * @return string
 */
function intersoccer_roster_resolve_course_season_for_filter_ui(string $selected, array $available_seasons): string {
    if ($selected === '') {
        return '';
    }
    if (in_array($selected, $available_seasons, true)) {
        return $selected;
    }
    foreach ($available_seasons as $season) {
        if (!function_exists('intersoccer_roster_course_season_filter_matches')) {
            break;
        }
        if (intersoccer_roster_course_season_filter_matches(
            ['season' => $season, 'season_raw' => $selected],
            $selected
        )) {
            return $season;
        }
    }
    return $selected;
}

/**
 * Whether a course listing group matches the selected season filter (display + raw + legacy camp labels).
 *
 * @param array<string,mixed> $group
 * @param string              $filter_season
 * @return bool
 */
function intersoccer_roster_course_season_filter_matches(array $group, string $filter_season): bool {
    if ($filter_season === '') {
        return true;
    }

    $group_season = (string) ($group['season'] ?? '');
    $group_raw = (string) ($group['season_raw'] ?? '');

    if ($filter_season === $group_season) {
        return true;
    }

    if (!function_exists('intersoccer_roster_normalize_course_listing_season')) {
        return $filter_season === $group_raw && $group_raw === $group_season;
    }

    $row_context = [
        'product_name' => $group['product_name'] ?? '',
        'course_day' => $group['course_day'] ?? '',
        'activity_type' => 'Course',
    ];
    $normalized_filter = intersoccer_roster_normalize_course_listing_season($filter_season, $row_context);
    $normalized_group = intersoccer_roster_normalize_course_listing_season(
        $group_season !== '' ? $group_season : $group_raw,
        $row_context
    );
    $normalized_raw = intersoccer_roster_normalize_course_listing_season($group_raw, $row_context);

    if ($normalized_filter !== '' && $normalized_filter === $normalized_group) {
        return true;
    }

    // Legacy camp→course labels: filter may match persisted raw while display season was corrected.
    if (
        $filter_season === $group_raw
        && $normalized_filter !== ''
        && $normalized_filter === $normalized_raw
        && $normalized_filter === $normalized_group
    ) {
        return true;
    }

    return false;
}

/**
 * Parse camp dates from camp_terms string
 * @param string $camp_terms The camp terms string
 * @param string $season The season/year
 * @return array [$start_date, $end_date, $event_dates]
 */
function intersoccer_parse_camp_dates_fixed($camp_terms, $season) {
    $start_date = null;
    $end_date = null;
    $event_dates = 'N/A';

    if (empty($camp_terms) || $camp_terms === 'N/A') {
        return [$start_date, $end_date, $event_dates];
    }

    // Extract year from season if available
    $year = null;
    if (!empty($season)) {
        // Try to extract year from season string (e.g., "Autumn camps 2025" -> 2025)
        if (preg_match('/\b(19|20)\d{2}\b/', $season, $year_matches)) {
            $year = intval($year_matches[0]);
        } elseif (is_numeric($season)) {
            $year = intval($season);
        }
    }
    if (!$year) {
        $year = date('Y');
    }

    // Try first regex pattern: month-week-X-month-day-month-day-days
    if (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\w+)-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
        $start_month = $matches[2];
        $start_day = $matches[3];
        $end_month = $matches[4];
        $end_day = $matches[5];

        $start_date_obj = DateTime::createFromFormat('F j Y', "$start_month $start_day $year");
        $end_date_obj = DateTime::createFromFormat('F j Y', "$end_month $end_day $year");

        if ($start_date_obj && $end_date_obj) {
            $start_date = $start_date_obj->format('Y-m-d');
            $end_date = $end_date_obj->format('Y-m-d');
            $event_dates = "$start_date to $end_date";
        }
    }
    // Try second regex pattern: month-week-X-month-day-day-days
    elseif (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
        $month = $matches[2];
        $start_day = $matches[3];
        $end_day = $matches[4];

        $start_date_obj = DateTime::createFromFormat('F j Y', "$month $start_day $year");
        $end_date_obj = DateTime::createFromFormat('F j Y', "$month $end_day $year");

        if ($start_date_obj && $end_date_obj) {
            $start_date = $start_date_obj->format('Y-m-d');
            $end_date = $end_date_obj->format('Y-m-d');
            $event_dates = "$start_date to $end_date";
        }
    }
    // Try third pattern: Season: Month Day (e.g., "Autumn: September 30", "Winter: December 15")
    elseif (preg_match('/^\s*\w+\s*:\s*(\w+)\s+(\d{1,2})\s*$/i', $camp_terms, $matches)) {
        $month = $matches[1];
        $day = intval($matches[2]);

        $date_obj = DateTime::createFromFormat('F j Y', "$month $day $year");
        if ($date_obj) {
            $start_date = $date_obj->format('Y-m-d');
            $end_date = $start_date; // Single day event
            $event_dates = $start_date;
        }
    }
    // Try fourth pattern: Just "Month Day" (e.g., "September 30")
    elseif (preg_match('/^\s*(\w+)\s+(\d{1,2})\s*$/i', $camp_terms, $matches)) {
        $month = $matches[1];
        $day = intval($matches[2]);

        $date_obj = DateTime::createFromFormat('F j Y', "$month $day $year");
        if ($date_obj) {
            $start_date = $date_obj->format('Y-m-d');
            $end_date = $start_date; // Single day event
            $event_dates = $start_date;
        }
    }

    return [$start_date, $end_date, $event_dates];
}

require_once __DIR__ . '/order-meta-keys.php';
