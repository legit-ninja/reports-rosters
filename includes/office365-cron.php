<?php
/**
 * Office 365 scheduled sync: cron hook and runner.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') || exit;

use InterSoccer\ReportsRosters\Office365\SyncService;

/** Cron hook name for Office 365 scheduled sync */
const INTERSOCCER_OFFICE365_CRON_HOOK = 'intersoccer_office365_scheduled_sync';

/** Option key for last sync result */
const INTERSOCCER_OFFICE365_LAST_SYNC_OPTION = 'intersoccer_office365_last_sync';

/**
 * Schedule or unschedule the Office 365 sync cron based on settings.
 *
 * @param array $settings Office 365 settings (e.g. from SyncService::getStoredSettings()).
 */
function intersoccer_office365_schedule_cron(array $settings) {
    $hook = INTERSOCCER_OFFICE365_CRON_HOOK;
    wp_clear_scheduled_hook($hook);

    if (empty($settings['auto_sync_enabled'])) {
        return;
    }

    $recurrence = isset($settings['auto_sync_schedule']) && $settings['auto_sync_schedule'] === 'weekly' ? 'weekly' : 'daily';
    if ($recurrence === 'weekly' && !isset(wp_get_schedules()['weekly'])) {
        add_filter('cron_schedules', function ($schedules) {
            $schedules['weekly'] = ['interval' => 604800, 'display' => __('Once Weekly', 'intersoccer-reports-rosters')];
            return $schedules;
        });
    }
    wp_schedule_event(time(), $recurrence, $hook);
}

/**
 * Run the scheduled Office 365 sync: generate configured exports and upload each.
 */
function intersoccer_office365_run_scheduled_sync() {
    $settings = SyncService::getStoredSettings();
    if (empty($settings['enabled']) || empty($settings['auto_sync_enabled'])) {
        return;
    }

    $jobs = isset($settings['auto_sync_jobs']) && is_array($settings['auto_sync_jobs'])
        ? $settings['auto_sync_jobs']
        : [];
    if (empty($jobs)) {
        update_option(INTERSOCCER_OFFICE365_LAST_SYNC_OPTION, [
            'time' => time(),
            'success' => true,
            'message' => __('No jobs configured.', 'intersoccer-reports-rosters'),
        ]);
        return;
    }

    $plugin_root = dirname(__DIR__);
    $service = new SyncService();
    $errors = [];
    $uploaded = 0;

    if (in_array('booking_report', $jobs, true) && !function_exists('intersoccer_office365_generate_booking_report_xlsx')) {
        require_once $plugin_root . '/includes/reports.php';
    }
    foreach ($jobs as $job) {
        if ($job === 'final_reports') {
            if (!function_exists('intersoccer_office365_generate_final_reports_xlsx')) {
                require_once $plugin_root . '/includes/reports-export.php';
            }
            $year = (int) date('Y');
            foreach (['Camp', 'Course'] as $activity_type) {
                $result = intersoccer_office365_generate_final_reports_xlsx($year, $activity_type, null, null);
                if ($result) {
                    $filename = preg_replace('/\.xlsx$/', '_' . date('Y-m-d') . '.xlsx', $result['filename']);
                    $upload = $service->uploadFile($filename, $result['content']);
                    if (!empty($upload['success'])) {
                        $uploaded++;
                    } else {
                        $errors[] = $filename . ': ' . (isset($upload['error']) ? $upload['error'] : 'Upload failed');
                    }
                }
            }
        } elseif ($job === 'booking_report') {
            $end_date = date('Y-m-d');
            $start_date = date('Y-m-d', strtotime('-30 days'));
            $year = (int) date('Y');
            if (function_exists('intersoccer_office365_generate_booking_report_xlsx')) {
                $result = intersoccer_office365_generate_booking_report_xlsx($start_date, $end_date, $year);
                if ($result) {
                    $upload = $service->uploadFile($result['filename'], $result['content']);
                    if (!empty($upload['success'])) {
                        $uploaded++;
                    } else {
                        $errors[] = $result['filename'] . ': ' . (isset($upload['error']) ? $upload['error'] : 'Upload failed');
                    }
                }
            } else {
                $errors[] = 'Booking report generator not available.';
            }
        } elseif ($job === 'roster_master') {
            if (function_exists('intersoccer_office365_generate_roster_master_xlsx')) {
                $result = intersoccer_office365_generate_roster_master_xlsx();
                if ($result) {
                    $upload = $service->uploadFile($result['filename'], $result['content']);
                    if (!empty($upload['success'])) {
                        $uploaded++;
                    } else {
                        $errors[] = $result['filename'] . ': ' . (isset($upload['error']) ? $upload['error'] : 'Upload failed');
                    }
                }
            } else {
                $errors[] = 'Master roster generator not available.';
            }
        }
    }

    $message = sprintf(
        __('Uploaded %d file(s).', 'intersoccer-reports-rosters'),
        $uploaded
    );
    if (!empty($errors)) {
        $message .= ' ' . __('Errors:', 'intersoccer-reports-rosters') . ' ' . implode('; ', $errors);
    }

    update_option(INTERSOCCER_OFFICE365_LAST_SYNC_OPTION, [
        'time' => time(),
        'success' => empty($errors),
        'message' => $message,
    ]);
}
