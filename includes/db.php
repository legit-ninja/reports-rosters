<?php
/**
 * Database config and maintenance for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.21
 * @author Jeremy Lee
 */
defined('ABSPATH') or die('Restricted access');
/**
 * Create or upgrade the rosters table schema without dropping or populating data.
 */
function intersoccer_create_rosters_table() {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database')) {
        return intersoccer_oop_create_rosters_table();
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $rosters_table (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        order_id bigint unsigned NOT NULL,
        order_item_id bigint unsigned NOT NULL,
        variation_id bigint unsigned NOT NULL,
        player_name varchar(255) NOT NULL,
        first_name varchar(100) NOT NULL,
        last_name varchar(100) NOT NULL,
        age int DEFAULT NULL,
        gender varchar(20) DEFAULT 'N/A',
        booking_type varchar(50) NOT NULL,
        selected_days text,
        camp_terms varchar(100) DEFAULT NULL,
        venue varchar(200) DEFAULT '',
        parent_phone varchar(20) DEFAULT 'N/A',
        parent_email varchar(100) DEFAULT 'N/A',
        medical_conditions text,
        late_pickup varchar(10) DEFAULT 'No',
        late_pickup_days text,
        day_presence text,
        age_group varchar(50) DEFAULT '',
        start_date date DEFAULT '1970-01-01',
        end_date date DEFAULT '1970-01-01',
        event_dates varchar(100) DEFAULT 'N/A',
        product_name varchar(255) NOT NULL,
        activity_type varchar(50) DEFAULT '',
        shirt_size varchar(50) DEFAULT 'N/A',
        shorts_size varchar(50) DEFAULT 'N/A',
        registration_timestamp datetime DEFAULT NULL,
        course_day varchar(20) DEFAULT 'N/A',
        updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        product_id bigint unsigned NOT NULL,
        player_first_name varchar(100) NOT NULL,
        player_last_name varchar(100) NOT NULL,
        player_dob date NOT NULL DEFAULT '1970-01-01',
        player_gender varchar(10) DEFAULT '',
        player_medical text,
        player_dietary text,
        parent_first_name varchar(100) NOT NULL,
        parent_last_name varchar(100) NOT NULL,
        emergency_contact varchar(20) DEFAULT '',
        term varchar(200) DEFAULT '',
        times varchar(50) DEFAULT '',
        days_selected varchar(200) DEFAULT '',
        season varchar(50) DEFAULT '',
        canton_region varchar(100) DEFAULT '',
        city varchar(100) DEFAULT '',
        avs_number varchar(50) DEFAULT 'N/A',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        base_price decimal(10,2) DEFAULT 0.00,
        discount_amount decimal(10,2) DEFAULT 0.00,
        final_price decimal(10,2) DEFAULT 0.00,
        reimbursement decimal(10,2) DEFAULT 0.00,
        discount_codes varchar(255) DEFAULT '',
        girls_only BOOLEAN DEFAULT FALSE,
        event_signature varchar(255) DEFAULT '',
        is_placeholder TINYINT(1) DEFAULT 0,
        event_completed TINYINT(1) DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_order_item_id (order_item_id),
        KEY idx_player_name (player_name),
        KEY idx_venue (venue),
        KEY idx_activity_type (activity_type(50)),
        KEY idx_start_date (start_date),
        KEY idx_variation_id (variation_id),
        KEY idx_order_id (order_id),
        KEY idx_event_signature (event_signature(100)),
        KEY idx_is_placeholder (is_placeholder),
        KEY idx_event_completed (event_completed)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    error_log('InterSoccer: Rosters table created/verified on activation (no rebuild).');
    $describe = $wpdb->get_results("DESCRIBE $rosters_table", ARRAY_A);
    error_log('InterSoccer: Post-rebuild DESCRIBE: ' . print_r($describe, true));

    // Migrate existing tables to add event_signature column if it doesn't exist
    intersoccer_migrate_rosters_table();
}

/**
 * Migrate existing rosters table to add new columns
 */
function intersoccer_migrate_rosters_table() {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database')) {
        return intersoccer_oop_migrate_rosters_table();
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Check if event_signature column exists
    $columns = $wpdb->get_col("DESCRIBE $rosters_table", 0);
    if (!in_array('event_signature', $columns)) {
        error_log('InterSoccer: Adding event_signature column to existing rosters table');

        // Add event_signature column
        $wpdb->query("ALTER TABLE $rosters_table ADD COLUMN event_signature varchar(255) DEFAULT '' AFTER girls_only");

        // Add index for event_signature
        $wpdb->query("ALTER TABLE $rosters_table ADD KEY idx_event_signature (event_signature(100))");

        // Populate event_signature for existing records
        $existing_records = $wpdb->get_results("SELECT id, activity_type, venue, age_group, camp_terms, course_day, times, season, girls_only, product_id FROM $rosters_table WHERE event_signature = '' OR event_signature IS NULL", ARRAY_A);

        foreach ($existing_records as $record) {
            $normalized_data = intersoccer_normalize_event_data_for_signature([
                'activity_type' => $record['activity_type'],
                'venue' => $record['venue'],
                'age_group' => $record['age_group'],
                'camp_terms' => $record['camp_terms'],
                'course_day' => $record['course_day'],
                'times' => $record['times'],
                'season' => $record['season'],
                'girls_only' => $record['girls_only'],
                'product_id' => $record['product_id'],
            ]);

            $signature = intersoccer_generate_event_signature($normalized_data);

            $wpdb->update(
                $rosters_table,
                ['event_signature' => $signature],
                ['id' => $record['id']],
                ['%s'],
                ['%d']
            );
        }

        error_log('InterSoccer: Migrated ' . count($existing_records) . ' existing roster records with event signatures');
    }

    // Check if is_placeholder column exists
    if (!in_array('is_placeholder', $columns)) {
        error_log('InterSoccer: Adding is_placeholder column to existing rosters table');

        // Add is_placeholder column
        $wpdb->query("ALTER TABLE $rosters_table ADD COLUMN is_placeholder TINYINT(1) DEFAULT 0 AFTER event_signature");

        // Add index for is_placeholder
        $wpdb->query("ALTER TABLE $rosters_table ADD KEY idx_is_placeholder (is_placeholder)");

        error_log('InterSoccer: Added is_placeholder column with index');
        $columns[] = 'is_placeholder';
    }

    if (!in_array('event_completed', $columns)) {
        error_log('InterSoccer: Adding event_completed column to existing rosters table');

        $added = $wpdb->query("ALTER TABLE $rosters_table ADD COLUMN event_completed TINYINT(1) DEFAULT 0 AFTER is_placeholder");
        if ($added === false) {
            error_log('InterSoccer: Failed to add event_completed column - ' . $wpdb->last_error);
        }

        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'idx_event_completed'",
            $rosters_table
        ));

        if (empty($index_exists)) {
            $wpdb->query("ALTER TABLE $rosters_table ADD KEY idx_event_completed (event_completed)");
            error_log('InterSoccer: Added idx_event_completed index');
        }

        $columns[] = 'event_completed';
        error_log('InterSoccer: Added event_completed column with index');
    }
}

/**
 * Rebuild event signatures for all existing roster records
 * This ensures language normalization is applied to all records
 * Also normalizes stored values (venue, city, canton_region, etc.) to English
 */
function intersoccer_rebuild_event_signatures() {
    global $wpdb;
    
    error_log('InterSoccer: intersoccer_rebuild_event_signatures() function called');
    
    try {
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

        error_log('InterSoccer: Starting event signature rebuild and normalization for all records');

    // Get all records that need signature updates (including city and canton_region for tournaments)
    $records = $wpdb->get_results("SELECT id, order_item_id, activity_type, venue, age_group, camp_terms, course_day, times, season, girls_only, city, canton_region, product_id, variation_id, start_date, product_name FROM $rosters_table", ARRAY_A);

    // Store current language if using WPML
    $current_lang = '';
    $default_lang = '';
    if (function_exists('wpml_get_current_language') && function_exists('wpml_get_default_language')) {
        $current_lang = wpml_get_current_language();
        $default_lang = wpml_get_default_language();
    }

    $updated = 0;
    foreach ($records as $record) {
        $start_date = $record['start_date'] ?? '';
        
        // Normalize product_id for WPML translations BEFORE signature generation
        // This ensures the signature uses the same product_id regardless of language
        // This ensures French and English versions of the same product generate the same signature
        $product_id_to_use = $record['product_id'];
        $normalized_product_name = $record['product_name'] ?? '';
        
        if (!empty($record['product_id']) && function_exists('wpml_get_default_language')) {
            // First, ensure we have the parent product ID (in case product_id is a variation)
            $product = wc_get_product($record['product_id']);
            if ($product && method_exists($product, 'get_parent_id')) {
                $parent_id = $product->get_parent_id();
                if ($parent_id > 0) {
                    // Use parent product ID instead of variation ID
                    error_log('InterSoccer: Using parent product ID for variation - variation_id: ' . $record['product_id'] . ', parent_id: ' . $parent_id . ' (record id: ' . $record['id'] . ')');
                    $product_id_to_use = $parent_id;
                }
            }
            
            // Get the original product_id for WPML translations (normalize to default language)
            if (function_exists('apply_filters')) {
                $original_product_id = apply_filters('wpml_object_id', $product_id_to_use, 'product', true, $default_lang);
                error_log('InterSoccer: WPML lookup for product_id ' . $product_id_to_use . ' returned ' . $original_product_id . ' (default_lang: ' . $default_lang . ', record id: ' . $record['id'] . ')');
                if ($original_product_id && $original_product_id != $product_id_to_use) {
                    error_log('InterSoccer: Found WPML translation - normalizing product_id from ' . $product_id_to_use . ' to ' . $original_product_id . ' (record id: ' . $record['id'] . ')');
                    $product_id_to_use = $original_product_id;
                } else {
                    error_log('InterSoccer: Product ID ' . $product_id_to_use . ' already in default language or no translation found (record id: ' . $record['id'] . ')');
                }
            }
            
            // Switch to default language to get English product name
            if ($current_lang !== $default_lang) {
                do_action('wpml_switch_language', $default_lang);
            }
            
            $product = wc_get_product($product_id_to_use);
            if ($product) {
                $normalized_product_name = $product->get_name();
                error_log('InterSoccer: Normalized product_name from "' . $record['product_name'] . '" to "' . $normalized_product_name . '" (record id: ' . $record['id'] . ')');
            } else {
                error_log('InterSoccer: Warning - Product not found for product_id ' . $product_id_to_use . ' (record id: ' . $record['id'] . ')');
            }
            
            // Switch back to original language
            if ($current_lang !== $default_lang && !empty($current_lang)) {
                do_action('wpml_switch_language', $current_lang);
            }
        }
        
        // If date is invalid (1970-01-01, NULL, or empty), try to re-extract from order item
        if (($start_date === '1970-01-01' || empty($start_date)) && !empty($record['order_item_id'])) {
            $order_item_id = $record['order_item_id'];
            error_log('InterSoccer: Attempting to re-extract date for invalid entry (id: ' . $record['id'] . ', order_item_id: ' . $order_item_id . ')');
            
            // Get order item metadata
            $item_meta = wc_get_order_item_meta($order_item_id, '', true);
            if ($item_meta) {
                $item_meta_flat = array_combine(
                    array_keys($item_meta),
                    array_map(function ($value, $key) {
                        return is_array($value) ? $value[0] ?? implode(', ', array_map('trim', $value)) : trim($value);
                    }, array_values($item_meta), array_keys($item_meta))
                );
                
                error_log('InterSoccer: Found order item metadata keys: ' . implode(', ', array_keys($item_meta_flat)) . ' (record id: ' . $record['id'] . ')');
                
                // Try to get date from product attribute or metadata
                // Use normalized product_id for attribute lookup
                $variation_id = $record['variation_id'] ?? null;
                
                if ($record['activity_type'] === 'Tournament') {
                    // For tournaments, try to get date from product attribute
                    $variation = $variation_id ? wc_get_product($variation_id) : null;
                    $parent_product = wc_get_product($product_id_to_use);
                    
                    $tournament_date = null;
                    if ($variation) {
                        $tournament_date = $variation->get_attribute('pa_date') ?: $variation->get_attribute('Date');
                        if ($tournament_date) {
                            error_log('InterSoccer: Found tournament date in variation attributes: ' . $tournament_date . ' (record id: ' . $record['id'] . ')');
                        }
                    }
                    // If not found in original variation, try default language variation
                    if (!$tournament_date && $variation_id && function_exists('intersoccer_get_default_language_variation_id')) {
                        $default_variation_id = intersoccer_get_default_language_variation_id($variation_id);
                        if ($default_variation_id != $variation_id) {
                            $default_variation = wc_get_product($default_variation_id);
                            if ($default_variation) {
                                $tournament_date = $default_variation->get_attribute('pa_date') ?: $default_variation->get_attribute('Date');
                                if ($tournament_date) {
                                    error_log('InterSoccer: Found tournament date in default language variation ' . $default_variation_id . ' attributes: ' . $tournament_date . ' (record id: ' . $record['id'] . ')');
                                }
                            }
                        }
                    }
                    if (!$tournament_date && $parent_product) {
                        $tournament_date = $parent_product->get_attribute('pa_date') ?: $parent_product->get_attribute('Date');
                        if ($tournament_date) {
                            error_log('InterSoccer: Found tournament date in parent product attributes: ' . $tournament_date . ' (record id: ' . $record['id'] . ')');
                        }
                    }
                    if (!$tournament_date) {
                        // Try multiple possible metadata key variations
                        $possible_keys = ['Date', 'date', 'pa_date', 'Date (fr)', 'Tournament Date'];
                        foreach ($possible_keys as $key) {
                            if (isset($item_meta_flat[$key]) && !empty($item_meta_flat[$key])) {
                                $tournament_date = $item_meta_flat[$key];
                                error_log('InterSoccer: Found tournament date in order item metadata using key "' . $key . '": ' . $tournament_date . ' (record id: ' . $record['id'] . ')');
                                break;
                            }
                        }
                        // Also check raw metadata structure (might be nested)
                        if (!$tournament_date && isset($item_meta['Date'])) {
                            $raw_date = is_array($item_meta['Date']) ? ($item_meta['Date'][0] ?? null) : $item_meta['Date'];
                            if (!empty($raw_date)) {
                                $tournament_date = trim($raw_date);
                                error_log('InterSoccer: Found tournament date in raw order item metadata: ' . $tournament_date . ' (record id: ' . $record['id'] . ')');
                            }
                        }
                    }
                    
                    if ($tournament_date && function_exists('intersoccer_parse_date_unified')) {
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
                            error_log('InterSoccer: Converted slug format date to readable format: ' . $tournament_date . ' (record id: ' . $record['id'] . ')');
                        }
                        
                        // If the date doesn't have a year, try to extract it from the season
                        $year = null;
                        if (!preg_match('/\d{4}/', $tournament_date)) {
                            // No year in date string, try to extract from season
                            $season = $record['season'] ?? '';
                            // Handle French season names: "Automne 2025", "Printemps 2025", etc.
                            if (preg_match('/(\d{4})/', $season, $matches)) {
                                $year = $matches[1];
                                // Try different formats: with day name, without day name
                                if (preg_match('/^[A-Z][a-z]+\s+\d+\s+[A-Za-zàâäéèêëïîôùûüÿç]+$/', $tournament_date)) {
                                    // Format: "Dimanche 14 décembre" - add year at the end
                                    $tournament_date_with_year = $tournament_date . ' ' . $year;
                                } else {
                                    // Format: "14 décembre" - add year at the end
                                    $tournament_date_with_year = $tournament_date . ' ' . $year;
                                }
                                error_log('InterSoccer: Adding year ' . $year . ' from season "' . $season . '" to date "' . $tournament_date . '" -> "' . $tournament_date_with_year . '" (record id: ' . $record['id'] . ')');
                                $tournament_date = $tournament_date_with_year;
                            } else {
                                error_log('InterSoccer: Could not extract year from season "' . $season . '" for date "' . $tournament_date . '" (record id: ' . $record['id'] . ')');
                            }
                        }
                        
                        $parsed_date = intersoccer_parse_date_unified($tournament_date, 'rebuild event signatures (record id: ' . $record['id'] . ')');
                        if ($parsed_date) {
                            $start_date = $parsed_date;
                            $end_date = $parsed_date; // Tournaments are typically one day
                            error_log('InterSoccer: Successfully re-extracted tournament date: ' . $start_date . ' (record id: ' . $record['id'] . ')');
                        } else {
                            error_log('InterSoccer: Failed to parse tournament date "' . $tournament_date . '" (record id: ' . $record['id'] . ')');
                        }
                    } else {
                        error_log('InterSoccer: No tournament date found in product attributes or order item metadata (record id: ' . $record['id'] . ')');
                    }
                } else {
                    // For other types, try Start Date/End Date from metadata
                    $meta_start = $item_meta_flat['Start Date'] ?? null;
                    if ($meta_start && function_exists('intersoccer_parse_date_unified')) {
                        $parsed_date = intersoccer_parse_date_unified($meta_start, 'rebuild event signatures (record id: ' . $record['id'] . ')');
                        if ($parsed_date) {
                            $start_date = $parsed_date;
                            error_log('InterSoccer: Successfully re-extracted start date: ' . $start_date . ' (record id: ' . $record['id'] . ')');
                        } else {
                            error_log('InterSoccer: Failed to parse start date "' . $meta_start . '" (record id: ' . $record['id'] . ')');
                        }
                    } else {
                        error_log('InterSoccer: No Start Date found in order item metadata (record id: ' . $record['id'] . ')');
                    }
                }
            } else {
                error_log('InterSoccer: No order item metadata found for order_item_id ' . $order_item_id . ' (record id: ' . $record['id'] . ')');
            }
        }
        
        // Use normalized product_id in original_data for signature generation
        $original_data = [
            'activity_type' => $record['activity_type'],
            'venue' => $record['venue'],
            'age_group' => $record['age_group'],
            'camp_terms' => $record['camp_terms'],
            'course_day' => $record['course_day'],
            'times' => $record['times'],
            'season' => $record['season'],
            'girls_only' => $record['girls_only'],
            'city' => $record['city'] ?? '',
            'canton_region' => $record['canton_region'] ?? '',
            'product_id' => $product_id_to_use, // Use normalized product_id
            'start_date' => $start_date,
        ];
        
        $normalized_data = intersoccer_normalize_event_data_for_signature($original_data);
        
        // Add start_date back for signature generation (normalization doesn't modify dates)
        $normalized_data['start_date'] = $start_date;

        // Log signature components before generation for debugging
        error_log('InterSoccer: Signature components for record id ' . $record['id'] . ': ' . json_encode([
            'original_product_id' => $record['product_id'],
            'normalized_product_id' => $product_id_to_use,
            'start_date' => $start_date,
            'activity_type' => $normalized_data['activity_type'] ?? $record['activity_type'],
            'venue' => $normalized_data['venue'] ?? $record['venue'],
            'age_group' => $normalized_data['age_group'] ?? $record['age_group'],
            'course_day' => $normalized_data['course_day'] ?? $record['course_day'],
            'times' => $normalized_data['times'] ?? $record['times'],
            'season' => $normalized_data['season'] ?? $record['season'],
            'city' => $normalized_data['city'] ?? ($record['city'] ?? ''),
            'canton_region' => $normalized_data['canton_region'] ?? ($record['canton_region'] ?? ''),
        ]));

        $signature = intersoccer_generate_event_signature($normalized_data);
        
        error_log('InterSoccer: Generated signature ' . $signature . ' for record id ' . $record['id']);

        // Update both event_signature and normalized stored values
        $update_data = [
            'event_signature' => $signature,
            'venue' => substr((string)($normalized_data['venue'] ?? $record['venue'] ?? ''), 0, 200),
            'age_group' => substr((string)($normalized_data['age_group'] ?? $record['age_group'] ?? ''), 0, 50),
            'camp_terms' => substr((string)($normalized_data['camp_terms'] ?? $record['camp_terms'] ?? ''), 0, 100),
            'course_day' => substr((string)($normalized_data['course_day'] ?? $record['course_day'] ?? ''), 0, 20),
            'times' => substr((string)($normalized_data['times'] ?? $record['times'] ?? ''), 0, 50),
            'season' => substr((string)($normalized_data['season'] ?? $record['season'] ?? ''), 0, 50),
            'city' => substr((string)($normalized_data['city'] ?? $record['city'] ?? ''), 0, 100),
            'canton_region' => substr((string)($normalized_data['canton_region'] ?? $record['canton_region'] ?? ''), 0, 100),
            'activity_type' => substr((string)($normalized_data['activity_type'] ?? $record['activity_type'] ?? ''), 0, 50),
            'product_name' => substr((string)($normalized_product_name ?: $record['product_name'] ?? ''), 0, 255),
        ];
        
        // Update product_id to normalized value if it changed (for WPML translations)
        if ($product_id_to_use != $record['product_id']) {
            $update_data['product_id'] = $product_id_to_use;
            error_log('InterSoccer: Updating stored product_id from ' . $record['product_id'] . ' to ' . $product_id_to_use . ' (record id: ' . $record['id'] . ')');
        }
        
        // If we re-extracted the date, update it
        if ($start_date !== ($record['start_date'] ?? '')) {
            $update_data['start_date'] = $start_date;
            // For tournaments, also update end_date to match start_date
            if ($record['activity_type'] === 'Tournament') {
                $update_data['end_date'] = $start_date;
            }
        }

        // Build format array - product_id needs %d, everything else is %s
        $formats = [];
        foreach ($update_data as $key => $value) {
            if ($key === 'product_id') {
                $formats[] = '%d';
            } else {
                $formats[] = '%s';
            }
        }
        
        $wpdb->update(
            $rosters_table,
            $update_data,
            ['id' => $record['id']],
            $formats,
            ['%d']
        );

        $updated++;
    }

        error_log('InterSoccer: Rebuilt event signatures and normalized stored values for ' . $updated . ' records');
        return [
            'status' => 'success',
            'updated' => $updated,
            'message' => __('Rebuilt event signatures and normalized stored values for ' . $updated . ' records.', 'intersoccer-reports-rosters')
        ];
    } catch (Exception $e) {
        error_log('InterSoccer: Exception in rebuild_event_signatures: ' . $e->getMessage());
        error_log('InterSoccer: Exception trace: ' . $e->getTraceAsString());
        return [
            'status' => 'error',
            'updated' => 0,
            'message' => __('Rebuild failed: ' . $e->getMessage(), 'intersoccer-reports-rosters')
        ];
    } catch (Error $e) {
        error_log('InterSoccer: Fatal error in rebuild_event_signatures: ' . $e->getMessage());
        error_log('InterSoccer: Fatal error trace: ' . $e->getTraceAsString());
        return [
            'status' => 'error',
            'updated' => 0,
            'message' => __('Rebuild failed with fatal error: ' . $e->getMessage(), 'intersoccer-reports-rosters')
        ];
    }
}

/**
 * Rebuild rosters and reports table
 */
function intersoccer_rebuild_rosters_and_reports() {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database')) {
        error_log('InterSoccer: Routing roster rebuild through OOP RosterBuilder');

        try {
            $result = intersoccer_oop_rebuild_rosters([
                'clear_existing' => true,
            ]);

            if (!is_array($result)) {
                error_log('InterSoccer: OOP rebuild returned unexpected result');
                return [
                    'status' => 'error',
                    'inserted' => 0,
                    'message' => __('OOP roster rebuild failed: unexpected response.', 'intersoccer-reports-rosters')
                ];
            }

            $errors = array_filter($result['errors'] ?? []);
            $inserted = intval(($result['rosters_created'] ?? 0) + ($result['rosters_updated'] ?? 0));
            $orders = intval($result['orders_processed'] ?? 0);

            $message = empty($errors)
                ? sprintf(__('Rebuild completed via OOP engine. Orders processed: %d.', 'intersoccer-reports-rosters'), $orders)
                : sprintf(__('Rebuild completed with warnings. Orders processed: %d. Check logs for details.', 'intersoccer-reports-rosters'), $orders);

            return [
                'status' => empty($errors) ? 'success' : 'success',
                'inserted' => $inserted,
                'message' => $message,
                'stats' => $result,
                'warnings' => $errors,
            ];

        } catch (Exception $e) {
            error_log('InterSoccer: OOP roster rebuild exception - ' . $e->getMessage());
            return [
                'status' => 'error',
                'inserted' => 0,
                'message' => __('OOP roster rebuild failed: ', 'intersoccer-reports-rosters') . $e->getMessage()
            ];
        }
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    error_log('InterSoccer: Starting forced rebuild for table ' . $rosters_table);

    $wpdb->query("DROP TABLE IF EXISTS $rosters_table");
    intersoccer_create_rosters_table();
    error_log('InterSoccer: Table ' . $rosters_table . ' dropped and recreated');

    $wpdb->query('START TRANSACTION');

    $orders = wc_get_orders(['limit' => -1, 'status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold']]);
    error_log('InterSoccer: Found ' . count($orders) . ' orders for rebuild');

    $inserted_items = 0;

    if (empty($orders)) {
        error_log('InterSoccer: No orders retrieved for rebuild');
        $wpdb->query('ROLLBACK');
        return ['status' => 'error', 'inserted' => 0];
    }

    foreach ($orders as $order) {
        wp_cache_flush();
        $order_id = $order->get_id();
        error_log('InterSoccer: Processing order ' . $order_id . ' for rebuild');

        foreach ($order->get_items() as $item_id => $item) {
            $result = intersoccer_update_roster_entry($order_id, $item_id);
            if ($result) {
                $inserted_items++;
            }
        }
    }

    $wpdb->query('COMMIT');
    error_log('InterSoccer: Rebuild completed. Inserted: ' . $inserted_items);
    return [
        'status' => 'success',
        'inserted' => $inserted_items,
        'message' => __('Rebuild completed. Inserted ' . $inserted_items . ' rosters.', 'intersoccer-reports-rosters')
    ];
}

function intersoccer_reconcile_rosters() {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database')) {
        error_log('InterSoccer: Routing roster reconciliation through OOP RosterBuilder');

        try {
            $result = intersoccer_oop_reconcile_rosters([
                'delete_obsolete' => true,
            ]);

            $message = sprintf(
                __('Reconciled rosters via OOP engine: Synced %1$d entries, deleted %2$d obsolete ones.', 'intersoccer-reports-rosters'),
                intval($result['synced'] ?? 0),
                intval($result['deleted'] ?? 0)
            );

            if (!empty($result['errors'])) {
                $message .= ' ' . __('Some items reported errors. Check the logs for details.', 'intersoccer-reports-rosters');
            }

            return [
                'status' => !empty($result['errors']) ? 'warning' : 'success',
                'synced' => intval($result['synced'] ?? 0),
                'deleted' => intval($result['deleted'] ?? 0),
                'errors' => intval($result['errors'] ?? 0),
                'warnings' => $result['error_messages'] ?? [],
                'message' => $message,
            ];

        } catch (Exception $e) {
            error_log('InterSoccer: OOP roster reconciliation exception - ' . $e->getMessage());
            return [
                'status' => 'error',
                'synced' => 0,
                'deleted' => 0,
                'errors' => 1,
                'warnings' => [],
                'message' => __('OOP roster reconciliation failed: ', 'intersoccer-reports-rosters') . $e->getMessage(),
            ];
        }
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    error_log('InterSoccer: Starting roster reconciliation');

    $orders = wc_get_orders([
        'limit' => -1,
        'status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
    ]);
    error_log('InterSoccer: Found ' . count($orders) . ' orders for reconciliation');

    $synced = 0;
    $deleted = 0;

    try {
        $existing_items = $wpdb->get_col("SELECT order_item_id FROM $rosters_table");

        foreach ($orders as $order) {
            $order_id = $order->get_id();
            error_log('InterSoccer: Reconciling order ' . $order_id);

            foreach ($order->get_items() as $item_id => $item) {
                $result = intersoccer_update_roster_entry($order_id, $item_id);
                if ($result) {
                    $synced++;
                    $key = array_search($item_id, $existing_items);
                    if ($key !== false) {
                        unset($existing_items[$key]);
                    }
                }
            }
        }

        foreach ($existing_items as $obsolete_item_id) {
            $wpdb->delete($rosters_table, ['order_item_id' => $obsolete_item_id]);
            $deleted++;
            error_log('InterSoccer: Deleted obsolete roster entry for order_item_id ' . $obsolete_item_id);
        }

        error_log('InterSoccer: Reconciliation completed. Synced: ' . $synced . ', Deleted: ' . $deleted);
        return [
            'status' => 'success',
            'synced' => $synced,
            'deleted' => $deleted,
            'message' => __('Reconciled rosters: Synced ' . $synced . ' entries, deleted ' . $deleted . ' obsolete ones.', 'intersoccer-reports-rosters')
        ];
    } catch (Exception $e) {
        error_log('InterSoccer: Reconciliation failed: ' . $e->getMessage());
        return [
            'status' => 'error',
            'message' => __('Reconciliation failed: ' . $e->getMessage(), 'intersoccer-reports-rosters')
        ];
    }
}

if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('ajax')) {
    if (function_exists('intersoccer_oop_register_roster_ajax_handlers')) {
        intersoccer_oop_register_roster_ajax_handlers();
    }
} else {
    add_action('wp_ajax_intersoccer_rebuild_rosters_and_reports', 'intersoccer_rebuild_rosters_and_reports_ajax');
    add_action('wp_ajax_intersoccer_reconcile_rosters', 'intersoccer_reconcile_rosters_ajax');

    if (!function_exists('intersoccer_rebuild_rosters_and_reports_ajax')) {
        function intersoccer_rebuild_rosters_and_reports_ajax() {
            ob_start();

            $nonce_valid = false;
            if (isset($_POST['nonce'])) {
                $nonce_valid = wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'intersoccer_rebuild_nonce');
            } elseif (isset($_POST['intersoccer_rebuild_nonce_field'])) {
                $nonce_valid = wp_verify_nonce(sanitize_text_field($_POST['intersoccer_rebuild_nonce_field']), 'intersoccer_rebuild_nonce');
            }

            if (!$nonce_valid) {
                ob_clean();
                wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'intersoccer-reports-rosters')]);
            }

            if (!current_user_can('manage_options')) {
                ob_clean();
                wp_send_json_error(['message' => __('You do not have permission to rebuild rosters.', 'intersoccer-reports-rosters')]);
            }
            error_log('InterSoccer: AJAX rebuild request received with data: ' . print_r($_POST, true));

            try {
                $result = intersoccer_rebuild_rosters_and_reports();
                ob_clean();
                if ($result['status'] === 'success') {
                    wp_send_json_success([
                        'inserted' => $result['inserted'],
                        'message' => __('Rebuild completed. Inserted ' . $result['inserted'] . ' rosters.', 'intersoccer-reports-rosters')
                    ]);
                } else {
                    wp_send_json_error(['message' => __('Rebuild failed: ' . $result['message'], 'intersoccer-reports-rosters')]);
                }
            } catch (Exception $e) {
                error_log('InterSoccer: Rebuild exception: ' . $e->getMessage());
                ob_clean();
                wp_send_json_error(['message' => __('Rebuild failed with exception: ' . $e->getMessage(), 'intersoccer-reports-rosters')]);
            }
        }
    }

    if (!function_exists('intersoccer_reconcile_rosters_ajax')) {
        function intersoccer_reconcile_rosters_ajax() {
            ob_start();

            $nonce_valid = false;
            if (isset($_POST['nonce'])) {
                $nonce_valid = wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'intersoccer_reports_rosters_nonce');
            } elseif (isset($_POST['intersoccer_rebuild_nonce_field'])) {
                $nonce_valid = wp_verify_nonce(sanitize_text_field($_POST['intersoccer_rebuild_nonce_field']), 'intersoccer_rebuild_nonce');
            }

            if (!$nonce_valid) {
                ob_clean();
                wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'intersoccer-reports-rosters')]);
            }

            if (!current_user_can('manage_options')) {
                ob_clean();
                wp_send_json_error(['message' => __('You do not have permission to reconcile rosters.', 'intersoccer-reports-rosters')]);
            }
            error_log('InterSoccer: AJAX reconcile request received with data: ' . print_r($_POST, true));

            try {
                $result = intersoccer_reconcile_rosters();
                ob_clean();
                if ($result['status'] === 'success') {
                    wp_send_json_success([
                        'message' => $result['message'],
                        'synced' => $result['synced'],
                        'deleted' => $result['deleted']
                    ]);
                } else {
                    wp_send_json_error(['message' => $result['message']]);
                }
            } catch (Exception $e) {
                error_log('InterSoccer: Reconcile exception: ' . $e->getMessage());
                ob_clean();
                wp_send_json_error(['message' => __('Reconcile failed: ' . $e->getMessage(), 'intersoccer-reports-rosters')]);
            }
        }
    }
}

/**
 * Helper to prepare roster entry data (extracted from rebuild for reuse).
 * Returns array or false if invalid.
 */
function intersoccer_prepare_roster_entry($order, $item, $order_item_id, $order_id, $order_date, $girls_only_variation_ids) {
    $product = $item->get_product();
    if (!$product) {
        return false;
    }

    $product_id = $item->get_product_id();
    $variation_id = $item->get_variation_id();
    $variation = wc_get_product($variation_id) ? wc_get_product($variation_id) : $product;
    $parent_product = wc_get_product($product_id);

    $raw_order_item_meta = wc_get_order_item_meta($order_item_id, '', true);
    error_log("InterSoccer: Raw order item meta for order $order_id, item $order_item_id: " . print_r($raw_order_item_meta, true));

    // Activity type logic (same as rebuild)
    $activity_type = $raw_order_item_meta['Activity Type'][0] ?? null;
    error_log("InterSoccer: Raw Activity Type from meta for order $order_id, item $order_item_id: " . print_r($activity_type, true));
    if ($activity_type) {
        $activity_type = trim(strtolower(html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $activity_types = array_map('trim', explode(',', $activity_type));
        error_log("InterSoccer: Processed activity_types from meta for order $order_id, item $order_item_id: " . print_r($activity_types, true));
        if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
            $activity_type = 'Girls Only';
        } else {
            $activity_type = implode(', ', array_map('ucfirst', $activity_types));
        }
    } else {
        $variation_activity_type = $variation ? $variation->get_attribute('pa_activity-type') : ($parent_product ? $parent_product->get_attribute('pa_activity-type') : null);
        if ($variation_activity_type) {
            if (is_array($variation_activity_type)) {
                $variation_activity_type = implode(', ', array_map('trim', $variation_activity_type));
            }
            $activity_type = trim(strtolower(html_entity_decode($variation_activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $activity_types = array_map('trim', explode(',', $activity_type));
            if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
                $activity_type = 'Girls Only';
            } elseif (!empty($activity_types[0])) {
                $activity_type = ucfirst($activity_types[0]);
            } else {
                if ($variation && $variation->get_attribute('pa_course-day') || ($parent_product && $parent_product->get_attribute('pa_course-day'))) {
                    $activity_type = 'Course';
                }
            }
        }
    }

    $order_item_meta = array_combine(
        array_keys($raw_order_item_meta),
        array_map(function ($value, $key) {
            if ($key !== 'Activity Type' && is_array($value)) {
                return $value[0] ?? implode(', ', array_map('trim', $value));
            }
            return is_array($value) ? $value[0] ?? implode(', ', array_map('trim', $value)) : trim($value);
        }, array_values($raw_order_item_meta), array_keys($raw_order_item_meta))
    );

    $assigned_attendees = $order_item_meta['Assigned Attendees'] ?? $order_item_meta['Assigned Attendee'] ?? 'Unknown Attendee';
    $attendees = is_array($assigned_attendees) ? $assigned_attendees : [$assigned_attendees];

    foreach ($attendees as $assigned_attendee) {
        // Strip leading numeric prefix + space
        $assigned_attendee = preg_replace('/^\d+\s*/', '', trim($assigned_attendee));
        $player_name_parts = explode(' ', $assigned_attendee, 2);
        $first_name = !empty($player_name_parts[0]) ? $player_name_parts[0] : 'Unknown';
        $last_name = !empty($player_name_parts[1]) ? $player_name_parts[1] : 'Unknown';

        // Normalize for matching (lowercase, trim, remove non-alpha, translit accents)
        $first_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $first_name) ?? $first_name)));
        $last_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $last_name) ?? $last_name)));

        $user_id = $order->get_user_id();
        $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
        $player_index = $order_item_meta['assigned_player'] ?? false;
        $age = isset($order_item_meta['Player Age']) ? (int)$order_item_meta['Player Age'] : null;
        $gender = $order_item_meta['Player Gender'] ?? 'N/A';
        $medical_conditions = $order_item_meta['Medical Conditions'] ?? '';
        $avs_number = 'N/A'; // Default
        if ($player_index !== false && is_array($players) && isset($players[$player_index])) {
            $player = $players[$player_index];
            $first_name = $player['first_name'] ?? $first_name;
            $last_name = $player['last_name'] ?? $last_name;
            $dob = $player['dob'] ?? null;
            $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
            $gender = $player['gender'] ?? $gender;
            $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
            $avs_number = $player['avs_number'] ?? 'N/A';
        } else {
            $matched = false;
            foreach ($players as $player) {
                $meta_first_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['first_name'] ?? '') ?? '')));
                $meta_last_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['last_name'] ?? '') ?? '')));
                if ($meta_first_norm === $first_name_norm && $meta_last_norm === $last_name_norm) {
                    $dob = $player['dob'] ?? null;
                    $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                    $gender = $player['gender'] ?? $gender;
                    $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                    $avs_number = $player['avs_number'] ?? 'N/A';
                    $matched = true;
                    break;
                }
            }
            // Fallback to first-name only if no exact match
            if (!$matched) {
                foreach ($players as $player) {
                    $meta_first_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['first_name'] ?? '') ?? '')));
                    if ($meta_first_norm === $first_name_norm) {
                        $dob = $player['dob'] ?? null;
                        $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                        $gender = $player['gender'] ?? $gender;
                        $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                        $avs_number = $player['avs_number'] ?? 'N/A';
                        error_log("InterSoccer: Fallback first-name match for attendee $assigned_attendee in rebuild order $order_id item $order_item_id");
                        break;
                    }
                }
            }
        }

        // Extract event details, prioritizing camp_terms for Camps
        $booking_type = $order_item_meta['pa_booking-type'] ?? ($variation ? $variation->get_attribute('pa_booking-type') : ($parent_product ? $parent_product->get_attribute('pa_booking-type') : 'Unknown'));
        $selected_days = $order_item_meta['Days Selected'] ?? 'N/A';
        $camp_terms = $order_item_meta['pa_camp-terms'] ?? ($variation ? $variation->get_attribute('pa_camp-terms') : ($parent_product ? $parent_product->get_attribute('pa_camp-terms') : 'N/A'));
        $venue = $order_item_meta['pa_intersoccer-venues'] ?? ($variation ? $variation->get_attribute('pa_intersoccer-venues') : ($parent_product ? $parent_product->get_attribute('pa_intersoccer-venues') : 'Unknown Venue'));
        if ($venue === 'Unknown Venue') {
            $meta = wc_get_order_item_meta($order_item_id, 'pa_intersoccer-venues', true);
            if ($meta) {
                $venue = $meta;
                error_log("InterSoccer: Fallback venue extracted for order $order_id, item $order_item_id: $venue");
            }
        }
        $age_group = $order_item_meta['pa_age-group'] ?? ($variation ? $variation->get_attribute('pa_age-group') : ($parent_product ? $parent_product->get_attribute('pa_age-group') : 'N/A'));
        // Extract course_day for Courses, tournament_day for Tournaments
        if ($activity_type === 'Course') {
            $course_day = $order_item_meta['pa_course-day'] ?? ($variation ? $variation->get_attribute('pa_course-day') : ($parent_product ? $parent_product->get_attribute('pa_course-day') : 'N/A'));
        } elseif ($activity_type === 'Tournament') {
            // For Tournaments, extract Tournament Day from metadata
            $course_day = $order_item_meta['Tournament Day'] ?? $order_item_meta['pa_tournament-day'] ?? ($variation ? $variation->get_attribute('pa_tournament-day') : ($parent_product ? $parent_product->get_attribute('pa_tournament-day') : 'N/A'));
        } else {
            $course_day = 'N/A';
        }

        // Extract times with postmeta fallback (supports Course, Camp, and Tournament)
        $times = $order_item_meta['Course Times'] ?? $order_item_meta['Camp Times'] ?? $order_item_meta['Tournament Time'] ?? null;
        if (!$times) {
            $times = $variation ? ($variation->get_attribute('pa_course-times') ?? $variation->get_attribute('pa_camp-times') ?? $variation->get_attribute('pa_tournament-time')) : null;
            if (!$times && $parent_product) {
                $times = $parent_product->get_attribute('pa_course-times') ?? $parent_product->get_attribute('pa_camp-times') ?? $parent_product->get_attribute('pa_tournament-time');
            }
            if (!$times && $variation_id) {
                $times = get_post_meta($variation_id, 'attribute_pa_camp-times', true) ?: get_post_meta($variation_id, 'attribute_pa_course-times', true) ?: get_post_meta($variation_id, 'attribute_pa_tournament-time', true);
            }
            if (!$times && $product_id) {
                $times = get_post_meta($product_id, 'attribute_pa_camp-times', true) ?: get_post_meta($product_id, 'attribute_pa_course-times', true) ?: get_post_meta($product_id, 'attribute_pa_tournament-time', true);
            }
            $times = $times ?: 'N/A';
        }
        error_log("InterSoccer: Times source for order $order_id, item $order_item_id: Meta - " . ($order_item_meta['Course Times'] ?? $order_item_meta['Camp Times'] ?? $order_item_meta['Tournament Time'] ?? 'N/A') . ', Variation attr - ' . ($variation ? ($variation->get_attribute('pa_course-times') ?? $variation->get_attribute('pa_camp-times') ?? $variation->get_attribute('pa_tournament-time') ?? 'N/A') : 'N/A') . ', Parent attr - ' . ($parent_product ? ($parent_product->get_attribute('pa_course-times') ?? $parent_product->get_attribute('pa_camp-times') ?? $parent_product->get_attribute('pa_tournament-time') ?? 'N/A') : 'N/A') . ', Postmeta - ' . (get_post_meta($variation_id ?: $product_id, 'attribute_pa_camp-times', true) ?: get_post_meta($variation_id ?: $product_id, 'attribute_pa_course-times', true) ?: get_post_meta($variation_id ?: $product_id, 'attribute_pa_tournament-time', true) ?: 'N/A') . ', Final: ' . $times);

        $start_date = null;
        $end_date = null;
        $event_dates = 'N/A';
        $season = $raw_order_item_meta['Season'][0] ?? 'N/A';
        
        if ($activity_type === 'Camp' && $camp_terms !== 'N/A') {
            if (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\w+)-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                $start_month = $matches[2];
                $start_day = $matches[3];
                $end_month = $matches[4];
                $end_day = $matches[5];
                $year = $season_year ?: (date('Y', strtotime($order_date)) ?: date('Y'));
                $start_date_obj = DateTime::createFromFormat('F j Y', "$start_month $start_day $year");
                $end_date_obj = DateTime::createFromFormat('F j Y', "$end_month $end_day $year");
                if ($start_date_obj && $end_date_obj) {
                    $start_date = $start_date_obj->format('Y-m-d');
                    $end_date = $end_date_obj->format('Y-m-d');
                    $event_dates = "$start_date to $end_date";
                } else {
                    error_log("InterSoccer: Date parsing failed for camp_terms $camp_terms (start_month: $start_month, start_day: $start_day, end_month: $end_month, end_day: $end_day, year: $year) for order $order_id, item $order_item_id");
                }
            } elseif (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                $month = $matches[2];
                $start_day = $matches[3];
                $end_day = $matches[4];
                $year = $season_year ?: (date('Y', strtotime($order_date)) ?: date('Y'));
                $start_date_obj = DateTime::createFromFormat('F j Y', "$month $start_day $year");
                $end_date_obj = DateTime::createFromFormat('F j Y', "$month $end_day $year");
                if ($start_date_obj && $end_date_obj) {
                    $start_date = $start_date_obj->format('Y-m-d');
                    $end_date = $end_date_obj->format('Y-m-d');
                    $event_dates = "$start_date to $end_date";
                } else {
                    error_log("InterSoccer: Date parsing failed for camp_terms $camp_terms (month: $month, start_day: $start_day, end_day: $end_day, year: $year) for order $order_id, item $order_item_id");
                }
            } else {
                error_log("InterSoccer: Regex failed to match camp_terms $camp_terms for order $order_id, item $order_item_id");
            }
        } elseif ($activity_type === 'Tournament') {
            // For tournaments, get date from product attribute pa_date or Date
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
                            error_log("InterSoccer: Found tournament date in default language variation $default_variation_id attributes: $tournament_date (order $order_id, item $order_item_id)");
                        }
                    }
                }
            }
            if (!$tournament_date && $parent_product) {
                $tournament_date = $parent_product->get_attribute('pa_date') ?: $parent_product->get_attribute('Date');
            }
            // Also check order item metadata as fallback - try multiple key variations
            if (!$tournament_date) {
                // First check the flattened order_item_meta array
                $possible_keys = ['Date', 'date', 'pa_date', 'Date (fr)', 'Tournament Date'];
                foreach ($possible_keys as $key) {
                    if (isset($order_item_meta[$key]) && !empty($order_item_meta[$key])) {
                        $tournament_date = is_array($order_item_meta[$key]) ? ($order_item_meta[$key][0] ?? null) : $order_item_meta[$key];
                        if (!empty($tournament_date)) {
                            $tournament_date = trim($tournament_date);
                            error_log("InterSoccer: Found tournament date in order_item_meta['$key']: $tournament_date (order $order_id, item $order_item_id)");
                            break;
                        }
                    }
                }
                
                // Also check raw_order_item_meta (before flattening) - might have different structure
                if (!$tournament_date && isset($raw_order_item_meta)) {
                    foreach ($possible_keys as $key) {
                        if (isset($raw_order_item_meta[$key])) {
                            $raw_value = $raw_order_item_meta[$key];
                            if (is_array($raw_value)) {
                                $tournament_date = !empty($raw_value[0]) ? trim($raw_value[0]) : null;
                            } else {
                                $tournament_date = !empty($raw_value) ? trim($raw_value) : null;
                            }
                            if (!empty($tournament_date)) {
                                error_log("InterSoccer: Found tournament date in raw_order_item_meta['$key']: $tournament_date (order $order_id, item $order_item_id)");
                                break;
                            }
                        }
                    }
                }
                
                // If still not found, try querying database directly - this is the most reliable method
                if (!$tournament_date) {
                    global $wpdb;
                    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
                    // First, get all Date-related metadata to see what's actually stored
                    $all_date_meta = $wpdb->get_results($wpdb->prepare(
                        "SELECT meta_key, meta_value FROM $order_itemmeta_table 
                         WHERE order_item_id = %d AND (meta_key LIKE '%%Date%%' OR meta_key LIKE '%%date%%')
                         AND meta_value IS NOT NULL AND meta_value != ''",
                        $order_item_id
                    ), ARRAY_A);
                    if ($all_date_meta) {
                        error_log("InterSoccer: Found Date-related metadata in database for order_item_id $order_item_id: " . json_encode($all_date_meta));
                        // Try each found key - prioritize 'Date' key
                        $date_keys = [];
                        foreach ($all_date_meta as $meta_row) {
                            if (strtolower($meta_row['meta_key']) === 'date') {
                                array_unshift($date_keys, $meta_row);
                            } else {
                                $date_keys[] = $meta_row;
                            }
                        }
                        foreach ($date_keys as $meta_row) {
                            $tournament_date = trim($meta_row['meta_value']);
                            if (!empty($tournament_date)) {
                                error_log("InterSoccer: Using tournament date from database key '{$meta_row['meta_key']}': $tournament_date (order $order_id, item $order_item_id)");
                                break;
                            }
                        }
                    } else {
                        error_log("InterSoccer: No Date-related metadata found in database for order_item_id $order_item_id (order $order_id, item $order_item_id)");
                    }
                }
            }
            
            if ($tournament_date) {
                error_log("InterSoccer: Found tournament date attribute for order $order_id, item $order_item_id: " . $tournament_date);
                
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
                    error_log("InterSoccer: Converted slug format date to readable format: $tournament_date (order $order_id, item $order_item_id)");
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
                        error_log("InterSoccer: Adding year $year from season \"$season\" to date \"$tournament_date\" (order $order_id, item $order_item_id)");
                    }
                }
                
                $context = "order $order_id, item $order_item_id (tournament date)";
                $parsed_date = intersoccer_parse_date_unified($tournament_date, $context);
                
                if ($parsed_date) {
                    // Tournaments are typically one day, so use same date for start and end
                    $start_date = $parsed_date;
                    $end_date = $parsed_date;
                    $event_dates = $start_date;
                    error_log("InterSoccer: Parsed tournament date: $start_date");
                } else {
                    error_log("InterSoccer: Failed to parse tournament date '$tournament_date' for order $order_id, item $order_item_id");
                    $start_date = '1970-01-01';
                    $end_date = '1970-01-01';
                    $event_dates = 'N/A';
                }
            } else {
                // Fallback to Start Date/End Date from order item metadata if available
                if (!empty($order_item_meta['Start Date']) && !empty($order_item_meta['End Date'])) {
                    error_log("InterSoccer: Tournament date attribute not found, trying Start Date/End Date from metadata for order $order_id, item $order_item_id");
                    $context = "order $order_id, item $order_item_id";
                    $start_date = intersoccer_parse_date_unified($order_item_meta['Start Date'], $context . ' (start)');
                    $end_date = intersoccer_parse_date_unified($order_item_meta['End Date'], $context . ' (end)');
                    
                    if ($start_date && $end_date) {
                        $event_dates = "$start_date to $end_date";
                    } else {
                        error_log("InterSoccer: Date parsing failed for order $order_id, item $order_item_id. Using default dates.");
                        $start_date = '1970-01-01';
                        $end_date = '1970-01-01';
                        $event_dates = 'N/A';
                    }
                } else {
                    error_log("InterSoccer: No tournament date found (checked pa_date, Date attribute, and Start Date/End Date metadata) for order $order_id, item $order_item_id. Using defaults.");
                    $start_date = '1970-01-01';
                    $end_date = '1970-01-01';
                    $event_dates = 'N/A';
                }
            }
        } elseif ($activity_type === 'Course' && !empty($order_item_meta['Start Date']) && !empty($order_item_meta['End Date'])) {
            // Log raw date values for debugging
            error_log("InterSoccer: Raw Start Date for order $order_id, item $order_item_id: " . print_r($order_item_meta['Start Date'], true));
            error_log("InterSoccer: Raw End Date for order $order_id, item $order_item_id: " . print_r($order_item_meta['End Date'], true));

            // Use unified date parser
            $context = "order $order_id, item $order_item_id";
            $start_date = intersoccer_parse_date_unified($order_item_meta['Start Date'], $context . ' (start)');
            $end_date = intersoccer_parse_date_unified($order_item_meta['End Date'], $context . ' (end)');

            // Fallback if parsing fails
            if (!$start_date || !$end_date) {
                error_log("InterSoccer: Date parsing failed for order $order_id, item $order_item_id. Using default dates.");
                $start_date = '1970-01-01';
                $end_date = '1970-01-01';
                $event_dates = 'N/A';
            } else {
                $event_dates = "$start_date to $end_date";
            }
        } else {
            if ($activity_type === 'Course' || $activity_type === 'Tournament') {
                error_log("InterSoccer: Missing or invalid Start Date/End Date for $activity_type in order $order_id, item $order_item_id. Using defaults.");
            }
            $start_date = '1970-01-01';
            $end_date = '1970-01-01';
            $event_dates = 'N/A';
        }

        $late_pickup = (!empty($order_item_meta['Late Pickup Type'])) ? 'Yes' : 'No';
        $late_pickup_days = $order_item_meta['Late Pickup Days'] ?? '';
        
        // Get product name and normalize to English if WPML is active
        $product_name = $product->get_name();
        if (function_exists('wpml_get_default_language') && function_exists('wpml_get_current_language')) {
            $current_lang = wpml_get_current_language();
            $default_lang = wpml_get_default_language();
            
            if ($current_lang !== $default_lang) {
                // Switch to default language to get English product name
                do_action('wpml_switch_language', $default_lang);
                $product_english = wc_get_product($product_id);
                if ($product_english) {
                    $product_name = $product_english->get_name();
                }
                // Switch back to original language
                do_action('wpml_switch_language', $current_lang);
            }
        }

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

        $shirt_size = 'N/A';
        $shorts_size = 'N/A';
        if ($activity_type === 'Girls Only' || in_array($variation_id, $girls_only_variation_ids)) {
            $possible_shirt_keys = ['pa_what-size-t-shirt-does-your', 'pa_tshirt-size', 'pa_what-size-t-shirt-does-your-child-wear', 'Shirt Size', 'T-shirt Size'];
            $possible_shorts_keys = ['pa_what-size-shorts-does-your-c', 'pa_what-size-shorts-does-your-child-wear', 'Shorts Size', 'Shorts'];
            foreach ($possible_shirt_keys as $key) {
                if (isset($order_item_meta[$key]) && $order_item_meta[$key] !== '') {
                    $shirt_size = substr(trim($order_item_meta[$key]), 0, 50);
                    break;
                }
            }
            foreach ($possible_shorts_keys as $key) {
                if (isset($order_item_meta[$key]) && $order_item_meta[$key] !== '') {
                    $shorts_size = substr(trim($order_item_meta[$key]), 0, 50);
                    break;
                }
            }
            if ($shirt_size === 'N/A' || $shorts_size === 'N/A') {
                $meta = wc_get_order_item_meta($order_item_id, '', true);
                foreach ($possible_shirt_keys as $key) {
                    if (isset($meta[$key][0]) && $meta[$key][0] !== '') {
                        $shirt_size = substr(trim($meta[$key][0]), 0, 50);
                        break;
                    }
                }
                foreach ($possible_shorts_keys as $key) {
                    if (isset($meta[$key][0]) && $meta[$key][0] !== '') {
                        $shorts_size = substr(trim($meta[$key][0]), 0, 50);
                        break;
                    }
                }
                error_log("InterSoccer: Fallback for order $order_id, item $order_item_id - shirt_size: $shirt_size, shorts_size: $shorts_size");
            }
        }

        // Normalize event data to English for consistent storage
        // This ensures all roster entries are stored in English regardless of order language
        $event_data_to_normalize = [
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
        
        $normalized_event_data = intersoccer_normalize_event_data_for_signature($event_data_to_normalize);
        
        // Add start_date back to normalized data for signature generation (normalization doesn't modify dates)
        $normalized_event_data['start_date'] = $start_date;
        
        // Use normalized values for storage
        $normalized_venue = $normalized_event_data['venue'] ?? $venue;
        $normalized_age_group = $normalized_event_data['age_group'] ?? $age_group;
        $normalized_camp_terms = $normalized_event_data['camp_terms'] ?? $camp_terms;
        $normalized_course_day = $normalized_event_data['course_day'] ?? $course_day;
        $normalized_times = $normalized_event_data['times'] ?? $times;
        $normalized_season = $normalized_event_data['season'] ?? $season;
        $normalized_city = $normalized_event_data['city'] ?? $city;
        $normalized_canton_region = $normalized_event_data['canton_region'] ?? $canton_region;
        $normalized_activity_type = $normalized_event_data['activity_type'] ?? ucfirst($activity_type ?: 'Event');

        // Prepare roster_entry for insertion
        $roster_entry = [
            'order_id' => $order_id,
            'order_item_id' => $order_item_id,
            'variation_id' => $variation_id,
            'customer_id' => $order->get_customer_id(),
            'player_name' => substr((string)($assigned_attendee ?: 'Unknown Player'), 0, 255),
            'first_name' => substr((string)($first_name ?: 'Unknown'), 0, 100),
            'last_name' => substr((string)($last_name ?: 'Unknown'), 0, 100),
            'age' => $age,
            'gender' => substr((string)($gender ?: 'N/A'), 0, 20),
            'booking_type' => $booking_type,
            'selected_days' => $selected_days,
            'camp_terms' => substr((string)($normalized_camp_terms ?: 'N/A'), 0, 100),
            'venue' => substr((string)($normalized_venue ?: 'Unknown Venue'), 0, 200),
            'parent_phone' => substr((string)($order->get_billing_phone() ?: 'N/A'), 0, 20),
            'parent_email' => substr((string)($order->get_billing_email() ?: 'N/A'), 0, 100),
            'medical_conditions' => $medical_conditions,
            'late_pickup' => $late_pickup,
            'late_pickup_days' => $late_pickup_days,
            'day_presence' => json_encode($day_presence),
            'age_group' => substr((string)($normalized_age_group ?: 'N/A'), 0, 50),
            'start_date' => $start_date ?: '1970-01-01',
            'end_date' => $end_date ?: '1970-01-01',
            'event_dates' => substr((string)($event_dates ?: 'N/A'), 0, 100),
            'product_name' => substr((string)($product_name ?: 'Unknown Product'), 0, 255),
            'activity_type' => substr((string)($normalized_activity_type), 0, 50),
            'shirt_size' => substr((string)($shirt_size ?: 'N/A'), 0, 50),
            'shorts_size' => substr((string)($shorts_size ?: 'N/A'), 0, 50),
            'registration_timestamp' => $order_date,
            'course_day' => substr((string)($normalized_course_day ?: 'N/A'), 0, 20),
            'product_id' => $product_id,
            'player_first_name' => substr((string)($first_name ?: 'Unknown'), 0, 100),
            'player_last_name' => substr((string)($last_name ?: 'Unknown'), 0, 100),
            'player_dob' => $dob ?? '1970-01-01',
            'player_gender' => substr((string)($gender ?: 'N/A'), 0, 10),
            'player_medical' => $medical_conditions,
            'player_dietary' => '',
            'parent_first_name' => substr((string)($order->get_billing_first_name() ?: 'Unknown'), 0, 100),
            'parent_last_name' => substr((string)($order->get_billing_last_name() ?: 'Unknown'), 0, 100),
            'emergency_contact' => substr((string)($order->get_billing_phone() ?: 'N/A'), 0, 20),
            'term' => substr((string)(($normalized_camp_terms ?: $normalized_course_day) ?: 'N/A'), 0, 200),
            'times' => substr((string)($normalized_times ?: 'N/A'), 0, 50),
            'days_selected' => substr((string)($selected_days ?: 'N/A'), 0, 200),
            'season' => substr((string)($normalized_season ?: 'N/A'), 0, 50),
            'canton_region' => substr((string)($normalized_canton_region ?: ''), 0, 100),
            'city' => substr((string)($normalized_city ?: ''), 0, 100),
            'avs_number' => substr((string)($avs_number ?: 'N/A'), 0, 50),
            'created_at' => current_time('mysql'),
            'base_price' => 0.00,
            'discount_amount' => 0.00,
            'final_price' => 0.00,
            'reimbursement' => 0.00,
            'discount_codes' => '',
            'girls_only' => FALSE,
        'event_signature' => '',
        'event_completed' => 0,
        ];

        // Generate event signature using the normalized values (same as stored values)
        // This ensures consistency between stored data and event signature
        $roster_entry['event_signature'] = intersoccer_generate_event_signature($normalized_event_data);
        
        error_log('InterSoccer: Generated event_signature=' . $roster_entry['event_signature'] . ' for Order=' . $order_id . ', Item=' . $order_item_id . ' using normalized values');

        // Log to validate $order before insert
        error_log('InterSoccer: Order object type for ' . $order_id . ': ' . (is_object($order) ? get_class($order) : 'Invalid') . ' | Billing last name: ' . $order->get_billing_last_name());
    }

    return $roster_entry;
}

/**
 * Upgrade database schema
 */
function intersoccer_upgrade_database() {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database')) {
        return intersoccer_oop_upgrade_database();
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    error_log('InterSoccer: Starting database upgrade');

    // Run migration to add any missing columns (including is_placeholder)
    intersoccer_migrate_rosters_table();

    $columns = $wpdb->get_results("DESCRIBE $rosters_table");
    $existing_cols = wp_list_pluck($columns, 'Field');

    $new_columns = [
        'base_price' => 'decimal(10,2) DEFAULT 0.00',
        'discount_amount' => 'decimal(10,2) DEFAULT 0.00',
        'final_price' => 'decimal(10,2) DEFAULT 0.00',
        'reimbursement' => 'decimal(10,2) DEFAULT 0.00',
        'discount_codes' => 'varchar(255) DEFAULT \'\'',
        'girls_only' => 'BOOLEAN DEFAULT FALSE',
        'late_pickup_days' => 'text',
        'event_signature' => 'varchar(255) DEFAULT \'\'',
        'event_completed' => 'TINYINT(1) DEFAULT 0',
    ];

    foreach ($new_columns as $col => $type) {
        if (!in_array($col, $existing_cols)) {
            $wpdb->query("ALTER TABLE $rosters_table ADD $col $type");
            error_log('InterSoccer: Added column ' . $col . ' to rosters table');
            $existing_cols[] = $col;
        }
    }

    $has_event_completed_index = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'idx_event_completed'",
        $rosters_table
    ));

    if (empty($has_event_completed_index)) {
        $wpdb->query("ALTER TABLE $rosters_table ADD KEY idx_event_completed (event_completed)");
        error_log('InterSoccer: Added idx_event_completed index during upgrade');
    }

    // Backfill financial data and girls_only
    $rows = $wpdb->get_results("SELECT order_id, order_item_id, activity_type, variation_id, product_id FROM $rosters_table");
    foreach ($rows as $row) {
        $order = wc_get_order($row->order_id);
        if (!$order) continue;

        $item = $order->get_item($row->order_item_id);
        if (!$item) continue;

        $base_price = (float) $item->get_subtotal();
        $final_price = (float) $item->get_total();
        $discount_amount = $base_price - $final_price;
        $reimbursement = 0;
        $discount_codes = implode(',', $order->get_coupon_codes());

        // Backfill girls_only and update activity_type
        $girls_only = FALSE;
        $activity_type = 'unknown';
        $type_id = $row->variation_id ?: $row->product_id;
        $product_type = intersoccer_get_product_type($type_id);
        if ($product_type === 'camp') {
            $activity_type = 'Camp';
        } elseif ($product_type === 'course') {
            $activity_type = 'Course';
        } else {
            $activity_type = ucfirst($product_type);
        }

        // Extract late pickup data
        $late_pickup = (!empty($item_meta['Late Pickup Type'])) ? 'Yes' : 'No';
        $late_pickup_days = $item_meta['Late Pickup Days'] ?? '';

        // Check order item metadata for girls_only
        $item_meta = [];
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $item_meta[$data['key']] = $data['value'];
        }
        $raw_order_item_meta = wc_get_order_item_meta($row->order_item_id, '', true);
        $meta_activity_type = $item_meta['pa_activity-type'] ?? $item_meta['Activity Type'] ?? $raw_order_item_meta['Activity Type'][0] ?? '';
        if ($meta_activity_type) {
            $normalized_activity = trim(strtolower(html_entity_decode($meta_activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $normalized_activity = str_replace(["'", '"'], '', $normalized_activity);
            $activity_types = array_map('trim', explode(',', $normalized_activity));
            if (in_array('girls only', $activity_types) || in_array('camp girls only', $activity_types) || in_array('course girls only', $activity_types)) {
                $girls_only = TRUE;
            }
        } elseif ($row->activity_type) {
            // Fallback to existing activity_type for backfill
            $normalized_activity = trim(strtolower(html_entity_decode($row->activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $normalized_activity = str_replace(["'", '"'], '', $normalized_activity);
            $activity_types = array_map('trim', explode(',', $normalized_activity));
            if (in_array('girls only', $activity_types) || in_array('camp girls only', $activity_types) || in_array('course girls only', $activity_types)) {
                $girls_only = TRUE;
            }
        }

        $wpdb->update(
            $rosters_table,
            [
                'base_price' => $base_price,
                'discount_amount' => $discount_amount,
                'final_price' => $final_price,
                'reimbursement' => $reimbursement,
                'discount_codes' => $discount_codes,
                'girls_only' => $girls_only,
                'activity_type' => $activity_type,
                'late_pickup' => $late_pickup,
                'late_pickup_days' => $late_pickup_days,
            ],
            ['order_item_id' => $row->order_item_id]
        );
        error_log('InterSoccer: Backfilled financial data, girls_only, activity_type, and late pickup data for order_item_id ' . $row->order_item_id . ' (girls_only: ' . $girls_only . ', activity_type: ' . $activity_type . ', late_pickup: ' . $late_pickup . ', late_pickup_days: ' . $late_pickup_days . ')');
    }

    // Backfill avs_number
    $rows_without_avs = $wpdb->get_results("SELECT * FROM $rosters_table WHERE avs_number = 'N/A' OR avs_number = ''");
    foreach ($rows_without_avs as $row) {
        $order = wc_get_order($row->order_id);
        if (!$order) continue;

        $user_id = $order->get_user_id();
        $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
        $first_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $row->first_name))));
        $last_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $row->last_name))));

        foreach ($players as $player) {
            $meta_first_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['first_name'] ?? '') ?? '')));
            $meta_last_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['last_name'] ?? '') ?? '')));
            if ($meta_first_norm === $first_name_norm && $meta_last_norm === $last_name_norm && isset($player['avs_number'])) {
                $avs_number = $player['avs_number'];
                $wpdb->update($rosters_table, ['avs_number' => $avs_number], ['id' => $row->id]);
                error_log('InterSoccer: Backfilled avs_number for order ' . $row->order_id . ': ' . $avs_number);
                break;
            }
        }
    }

    error_log('InterSoccer: Database upgrade completed.');

    return true;
}


// AJAX handlers unchanged
add_action('wp_ajax_intersoccer_upgrade_database', 'intersoccer_upgrade_database_ajax');
function intersoccer_upgrade_database_ajax() {
    check_ajax_referer('intersoccer_rebuild_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to upgrade the database.', 'intersoccer-reports-rosters'));
    }
    $success = intersoccer_upgrade_database();
    $version = get_option('intersoccer_db_version', '1.0.0');
    $engine_label = (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database'))
        ? __('OOP Migrator', 'intersoccer-reports-rosters')
        : __('Legacy Migrator', 'intersoccer-reports-rosters');

    if ($success === false) {
        wp_send_json_error(__('Database upgrade failed. Check logs for details.', 'intersoccer-reports-rosters'));
    }

    wp_send_json_success([
        'message' => sprintf(
            /* translators: 1: current schema version, 2: migration engine */
            __('Database upgrade completed. Schema version is now %1$s (Engine: %2$s).', 'intersoccer-reports-rosters'),
            $version,
            $engine_label
        ),
        'version' => $version,
        'engine' => $engine_label,
    ]);
}

add_action('wp_ajax_intersoccer_rebuild_event_signatures', 'intersoccer_rebuild_event_signatures_ajax');
function intersoccer_rebuild_event_signatures_ajax() {
    error_log('InterSoccer: AJAX rebuild event signatures handler called');
    
    ob_start();
    
    try {
        // Check nonce - try both field names (nonce and intersoccer_rebuild_nonce_field)
        $nonce_check = false;
        if (isset($_POST['nonce'])) {
            $nonce_check = check_ajax_referer('intersoccer_rebuild_nonce', 'nonce', false);
            error_log('InterSoccer: AJAX rebuild - Checking nonce field "nonce": ' . ($nonce_check ? 'valid' : 'invalid'));
        }
        if (!$nonce_check && isset($_POST['intersoccer_rebuild_nonce_field'])) {
            $nonce_check = check_ajax_referer('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field', false);
            error_log('InterSoccer: AJAX rebuild - Checking nonce field "intersoccer_rebuild_nonce_field": ' . ($nonce_check ? 'valid' : 'invalid'));
        }
        
        if (!$nonce_check) {
            error_log('InterSoccer: AJAX rebuild - Nonce check failed. POST data keys: ' . implode(', ', array_keys($_POST)));
            ob_clean();
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'intersoccer-reports-rosters')]);
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('InterSoccer: AJAX rebuild - Permission check failed');
            ob_clean();
            wp_send_json_error(['message' => __('You do not have permission to rebuild event signatures.', 'intersoccer-reports-rosters')]);
            return;
        }
        
        error_log('InterSoccer: AJAX rebuild event signatures request received - calling rebuild function');

        $result = intersoccer_rebuild_event_signatures();
        
        error_log('InterSoccer: AJAX rebuild - Function returned: ' . json_encode($result));
        
        ob_clean();
        if ($result['status'] === 'success') {
            wp_send_json_success(['updated' => $result['updated'], 'message' => __('Event signatures rebuilt for ' . $result['updated'] . ' records.', 'intersoccer-reports-rosters')]);
        } else {
            wp_send_json_error(['message' => __('Event signature rebuild failed: ' . $result['message'], 'intersoccer-reports-rosters')]);
        }
    } catch (Exception $e) {
        error_log('InterSoccer: Event signature rebuild exception: ' . $e->getMessage());
        error_log('InterSoccer: Event signature rebuild exception trace: ' . $e->getTraceAsString());
        ob_clean();
        wp_send_json_error(['message' => __('Event signature rebuild failed with exception: ' . $e->getMessage(), 'intersoccer-reports-rosters')]);
    } catch (Error $e) {
        error_log('InterSoccer: Event signature rebuild fatal error: ' . $e->getMessage());
        error_log('InterSoccer: Event signature rebuild fatal error trace: ' . $e->getTraceAsString());
        ob_clean();
        wp_send_json_error(['message' => __('Event signature rebuild failed with error: ' . $e->getMessage(), 'intersoccer-reports-rosters')]);
    }
}

add_action('wp_ajax_intersoccer_repair_day_presence', 'intersoccer_repair_day_presence_ajax');
function intersoccer_repair_day_presence_ajax() {
    // Use the same nonce used across roster details AJAX actions.
    $nonce_ok = false;
    if (isset($_POST['nonce'])) {
        $nonce_ok = check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce', false);
    }

    if (!$nonce_ok) {
        wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'intersoccer-reports-rosters')]);
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have permission to repair day presence.', 'intersoccer-reports-rosters')]);
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $event_signature = isset($_POST['event_signature']) ? sanitize_text_field($_POST['event_signature']) : '';
    if ($event_signature === '') {
        wp_send_json_error(['message' => __('Missing event signature.', 'intersoccer-reports-rosters')]);
    }


    // Pull only the columns we need; do not touch event_completed.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, booking_type, selected_days, day_presence FROM {$rosters_table} WHERE event_signature = %s",
            $event_signature
        ),
        ARRAY_A
    );

    if (!is_array($rows) || empty($rows)) {
        wp_send_json_success([
            'updated' => 0,
            'skipped' => 0,
            'total' => 0,
            'message' => __('No roster entries found for this event.', 'intersoccer-reports-rosters'),
        ]);
    }

    $updated = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            $skipped++;
            continue;
        }

        $new_presence = function_exists('intersoccer_compute_day_presence')
            ? intersoccer_compute_day_presence($row['booking_type'] ?? '', $row['selected_days'] ?? '')
            : ['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No'];

        $new_json = wp_json_encode($new_presence);
        $old_json = is_string($row['day_presence'] ?? null) ? (string) $row['day_presence'] : '';

        if ($new_json === $old_json) {
            $skipped++;
            continue;
        }

        $result = $wpdb->update(
            $rosters_table,
            ['day_presence' => $new_json],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($result !== false) {
            $updated++;
        } else {
            $skipped++;
        }
    }

    wp_send_json_success([
        'updated' => $updated,
        'skipped' => $skipped,
        'total' => count($rows),
        'message' => sprintf(
            /* translators: 1: updated count, 2: total count */
            __('Repaired day presence for %1$d of %2$d roster entries.', 'intersoccer-reports-rosters'),
            $updated,
            count($rows)
        ),
    ]);
}

/**
 * Validate the rosters table: Check existence and schema match.
 * Returns true if valid, else false and sets admin notice.
 */
function intersoccer_validate_rosters_table() {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database')) {
        return intersoccer_oop_validate_rosters_table();
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rosters_table)) === $rosters_table;
    error_log('InterSoccer: Rosters table exists: ' . ($table_exists ? 'yes' : 'no'));
    if (!$table_exists) {
        intersoccer_create_rosters_table();
        return intersoccer_validate_rosters_table();
    }

    $expected_columns = [
        'id' => 'bigint unsigned',
        'order_id' => 'bigint unsigned',
        'order_item_id' => 'bigint unsigned',
        'variation_id' => 'bigint unsigned',
        'player_name' => 'varchar(255)',
        'first_name' => 'varchar(100)',
        'last_name' => 'varchar(100)',
        'age' => 'int',
        'gender' => 'varchar(20)',
        'booking_type' => 'varchar(50)',
        'selected_days' => 'text',
        'camp_terms' => 'varchar(100)',
        'venue' => 'varchar(200)',
        'parent_phone' => 'varchar(20)',
        'parent_email' => 'varchar(100)',
        'medical_conditions' => 'text',
        'late_pickup' => 'varchar(10)',
        'late_pickup_days' => 'text',
        'day_presence' => 'text',
        'age_group' => 'varchar(50)',
        'start_date' => 'date',
        'end_date' => 'date',
        'event_dates' => 'varchar(100)',
        'product_name' => 'varchar(255)',
        'activity_type' => 'varchar(50)',
        'shirt_size' => 'varchar(50)',
        'shorts_size' => 'varchar(50)',
        'registration_timestamp' => 'datetime',
        'course_day' => 'varchar(20)',
        'updated_at' => 'timestamp',
        'product_id' => 'bigint unsigned',
        'player_first_name' => 'varchar(100)',
        'player_last_name' => 'varchar(100)',
        'player_dob' => 'date',
        'player_gender' => 'varchar(10)',
        'player_medical' => 'text',
        'player_dietary' => 'text',
        'parent_first_name' => 'varchar(100)',
        'parent_last_name' => 'varchar(100)',
        'emergency_contact' => 'varchar(20)',
        'term' => 'varchar(200)',
        'times' => 'varchar(50)',
        'days_selected' => 'varchar(200)',
        'season' => 'varchar(50)',
        'canton_region' => 'varchar(100)',
        'city' => 'varchar(100)',
        'avs_number' => 'varchar(50)',
        'created_at' => 'datetime',
        'base_price' => 'decimal(10,2)',
        'discount_amount' => 'decimal(10,2)',
        'final_price' => 'decimal(10,2)',
        'reimbursement' => 'decimal(10,2)',
        'discount_codes' => 'varchar(255)',
        'girls_only' => 'boolean',
        'event_signature' => 'varchar(255)',
        'is_placeholder' => 'tinyint',
        'event_completed' => 'tinyint',
    ];

    $actual_columns_raw = $wpdb->get_results("DESCRIBE $rosters_table", ARRAY_A);
    $actual_columns = [];
    foreach ($actual_columns_raw as $col) {
        $actual_columns[$col['Field']] = strtolower(preg_replace('/\s*\(.*?\)/', '', $col['Type']));
    }
    error_log('InterSoccer: Rosters table DESCRIBE result: ' . print_r($actual_columns, true));

    $mismatch = array_diff_key($expected_columns, $actual_columns) || array_diff_key($actual_columns, $expected_columns);
    if ($mismatch) {
        error_log('InterSoccer: Rosters table schema mismatch detected.');
        add_action('admin_notices', 'intersoccer_db_upgrade_notice');
        return false;
    }

    return true;
}

/**
 * Admin notice for DB upgrade needed.
 */
function intersoccer_db_upgrade_notice() {
    ?>
    <div class="notice notice-warning is-dismissible">
        <p><?php _e('InterSoccer Rosters table schema is outdated. Go to Advanced Features and click "Upgrade Database".', 'intersoccer-reports-rosters'); ?></p>
    </div>
    <?php
}
?>