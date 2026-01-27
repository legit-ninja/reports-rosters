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
    
    // If WPML is active, try to get the English version
    if (function_exists('apply_filters')) {
        $default_lang = apply_filters('wpml_default_language', null);
        if (!empty($default_lang)) {
            // Store current language to restore later
            $current_lang = apply_filters('wpml_current_language', null);
            
            // Switch to default language to get English term name
            if ($current_lang && $current_lang !== $default_lang) {
                do_action('wpml_switch_language', $default_lang);
            }
            
            // Try to find the term by slug first
            $term = get_term_by('slug', $value, $taxonomy);
            if (!$term || is_wp_error($term)) {
                // Try by name
                $term = get_term_by('name', $value, $taxonomy);
            }
            
            // If still not found, try using the robust translation-aware function
            if ((!$term || is_wp_error($term)) && function_exists('intersoccer_get_term_by_translated_name')) {
                $term = intersoccer_get_term_by_translated_name($value, $taxonomy);
            }
            
            // Restore original language
            if ($current_lang && $current_lang !== $default_lang) {
                do_action('wpml_switch_language', $current_lang);
            }
            
            // If we found a term, return its name (should be in English now)
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
        }
    }
    
    // Fallback: try simple lookup without language switching
    $term = get_term_by('slug', $value, $taxonomy);
    if (!$term || is_wp_error($term)) {
        $term = get_term_by('name', $value, $taxonomy);
    }
    
    return ($term && !is_wp_error($term)) ? $term->name : $value;
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
            
            // If we found a different product ID, get the English product name
            if ($original_product_id && $original_product_id != $product_id) {
                // Store current language to restore later
                $current_lang = apply_filters('wpml_current_language', null);
                
                // Switch to default language to get English product name
                if ($current_lang && $current_lang !== $default_lang) {
                    do_action('wpml_switch_language', $default_lang);
                }
                
                $english_product = wc_get_product($original_product_id);
                if ($english_product) {
                    $english_name = $english_product->get_name();
                    // Switch back to original language
                    if ($current_lang && $current_lang !== $default_lang) {
                        do_action('wpml_switch_language', $current_lang);
                    }
                    if (!empty($english_name)) {
                        return $english_name;
                    }
                } else {
                    // Switch back to original language
                    if ($current_lang && $current_lang !== $default_lang) {
                        do_action('wpml_switch_language', $current_lang);
                    }
                }
            } else {
                // Product is already in default language, but verify the name is correct
                // Switch to default language context to ensure we get the right name
                $current_lang = apply_filters('wpml_current_language', null);
                if ($current_lang && $current_lang !== $default_lang) {
                    do_action('wpml_switch_language', $default_lang);
                    $product = wc_get_product($normalized_product_id);
                    if ($product) {
                        $english_name = $product->get_name();
                        do_action('wpml_switch_language', $current_lang);
                        if (!empty($english_name)) {
                            return $english_name;
                        }
                    } else {
                        do_action('wpml_switch_language', $current_lang);
                    }
                }
            }
        }
    }
    
    // Fallback: return original name if we can't normalize it
    return $product_name;
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
    
    $default_lang = wpml_get_default_language();
    if (empty($default_lang)) {
        return $variation_id;
    }
    
    // Get the default language version of the variation
    $default_variation_id = apply_filters('wpml_object_id', $variation_id, 'product_variation', true, $default_lang);
    
    if ($default_variation_id && $default_variation_id != $variation_id) {
        error_log('InterSoccer: Found default language variation ID ' . $default_variation_id . ' for variation ' . $variation_id);
        return $default_variation_id;
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

        $booking_norm = strtolower(trim((string) $booking_type));

        if ($booking_norm === 'full-week' || $booking_norm === 'full week') {
            return ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
        }

        $raw = trim((string) $selected_days);
        if ($raw === '') {
            return $presence;
        }

        // Split on common delimiters: comma, semicolon, slash, pipe.
        $tokens = preg_split('/[,;\/|]+/', $raw) ?: [];
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

/**
 * Shared function to insert or update a roster entry from an order item.
 * Ensures consistent data extraction and insertion across all population points.
 *
 * @param int $order_id Order ID.
 * @param int $item_id Order item ID.
 * @return bool True if inserted/updated successfully, false otherwise.
 */
function intersoccer_update_roster_entry($order_id, $item_id) {
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
    if (function_exists('wpml_get_default_language') && function_exists('wpml_get_current_language')) {
        $current_lang = wpml_get_current_language();
        $default_lang = wpml_get_default_language();
        
        if ($current_lang !== $default_lang) {
            // Switch to default language to get English product name
            do_action('wpml_switch_language', $default_lang);
            $product = wc_get_product($product_id);
            if ($product) {
                $product_name = $product->get_name();
            }
            // Switch back to original language
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
    if (strtolower($booking_type) === 'single-days') {
        $days = array_map('trim', explode(',', (string) $selected_days));
        foreach ($days as $day) {
            $canonical_day = function_exists('intersoccer_normalize_weekday_token')
                ? intersoccer_normalize_weekday_token($day)
                : $day;

            if ($canonical_day && array_key_exists($canonical_day, $day_presence)) {
                $day_presence[$canonical_day] = 'Yes';
            }
        }
    } elseif (strtolower($booking_type) === 'full-week') {
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
        'booking_type' => substr((string)($booking_type ?: 'Unknown'), 0, 50),
        'selected_days' => $selected_days,
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
        'days_selected' => substr((string)($selected_days ?: 'N/A'), 0, 200),
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
    $data['event_signature'] = intersoccer_generate_event_signature($normalized_event_data);
    
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
                "SELECT id, activity_type, venue, age_group, camp_terms, course_day, times, season, girls_only, product_id 
                 FROM {$rosters_table} 
                 WHERE order_item_id = %d 
                 LIMIT 1",
                $order_item_id
            ),
            ARRAY_A
        );

        if (!$record) {
            error_log('InterSoccer: No roster entry found for order item ' . $order_item_id . ' when rebuilding event signature.');
            return false;
        }

        $normalized_data = intersoccer_normalize_event_data_for_signature([
            'activity_type' => $record['activity_type'],
            'venue'         => $record['venue'],
            'age_group'     => $record['age_group'],
            'camp_terms'    => $record['camp_terms'],
            'course_day'    => $record['course_day'],
            'times'         => $record['times'],
            'season'        => $record['season'],
            'girls_only'    => (bool) $record['girls_only'],
            'product_id'    => $record['product_id'],
        ]);

        $signature = intersoccer_generate_event_signature($normalized_data);

        $updated = $wpdb->update(
            $rosters_table,
            ['event_signature' => $signature],
            ['id' => $record['id']],
            ['%s'],
            ['%d']
        );

        if ($updated !== false) {
            error_log('InterSoccer: Rebuilt event signature ' . $signature . ' for order item ' . $order_item_id . '.');
            return true;
        }

        error_log('InterSoccer: Failed to rebuild event signature for order item ' . $order_item_id . ' - DB error: ' . $wpdb->last_error);
        return false;
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

// Add this action to run the test (remove after testing)
add_action('admin_init', 'intersoccer_test_process_orders');

function intersoccer_get_product_type_safe($product_id, $variation_id = null) {
    error_log('InterSoccer: get_product_type_safe called with product_id: ' . $product_id . ', variation_id: ' . $variation_id);
    
    // Check if the Product Variations plugin function exists
    if (!function_exists('intersoccer_get_product_type')) {
        error_log('InterSoccer: CRITICAL - intersoccer_get_product_type function not found from Product Variations plugin');
        return 'unknown';
    }
    
    // Try variation ID first if provided
    if ($variation_id && $variation_id > 0) {
        $type = intersoccer_get_product_type($variation_id);
        error_log('InterSoccer: Product type for variation ' . $variation_id . ': ' . var_export($type, true));
        if (!empty($type)) {
            return $type;
        }
    }
    
    // Try parent product ID
    $type = intersoccer_get_product_type($product_id);
    error_log('InterSoccer: Product type for parent ' . $product_id . ': ' . var_export($type, true));
    if (!empty($type)) {
        return $type;
    }
    
    // Manual fallback if the function fails
    error_log('InterSoccer: Manual fallback for product type detection');
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

/**
 * Normalizes event data to English for consistent event signature generation.
 * This ensures that orders placed in different languages are grouped with the correct rosters.
 *
 * @param array $event_data Array containing event characteristics
 * @return array Normalized event data in English
 */
function intersoccer_normalize_event_data_for_signature($event_data) {
    // Store current language if using WPML
    $current_lang = '';
    if (function_exists('wpml_get_current_language')) {
        $current_lang = wpml_get_current_language();
    }

    // Switch to default language to get English values
    $default_lang = '';
    if (function_exists('wpml_get_default_language')) {
        $default_lang = wpml_get_default_language();
        if ($current_lang !== $default_lang) {
            do_action('wpml_switch_language', $default_lang);
        }
    }

    $normalized = $event_data;

    try {
        // For taxonomy-based attributes, the order metadata contains translated names
        // We need to find the term by name in current language, then get the name in default language

        // Normalize venue (taxonomy term name)
        if (!empty($event_data['venue'])) {
            $term = intersoccer_get_term_by_translated_name($event_data['venue'], 'pa_intersoccer-venues');
            if ($term) {
                $normalized['venue'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['venue'] = intersoccer_normalize_term_fallback($event_data['venue']);
            }
        }

        // Normalize age_group (taxonomy term name)
        if (!empty($event_data['age_group'])) {
            $term = intersoccer_get_term_by_translated_name($event_data['age_group'], 'pa_age-group');
            if ($term) {
                $normalized['age_group'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['age_group'] = intersoccer_normalize_term_fallback($event_data['age_group']);
            }
        }

        // Normalize camp_terms (taxonomy term name)
        if (!empty($event_data['camp_terms'])) {
            $term = intersoccer_get_term_by_translated_name($event_data['camp_terms'], 'pa_camp-terms');
            if ($term) {
                $normalized['camp_terms'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['camp_terms'] = intersoccer_normalize_term_fallback($event_data['camp_terms']);
            }
        }

        // Normalize course_day (taxonomy term name)
        if (!empty($event_data['course_day'])) {
            $term = intersoccer_get_term_by_translated_name($event_data['course_day'], 'pa_course-day');
            if ($term) {
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
                $term = intersoccer_get_term_by_translated_name($event_data['times'], $taxonomy);
                if ($term) break;
            }
            if ($term) {
                $normalized['times'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['times'] = intersoccer_normalize_term_fallback($event_data['times']);
            }
        }

        // Normalize season (taxonomy term name)
        if (!empty($event_data['season'])) {
            $normalized['season'] = $event_data['season'];
            $term = intersoccer_get_term_by_translated_name($event_data['season'], 'pa_program-season');
            if ($term) {
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
            $term = intersoccer_get_term_by_translated_name($event_data['city'], 'pa_city');
            if ($term) {
                $normalized['city'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['city'] = intersoccer_normalize_term_fallback($event_data['city']);
            }
        }

        // Normalize canton_region (taxonomy term name) - important for tournaments
        if (!empty($event_data['canton_region'])) {
            $term = intersoccer_get_term_by_translated_name($event_data['canton_region'], 'pa_canton-region');
            if ($term) {
                $normalized['canton_region'] = $term->name;
            } else {
                // Use fallback normalization to ensure consistent signatures
                $normalized['canton_region'] = intersoccer_normalize_term_fallback($event_data['canton_region']);
            }
        }

        // Normalize activity_type - this might be a direct value, not a taxonomy term
        if (!empty($event_data['activity_type'])) {
            // Check if it's a taxonomy term first
            $term = intersoccer_get_term_by_translated_name($event_data['activity_type'], 'pa_activity-type');
            if ($term) {
                $normalized['activity_type'] = $term->name;
            } else {
                // If not a taxonomy term, normalize the string directly
                $normalized['activity_type'] = intersoccer_normalize_activity_type($event_data['activity_type']);
            }
        }

        error_log('InterSoccer: Normalized event data for signature: ' . json_encode([
            'original' => $event_data,
            'normalized' => $normalized
        ]));

    } catch (Exception $e) {
        error_log('InterSoccer: Error normalizing event data: ' . $e->getMessage());
        // Return original data if normalization fails
        $normalized = $event_data;
    }

    // Switch back to original language
    if (!empty($current_lang) && $current_lang !== $default_lang && function_exists('do_action')) {
        do_action('wpml_switch_language', $current_lang);
    }

    return $normalized;
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

        // Check each translation pattern
        foreach ($translations as $pattern => $english) {
            if (strpos($normalized, $pattern) !== false) {
                error_log('InterSoccer: Normalized activity type "' . $activity_type . '" to "' . $english . '"');
                return $english;
            }
        }

        // If no match found, return as-is but normalized (lowercase, trimmed)
        error_log('InterSoccer: Activity type "' . $activity_type . '" not matched, using normalized: "' . $normalized . '"');
        return $normalized;
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
        'camp_terms' => $event_data['camp_terms'] ?? '',
        'course_day' => intersoccer_get_term_slug_by_name($event_data['course_day'] ?? '', 'pa_course-day'),
        'times' => $event_data['times'] ?? '',
        'season' => intersoccer_get_term_slug_by_name($event_data['season'] ?? '', 'pa_program-season'),
        'girls_only' => $event_data['girls_only'] ? '1' : '0',
        'city' => intersoccer_get_term_slug_by_name($event_data['city'] ?? '', 'pa_city'),
        'canton_region' => intersoccer_get_term_slug_by_name($event_data['canton_region'] ?? '', 'pa_canton-region'),
        'product_id' => $product_id, // Use normalized product_id
    ];
    
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
    
    // Use robust translation-aware lookup for consistency
    if (function_exists('intersoccer_get_term_by_translated_name')) {
        $term = intersoccer_get_term_by_translated_name($name, $taxonomy);
        if ($term && !is_wp_error($term)) {
            error_log('InterSoccer: Found term slug via robust normalization for "' . $name . '" in taxonomy "' . $taxonomy . '" -> slug: "' . $term->slug . '"');
            return $term->slug;
        }
    }
    
    // Fallback: try direct lookup (for backwards compatibility)
    $term = get_term_by('name', $name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        error_log('InterSoccer: Found term slug via direct name lookup for "' . $name . '" in taxonomy "' . $taxonomy . '" -> slug: "' . $term->slug . '"');
        return $term->slug;
    }
    
    // If not found by name, try as slug already
    $term = get_term_by('slug', $name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        error_log('InterSoccer: Found term slug via direct slug lookup for "' . $name . '" in taxonomy "' . $taxonomy . '" -> slug: "' . $term->slug . '"');
        return $term->slug;
    }
    
    // Fallback: use fallback normalization to ensure consistent signatures
    if (function_exists('intersoccer_normalize_term_fallback')) {
        $fallback = intersoccer_normalize_term_fallback($name);
        error_log('InterSoccer: Using fallback normalization for term "' . $name . '" in taxonomy "' . $taxonomy . '" -> "' . $fallback . '"');
        return $fallback;
    }
    
    // Last resort: return original name (lowercased for consistency)
    error_log('InterSoccer: WARNING - Could not find term slug for "' . $name . '" in taxonomy "' . $taxonomy . '", returning as-is');
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
