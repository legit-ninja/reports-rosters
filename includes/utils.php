<?php
/**
 * Shared utility functions for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.3
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Fetch all attribute terms for a given attribute across all variations.
 *
 * @param string $attribute_name The attribute name (e.g., 'pa_canton-region').
 * @return array List of unique terms.
 */
function intersoccer_get_attribute_terms($attribute_name) {
    try {
        if (!function_exists('wc_get_products')) {
            error_log('InterSoccer: wc_get_products not available in intersoccer_get_attribute_terms');
            return [];
        }

        $terms = [];
        $products = wc_get_products([
            'type' => 'variable',
            'limit' => -1,
            'status' => 'publish',
        ]);

        foreach ($products as $product) {
            $product_id = $product->get_id();
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }

                $variation_terms = wc_get_product_terms($product_id, $attribute_name, ['fields' => 'names']);
                foreach ($variation_terms as $term) {
                    if (!in_array($term, $terms)) {
                        $terms[] = $term;
                    }
                }
            }
        }

        sort($terms);
        return $terms;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_get_attribute_terms: ' . $e->getMessage());
        return [];
    }
}
?>
