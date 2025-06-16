<?php
/**
 * Utility functions for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.1
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_normalize_attribute')) {
    /**
     * Normalize attribute values for comparison.
     *
     * @param mixed $value Attribute value (string or array).
     * @return string Normalized value or empty string if invalid.
     */
    function intersoccer_normalize_attribute($value) {
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

// Function to get term name from slug
function intersoccer_get_term_name($slug, $taxonomy) {
    $term = get_term_by('slug', $slug, $taxonomy);
    return $term ? $term->name : $slug;
}
?>
