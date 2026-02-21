<?php
/**
 * Office 365 Sync settings page and AJAX handlers.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

use InterSoccer\ReportsRosters\Office365\SyncService;

/**
 * Handle POST save for Office 365 settings. Call on Settings page when tab=office365.
 *
 * @return bool True if settings were saved, false otherwise.
 */
function intersoccer_office365_maybe_save_settings(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['intersoccer_office365_nonce'])
        || !wp_verify_nonce(sanitize_text_field($_POST['intersoccer_office365_nonce']), 'intersoccer_office365_save')) {
        return false;
    }
    $settings = intersoccer_office365_sanitize_settings($_POST);
    update_option(SyncService::OPTION_NAME, $settings);
    SyncService::clearTokenCache();
    if (function_exists('intersoccer_office365_schedule_cron')) {
        intersoccer_office365_schedule_cron($settings);
    }
    return true;
}

/**
 * Render Office 365 Sync form content (for standalone page or Settings tab).
 *
 * @param string $form_action Form action URL; empty for same page.
 */
function intersoccer_render_office365_settings_tab_content(string $form_action = ''): void {
    $settings = SyncService::getStoredSettings();
    $destination = $settings['destination_type'] ?? SyncService::DESTINATION_ONEDRIVE;
    $form_action_attr = $form_action !== '' ? ' action="' . esc_attr($form_action) . '"' : '';
    ?>
        <p class="description" style="max-width: 640px;">
            <?php esc_html_e('Automatically upload roster and report Excel files to OneDrive or SharePoint when you export, or on a schedule. Follow the steps below to connect your Microsoft 365 account.', 'intersoccer-reports-rosters'); ?>
        </p>

        <form method="post"<?php echo $form_action_attr; ?> id="intersoccer-office365-form">
            <?php wp_nonce_field('intersoccer_office365_save', 'intersoccer_office365_nonce'); ?>

            <h3 class="title" style="margin-top: 24px; margin-bottom: 8px;"><?php esc_html_e('Step 1: Connect to Microsoft 365', 'intersoccer-reports-rosters'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable sync', 'intersoccer-reports-rosters'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="office365_enabled" value="1" <?php checked(!empty($settings['enabled'])); ?> />
                            <?php esc_html_e('Enable Office 365 sync for exports', 'intersoccer-reports-rosters'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="office365_tenant_id"><?php esc_html_e('Azure AD Tenant ID', 'intersoccer-reports-rosters'); ?></label></th>
                    <td>
                        <input type="text" id="office365_tenant_id" name="office365_tenant_id" value="<?php echo esc_attr($settings['tenant_id'] ?? ''); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Found on the app\'s Overview page as Directory (tenant) ID.', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="office365_client_id"><?php esc_html_e('Application (client) ID', 'intersoccer-reports-rosters'); ?></label></th>
                    <td>
                        <input type="text" id="office365_client_id" name="office365_client_id" value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('On the same Overview page as Application (client) ID.', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="office365_client_secret"><?php esc_html_e('Client secret', 'intersoccer-reports-rosters'); ?></label></th>
                    <td>
                        <input type="password" id="office365_client_secret" name="office365_client_secret" value="" class="regular-text" autocomplete="off" placeholder="<?php echo !empty($settings['client_secret']) ? '••••••••' : ''; ?>" />
                        <p class="description"><?php esc_html_e('Leave blank to keep existing secret. Under Certificates & secrets → New client secret; copy the Value immediately (it\'s only shown once). Required: Files.ReadWrite.All and Sites.ReadWrite.All (application permissions) with admin consent.', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top: 16px;">
                        <h3 class="title" style="margin: 0 0 8px 0;"><?php esc_html_e('Step 2: Choose where to sync', 'intersoccer-reports-rosters'); ?></h3>
                        <p class="description" style="margin-bottom: 8px;"><?php esc_html_e('Use OneDrive for a user\'s personal folder; use SharePoint for a team site document library.', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Destination', 'intersoccer-reports-rosters'); ?></th>
                    <td>
                        <label><input type="radio" name="office365_destination_type" value="<?php echo esc_attr(SyncService::DESTINATION_ONEDRIVE); ?>" <?php checked($destination, SyncService::DESTINATION_ONEDRIVE); ?> /> <?php esc_html_e('OneDrive', 'intersoccer-reports-rosters'); ?></label>
                        &nbsp;
                        <label><input type="radio" name="office365_destination_type" value="<?php echo esc_attr(SyncService::DESTINATION_SHAREPOINT); ?>" <?php checked($destination, SyncService::DESTINATION_SHAREPOINT); ?> /> <?php esc_html_e('SharePoint', 'intersoccer-reports-rosters'); ?></label>
                    </td>
                </tr>
                <tr class="office365-onedrive-fields" style="<?php echo $destination !== SyncService::DESTINATION_ONEDRIVE ? 'display:none' : ''; ?>">
                    <th scope="row"><label for="office365_onedrive_user_id"><?php esc_html_e('OneDrive: User ID or UPN', 'intersoccer-reports-rosters'); ?></label></th>
                    <td>
                        <input type="text" id="office365_onedrive_user_id" name="office365_onedrive_user_id" value="<?php echo esc_attr($settings['onedrive_user_id'] ?? ''); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('User whose OneDrive to use (e.g. user@tenant.onmicrosoft.com or object ID)', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <tr class="office365-onedrive-fields" style="<?php echo $destination !== SyncService::DESTINATION_ONEDRIVE ? 'display:none' : ''; ?>">
                    <th scope="row"><label for="office365_onedrive_folder_path"><?php esc_html_e('OneDrive: Folder path', 'intersoccer-reports-rosters'); ?></label></th>
                    <td>
                        <input type="text" id="office365_onedrive_folder_path" name="office365_onedrive_folder_path" value="<?php echo esc_attr($settings['onedrive_folder_path'] ?? ''); ?>" class="regular-text" placeholder="Reports/InterSoccer" />
                        <p class="description"><?php esc_html_e('Optional subfolder (e.g. Reports/InterSoccer). Leave empty for root.', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <tr class="office365-sharepoint-fields" style="<?php echo $destination !== SyncService::DESTINATION_SHAREPOINT ? 'display:none' : ''; ?>">
                    <th scope="row"><label for="office365_sharepoint_site_url"><?php esc_html_e('SharePoint: Site URL', 'intersoccer-reports-rosters'); ?></label></th>
                    <td>
                        <input type="url" id="office365_sharepoint_site_url" name="office365_sharepoint_site_url" value="<?php echo esc_attr($settings['sharepoint_site_url'] ?? ''); ?>" class="large-text" placeholder="https://tenant.sharepoint.com/sites/InterSoccer" />
                    </td>
                </tr>
                <tr class="office365-sharepoint-fields" style="<?php echo $destination !== SyncService::DESTINATION_SHAREPOINT ? 'display:none' : ''; ?>">
                    <th scope="row"><label for="office365_sharepoint_folder_path"><?php esc_html_e('SharePoint: Folder path', 'intersoccer-reports-rosters'); ?></label></th>
                    <td>
                        <input type="text" id="office365_sharepoint_folder_path" name="office365_sharepoint_folder_path" value="<?php echo esc_attr($settings['sharepoint_folder_path'] ?? ''); ?>" class="regular-text" placeholder="Reports/Rosters" />
                        <p class="description"><?php esc_html_e('Path within the default document library (e.g. Reports/Rosters)', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top: 16px;">
                        <h3 class="title" style="margin: 0 0 8px 0;"><?php esc_html_e('Step 3: Optional – scheduled sync', 'intersoccer-reports-rosters'); ?></h3>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Automatic sync', 'intersoccer-reports-rosters'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="office365_auto_sync_enabled" value="1" <?php checked(!empty($settings['auto_sync_enabled'])); ?> />
                            <?php esc_html_e('Run scheduled sync', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Export and upload configured reports/rosters on a schedule. You can still use Export and sync on each report/roster page without enabling this.', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="office365_auto_sync_schedule"><?php esc_html_e('Schedule', 'intersoccer-reports-rosters'); ?></label></th>
                    <td>
                        <select id="office365_auto_sync_schedule" name="office365_auto_sync_schedule">
                            <option value="daily" <?php selected($settings['auto_sync_schedule'] ?? 'daily', 'daily'); ?>><?php esc_html_e('Daily', 'intersoccer-reports-rosters'); ?></option>
                            <option value="weekly" <?php selected($settings['auto_sync_schedule'] ?? '', 'weekly'); ?>><?php esc_html_e('Weekly', 'intersoccer-reports-rosters'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Export jobs (scheduled)', 'intersoccer-reports-rosters'); ?></th>
                    <td>
                        <?php
                        $job_options = [
                            'final_reports' => __('Final reports (current year, Camp + Course)', 'intersoccer-reports-rosters'),
                            'booking_report' => __('Booking report (last 30 days)', 'intersoccer-reports-rosters'),
                            'roster_master' => __('Master roster (all activities)', 'intersoccer-reports-rosters'),
                        ];
                        $saved_jobs = $settings['auto_sync_jobs'] ?? [];
                        if (!is_array($saved_jobs)) {
                            $saved_jobs = [];
                        }
                        foreach ($job_options as $job_key => $label) {
                            $checked = in_array($job_key, $saved_jobs, true);
                            ?>
                            <label style="display:block; margin-bottom:6px;">
                                <input type="checkbox" name="office365_auto_sync_jobs[]" value="<?php echo esc_attr($job_key); ?>" <?php checked($checked); ?> />
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php } ?>
                        <p class="description"><?php esc_html_e('When automatic sync runs, selected exports are generated and uploaded to the destination.', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <?php
                $last_sync = get_option('intersoccer_office365_last_sync', null);
                if (is_array($last_sync) && !empty($last_sync)) {
                    ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Last scheduled sync', 'intersoccer-reports-rosters'); ?></th>
                    <td>
                        <p><?php echo esc_html(sprintf(
                            __('Run at %s. Success: %s. %s', 'intersoccer-reports-rosters'),
                            isset($last_sync['time']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync['time']) : '—',
                            !empty($last_sync['success']) ? __('Yes', 'intersoccer-reports-rosters') : __('No', 'intersoccer-reports-rosters'),
                            isset($last_sync['message']) ? $last_sync['message'] : ''
                        )); ?></p>
                    </td>
                </tr>
                <?php } ?>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save settings', 'intersoccer-reports-rosters'); ?></button>
                <button type="button" id="intersoccer-office365-test" class="button"><?php esc_html_e('Test connection', 'intersoccer-reports-rosters'); ?></button>
            </p>
        </form>

        <div id="intersoccer-office365-test-result" style="margin-top:12px;"></div>

        <hr style="margin: 24px 0 16px 0;" />
        <h2 style="margin-bottom: 8px;"><?php esc_html_e('Setup instructions', 'intersoccer-reports-rosters'); ?></h2>
        <details class="intersoccer-office365-setup-details" style="margin-bottom: 16px;">
            <summary style="cursor: pointer; font-weight: 600;"><?php esc_html_e('Show step-by-step setup instructions', 'intersoccer-reports-rosters'); ?></summary>
            <ol style="margin: 12px 0 0 20px; padding: 0; max-width: 640px;">
                <li style="margin-bottom: 10px;"><strong><?php esc_html_e('Create an app in Azure', 'intersoccer-reports-rosters'); ?></strong><br />
                    <?php esc_html_e('Go to Azure Portal → Microsoft Entra ID (or Azure Active Directory) → App registrations → New registration. Name it (e.g. "InterSoccer Reports Sync"), leave defaults, Register.', 'intersoccer-reports-rosters'); ?>
                </li>
                <li style="margin-bottom: 10px;"><strong><?php esc_html_e('Note the IDs', 'intersoccer-reports-rosters'); ?></strong><br />
                    <?php esc_html_e('On the app\'s Overview: copy Directory (tenant) ID and Application (client) ID into the form (Step 1).', 'intersoccer-reports-rosters'); ?>
                </li>
                <li style="margin-bottom: 10px;"><strong><?php esc_html_e('Create a client secret', 'intersoccer-reports-rosters'); ?></strong><br />
                    <?php esc_html_e('Certificates & secrets → New client secret → add description, expiry, Add → copy the Value once (it\'s hidden later) into Client secret.', 'intersoccer-reports-rosters'); ?>
                </li>
                <li style="margin-bottom: 10px;"><strong><?php esc_html_e('Grant permissions', 'intersoccer-reports-rosters'); ?></strong><br />
                    <?php esc_html_e('API permissions → Add permission → Microsoft Graph → Application permissions → add Files.ReadWrite.All and Sites.ReadWrite.All → Grant admin consent for your organisation.', 'intersoccer-reports-rosters'); ?>
                </li>
                <li style="margin-bottom: 10px;"><strong><?php esc_html_e('Choose destination', 'intersoccer-reports-rosters'); ?></strong><br />
                    <?php esc_html_e('OneDrive: use the user\'s email (UPN) or Object ID whose OneDrive should receive files. SharePoint: use the site\'s full URL (e.g. https://tenant.sharepoint.com/sites/YourSite).', 'intersoccer-reports-rosters'); ?>
                </li>
            </ol>
            <p style="margin: 12px 0 0 0; font-size: 13px;">
                <?php
                printf(
                    /* translators: %s: URL to Microsoft docs */
                    esc_html__('For more detail, see %s.', 'intersoccer-reports-rosters'),
                    '<a href="https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app" target="_blank" rel="noopener noreferrer">' . esc_html__('Register an application with the Microsoft identity platform', 'intersoccer-reports-rosters') . '</a>'
                );
                ?>
            </p>
        </details>

    <script>
    jQuery(function($) {
        $('input[name="office365_destination_type"]').on('change', function() {
            var dest = $(this).val();
            $('.office365-onedrive-fields').toggle(dest === 'onedrive');
            $('.office365-sharepoint-fields').toggle(dest === 'sharepoint');
        });

        $('#intersoccer-office365-test').on('click', function() {
            var $btn = $(this);
            var $result = $('#intersoccer-office365-test-result');
            $btn.prop('disabled', true);
            $result.html('<span class="spinner is-active" style="float:none;"></span> Testing...').show();

            $.post(ajaxurl, {
                action: 'intersoccer_office365_test_connection',
                nonce: '<?php echo esc_js(wp_create_nonce('intersoccer_office365_test')); ?>'
            }).done(function(r) {
                if (r.success) {
                    var msg = '<?php echo esc_js(__('Connection successful. You can now use Export and sync (or Also sync to Office 365) when exporting rosters and reports.', 'intersoccer-reports-rosters')); ?>';
                    $result.html('<p class="notice notice-success">' + msg + '</p>');
                } else {
                    $result.html('<p class="notice notice-error">' + (r.data && r.data.message ? r.data.message : 'Test failed') + '</p>');
                }
            }).fail(function() {
                $result.html('<p class="notice notice-error">Request failed.</p>');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
    });
    </script>
    <?php
}

/**
 * Render full Office 365 Sync settings page (standalone; used if linked directly).
 */
function intersoccer_render_office365_settings_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'intersoccer-reports-rosters'));
    }
    if (intersoccer_office365_maybe_save_settings()) {
        echo '<div class="notice notice-success"><p>' . esc_html__('Office 365 settings saved.', 'intersoccer-reports-rosters') . '</p></div>';
    }
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Office 365 Sync', 'intersoccer-reports-rosters') . '</h1>';
    intersoccer_render_office365_settings_tab_content('');
    echo '</div>';
}

/**
 * Sanitize and merge POST data into settings array.
 *
 * @param array $post
 * @return array
 */
function intersoccer_office365_sanitize_settings(array $post): array {
    $current = SyncService::getStoredSettings();
    $secret = isset($post['office365_client_secret']) && $post['office365_client_secret'] !== ''
        ? sanitize_text_field($post['office365_client_secret'])
        : ($current['client_secret'] ?? '');

    return [
        'enabled' => !empty($post['office365_enabled']),
        'tenant_id' => isset($post['office365_tenant_id']) ? sanitize_text_field($post['office365_tenant_id']) : '',
        'client_id' => isset($post['office365_client_id']) ? sanitize_text_field($post['office365_client_id']) : '',
        'client_secret' => $secret,
        'destination_type' => isset($post['office365_destination_type']) && $post['office365_destination_type'] === SyncService::DESTINATION_SHAREPOINT
            ? SyncService::DESTINATION_SHAREPOINT
            : SyncService::DESTINATION_ONEDRIVE,
        'onedrive_user_id' => isset($post['office365_onedrive_user_id']) ? sanitize_text_field($post['office365_onedrive_user_id']) : '',
        'onedrive_folder_path' => isset($post['office365_onedrive_folder_path']) ? sanitize_text_field($post['office365_onedrive_folder_path']) : '',
        'sharepoint_site_url' => isset($post['office365_sharepoint_site_url']) ? esc_url_raw(trim($post['office365_sharepoint_site_url'])) : '',
        'sharepoint_folder_path' => isset($post['office365_sharepoint_folder_path']) ? sanitize_text_field($post['office365_sharepoint_folder_path']) : '',
        'auto_sync_enabled' => !empty($post['office365_auto_sync_enabled']),
        'auto_sync_schedule' => isset($post['office365_auto_sync_schedule']) && $post['office365_auto_sync_schedule'] === 'weekly' ? 'weekly' : 'daily',
        'auto_sync_jobs' => isset($post['office365_auto_sync_jobs']) && is_array($post['office365_auto_sync_jobs'])
            ? array_values(array_intersect(array_map('sanitize_text_field', $post['office365_auto_sync_jobs']), ['final_reports', 'booking_report', 'roster_master']))
            : ($current['auto_sync_jobs'] ?? []),
    ];
}

/**
 * AJAX: Test Office 365 connection.
 */
function intersoccer_office365_ajax_test_connection() {
    check_ajax_referer('intersoccer_office365_test', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
    }

    $settings = SyncService::getStoredSettings();
    $service = new SyncService($settings);
    $result = $service->testConnection(true);
    if ($result['success']) {
        wp_send_json_success(['message' => $result['message']]);
    }
    wp_send_json_error(['message' => $result['message']]);
}

add_action('wp_ajax_intersoccer_office365_test_connection', 'intersoccer_office365_ajax_test_connection');
