<?php
/**
 * File: placeholder-rosters.php
 * Description: Manages placeholder roster entries for migration purposes
 * Version: 1.0.0
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

error_log('InterSoccer: placeholder-rosters.php file loaded');

/**
 * Hook into product save to create/update placeholder rosters
 */
add_action('woocommerce_new_product', 'intersoccer_create_placeholders_for_product', 10, 1);
add_action('woocommerce_update_product', 'intersoccer_create_placeholders_for_product', 10, 1);

/**
 * Create or update placeholder rosters when a product is published
 * 
 * @param int $product_id The product ID
 */
function intersoccer_create_placeholders_for_product($product_id) {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database')) {
        try {
            $manager = intersoccer_oop_get_placeholder_manager();
            $result = $manager->createForProduct($product_id);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer Placeholder (OOP): Processed product ' . $product_id . ' - ' . json_encode($result));
            }

            return;
        } catch (\Exception $e) {
            error_log('InterSoccer Placeholder (OOP): Failed to create placeholders for product ' . $product_id . ' - ' . $e->getMessage());
        }
    }

    $product = wc_get_product($product_id);

    if (!$product) {
        error_log('InterSoccer Placeholder: Invalid product ID ' . $product_id);
        return;
    }

    if ($product->get_status() !== 'publish') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Placeholder: Skipping non-published product ' . $product_id);
        }
        return;
    }

    if (!$product->is_type('variable')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Placeholder: Skipping non-variable product ' . $product_id);
        }
        return;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Placeholder: Creating/updating placeholders for product ' . $product_id);
    }

    $variations = $product->get_available_variations();
    $created = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($variations as $variation_data) {
        $variation_id = $variation_data['variation_id'];
        $result = intersoccer_create_placeholder_from_variation($variation_id, $product_id);

        if ($result === 'created') {
            $created++;
        } elseif ($result === 'updated') {
            $updated++;
        } else {
            $skipped++;
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("InterSoccer Placeholder: Processed product $product_id - Created: $created, Updated: $updated, Skipped: $skipped");
    }
}

/**
 * Create a placeholder roster entry from a product variation
 * 
 * @param int $variation_id The variation ID
 * @param int $product_id The parent product ID
 * @return string 'created', 'updated', or 'skipped'
 */
function intersoccer_create_placeholder_from_variation($variation_id, $product_id) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    $variation = wc_get_product($variation_id);
    $parent_product = wc_get_product($product_id);
    
    if (!$variation || !$parent_product) {
        error_log('InterSoccer Placeholder: Invalid variation ' . $variation_id . ' or product ' . $product_id);
        return 'skipped';
    }

    // Extract event attributes from the variation
    $event_data = intersoccer_extract_event_data_from_variation($variation, $parent_product);
    
    if (!$event_data) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Placeholder: Could not extract event data for variation ' . $variation_id);
        }
        return 'skipped';
    }

    // Generate event signature
    $normalized_data = intersoccer_normalize_event_data_for_signature($event_data);
    $event_signature = intersoccer_generate_event_signature($normalized_data);

    // Check if placeholder already exists for this event_signature
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $rosters_table WHERE event_signature = %s AND is_placeholder = 1",
        $event_signature
    ));

    // Prepare placeholder entry data
    $placeholder_data = [
        'order_id' => 0,
        'order_item_id' => 0,
        'variation_id' => $variation_id,
        'player_name' => 'Empty Roster',
        'first_name' => 'Empty',
        'last_name' => 'Roster',
        'age' => null,
        'gender' => 'N/A',
        'booking_type' => $event_data['booking_type'] ?? 'Unknown',
        'selected_days' => 'N/A',
        'camp_terms' => $event_data['camp_terms'] ?? 'N/A',
        'venue' => $event_data['venue'] ?? 'Unknown Venue',
        'parent_phone' => 'N/A',
        'parent_email' => 'N/A',
        'medical_conditions' => '',
        'late_pickup' => 'No',
        'late_pickup_days' => '',
        'day_presence' => json_encode(['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No']),
        'age_group' => $event_data['age_group'] ?? 'N/A',
        'start_date' => '1970-01-01',
        'end_date' => '1970-01-01',
        'event_dates' => 'N/A',
        'product_name' => $parent_product->get_name(),
        'activity_type' => $event_data['activity_type'] ?? 'Event',
        'shirt_size' => 'N/A',
        'shorts_size' => 'N/A',
        'registration_timestamp' => current_time('mysql'),
        'course_day' => $event_data['course_day'] ?? 'N/A',
        'updated_at' => current_time('mysql'),
        'product_id' => $product_id,
        'player_first_name' => 'Empty',
        'player_last_name' => 'Roster',
        'player_dob' => '1970-01-01',
        'player_gender' => 'N/A',
        'player_medical' => '',
        'player_dietary' => '',
        'parent_first_name' => 'Placeholder',
        'parent_last_name' => 'Entry',
        'emergency_contact' => 'N/A',
        'term' => $event_data['camp_terms'] ?? $event_data['course_day'] ?? 'N/A',
        'times' => $event_data['times'] ?? 'N/A',
        'days_selected' => 'N/A',
        'season' => $event_data['season'] ?? 'N/A',
        'canton_region' => '',
        'city' => '',
        'avs_number' => 'N/A',
        'created_at' => current_time('mysql'),
        'base_price' => 0.00,
        'discount_amount' => 0.00,
        'final_price' => 0.00,
        'reimbursement' => 0.00,
        'discount_codes' => '',
        'girls_only' => $event_data['girls_only'] ?? false,
        'event_signature' => $event_signature,
        'is_placeholder' => 1,
    ];

    if ($existing) {
        // Update existing placeholder
        $result = $wpdb->update(
            $rosters_table,
            $placeholder_data,
            ['id' => $existing->id],
            [
                '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%d',
                '%s', '%d'
            ],
            ['%d']
        );

        if ($result !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer Placeholder: Updated placeholder for variation ' . $variation_id . ' (signature: ' . $event_signature . ')');
            }
            return 'updated';
        } else {
            error_log('InterSoccer Placeholder: Failed to update placeholder for variation ' . $variation_id);
            return 'skipped';
        }
    } else {
        // Insert new placeholder
        $result = $wpdb->insert(
            $rosters_table,
            $placeholder_data,
            [
                '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%d',
                '%s', '%d'
            ]
        );

        if ($result) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer Placeholder: Created placeholder for variation ' . $variation_id . ' (signature: ' . $event_signature . ')');
            }
            return 'created';
        } else {
            error_log('InterSoccer Placeholder: Failed to create placeholder for variation ' . $variation_id . ' - ' . $wpdb->last_error);
            return 'skipped';
        }
    }
}

/**
 * Extract event data from a variation and parent product
 * 
 * @param WC_Product_Variation $variation The variation object
 * @param WC_Product $parent_product The parent product object
 * @return array|false Event data array or false on failure
 */
function intersoccer_extract_event_data_from_variation($variation, $parent_product) {
    if (!$variation || !$parent_product) {
        return false;
    }

    $variation_id = $variation->get_id();
    $product_id = $parent_product->get_id();

    // Extract activity type
    $activity_type_raw = $variation->get_attribute('pa_activity-type') ?: $parent_product->get_attribute('pa_activity-type');
    $activity_type = 'Event';
    $girls_only = false;

    if ($activity_type_raw) {
        $normalized = strtolower(trim($activity_type_raw));
        $types = array_map('trim', explode(',', $normalized));
        
        if (in_array('girls only', $types) || in_array('girls\' only', $types)) {
            $girls_only = true;
            $activity_type = 'Girls Only';
        } elseif (in_array('camp', $types)) {
            $activity_type = 'Camp';
        } elseif (in_array('course', $types)) {
            $activity_type = 'Course';
        } else {
            $activity_type = ucfirst($types[0]);
        }
    }

    // Extract other attributes
    $venue = $variation->get_attribute('pa_intersoccer-venues') ?: $parent_product->get_attribute('pa_intersoccer-venues') ?: 'Unknown Venue';
    $age_group = $variation->get_attribute('pa_age-group') ?: $parent_product->get_attribute('pa_age-group') ?: 'N/A';
    $camp_terms = $variation->get_attribute('pa_camp-terms') ?: $parent_product->get_attribute('pa_camp-terms') ?: 'N/A';
    $course_day = $variation->get_attribute('pa_course-day') ?: $parent_product->get_attribute('pa_course-day') ?: 'N/A';
    $booking_type = $variation->get_attribute('pa_booking-type') ?: $parent_product->get_attribute('pa_booking-type') ?: 'Unknown';
    $season = $variation->get_attribute('pa_program-season') ?: $parent_product->get_attribute('pa_program-season') ?: 'N/A';

    // Extract times (camp or course)
    $times = $variation->get_attribute('pa_camp-times') ?: $variation->get_attribute('pa_course-times');
    if (!$times) {
        $times = $parent_product->get_attribute('pa_camp-times') ?: $parent_product->get_attribute('pa_course-times');
    }
    if (!$times) {
        $times = get_post_meta($variation_id, 'attribute_pa_camp-times', true) ?: get_post_meta($variation_id, 'attribute_pa_course-times', true);
    }
    if (!$times) {
        $times = get_post_meta($product_id, 'attribute_pa_camp-times', true) ?: get_post_meta($product_id, 'attribute_pa_course-times', true);
    }
    $times = $times ?: 'N/A';

    return [
        'activity_type' => $activity_type,
        'venue' => $venue,
        'age_group' => $age_group,
        'camp_terms' => $camp_terms,
        'course_day' => $course_day,
        'times' => $times,
        'season' => $season,
        'girls_only' => $girls_only,
        'product_id' => $product_id,
        'booking_type' => $booking_type,
    ];
}

/**
 * Delete placeholder roster when a real order is placed
 * 
 * @param string $event_signature The event signature to match
 */
function intersoccer_delete_placeholder_by_signature($event_signature) {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database')) {
        try {
            return intersoccer_oop_delete_placeholder_by_signature($event_signature);
        } catch (\Exception $e) {
            error_log('InterSoccer Placeholder (OOP): Failed to delete by signature ' . $event_signature . ' - ' . $e->getMessage());
            return 0;
        }
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $deleted = $wpdb->delete(
        $rosters_table,
        [
            'event_signature' => $event_signature,
            'is_placeholder' => 1,
        ],
        ['%s', '%d']
    );

    if ($deleted && defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Placeholder: Deleted placeholder for event_signature: ' . $event_signature);
    }

    return $deleted;
}

/**
 * Delete all placeholder rosters for a product
 * 
 * @param int $product_id The product ID
 */
function intersoccer_delete_placeholders_for_product($product_id) {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database')) {
        try {
            return intersoccer_oop_delete_placeholders_for_product($product_id);
        } catch (\Exception $e) {
            error_log('InterSoccer Placeholder (OOP): Failed to delete placeholders for product ' . $product_id . ' - ' . $e->getMessage());
            return 0;
        }
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $deleted = $wpdb->delete(
        $rosters_table,
        [
            'product_id' => $product_id,
            'is_placeholder' => 1,
        ],
        ['%d', '%d']
    );

    if ($deleted && defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Placeholder: Deleted ' . $deleted . ' placeholders for product ' . $product_id);
    }

    return $deleted;
}

/**
 * Sync all placeholders from existing products
 * Used for backfilling existing products or after plugin update
 */
function intersoccer_sync_all_placeholders() {
    $args = [
        'type' => 'variable',
        'status' => 'publish',
        'limit' => -1,
    ];

    $products = wc_get_products($args);
    $processed = 0;
    $total_created = 0;
    $total_updated = 0;

    foreach ($products as $product) {
        $product_id = $product->get_id();
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Placeholder: Syncing placeholders for product ' . $product_id);
        }

        $variations = $product->get_available_variations();
        foreach ($variations as $variation_data) {
            $variation_id = $variation_data['variation_id'];
            $result = intersoccer_create_placeholder_from_variation($variation_id, $product_id);
            
            if ($result === 'created') {
                $total_created++;
            } elseif ($result === 'updated') {
                $total_updated++;
            }
        }

        $processed++;
    }

    error_log("InterSoccer Placeholder: Sync completed - Processed $processed products, Created $total_created placeholders, Updated $total_updated placeholders");

    return [
        'processed' => $processed,
        'created' => $total_created,
        'updated' => $total_updated,
    ];
}

/**
 * Hook into product deletion to clean up placeholders
 */
add_action('before_delete_post', 'intersoccer_cleanup_placeholders_on_product_delete', 10, 1);

function intersoccer_cleanup_placeholders_on_product_delete($post_id) {
    $product = wc_get_product($post_id);
    
    if (!$product || !$product->is_type('variable')) {
        return;
    }

    intersoccer_delete_placeholders_for_product($post_id);
}

/**
 * AJAX handler to manually sync all placeholders
 */
add_action('wp_ajax_intersoccer_sync_placeholders', 'intersoccer_sync_placeholders_ajax');

function intersoccer_sync_placeholders_ajax() {
    check_ajax_referer('intersoccer_rebuild_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have permission to sync placeholders.', 'intersoccer-reports-rosters')]);
    }

    try {
        $result = intersoccer_sync_all_placeholders();
        
        wp_send_json_success([
            'message' => sprintf(
                __('Sync completed: Processed %d products, Created %d placeholders, Updated %d placeholders', 'intersoccer-reports-rosters'),
                $result['processed'],
                $result['created'],
                $result['updated']
            ),
            'result' => $result,
        ]);
    } catch (Exception $e) {
        error_log('InterSoccer Placeholder: Sync exception - ' . $e->getMessage());
        wp_send_json_error(['message' => __('Sync failed: ' . $e->getMessage(), 'intersoccer-reports-rosters')]);
    }
}

