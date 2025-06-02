<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: Generates event rosters and reports for InterSoccer Switzerland admins using WooCommerce data.
 * Version: 2.4.7
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
    $required_plugins = [
        'woocommerce/woocommerce.php' => 'WooCommerce',
        'intersoccer-product-variations/intersoccer-product-variations.php' => 'InterSoccer Product Variations',
        'intersoccer-player-management/intersoccer-player-management.php' => 'InterSoccer Player Management',
    ];

    $missing = [];
    foreach ($required_plugins as $plugin => $name) {
        if (!is_plugin_active($plugin)) {
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

    // Create custom tables
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Create wp_intersoccer_attendance table
    $attendance_table = $wpdb->prefix . 'intersoccer_attendance';
    $attendance_sql = "CREATE TABLE $attendance_table (
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

    // Create wp_intersoccer_coach_notes table
    $notes_table = $wpdb->prefix . 'intersoccer_coach_notes';
    $notes_sql = "CREATE TABLE $notes_table (
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

    error_log('InterSoccer: Reports and Rosters plugin activated');
    flush_rewrite_rules();
});

// Include modular files
$included_files = [];
$files_to_include = [
    'utils.php',
    'reports.php',
    'rosters.php',
    'roster-data.php',
    'roster-export.php',
    'advanced.php',
];

foreach ($files_to_include as $file) {
    $file_path = plugin_dir_path(__FILE__) . 'includes/' . $file;
    if (!isset($included_files[$file]) && file_exists($file_path)) {
        require_once $file_path;
        $included_files[$file] = true;
        error_log('InterSoccer: Included includes/' . $file);
    } else {
        error_log('InterSoccer: Failed to include includes/' . $file . ' - File not found');
    }
}

// Enqueue CSS and JS for Reports and Rosters pages
add_action('admin_enqueue_scripts', function ($hook) {
    // Only enqueue on Reports and Rosters, Overview, Reports, Rosters, Roster Details, and Advanced pages
    $screen = get_current_screen();
    if ($screen && in_array($screen->id, ['toplevel_page_intersoccer-reports-rosters', 'intersoccer-reports-rosters_page_intersoccer-overview', 'intersoccer-reports-rosters_page_intersoccer-reports', 'intersoccer-reports-rosters_page_intersoccer-rosters', 'intersoccer-reports-rosters_page_intersoccer-roster-details', 'intersoccer-reports-rosters_page_intersoccer-advanced'])) {
        wp_enqueue_style(
            'intersoccer-reports-rosters-css',
            plugin_dir_url(__FILE__) . 'css/reports-rosters.css',
            [],
            '2.4.7'
        );
        error_log('InterSoccer: Enqueued reports-rosters.css on page ' . $screen->id);

        // Enqueue Chart.js and custom chart script on Overview page
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
                '2.4.7',
                true
            );
        }

        // Enqueue jQuery and AJAX scripts for Advanced page
        if ($screen->id === 'intersoccer-reports-rosters_page_intersoccer-advanced') {
            wp_enqueue_script('jquery');
            wp_enqueue_script(
                'intersoccer-advanced-ajax',
                plugin_dir_url(__FILE__) . 'js/advanced-ajax.js',
                ['jquery'],
                '2.4.7',
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
});

// Add admin menus
add_action('admin_menu', function () {
    // Reports and Rosters Top-Level Menu
    add_menu_page(
        __('InterSoccer Reports and Rosters', 'intersoccer-reports-rosters'),
        __('Reports and Rosters', 'intersoccer-reports-rosters'),
        'manage_options',
        'intersoccer-reports-rosters',
        'intersoccer_render_plugin_overview_page', // Default landing page
        'dashicons-chart-bar',
        30
    );

    // Overview Submenu (visible)
    add_submenu_page(
        'intersoccer-reports-rosters',
        __('InterSoccer Overview', 'intersoccer-reports-rosters'),
        __('Overview', 'intersoccer-reports-rosters'),
        'manage_options',
        'intersoccer-overview',
        'intersoccer_render_plugin_overview_page'
    );

    // Reports Submenu
    add_submenu_page(
        'intersoccer-reports-rosters',
        __('InterSoccer Reports', 'intersoccer-reports-rosters'),
        __('Reports', 'intersoccer-reports-rosters'),
        'manage_options',
        'intersoccer-reports',
        'intersoccer_render_reports_page'
    );

    // Rosters Submenu
    add_submenu_page(
        'intersoccer-reports-rosters',
        __('InterSoccer Rosters', 'intersoccer-reports-rosters'),
        __('Rosters', 'intersoccer-reports-rosters'),
        'manage_options',
        'intersoccer-rosters',
        'intersoccer_render_rosters_page'
    );

    // Add submenu page for roster details (hidden from menu)
    add_submenu_page(
        'intersoccer-reports-rosters',
        __('Roster Details', 'intersoccer-reports-rosters'),
        null, // Hidden from menu
        'manage_options',
        'intersoccer-roster-details',
        'intersoccer_render_roster_details_page'
    );

    // Advanced Submenu
    add_submenu_page(
        'intersoccer-reports-rosters',
        __('InterSoccer Advanced', 'intersoccer-reports-rosters'),
        __('Advanced', 'intersoccer-reports-rosters'),
        'manage_options',
        'intersoccer-advanced',
        'intersoccer_render_advanced_page'
    );
});

// Check if there are orders needing migration
function intersoccer_orders_need_migration() {
    $args = [
        'post_type' => 'shop_order',
        'post_status' => ['wc-completed', 'wc-processing'],
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
            // Check for Assigned Attendee metadata (current production key)
            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
            if (!$player_name) {
                // Fallback to Assigned Player (legacy key)
                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                if ($player_name) {
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                    error_log('InterSoccer: Migration Check - Normalized legacy Assigned Player to Assigned Attendee for Order Item ID ' . $item->get_id());
                }
            }

            // Fallback to user metadata if necessary
            if (!$player_name) {
                $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                if ($player_index && isset($players[$player_index])) {
                    $player = $players[$player_index];
                    $player_name = $player['first_name'] . ' ' . $player['last_name'];
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                    error_log('InterSoccer: Migration Check - Restored Assigned Attendee metadata for Order Item ID ' . $item->get_id() . ' as ' . $player_name);
                }
            }

            // If no player name, skip this item
            if (!$player_name) {
                error_log('InterSoccer: Migration Check - No Assigned Attendee for Order Item ID ' . $item->get_id());
                continue;
            }

            // Check if age or gender metadata is missing
            $age = wc_get_order_item_meta($item->get_id(), 'Player Age', true);
            $gender = wc_get_order_item_meta($item->get_id(), 'Player Gender', true);

            if (empty($age) || empty($gender)) {
                error_log('InterSoccer: Migration Check - Order Item ID ' . $item->get_id() . ' needs migration (Age: ' . ($age ?: 'missing') . ', Gender: ' . ($gender ?: 'missing') . ')');
                return true; // Found an order needing migration
            }
        }
    }
    error_log('InterSoccer: Migration Check - No orders need migration');
    return false; // No orders need migration
}

// Diagnostic function to check Assigned Attendee metadata
function intersoccer_diagnose_assigned_player_metadata() {
    $args = [
        'post_type' => 'shop_order',
        'post_status' => ['wc-completed', 'wc-processing'],
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
            // Check for Assigned Attendee metadata (current production key)
            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
            if ($player_name) {
                $has_assigned_player = true;
                break;
            }

            // Fallback to Assigned Player (legacy key)
            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
            if ($player_name) {
                $has_assigned_player = true;
                $can_restore_metadata = true;
                error_log('InterSoccer: Diagnostic - Order Item ID ' . $item->get_id() . ' has legacy Assigned Player');
                break;
            }

            // Check if assigned_player index exists but Assigned Attendee is missing
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
}

// Fetch data for charts
function intersoccer_get_chart_data() {
    // Fetch all variable products
    $products = wc_get_products([
        'type' => 'variable',
        'limit' => -1,
        'status' => 'publish',
    ]);

    // Fetch all orders
    $orders = wc_get_orders([
        'status' => ['completed', 'processing'],
        'limit' => -1,
    ]);

    // Event Popularity by Region (Pie Chart)
    $region_counts = [];
    foreach ($products as $product) {
        $product_id = $product->get_id();
        $region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
        $region_counts[$region] = ($region_counts[$region] ?? 0) + 1;
    }

    // Age Distribution of Attendees (Bar Chart)
    $age_groups = [
        '2-5' => 0,
        '6-9' => 0,
        '10-13' => 0,
        '14-15' => 0,
    ];

    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
            $user_id = $order->get_user_id();
            $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];

            if ($player_index && isset($players[$player_index])) {
                $player = $players[$player_index];
                if (isset($player['dob']) && !empty($player['dob'])) {
                    $dob = DateTime::createFromFormat('Y-m-d', $player['dob']);
                    if ($dob) {
                        $current_date = new DateTime('2025-06-02');
                        $age = $dob->diff($current_date)->y;

                        if ($age >= 2 && $age <= 5) {
                            $age_groups['2-5']++;
                        } elseif ($age >= 6 && $age <= 9) {
                            $age_groups['6-9']++;
                        } elseif ($age >= 10 && $age <= 13) {
                            $age_groups['10-13']++;
                        } elseif ($age >= 14 && $age <= 15) {
                            $age_groups['14-15']++;
                        }
                    }
                }
            }
        }
    }

    return [
        'region_counts' => $region_counts,
        'age_groups' => $age_groups,
    ];
}

// Overview page (default landing page for the plugin)
function intersoccer_render_plugin_overview_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    // Check if migration is requested
    if (isset($_GET['action']) && $_GET['action'] === 'migrate_player_data' && check_admin_referer('migrate_player_data_nonce')) {
        intersoccer_migrate_player_data_to_orders();
    }

    // Check if there are orders to migrate
    $needs_migration = intersoccer_orders_need_migration();

    // Run diagnostic for Assigned Attendee metadata
    $diagnostic = intersoccer_diagnose_assigned_player_metadata();

    // Fetch chart data
    $chart_data = intersoccer_get_chart_data();
    $region_counts = $chart_data['region_counts'];
    $age_groups = $chart_data['age_groups'];

    // Prepare data for Chart.js
    $region_labels = json_encode(array_keys($region_counts));
    $region_values = json_encode(array_values($region_counts));
    $age_labels = json_encode(array_keys($age_groups));
    $age_values = json_encode(array_values($age_groups));

    ?>
    <div class="wrap intersoccer-reports-rosters-dashboard">
        <h1><?php _e('InterSoccer Reports and Rosters - Overview', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php _e('Welcome to the InterSoccer Reports and Rosters plugin. Use the menu options below to access various features.', 'intersoccer-reports-rosters'); ?></p>
        <ul>
            <li><a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-reports')); ?>" class="button"><?php _e('View Reports', 'intersoccer-reports-rosters'); ?></a> - <?php _e('Generate and export reports for camps and courses.', 'intersoccer-reports-rosters'); ?></li>
            <li><a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-rosters')); ?>" class="button"><?php _e('View Rosters', 'intersoccer-reports-rosters'); ?></a> - <?php _e('Manage event rosters and player assignments.', 'intersoccer-reports-rosters'); ?></li>
            <li><a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-advanced')); ?>" class="button"><?php _e('Advanced Features', 'intersoccer-reports-rosters'); ?></a> - <?php _e('Access advanced tools like attendance management and coach notes.', 'intersoccer-reports-rosters'); ?></li>
        </ul>

        <!-- Migration Option -->
        <div class="filter-section">
            <h2><?php _e('Data Migration', 'intersoccer-reports-rosters'); ?></h2>
            <?php if ($needs_migration): ?>
                <p><?php _e('Some orders need to be updated with player age and gender metadata. Click the button below to run the migration.', 'intersoccer-reports-rosters'); ?></p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-overview&action=migrate_player_data'), 'migrate_player_data_nonce')); ?>" class="button button-primary"><?php _e('Migrate Player Data to Orders', 'intersoccer-reports-rosters'); ?></a>
            <?php else: ?>
                <p><?php _e('All orders already have player age and gender metadata. No migration is needed.', 'intersoccer-reports-rosters'); ?></p>
                <button class="button button-primary" disabled><?php _e('Migrate Player Data to Orders', 'intersoccer-reports-rosters'); ?></button>
            <?php endif; ?>
        </div>

        <!-- Diagnostic Report -->
        <div class="filter-section">
            <h2><?php _e('Assigned Attendee Metadata Diagnostic', 'intersoccer-reports-rosters'); ?></h2>
            <p><?php _e('Total Orders: ', 'intersoccer-reports-rosters'); echo esc_html($diagnostic['total_orders']); ?></p>
            <p><?php _e('Orders with Assigned Attendees: ', 'intersoccer-reports-rosters'); echo esc_html($diagnostic['orders_with_assigned_players']); ?></p>
            <p><?php _e('Orders Missing Assigned Attendees: ', 'intersoccer-reports-rosters'); echo esc_html($diagnostic['orders_missing_assigned_players']); ?></p>
            <p><?php _e('Orders with Attendees but Missing Metadata (Can Be Restored): ', 'intersoccer-reports-rosters'); echo esc_html($diagnostic['orders_with_players_but_missing_metadata']); ?></p>
            <?php if ($diagnostic['migration_needed']): ?>
                <p style="color: red;"><?php _e('A data migration is recommended to restore missing Assigned Attendee metadata for orders. This can be done by running the migration script above, which will attempt to restore metadata from user data.', 'intersoccer-reports-rosters'); ?></p>
            <?php else: ?>
                <p style="color: green;"><?php _e('No data migration is needed for Assigned Attendee metadata. Rosters should now be populated if orders exist.', 'intersoccer-reports-rosters'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Analytics Dashboard -->
        <div class="filter-section">
            <h2><?php _e('Analytics Dashboard', 'intersoccer-reports-rosters'); ?></h2>
            <div style="display: flex; flex-wrap: wrap; gap: 20px;">
                <!-- Event Popularity by Region (Pie Chart) -->
                <div style="flex: 1; min-width: 300px;">
                    <h3><?php _e('Event Popularity by Region', 'intersoccer-reports-rosters'); ?></h3>
                    <canvas id="regionChart" width="400" height="400"></canvas>
                </div>
                <!-- Age Distribution of Attendees (Bar Chart) -->
                <div style="flex: 1; min-width: 300px;">
                    <h3><?php _e('Age Distribution of Attendees', 'intersoccer-reports-rosters'); ?></h3>
                    <canvas id="ageChart" width="400" height="400"></canvas>
                </div>
            </div>
        </div>

        <!-- Future Enhancements -->
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

    <!-- Inline JavaScript to pass data to overview-charts.js -->
    <script>
        var regionChartData = {
            labels: <?php echo $region_labels; ?>,
            values: <?php echo $region_values; ?>,
        };
        var ageChartData = {
            labels: <?php echo $age_labels; ?>,
            values: <?php echo $age_values; ?>,
        };
    </script>
    <?php
    error_log('InterSoccer: Rendered Reports and Rosters Overview page');
}

// Migration function to update existing orders with player age and gender
function intersoccer_migrate_player_data_to_orders() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to perform this action.', 'intersoccer-reports-rosters'));
    }

    // Fetch all completed and processing orders
    $args = [
        'post_type' => 'shop_order',
        'post_status' => ['wc-completed', 'wc-processing'],
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
            
            // Skip if no player index or player data exists
            if (!$player_index || !isset($players[$player_index])) {
                error_log('InterSoccer: Migration - No player index or player data for Order Item ID ' . $item->get_id() . ' in Order ID ' . $order->get_id());
                continue;
            }

            // Verify Assigned Attendee metadata exists
            if (!$player_name) {
                error_log('InterSoccer: Migration - Missing Assigned Attendee metadata for Order Item ID ' . $item->get_id() . ' in Order ID ' . $order->get_id());
                // Attempt to restore Assigned Attendee from user metadata
                $player = $players[$player_index];
                $player_name = $player['first_name'] . ' ' . $player['last_name'];
                wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                error_log('InterSoccer: Migration - Restored Assigned Attendee metadata for Order Item ID ' . $item->get_id() . ' as ' . $player_name);
            }

            $player = $players[$player_index];
            $player_name = $player['first_name'] . ' ' . $player['last_name'];

            // Calculate age from DOB
            $age = 'N/A';
            if (isset($player['dob']) && !empty($player['dob'])) {
                $dob = DateTime::createFromFormat('Y-m-d', $player['dob']);
                if ($dob) {
                    $current_date = new DateTime('2025-06-02'); // Current date as of June 2, 2025
                    $interval = $dob->diff($current_date);
                    $age = $interval->y;
                }
            }

            // Get gender
            $gender = isset($player['gender']) && !empty($player['gender']) ? ucfirst($player['gender']) : 'N/A';

            // Check if age and gender are already in metadata
            $existing_age = wc_get_order_item_meta($item->get_id(), 'Player Age', true);
            $existing_gender = wc_get_order_item_meta($item->get_id(), 'Player Gender', true);

            if (!$existing_age || !$existing_gender) {
                // Update order item metadata
                wc_update_order_item_meta($item->get_id(), 'Player Age', $age);
                wc_update_order_item_meta($item->get_id(), 'Player Gender', $gender);
                $updated_items++;
                $order_updated = true;
                error_log('InterSoccer: Migration - Updated Order Item ID ' . $item->get_id() . ' with Age: ' . $age . ', Gender: ' . $gender);
            }
        }

        if ($order_updated) {
            $updated_orders++;
            error_log('InterSoccer: Migration - Updated Order ID ' . $order->get_id());
        }
    }

    // Display notice
    add_action('admin_notices', function() use ($updated_orders, $updated_items) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(__('Migration completed: Updated %d orders and %d order items with player age and gender metadata.', 'intersoccer-reports-rosters'), $updated_orders, $updated_items); ?></p>
        </div>
        <?php
    });

    // Redirect to Overview page
    wp_redirect(admin_url('admin.php?page=intersoccer-overview'));
    exit;
}
?>
