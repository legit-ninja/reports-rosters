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
 * Helper function to safely get term name
 */
function intersoccer_get_term_name($value, $taxonomy) {
    if (empty($value) || $value === 'N/A') {
        return 'N/A';
    }
    $term = get_term_by('slug', $value, $taxonomy);
    return $term ? $term->name : $value;
}