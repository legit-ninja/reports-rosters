<?php
/**
 * Rosters page functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.3
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render the Rosters page.
 */
function intersoccer_render_rosters_page() {
    try {
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
        }

        intersoccer_log_audit('view_rosters', 'User accessed the Rosters page');

        $current_date = new DateTime(current_time('Y-m-d'));
        $current_date_str = $current_date->format('Y-m-d');

        $filters = [
            'region' => isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '',
            'venue' => isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '',
            'show_no_attendees' => isset($_GET['show_no_attendees']) ? sanitize_text_field($_GET['show_no_attendees']) : '',
        ];

        $camps = intersoccer_pe_get_camp_variations($filters);
        $courses = intersoccer_pe_get_course_variations($filters);
        $girls_only = intersoccer_pe_get_girls_only_variations($filters);

        if ($courses === null) {
            error_log('InterSoccer: Courses data is null, possible error in intersoccer_pe_get_course_variations');
            $courses = [];
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'courses';

        if (isset($_GET['action'])) {
            $format = isset($_GET['format']) && $_GET['format'] === 'excel' ? 'excel' : 'csv';
            if ($_GET['action'] === 'export_all_rosters' && check_admin_referer('export_all_rosters_nonce')) {
                intersoccer_export_all_rosters($camps, $courses, 'all', $format);
            } elseif ($_GET['action'] === 'export_camp_rosters' && check_admin_referer('export_camp_rosters_nonce')) {
                intersoccer_export_all_rosters($camps, [], 'camps', $format);
            } elseif ($_GET['action'] === 'export_course_rosters' && check_admin_referer('export_course_rosters_nonce')) {
                intersoccer_export_all_rosters([], $courses, 'courses', $format);
            } elseif ($_GET['action'] === 'export_girls_only_rosters' && check_admin_referer('export_girls_only_rosters_nonce')) {
                intersoccer_export_all_rosters($girls_only, [], 'girls_only', $format);
            }
        }

        $query_string = http_build_query(array_filter($filters, function($value) {
            return !empty($value);
        }));

        $normalize_attribute = function($value) {
            return trim(strtolower($value));
        };

        $query_args = [
            'post_type' => 'shop_order',
            'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
            'posts_per_page' => -1,
        ];
        $order_query = new WP_Query($query_args);
        $orders = $order_query->posts;
        error_log("InterSoccer: Initialized orders with " . count($orders) . " items");

        ?>
        <div class="wrap intersoccer-reports-rosters-rosters">
            <h1><?php _e('InterSoccer Rosters', 'intersoccer-reports-rosters'); ?></h1>

            <form id="roster-filter-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="intersoccer-rosters" />
                <input type="hidden" name="tab" value="<?php echo esc_attr($active_tab); ?>" />
                <div class="filter-section">
                    <h2><?php _e('Filter Rosters', 'intersoccer-reports-rosters'); ?></h2>
                    <div class="filter-row">
                        <label for="region"><?php _e('Region:', 'intersoccer-reports-rosters'); ?></label>
                        <select name="region" id="region">
                            <option value=""><?php _e('All Regions', 'intersoccer-reports-rosters'); ?></option>
                            <?php
                            $regions = get_terms(['taxonomy' => 'pa_canton-region', 'fields' => 'names']);
                            if (!is_wp_error($regions)) {
                                foreach ($regions as $region) {
                                    $normalized_region = $normalize_attribute($region);
                                    ?>
                                    <option value="<?php echo esc_attr($normalized_region); ?>" <?php selected($filters['region'], $normalized_region); ?>><?php echo esc_html($region); ?></option>
                                    <?php
                                }
                            }
                            ?>
                        </select>

                        <label for="venue"><?php _e('Venue:', 'intersoccer-reports-rosters'); ?></label>
                        <select name="venue" id="venue">
                            <option value=""><?php _e('All Venues', 'intersoccer-reports-rosters'); ?></option>
                            <?php
                            $venues = get_terms(['taxonomy' => 'pa_intersoccer-venues', 'fields' => 'names']);
                            if (!is_wp_error($venues)) {
                                foreach ($venues as $venue) {
                                    $normalized_venue = $normalize_attribute($venue);
                                    ?>
                                    <option value="<?php echo esc_attr($normalized_venue); ?>" <?php selected($filters['venue'], $normalized_venue); ?>><?php echo esc_html($venue); ?></option>
                                    <?php
                                }
                            }
                            ?>
                        </select>

                        <label for="show_no_attendees"><?php _e('Show Empty Rosters:', 'intersoccer-reports-rosters'); ?></label>
                        <input type="checkbox" name="show_no_attendees" id="show_no_attendees" value="1" <?php checked($filters['show_no_attendees'], '1'); ?> />
                    </div>

                    <div class="filter-row">
                        <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
                    </div>
                </div>
            </form>

            <div class="export-section">
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-rosters&action=export_all_rosters' . ($query_string ? '&' . $query_string : '') . '&format=excel'), 'export_all_rosters_nonce')); ?>" class="button button-primary"><?php _e('Export All Rosters to Excel', 'intersoccer-reports-rosters'); ?></a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-rosters&action=export_camp_rosters' . ($query_string ? '&' . $query_string : '') . '&format=excel'), 'export_camp_rosters_nonce')); ?>" class="button button-primary"><?php _e('Export All Camp Rosters to Excel', 'intersoccer-reports-rosters'); ?></a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-rosters&action=export_course_rosters' . ($query_string ? '&' . $query_string : '') . '&format=excel'), 'export_course_rosters_nonce')); ?>" class="button button-primary"><?php _e('Export All Course Rosters to Excel', 'intersoccer-reports-rosters'); ?></a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-rosters&action=export_girls_only_rosters' . ($query_string ? '&' . $query_string : '') . '&format=excel'), 'export_girls_only_rosters_nonce')); ?>" class="button button-primary"><?php _e('Export All Girls Only Rosters to Excel', 'intersoccer-reports-rosters'); ?></a>
            </div>

            <div class="tab-section">
                <ul class="nav-tab-wrapper">
                    <li class="nav-tab <?php echo $active_tab === 'courses' ? 'nav-tab-active' : ''; ?>">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-rosters&tab=courses')); ?>" class="tab-link"><?php _e('Courses', 'intersoccer-reports-rosters'); ?></a>
                    </li>
                    <li class="nav-tab <?php echo $active_tab === 'camps' ? 'nav-tab-active' : ''; ?>">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-rosters&tab=camps')); ?>" class="tab-link"><?php _e('Camps', 'intersoccer-reports-rosters'); ?></a>
                    </li>
                    <li class="nav-tab <?php echo $active_tab === 'girls_only' ? 'nav-tab-active' : ''; ?>">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-rosters&tab=girls_only')); ?>" class="tab-link"><?php _e('Girls Only', 'intersoccer-reports-rosters'); ?></a>
                    </li>
                </ul>

                <div id="courses-tab" class="tab-content" style="<?php echo $active_tab === 'courses' ? '' : 'display: none;'; ?>">
                    <h2><?php _e('Ongoing Courses Rosters', 'intersoccer-reports-rosters'); ?></h2>
                    <?php if (!empty($courses) && is_array($courses)): ?>
                        <?php foreach ($courses as $config_key => $config): ?>
                            <?php
                            $parts = explode('|', $config_key);
                            $venue = $parts[1] ?? 'Unknown Venue';
                            $variation_players = [];
                            foreach ($orders as $order_post) {
                                $order = wc_get_order($order_post->ID);
                                if ($order) {
                                    foreach ($order->get_items() as $item) {
                                        if (in_array($item->get_variation_id(), $config['variation_ids'])) {
                                            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true) ?: wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                                            if ($player_name && !in_array($player_name, $variation_players)) {
                                                $variation_players[$item->get_variation_id()][] = $player_name;
                                            }
                                        }
                                    }
                                }
                            }
                            ?>
                            <h3><?php echo esc_html($venue); ?></h3>
                            <div class="table-responsive">
                                <table class="widefat fixed">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Event Name', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><?php echo esc_html($config['product_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['region'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['venue'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['total_players'] ?? '0'); ?></td>
                                            <td>
                                                <?php if (!empty($config['variation_ids'])): ?>
                                                    <a href="<?php echo esc_url(add_query_arg(['variation_ids' => implode(',', $config['variation_ids']), 'context' => json_encode(['variation_players' => $variation_players])], admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . reset($config['variation_ids'])))); ?>" class="button"><?php _e('View Roster', 'intersoccer-reports-rosters'); ?></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p><?php _e('No ongoing course rosters found matching the selected filters.', 'intersoccer-reports-rosters'); ?></p>
                    <?php endif; ?>
                </div>

                <div id="camps-tab" class="tab-content" style="<?php echo $active_tab === 'camps' ? '' : 'display: none;'; ?>">
                    <h2><?php _e('Upcoming Camps Rosters', 'intersoccer-reports-rosters'); ?></h2>
                    <?php if (!empty($camps) && is_array($camps)): ?>
                        <?php foreach ($camps as $config_key => $config): ?>
                            <?php
                            $parts = explode('|', $config_key);
                            $venue = $parts[2] ?? 'Unknown Venue';
                            $variation_players = [];
                            foreach ($orders as $order_post) {
                                $order = wc_get_order($order_post->ID);
                                if ($order) {
                                    foreach ($order->get_items() as $item) {
                                        if (in_array($item->get_variation_id(), $config['variation_ids'])) {
                                            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true) ?: wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                                            if ($player_name && !in_array($player_name, $variation_players[$item->get_variation_id()] ?? [])) {
                                                $variation_players[$item->get_variation_id()][] = $player_name;
                                            }
                                        }
                                    }
                                }
                            }
                            $context = [
                                'variation_players' => $variation_players,
                                'camp_terms' => $config['camp_terms'],
                            ];
                            ?>
                            <h3><?php echo esc_html($venue); ?></h3>
                            <div class="table-responsive">
                                <table class="widefat fixed">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Event Name', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Camp Term', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><?php echo esc_html($config['product_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['camp_terms'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['region'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['venue'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['total_players'] ?? '0'); ?></td>
                                            <td>
                                                <?php if (!empty($config['variation_ids'])): ?>
                                                    <a href="<?php echo esc_url(add_query_arg(['variation_ids' => implode(',', $config['variation_ids']), 'context' => json_encode($context)], admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . reset($config['variation_ids'])))); ?>" class="button"><?php _e('View Roster', 'intersoccer-reports-rosters'); ?></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p><?php _e('No upcoming camp rosters found matching the selected filters.', 'intersoccer-reports-rosters'); ?></p>
                    <?php endif; ?>
                </div>

                <div id="girls_only-tab" class="tab-content" style="<?php echo $active_tab === 'girls_only' ? '' : 'display: none;'; ?>">
                    <h2><?php _e('Girls Only Rosters', 'intersoccer-reports-rosters'); ?></h2>
                    <?php if (!empty($girls_only) && is_array($girls_only)): ?>
                        <?php foreach ($girls_only as $config_key => $config): ?>
                            <?php
                            $parts = explode('|', $config_key);
                            $venue = $parts[1] ?? 'Unknown Venue';
                            $variation_players = [];
                            foreach ($orders as $order_post) {
                                $order = wc_get_order($order_post->ID);
                                if ($order) {
                                    foreach ($order->get_items() as $item) {
                                        if (in_array($item->get_variation_id(), $config['variation_ids'])) {
                                            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true) ?: wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                                            if ($player_name && !in_array($player_name, $variation_players)) {
                                                $variation_players[$item->get_variation_id()][] = $player_name;
                                            }
                                        }
                                    }
                                }
                            }
                            ?>
                            <h3><?php echo esc_html($venue); ?></h3>
                            <div class="table-responsive">
                                <table class="widefat fixed">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Event Name', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                                            <th><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><?php echo esc_html($config['product_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['region'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['venue'] ?? 'N/A'); ?></td>
                                            <td><?php echo esc_html($config['total_players'] ?? '0'); ?></td>
                                            <td>
                                                <?php if (!empty($config['variation_ids'])): ?>
                                                    <a href="<?php echo esc_url(add_query_arg(['variation_ids' => implode(',', $config['variation_ids']), 'context' => json_encode(['variation_players' => $variation_players])], admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . reset($config['variation_ids'])))); ?>" class="button"><?php _e('View Roster', 'intersoccer-reports-rosters'); ?></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p><?php _e('No girls only rosters found matching the selected filters.', 'intersoccer-reports-rosters'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        wp_reset_postdata(); // Ensure post data is reset
        error_log('InterSoccer: Rendered Rosters page');
    } catch (Exception $e) {
        error_log('InterSoccer: Error rendering rosters page: ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the rosters page.', 'intersoccer-reports-rosters'));
    }
}

/**
 * Render the Roster Details page for a specific variation or group of variations.
 */
function intersoccer_render_roster_details_page() {
    try {
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
        }

        $variation_id = isset($_GET['variation_id']) ? intval($_GET['variation_id']) : 0;
        $variation_ids = isset($_GET['variation_ids']) ? explode(',', sanitize_text_field($_GET['variation_ids'])) : [$variation_id];
        if (empty($variation_ids)) {
            wp_die(__('Invalid variation ID(s).', 'intersoccer-reports-rosters'));
        }

        $context_json = isset($_GET['context']) ? json_decode(urldecode($_GET['context']), true) : [];
        $context = is_array($context_json) ? $context_json : (isset($_GET['variation_players']) ? ['variation_players' => json_decode(urldecode($_GET['variation_players']), true)] : []);
        error_log("InterSoccer: Roster details context for variation IDs " . implode(',', $variation_ids) . ": " . print_r($context, true));

        if (isset($_GET['action']) && check_admin_referer('export-roster_nonce')) {
            $format = isset($_GET['format']) && $_GET['format'] === 'excel' ? 'excel' : 'csv';
            intersoccer_export_roster($variation_ids, $format, $context);
            exit;
        }

        $roster = intersoccer_pe_get_event_roster_by_variation($variation_ids, $context);
        $is_camp = !empty($roster) && in_array($roster[0]['booking_type'] ?? '', ['Full Week', 'single-days']);

        $variation = wc_get_product($variation_id);
        $product_id = $variation ? $variation->get_parent_id() : 0;
        $product = $product_id ? wc_get_product($product_id) : null;
        $event_name = $product ? $product->get_name() : 'Unknown Event';
        $region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
        $venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
        intersoccer_log_audit('view_roster_details', "User viewed roster details for Variation ID(s) " . implode(',', $variation_ids));

        ?>
        <div class="wrap intersoccer-reports-rosters-roster-details">
            <h1><?php printf(__('Roster for %s (Variation ID(s) %s)', 'intersoccer-reports-rosters'), esc_html($event_name), implode(', ', $variation_ids)); ?></h1>
            <p>
                <strong><?php _e('Region:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html($region); ?><br>
                <strong><?php _e('Venue:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html($venue); ?>
            </p>

            <div class="export-section">
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['variation_ids' => implode(',', $variation_ids), 'context' => urlencode($_GET['context'] ?? '')], admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . $variation_id . '&action=export_csv&format=excel')), 'export-roster_nonce')); ?>" class="button button-primary"><?php _e('Export to Excel', 'intersoccer-reports-rosters'); ?></a>
            </div>

            <?php if (!empty($roster)): ?>
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('First Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Last Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Gender', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Parent Phone', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Parent Email', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Medical/Dietary', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Late Pick-Up (18h)', 'intersoccer-reports-rosters'); ?></th>
                            <?php if ($is_camp): ?>
                                <th><?php _e('Selected Days', 'intersoccer-reports-rosters'); ?></th>
                            <?php else: ?>
                                <th><?php _e('Discount Info', 'intersoccer-reports-rosters'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roster as $player): ?>
                            <tr>
                                <td><?php echo esc_html($player['first_name'] ?? 'N/A'); ?></td>
                                <td><?php echo esc_html($player['last_name'] ?? 'N/A'); ?></td>
                                <td><?php echo esc_html($player['gender'] ?? 'N/A'); ?></td>
                                <td><?php echo esc_html($player['parent_phone'] ?? 'N/A'); ?></td>
                                <td><?php echo esc_html($player['parent_email'] ?? 'N/A'); ?></td>
                                <td><?php echo wp_kses_post($player['medical_conditions'] ?? 'None'); ?></td>
                                <td><?php echo esc_html($player['late_pickup'] === '18h' ? __('Yes', 'intersoccer-reports-rosters') : __('No', 'intersoccer-reports-rosters')); ?></td>
                                <?php if ($is_camp): ?>
                                    <td><?php echo esc_html(implode(', ', $player['selected_days'] ?? [])); ?></td>
                                <?php else: ?>
                                    <td><?php echo esc_html($player['discount_info'] ?? 'None'); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('No players found for this event.', 'intersoccer-reports-rosters'); ?></p>
            <?php endif; ?>

            <p><a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-rosters')); ?>" class="button"><?php _e('Back to Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        </div>
        <?php
        error_log('InterSoccer: Rendered Roster Details page for Variation ID(s) ' . implode(',', $variation_ids));
    } catch (Exception $e) {
        error_log('InterSoccer: Error rendering roster details page: ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the roster details page.', 'intersoccer-reports-rosters'));
    }
}
?>
