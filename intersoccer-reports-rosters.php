<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: Generates event rosters and reports for InterSoccer Switzerland admins using WooCommerce data.
 * Version: 1.2.0
 * Author: Jeremy Lee
 * Text Domain: intersoccer-reports-rosters
 */

defined('ABSPATH') or die('Restricted access');

// Log plugin loading
error_log('InterSoccer: Loading intersoccer-reports-rosters.php');

// Prevent early WooCommerce textdomain loading
add_filter('load_textdomain_mofile', function ($mofile, $domain) {
    if ($domain === 'woocommerce' && !did_action('init')) {
        return '';
    }
    return $mofile;
}, 10, 2);

// Suppress early translation loading notice for third-party plugins
add_filter('load_textdomain_mofile', function ($mofile, $domain) {
    if ($domain === 'woocommerce-products-filter' && !did_action('init')) {
        return '';
    }
    return $mofile;
}, 10, 2);

// Suppress deprecated warnings
add_filter('deprecated_function_trigger_error', function () {
    return false;
}, 10, 2);

// Prevent activation if dependencies are missing
register_activation_hook(__FILE__, function () {
    try {
        $required_plugins = [
            'woocommerce/woocommerce.php' => 'WooCommerce',
            // 'intersoccer-product-variations/intersoccer-product-variations.php' => 'InterSoccer Product Variations',
            // 'intersoccer-player-management/intersoccer-player-management.php' => 'InterSoccer Player Management',
        ];

        $missing = [];
        foreach ($required_plugins as $plugin => $name) {
            if (!function_exists('is_plugin_active') || !is_plugin_active($plugin)) {
                $missing[] = $name;
            }
        }

        if (!empty($missing)) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                sprintf(__('InterSoccer Reports and Rosters requires the following plugins: %s. Please activate them and try again.', 'intersoccer-reports-rosters'), implode(', ', $missing)),
                __('Plugin Activation Error', 'intersoccer-reports-rosters'),
                ['back_link' => true]
            );
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $attendance_table = $wpdb->prefix . 'intersoccer_attendance';
        $attendance_sql = "CREATE TABLE IF NOT EXISTS $attendance_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) NOT NULL,
            player_name VARCHAR(255) NOT NULL,
            date DATE NOT NULL,
            status ENUM('Present', 'Absent', 'Late') NOT NULL DEFAULT 'Absent',
            coach_id BIGINT(20) NOT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_event_id (event_id),
            INDEX idx_date (date)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($attendance_sql);

        $notes_table = $wpdb->prefix . 'intersoccer_coach_notes';
        $notes_sql = "CREATE TABLE IF NOT EXISTS $notes_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) NOT NULL,
            player_id VARCHAR(255) DEFAULT NULL,
            coach_id BIGINT(20) NOT NULL,
            date DATE NOT NULL,
            notes TEXT NOT NULL,
            incident_report TEXT DEFAULT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_event_id (event_id),
            INDEX idx_date (date)
        ) $charset_collate;";
        dbDelta($notes_sql);

        $audit_table = $wpdb->prefix . 'intersoccer_audit';
        $audit_sql = "CREATE TABLE IF NOT EXISTS $audit_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) NOT NULL,
            action VARCHAR(255) NOT NULL,
            details TEXT NOT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user_id (user_id),
            INDEX idx_timestamp (timestamp)
        ) $charset_collate;";
        dbDelta($audit_sql);

        error_log('InterSoccer: Reports and Rosters plugin activated');
        flush_rewrite_rules();
    } catch (Exception $e) {
        error_log('InterSoccer: Activation error: ' . $e->getMessage());
        wp_die(
            __('An error occurred during plugin activation. Please check server logs or contact support.', 'intersoccer-reports-rosters'),
            __('Plugin Activation Error', 'intersoccer-reports-rosters'),
            ['back_link' => true]
        );
    }
});

// Include modular files safely
$included_files = [];
$files_to_include = [
    'utils.php',
    'reports.php',
    'rosters.php',
    'roster-data.php',
    'roster-export.php',
    'advanced.php',
    'event-reports.php',
];

foreach ($files_to_include as $file) {
    $file_path = plugin_dir_path(__FILE__) . 'includes/' . $file;
    if (!isset($included_files[$file]) && file_exists($file_path)) {
        try {
            require_once $file_path;
            $included_files[$file] = true;
            error_log('InterSoccer: Included includes/' . $file);
        } catch (Exception $e) {
            error_log('InterSoccer: Error including includes/' . $file . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            add_action('admin_notices', function() use ($file) {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php printf(__('Error loading %s in InterSoccer Reports and Rosters plugin. Please check the file for issues.', 'intersoccer-reports-rosters'), esc_html($file)); ?></p>
                </div>
                <?php
            });
        }
    } else {
        error_log('InterSoccer: Failed to include includes/' . $file . ' - File not found at ' . $file_path);
        add_action('admin_notices', function() use ($file) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php printf(__('Missing %s in InterSoccer Reports and Rosters plugin. Please ensure all required files are present.', 'intersoccer-reports-rosters'), esc_html($file)); ?></p>
            </div>
            <?php
        });
    }
}

// Enqueue CSS and JS
add_action('admin_enqueue_scripts', function ($hook) {
    try {
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, ['toplevel_page_intersoccer-reports-rosters', 'intersoccer-reports-rosters_page_intersoccer-reports', 'intersoccer-reports-rosters_page_intersoccer-rosters', 'intersoccer-reports-rosters_page_intersoccer-roster-details', 'intersoccer-reports-rosters_page_intersoccer-advanced'])) {
            wp_enqueue_style(
                'intersoccer-reports-rosters-css',
                plugin_dir_url(__FILE__) . 'css/reports-rosters.css',
                [],
                '1.0.3'
            );
            error_log('InterSoccer: Enqueued reports-rosters.css on page ' . $screen->id);

            if ($screen->id === 'toplevel_page_intersoccer-reports-rosters') {
                wp_enqueue_script(
                    'chart-js',
                    'https://cdn.jsdelivr.net/npm/chart.js',
                    [],
                    '3.9.1',
                    true
                );
                wp_enqueue_script(
                    'intersoccer-overview-charts',
                    plugin_dir_url(__FILE__) . 'js/overview-charts.js',
                    ['chart-js'],
                    '1.0.3',
                    true
                );
            }

            if (in_array($screen->id, ['intersoccer-reports-rosters_page_intersoccer-rosters', 'intersoccer-reports-rosters_page_intersoccer-roster-details'])) {
                wp_enqueue_script(
                    'intersoccer-rosters-tabs',
                    plugin_dir_url(__FILE__) . 'js/rosters-tabs.js',
                    ['jquery'],
                    '1.0.3',
                    true
                );
            }

            if ($screen->id === 'intersoccer-reports-rosters_page_intersoccer-advanced') {
                wp_enqueue_script('jquery');
                wp_enqueue_script(
                    'intersoccer-advanced-ajax',
                    plugin_dir_url(__FILE__) . 'js/advanced-ajax.js',
                    ['jquery'],
                    '1.0.3',
                    true
                );
                wp_localize_script(
                    'intersoccer-advanced-ajax',
                    'intersoccer_ajax',
                    [
                        'ajax_url' => admin_url('admin-ajax.php'),
                        'nonce' => wp_create_nonce('intersoccer_advanced_nonce'),
                    ]
                );
            }
        }
    } catch (Exception $e) {
        error_log('InterSoccer: Error enqueuing scripts/styles: ' . $e->getMessage());
    }
});

// Add admin menus
add_action('admin_menu', function () {
    try {
        add_menu_page(
            __('InterSoccer Reports and Rosters', 'intersoccer-reports-rosters'),
            __('Reports and Rosters', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-reports-rosters',
            'intersoccer_render_plugin_overview_page',
            'dashicons-chart-bar',
            30
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('InterSoccer Overview', 'intersoccer-reports-rosters'),
            __('Overview', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-reports-rosters',
            'intersoccer_render_plugin_overview_page'
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('InterSoccer Reports', 'intersoccer-reports-rosters'),
            __('Reports', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-reports',
            'intersoccer_render_reports_page'
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('InterSoccer Rosters', 'intersoccer-reports-rosters'),
            __('Rosters', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-rosters',
            'intersoccer_render_rosters_page'
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Roster Details', 'intersoccer-reports-rosters'),
            null,
            'read',
            'intersoccer-roster-details',
            'intersoccer_render_roster_details_page'
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('InterSoccer Advanced', 'intersoccer-reports-rosters'),
            __('Advanced', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-advanced',
            'intersoccer_render_advanced_page'
        );
    } catch (Exception $e) {
        error_log('InterSoccer: Error adding admin menus: ' . $e->getMessage());
    }
});

// Log actions to audit table
function intersoccer_log_audit($action, $details) {
    try {
        global $wpdb;
        if (!isset($wpdb)) {
            error_log('InterSoccer: Audit logging failed - $wpdb not available');
            return;
        }
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'intersoccer_audit';
        $result = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'action' => sanitize_text_field($action),
                'details' => wp_kses_post($details),
                'timestamp' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s']
        );
        if ($result === false) {
            error_log('InterSoccer: Audit logging failed - Database error: ' . $wpdb->last_error);
        } else {
            error_log("InterSoccer: Audit logged - Action: $action, Details: $details, User ID: $user_id");
        }
    } catch (Exception $e) {
        error_log('InterSoccer: Audit logging error: ' . $e->getMessage());
    }
}

// Check if orders need migration
function intersoccer_orders_need_migration() {
    try {
        $args = [
            'post_type' => 'shop_order',
            'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
            'posts_per_page' => -1,
        ];

        $orders = get_posts($args);
        foreach ($orders as $order_post) {
            $order = wc_get_order($order_post->ID);
            if (!$order) {
                error_log('InterSoccer: Migration Check - Invalid order ID ' . $order_post->ID);
                continue;
            }

            $user_id = $order->get_user_id();
            $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];

            foreach ($order->get_items() as $item) {
                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
                if (!$player_name) {
                    $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                    if ($player_name) {
                        wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                        error_log('InterSoccer: Migration Check - Normalized legacy Assigned Player to Assigned Attendee for Order Item ID ' . $item->get_id());
                    }
                }

                if (!$player_name) {
                    $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                    if ($player_index && isset($players[$player_index])) {
                        $player = $players[$player_index];
                        $player_name = $player['first_name'] . ' ' . $player['last_name'];
                        wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                        error_log('InterSoccer: Migration Check - Restored Assigned Attendee metadata for Order Item ID ' . $item->get_id() . ' as ' . $player_name);
                    }
                }

                if (!$player_name) {
                    error_log('InterSoccer: Migration Check - No Assigned Attendee for Order Item ID ' . $item->get_id());
                    continue;
                }

                $age = wc_get_order_item_meta($item->get_id(), 'Player Age', true);
                $gender = wc_get_order_item_meta($item->get_id(), 'Player Gender', true);
                $medical = wc_get_order_item_meta($item->get_id(), 'Medical Conditions', true);
                $late_pickup = wc_get_order_item_meta($item->get_id(), 'Late Pickup', true);

                if (empty($age) || empty($gender) || empty($medical) || empty($late_pickup)) {
                    error_log('InterSoccer: Migration Check - Order Item ID ' . $item->get_id() . ' needs migration (Age: ' . ($age ?: 'missing') . ', Gender: ' . ($gender ?: 'missing') . ', Medical: ' . ($medical ?: 'missing') . ', Late Pickup: ' . ($late_pickup ?: 'missing') . ')');
                    return true;
                }
            }
        }
        error_log('InterSoccer: Migration Check - No orders need migration');
        return false;
    } catch (Exception $e) {
        error_log('InterSoccer: Migration Check error: ' . $e->getMessage());
        return false;
    }
}

// Diagnostic function to check Assigned Attendee metadata
function intersoccer_diagnose_assigned_player_metadata() {
    try {
        $args = [
            'post_type' => 'shop_order',
            'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
            'posts_per_page' => -1,
        ];

        $orders = get_posts($args);
        $total_orders = count($orders);
        $orders_with_assigned_players = 0;
        $orders_missing_assigned_players = 0;
        $orders_with_players_but_missing_metadata = 0;

        foreach ($orders as $order_post) {
            $order = wc_get_order($order_post->ID);
            if (!$order) {
                error_log('InterSoccer: Diagnostic - Invalid order ID ' . $order_post->ID);
                continue;
            }

            $user_id = $order->get_user_id();
            $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
            $has_assigned_player = false;
            $can_restore_metadata = false;

            foreach ($order->get_items() as $item) {
                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
                if ($player_name) {
                    $has_assigned_player = true;
                    break;
                }

                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                if ($player_name) {
                    $has_assigned_player = true;
                    $can_restore_metadata = true;
                    error_log('InterSoccer: Diagnostic - Order Item ID ' . $item->get_id() . ' has legacy Assigned Player');
                    break;
                }

                $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                if ($player_index && isset($players[$player_index])) {
                    $has_assigned_player = true;
                    $can_restore_metadata = true;
                    error_log('InterSoccer: Diagnostic - Order Item ID ' . $item->get_id() . ' can restore Assigned Attendee from user metadata');
                    break;
                }
            }

            if ($has_assigned_player) {
                $orders_with_assigned_players++;
                if ($can_restore_metadata) {
                    $orders_with_players_but_missing_metadata++;
                }
            } else {
                $orders_missing_assigned_players++;
                error_log('InterSoccer: Diagnostic - Order ID ' . $order->get_id() . ' has no Assigned Attendee');
            }
        }

        $diagnostic = [
            'total_orders' => $total_orders,
            'orders_with_assigned_players' => $orders_with_assigned_players,
            'orders_missing_assigned_players' => $orders_missing_assigned_players,
            'orders_with_players_but_missing_metadata' => $orders_with_players_but_missing_metadata,
            'migration_needed' => $orders_with_players_but_missing_metadata > 0 || $orders_with_assigned_players < $total_orders,
        ];

        error_log('InterSoccer: Diagnostic - Total Orders: ' . $diagnostic['total_orders'] . ', Orders with Assigned Attendees: ' . $diagnostic['orders_with_assigned_players'] . ', Orders Missing Assigned Attendees: ' . $diagnostic['orders_missing_assigned_players'] . ', Orders Needing Metadata Restoration: ' . $diagnostic['orders_with_players_but_missing_metadata']);

        return $diagnostic;
    } catch (Exception $e) {
        error_log('InterSoccer: Diagnostic error: ' . $e->getMessage());
        return [
            'total_orders' => 0,
            'orders_with_assigned_players' => 0,
            'orders_missing_assigned_players' => 0,
            'orders_with_players_but_missing_metadata' => 0,
            'migration_needed' => false,
        ];
    }
}

// Fetch data for charts
function intersoccer_get_chart_data() {
    try {
        $orders = wc_get_orders([
            'status' => ['completed', 'processing', 'pending', 'on-hold'],
            'limit' => -1,
        ]);
        error_log('InterSoccer: Found ' . count($orders) . ' orders for chart data');

        $region_counts = [];
        $genders = ['Male' => 0, 'Female' => 0, 'Other' => 0];
        $age_groups = [
            '2-5' => 0,
            '6-9' => 0,
            '10-13' => 0,
            '14-15' => 0,
        ];
        $weekly_trends = [];
        $current_date = new DateTime(current_time('Y-m-d'));
        for ($i = 11; $i >= 0; $i--) {
            $week_start = (clone $current_date)->modify("-$i weeks")->setTime(0, 0, 0);
            $week_label = $week_start->format('Y-m-d');
            $weekly_trends[$week_label] = 0;
        }

        $current_attendance_by_venue = [];
        $current_date_str = $current_date->format('Y-m-d');

        foreach ($orders as $order) {
            $order_date = new DateTime($order->get_date_created()->format('Y-m-d H:i:s'));
            $user_id = $order->get_user_id();
            $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
            error_log('InterSoccer: Order ID ' . $order->get_id() . ' - User ID: ' . $user_id . ', Players: ' . print_r($players, true));

            foreach ($order->get_items() as $item) {
                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
                if (!$player_name) {
                    $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                    if ($player_name) {
                        wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                        error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - Normalized legacy Assigned Player to Assigned Attendee');
                    }
                }
                if (!$player_name) {
                    error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - No Assigned Attendee');
                    continue;
                }

                $variation_id = $item->get_variation_id();
                $product_id = $item->get_product_id();
                $region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
                $venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
                $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? null;
                $end_date = wc_get_product_terms($product_id, 'pa_end-date', ['fields' => 'names'])[0] ?? null;

                $region_counts[$region] = ($region_counts[$region] ?? 0) + 1;

                $week_start = (clone $order_date)->modify('monday this week')->setTime(0, 0, 0);
                $week_label = $week_start->format('Y-m-d');
                if (isset($weekly_trends[$week_label])) {
                    $weekly_trends[$week_label]++;
                }

                $is_active = false;
                if ($start_date && $end_date) {
                    $start = DateTime::createFromFormat('d/m/Y', $start_date);
                    $end = DateTime::createFromFormat('d/m/Y', $end_date);
                    if ($start && $end) {
                        $start_str = $start->format('Y-m-d');
                        $end_str = $end->format('Y-m-d');
                        if ($current_date_str >= $start_str && $current_date_str <= $end_str) {
                            $is_active = true;
                        }
                    }
                }

                if ($is_active) {
                    $current_attendance_by_venue[$venue] = ($current_attendance_by_venue[$venue] ?? 0) + 1;
                    error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - Added to current attendance for Venue: ' . $venue);
                }

                $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                if ($player_index && isset($players[$player_index])) {
                    $player = $players[$player_index];
                    error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - Player Index: ' . $player_index . ', Player Data: ' . print_r($player, true));

                    if (isset($player['dob']) && !empty($player['dob'])) {
                        $dob = DateTime::createFromFormat('Y-m-d', $player['dob']);
                        if ($dob) {
                            $age = $dob->diff($current_date)->y;
                            error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - Player Age: ' . $age);
                            if ($age >= 2 && $age <= 5) {
                                $age_groups['2-5']++;
                            } elseif ($age >= 6 && $age <= 9) {
                                $age_groups['6-9']++;
                            } elseif ($age >= 10 && $age <= 13) {
                                $age_groups['10-13']++;
                            } elseif ($age >= 14 && $age <= 15) {
                                $age_groups['14-15']++;
                            }
                        } else {
                            error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - Invalid DOB format: ' . $player['dob']);
                        }
                    } else {
                        error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - DOB not set for player');
                    }

                    $gender = isset($player['gender']) && !empty($player['gender']) ? ucfirst($player['gender']) : 'Other';
                    if (in_array($gender, ['Male', 'Female', 'Other'])) {
                        $genders[$gender]++;
                    } else {
                        error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - Invalid gender value: ' . ($player['gender'] ?? 'not set'));
                    }
                } else {
                    error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - Player Index not found or invalid');
                }
            }
        }

        $region_counts = array_map('intval', $region_counts);
        $genders = array_map('intval', $genders);
        $age_groups = array_map('intval', $age_groups);
        $weekly_trends = array_map('intval', $weekly_trends);
        $current_attendance_by_venue = array_map('intval', $current_attendance_by_venue);

        error_log('InterSoccer: Chart Data - Age Groups: ' . print_r($age_groups, true));
        error_log('InterSoccer: Chart Data - Genders: ' . print_r($genders, true));
        error_log('InterSoccer: Chart Data - Current Attendance by Venue: ' . print_r($current_attendance_by_venue, true));

        return [
            'region_counts' => $region_counts,
            'age_groups' => $age_groups,
            'genders' => $genders,
            'weekly_trends' => $weekly_trends,
            'current_attendance_by_venue' => $current_attendance_by_venue,
        ];
    } catch (Exception $e) {
        error_log('InterSoccer: Chart Data error: ' . $e->getMessage());
        return [
            'region_counts' => [],
            'age_groups' => [],
            'genders' => [],
            'weekly_trends' => [],
            'current_attendance_by_venue' => [],
        ];
    }
}

// Overview page
function intersoccer_render_plugin_overview_page() {
    try {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
        }

        intersoccer_log_audit('view_overview', 'User accessed the Reports and Rosters Overview page');

        $chart_data = intersoccer_get_chart_data();
        $region_counts = $chart_data['region_counts'];
        $age_groups = $chart_data['age_groups'];
        $genders = $chart_data['genders'];
        $weekly_trends = $chart_data['weekly_trends'];
        $current_attendance_by_venue = $chart_data['current_attendance_by_venue'];

        $region_labels = json_encode(array_keys($region_counts));
        $region_values = json_encode(array_values($region_counts));
        $age_labels = json_encode(array_keys($age_groups));
        $age_values = json_encode(array_values($age_groups));
        $gender_labels = json_encode(array_keys($genders));
        $gender_values = json_encode(array_values($genders));
        $weekly_labels = json_encode(array_keys($weekly_trends));
        $weekly_values = json_encode(array_values($weekly_trends));
        $current_venue_labels = json_encode(array_keys($current_attendance_by_venue));
        $current_venue_values = json_encode(array_values($current_attendance_by_venue));

        ?>
        <div class="wrap intersoccer-reports-rosters-dashboard">
            <h1><?php _e('InterSoccer Reports and Rosters - Overview', 'intersoccer-reports-rosters'); ?></h1>
            <p><?php _e('Welcome to the InterSoccer Reports and Rosters plugin. Use the menu options below to access various features.', 'intersoccer-reports-rosters'); ?></p>
            <ul>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-reports')); ?>" class="button"><?php _e('View Reports', 'intersoccer-reports-rosters'); ?></a> - <?php _e('Generate and export reports for camps and courses.', 'intersoccer-reports-rosters'); ?></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-rosters')); ?>" class="button"><?php _e('View Rosters', 'intersoccer-reports-rosters'); ?></a> - <?php _e('Manage event rosters and player assignments.', 'intersoccer-reports-rosters'); ?></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-advanced')); ?>" class="button"><?php _e('Advanced Features', 'intersoccer-reports-rosters'); ?></a> - <?php _e('Access advanced tools like attendance management, coach notes, and data migration.', 'intersoccer-reports-rosters'); ?></li>
            </ul>

            <div class="filter-section">
                <h2><?php _e('Analytics Dashboard', 'intersoccer-reports-rosters'); ?></h2>
                <div style="display: flex; flex-wrap: wrap; gap: 20px;">
                    <div style="flex: 1; min-width: 300px;">
                        <h3><?php _e('Current Attendance by Venue (Today)', 'intersoccer-reports-rosters'); ?></h3>
                        <canvas id="currentVenueChart" width="400" height="400"></canvas>
                    </div>
                    <div style="flex: 1; min-width: 300px;">
                        <h3><?php _e('Attendees by Region', 'intersoccer-reports-rosters'); ?></h3>
                        <canvas id="regionChart" width="400" height="400"></canvas>
                    </div>
                    <div style="flex: 1; min-width: 300px;">
                        <h3><?php _e('Age Distribution of Attendees', 'intersoccer-reports-rosters'); ?></h3>
                        <canvas id="ageChart" width="400" height="400"></canvas>
                    </div>
                    <div style="flex: 1; min-width: 300px;">
                        <h3><?php _e('Gender Distribution', 'intersoccer-reports-rosters'); ?></h3>
                        <canvas id="genderChart" width="400" height="400"></canvas>
                    </div>
                    <div style="flex: 1; min-width: 300px;">
                        <h3><?php _e('Weekly Attendance Trends', 'intersoccer-reports-rosters'); ?></h3>
                        <canvas id="weeklyTrendsChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>

            <div class="filter-section">
                <h2><?php _e('Future Enhancements', 'intersoccer-reports-rosters'); ?></h2>
                <p><?php _e('Upcoming features include:', 'intersoccer-reports-rosters'); ?></p>
                <ul>
                    <li><?php _e('Export reports and rosters to PDF.', 'intersoccer-reports-rosters'); ?></li>
                    <li><?php _e('Integration with Google Sheets for seamless data export and collaboration.', 'intersoccer-reports-rosters'); ?></li>
                    <li><?php _e('More advanced analytics with additional chart types.', 'intersoccer-reports-rosters'); ?></li>
                </ul>
            </div>
        </div>

        <script>
            var regionChartData = {
                labels: <?php echo $region_labels; ?>,
                values: <?php echo $region_values; ?>,
            };
            var ageChartData = {
                labels: <?php echo $age_labels; ?>,
                values: <?php echo $age_values; ?>,
            };
            var genderChartData = {
                labels: <?php echo $gender_labels; ?>,
                values: <?php echo $gender_values; ?>,
            };
            var weeklyTrendsChartData = {
                labels: <?php echo $weekly_labels; ?>,
                values: <?php echo $weekly_values; ?>,
            };
            var currentVenueChartData = {
                labels: <?php echo $current_venue_labels; ?>,
                values: <?php echo $current_venue_values; ?>,
            };
        </script>
        <?php
        error_log('InterSoccer: Rendered Reports and Rosters Overview page');
    } catch (Exception $e) {
        error_log('InterSoccer: Overview page error: ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the overview page.', 'intersoccer-reports-rosters'));
    }
}

// Migration function to update existing orders with player data
function intersoccer_migrate_player_data_to_orders() {
    try {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'intersoccer-reports-rosters'));
        }

        $args = [
            'post_type' => 'shop_order',
            'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
            'posts_per_page' => -1,
        ];

        $orders = get_posts($args);
        $updated_orders = 0;
        $updated_items = 0;

        foreach ($orders as $order_post) {
            $order = wc_get_order($order_post->ID);
            if (!$order) {
                error_log('InterSoccer: Migration - Invalid order ID ' . $order_post->ID);
                continue;
            }

            $user_id = $order->get_user_id();
            $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
            $order_updated = false;

            foreach ($order->get_items() as $item) {
                $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);

                if (!$player_index || !isset($players[$player_index])) {
                    error_log('InterSoccer: Migration - No player index or player data for Order Item ID ' . $item->get_id() . ' in Order ID ' . $order->get_id());
                    continue;
                }

                if (!$player_name) {
                    $player = $players[$player_index];
                    $player_name = $player['first_name'] . ' ' . $player['last_name'];
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                    error_log('InterSoccer: Migration - Restored Assigned Attendee metadata for Order Item ID ' . $item->get_id() . ' as ' . $player_name);
                }

                $player = $players[$player_index];
                $age = 'N/A';
                if (isset($player['dob']) && !empty($player['dob'])) {
                    $dob = DateTime::createFromFormat('Y-m-d', $player['dob']);
                    if ($dob) {
                        $current_date = new DateTime(current_time('Y-m-d'));
                        $interval = $dob->diff($current_date);
                        $age = $interval->y;
                    }
                }

                $gender = isset($player['gender']) && !empty($player['gender']) ? ucfirst($player['gender']) : 'N/A';
                $medical = isset($player['medical_conditions']) ? $player['medical_conditions'] : 'None';
                $late_pickup = wc_get_order_item_meta($item->get_id(), 'late_pickup', true) ?: 'No';

                $existing_age = wc_get_order_item_meta($item->get_id(), 'Player Age', true);
                $existing_gender = wc_get_order_item_meta($item->get_id(), 'Player Gender', true);
                $existing_medical = wc_get_order_item_meta($item->get_id(), 'Medical Conditions', true);
                $existing_late_pickup = wc_get_order_item_meta($item->get_id(), 'Late Pickup', true);

                if (!$existing_age || !$existing_gender || !$existing_medical || !$existing_late_pickup) {
                    wc_update_order_item_meta($item->get_id(), 'Player Age', $age);
                    wc_update_order_item_meta($item->get_id(), 'Player Gender', $gender);
                    wc_update_order_item_meta($item->get_id(), 'Medical Conditions', $medical);
                    wc_update_order_item_meta($item->get_id(), 'Late Pickup', $late_pickup);
                    $updated_items++;
                    $order_updated = true;
                    error_log('InterSoccer: Migration - Updated Order Item ID ' . $item->get_id() . ' with Age: ' . $age . ', Gender: ' . $gender . ', Medical: ' . $medical . ', Late Pickup: ' . $late_pickup);
                }
            }

            if ($order_updated) {
                $updated_orders++;
                error_log('InterSoccer: Migration - Updated Order ID ' . $order->get_id());
            }
        }

        intersoccer_log_audit('migrate_player_data', "Updated $updated_orders orders and $updated_items order items with player data");

        add_action('admin_notices', function() use ($updated_orders, $updated_items) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php printf(__('Migration completed: Updated %d orders and %d order items with player data.', 'intersoccer-reports-rosters'), $updated_orders, $updated_items); ?></p>
            </div>
            <?php
        });

        wp_redirect(admin_url('admin.php?page=intersoccer-advanced'));
        exit;
    } catch (Exception $e) {
        error_log('InterSoccer: Migration error: ' . $e->getMessage());
        wp_die(__('An error occurred during data migration.', 'intersoccer-reports-rosters'));
    }
}
?>
