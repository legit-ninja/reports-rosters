<?php
/**
 * InterSoccer Reports - Data Processing Functions
 *
 * @package InterSoccerReports
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display booking report with filters
 */
function intersoccer_display_booking_report($start_date, $end_date, $activity_type, $venue, $canton) {
    global $wpdb;

    // Build the query
    $query = "SELECT
                p.ID as order_id,
                p.post_date,
                p.post_status,
                oi.order_item_name,
                om_product_id.meta_value as product_id,
                om_activity_type.meta_value as activity_type,
                om_canton.meta_value as canton,
                t.name as venue,
                om_booking_type.meta_value as booking_type,
                om_selected_days.meta_value as selected_days,
                om_age_group.meta_value as age_group,
                om_gender.meta_value as gender,
                om_line_total.meta_value as line_total,
                om_discount_codes.meta_value as discount_codes
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_product_id ON oi.order_item_id = om_product_id.order_item_id AND om_product_id.meta_key = '_product_id'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_activity_type ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_canton ON oi.order_item_id = om_canton.order_item_id AND om_canton.meta_key = 'Canton / Region'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_venue ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
             LEFT JOIN {$wpdb->terms} t ON om_venue.meta_value = t.slug
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_booking_type ON oi.order_item_id = om_booking_type.order_item_id AND om_booking_type.meta_key = 'pa_booking-type'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_selected_days ON oi.order_item_id = om_selected_days.order_item_id AND om_selected_days.meta_key = 'Days of Week'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_age_group ON oi.order_item_id = om_age_group.order_item_id AND om_age_group.meta_key = 'pa_age-group'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_gender ON oi.order_item_id = om_gender.order_item_id AND om_gender.meta_key = 'gender'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_line_total ON oi.order_item_id = om_line_total.order_item_id AND om_line_total.meta_key = '_line_total'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_discount_codes ON oi.order_item_id = om_discount_codes.order_item_id AND om_discount_codes.meta_key = '_applied_discounts'
             WHERE p.post_type = 'shop_order'
             AND p.post_date BETWEEN %s AND %s";

    $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');

    if (!empty($activity_type)) {
        $query .= " AND om_activity_type.meta_value = %s";
        $params[] = $activity_type;
    }

    if (!empty($venue)) {
        $query .= " AND t.name = %s";
        $params[] = $venue;
    }

    if (!empty($canton)) {
        $query .= " AND om_canton.meta_value = %s";
        $params[] = $canton;
    }

    $query .= " ORDER BY p.post_date DESC";

    $results = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

    if (empty($results)) {
        echo '<p>' . __('No bookings found for the selected criteria.', 'intersoccer-reports-rosters') . '</p>';
        return;
    }

    ?>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Order ID', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Date', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Status', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Product', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Activity Type', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Canton', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Booking Type', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Gender', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Price', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Discounts', 'intersoccer-reports-rosters'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $row): ?>
            <tr>
                <td><?php echo esc_html($row['order_id']); ?></td>
                <td><?php echo esc_html(date('Y-m-d H:i', strtotime($row['post_date']))); ?></td>
                <td><?php echo esc_html($row['post_status']); ?></td>
                <td><?php echo esc_html($row['order_item_name']); ?></td>
                <td><?php echo esc_html($row['activity_type']); ?></td>
                <td><?php echo esc_html($row['canton']); ?></td>
                <td><?php echo esc_html($row['venue']); ?></td>
                <td><?php echo esc_html($row['booking_type']); ?></td>
                <td><?php echo esc_html($row['age_group']); ?></td>
                <td><?php echo esc_html($row['gender']); ?></td>
                <td><?php echo esc_html($row['line_total']); ?></td>
                <td><?php echo esc_html($row['discount_codes']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Extract year from season string (e.g., "Winter camps 2026" -> 2026)
 * @param string $season Season string
 * @return int|null Year if found, null otherwise
 */
function intersoccer_extract_year_from_season($season) {
    if (empty($season)) {
        return null;
    }
    // Match 4-digit year in season string (e.g., "Winter 2026", "Summer camps 2025", "2026 Winter")
    if (preg_match('/(\d{4})/', $season, $matches)) {
        return intval($matches[1]);
    }
    return null;
}

/**
 * Extract season type from season string (e.g., "Summer camps 2025" -> "Summer", "Winter 2026" -> "Winter")
 * @param string $season Season string
 * @return string|null Season type if found, null otherwise
 */
function intersoccer_extract_season_type($season) {
    if (empty($season)) {
        return null;
    }
    // Common season types
    $season_types = ['Summer', 'Winter', 'Autumn', 'Spring', 'Easter', 'Halloween'];
    
    foreach ($season_types as $type) {
        if (stripos($season, $type) !== false) {
            return $type;
        }
    }
    
    return null;
}

/**
 * Get final reports data
 * @param int|string $year Year to filter by
 * @param string $activity_type Activity type ('Camp' or 'Course')
 * @param string|null $season_type Optional season type filter (e.g., 'Summer', 'Winter', 'Autumn')
 * @param string|null $region Optional region/canton filter (e.g., 'Geneva', 'Zurich')
 * @return array Report data grouped by date range, canton, venue, and camp type
 */
function intersoccer_get_final_reports_data($year, $activity_type, $season_type = null, $region = null) {
    global $wpdb;
    $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
    $posts_table = $wpdb->prefix . 'posts';
    $terms_table = $wpdb->prefix . 'terms';

    if ($activity_type === 'Camp') {
        // Note: We'll group camps by their actual dates dynamically, not by hardcoded summer weeks
        // This allows all seasons to be included

        // Query rosters table directly for camps with parsed dates, then join with order data for financial info
        // This is more reliable than parsing camp_terms from WooCommerce orders
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $posts_table = $wpdb->prefix . 'posts';
        
        // First, try to get data from rosters table (has parsed dates)
        // Ensure year is an integer for SQL comparison
        $year_int = intval($year);
        
        // Build WHERE conditions dynamically based on filters
        // Exclude placeholder records (order_item_id = 0)
        $where_conditions = [
            "r.activity_type = %s",
            "r.season LIKE %s",
            "r.order_item_id > 0",
            "p.post_type = 'shop_order'",
            "p.post_status = 'wc-completed'"
        ];
        $prepare_values = [
            $activity_type,
            '%' . $year_int . '%'
        ];
        
        // Add season type filter if provided
        if (!empty($season_type)) {
            $where_conditions[] = "r.season LIKE %s";
            $prepare_values[] = $season_type . '%';
        }
        
        // Add region filter if provided
        if (!empty($region)) {
            $where_conditions[] = "r.canton_region = %s";
            $prepare_values[] = $region;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $rosters_query = $wpdb->prepare(
            "SELECT 
                r.order_item_id,
                r.canton_region AS canton,
                r.venue,
                r.camp_terms,
                r.season,
                r.booking_type,
                r.selected_days,
                r.age_group,
                r.activity_type,
                r.product_id,
                r.start_date,
                r.end_date,
                oi.order_id,
                om_line_subtotal.meta_value AS line_subtotal,
                om_line_total.meta_value AS line_total,
                p.post_date
             FROM $rosters_table r
             JOIN $order_items_table oi ON r.order_item_id = oi.order_item_id
             JOIN $posts_table p ON oi.order_id = p.ID
             LEFT JOIN $order_itemmeta_table om_line_subtotal ON oi.order_item_id = om_line_subtotal.order_item_id AND om_line_subtotal.meta_key = '_line_subtotal'
             LEFT JOIN $order_itemmeta_table om_line_total ON oi.order_item_id = om_line_total.order_item_id AND om_line_total.meta_key = '_line_total'
             WHERE $where_clause",
            ...$prepare_values
        );
        
        $rosters_from_table = $wpdb->get_results($rosters_query, ARRAY_A);
        
        // Track filtering stages
        $initial_count = count($rosters_from_table);
        $after_season_filter = 0;
        $skipped_season_mismatch = 0;
        $skipped_date_mismatch = 0;
        
        // If rosters table has data, use it; otherwise fall back to WooCommerce query
        if (!empty($rosters_from_table)) {
            $rosters = $rosters_from_table;
            // Mark that we're using rosters table data (dates are already parsed)
            // Also filter by season year if available
            $invalid_date_count = 0;
            $invalid_date_records = [];
            foreach ($rosters as &$roster) {
                // Check if dates are invalid placeholders (1970-01-01)
                $start_date = $roster['start_date'] ?? '';
                $end_date = $roster['end_date'] ?? '';
                
                if ($start_date === '1970-01-01' || empty($start_date) || $start_date === '0000-00-00') {
                    // Invalid date - mark as needing date parsing from camp_terms
                    $roster['event_start_date'] = null;
                    $roster['event_end_date'] = null;
                    $invalid_date_count++;
                    
                    // Collect data for debugging
                    $invalid_date_records[] = [
                        'order_item_id' => $roster['order_item_id'] ?? 'unknown',
                        'venue' => $roster['venue'] ?? 'unknown',
                        'season' => $roster['season'] ?? 'NULL',
                        'camp_terms' => substr($roster['camp_terms'] ?? 'NULL', 0, 50),
                        'booking_type' => $roster['booking_type'] ?? 'NULL',
                        'selected_days' => $roster['selected_days'] ?? 'NULL',
                        'product_id' => $roster['product_id'] ?? 'NULL',
                        'age_group' => $roster['age_group'] ?? 'NULL',
                    ];
                } else {
                    $roster['event_start_date'] = $start_date;
                    $roster['event_end_date'] = $end_date ?: $start_date;
                }
                $roster['from_rosters_table'] = true;
            }
            unset($roster);
        } else {
            // Fallback to original WooCommerce query
        
        // Query orders for camps (with BuyClub data optimization)
        // Note: Final Reports query WooCommerce directly, not the rosters table
        // Placeholder filtering is only needed for roster display pages, not reports
        
        // Build WHERE conditions for WooCommerce query
        $woo_where_conditions = [
            "p.post_type = 'shop_order'",
            "p.post_status = 'wc-completed'",
            "COALESCE(om_activity_type.meta_value, pm_activity_type.meta_value) = %s"
        ];
        $woo_prepare_values = [$activity_type];
        
        // Add season type filter if provided
        if (!empty($season_type)) {
            $woo_where_conditions[] = "COALESCE(om_season.meta_value, om_season_alt.meta_value) LIKE %s";
            $woo_prepare_values[] = $season_type . '%';
        }
        
        // Add region filter if provided
        if (!empty($region)) {
            $woo_where_conditions[] = "om_canton.meta_value = %s";
            $woo_prepare_values[] = $region;
        }
        
        $woo_where_clause = implode(' AND ', $woo_where_conditions);
        
        $query = $wpdb->prepare(
            "SELECT
                oi.order_item_id,
                om_canton.meta_value AS canton,
                t.name AS venue,
                COALESCE(om_camp_terms.meta_value, om_camp_terms_alt.meta_value, pm_camp_terms_variation.meta_value, pm_camp_terms_product.meta_value) AS camp_terms,
                COALESCE(om_season.meta_value, om_season_alt.meta_value) AS season,
                om_booking_type.meta_value AS booking_type,
                om_selected_days.meta_value AS selected_days,
                om_age_group.meta_value AS age_group,
                COALESCE(om_activity_type.meta_value, pm_activity_type.meta_value) AS activity_type,
                om_product_id.meta_value AS product_id,
                p.post_date,
                om_line_subtotal.meta_value AS line_subtotal,
                om_line_total.meta_value AS line_total
             FROM $posts_table p
             JOIN $order_items_table oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
             LEFT JOIN $order_itemmeta_table om_canton ON oi.order_item_id = om_canton.order_item_id AND om_canton.meta_key = 'Canton / Region'
             LEFT JOIN $order_itemmeta_table om_venue ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
             LEFT JOIN $terms_table t ON om_venue.meta_value = t.slug
             LEFT JOIN $order_itemmeta_table om_camp_terms ON oi.order_item_id = om_camp_terms.order_item_id AND om_camp_terms.meta_key = 'camp_terms'
             LEFT JOIN $order_itemmeta_table om_camp_terms_alt ON oi.order_item_id = om_camp_terms_alt.order_item_id AND om_camp_terms_alt.meta_key = 'pa_camp-terms'
             LEFT JOIN $order_itemmeta_table om_season ON oi.order_item_id = om_season.order_item_id AND om_season.meta_key = 'Season'
             LEFT JOIN $order_itemmeta_table om_season_alt ON oi.order_item_id = om_season_alt.order_item_id AND om_season_alt.meta_key = 'pa_program-season'
             LEFT JOIN $order_itemmeta_table om_booking_type ON oi.order_item_id = om_booking_type.order_item_id AND om_booking_type.meta_key = 'pa_booking-type'
             LEFT JOIN $order_itemmeta_table om_selected_days ON oi.order_item_id = om_selected_days.order_item_id AND om_selected_days.meta_key = 'Days of Week'
             LEFT JOIN $order_itemmeta_table om_age_group ON oi.order_item_id = om_age_group.order_item_id AND om_age_group.meta_key = 'pa_age-group'
             LEFT JOIN $order_itemmeta_table om_activity_type ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
             LEFT JOIN $order_itemmeta_table om_product_id ON oi.order_item_id = om_product_id.order_item_id AND om_product_id.meta_key = '_product_id'
             LEFT JOIN $order_itemmeta_table om_variation_id ON oi.order_item_id = om_variation_id.order_item_id AND om_variation_id.meta_key = '_variation_id'
             LEFT JOIN {$wpdb->postmeta} pm_activity_type ON om_product_id.meta_value = pm_activity_type.post_id AND pm_activity_type.meta_key = 'pa_activity-type'
             LEFT JOIN {$wpdb->postmeta} pm_camp_terms_variation ON om_variation_id.meta_value = pm_camp_terms_variation.post_id AND pm_camp_terms_variation.meta_key = 'attribute_pa_camp-terms' AND om_variation_id.meta_value IS NOT NULL AND om_variation_id.meta_value != ''
             LEFT JOIN {$wpdb->postmeta} pm_camp_terms_product ON om_product_id.meta_value = pm_camp_terms_product.post_id AND pm_camp_terms_product.meta_key = 'attribute_pa_camp-terms'
             LEFT JOIN $order_itemmeta_table om_line_subtotal ON oi.order_item_id = om_line_subtotal.order_item_id AND om_line_subtotal.meta_key = '_line_subtotal'
             LEFT JOIN $order_itemmeta_table om_line_total ON oi.order_item_id = om_line_total.order_item_id AND om_line_total.meta_key = '_line_total'
             WHERE $woo_where_clause",
            ...$woo_prepare_values
        );

            $rosters = $wpdb->get_results($query, ARRAY_A);
            // Initialize tracking variables for WooCommerce fallback path
            if (!isset($initial_count)) {
                $initial_count = count($rosters);
                $after_season_filter = 0;
                $skipped_season_mismatch = 0;
                $skipped_date_mismatch = 0;
            }
        }
        
        // Parse event dates and filter by event year
        $filtered_rosters = [];
        $skipped_no_dates = 0;
        $skipped_year_mismatch = 0;
        foreach ($rosters as $idx => &$roster) {
            // If we already have dates from rosters table, verify year before skipping parsing
            if (!empty($roster['from_rosters_table'])) {
                // Check if dates are invalid - if so, try multiple methods to extract dates
                if (empty($roster['event_start_date']) || $roster['event_start_date'] === '1970-01-01') {
                    // Invalid date - try multiple extraction methods
                    $order_item_id = $roster['order_item_id'] ?? null;
                    $camp_terms = $roster['camp_terms'] ?? '';
                    $season = $roster['season'] ?? '';
                    $venue = $roster['venue'] ?? 'unknown';
                    $product_id = $roster['product_id'] ?? null;
                    
                    $event_start_date = null;
                    $event_end_date = null;
                    
                    // Method 1: Try parsing from camp_terms
                    if (!empty($camp_terms) && $camp_terms !== 'N/A') {
                        list($parsed_start, $parsed_end, $event_dates) = intersoccer_parse_camp_dates_fixed($camp_terms, $season);
                        if (!empty($parsed_start) && $parsed_start !== '1970-01-01') {
                            $event_start_date = $parsed_start;
                            $event_end_date = $parsed_end ?: $parsed_start;
                        }
                    }
                    
                    // Method 2: Try fetching from order item metadata
                    if (empty($event_start_date) && $order_item_id) {
                        $om_start = wc_get_order_item_meta($order_item_id, 'Start Date', true);
                        $om_end = wc_get_order_item_meta($order_item_id, 'End Date', true);
                        if (!empty($om_start) && $om_start !== 'N/A') {
                            $parsed_start = intersoccer_parse_date_unified($om_start);
                            if (!empty($parsed_start) && $parsed_start !== '1970-01-01') {
                                $event_start_date = $parsed_start;
                                $event_end_date = !empty($om_end) && $om_end !== 'N/A' ? intersoccer_parse_date_unified($om_end) : $parsed_start;
                            }
                        }
                    }
                    
                    // Method 3: Try fetching from product/variation attributes
                    if (empty($event_start_date) && $product_id) {
                        $product = wc_get_product($product_id);
                        if ($product) {
                            // Try to get dates from product attributes or metadata
                            $product_camp_terms = $product->get_attribute('pa_camp-terms');
                            if (!empty($product_camp_terms) && $product_camp_terms !== 'N/A') {
                                list($parsed_start, $parsed_end, $event_dates) = intersoccer_parse_camp_dates_fixed($product_camp_terms, $season);
                                if (!empty($parsed_start) && $parsed_start !== '1970-01-01') {
                                    $event_start_date = $parsed_start;
                                    $event_end_date = $parsed_end ?: $parsed_start;
                                }
                            }
                        }
                    }
                    
                    // Method 4: Check if it's a single-day event based on selected_days
                    if (empty($event_start_date) && !empty($roster['selected_days'])) {
                        // For single-day events, we might need to infer the date from the season and selected day
                        // This is a fallback - we'll use the first day of the season's year as a placeholder
                        $season_year = !empty($season) ? intersoccer_extract_year_from_season($season) : null;
                        if ($season_year && $season_year == intval($year)) {
                            // Use first day of year as placeholder - better than 1970
                            $event_start_date = $season_year . '-01-01';
                            $event_end_date = $season_year . '-01-01';
                        }
                    }
                    
                    if (!empty($event_start_date) && $event_start_date !== '1970-01-01') {
                        $roster['event_start_date'] = $event_start_date;
                        $roster['event_end_date'] = $event_end_date ?: $event_start_date;
                    } else {
                        // Still no valid date - skip this record
                        $skipped_no_dates++;
                        continue;
                    }
                }
                
                // For camps, season is authoritative - match the user's query behavior (season LIKE '%year')
                $season_year = !empty($roster['season']) ? intersoccer_extract_year_from_season($roster['season']) : null;
                $requested_year_int = intval($year);
                
                // Apply season type filter if provided
                if (!empty($season_type)) {
                    $roster_season_type = !empty($roster['season']) ? intersoccer_extract_season_type($roster['season']) : null;
                    if ($roster_season_type !== $season_type) {
                        $skipped_year_mismatch++;
                        $skipped_season_mismatch++;
                        continue;
                    }
                }
                
                // Apply region filter if provided
                if (!empty($region)) {
                    $roster_canton = $roster['canton'] ?? '';
                    if ($roster_canton !== $region) {
                        $skipped_year_mismatch++;
                        continue;
                    }
                }
                
                // Primary filter: season year must match (like user's query: season LIKE '%year')
                if ($season_year !== null) {
                    if ($season_year == $requested_year_int) {
                        // Season matches - include this record
                        $after_season_filter++;
                        $filtered_rosters[] = $roster;
                        continue;
                    } else {
                        // Season doesn't match - exclude (this matches user's query behavior)
                        $skipped_year_mismatch++;
                        $skipped_season_mismatch++;
                        continue;
                    }
                } else {
                    // No season - fall back to date-based filtering
                    $event_year = !empty($roster['event_start_date']) ? intval(date('Y', strtotime($roster['event_start_date']))) : null;
                    if ($event_year !== null && $event_year == $requested_year_int) {
                        $after_season_filter++;
                        $filtered_rosters[] = $roster;
                        continue;
                    } else {
                        $skipped_year_mismatch++;
                        $skipped_date_mismatch++;
                        continue;
                    }
                }
            }
            
            $camp_terms = $roster['camp_terms'] ?? '';
            $season = $roster['season'] ?? '';
            
            // If camp_terms is still empty, try fetching from product/variation using WooCommerce functions
            if (empty($camp_terms) || $camp_terms === 'N/A') {
                $product_id = null;
                $variation_id = null;
                
                // Get product_id and variation_id from order item
                $order_item_id = $roster['order_item_id'] ?? null;
                if ($order_item_id) {
                    $product_id = wc_get_order_item_meta($order_item_id, '_product_id', true);
                    $variation_id = wc_get_order_item_meta($order_item_id, '_variation_id', true);
                }
                
                // Try to get camp_terms from variation first, then product
                if ($variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $camp_terms = $variation->get_attribute('pa_camp-terms') ?: '';
                    }
                }
                
                if (empty($camp_terms) && $product_id) {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $camp_terms = $product->get_attribute('pa_camp-terms') ?: '';
                    }
                }
                
                // Update roster entry with found camp_terms
                $roster['camp_terms'] = $camp_terms;
            }
            
            // Parse event dates from camp_terms
            if (empty($camp_terms) || $camp_terms === 'N/A') {
                $skipped_no_dates++;
                continue;
            }
            
            list($event_start_date, $event_end_date, $event_dates) = intersoccer_parse_camp_dates_fixed($camp_terms, $season);
            
            // If parsing failed, try to get dates from rosters table as fallback
            if (empty($event_start_date)) {
                $order_item_id = $roster['order_item_id'] ?? null;
                if ($order_item_id) {
                    // Try to get parsed dates from rosters table
                    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
                    $roster_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT start_date, end_date FROM $rosters_table WHERE order_item_id = %d AND start_date != '1970-01-01' AND start_date IS NOT NULL LIMIT 1",
                        $order_item_id
                    ), ARRAY_A);
                    
                    if ($roster_data && !empty($roster_data['start_date'])) {
                        $event_start_date = $roster_data['start_date'];
                        $event_end_date = $roster_data['end_date'] ?: $event_start_date;
                    } else {
                        $skipped_no_dates++;
                        continue;
                    }
                } else {
                    $skipped_no_dates++;
                    continue;
                }
            }
            
            // Apply season type filter if provided
            if (!empty($season_type)) {
                $roster_season_type = !empty($season) ? intersoccer_extract_season_type($season) : null;
                if ($roster_season_type !== $season_type) {
                    $skipped_year_mismatch++;
                    if (isset($skipped_season_mismatch)) {
                        $skipped_season_mismatch++;
                    }
                    continue;
                }
            }
            
            // Apply region filter if provided
            if (!empty($region)) {
                $roster_canton = $roster['canton'] ?? '';
                if ($roster_canton !== $region) {
                    $skipped_year_mismatch++;
                    continue;
                }
            }
            
            // Filter by season year (primary) - match user's query behavior (season LIKE '%year')
            $season_year = !empty($season) ? intersoccer_extract_year_from_season($season) : null;
            $requested_year_int = intval($year);
            
            // Primary filter: season year must match (like user's query: season LIKE '%year')
            if ($season_year !== null) {
                if ($season_year == $requested_year_int) {
                    // Season matches - include this record
                    if (isset($after_season_filter)) {
                        $after_season_filter++;
                    }
                    $roster['event_start_date'] = $event_start_date;
                    $roster['event_end_date'] = $event_end_date;
                    $filtered_rosters[] = $roster;
                    continue;
                } else {
                    // Season doesn't match - exclude (this matches user's query behavior)
                    $skipped_year_mismatch++;
                    if (isset($skipped_season_mismatch)) {
                        $skipped_season_mismatch++;
                    }
                    continue;
                }
            } else {
                // No season - fall back to date-based filtering
                $event_year = intval(date('Y', strtotime($event_start_date)));
                if ($event_year != $requested_year_int) {
                    $skipped_year_mismatch++;
                    if (isset($skipped_date_mismatch)) {
                        $skipped_date_mismatch++;
                    }
                    continue; // Skip entries that don't match the requested year
                }
            }
            
            if (isset($after_season_filter)) {
                $after_season_filter++;
            }
            
            // Add event dates to roster entry
            $roster['event_start_date'] = $event_start_date;
            $roster['event_end_date'] = $event_end_date;
            $filtered_rosters[] = $roster;
        }
        unset($roster); // Break the reference
        $rosters = $filtered_rosters;
        
        if (empty($rosters)) {
            return [];
        }

        // Determine camp type and BuyClub (using data from main query - no additional queries needed)
        $buyclub_count = 0;
        foreach ($rosters as &$roster) {
            $age_group = $roster['age_group'] ?? '';
            $roster['camp_type'] = (!empty($age_group) && (stripos($age_group, '3-5y') !== false || stripos($age_group, 'half-day') !== false)) ? 'Mini - Half Day' : 'Full Day';

            // BuyClub: orders with original price > 0 and final price = 0
            // Now using data from main query (line_subtotal and line_total already fetched)
            $line_subtotal = floatval($roster['line_subtotal'] ?? 0);
            $line_total = floatval($roster['line_total'] ?? 0);
            $roster['is_buyclub'] = $line_subtotal > 0 && $line_total === 0.0;
            if ($roster['is_buyclub']) {
                $buyclub_count++;
            }
        }
        unset($roster);

        // Group by date range (month or week), canton, venue, camp_type
        // Include all seasons, not just summer camps
        $report_data = [];
        $date_groups = [];
        $processed_order_item_ids = []; // Track unique records to detect duplicates
        $duplicate_count = 0;
        $entries_without_dates = 0;
        
        // Group camps by their actual dates - create groups dynamically
        // Note: We skip entries with invalid dates (1970-01-01) as they can't be properly grouped
        foreach ($rosters as $entry) {
            // Skip entries with invalid dates
            if (empty($entry['event_start_date']) || $entry['event_start_date'] === '1970-01-01' || $entry['event_start_date'] === '0000-00-00') {
                $entries_without_dates++;
                continue;
            }
            
            // Track unique records
            $order_item_id = $entry['order_item_id'] ?? 'unknown_' . uniqid();
            if (isset($processed_order_item_ids[$order_item_id])) {
                $duplicate_count++;
            } else {
                $processed_order_item_ids[$order_item_id] = true;
            }
            
            $event_start = strtotime($entry['event_start_date']);
            $event_end = !empty($entry['event_end_date']) ? strtotime($entry['event_end_date']) : $event_start;
            
            // Create a date range key (e.g., "2026-01-01 to 2026-01-07" for a week)
            // Group by week (Monday to Sunday) for better organization
            $start_date_obj = new DateTime($entry['event_start_date']);
            $end_date_obj = !empty($entry['event_end_date']) ? new DateTime($entry['event_end_date']) : $start_date_obj;
            
            // Format as "Month Day - Month Day, Year" (e.g., "January 1 - January 7, 2026")
            $start_formatted = $start_date_obj->format('F j');
            $end_formatted = $end_date_obj->format('F j, Y');
            $date_range_key = $start_formatted . ' - ' . $end_formatted;
            
            // If start and end are same day, just show one date
            if ($start_date_obj->format('Y-m-d') === $end_date_obj->format('Y-m-d')) {
                $date_range_key = $start_date_obj->format('F j, Y');
            }
            
            if (!isset($date_groups[$date_range_key])) {
                $date_groups[$date_range_key] = [
                    'start_date' => $entry['event_start_date'],
                    'end_date' => $entry['event_end_date'] ?? $entry['event_start_date'],
                    'entries' => []
                ];
            }
            $date_groups[$date_range_key]['entries'][] = $entry;
        }
        
        // Sort date groups by start date
        uasort($date_groups, function($a, $b) {
            return strcmp($a['start_date'], $b['start_date']);
        });
        
        // Process each date group
        foreach ($date_groups as $date_range_key => $date_group) {
            $group_entries = $date_group['entries'];
            
            // Group by canton, venue, camp_type
            $location_groups = [];
            foreach ($group_entries as $entry) {
                $canton = $entry['canton'] ?? 'Unknown';
                $venue = $entry['venue'] ?? 'Unknown';
                $camp_type = $entry['camp_type'];
                $key = "$canton|$venue|$camp_type";
                $location_groups[$key][] = $entry;
            }

            $report_data[$date_range_key] = [];
            foreach ($location_groups as $key => $group) {
                list($canton, $venue, $camp_type) = explode('|', $key);

                $full_week = 0;
                $individual_days = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0];
                $processed_count = 0;
                $skipped_buyclub = 0;
                $total_in_group = count($group);

                foreach ($group as $entry) {
                    // Include all records in Final Numbers Report (including BuyClub)
                    // BuyClub participants still count towards attendance numbers
                    $processed_count++;
                    $booking_type = strtolower($entry['booking_type'] ?? '');
                    if ($booking_type === 'full-week') {
                        $full_week++;
                    } elseif ($booking_type === 'single-days' && !empty($entry['selected_days'])) {
                        $days = array_map('trim', explode(',', $entry['selected_days']));
                        foreach ($days as $day) {
                            if (isset($individual_days[$day])) {
                                $individual_days[$day]++;
                            }
                        }
                    }
                }

                // Calculate min-max: full week bookings count for every day, single day bookings are added on top
                // For each day: total = full_week + individual_days[day]
                $daily_totals = [];
                foreach ($individual_days as $day => $count) {
                    $daily_totals[$day] = $full_week + $count;
                }
                // If no individual days but we have full week bookings, all days should have full_week count
                if ($full_week > 0 && empty($daily_totals)) {
                    $daily_totals = array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $full_week);
                }
                $daily_total_values = array_values($daily_totals);
                $min = !empty($daily_total_values) ? min($daily_total_values) : 0;
                $max = !empty($daily_total_values) ? max($daily_total_values) : 0;

                $report_data[$date_range_key][$canton][$venue][$camp_type] = [
                    'full_week' => $full_week,
                    'individual_days' => $individual_days,
                    'min_max' => "$min-$max",
                    'unique_records' => $processed_count, // Track actual number of unique records
                ];
            }
        }
        
        return $report_data;
    } else {
        // Course logic
        // Query orders for courses (with BuyClub data optimization)
        // Note: Final Reports query WooCommerce directly, not the rosters table
        // Placeholder filtering is only needed for roster display pages, not reports
        $query = $wpdb->prepare(
            "SELECT
                oi.order_item_id,
                om_canton.meta_value AS canton,
                t.name AS venue,
                om_product_id.meta_value AS product_id,
                om_booking_type.meta_value AS booking_type,
                om_discount_codes.meta_value AS discount_codes,
                om_gender.meta_value AS gender,
                om_course_day.meta_value AS course_day,
                om_start_date.meta_value AS start_date,
                om_end_date.meta_value AS end_date,
                p.post_date,
                om_line_subtotal.meta_value AS line_subtotal,
                om_line_total.meta_value AS line_total
             FROM $posts_table p
             JOIN $order_items_table oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
             LEFT JOIN $order_itemmeta_table om_canton ON oi.order_item_id = om_canton.order_item_id AND om_canton.meta_key = 'Canton / Region'
             LEFT JOIN $order_itemmeta_table om_venue ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
             LEFT JOIN $terms_table t ON om_venue.meta_value = t.slug
             LEFT JOIN $order_itemmeta_table om_product_id ON oi.order_item_id = om_product_id.order_item_id AND om_product_id.meta_key = '_product_id'
             LEFT JOIN $order_itemmeta_table om_booking_type ON oi.order_item_id = om_booking_type.order_item_id AND om_booking_type.meta_key = 'booking_type'
             LEFT JOIN $order_itemmeta_table om_discount_codes ON oi.order_item_id = om_discount_codes.order_item_id AND om_discount_codes.meta_key = '_applied_discounts'
             LEFT JOIN $order_itemmeta_table om_gender ON oi.order_item_id = om_gender.order_item_id AND om_gender.meta_key = 'gender'
             LEFT JOIN $order_itemmeta_table om_activity_type ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
             LEFT JOIN $order_itemmeta_table om_course_day ON oi.order_item_id = om_course_day.order_item_id AND om_course_day.meta_key = 'pa_course-day'
             LEFT JOIN $order_itemmeta_table om_start_date ON oi.order_item_id = om_start_date.order_item_id AND om_start_date.meta_key = 'Start Date'
             LEFT JOIN $order_itemmeta_table om_end_date ON oi.order_item_id = om_end_date.order_item_id AND om_end_date.meta_key = 'End Date'
             LEFT JOIN {$wpdb->postmeta} pm_activity_type ON om_product_id.meta_value = pm_activity_type.post_id AND pm_activity_type.meta_key = 'pa_activity-type'
             LEFT JOIN $order_itemmeta_table om_line_subtotal ON oi.order_item_id = om_line_subtotal.order_item_id AND om_line_subtotal.meta_key = '_line_subtotal'
             LEFT JOIN $order_itemmeta_table om_line_total ON oi.order_item_id = om_line_total.order_item_id AND om_line_total.meta_key = '_line_total'
             WHERE p.post_type = 'shop_order'
             AND p.post_status = 'wc-completed'
             AND COALESCE(om_activity_type.meta_value, pm_activity_type.meta_value) = %s",
            $activity_type
        );

            $rosters = $wpdb->get_results($query, ARRAY_A);
        
        // Parse event dates and filter by event year
        $filtered_rosters = [];
        $skipped_no_dates = 0;
        foreach ($rosters as $roster) {
            $start_date_str = $roster['start_date'] ?? '';
            $end_date_str = $roster['end_date'] ?? '';
            
            // Parse event dates from order metadata
            if (empty($start_date_str) || $start_date_str === 'N/A') {
                $skipped_no_dates++;
                continue;
            }
            
            $context = 'Final Reports Course (order_item_id: ' . ($roster['order_item_id'] ?? 'unknown') . ')';
            $parsed_start_date = intersoccer_parse_date_unified($start_date_str, $context . ' (start)');
            
            if (empty($parsed_start_date)) {
                $skipped_no_dates++;
                continue;
            }
            
            // Parse end date if available
            $parsed_end_date = null;
            if (!empty($end_date_str) && $end_date_str !== 'N/A') {
                $parsed_end_date = intersoccer_parse_date_unified($end_date_str, $context . ' (end)');
            }
            
            // Filter by event year - include if start or end date falls in the requested year
            $start_year = date('Y', strtotime($parsed_start_date));
            $end_year = $parsed_end_date ? date('Y', strtotime($parsed_end_date)) : $start_year;
            
            if ($start_year != $year && $end_year != $year) {
                continue; // Skip entries that don't match the requested year
            }
            
            // Add parsed dates to roster entry
            $roster['event_start_date'] = $parsed_start_date;
            $roster['event_end_date'] = $parsed_end_date ?: $parsed_start_date;
            $filtered_rosters[] = $roster;
        }
        
        $rosters = $filtered_rosters;
        if (empty($rosters)) {
            return [];
        }

        // Determine categories for courses (using data from main query - no additional queries needed)
        foreach ($rosters as &$roster) {
            // BuyClub: orders with original price > 0 and final price = 0
            // Now using data from main query (line_subtotal and line_total already fetched)
            $line_subtotal = floatval($roster['line_subtotal'] ?? 0);
            $line_total = floatval($roster['line_total'] ?? 0);
            $roster['is_buyclub'] = $line_subtotal > 0 && $line_total === 0.0;

            // Course Day: from pa_course-day attribute
            $roster['course_day'] = $roster['course_day'] ?? 'Unknown';
        }
        unset($roster);

        // Group by region, course name, course day
        $report_data = [];
        foreach ($rosters as $entry) {
            // Skip BuyClub orders entirely
            if ($entry['is_buyclub']) {
                continue;
            }

            $region = $entry['canton'] ?? 'Unknown';
            $product_id = $entry['product_id'];
            $course_name = $product_id ? get_the_title($product_id) : 'Unknown';
            $course_day = $entry['course_day'] ?? 'Unknown';

            if (!isset($report_data[$region])) {
                $report_data[$region] = [];
            }
            if (!isset($report_data[$region][$course_name])) {
                $report_data[$region][$course_name] = [];
            }
            if (!isset($report_data[$region][$course_name][$course_day])) {
                $report_data[$region][$course_name][$course_day] = [
                    'online' => 0,
                    'total' => 0,
                    'final' => 0,
                ];
            }

            $report_data[$region][$course_name][$course_day]['online']++;
            $report_data[$region][$course_name][$course_day]['total']++;
            $report_data[$region][$course_name][$course_day]['final']++;
        }

        return $report_data;
    }
}

/**
 * Calculate final reports totals
 */
function intersoccer_calculate_final_reports_totals($report_data, $activity_type) {
    if ($activity_type === 'Camp') {
        $totals = [
            'full_day' => ['full_week' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0], 'online' => 0, 'total' => 0, 'unique_records' => 0],
            'mini' => ['full_week' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0], 'online' => 0, 'total' => 0, 'unique_records' => 0],
            'all' => ['full_week' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0], 'online' => 0, 'total' => 0, 'unique_records' => 0],
        ];

        foreach ($report_data as $week => $cantons) {
            foreach ($cantons as $canton => $venues) {
                foreach ($venues as $venue => $camp_types) {
                    foreach ($camp_types as $camp_type => $data) {
                        // Get unique record count (stored during data processing)
                        $unique_records = isset($data['unique_records']) ? $data['unique_records'] : 0;
                        
                        if ($camp_type === 'Full Day') {
                            $totals['full_day']['full_week'] += $data['full_week'];
                            foreach ($data['individual_days'] as $day => $count) {
                                $totals['full_day']['individual_days'][$day] += $count;
                            }
                            $totals['full_day']['online'] = $totals['full_day']['full_week'] + array_sum($totals['full_day']['individual_days']);
                            $totals['full_day']['total'] += $data['full_week'] + array_sum($data['individual_days']);
                            $totals['full_day']['unique_records'] += $unique_records;
                        } elseif ($camp_type === 'Mini - Half Day') {
                            $totals['mini']['full_week'] += $data['full_week'];
                            foreach ($data['individual_days'] as $day => $count) {
                                $totals['mini']['individual_days'][$day] += $count;
                            }
                            $totals['mini']['online'] = $totals['mini']['full_week'] + array_sum($totals['mini']['individual_days']);
                            $totals['mini']['total'] += $data['full_week'] + array_sum($data['individual_days']);
                            $totals['mini']['unique_records'] += $unique_records;
                        }

                        // All camps total
                        $totals['all']['full_week'] += $data['full_week'];
                        foreach ($data['individual_days'] as $day => $count) {
                            $totals['all']['individual_days'][$day] += $count;
                        }
                        $totals['all']['online'] = $totals['all']['full_week'] + array_sum($totals['all']['individual_days']);
                        $totals['all']['total'] += $data['full_week'] + array_sum($data['individual_days']);
                        $totals['all']['unique_records'] += $unique_records;
                    }
                }
            }
        }

        return $totals;
    } else {
        // Course totals
        $totals = [
            'regions' => [],
            'all' => [
                'online' => 0,
                'total' => 0,
                'final' => 0,
            ]
        ];

        foreach ($report_data as $region => $courses) {
            $totals['regions'][$region] = [
                'online' => 0,
                'total' => 0,
                'final' => 0,
                'prev_year' => 0, // Placeholder for previous year data
            ];

            foreach ($courses as $course_name => $course_days) {
                foreach ($course_days as $course_day => $data) {
                    $totals['regions'][$region]['online'] += $data['online'];
                    $totals['regions'][$region]['total'] += $data['total'];
                    $totals['regions'][$region]['final'] += $data['final'];

                    $totals['all']['online'] += $data['online'];
                    $totals['all']['total'] += $data['total'];
                    $totals['all']['final'] += $data['final'];
                }
            }
        }

        return $totals;
    }
}

