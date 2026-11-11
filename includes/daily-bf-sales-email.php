<?php
/**
 * Daily sales email for a coupon window.
 *
 * Enable is off by default. Recipients come only from the Send to setting.
 * Invalid addresses in that list are dropped. The email goes to the valid
 * ones. Nothing is sent when no valid address remains.
 * The hourly check is scheduled only while Enable is on. Turning Enable off,
 * or deactivating the plugin, clears that event. A plain load with Enable off
 * does not schedule it. The window starts at 00:00 Europe/Zurich on the
 * campaign start date and ends on the WooCommerce coupon expiry. There is
 * no coupon start date. The morning after the last day is the final campaign
 * email. A coupon that expires at 00:00 Europe/Zurich ends on the previous
 * calendar day. The final email reports that day's paid sales and the running
 * total for the whole campaign (campaign start through that day). After that
 * send, nothing more goes out for the campaign. A test send goes to one typed
 * address and does not mark the day. It does not depend on the cron event.
 * A sample send uses that same address and the same HTML renderer on a
 * fixed fake dataset, with [SAMPLE] in the subject and a sample banner.
 * It does not read shop data, does not use the Send to list, and does
 * not mark the day.
 *
 * Paid totals count processing and completed orders whose paid date falls in
 * the Europe/Zurich window. On-hold orders are counted separately by the date
 * they were created and are not added in. Orders with no paid date are omitted
 * from the paid totals.
 *
 * Low and zero booking lists cover published Winter, Spring, and Easter 2027
 * programmes that have not started. Counts include every paid booking, of any
 * date. The low threshold is an admin setting and defaults to 3. Zero bookings
 * are a separate list.
 *
 * @package InterSoccer\ReportsRosters\Services
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Campaign\FacetNormalizer;
use InterSoccer\ReportsRosters\Core\Logger;

defined('ABSPATH') or die('Restricted access');

class DailyBfSalesEmail {

    const CRON_HOOK = 'intersoccer_rr_daily_bf_sales_email';
    const PAGE_SLUG = 'intersoccer-daily-bf-sales-email';
    const OPTION_SETTINGS = 'intersoccer_rr_daily_bf_sales_settings';
    const OPTION_STATE = 'intersoccer_rr_daily_bf_sales_state';
    const TIMEZONE = 'Europe/Zurich';
    const NONCE_SETTINGS = 'intersoccer_rr_daily_bf_sales_settings';
    const NONCE_TEST = 'intersoccer_rr_daily_bf_sales_test';

    const FOOTER_PAID_DATE = 'Figures use the paid date in Europe/Zurich.';
    const FINAL_CAMPAIGN_LINE = 'Final campaign totals';
    const SAMPLE_BANNER = 'Sample data, not real sales.';
    const PAID_ORDER_STATUSES = ['wc-processing', 'wc-completed'];
    const PAID_ORDER_STATUSES_FILTER = 'intersoccer_rr_daily_bf_paid_order_statuses';

    /**
     * Composite season×type labels (for example Winter Courses, Easter Camps).
     * Set this to false for a separate season table and a separate type table.
     */
    const COMPOSITE_SEASON_TYPE_LABELS = true;
    const COMPOSITE_SEASON_TYPE_FILTER = 'intersoccer_rr_daily_bf_composite_season_type_labels';
    const ATTENTION_ORDER_STATUSES = ['wc-failed', 'wc-pending'];
    const ATTENTION_ORDER_STATUSES_FILTER = 'intersoccer_rr_daily_bf_attention_order_statuses';
    const DEFAULT_LOW_BOOKING_THRESHOLD = 3;
    const LOW_BOOKING_YEAR = '2027';

    /**
     * @var self|null
     */
    private static $instance = null;

    /**
     * @var bool
     */
    private static $hooksRegistered = false;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var FinancialReportService|null
     */
    private $reports;

    /**
     * @var callable|null
     */
    private $couponLoader;

    /**
     * @var \DateTimeZone|null
     */
    private $siteTimezone;

    /**
     * @var FacetNormalizer|null
     */
    private $facets = null;

    /**
     * @param Logger|null                  $logger
     * @param FinancialReportService|null  $reports
     * @param callable|null                $couponLoader
     * @param \DateTimeZone|null           $siteTimezone Site timezone used to read order created dates.
     */
    public function __construct($logger = null, $reports = null, $couponLoader = null, $siteTimezone = null) {
        $this->logger = ($logger instanceof Logger) ? $logger : new Logger();
        $this->reports = ($reports instanceof FinancialReportService) ? $reports : null;
        $this->couponLoader = is_callable($couponLoader) ? $couponLoader : null;
        $this->siteTimezone = ($siteTimezone instanceof \DateTimeZone) ? $siteTimezone : null;
    }

    /**
     * @return self
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register the admin page, the cron callback, and settings listeners.
     * The hourly event is queued only when Enable is on.
     *
     * @return void
     */
    public static function register() {
        self::ensureScheduled();
        if (self::$hooksRegistered) {
            return;
        }
        self::$hooksRegistered = true;
        add_action(self::CRON_HOOK, [self::instance(), 'runScheduledFromCron']);
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_init', [self::class, 'maybeHandlePost']);
        add_action('update_option', [self::class, 'onOptionUpdated'], 10, 3);
        add_action('update_option_' . self::OPTION_SETTINGS, [self::class, 'onSettingsUpdated'], 10, 3);
    }

    /**
     * Schedule the hourly check when Enable is on and the event is not already queued.
     * Enable off leaves any existing event in place. Saving settings, or deactivating
     * the plugin, clears it. The Zurich clock decides the send.
     *
     * @return void
     */
    public static function ensureScheduled() {
        if (!self::storedEnabled()) {
            return;
        }
        self::scheduleHourly();
    }

    /**
     * Option writes pass the option name first. Ignore every option except settings.
     *
     * @param mixed $option
     * @param mixed $oldValue
     * @param mixed $value
     * @return void
     */
    public static function onOptionUpdated($option, $oldValue = null, $value = null) {
        if ((string) $option !== self::OPTION_SETTINGS) {
            return;
        }
        self::syncScheduleToValue($value);
    }

    /**
     * The settings option hook passes the new value second, after the option is stored.
     *
     * @param mixed $oldValue
     * @param mixed $value
     * @param mixed $option
     * @return void
     */
    public static function onSettingsUpdated($oldValue = null, $value = null, $option = '') {
        self::syncScheduleToValue($value);
    }

    /**
     * @return void
     */
    public static function unschedule() {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    /**
     * Queue the hourly event once. A second call leaves the existing event in place.
     *
     * @return void
     */
    private static function scheduleHourly() {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }
        $delay = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
        wp_schedule_event(time() + $delay, 'hourly', self::CRON_HOOK);
    }

    /**
     * @param mixed $value Saved settings array.
     * @return void
     */
    private static function syncScheduleToValue($value) {
        if (self::valueEnabled($value)) {
            self::scheduleHourly();
            return;
        }
        self::unschedule();
    }

    /**
     * @return bool
     */
    private static function storedEnabled() {
        return !empty(self::instance()->settings()['enabled']);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function valueEnabled($value) {
        return is_array($value) && !empty($value['enabled']);
    }

    /**
     * @return void
     */
    public static function registerMenu() {
        if (!function_exists('add_submenu_page')) {
            return;
        }
        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Daily sales email', 'intersoccer-reports-rosters'),
            __('Daily sales email', 'intersoccer-reports-rosters'),
            'manage_options',
            self::PAGE_SLUG,
            [self::instance(), 'renderPage']
        );
    }

    /**
     * @return void
     */
    public static function maybeHandlePost() {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG) {
            return;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
        if ($method !== 'POST') {
            return;
        }
        $post = isset($_POST) && is_array($_POST) ? wp_unslash($_POST) : [];
        $result = self::instance()->handleAdminPost($post);
        $url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'bf_notice' => $result['code'],
                'bf_detail' => $result['message'],
            ],
            admin_url('admin.php')
        );
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
            exit;
        }
    }

    /**
     * Cron entry. Uses the current Europe/Zurich clock.
     *
     * @return string
     */
    public function runScheduledFromCron() {
        return $this->runScheduled(new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)));
    }

    /**
     * Send the scheduled digest when this Zurich morning is inside the coupon window.
     *
     * One successful send is recorded per reported date. A later run the same morning
     * does not send again. Failures are not marked sent.
     *
     * @param \DateTimeImmutable|null $now
     * @return string Reason code.
     */
    public function runScheduled($now = null) {
        $now = $this->asZurich($now);
        $settings = $this->settings();
        if (!$settings['enabled']) {
            return 'disabled';
        }
        if (!self::isSendTimeReached($now, $settings['send_time'])) {
            return 'before_send_time';
        }
        $reportDate = self::yesterdayYmd($now);
        if ($this->wasSent($reportDate)) {
            return 'already_sent';
        }

        $couponStatus = $this->couponStatus($settings['coupon_code']);
        if ($couponStatus['coupon'] === null) {
            $this->logReason($couponStatus['reason']);
            $this->writeResult($couponStatus['reason']);
            return $couponStatus['reason'];
        }
        if (!self::zurichDayOverlapsWindow($reportDate, $couponStatus['coupon']['start_unix'], $couponStatus['coupon']['expires_unix'])) {
            $this->logReason('outside_window');
            $this->writeResult('outside_window');
            return 'outside_window';
        }

        $parsed = $this->parseRecipientList($settings['recipients']);
        if (!$parsed['ok']) {
            $this->logReason($parsed['reason']);
            $this->writeResult($parsed['reason']);
            return $parsed['reason'];
        }

        try {
            $digest = $this->buildDigest($now);
        } catch (\Throwable $e) {
            $this->logReason('report_failed');
            $this->writeResult('report_failed');
            return 'report_failed';
        }

        $delivery = $this->deliver($parsed['emails'], $this->subjectFor($digest), $this->renderHtml($digest));
        if (!$delivery['ok']) {
            $this->logReason('mail_failed');
            $this->writeResult('mail_failed');
            return 'mail_failed';
        }

        $this->markSent($reportDate, $now);
        $this->logReason('sent');
        return 'sent';
    }

    /**
     * Send yesterday's report to one address. Does not read the Send to list and
     * does not write the sent marker. Works when Enable is off and before the
     * coupon window opens.
     *
     * @param string                  $rawAddress
     * @param \DateTimeImmutable|null $now
     * @return array{ok:bool,code:string,message:string}
     */
    public function sendTest($rawAddress, $now = null) {
        $valid = $this->validateTestAddress($rawAddress);
        if (!$valid['ok']) {
            return [
                'ok' => false,
                'code' => 'test_invalid',
                'message' => $valid['message'],
            ];
        }
        $now = $this->asZurich($now);
        try {
            $digest = $this->buildDigest($now);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'code' => 'test_failed',
                'message' => 'Test email failed: the report could not be built.',
            ];
        }
        $delivery = $this->deliver(
            [$valid['email']],
            '[TEST] ' . $this->subjectFor($digest),
            $this->renderHtml($digest)
        );
        if (!$delivery['ok']) {
            $detail = $delivery['message'] !== '' ? $delivery['message'] : 'The mailer did not accept the message.';
            return [
                'ok' => false,
                'code' => 'test_failed',
                'message' => 'Test email failed: ' . $detail,
            ];
        }
        return [
            'ok' => true,
            'code' => 'test_sent',
            'message' => 'Test email sent.',
        ];
    }

    /**
     * Send a fixed fake digest to one address. Does not read orders, coupons,
     * products, or the Send to list, and does not write the sent marker.
     * Works when Enable is off. Does not schedule cron.
     *
     * @param string $rawAddress
     * @return array{ok:bool,code:string,message:string}
     */
    public function sendSample($rawAddress) {
        $valid = $this->validateTestAddress($rawAddress);
        if (!$valid['ok']) {
            return [
                'ok' => false,
                'code' => 'test_invalid',
                'message' => $valid['message'],
            ];
        }
        $digest = $this->sampleDigest();
        $delivery = $this->deliver(
            [$valid['email']],
            '[SAMPLE] ' . $this->subjectFor($digest),
            $this->renderSampleHtml($digest)
        );
        if (!$delivery['ok']) {
            $detail = $delivery['message'] !== '' ? $delivery['message'] : 'The mailer did not accept the message.';
            return [
                'ok' => false,
                'code' => 'sample_failed',
                'message' => 'Sample email failed: ' . $detail,
            ];
        }
        return [
            'ok' => true,
            'code' => 'sample_sent',
            'message' => 'Sample email sent.',
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array{ok:bool,code:string,message:string}
     */
    public function handleAdminPost(array $post) {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return [
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'You are not allowed to manage this email.',
            ];
        }
        if (isset($post['bf_test_send'])) {
            if (!$this->nonceOk(isset($post['_wpnonce']) ? (string) $post['_wpnonce'] : '', self::NONCE_TEST)) {
                return [
                    'ok' => false,
                    'code' => 'bad_nonce',
                    'message' => 'The request expired. Refresh the page and try again.',
                ];
            }
            $address = isset($post['bf_test_address']) ? (string) $post['bf_test_address'] : '';
            return $this->sendTest($address);
        }
        if (isset($post['bf_sample_send'])) {
            if (!$this->nonceOk(isset($post['_wpnonce']) ? (string) $post['_wpnonce'] : '', self::NONCE_TEST)) {
                return [
                    'ok' => false,
                    'code' => 'bad_nonce',
                    'message' => 'The request expired. Refresh the page and try again.',
                ];
            }
            $address = isset($post['bf_test_address']) ? (string) $post['bf_test_address'] : '';
            return $this->sendSample($address);
        }
        if (isset($post['bf_save'])) {
            if (!$this->nonceOk(isset($post['_wpnonce']) ? (string) $post['_wpnonce'] : '', self::NONCE_SETTINGS)) {
                return [
                    'ok' => false,
                    'code' => 'bad_nonce',
                    'message' => 'The request expired. Refresh the page and try again.',
                ];
            }
            return $this->saveSettings($post);
        }
        return [
            'ok' => false,
            'code' => 'ignored',
            'message' => '',
        ];
    }

    /**
     * @return void
     */
    public function renderPage() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            echo '<div class="wrap"><p>You are not allowed to manage this email.</p></div>';
            return;
        }
        $settings = $this->settings();
        $state = $this->state();
        $notice = isset($_GET['bf_notice']) ? sanitize_text_field(wp_unslash($_GET['bf_notice'])) : '';
        $detail = isset($_GET['bf_detail']) ? sanitize_text_field(wp_unslash($_GET['bf_detail'])) : '';
        $prefill = $this->currentUserEmail();
        $checked = !empty($settings['enabled']) ? ' checked="checked"' : '';
        echo '<div class="wrap">';
        echo '<h1>Daily sales email</h1>';
        if ($notice !== '' && $detail !== '') {
            $class = ($notice === 'test_sent' || $notice === 'sample_sent' || $notice === 'saved') ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($detail) . '</p></div>';
        }
        echo '<p>Enable is off until you turn it on. Recipients come only from Send to. Nothing is hardcoded.</p>';
        echo '<p>The email sends each morning at the chosen Europe/Zurich time while yesterday falls inside the campaign window. The window starts at 00:00 Europe/Zurich on the campaign start date and ends on the coupon expiry. The morning after the last day is the final campaign email. A coupon that expires at 00:00 Europe/Zurich ends on the previous calendar day. It reports that day\'s paid sales and the running total for the whole campaign (campaign start through that day), with Final in the subject. After that send, nothing more goes out for the campaign.</p>';
        foreach ($this->configurationWarnings() as $warning) {
            echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
        }
        echo '<p>Totals are net inc-VAT for processing and completed orders with a paid date in the window. On-hold orders are counted separately by the date they were created and are not included. Orders with no paid date are omitted from the paid totals.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">';
        $this->nonceField(self::NONCE_SETTINGS);
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Enable</th><td><label><input type="checkbox" name="bf_enabled" value="1"' . $checked . ' /> Send the daily email</label></td></tr>';
        echo '<tr><th scope="row"><label for="bf_recipients">Send to</label></th><td>';
        echo '<textarea name="bf_recipients" id="bf_recipients" rows="3" cols="50" class="large-text">' . esc_html($settings['recipients']) . '</textarea>';
        echo '<p class="description">Comma-separated email addresses. Empty entries are skipped. Invalid addresses are dropped, and the email sends to the valid ones. Nothing is sent when no valid address remains.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="bf_send_time">Send time</label></th><td>';
        echo '<input type="text" name="bf_send_time" id="bf_send_time" value="' . esc_attr($settings['send_time']) . '" pattern="[0-2][0-9]:[0-5][0-9]" />';
        echo '<p class="description">HH:MM on the Europe/Zurich clock. Daylight saving is handled by that timezone.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="bf_campaign_start">Campaign start date</label></th><td>';
        echo '<input type="date" name="bf_campaign_start" id="bf_campaign_start" value="' . esc_attr($settings['campaign_start']) . '" />';
        echo '<p class="description">First day of the window, saved as YYYY-MM-DD and read as 00:00 Europe/Zurich. Leave it empty to save without a start. The scheduled email does not send until a start date is set.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="bf_coupon_code">Coupon code</label></th><td>';
        echo '<input type="text" name="bf_coupon_code" id="bf_coupon_code" value="' . esc_attr($settings['coupon_code']) . '" class="regular-text" />';
        echo '<p class="description">The window ends on the WooCommerce coupon expiry (<code>date_expires</code>). WooCommerce does not store a coupon start date. A missing expiry means the scheduled email does not send. Dates are read in Europe/Zurich.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="bf_low_booking_threshold">Low booking threshold</label></th><td>';
        echo '<input type="number" name="bf_low_booking_threshold" id="bf_low_booking_threshold" min="0" max="999" value="' . esc_attr((string) (int) $settings['low_booking_threshold']) . '" />';
        echo '<p class="description">Published Winter, Spring, and Easter 2027 programmes that have not started. Paid bookings of any date, from 1 through this number, are listed as low. Zero paid bookings are a separate list. On-hold orders are not counted.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row">Last sent</th><td><p>' . esc_html($state['last_sent_at'] !== '' ? $state['last_sent_at'] . ' Europe/Zurich' : 'Never') . '</p></td></tr>';
        echo '<tr><th scope="row">Last result</th><td><p>' . esc_html($this->resultLabel($state['last_result'])) . '</p></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" name="bf_save" value="1" class="button button-primary">Save settings</button></p>';
        echo '</form>';

        echo '<hr />';
        echo '<h2>Send test email</h2>';
        echo '<p>Sends yesterday\'s report to one address, with [TEST] in the subject. It does not use the Send to list, does not require Enable, and does not mark the day as sent.</p>';
        echo '<p>Send sample email uses that same address and sends sample (fake) data with [SAMPLE] in the subject. It does not use real sales, does not use the Send to list, does not require Enable, and does not mark the day as sent.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">';
        $this->nonceField(self::NONCE_TEST);
        echo '<p><label for="bf_test_address">Test address</label><br />';
        echo '<input type="text" name="bf_test_address" id="bf_test_address" value="' . esc_attr($prefill) . '" class="regular-text" /></p>';
        echo '<p><button type="submit" name="bf_test_send" value="1" class="button">Send test email</button> ';
        echo '<button type="submit" name="bf_sample_send" value="1" class="button">Send sample email</button></p>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * @param \DateTimeImmutable|null $now
     * @return array<string,mixed>
     */
    public function buildDigest($now = null) {
        $now = $this->asZurich($now);
        $reportDate = self::yesterdayYmd($now);
        $dailyReport = $this->loadReport($reportDate, $reportDate, self::paidOrderStatuses());
        $daily = $this->summarize(is_array($dailyReport) ? $dailyReport : []);
        $dailyOnHold = $this->onHoldFigures($this->summarize($this->loadOnHoldReport($reportDate, $reportDate)));
        $couponStatus = $this->couponStatus($this->settings()['coupon_code']);
        $campaignStart = '';
        $campaign = null;
        $campaignOnHold = $this->onHoldFigures($this->emptySummary());
        $campaignStarted = false;
        $finalCampaign = false;
        if ($couponStatus['coupon'] !== null) {
            $campaignStart = self::zurichYmdFromTimestamp((int) $couponStatus['coupon']['start_unix']);
            $finalCampaign = self::isFinalCampaignDay(
                $reportDate,
                (int) $couponStatus['coupon']['start_unix'],
                (int) $couponStatus['coupon']['expires_unix']
            );
            if (strcmp($campaignStart, $reportDate) <= 0) {
                $campaignStarted = true;
                $campaignReport = $this->loadReport($campaignStart, $reportDate, self::paidOrderStatuses());
                $campaign = $this->summarize(is_array($campaignReport) ? $campaignReport : []);
                $campaignOnHold = $this->onHoldFigures($this->summarize($this->loadOnHoldReport($campaignStart, $reportDate)));
            } else {
                $campaign = $this->emptySummary();
            }
        }
        $coverage = $this->programmeBookingLists($now->format('Y-m-d'));
        return [
            'report_date' => $reportDate,
            'campaign_start' => $campaignStart,
            'campaign_available' => $couponStatus['coupon'] !== null,
            'campaign_started' => $campaignStarted,
            'daily' => $daily,
            'campaign' => $campaign,
            'daily_on_hold' => $dailyOnHold,
            'campaign_on_hold' => $campaignOnHold,
            'attention_orders' => $this->attentionOrders($this->attentionWindowStart($campaignStart, $reportDate), $reportDate),
            'low_bookings' => $coverage['low'],
            'zero_bookings' => $coverage['zero'],
            'used_created_fallback' => false,
            'final_campaign' => $finalCampaign,
            'booking_report_url' => $this->bookingReportUrl($reportDate),
        ];
    }

    /**
     * Fixed fake digest for the sample send. Same shape as buildDigest().
     * Numbers and names are invented. This method does not read the shop.
     *
     * @return array<string,mixed>
     */
    private function sampleDigest() {
        $lines = $this->sampleLines();
        $daily = $this->sampleSummary($lines, 'daily_qty', 'daily_inc', 4);
        $campaign = $this->sampleSummary($lines, 'campaign_qty', 'campaign_inc', 9);
        return [
            'report_date' => '2026-11-10',
            'campaign_start' => '2026-11-01',
            'campaign_available' => true,
            'campaign_started' => true,
            'daily' => $daily,
            'campaign' => $campaign,
            'daily_on_hold' => [
                'order_count' => 2,
                'net_inc_vat' => 45.00,
            ],
            'campaign_on_hold' => [
                'order_count' => 3,
                'net_inc_vat' => 90.50,
            ],
            'attention_orders' => [
                [
                    'order_number' => '900101',
                    'created_at' => '2026-11-10 09:15',
                    'admin_url' => 'https://example.com/sample-order/900101',
                ],
                [
                    'order_number' => '900102',
                    'created_at' => '2026-11-10 11:40',
                    'admin_url' => 'https://example.com/sample-order/900102',
                ],
                [
                    'order_number' => '900103',
                    'created_at' => '2026-11-10 16:05',
                    'admin_url' => 'https://example.test/sample-order/900103',
                ],
            ],
            'low_bookings' => [
                [
                    'label' => 'Sample Camp E',
                    'season' => 'Winter',
                    'type' => 'Camp',
                    'region' => 'Sample Region A',
                    'count' => 1,
                ],
                [
                    'label' => 'Sample Course D',
                    'season' => 'Spring',
                    'type' => 'Course',
                    'region' => 'Sample Region B',
                    'count' => 2,
                ],
                [
                    'label' => 'Sample Tournament B',
                    'season' => 'Easter',
                    'type' => 'Tournament',
                    'region' => 'Sample Region C',
                    'count' => 1,
                ],
            ],
            'zero_bookings' => [
                [
                    'label' => 'Sample Camp F',
                    'season' => 'Easter',
                    'type' => 'Camp',
                    'region' => 'Sample Region A',
                    'count' => 0,
                ],
            ],
            'used_created_fallback' => false,
            'final_campaign' => true,
            'booking_report_url' => 'https://example.com/sample-booking-report/900001',
        ];
    }

    /**
     * Invented product lines. Daily and campaign figures stay in one place
     * so the two summaries cannot drift apart.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sampleLines() {
        return [
            ['product_key' => 'p:sample-camp-a', 'product' => 'Sample Camp A', 'season' => 'Winter', 'type' => 'Camp', 'type_plural' => 'Camps', 'region' => 'Sample Region A', 'daily_qty' => 2, 'daily_inc' => 120.00, 'campaign_qty' => 5, 'campaign_inc' => 300.00],
            ['product_key' => 'p:sample-camp-b', 'product' => 'Sample Camp B', 'season' => 'Winter', 'type' => 'Camp', 'type_plural' => 'Camps', 'region' => 'Sample Region B', 'daily_qty' => 1, 'daily_inc' => 80.00, 'campaign_qty' => 3, 'campaign_inc' => 240.00],
            ['product_key' => 'p:sample-camp-c', 'product' => 'Sample Camp C', 'season' => 'Easter', 'type' => 'Camp', 'type_plural' => 'Camps', 'region' => 'Sample Region A', 'daily_qty' => 2, 'daily_inc' => 150.00, 'campaign_qty' => 4, 'campaign_inc' => 300.00],
            ['product_key' => 'p:sample-camp-d', 'product' => 'Sample Camp D', 'season' => 'Easter', 'type' => 'Camp', 'type_plural' => 'Camps', 'region' => 'Sample Region C', 'daily_qty' => 1, 'daily_inc' => 75.00, 'campaign_qty' => 2, 'campaign_inc' => 150.00],
            ['product_key' => 'p:sample-course-a', 'product' => 'Sample Course A', 'season' => 'Spring', 'type' => 'Course', 'type_plural' => 'Courses', 'region' => 'Sample Region B', 'daily_qty' => 3, 'daily_inc' => 90.00, 'campaign_qty' => 6, 'campaign_inc' => 180.00],
            ['product_key' => 'p:sample-course-b', 'product' => 'Sample Course B', 'season' => 'Spring', 'type' => 'Course', 'type_plural' => 'Courses', 'region' => 'Sample Region A', 'daily_qty' => 1, 'daily_inc' => 40.00, 'campaign_qty' => 2, 'campaign_inc' => 80.00],
            ['product_key' => 'p:sample-course-c', 'product' => 'Sample Course C', 'season' => 'Summer', 'type' => 'Course', 'type_plural' => 'Courses', 'region' => 'Sample Region C', 'daily_qty' => 1, 'daily_inc' => 55.00, 'campaign_qty' => 2, 'campaign_inc' => 110.00],
            ['product_key' => 'p:sample-venue-b', 'product' => 'Sample Venue B', 'season' => 'Winter', 'type' => 'Course', 'type_plural' => 'Courses', 'region' => 'Sample Region B', 'daily_qty' => 2, 'daily_inc' => 64.00, 'campaign_qty' => 4, 'campaign_inc' => 128.00],
            ['product_key' => 'p:sample-birthday-a', 'product' => 'Sample Birthday A', 'season' => 'Spring', 'type' => 'Birthday', 'type_plural' => 'Birthdays', 'region' => 'Sample Region A', 'daily_qty' => 1, 'daily_inc' => 35.00, 'campaign_qty' => 1, 'campaign_inc' => 35.00],
            ['product_key' => 'p:sample-tournament-a', 'product' => 'Sample Tournament A', 'season' => 'Summer', 'type' => 'Tournament', 'type_plural' => 'Tournaments', 'region' => 'Sample Region C', 'daily_qty' => 1, 'daily_inc' => 48.00, 'campaign_qty' => 2, 'campaign_inc' => 96.00],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @param string                         $qtyKey
     * @param string                         $incKey
     * @param int                            $orderCount
     * @return array<string,mixed>
     */
    private function sampleSummary(array $lines, $qtyKey, $incKey, $orderCount) {
        $products = [];
        $regions = [];
        $seasons = [];
        $seasonTypes = [];
        $types = [];
        $net = 0.0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (int) ($line[$qtyKey] ?? 0);
            $inc = round((float) ($line[$incKey] ?? 0), 2);
            $net += $inc;
            $season = (string) ($line['season'] ?? '');
            $type = (string) ($line['type'] ?? '');
            $compositeKey = $season . '|' . $type;
            $compositeLabel = $season . ' ' . (string) ($line['type_plural'] ?? $type);
            $this->sampleAdd($products, (string) ($line['product_key'] ?? ''), (string) ($line['product'] ?? ''), $qty, $inc);
            $this->sampleAdd($regions, (string) ($line['region'] ?? ''), (string) ($line['region'] ?? ''), $qty, $inc);
            $this->sampleAdd($seasons, $season, $season, $qty, $inc);
            $this->sampleAdd($seasonTypes, $compositeKey, $compositeLabel, $qty, $inc);
            $seasonTypes[$compositeKey]['season'] = $season;
            $seasonTypes[$compositeKey]['type'] = $type;
            $this->sampleAdd($types, $type, $type, $qty, $inc);
        }
        return [
            'order_count' => (int) $orderCount,
            'types' => array_values($types),
            'products' => array_values($products),
            'regions' => array_values($regions),
            'seasons' => array_values($seasons),
            'season_types' => array_values($seasonTypes),
            'used_created_fallback' => false,
            'net_ex_vat' => 0.0,
            'net_inc_vat' => round($net, 2),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $bucket
     * @param string                            $key
     * @param string                            $label
     * @param int                               $qty
     * @param float                             $inc
     * @return void
     */
    private function sampleAdd(array &$bucket, $key, $label, $qty, $inc) {
        if (!isset($bucket[$key])) {
            $bucket[$key] = [
                'key' => (string) $key,
                'label' => (string) $label,
                'quantity' => 0,
                'net_ex_vat' => 0.0,
                'net_inc_vat' => 0.0,
            ];
        }
        $bucket[$key]['quantity'] += (int) $qty;
        $bucket[$key]['net_inc_vat'] = round((float) $bucket[$key]['net_inc_vat'] + (float) $inc, 2);
    }

    /**
     * @param array<string,mixed> $digest
     * @return string
     */
    public function renderHtml(array $digest) {
        $reportDate = (string) ($digest['report_date'] ?? '');
        $daily = isset($digest['daily']) && is_array($digest['daily']) ? $digest['daily'] : $this->emptySummary();
        $campaign = isset($digest['campaign']) && is_array($digest['campaign']) ? $digest['campaign'] : $this->emptySummary();
        $html = '<div>';
        $html .= '<h2>Yesterday (' . esc_html($reportDate) . ', Europe/Zurich)</h2>';
        $html .= '<p><strong>' . esc_html($this->money((float) ($daily['net_inc_vat'] ?? 0))) . ' CHF</strong> net inc-VAT</p>';
        $html .= '<p>Orders: ' . esc_html((string) (int) ($daily['order_count'] ?? 0)) . '</p>';
        $html .= $this->renderOnHoldNote(
            'On hold yesterday',
            isset($digest['daily_on_hold']) && is_array($digest['daily_on_hold']) ? $digest['daily_on_hold'] : []
        );
        $html .= '<h3>Regions</h3>';
        $html .= $this->renderComparisonTable('Region', $daily, $campaign, 'regions', true);
        if (self::compositeSeasonTypeLabels()) {
            $html .= '<h3>Season and type</h3>';
            $html .= $this->renderComparisonTable('Season and type', $daily, $campaign, 'season_types', true);
        } else {
            $html .= '<h3>Seasons</h3>';
            $html .= $this->renderComparisonTable('Season', $daily, $campaign, 'seasons', true);
            $html .= $this->renderComparisonTable('Product type', $daily, $campaign, 'types');
        }
        $html .= '<h3>Products</h3>';
        $html .= $this->renderComparisonTable('Product', $daily, $campaign, 'products', true);
        $html .= '<h3>Failed and pending orders</h3>';
        $attention = (isset($digest['attention_orders']) && is_array($digest['attention_orders'])) ? $digest['attention_orders'] : [];
        $html .= $this->renderAttentionOrders($attention);
        $html .= '<h3>Low bookings</h3>';
        $lowBookings = (isset($digest['low_bookings']) && is_array($digest['low_bookings'])) ? $digest['low_bookings'] : [];
        $html .= $this->renderBookingList($lowBookings, 'No programmes in this scope have a low booking count.');
        $html .= '<h3>Zero bookings</h3>';
        $zeroBookings = (isset($digest['zero_bookings']) && is_array($digest['zero_bookings'])) ? $digest['zero_bookings'] : [];
        $html .= $this->renderBookingList($zeroBookings, 'No programmes in this scope have zero bookings.');
        if (!empty($digest['final_campaign'])) {
            $html .= '<p>' . esc_html(self::FINAL_CAMPAIGN_LINE) . '</p>';
        }
        $html .= '<h2>Running total since campaign start</h2>';
        if (empty($digest['campaign_available'])) {
            $html .= '<p>The coupon window is not available, so the running total is empty.</p>';
        } elseif (empty($digest['campaign_started'])) {
            $html .= '<p>The campaign has not started, so the running total is empty.</p>';
        } else {
            $html .= '<p>' . esc_html((string) ($digest['campaign_start'] ?? '')) . ' through ' . esc_html($reportDate) . ' (Europe/Zurich, paid date).</p>';
            $html .= '<p>Orders: ' . esc_html((string) (int) ($campaign['order_count'] ?? 0)) . '</p>';
            $html .= $this->renderOnHoldNote(
                'On hold in the campaign',
                isset($digest['campaign_on_hold']) && is_array($digest['campaign_on_hold']) ? $digest['campaign_on_hold'] : []
            );
            $html .= '<p>Campaign total: ' . esc_html($this->money((float) ($campaign['net_inc_vat'] ?? 0))) . ' CHF inc-VAT.</p>';
        }
        $url = (string) ($digest['booking_report_url'] ?? '');
        $html .= '<p><a href="' . esc_url($url) . '">Booking report for this day</a></p>';
        $html .= '<p>Net inc-VAT is what the customer paid after discounts and refunds, including remaining line tax.</p>';
        $html .= '<p>' . esc_html(self::FOOTER_PAID_DATE) . '</p>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Sample banner plus the shared renderer. Real and test emails call renderHtml() directly.
     *
     * @param array<string,mixed> $digest
     * @return string
     */
    private function renderSampleHtml(array $digest) {
        return '<p>' . esc_html(self::SAMPLE_BANNER) . '</p>' . $this->renderHtml($digest);
    }

    /**
     * Skip empty tokens and drop invalid ones. ok is true when at least one
     * valid address remains, in the original order.
     *
     * @param string $raw
     * @return array{ok:bool,reason:string,emails:array<int,string>,dropped:array<int,string>}
     */
    public function parseRecipientList($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['ok' => false, 'reason' => 'empty_recipients', 'emails' => [], 'dropped' => []];
        }
        $parts = explode(',', $raw);
        $emails = [];
        $dropped = [];
        $sawToken = false;
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '') {
                continue;
            }
            $sawToken = true;
            if (strpos($token, ';') !== false || preg_match('/\s/', $token) || !function_exists('is_email') || !is_email($token)) {
                $dropped[] = $token;
                continue;
            }
            $emails[] = $this->stripHeaderBreaks($token);
        }
        if (!$sawToken) {
            return ['ok' => false, 'reason' => 'empty_recipients', 'emails' => [], 'dropped' => []];
        }
        if ($emails === []) {
            return ['ok' => false, 'reason' => 'invalid_recipient', 'emails' => [], 'dropped' => $dropped];
        }
        return ['ok' => true, 'reason' => '', 'emails' => $emails, 'dropped' => $dropped];
    }

    /**
     * @param string $raw
     * @return array{ok:bool,email:string,message:string}
     */
    public function validateTestAddress($raw) {
        $token = trim((string) $raw);
        if ($token === '') {
            return [
                'ok' => false,
                'email' => '',
                'message' => 'Enter one valid email address.',
            ];
        }
        if (strpos($token, ',') !== false || strpos($token, ';') !== false || preg_match('/\s/', $token)) {
            return [
                'ok' => false,
                'email' => '',
                'message' => 'Enter one email address only. Commas, semicolons, and spaces are not allowed.',
            ];
        }
        if (!function_exists('is_email') || !is_email($token)) {
            return [
                'ok' => false,
                'email' => '',
                'message' => 'Enter one valid email address.',
            ];
        }
        return [
            'ok' => true,
            'email' => $this->stripHeaderBreaks($token),
            'message' => '',
        ];
    }

    /**
     * @param int $unix
     * @return string
     */
    public static function zurichYmdFromTimestamp($unix) {
        $dt = (new \DateTimeImmutable('@' . (int) $unix))->setTimezone(new \DateTimeZone(self::TIMEZONE));
        return $dt->format('Y-m-d');
    }

    /**
     * @param string        $local  Y-m-d H:i:s in the site timezone.
     * @param \DateTimeZone $siteTz
     * @return string
     */
    public static function zurichYmdFromSiteLocal($local, \DateTimeZone $siteTz) {
        $dt = new \DateTimeImmutable((string) $local, $siteTz);
        return $dt->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
    }

    /**
     * @param \DateTimeImmutable $now
     * @return string
     */
    public static function yesterdayYmd(\DateTimeImmutable $now) {
        $zurich = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        return $zurich->modify('-1 day')->format('Y-m-d');
    }

    /**
     * @param \DateTimeImmutable $now
     * @param string             $sendTime HH:MM
     * @return bool
     */
    public static function isSendTimeReached(\DateTimeImmutable $now, $sendTime) {
        $now = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $sendTime = self::isValidSendTime($sendTime) ? (string) $sendTime : '08:00';
        $parts = explode(':', $sendTime);
        $nowMinutes = ((int) $now->format('G')) * 60 + (int) $now->format('i');
        $sendMinutes = ((int) $parts[0]) * 60 + (int) $parts[1];
        return $nowMinutes >= $sendMinutes;
    }

    /**
     * A Zurich calendar day overlaps the inclusive coupon window [start, expiry].
     *
     * An expiry stored as 00:00:00 Europe/Zurich ends on the previous calendar
     * day. The morning after that last day reports it and still overlaps.
     * The following morning does not.
     *
     * @param string $ymd
     * @param int    $startUnix
     * @param int    $expiresUnix
     * @return bool
     */
    public static function zurichDayOverlapsWindow($ymd, $startUnix, $expiresUnix) {
        $expiresUnix = self::effectiveExpiryUnix($expiresUnix);
        try {
            $tz = new \DateTimeZone(self::TIMEZONE);
            $dayStart = new \DateTimeImmutable($ymd . ' 00:00:00', $tz);
        } catch (\Exception $e) {
            return false;
        }
        $dayEnd = $dayStart->modify('+1 day');
        if ($dayEnd->getTimestamp() <= (int) $startUnix) {
            return false;
        }
        if ($dayStart->getTimestamp() > $expiresUnix) {
            return false;
        }
        return true;
    }

    /**
     * A reported day is final when it is the last Zurich calendar day that
     * overlaps the coupon window. A midnight expiry uses the previous day.
     *
     * @param string $reportDate
     * @param int    $startUnix
     * @param int    $expiresUnix
     * @return bool
     */
    public static function isFinalCampaignDay($reportDate, $startUnix, $expiresUnix) {
        $expiresUnix = self::effectiveExpiryUnix($expiresUnix);
        $expiryDay = self::zurichYmdFromTimestamp($expiresUnix);
        if ((string) $reportDate !== $expiryDay) {
            return false;
        }
        return self::zurichDayOverlapsWindow($reportDate, $startUnix, $expiresUnix);
    }

    /**
     * WooCommerce stores a date-only expiry as 00:00:00. That instant is the
     * start of a day the coupon does not cover, so the previous day is last.
     *
     * @param int $expiresUnix
     * @return int
     */
    public static function effectiveExpiryUnix($expiresUnix) {
        $expiresUnix = (int) $expiresUnix;
        if ($expiresUnix <= 0 || !self::isZurichMidnight($expiresUnix)) {
            return $expiresUnix;
        }
        return $expiresUnix - 1;
    }

    /**
     * @param int $unix
     * @return bool
     */
    public static function isZurichMidnight($unix) {
        try {
            $dt = (new \DateTimeImmutable('@' . (int) $unix))->setTimezone(new \DateTimeZone(self::TIMEZONE));
        } catch (\Exception $e) {
            return false;
        }
        return $dt->format('H:i:s') === '00:00:00';
    }

    /**
     * @param string        $startYmd
     * @param string        $endYmd
     * @param \DateTimeZone $siteTz
     * @return array{paid_start_unix:int,paid_end_unix:int,created_start_local:string,created_end_local:string}
     */
    public static function rangeBounds($startYmd, $endYmd, \DateTimeZone $siteTz) {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $start = new \DateTimeImmutable($startYmd . ' 00:00:00', $tz);
        $end = (new \DateTimeImmutable($endYmd . ' 00:00:00', $tz))->modify('+1 day');
        return [
            'paid_start_unix' => $start->getTimestamp(),
            'paid_end_unix' => $end->getTimestamp(),
            'created_start_local' => $start->setTimezone($siteTz)->format('Y-m-d H:i:s'),
            'created_end_local' => $end->setTimezone($siteTz)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param string $sendTime
     * @return bool
     */
    public static function isValidSendTime($sendTime) {
        return is_string($sendTime) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $sendTime) === 1;
    }

    /**
     * Empty is allowed and stored as an empty string. Any other value must be
     * a real YYYY-MM-DD date. Invalid input returns null so save can reject it.
     *
     * @param string $raw
     * @return string|null
     */
    private static function validCampaignStartYmd($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        $tz = new \DateTimeZone(self::TIMEZONE);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!($dt instanceof \DateTimeImmutable)) {
            return null;
        }
        if (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count']))) {
            return null;
        }
        if ($dt->format('Y-m-d') !== $raw) {
            return null;
        }
        return $raw;
    }

    /**
     * Persistent settings-page warnings. These stay visible without a save flash.
     *
     * @return array<int,string>
     */
    private function configurationWarnings() {
        $warnings = [];
        $settings = $this->settings();
        if ($settings['campaign_start'] === '') {
            $warnings[] = 'Campaign start date is not set. The scheduled email will not send.';
        }
        $code = trim((string) $settings['coupon_code']);
        if ($code === '') {
            return $warnings;
        }
        $raw = $this->loadCouponRecord($code);
        if (!is_array($raw)) {
            return $warnings;
        }
        $expires = $this->parseInstant(isset($raw['expires']) ? $raw['expires'] : null);
        if ($expires === null) {
            $warnings[] = 'The coupon has no expiry date. The scheduled email will not send.';
        }
        return $warnings;
    }

    /**
     * @return bool
     */
    /**
     * Failed and pending-payment statuses. One filter changes the set.
     *
     * @return array<int,string>
     */
    public static function attentionOrderStatuses() {
        $raw = function_exists('apply_filters')
            ? apply_filters(self::ATTENTION_ORDER_STATUSES_FILTER, self::ATTENTION_ORDER_STATUSES)
            : self::ATTENTION_ORDER_STATUSES;
        $clean = self::sanitizeOrderStatuses($raw);
        return $clean !== [] ? $clean : self::ATTENTION_ORDER_STATUSES;
    }

    public static function compositeSeasonTypeLabels() {
        $default = self::COMPOSITE_SEASON_TYPE_LABELS;
        if (!function_exists('apply_filters')) {
            return $default;
        }
        return (bool) apply_filters(self::COMPOSITE_SEASON_TYPE_FILTER, $default);
    }

    /**
     * Paid statuses for this email. One filter changes the set; the default is strict.
     *
     * @return array<int,string>
     */
    public static function paidOrderStatuses() {
        $raw = function_exists('apply_filters')
            ? apply_filters(self::PAID_ORDER_STATUSES_FILTER, self::PAID_ORDER_STATUSES)
            : self::PAID_ORDER_STATUSES;
        $clean = self::sanitizeOrderStatuses($raw);
        return $clean !== [] ? $clean : self::PAID_ORDER_STATUSES;
    }

    /**
     * @param string             $startYmd
     * @param string             $endYmd
     * @param \DateTimeZone      $siteTz
     * @param array<int,string>|null $statuses
     * @return array<string,mixed>
     */
    public static function paidWindowOptions($startYmd, $endYmd, \DateTimeZone $siteTz, $statuses = null) {
        $bounds = self::rangeBounds($startYmd, $endYmd, $siteTz);
        if ($statuses === null) {
            $statuses = self::paidOrderStatuses();
        }
        return [
            'date_basis' => 'paid_only',
            'order_statuses' => array_values($statuses),
            'paid_start_unix' => $bounds['paid_start_unix'],
            'paid_end_unix' => $bounds['paid_end_unix'],
            'include_line_meta' => true,
        ];
    }

    /**
     * @return array{enabled:bool,recipients:string,send_time:string,coupon_code:string,low_booking_threshold:int,campaign_start:string}
     */
    public function settings() {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $sendTime = isset($stored['send_time']) ? (string) $stored['send_time'] : '08:00';
        if (!self::isValidSendTime($sendTime)) {
            $sendTime = '08:00';
        }
        $campaignStart = isset($stored['campaign_start']) ? (string) $stored['campaign_start'] : '';
        $campaignStart = self::validCampaignStartYmd($campaignStart);
        if ($campaignStart === null) {
            $campaignStart = '';
        }
        return [
            'enabled' => !empty($stored['enabled']),
            'recipients' => isset($stored['recipients']) ? (string) $stored['recipients'] : '',
            'send_time' => $sendTime,
            'coupon_code' => isset($stored['coupon_code']) ? (string) $stored['coupon_code'] : '',
            'campaign_start' => $campaignStart,
            'low_booking_threshold' => array_key_exists('low_booking_threshold', $stored)
                ? self::normalizeLowBookingThreshold($stored['low_booking_threshold'])
                : self::DEFAULT_LOW_BOOKING_THRESHOLD,
        ];
    }

    /**
     * @return array{sent_dates:array<string,int>,last_sent_at:string,last_result:string}
     */
    public function state() {
        $stored = get_option(self::OPTION_STATE, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $sent = (isset($stored['sent_dates']) && is_array($stored['sent_dates'])) ? $stored['sent_dates'] : [];
        $clean = [];
        foreach ($sent as $date => $stamp) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                $clean[(string) $date] = (int) $stamp;
            }
        }
        $result = isset($stored['last_result']) ? (string) $stored['last_result'] : '';
        return [
            'sent_dates' => $clean,
            'last_sent_at' => isset($stored['last_sent_at']) ? (string) $stored['last_sent_at'] : '',
            'last_result' => $this->allowReason($result),
        ];
    }

    /**
     * @param string $ymd
     * @return string
     */
    public function bookingReportUrl($ymd) {
        $ymd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ymd) ? (string) $ymd : '';
        return admin_url('admin.php?page=intersoccer-reports&start_date=' . rawurlencode($ymd) . '&end_date=' . rawurlencode($ymd));
    }

    /**
     * @param array<string,mixed> $post
     * @return array{ok:bool,code:string,message:string}
     */
    private function saveSettings(array $post) {
        $sendTime = isset($post['bf_send_time']) ? trim((string) $post['bf_send_time']) : '';
        if (!self::isValidSendTime($sendTime)) {
            return [
                'ok' => false,
                'code' => 'invalid_time',
                'message' => 'Enter a send time as HH:MM (24-hour, Europe/Zurich).',
            ];
        }
        $recipients = isset($post['bf_recipients']) ? (string) $post['bf_recipients'] : '';
        $recipients = str_replace(["\r\n", "\r", "\n"], ',', $recipients);
        $recipients = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($recipients) : trim($recipients);
        if (strlen($recipients) > 2000) {
            $recipients = substr($recipients, 0, 2000);
        }
        $parsedRecipients = $this->parseRecipientList($recipients);
        $keptRecipients = implode(', ', $parsedRecipients['emails']);
        $droppedNotice = [];
        $droppedTokens = (isset($parsedRecipients['dropped']) && is_array($parsedRecipients['dropped']))
            ? $parsedRecipients['dropped']
            : [];
        foreach ($droppedTokens as $token) {
            $clean = $this->stripHeaderBreaks((string) $token);
            if ($clean !== '') {
                $droppedNotice[] = $clean;
            }
        }
        $code = isset($post['bf_coupon_code']) ? (string) $post['bf_coupon_code'] : '';
        $code = function_exists('sanitize_text_field') ? sanitize_text_field($code) : trim($code);
        if (strlen($code) > 64) {
            $code = substr($code, 0, 64);
        }
        $campaignStartRaw = isset($post['bf_campaign_start']) ? (string) $post['bf_campaign_start'] : '';
        $campaignStartRaw = function_exists('sanitize_text_field') ? sanitize_text_field($campaignStartRaw) : trim($campaignStartRaw);
        $campaignStart = self::validCampaignStartYmd($campaignStartRaw);
        if ($campaignStart === null) {
            return [
                'ok' => false,
                'code' => 'invalid_campaign_start',
                'message' => 'Enter the campaign start date as YYYY-MM-DD, or leave it empty.',
            ];
        }
        $threshold = self::normalizeLowBookingThreshold(
            array_key_exists('bf_low_booking_threshold', $post) ? $post['bf_low_booking_threshold'] : null
        );
        $settings = [
            'enabled' => !empty($post['bf_enabled']),
            'low_booking_threshold' => $threshold,
            'recipients' => $keptRecipients,
            'send_time' => $sendTime,
            'coupon_code' => $code,
            'campaign_start' => $campaignStart,
        ];
        update_option(self::OPTION_SETTINGS, $settings, false);
        self::syncScheduleToValue($settings);
        if (!$parsedRecipients['ok'] && $parsedRecipients['reason'] === 'empty_recipients') {
            return [
                'ok' => false,
                'code' => 'empty_recipients',
                'message' => 'The Send to list is empty, so nothing will be sent.',
            ];
        }
        if (!$parsedRecipients['ok']) {
            $message = 'No valid email address remains, so nothing will be sent.';
            if ($droppedNotice !== []) {
                $message .= ' Removed: ' . implode(', ', $droppedNotice);
            }
            return [
                'ok' => false,
                'code' => 'invalid_recipient',
                'message' => $message,
            ];
        }
        if ($droppedNotice !== []) {
            return [
                'ok' => true,
                'code' => 'saved',
                'message' => 'Settings saved. Removed invalid addresses: ' . implode(', ', $droppedNotice),
            ];
        }
        return [
            'ok' => true,
            'code' => 'saved',
            'message' => 'Settings saved.',
        ];
    }

    /**
     * @param string            $startYmd
     * @param string            $endYmd
     * @param array<int,string> $statuses
     * @return array<string,mixed>
     */
    private function loadReport($startYmd, $endYmd, array $statuses) {
        $options = self::paidWindowOptions($startYmd, $endYmd, $this->siteTimezone(), $statuses);
        return $this->reportService()->getFinancialBookingReport($startYmd, $endYmd, '', '', $options);
    }

    /**
     * On-hold orders have no paid date, so this count uses the created date.
     * The rows stay out of the paid totals.
     *
     * @param string $startYmd
     * @param string $endYmd
     * @return array<string,mixed>
     */
    private function loadOnHoldReport($startYmd, $endYmd) {
        $bounds = self::rangeBounds($startYmd, $endYmd, $this->siteTimezone());
        $report = $this->reportService()->getFinancialBookingReport($startYmd, $endYmd, '', '', [
            'date_basis' => 'created_only',
            'order_statuses' => ['wc-on-hold'],
            'created_start_local' => $bounds['created_start_local'],
            'created_end_local' => $bounds['created_end_local'],
            'include_line_meta' => true,
        ]);
        return is_array($report) ? $report : [];
    }

    /**
     * Coupon start through the report day, or the report day when the coupon
     * window is not known yet. Empty when the campaign has not started.
     *
     * @param string $campaignStart
     * @param string $reportDate
     * @return string
     */
    private function attentionWindowStart($campaignStart, $reportDate) {
        $campaignStart = (string) $campaignStart;
        $reportDate = (string) $reportDate;
        if ($campaignStart === '') {
            return $reportDate;
        }
        if (strcmp($campaignStart, $reportDate) > 0) {
            return '';
        }
        return $campaignStart;
    }

    /**
     * Failed and pending orders created from the campaign start through the
     * reported Zurich day. The list keeps the order number, created time,
     * and admin link only, newest first.
     *
     * @param string $startYmd
     * @param string $endYmd
     * @return array<int,array{order_number:string,created_at:string,admin_url:string}>
     */
    private function attentionOrders($startYmd, $endYmd) {
        $startYmd = (string) $startYmd;
        $endYmd = (string) $endYmd;
        if ($startYmd === '' || $endYmd === '' || strcmp($startYmd, $endYmd) > 0) {
            return [];
        }
        $bounds = self::rangeBounds($startYmd, $endYmd, $this->siteTimezone());
        $report = $this->reportService()->getFinancialBookingReport($startYmd, $endYmd, '', '', [
            'date_basis' => 'created_only',
            'order_statuses' => self::attentionOrderStatuses(),
            'created_start_local' => $bounds['created_start_local'],
            'created_end_local' => $bounds['created_end_local'],
        ]);
        $rows = (isset($report['data']) && is_array($report['data'])) ? $report['data'] : [];
        $seen = [];
        $list = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = (int) ($row['order_id'] ?? 0);
            if ($orderId <= 0 || isset($seen[$orderId])) {
                continue;
            }
            $seen[$orderId] = true;
            $list[] = [
                'order_number' => (string) $orderId,
                'created_at' => $this->zurichCreatedLabel(isset($row['order_date_local']) ? (string) $row['order_date_local'] : ''),
                'admin_url' => $this->orderAdminUrl($orderId),
            ];
        }
        usort($list, function ($a, $b) {
            $byTime = strcmp((string) $b['created_at'], (string) $a['created_at']);
            if ($byTime !== 0) {
                return $byTime;
            }
            return strcmp((string) $b['order_number'], (string) $a['order_number']);
        });
        return $list;
    }

    /**
     * @param string $local Site-local Y-m-d H:i:s.
     * @return string
     */
    private function zurichCreatedLabel($local) {
        $local = trim((string) $local);
        if ($local === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($local, $this->siteTimezone());
        } catch (\Exception $e) {
            return '';
        }
        return $dt->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i');
    }

    /**
     * Legacy edit link, or the HPOS orders screen when that storage is on.
     * The orders-table class can be loaded while HPOS is still off.
     *
     * @param int $orderId
     * @return string
     */
    private function orderAdminUrl($orderId) {
        $orderId = (int) $orderId;
        if ($orderId <= 0 || !function_exists('admin_url')) {
            return '';
        }
        if ($this->hposOrdersEnabled()) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $orderId);
        }
        return admin_url('post.php?post=' . $orderId . '&action=edit');
    }

    /**
     * @return bool
     */
    private function hposOrdersEnabled() {
        $class = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
        if (!class_exists($class) || !method_exists($class, 'custom_orders_table_usage_is_enabled')) {
            return false;
        }
        return (bool) call_user_func([$class, 'custom_orders_table_usage_is_enabled']);
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @return string
     */
    private function renderAttentionOrders(array $orders) {
        if ($orders === []) {
            return '<p>No failed or pending orders were created in the campaign window.</p>';
        }
        $html = '<table><thead><tr><th>Order</th><th>Created (Europe/Zurich)</th><th>Admin</th></tr></thead><tbody>';
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $number = (string) ($order['order_number'] ?? '');
            $created = (string) ($order['created_at'] ?? '');
            $url = (string) ($order['admin_url'] ?? '');
            $html .= '<tr>';
            $html .= '<td>' . esc_html($number) . '</td>';
            $html .= '<td>' . esc_html($created) . '</td>';
            $html .= '<td>';
            if ($url !== '') {
                $html .= '<a href="' . esc_url($url) . '">Edit order</a>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * @param mixed $value
     * @return int
     */
    private static function normalizeLowBookingThreshold($value) {
        if ($value === null) {
            return self::DEFAULT_LOW_BOOKING_THRESHOLD;
        }
        $text = trim((string) $value);
        if (!preg_match('/^\d{1,3}$/', $text)) {
            return self::DEFAULT_LOW_BOOKING_THRESHOLD;
        }
        $number = (int) $text;
        if ($number > 999) {
            return self::DEFAULT_LOW_BOOKING_THRESHOLD;
        }
        return $number;
    }

    /**
     * Published Winter, Spring, and Easter programmes for the scoped year that
     * have not started. Counts include every paid booking, of any date.
     *
     * @param string $asOfYmd
     * @return array{low:array<int,array<string,mixed>>,zero:array<int,array<string,mixed>>}
     */
    private function programmeBookingLists($asOfYmd) {
        $threshold = (int) $this->settings()['low_booking_threshold'];
        $events = $this->catalogueEvents($asOfYmd);
        $report = $this->reportService()->getFinancialBookingReport($asOfYmd, $asOfYmd, '', '', [
            'date_basis' => 'paid_any',
            'order_statuses' => self::paidOrderStatuses(),
        ]);
        $counts = $this->paidCountsByProduct(is_array($report) ? $report : []);
        $low = [];
        $zero = [];
        foreach ($events as $event) {
            $productId = (int) $event['product_id'];
            $count = isset($counts[$productId]) ? (int) $counts[$productId] : 0;
            $row = [
                'label' => (string) $event['label'],
                'season' => (string) $event['season'],
                'type' => (string) $event['type'],
                'region' => (string) $event['region'],
                'count' => $count,
            ];
            if ($count === 0) {
                $zero[] = $row;
            } elseif ($count >= 1 && $count <= $threshold) {
                $low[] = $row;
            }
        }
        $sort = static function ($left, $right) {
            return strcmp((string) $left['label'], (string) $right['label']);
        };
        usort($low, $sort);
        usort($zero, $sort);
        return ['low' => $low, 'zero' => $zero];
    }

    /**
     * @param string $asOfYmd
     * @return array<int,array<string,mixed>>
     */
    private function catalogueEvents($asOfYmd) {
        $published = $this->publishedProductIds();
        $events = [];
        $seen = [];
        foreach ($published as $id) {
            $defaultId = $this->defaultLanguageProductId($id);
            if (!isset($published[$defaultId]) || isset($seen[$defaultId])) {
                continue;
            }
            $seen[$defaultId] = true;
            $event = $this->catalogueEvent($defaultId, $asOfYmd);
            if ($event !== null) {
                $events[$defaultId] = $event;
            }
        }
        return $events;
    }

    /**
     * @return array<int,int>
     */
    private function publishedProductIds() {
        if (!function_exists('get_posts')) {
            return [];
        }
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        if (!is_array($ids)) {
            return [];
        }
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        return $clean;
    }

    /**
     * @param int    $productId
     * @param string $asOfYmd
     * @return array<string,mixed>|null
     */
    private function catalogueEvent($productId, $asOfYmd) {
        $productId = (int) $productId;
        $type = $this->catalogueTypeLabel($this->productMeta($productId, ['_intersoccer_product_type']));
        if ($type === '') {
            return null;
        }
        $seasonKeys = FacetNormalizer::season_meta_keys();
        $termKeys = FacetNormalizer::camp_term_meta_keys();
        $yearKeys = ['pa_program-year', 'attribute_pa_program-year', 'Program Year', 'Année', 'Jahr'];
        $regionKeys = [
            '_intersoccer_canonical_canton',
            'Canton / Region',
            'Canton / Région',
            'Kanton / Region',
            'pa_canton-region',
            'attribute_pa_canton-region',
        ];
        $seasonRaw = $this->productMeta($productId, $seasonKeys);
        $terms = $this->productMeta($productId, $termKeys);
        $yearRaw = $this->productMeta($productId, $yearKeys);
        $regionRaw = $this->productMeta($productId, $regionKeys);
        $start = $this->productMeta($productId, ['_camp_start_date']);
        foreach ($this->variationIds($productId) as $variationId) {
            if ($seasonRaw === '') {
                $seasonRaw = $this->productMeta($variationId, $seasonKeys);
            }
            if ($terms === '') {
                $terms = $this->productMeta($variationId, $termKeys);
            }
            if ($yearRaw === '') {
                $yearRaw = $this->productMeta($variationId, $yearKeys);
            }
            if ($regionRaw === '') {
                $regionRaw = $this->productMeta($variationId, $regionKeys);
            }
            $candidate = $this->productMeta($variationId, ['_camp_start_date']);
            if ($candidate !== '' && ($start === '' || strcmp($candidate, $start) < 0)) {
                $start = $candidate;
            }
        }
        $name = function_exists('get_the_title') ? trim((string) get_the_title($productId)) : '';
        if ($this->programmeYear($yearRaw, $seasonRaw, $terms, $name) !== self::LOW_BOOKING_YEAR) {
            return null;
        }
        $season = $this->catalogueSeason($seasonRaw, $terms, $name, $type);
        if ($season === '') {
            return null;
        }
        if ($this->eventHasStarted($start, $asOfYmd)) {
            return null;
        }
        $region = 'Unknown';
        if ($regionRaw !== '') {
            $normalized = $this->facetNormalizer()->normalize_region($regionRaw);
            if ($normalized !== '' && $normalized !== 'not_recorded') {
                $region = $normalized;
            }
        }
        return [
            'product_id' => $productId,
            'label' => $name !== '' ? $name : ('Product ' . $productId),
            'season' => $season,
            'type' => $type,
            'region' => $region,
        ];
    }

    /**
     * @param string $slug
     * @return string
     */
    private function catalogueTypeLabel($slug) {
        $slug = strtolower(trim((string) $slug));
        $map = [
            'camp' => ProductTypeClassifierService::TYPE_CAMP,
            'course' => ProductTypeClassifierService::TYPE_COURSE,
            'birthday' => ProductTypeClassifierService::TYPE_BIRTHDAY,
            'tournament' => ProductTypeClassifierService::TYPE_TOURNAMENT,
        ];
        return isset($map[$slug]) ? $map[$slug] : '';
    }

    /**
     * @param string $yearRaw
     * @param string $seasonRaw
     * @param string $terms
     * @param string $name
     * @return string
     */
    private function programmeYear($yearRaw, $seasonRaw, $terms, $name) {
        foreach ([$yearRaw, $seasonRaw, $terms, $name] as $value) {
            if (preg_match('/\b(20\d{2})\b/', (string) $value, $matches)) {
                return $matches[1];
            }
        }
        return '';
    }

    /**
     * Winter, Spring, or Easter. A Spring camp is promoted when Easter is named.
     *
     * @param string $seasonRaw
     * @param string $terms
     * @param string $name
     * @param string $type
     * @return string
     */
    private function catalogueSeason($seasonRaw, $terms, $name, $type) {
        if ($this->normalizeSeasonValue($seasonRaw) === 'Easter') {
            return 'Easter';
        }
        $bucket = $this->normalizeSeasonValue($seasonRaw);
        if ($bucket === '') {
            $bucket = $this->normalizeSeasonValue($terms);
        }
        if ($bucket === '') {
            $bucket = $this->normalizeSeasonValue($name);
        }
        if ($type === ProductTypeClassifierService::TYPE_CAMP
            && $bucket === 'Spring'
            && ($this->mentionsEaster($seasonRaw) || $this->mentionsEaster($terms) || $this->mentionsEaster($name))
        ) {
            $bucket = 'Easter';
        }
        if ($bucket === 'Winter' || $bucket === 'Spring' || $bucket === 'Easter') {
            return $bucket;
        }
        return '';
    }

    /**
     * @param string $start
     * @param string $asOfYmd
     * @return bool
     */
    private function eventHasStarted($start, $asOfYmd) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $start, $matches)) {
            return false;
        }
        return strcmp($matches[1], (string) $asOfYmd) < 0;
    }

    /**
     * @param int $productId
     * @return array<int,int>
     */
    private function variationIds($productId) {
        if (!function_exists('get_posts')) {
            return [];
        }
        $ids = get_posts([
            'post_type' => 'product_variation',
            'post_status' => 'publish',
            'post_parent' => (int) $productId,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        if (!is_array($ids)) {
            return [];
        }
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[] = $id;
            }
        }
        return $clean;
    }

    /**
     * @param int                $postId
     * @param array<int,string>  $keys
     * @return string
     */
    private function productMeta($postId, array $keys) {
        if (!function_exists('get_post_meta')) {
            return '';
        }
        foreach ($keys as $key) {
            $value = get_post_meta((int) $postId, $key, true);
            if (is_string($value) || is_numeric($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return '';
    }

    /**
     * @param int $productId
     * @return int
     */
    private function defaultLanguageProductId($productId) {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return 0;
        }
        if (function_exists('wp_get_post_parent_id')) {
            $parent = (int) wp_get_post_parent_id($productId);
            if ($parent > 0) {
                $productId = $parent;
            }
        }
        if (!function_exists('apply_filters')) {
            return $productId;
        }
        $language = 'en';
        if (function_exists('wpml_get_default_language')) {
            $detected = wpml_get_default_language();
            if (is_string($detected) && $detected !== '') {
                $language = $detected;
            }
        }
        $mapped = apply_filters('wpml_object_id', $productId, 'product', true, $language);
        $mapped = (int) $mapped;
        return $mapped > 0 ? $mapped : $productId;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,int>
     */
    private function paidCountsByProduct(array $report) {
        $counts = [];
        $rows = isset($report['data']) && is_array($report['data']) ? $report['data'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = $this->defaultLanguageProductId(isset($row['product_id']) ? (int) $row['product_id'] : 0);
            if ($productId <= 0) {
                continue;
            }
            $quantity = isset($row['line_quantity']) ? (int) $row['line_quantity'] : 0;
            if ($quantity < 1) {
                $quantity = 1;
            }
            if (!isset($counts[$productId])) {
                $counts[$productId] = 0;
            }
            $counts[$productId] += $quantity;
        }
        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param string                         $emptyMessage
     * @return string
     */
    private function renderBookingList(array $rows, $emptyMessage) {
        if ($rows === []) {
            return '<p>' . esc_html($emptyMessage) . '</p>';
        }
        $html = '<table><thead><tr><th>Event</th><th>Season</th><th>Type</th><th>Region</th><th>Count</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $html .= '<tr>';
            $html .= '<td>' . esc_html((string) ($row['label'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($row['season'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($row['type'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($row['region'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) (int) ($row['count'] ?? 0)) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * @return FinancialReportService
     */
    private function reportService() {
        if ($this->reports instanceof FinancialReportService) {
            return $this->reports;
        }
        $this->reports = new FinancialReportService($this->logger);
        return $this->reports;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function summarize(array $report) {
        $types = [];
        foreach (self::typeOrder() as $bucket) {
            $types[$bucket] = $this->emptyType($bucket);
        }
        $products = [];
        $regions = [];
        $seasons = [];
        $seasonTypes = [];
        $orders = [];
        $fallback = false;
        $rows = isset($report['data']) && is_array($report['data']) ? $report['data'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bucket = isset($row['product_type']) ? (string) $row['product_type'] : ProductTypeClassifierService::TYPE_OTHER;
            if (!isset($types[$bucket])) {
                $bucket = ProductTypeClassifierService::TYPE_OTHER;
            }
            $qty = (int) ($row['line_quantity'] ?? 0);
            $gross = round((float) ($row['base_price_raw'] ?? 0), 2);
            $discount = round((float) ($row['discount_raw'] ?? 0), 2);
            $refund = round((float) ($row['reimbursement_raw'] ?? 0), 2);
            $netEx = round((float) ($row['net_ex_vat'] ?? 0), 2);
            $netInc = round($this->netIncVat($row), 2);
            $types[$bucket]['quantity'] += $qty;
            $types[$bucket]['gross_ex_vat'] += $gross;
            $types[$bucket]['discounts'] += $discount;
            $types[$bucket]['refunds'] += $refund;
            $types[$bucket]['net_ex_vat'] += $netEx;
            $types[$bucket]['net_inc_vat'] += $netInc;

            $variationId = (int) ($row['variation_id'] ?? 0);
            $productId = (int) ($row['product_id'] ?? 0);
            $key = $variationId > 0 ? 'v:' . $variationId : 'p:' . $productId;
            $label = trim((string) ($row['order_item_name'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($row['class_name'] ?? ''));
            }
            if ($label === '') {
                $label = 'Product ' . $key;
            }
            if (!isset($products[$key])) {
                $products[$key] = [
                    'key' => $key,
                    'label' => $label,
                    'type' => $bucket,
                    'quantity' => 0,
                    'net_ex_vat' => 0.0,
                    'net_inc_vat' => 0.0,
                ];
            }
            $products[$key]['quantity'] += $qty;
            $products[$key]['net_ex_vat'] += $netEx;
            $products[$key]['net_inc_vat'] += $netInc;

            $regionLabel = $this->regionLabel($row);
            if (!isset($regions[$regionLabel])) {
                $regions[$regionLabel] = [
                    'key' => $regionLabel,
                    'label' => $regionLabel,
                    'quantity' => 0,
                    'net_ex_vat' => 0.0,
                    'net_inc_vat' => 0.0,
                ];
            }
            $regions[$regionLabel]['quantity'] += $qty;
            $regions[$regionLabel]['net_ex_vat'] += $netEx;
            $regions[$regionLabel]['net_inc_vat'] += $netInc;

            $season = $this->seasonBucket($row, $bucket);
            if (!isset($seasons[$season])) {
                $seasons[$season] = $this->moneyRow($season, $season);
            }
            $seasons[$season]['quantity'] += $qty;
            $seasons[$season]['net_ex_vat'] += $netEx;
            $seasons[$season]['net_inc_vat'] += $netInc;

            $compositeKey = $season . '|' . $bucket;
            if (!isset($seasonTypes[$compositeKey])) {
                $seasonTypes[$compositeKey] = $this->moneyRow($compositeKey, $this->compositeLabel($season, $bucket));
                $seasonTypes[$compositeKey]['season'] = $season;
                $seasonTypes[$compositeKey]['type'] = $bucket;
            }
            $seasonTypes[$compositeKey]['quantity'] += $qty;
            $seasonTypes[$compositeKey]['net_ex_vat'] += $netEx;
            $seasonTypes[$compositeKey]['net_inc_vat'] += $netInc;

            $orderId = (int) ($row['order_id'] ?? 0);
            if ($orderId > 0) {
                $orders[$orderId] = true;
            }
            if (!empty($row['used_created_date'])) {
                $fallback = true;
            }
        }
        $netEx = 0.0;
        $netInc = 0.0;
        foreach ($types as $bucket => $type) {
            $types[$bucket]['gross_ex_vat'] = round($type['gross_ex_vat'], 2);
            $types[$bucket]['discounts'] = round($type['discounts'], 2);
            $types[$bucket]['refunds'] = round($type['refunds'], 2);
            $types[$bucket]['net_ex_vat'] = round($type['net_ex_vat'], 2);
            $types[$bucket]['net_inc_vat'] = round($type['net_inc_vat'], 2);
            $netEx += $types[$bucket]['net_ex_vat'];
            $netInc += $types[$bucket]['net_inc_vat'];
        }
        foreach ($products as $key => $product) {
            $products[$key]['net_ex_vat'] = round($product['net_ex_vat'], 2);
            $products[$key]['net_inc_vat'] = round($product['net_inc_vat'], 2);
        }
        foreach ($regions as $key => $region) {
            $regions[$key]['net_ex_vat'] = round($region['net_ex_vat'], 2);
            $regions[$key]['net_inc_vat'] = round($region['net_inc_vat'], 2);
        }
        foreach ($seasons as $key => $seasonRow) {
            $seasons[$key]['net_ex_vat'] = round($seasonRow['net_ex_vat'], 2);
            $seasons[$key]['net_inc_vat'] = round($seasonRow['net_inc_vat'], 2);
        }
        foreach ($seasonTypes as $key => $seasonType) {
            $seasonTypes[$key]['net_ex_vat'] = round($seasonType['net_ex_vat'], 2);
            $seasonTypes[$key]['net_inc_vat'] = round($seasonType['net_inc_vat'], 2);
        }
        uasort($products, function ($a, $b) {
            return strcmp((string) $a['label'], (string) $b['label']);
        });
        uasort($regions, function ($a, $b) {
            return strcmp((string) $a['label'], (string) $b['label']);
        });
        uasort($seasons, function ($a, $b) {
            return strcmp((string) $a['label'], (string) $b['label']);
        });
        uasort($seasonTypes, function ($a, $b) {
            return strcmp((string) $a['label'], (string) $b['label']);
        });
        return [
            'order_count' => count($orders),
            'types' => array_values($types),
            'products' => array_values($products),
            'regions' => array_values($regions),
            'seasons' => array_values($seasons),
            'season_types' => array_values($seasonTypes),
            'used_created_fallback' => $fallback,
            'net_ex_vat' => round($netEx, 2),
            'net_inc_vat' => round($netInc, 2),
        ];
    }

    /**
     * @param string $key
     * @param string $label
     * @return array<string,mixed>
     */
    private function moneyRow($key, $label) {
        return [
            'key' => (string) $key,
            'label' => (string) $label,
            'quantity' => 0,
            'net_ex_vat' => 0.0,
            'net_inc_vat' => 0.0,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param string              $productType
     * @return string
     */
    private function seasonBucket(array $row, $productType) {
        $meta = (isset($row['line_meta']) && is_array($row['line_meta'])) ? $row['line_meta'] : [];
        $facets = $this->facetNormalizer();
        $raw = $facets->meta_value($meta, FacetNormalizer::season_meta_keys());
        $terms = $facets->meta_value($meta, FacetNormalizer::camp_term_meta_keys());
        $name = trim((string) ($row['order_item_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['class_name'] ?? ''));
        }
        $bucket = $this->normalizeSeasonValue($raw);
        if ($bucket === 'Easter') {
            return 'Easter';
        }
        if ($productType === ProductTypeClassifierService::TYPE_CAMP
            && $bucket === 'Spring'
            && ($this->mentionsEaster($raw) || $this->mentionsEaster($terms) || $this->mentionsEaster($name))
        ) {
            return 'Easter';
        }
        return $bucket === '' ? 'Unspecified' : $bucket;
    }

    /**
     * English season bucket. Empty when the text has no season word.
     *
     * @param string $raw
     * @return string
     */
    private function normalizeSeasonValue($raw) {
        $value = FacetNormalizer::decode_label($raw);
        if ($value === '') {
            return '';
        }
        $value = str_ireplace(
            ['Pâques', 'Paques', 'Ostern', 'Oster', 'Frühling', 'Fruhling', 'Hiver', 'Printemps', 'Automne', 'Sommer', 'Herbst', 'Été', 'Ete'],
            ['Easter', 'Easter', 'Easter', 'Easter', 'Spring', 'Spring', 'Winter', 'Spring', 'Autumn', 'Summer', 'Autumn', 'Summer', 'Summer'],
            $value
        );
        $lower = strtolower($value);
        $buckets = [
            'easter' => 'Easter',
            'winter' => 'Winter',
            'spring' => 'Spring',
            'summer' => 'Summer',
            'autumn' => 'Autumn',
            'fall' => 'Autumn',
        ];
        foreach ($buckets as $needle => $label) {
            if (strpos($lower, $needle) !== false) {
                return $label;
            }
        }
        return '';
    }

    /**
     * @param string $value
     * @return bool
     */
    private function mentionsEaster($value) {
        $value = strtolower(FacetNormalizer::decode_label($value));
        if ($value === '') {
            return false;
        }
        foreach (['easter', 'pâques', 'paques', 'ostern', 'oster'] as $needle) {
            if (strpos($value, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $season
     * @param string $type
     * @return string
     */
    private function compositeLabel($season, $type) {
        $plurals = [
            ProductTypeClassifierService::TYPE_CAMP => 'Camps',
            ProductTypeClassifierService::TYPE_COURSE => 'Courses',
            ProductTypeClassifierService::TYPE_BIRTHDAY => 'Birthdays',
            ProductTypeClassifierService::TYPE_TOURNAMENT => 'Tournaments',
            ProductTypeClassifierService::TYPE_OTHER => 'Other',
        ];
        $typeLabel = isset($plurals[$type]) ? $plurals[$type] : (string) $type;
        if ($season === '' || $season === 'Unspecified') {
            return $typeLabel;
        }
        return $season . ' ' . $typeLabel;
    }

    /**
     * Canonical canton, then FR/DE/EN labels, then the product attribute.
     *
     * @param array<string,mixed> $row
     * @return string
     */
    private function regionLabel(array $row) {
        $meta = (isset($row['line_meta']) && is_array($row['line_meta'])) ? $row['line_meta'] : [];
        $facets = $this->facetNormalizer()->normalize_from_meta($meta);
        $region = isset($facets['region']) ? (string) $facets['region'] : '';
        if ($region === '' || $region === 'not_recorded') {
            $fromProduct = $this->cantonFromProduct($row);
            if ($fromProduct !== '') {
                $region = $this->facetNormalizer()->normalize_region($fromProduct);
            }
        }
        if ($region === '' || $region === 'not_recorded') {
            return 'Unknown';
        }
        return $region;
    }

    /**
     * @param array<string,mixed> $row
     * @return string
     */
    private function cantonFromProduct(array $row) {
        if (function_exists('intersoccer_reports_enrich_camp_canton_from_product')) {
            $entry = [
                'canton' => '',
                'product_id' => (int) ($row['product_id'] ?? 0),
                'variation_id' => (int) ($row['variation_id'] ?? 0),
                'order_item_id' => (int) ($row['order_item_id'] ?? 0),
            ];
            intersoccer_reports_enrich_camp_canton_from_product($entry);
            $canton = trim((string) ($entry['canton'] ?? ''));
            if ($canton !== '' && strcasecmp($canton, 'Unknown') !== 0) {
                return $canton;
            }
        }
        $variationId = (int) ($row['variation_id'] ?? 0);
        $productId = (int) ($row['product_id'] ?? 0);
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($variationId > 0 ? $variationId : $productId);
            $attr = $this->productCantonAttribute($product);
            if ($attr === '' && is_object($product) && method_exists($product, 'is_type') && $product->is_type('variation') && method_exists($product, 'get_parent_id')) {
                $attr = $this->productCantonAttribute(wc_get_product((int) $product->get_parent_id()));
            }
            if ($attr !== '') {
                return $attr;
            }
        }
        if (!function_exists('get_post_meta')) {
            return '';
        }
        foreach ([$variationId, $productId] as $id) {
            if ($id <= 0) {
                continue;
            }
            foreach (['attribute_pa_canton-region', 'pa_canton-region'] as $key) {
                $value = get_post_meta($id, $key, true);
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }
        return '';
    }

    /**
     * @param mixed $product
     * @return string
     */
    private function productCantonAttribute($product) {
        if (!is_object($product) || !method_exists($product, 'get_attribute')) {
            return '';
        }
        $attr = $product->get_attribute('pa_canton-region');
        if (!is_string($attr) || trim($attr) === '') {
            return '';
        }
        return trim($attr);
    }

    /**
     * @return FacetNormalizer
     */
    private function facetNormalizer() {
        if (!($this->facets instanceof FacetNormalizer)) {
            $this->facets = new FacetNormalizer();
        }
        return $this->facets;
    }

    /**
     * @param array<string,mixed> $row
     * @return float
     */
    private function netIncVat(array $row) {
        $net = (float) ($row['net_ex_vat'] ?? 0);
        $tax = (float) ($row['line_tax_amount'] ?? 0);
        $final = (float) ($row['final_price_raw'] ?? 0);
        $refund = (float) ($row['reimbursement_raw'] ?? 0);
        if ($net <= 0) {
            return 0.0;
        }
        if ($refund > 0 && $final > 0) {
            $tax = $tax * max(0.0, ($final - $refund) / $final);
        }
        return $net + $tax;
    }

    /**
     * @return array<int,string>
     */
    private static function typeOrder() {
        return [
            ProductTypeClassifierService::TYPE_CAMP,
            ProductTypeClassifierService::TYPE_COURSE,
            ProductTypeClassifierService::TYPE_BIRTHDAY,
            ProductTypeClassifierService::TYPE_TOURNAMENT,
            ProductTypeClassifierService::TYPE_OTHER,
        ];
    }

    /**
     * @param string $bucket
     * @return array<string,mixed>
     */
    private function emptyType($bucket) {
        $label = ($bucket === ProductTypeClassifierService::TYPE_OTHER) ? 'Other' : (string) $bucket;
        return [
            'key' => (string) $bucket,
            'label' => $label,
            'quantity' => 0,
            'gross_ex_vat' => 0.0,
            'discounts' => 0.0,
            'refunds' => 0.0,
            'net_ex_vat' => 0.0,
            'net_inc_vat' => 0.0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptySummary() {
        $types = [];
        foreach (self::typeOrder() as $bucket) {
            $types[] = $this->emptyType($bucket);
        }
        return [
            'order_count' => 0,
            'types' => $types,
            'products' => [],
            'regions' => [],
            'seasons' => [],
            'season_types' => [],
            'used_created_fallback' => false,
            'net_ex_vat' => 0.0,
            'net_inc_vat' => 0.0,
        ];
    }

    /**
     * @param string              $label
     * @param array<string,mixed> $onHold
     * @return string
     */
    private function renderOnHoldNote($label, array $onHold) {
        $count = (int) ($onHold['order_count'] ?? 0);
        $amount = $this->money((float) ($onHold['net_inc_vat'] ?? 0));
        return '<p>' . esc_html((string) $label) . ': ' . esc_html((string) $count)
            . ' orders, ' . esc_html($amount) . ' CHF inc-VAT. Not included in the totals.</p>';
    }

    /**
     * @param array<string,mixed> $summary
     * @return array{order_count:int,net_inc_vat:float}
     */
    private function onHoldFigures(array $summary) {
        return [
            'order_count' => (int) ($summary['order_count'] ?? 0),
            'net_inc_vat' => round((float) ($summary['net_inc_vat'] ?? 0), 2),
        ];
    }

    /**
     * Daily and campaign-to-date columns in one table. Inc-VAT is the amount shown.
     *
     * @param string              $labelHeading
     * @param array<string,mixed> $daily
     * @param array<string,mixed> $campaign
     * @param string              $collection types|products
     * @return string
     */
    private function renderComparisonTable($labelHeading, array $daily, array $campaign, $collection, $hideEmpty = false) {
        $dailyRows = (isset($daily[$collection]) && is_array($daily[$collection])) ? $daily[$collection] : [];
        $campaignRows = (isset($campaign[$collection]) && is_array($campaign[$collection])) ? $campaign[$collection] : [];
        $campaignByKey = [];
        foreach ($campaignRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $campaignByKey[(string) ($row['key'] ?? '')] = $row;
        }
        $pairs = [];
        $seen = [];
        foreach ($dailyRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            $seen[$key] = true;
            $pairs[] = [$row, isset($campaignByKey[$key]) ? $campaignByKey[$key] : null];
        }
        foreach ($campaignRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $pairs[] = [null, $row];
        }
        $html = '<table><thead><tr>';
        $html .= '<th>' . esc_html((string) $labelHeading) . '</th>';
        $html .= '<th>Daily qty</th><th>Daily inc-VAT</th><th>Campaign qty</th><th>Campaign inc-VAT</th>';
        $html .= '</tr></thead><tbody>';
        $shown = 0;
        $dailyQty = 0;
        $dailyInc = 0.0;
        $campaignQty = 0;
        $campaignInc = 0.0;
        foreach ($pairs as $pair) {
            $left = is_array($pair[0]) ? $pair[0] : [];
            $right = is_array($pair[1]) ? $pair[1] : [];
            $leftQty = (int) ($left['quantity'] ?? 0);
            $rightQty = (int) ($right['quantity'] ?? 0);
            $leftInc = (float) ($left['net_inc_vat'] ?? 0);
            $rightInc = (float) ($right['net_inc_vat'] ?? 0);
            if ($hideEmpty && $leftQty === 0 && $rightQty === 0) {
                continue;
            }
            $label = (string) ($left['label'] ?? '');
            if ($label === '') {
                $label = (string) ($right['label'] ?? '');
            }
            $html .= '<tr>';
            $html .= '<td>' . esc_html($label) . '</td>';
            $html .= '<td>' . esc_html((string) $leftQty) . '</td>';
            $html .= '<td>' . esc_html($this->money($leftInc)) . '</td>';
            $html .= '<td>' . esc_html((string) $rightQty) . '</td>';
            $html .= '<td>' . esc_html($this->money($rightInc)) . '</td>';
            $html .= '</tr>';
            $shown++;
            $dailyQty += $leftQty;
            $dailyInc += $leftInc;
            $campaignQty += $rightQty;
            $campaignInc += $rightInc;
        }
        if ($hideEmpty && $shown === 0) {
            if ($collection === 'regions') {
                $empty = 'No regions in this range.';
            } elseif ($collection === 'products') {
                $empty = 'No products sold in this range.';
            } else {
                $empty = 'None in this range.';
            }
            $html .= '<tr><td colspan="5">' . esc_html($empty) . '</td></tr>';
        }
        $html .= '<tr><td>Total</td>';
        $html .= '<td>' . esc_html((string) $dailyQty) . '</td>';
        $html .= '<td>' . esc_html($this->money($dailyInc)) . '</td>';
        $html .= '<td>' . esc_html((string) $campaignQty) . '</td>';
        $html .= '<td>' . esc_html($this->money($campaignInc)) . '</td></tr>';
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * @param mixed $raw
     * @return array<int,string>
     */
    private static function sanitizeOrderStatuses($raw) {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $status) {
            $status = (string) $status;
            if (preg_match('/^wc-[a-z0-9_-]+$/', $status) !== 1) {
                continue;
            }
            if (!in_array($status, $out, true)) {
                $out[] = $status;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $digest
     * @return string
     */
    private function subjectFor(array $digest) {
        $date = isset($digest['report_date']) ? (string) $digest['report_date'] : '';
        $prefix = !empty($digest['final_campaign']) ? 'Final ' : '';
        return $this->stripHeaderBreaks($prefix . 'Daily sales report ' . $date);
    }

    /**
     * @param array<int,string> $emails
     * @param string            $subject
     * @param string            $html
     * @return array{ok:bool,message:string}
     */
    private function deliver(array $emails, $subject, $html) {
        if (!function_exists('wp_mail') || $emails === []) {
            return ['ok' => false, 'message' => ''];
        }
        $errorMessage = '';
        $listener = function ($wpError) use (&$errorMessage) {
            if (is_object($wpError) && method_exists($wpError, 'get_error_message')) {
                $errorMessage = (string) $wpError->get_error_message();
            }
        };
        add_action('wp_mail_failed', $listener, 10, 1);
        $to = count($emails) === 1 ? $emails[0] : array_values($emails);
        $sent = wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
        remove_action('wp_mail_failed', $listener, 10);
        if (!$sent) {
            return ['ok' => false, 'message' => $errorMessage];
        }
        return ['ok' => true, 'message' => ''];
    }

    /**
     * @param string $code
     * @return array{coupon:array{start_unix:int,expires_unix:int}|null,reason:string}
     */
    private function couponStatus($code) {
        $code = trim((string) $code);
        if ($code === '') {
            return ['coupon' => null, 'reason' => 'coupon_missing'];
        }
        $raw = $this->loadCouponRecord($code);
        if (!is_array($raw)) {
            return ['coupon' => null, 'reason' => 'coupon_not_found'];
        }
        $start = $this->campaignStartUnix();
        $expires = $this->parseInstant(isset($raw['expires']) ? $raw['expires'] : null);
        if ($start === null) {
            return ['coupon' => null, 'reason' => 'campaign_start_missing'];
        }
        if ($expires === null) {
            return ['coupon' => null, 'reason' => 'coupon_missing_expiry'];
        }
        return [
            'coupon' => [
                'start_unix' => $start,
                'expires_unix' => $expires,
            ],
            'reason' => '',
        ];
    }

    /**
     * Window start is the campaign start setting at 00:00 Europe/Zurich.
     * A coupon loader may still return a start value; it is not the window.
     *
     * @return int|null
     */
    private function campaignStartUnix() {
        $ymd = $this->settings()['campaign_start'];
        if ($ymd === '') {
            return null;
        }
        return $this->parseInstant($ymd);
    }

    /**
     * @param string $code
     * @return array<string,mixed>|null
     */
    private function loadCouponRecord($code) {
        if ($this->couponLoader !== null) {
            $raw = call_user_func($this->couponLoader, $code);
            return is_array($raw) ? $raw : null;
        }
        return $this->loadCouponFromWoo($code);
    }

    /**
     * Expiry comes from WC_Coupon::get_date_expires(), then the date_expires
     * timestamp meta. WooCommerce does not store a coupon start date.
     *
     * @param string $code
     * @return array{expires:mixed}|null
     */
    private function loadCouponFromWoo($code) {
        if (!function_exists('wc_get_coupon_id_by_code') || !function_exists('get_post_meta')) {
            return null;
        }
        $id = (int) wc_get_coupon_id_by_code($code);
        if ($id <= 0) {
            return null;
        }
        $expires = null;
        if (class_exists('WC_Coupon')) {
            $coupon = new \WC_Coupon($id);
            if (is_object($coupon) && method_exists($coupon, 'get_date_expires')) {
                $expiry = $coupon->get_date_expires();
                if ($expiry instanceof \DateTimeInterface) {
                    $unix = $expiry->getTimestamp();
                    if ($unix > 0) {
                        $expires = $unix;
                    }
                }
            }
        }
        if ($expires === null) {
            $meta = get_post_meta($id, 'date_expires', true);
            if ($meta !== '' && $meta !== false && $meta !== null) {
                $expires = $meta;
            }
        }
        return [
            'expires' => self::normalizeStoredExpiry($expires),
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeStoredExpiry($value) {
        if ($value instanceof \DateTimeInterface) {
            return self::effectiveExpiryUnix($value->getTimestamp());
        }
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', (string) $value))) {
            $unix = (int) $value;
            if ($unix <= 0) {
                return $value;
            }
            return self::effectiveExpiryUnix($unix);
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function parseInstant($value) {
        if ($value instanceof \DateTimeInterface) {
            $unix = $value->getTimestamp();
            return $unix > 0 ? $unix : null;
        }
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value))) {
            $unix = (int) $value;
            return $unix > 0 ? $unix : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $dt = new \DateTimeImmutable($value . ' 00:00:00', new \DateTimeZone(self::TIMEZONE));
                return $dt->getTimestamp();
            }
            $dt = new \DateTimeImmutable($value, new \DateTimeZone(self::TIMEZONE));
            $unix = $dt->getTimestamp();
            return $unix > 0 ? $unix : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param string $reportDate
     * @return bool
     */
    private function wasSent($reportDate) {
        $state = $this->state();
        return isset($state['sent_dates'][$reportDate]);
    }

    /**
     * @param string             $reportDate
     * @param \DateTimeImmutable $now
     * @return void
     */
    private function markSent($reportDate, \DateTimeImmutable $now) {
        $state = $this->state();
        $state['sent_dates'][$reportDate] = $now->getTimestamp();
        $state['last_result'] = 'sent';
        $state['last_sent_at'] = $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i');
        update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * @param string $code
     * @return void
     */
    private function writeResult($code) {
        $state = $this->state();
        $state['last_result'] = $this->allowReason($code);
        update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * Log a reason code only. Addresses are never included.
     *
     * @param string $reason
     * @return void
     */
    private function logReason($reason) {
        $reason = $this->allowReason($reason);
        $this->logger->info('Daily sales email: ' . $reason, ['reason' => $reason]);
    }

    /**
     * @param string $reason
     * @return string
     */
    private function allowReason($reason) {
        $allowed = [
            '',
            'sent',
            'mail_failed',
            'empty_recipients',
            'invalid_recipient',
            'outside_window',
            'coupon_missing',
            'coupon_not_found',
            'coupon_missing_dates',
            'campaign_start_missing',
            'coupon_missing_expiry',
            'report_failed',
            'disabled',
            'before_send_time',
            'already_sent',
        ];
        return in_array((string) $reason, $allowed, true) ? (string) $reason : 'unknown';
    }

    /**
     * @param string $code
     * @return string
     */
    private function resultLabel($code) {
        $map = [
            '' => 'None',
            'sent' => 'Sent',
            'mail_failed' => 'Mail failed',
            'empty_recipients' => 'Not sent: the Send to list is empty',
            'invalid_recipient' => 'Not sent: the Send to list has no valid address',
            'outside_window' => 'Not sent: outside the coupon window',
            'coupon_missing' => 'Not sent: no coupon code is set',
            'coupon_not_found' => 'Not sent: the coupon was not found',
            'coupon_missing_dates' => 'Not sent: the coupon is missing a start or expiry',
            'campaign_start_missing' => 'Not sent: the campaign start date is not set',
            'coupon_missing_expiry' => 'Not sent: the coupon has no expiry date',
            'report_failed' => 'Not sent: the report could not be built',
            'unknown' => 'Not sent',
        ];
        return isset($map[$code]) ? $map[$code] : 'Not sent';
    }

    /**
     * @param \DateTimeImmutable|null $now
     * @return \DateTimeImmutable
     */
    private function asZurich($now) {
        if (!($now instanceof \DateTimeImmutable)) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        }
        return $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }

    /**
     * @return \DateTimeZone
     */
    private function siteTimezone() {
        if ($this->siteTimezone instanceof \DateTimeZone) {
            return $this->siteTimezone;
        }
        if (function_exists('wp_timezone')) {
            $tz = wp_timezone();
            if ($tz instanceof \DateTimeZone) {
                return $tz;
            }
        }
        return new \DateTimeZone('UTC');
    }

    /**
     * @return string
     */
    private function currentUserEmail() {
        if (!function_exists('wp_get_current_user')) {
            return '';
        }
        $user = wp_get_current_user();
        if (!is_object($user) || !isset($user->user_email)) {
            return '';
        }
        return $this->stripHeaderBreaks((string) $user->user_email);
    }

    /**
     * @param string $action
     * @return void
     */
    private function nonceField($action) {
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field($action);
            return;
        }
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce($action) : '';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
    }

    /**
     * @param string $nonce
     * @param string $action
     * @return bool
     */
    private function nonceOk($nonce, $action) {
        if (!function_exists('wp_verify_nonce')) {
            return false;
        }
        return (bool) wp_verify_nonce($nonce, $action);
    }

    /**
     * @param string $value
     * @return string
     */
    private function stripHeaderBreaks($value) {
        return str_replace(["\r", "\n", "\0"], '', (string) $value);
    }

    /**
     * @param float $amount
     * @return string
     */
    private function money($amount) {
        return number_format((float) $amount, 2, '.', '');
    }
}
