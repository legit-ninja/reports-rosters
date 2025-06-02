<?php
/**
 * Shared utility functions for InterSoccer Reports and Rosters plugin.
 */

// Fetch all attribute terms for a given attribute across all variations
function intersoccer_get_attribute_terms($attribute_name) {
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
}
?>
