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
 * The message also attaches an .xlsx workbook: a campaign booking summary
 * and one row per paid order in the campaign window. Workbook amounts are
 * CHF incl. VAT. If that file cannot be built, the HTML message is still
 * sent without the attachment.
 * From name, From email, and Reply-To email are optional. Blank values
 * leave the site default sender and add no Reply-To header.
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
 * The yesterday section lists each paid order once, then one line per paid
 * booking. Booking lines show the product, Zurich paid time, venue, city,
 * and the amount paid including VAT. They do not show customer or player
 * names, email addresses, or order numbers. A new or returning flag uses an
 * earlier paid order for the same customer. Product names link to the
 * product page, and the coupon setting is added as a coupon query parameter
 * when the code matches the front-end format. Region rows use display
 * buckets for this email (Geneva/Versoix, Vaud, Basel, Zug/Cham, Zurich, and
 * Unknown). The season-and-type table keeps the daily-versus-campaign
 * comparison and always shows Autumn, Winter, and Spring courses plus Easter
 * and Summer camps, even when the quantity is zero. Empty Other and Unknown
 * rows stay hidden. Every amount in the email is what the customer paid,
 * including VAT. There is no year-on-year comparison.
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
     * Optional workbook builder. Null uses the production writer.
     * The callable receives the digest and returns the xlsx path, or throws.
     *
     * @var callable|null
     */
    private $workbookBuilder;

    /**
     * @param Logger|null                  $logger
     * @param FinancialReportService|null  $reports
     * @param callable|null                $couponLoader
     * @param \DateTimeZone|null           $siteTimezone Site timezone used to read order created dates.
     * @param callable|null                $workbookBuilder
     */
    public function __construct($logger = null, $reports = null, $couponLoader = null, $siteTimezone = null, $workbookBuilder = null) {
        $this->logger = ($logger instanceof Logger) ? $logger : new Logger();
        $this->reports = ($reports instanceof FinancialReportService) ? $reports : null;
        $this->couponLoader = is_callable($couponLoader) ? $couponLoader : null;
        $this->siteTimezone = ($siteTimezone instanceof \DateTimeZone) ? $siteTimezone : null;
        $this->workbookBuilder = is_callable($workbookBuilder) ? $workbookBuilder : null;
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

        $delivery = $this->deliver($parsed['emails'], $this->subjectFor($digest), $this->renderHtml($digest), $digest);
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
            $this->renderHtml($digest),
            $digest
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
            $this->renderSampleHtml($digest),
            $digest
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
        echo '<p>Totals are CHF incl. VAT for processing and completed orders with a paid date in the window. On-hold orders are counted separately by the date they were created and are not included. Orders with no paid date are omitted from the paid totals.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">';
        $this->nonceField(self::NONCE_SETTINGS);
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Enable</th><td><label><input type="checkbox" name="bf_enabled" value="1"' . $checked . ' /> Send the daily email</label></td></tr>';
        echo '<tr><th scope="row"><label for="bf_recipients">Send to</label></th><td>';
        echo '<textarea name="bf_recipients" id="bf_recipients" rows="3" cols="50" class="large-text">' . esc_html($settings['recipients']) . '</textarea>';
        echo '<p class="description">Comma-separated email addresses. Empty entries are skipped. Invalid addresses are dropped, and the email sends to the valid ones. Nothing is sent when no valid address remains.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="bf_from_name">From name</label></th><td>';
        echo '<input type="text" name="bf_from_name" id="bf_from_name" value="' . esc_attr($settings['from_name']) . '" class="regular-text" />';
        echo '<p class="description">Optional. Used with From email. Leave it empty to use the site name. Leave From email empty to keep the site default sender.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="bf_from_email">From email</label></th><td>';
        echo '<input type="text" name="bf_from_email" id="bf_from_email" value="' . esc_attr($settings['from_email']) . '" class="regular-text" />';
        echo '<p class="description">Optional. When this is a valid email, the message is sent from this address. Leave it empty to keep the site default sender.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="bf_reply_to">Reply-To email</label></th><td>';
        echo '<input type="text" name="bf_reply_to" id="bf_reply_to" value="' . esc_attr($settings['reply_to']) . '" class="regular-text" />';
        echo '<p class="description">Optional. When this is a valid email, replies go to this address. Leave it empty to send no Reply-To header.</p>';
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
        $campaignReport = ['data' => []];
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
                $loadedCampaign = $this->loadReport($campaignStart, $reportDate, self::paidOrderStatuses());
                $campaignReport = is_array($loadedCampaign) ? $loadedCampaign : ['data' => []];
                $campaign = $this->summarize($campaignReport);
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
            'daily_orders' => $this->orderList(is_array($dailyReport) ? $dailyReport : []),
            'booking_lines' => $this->bookingLines(is_array($dailyReport) ? $dailyReport : []),
            'coupon_code' => $this->urlCouponCode($this->settings()['coupon_code']),
            'campaign' => $campaign,
            'daily_on_hold' => $dailyOnHold,
            'campaign_on_hold' => $campaignOnHold,
            'attention_orders' => $this->attentionOrders($this->attentionWindowStart($campaignStart, $reportDate), $reportDate),
            'low_bookings' => $coverage['low'],
            'zero_bookings' => $coverage['zero'],
            'used_created_fallback' => false,
            'final_campaign' => $finalCampaign,
            'booking_report_url' => $this->bookingReportUrl($reportDate),
            'workbook' => $this->workbookFromReport(
                is_array($campaignReport) ? $campaignReport : [],
                $reportDate,
                $campaignStart,
                (string) $this->settings()['coupon_code']
            ),
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
                    'region' => 'Geneva/Versoix',
                    'count' => 1,
                ],
                [
                    'label' => 'Sample Course D',
                    'season' => 'Spring',
                    'type' => 'Course',
                    'region' => 'Vaud',
                    'count' => 2,
                ],
                [
                    'label' => 'Sample Tournament B',
                    'season' => 'Easter',
                    'type' => 'Tournament',
                    'region' => 'Basel',
                    'count' => 1,
                ],
            ],
            'zero_bookings' => [
                [
                    'label' => 'Sample Camp F',
                    'season' => 'Easter',
                    'type' => 'Camp',
                    'region' => 'Zug/Cham',
                    'count' => 0,
                ],
            ],
            'used_created_fallback' => false,
            'final_campaign' => true,
            'booking_report_url' => 'https://example.com/sample-booking-report/900001',
            'coupon_code' => 'sample-code',
            'workbook' => $this->sampleWorkbook($lines, '2026-11-01', '2026-11-10', 'sample-code'),
            'booking_lines' => $this->sampleBookingLines($lines),
            'daily_orders' => [
                [
                    'paid_at' => '2026-11-10 09:15',
                    'order_number' => '900201',
                    'products' => 'Sample Camp A; Sample Camp B',
                    'venues' => 'Hall North; Hall West',
                    'net_inc_vat' => 200.00,
                    'customer_type' => 'Returning',
                    'attribution' => 'Organic: Google',
                    'language' => 'fr',
                ],
                [
                    'paid_at' => '2026-11-10 11:40',
                    'order_number' => '900202',
                    'products' => 'Sample Camp C; Sample Camp D',
                    'venues' => 'Hall South',
                    'net_inc_vat' => 225.00,
                    'customer_type' => 'New',
                    'attribution' => 'Direct',
                    'language' => 'de',
                ],
                [
                    'paid_at' => '2026-11-10 14:05',
                    'order_number' => '900203',
                    'products' => 'Sample Course A; Sample Course B; Sample Course C',
                    'venues' => 'Hall East',
                    'net_inc_vat' => 185.00,
                    'customer_type' => 'Returning',
                    'attribution' => 'Source: Fb',
                    'language' => 'en',
                ],
                [
                    'paid_at' => '2026-11-10 16:20',
                    'order_number' => '900204',
                    'products' => 'Sample Venue B; Sample Birthday A; Sample Tournament A',
                    'venues' => 'Hall Central',
                    'net_inc_vat' => 147.00,
                    'customer_type' => 'New',
                    'attribution' => 'Unknown',
                    'language' => 'Unknown',
                ],
            ],
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
            ['product_key' => 'p:sample-camp-a', 'product' => 'Sample Camp A', 'season' => 'Winter', 'type' => 'Camp', 'type_plural' => 'Camps', 'region' => 'Geneva/Versoix', 'daily_qty' => 2, 'daily_inc' => 120.00, 'campaign_qty' => 5, 'campaign_inc' => 300.00],
            ['product_key' => 'p:sample-camp-b', 'product' => 'Sample Camp B', 'season' => 'Summer', 'type' => 'Camp', 'type_plural' => 'Camps', 'region' => 'Vaud', 'daily_qty' => 1, 'daily_inc' => 80.00, 'campaign_qty' => 3, 'campaign_inc' => 240.00],
            ['product_key' => 'p:sample-camp-c', 'product' => 'Sample Camp C', 'season' => 'Easter', 'type' => 'Camp', 'type_plural' => 'Camps', 'region' => 'Basel', 'daily_qty' => 2, 'daily_inc' => 150.00, 'campaign_qty' => 4, 'campaign_inc' => 300.00],
            ['product_key' => 'p:sample-camp-d', 'product' => 'Sample Camp D', 'season' => 'Easter', 'type' => 'Camp', 'type_plural' => 'Camps', 'region' => 'Zug/Cham', 'daily_qty' => 1, 'daily_inc' => 75.00, 'campaign_qty' => 2, 'campaign_inc' => 150.00],
            ['product_key' => 'p:sample-course-a', 'product' => 'Sample Course A', 'season' => 'Spring', 'type' => 'Course', 'type_plural' => 'Courses', 'region' => 'Zurich', 'daily_qty' => 3, 'daily_inc' => 90.00, 'campaign_qty' => 6, 'campaign_inc' => 180.00],
            ['product_key' => 'p:sample-course-b', 'product' => 'Sample Course B', 'season' => 'Autumn', 'type' => 'Course', 'type_plural' => 'Courses', 'region' => 'Geneva/Versoix', 'daily_qty' => 1, 'daily_inc' => 40.00, 'campaign_qty' => 2, 'campaign_inc' => 80.00],
            ['product_key' => 'p:sample-course-c', 'product' => 'Sample Course C', 'season' => 'Winter', 'type' => 'Course', 'type_plural' => 'Courses', 'region' => 'Vaud', 'daily_qty' => 1, 'daily_inc' => 55.00, 'campaign_qty' => 2, 'campaign_inc' => 110.00],
            ['product_key' => 'p:sample-venue-b', 'product' => 'Sample Venue B', 'season' => 'Winter', 'type' => 'Course', 'type_plural' => 'Courses', 'region' => 'Basel', 'daily_qty' => 2, 'daily_inc' => 64.00, 'campaign_qty' => 4, 'campaign_inc' => 128.00],
            ['product_key' => 'p:sample-birthday-a', 'product' => 'Sample Birthday A', 'season' => 'Spring', 'type' => 'Birthday', 'type_plural' => 'Birthdays', 'region' => 'Unknown', 'daily_qty' => 1, 'daily_inc' => 35.00, 'campaign_qty' => 1, 'campaign_inc' => 35.00],
            ['product_key' => 'p:sample-tournament-a', 'product' => 'Sample Tournament A', 'season' => 'Summer', 'type' => 'Tournament', 'type_plural' => 'Tournaments', 'region' => 'Zug/Cham', 'daily_qty' => 1, 'daily_inc' => 48.00, 'campaign_qty' => 2, 'campaign_inc' => 96.00],
        ];
    }

    /**
     * One invented booking per sample product that sold yesterday.
     * Amounts are the same VAT-included figures as the daily summary.
     * Permalinks stay on the example test host. This does not read orders.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function sampleBookingLines(array $lines) {
        $out = [];
        $index = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (int) ($line['daily_qty'] ?? 0);
            $inc = round((float) ($line['daily_inc'] ?? 0), 2);
            if ($qty <= 0 || $inc <= 0) {
                continue;
            }
            $slug = strtolower((string) ($line['product_key'] ?? 'sample'));
            if (strpos($slug, 'p:') === 0) {
                $slug = substr($slug, 2);
            }
            $slug = preg_replace('/[^a-z0-9-]+/', '', $slug);
            if (!is_string($slug) || $slug === '') {
                $slug = 'sample';
            }
            $minute = 15 + ($index * 7);
            $hour = 9 + (int) floor($minute / 60);
            $minute = $minute % 60;
            $permalink = 'https://example.test/' . $slug . '/';
            if ($index === 1) {
                $permalink .= '?view=week';
            }
            $out[] = [
                'product' => (string) ($line['product'] ?? 'Sample'),
                'paid_at' => sprintf('2026-11-10 %02d:%02d', $hour, $minute),
                'venue' => 'Example Park',
                'city' => 'Sampletown',
                'amount_inc_vat' => $inc,
                'customer' => ($index % 2 === 0) ? 'New' : 'Returning',
                'permalink' => $permalink,
            ];
            $index++;
        }
        return $out;
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
        $html .= '<p><strong>' . esc_html($this->money((float) ($daily['net_inc_vat'] ?? 0))) . ' CHF</strong> incl. VAT</p>';
        $html .= '<p>Orders: ' . esc_html((string) (int) ($daily['order_count'] ?? 0)) . '</p>';
        $html .= $this->renderOnHoldNote(
            'On hold yesterday',
            isset($digest['daily_on_hold']) && is_array($digest['daily_on_hold']) ? $digest['daily_on_hold'] : []
        );
        $html .= '<h3>Paid orders</h3>';
        $orders = (isset($digest['daily_orders']) && is_array($digest['daily_orders'])) ? $digest['daily_orders'] : [];
        $html .= $this->renderOrderList($orders);
        $html .= '<h3>Bookings</h3>';
        $bookingLines = (isset($digest['booking_lines']) && is_array($digest['booking_lines'])) ? $digest['booking_lines'] : [];
        $couponCode = isset($digest['coupon_code']) ? (string) $digest['coupon_code'] : '';
        $html .= $this->renderBookingLines($bookingLines, $couponCode);
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
            $html .= '<p>Campaign total: ' . esc_html($this->money((float) ($campaign['net_inc_vat'] ?? 0))) . ' CHF incl. VAT.</p>';
        }
        $url = (string) ($digest['booking_report_url'] ?? '');
        $html .= '<p><a href="' . esc_url($url) . '">Booking report for this day</a></p>';
        $html .= '<p>Amounts are CHF incl. VAT: what the customer paid after discounts and refunds.</p>';
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
     * @return array{enabled:bool,recipients:string,send_time:string,coupon_code:string,low_booking_threshold:int,campaign_start:string,from_name:string,from_email:string,reply_to:string}
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
            'from_name' => $this->cleanSenderName(isset($stored['from_name']) ? $stored['from_name'] : ''),
            'from_email' => trim($this->stripHeaderControls(isset($stored['from_email']) ? (string) $stored['from_email'] : '')),
            'reply_to' => trim($this->stripHeaderControls(isset($stored['reply_to']) ? (string) $stored['reply_to'] : '')),
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
        $sender = $this->senderFieldsFromPost($post, $this->settings());
        $settings = [
            'enabled' => !empty($post['bf_enabled']),
            'low_booking_threshold' => $threshold,
            'recipients' => $keptRecipients,
            'send_time' => $sendTime,
            'coupon_code' => $code,
            'campaign_start' => $campaignStart,
            'from_name' => $sender['from_name'],
            'from_email' => $sender['from_email'],
            'reply_to' => $sender['reply_to'],
        ];
        update_option(self::OPTION_SETTINGS, $settings, false);
        self::syncScheduleToValue($settings);
        $senderNotice = $sender['notice'];
        if (!$parsedRecipients['ok'] && $parsedRecipients['reason'] === 'empty_recipients') {
            return $this->finishSettingsSave(false, 'empty_recipients', 'The Send to list is empty, so nothing will be sent.', $senderNotice);
        }
        if (!$parsedRecipients['ok']) {
            $message = 'No valid email address remains, so nothing will be sent.';
            if ($droppedNotice !== []) {
                $message .= ' Removed: ' . implode(', ', $droppedNotice);
            }
            return $this->finishSettingsSave(false, 'invalid_recipient', $message, $senderNotice);
        }
        if ($droppedNotice !== []) {
            return $this->finishSettingsSave(
                true,
                'saved',
                'Settings saved. Removed invalid addresses: ' . implode(', ', $droppedNotice),
                $senderNotice
            );
        }
        return $this->finishSettingsSave(true, 'saved', 'Settings saved.', $senderNotice);
    }

    /**
     * An invalid address keeps the previous valid value, or blank when the
     * previous value is also invalid. The notice names the field and does
     * not include the rejected text.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $previous
     * @return array{from_name:string,from_email:string,reply_to:string,notice:string}
     */
    private function senderFieldsFromPost(array $post, array $previous) {
        $notices = [];
        return [
            'from_name' => $this->cleanSenderName(isset($post['bf_from_name']) ? $post['bf_from_name'] : ''),
            'from_email' => $this->storedEmailField(
                isset($post['bf_from_email']) ? $post['bf_from_email'] : '',
                isset($previous['from_email']) ? $previous['from_email'] : '',
                'From email',
                $notices
            ),
            'reply_to' => $this->storedEmailField(
                isset($post['bf_reply_to']) ? $post['bf_reply_to'] : '',
                isset($previous['reply_to']) ? $previous['reply_to'] : '',
                'Reply-To email',
                $notices
            ),
            'notice' => implode(' ', $notices),
        ];
    }

    /**
     * @param mixed             $raw
     * @param mixed             $previous
     * @param string            $label
     * @param array<int,string> $notices
     * @return string
     */
    private function storedEmailField($raw, $previous, $label, array &$notices) {
        $clean = trim($this->stripHeaderControls((string) $raw));
        if ($clean === '') {
            return '';
        }
        $valid = $this->validHeaderEmail($clean);
        if ($valid !== '') {
            return $valid;
        }
        $notices[] = $label . ' is not a valid email address, so it was not saved. The previous value was kept.';
        return $this->validHeaderEmail($previous);
    }

    /**
     * @param bool   $ok
     * @param string $code
     * @param string $message
     * @param string $senderNotice
     * @return array{ok:bool,code:string,message:string}
     */
    private function finishSettingsSave($ok, $code, $message, $senderNotice) {
        $senderNotice = trim((string) $senderNotice);
        if ($senderNotice !== '' && $ok) {
            $ok = false;
            $code = 'invalid_sender';
        }
        return [
            'ok' => $ok,
            'code' => $code,
            'message' => $this->withSenderNotice($message, $senderNotice),
        ];
    }

    /**
     * @param string $message
     * @param string $notice
     * @return string
     */
    private function withSenderNotice($message, $notice) {
        $notice = trim((string) $notice);
        if ($notice === '') {
            return (string) $message;
        }
        $message = trim((string) $message);
        if ($message === '') {
            return $notice;
        }
        return $message . ' ' . $notice;
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

            $regionLabel = $this->regionDisplayBucket($this->regionLabel($row));
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
            . ' orders, ' . esc_html($amount) . ' CHF incl. VAT. Not included in the totals.</p>';
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
     * One row per paid order in the daily window. Several product names on
     * the same order are joined with "; ". Venues use the same join.
     *
     * @param array<string,mixed> $report
     * @return array<int,array<string,mixed>>
     */
    private function orderList(array $report) {
        $rows = isset($report['data']) && is_array($report['data']) ? $report['data'] : [];
        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = (int) ($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            if (!isset($grouped[$orderId])) {
                $grouped[$orderId] = [
                    'paid_gmt' => isset($row['date_paid_gmt']) ? (string) $row['date_paid_gmt'] : '',
                    'created_local' => isset($row['order_date_local']) ? (string) $row['order_date_local'] : '',
                    'products' => [],
                    'venues' => [],
                    'net_ex_vat' => 0.0,
                    'net_inc_vat' => 0.0,
                ];
            }
            $name = trim((string) ($row['order_item_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['class_name'] ?? ''));
            }
            if ($name !== '') {
                $grouped[$orderId]['products'][] = $name;
            }
            $venue = trim((string) ($row['venue'] ?? ''));
            if ($venue !== '' && strcasecmp($venue, 'N/A') !== 0) {
                $grouped[$orderId]['venues'][] = $venue;
            }
            $grouped[$orderId]['net_ex_vat'] += (float) ($row['net_ex_vat'] ?? 0);
            $grouped[$orderId]['net_inc_vat'] += $this->netIncVat($row);
        }
        $list = [];
        foreach ($grouped as $orderId => $order) {
            $paidGmt = (string) $order['paid_gmt'];
            $facts = $this->orderFacts((int) $orderId, $paidGmt);
            $list[] = [
                'paid_at' => $this->zurichPaidLabel($paidGmt, (string) $order['created_local']),
                'order_number' => (string) $facts['order_number'],
                'products' => $this->joinLabels($order['products'], 'Unknown'),
                'venues' => $this->joinLabels($order['venues'], 'Unknown'),
                'net_ex_vat' => round((float) $order['net_ex_vat'], 2),
                'net_inc_vat' => round((float) $order['net_inc_vat'], 2),
                'customer_type' => (string) $facts['customer_type'],
                'attribution' => (string) $facts['attribution'],
                'language' => (string) $facts['language'],
            ];
        }
        usort($list, function ($a, $b) {
            $byTime = strcmp((string) $a['paid_at'], (string) $b['paid_at']);
            if ($byTime !== 0) {
                return $byTime;
            }
            return strcmp((string) $a['order_number'], (string) $b['order_number']);
        });
        return $list;
    }

    /**
     * @param int    $orderId
     * @param string $paidGmt
     * @return array{order_number:string,customer_type:string,attribution:string,language:string,status:string,currency:string,customer:string}
     */
    private function orderFacts($orderId, $paidGmt) {
        $order = null;
        if (function_exists('wc_get_order')) {
            $loaded = wc_get_order((int) $orderId);
            if (is_object($loaded)) {
                $order = $loaded;
            }
        }
        $paidUnix = (ctype_digit((string) $paidGmt)) ? (int) $paidGmt : 0;
        $number = (string) (int) $orderId;
        if (is_object($order) && method_exists($order, 'get_order_number')) {
            $custom = trim((string) $order->get_order_number());
            if ($custom !== '') {
                $number = $custom;
            }
        }
        return [
            'order_number' => $number,
            'customer_type' => $this->customerTypeLabel($order, (int) $orderId, $paidUnix),
            'attribution' => $this->attributionLabel($order, (int) $orderId),
            'language' => $this->orderLanguageLabel($order, (int) $orderId),
            'status' => $this->orderStatusLabel($order),
            'currency' => $this->orderCurrency($order),
            'customer' => $this->orderCustomerName($order),
        ];
    }

    /**
     * Billing first and last name from the order already loaded above.
     * Blank when neither name is set. Not written to the log.
     *
     * @param object|null $order
     * @return string
     */
    private function orderCustomerName($order) {
        if (!is_object($order)) {
            return '';
        }
        $first = '';
        $last = '';
        if (method_exists($order, 'get_billing_first_name')) {
            $first = trim((string) $order->get_billing_first_name());
        }
        if (method_exists($order, 'get_billing_last_name')) {
            $last = trim((string) $order->get_billing_last_name());
        }
        return trim($first . ' ' . $last);
    }

    /**
     * @param object|null $order
     * @return string
     */
    private function orderStatusLabel($order) {
        if (!is_object($order) || !method_exists($order, 'get_status')) {
            return '';
        }
        $status = trim((string) $order->get_status());
        if ($status === '') {
            return '';
        }
        if (strpos($status, 'wc-') === 0) {
            $status = substr($status, 3);
        }
        return strtoupper(substr($status, 0, 1)) . substr($status, 1);
    }

    /**
     * @param object|null $order
     * @return string
     */
    private function orderCurrency($order) {
        if (!is_object($order) || !method_exists($order, 'get_currency')) {
            return '';
        }
        return trim((string) $order->get_currency());
    }

    /**
     * Paid instant in Europe/Zurich. The created date is the fallback, using
     * the same site-local conversion as zurichCreatedLabel().
     *
     * @param string $paidGmt
     * @param string $createdLocal
     * @return string
     */
    private function zurichPaidLabel($paidGmt, $createdLocal) {
        $paidGmt = trim((string) $paidGmt);
        if ($paidGmt !== '' && $paidGmt !== '0' && ctype_digit($paidGmt)) {
            try {
                $dt = new \DateTimeImmutable('@' . $paidGmt);
            } catch (\Exception $e) {
                return $this->zurichCreatedLabel($createdLocal);
            }
            return $dt->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i');
        }
        return $this->zurichCreatedLabel($createdLocal);
    }

    /**
     * WooCommerce's own returning-customer flag when the order exposes one.
     * Otherwise a customer account with an earlier paid order (same strict
     * paid statuses) is Returning, and everyone else is New. Guests have no
     * customer id, so this fallback labels them New. It does not match
     * guests by billing email.
     *
     * @param object|null $order
     * @param int         $orderId
     * @param int         $paidUnix
     * @return string
     */
    private function customerTypeLabel($order, $orderId, $paidUnix) {
        if (is_object($order) && method_exists($order, 'is_returning_customer')) {
            return $order->is_returning_customer() ? 'Returning' : 'New';
        }
        $customerId = 0;
        if (is_object($order) && method_exists($order, 'get_customer_id')) {
            $customerId = (int) $order->get_customer_id();
        }
        if ($customerId <= 0) {
            $customerId = (int) $this->orderMetaString($order, $orderId, '_customer_user');
        }
        if ($customerId <= 0) {
            return 'New';
        }
        if ($this->hasEarlierPaidOrder($customerId, (int) $orderId, (int) $paidUnix)) {
            return 'Returning';
        }
        return 'New';
    }

    /**
     * @param int $customerId
     * @param int $orderId
     * @param int $paidUnix
     * @return bool
     */
    private function hasEarlierPaidOrder($customerId, $orderId, $paidUnix) {
        if (!function_exists('wc_get_orders') || (int) $customerId <= 0 || (int) $paidUnix <= 0) {
            return false;
        }
        $statuses = [];
        foreach (self::paidOrderStatuses() as $status) {
            $status = (string) $status;
            if (strpos($status, 'wc-') === 0) {
                $status = substr($status, 3);
            }
            if ($status !== '' && !in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }
        $ids = wc_get_orders([
            'customer' => (int) $customerId,
            'customer_id' => (int) $customerId,
            'status' => $statuses,
            'exclude' => [(int) $orderId],
            'limit' => 1,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'ASC',
            'date_paid' => '<' . (int) $paidUnix,
        ]);
        if (!is_array($ids)) {
            return false;
        }
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== (int) $orderId) {
                return true;
            }
        }
        return false;
    }

    /**
     * WooCommerce order-attribution meta. Direct and organic keep those words.
     * Any other stored source is shown as "Source: Name". Missing meta is Unknown.
     *
     * @param object|null $order
     * @param int         $orderId
     * @return string
     */
    private function attributionLabel($order, $orderId) {
        $type = strtolower($this->orderMetaString($order, $orderId, '_wc_order_attribution_source_type'));
        $source = $this->orderMetaString($order, $orderId, '_wc_order_attribution_utm_source');
        if ($type === '' && $source === '') {
            return 'Unknown';
        }
        if ($type === 'organic') {
            $name = $source !== '' ? $this->attributionSourceName($source) : 'Unknown';
            return 'Organic: ' . $name;
        }
        if ($type === 'typein' || $type === 'direct') {
            return 'Direct';
        }
        if ($source !== '') {
            return 'Source: ' . $this->attributionSourceName($source);
        }
        return 'Source: ' . $this->attributionSourceName($type);
    }

    /**
     * @param string $source
     * @return string
     */
    private function attributionSourceName($source) {
        $source = trim((string) $source);
        if ($source === '') {
            return 'Unknown';
        }
        return strtoupper(substr($source, 0, 1)) . substr($source, 1);
    }

    /**
     * Same meta key as intersoccer_roster_move_order_language(). That helper
     * falls back to the site default. This email shows Unknown when the stored
     * language is missing or is not en, fr, or de.
     *
     * @param object|null $order
     * @param int         $orderId
     * @return string
     */
    private function orderLanguageLabel($order, $orderId) {
        $raw = strtolower($this->orderMetaString($order, $orderId, 'wpml_language'));
        if (strlen($raw) >= 2) {
            $raw = substr($raw, 0, 2);
        }
        if ($raw === 'en' || $raw === 'fr' || $raw === 'de') {
            return $raw;
        }
        return 'Unknown';
    }

    /**
     * @param object|null $order
     * @param int         $orderId
     * @param string      $key
     * @return string
     */
    private function orderMetaString($order, $orderId, $key) {
        if (is_object($order) && method_exists($order, 'get_meta')) {
            $value = $order->get_meta($key, true);
            if (is_string($value) || is_numeric($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        if (!function_exists('get_post_meta')) {
            return '';
        }
        $value = get_post_meta((int) $orderId, (string) $key, true);
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }
        return '';
    }

    /**
     * @param array<int,string> $labels
     * @param string            $empty
     * @return string
     */
    private function joinLabels(array $labels, $empty) {
        $clean = [];
        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label === '' || in_array($label, $clean, true)) {
                continue;
            }
            $clean[] = $label;
        }
        if ($clean === []) {
            return (string) $empty;
        }
        return implode('; ', $clean);
    }

    /**
     * One row per paid booking line for the reported day. Identity used to
     * flag new or returning customers stays in memory and is not returned.
     *
     * @param array<string,mixed> $report
     * @return array<int,array<string,mixed>>
     */
    private function bookingLines(array $report) {
        $rows = isset($report['data']) && is_array($report['data']) ? $report['data'] : [];
        $pending = [];
        $orderIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = (int) ($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $orderIds[$orderId] = $orderId;
            $pending[] = $row;
        }
        $customers = $this->loadCustomerUsers(array_values($orderIds));
        $orders = [];
        foreach ($pending as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            if (isset($orders[$orderId])) {
                continue;
            }
            $orders[$orderId] = [
                'instant' => $this->orderInstant(
                    isset($row['date_paid_gmt']) ? (string) $row['date_paid_gmt'] : '',
                    isset($row['order_date_local']) ? (string) $row['order_date_local'] : ''
                ),
                'customer_id' => isset($customers[$orderId]) ? (int) $customers[$orderId] : 0,
                'email' => $this->rowEmail($row),
            ];
        }
        $flags = $this->customerFlags($orders);
        $lines = [];
        foreach ($pending as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            $name = trim((string) ($row['order_item_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['class_name'] ?? ''));
            }
            if ($name === '') {
                $name = 'Unknown';
            }
            $venue = trim((string) ($row['venue'] ?? ''));
            if ($venue === '' || strcasecmp($venue, 'N/A') === 0) {
                $venue = 'Unknown';
            }
            $city = $this->lineCity($row);
            if ($city === '') {
                $city = 'Unknown';
            }
            $lines[] = [
                'product' => $name,
                'paid_at' => $this->zurichPaidLabel(
                    isset($row['date_paid_gmt']) ? (string) $row['date_paid_gmt'] : '',
                    isset($row['order_date_local']) ? (string) $row['order_date_local'] : ''
                ),
                'venue' => $venue,
                'city' => $city,
                'amount_inc_vat' => round($this->netIncVat($row), 2),
                'customer' => (isset($flags[$orderId]) && $flags[$orderId] === 'Returning') ? 'Returning' : 'New',
                'permalink' => $this->frontPermalink((int) ($row['product_id'] ?? 0)),
            ];
        }
        usort($lines, function ($a, $b) {
            $byTime = strcmp((string) $a['paid_at'], (string) $b['paid_at']);
            if ($byTime !== 0) {
                return $byTime;
            }
            return strcmp((string) $a['product'], (string) $b['product']);
        });
        return $lines;
    }

    /**
     * @param array<string,mixed> $row
     * @return string
     */
    private function rowEmail(array $row) {
        $email = trim((string) ($row['booker_email'] ?? ''));
        if ($email === '' || strcasecmp($email, 'N/A') === 0) {
            return '';
        }
        return $email;
    }

    /**
     * @param array<string,mixed> $row
     * @return string
     */
    private function lineCity(array $row) {
        $meta = (isset($row['line_meta']) && is_array($row['line_meta'])) ? $row['line_meta'] : [];
        $keys = ['City', 'pa_city', 'attribute_pa_city', 'Ville', 'Stadt'];
        $value = trim((string) $this->facetNormalizer()->meta_value($meta, $keys));
        if ($value === '' || strcasecmp($value, 'N/A') === 0) {
            return '';
        }
        return $value;
    }

    /**
     * @param string $paidRaw
     * @param string $createdLocal
     * @return int
     */
    private function orderInstant($paidRaw, $createdLocal) {
        $paidRaw = trim((string) $paidRaw);
        if ($paidRaw !== '' && $paidRaw !== '0' && ctype_digit($paidRaw)) {
            return (int) $paidRaw;
        }
        $createdLocal = trim((string) $createdLocal);
        if ($createdLocal === '') {
            return 0;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdLocal, $this->siteTimezone());
        if (!($dt instanceof \DateTimeImmutable)) {
            return 0;
        }
        return $dt->getTimestamp();
    }

    /**
     * @param string $email
     * @return string
     */
    private function guestEmailKey($email) {
        $email = trim((string) $email);
        if ($email === '' || strcasecmp($email, 'N/A') === 0 || strpos($email, '@') === false) {
            return '';
        }
        return strtolower($email);
    }

    /**
     * @param string $status
     * @return bool
     */
    private function isPaidOrderStatus($status) {
        $status = (string) $status;
        if (strpos($status, 'wc-') !== 0) {
            $status = 'wc-' . $status;
        }
        return in_array($status, self::paidOrderStatuses(), true);
    }

    /**
     * Batch-read customer user ids. Billing email is already on the report row.
     *
     * @param array<int,int> $orderIds
     * @return array<int,int>
     */
    private function loadCustomerUsers(array $orderIds) {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($orderIds === []) {
            return [];
        }
        $wpdb = $this->db();
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($orderIds), '%d'));
        $sql = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND post_id IN ($placeholders)";
        $prepared = $wpdb->prepare($sql, $orderIds);
        $rows = $wpdb->get_results($prepared);
        $out = [];
        foreach ((array) $rows as $row) {
            if (is_array($row)) {
                $row = (object) $row;
            }
            if (!is_object($row)) {
                continue;
            }
            $orderId = (int) ($row->post_id ?? 0);
            $customerId = (int) ($row->meta_value ?? 0);
            if ($orderId > 0 && $customerId > 0) {
                $out[$orderId] = $customerId;
            }
        }
        return $out;
    }

    /**
     * One read of earlier completed and processing orders for the customers
     * in this digest. Guest emails are compared in PHP, not in SQL.
     *
     * @param array<int,int> $userIds
     * @param bool           $hasGuests
     * @param int            $maxInstant
     * @return array<int,array<string,mixed>>
     */
    private function loadPaidHistory(array $userIds, $hasGuests, $maxInstant) {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $maxInstant = (int) $maxInstant;
        if ($maxInstant <= 0 || ($userIds === [] && !$hasGuests)) {
            return [];
        }
        $wpdb = $this->db();
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return [];
        }
        $statuses = self::paidOrderStatuses();
        if ($statuses === []) {
            return [];
        }
        $args = [];
        $statusSql = implode(',', array_fill(0, count($statuses), '%s'));
        foreach ($statuses as $status) {
            $args[] = (string) $status;
        }
        $identitySql = '1=0';
        if ($userIds !== []) {
            $idSql = implode(',', array_fill(0, count($userIds), '%d'));
            $identitySql = 'CAST(cust.meta_value AS UNSIGNED) IN (' . $idSql . ')';
            foreach ($userIds as $userId) {
                $args[] = (int) $userId;
            }
        }
        if ($hasGuests) {
            $guestSql = "(CAST(IFNULL(cust.meta_value, '0') AS UNSIGNED) = 0 AND IFNULL(mail.meta_value, '') <> '')";
            $identitySql = ($identitySql === '1=0') ? $guestSql : '(' . $identitySql . ' OR ' . $guestSql . ')';
        }
        $args[] = $maxInstant;
        $args[] = $this->siteLocalFromUnix($maxInstant);
        $sql = "SELECT p.ID AS order_id, p.post_status, p.post_date, cust.meta_value AS customer_user, mail.meta_value AS billing_email, paid.meta_value AS date_paid"
            . " FROM {$wpdb->posts} p"
            . " LEFT JOIN {$wpdb->postmeta} cust ON cust.post_id = p.ID AND cust.meta_key = '_customer_user'"
            . " LEFT JOIN {$wpdb->postmeta} mail ON mail.post_id = p.ID AND mail.meta_key = '_billing_email'"
            . " LEFT JOIN {$wpdb->postmeta} paid ON paid.post_id = p.ID AND paid.meta_key = '_date_paid'"
            . " WHERE p.post_type = 'shop_order'"
            . " AND p.post_status IN ($statusSql)"
            . " AND ($identitySql)"
            . " AND ((paid.meta_value IS NOT NULL AND paid.meta_value <> '' AND paid.meta_value <> '0' AND CAST(paid.meta_value AS UNSIGNED) < %d)"
            . " OR ((paid.meta_value IS NULL OR paid.meta_value = '' OR paid.meta_value = '0') AND p.post_date < %s))";
        $prepared = $wpdb->prepare($sql, $args);
        $rows = $wpdb->get_results($prepared);
        $list = [];
        foreach ((array) $rows as $row) {
            if (is_array($row)) {
                $row = (object) $row;
            }
            if (!is_object($row)) {
                continue;
            }
            $id = (int) ($row->order_id ?? 0);
            if ($id <= 0) {
                continue;
            }
            $list[] = [
                'id' => $id,
                'status' => isset($row->post_status) ? (string) $row->post_status : '',
                'customer_id' => (int) ($row->customer_user ?? 0),
                'email' => isset($row->billing_email) ? (string) $row->billing_email : '',
                'instant' => $this->orderInstant(
                    isset($row->date_paid) ? (string) $row->date_paid : '',
                    isset($row->post_date) ? (string) $row->post_date : ''
                ),
            ];
        }
        return $list;
    }

    /**
     * @param int $unix
     * @return string
     */
    private function siteLocalFromUnix($unix) {
        try {
            $dt = new \DateTimeImmutable('@' . (int) $unix);
        } catch (\Exception $e) {
            return '1970-01-01 00:00:00';
        }
        return $dt->setTimezone($this->siteTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @return array<int,string>
     */
    private function customerFlags(array $orders) {
        $flags = [];
        $userIds = [];
        $hasGuests = false;
        $maxInstant = 0;
        foreach ($orders as $orderId => $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = (int) $orderId;
            $flags[$orderId] = 'New';
            $customerId = (int) ($order['customer_id'] ?? 0);
            if ($customerId > 0) {
                $userIds[$customerId] = $customerId;
            } elseif ($this->guestEmailKey((string) ($order['email'] ?? '')) !== '') {
                $hasGuests = true;
            }
            $instant = (int) ($order['instant'] ?? 0);
            if ($instant > $maxInstant) {
                $maxInstant = $instant;
            }
        }
        if ($maxInstant <= 0 || ($userIds === [] && !$hasGuests)) {
            return $flags;
        }
        $history = $this->loadPaidHistory(array_values($userIds), $hasGuests, $maxInstant);
        foreach ($orders as $orderId => $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = (int) $orderId;
            $instant = (int) ($order['instant'] ?? 0);
            if ($instant <= 0) {
                continue;
            }
            $customerId = (int) ($order['customer_id'] ?? 0);
            $emailKey = $this->guestEmailKey((string) ($order['email'] ?? ''));
            if ($customerId <= 0 && $emailKey === '') {
                continue;
            }
            foreach ($history as $past) {
                if ((int) $past['id'] === $orderId) {
                    continue;
                }
                if ((int) $past['instant'] <= 0 || (int) $past['instant'] >= $instant) {
                    continue;
                }
                if (!$this->isPaidOrderStatus((string) $past['status'])) {
                    continue;
                }
                if ($customerId > 0) {
                    if ((int) $past['customer_id'] === $customerId) {
                        $flags[$orderId] = 'Returning';
                        break;
                    }
                    continue;
                }
                if ((int) $past['customer_id'] > 0) {
                    continue;
                }
                $pastEmail = $this->guestEmailKey((string) $past['email']);
                if ($pastEmail !== '' && $pastEmail === $emailKey) {
                    $flags[$orderId] = 'Returning';
                    break;
                }
            }
        }
        return $flags;
    }

    /**
     * Same database handle the booking report already uses.
     *
     * @return object|null
     */
    private function db() {
        $reports = $this->reportService();
        try {
            $property = new \ReflectionProperty(FinancialReportService::class, 'wpdb');
            $property->setAccessible(true);
            $wpdb = $property->getValue($reports);
        } catch (\Exception $e) {
            return null;
        }
        if (is_object($wpdb) && method_exists($wpdb, 'get_results')) {
            return $wpdb;
        }
        return null;
    }

    /**
     * @param int $productId
     * @return string
     */
    private function frontPermalink($productId) {
        $productId = (int) $productId;
        if ($productId <= 0 || !function_exists('get_permalink')) {
            return '';
        }
        $url = get_permalink($productId);
        if (!is_string($url)) {
            return '';
        }
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return $url;
    }

    /**
     * Front-end coupon query codes: wc_format_coupon_code, then at most 32
     * characters matching letters, numbers, hyphens, and underscores.
     * An already-normal code is kept when WooCommerce is not loaded.
     * This does not read the product-variations allowlist.
     *
     * @param mixed $raw
     * @return string
     */
    private function urlCouponCode($raw) {
        if (is_array($raw) || is_object($raw) || is_bool($raw)) {
            return '';
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        if (function_exists('wc_format_coupon_code')) {
            $code = wc_format_coupon_code($raw);
            if (!is_string($code)) {
                return '';
            }
            $code = trim($code);
        } else {
            $code = $raw;
        }
        if ($code === '' || strlen($code) > 32) {
            return '';
        }
        if (preg_match('/^[a-z0-9_-]+$/', $code) !== 1) {
            return '';
        }
        return $code;
    }

    /**
     * @param string $permalink
     * @param string $couponCode
     * @return string
     */
    private function bookingLineUrl($permalink, $couponCode) {
        $permalink = trim((string) $permalink);
        if ($permalink === '') {
            return '';
        }
        $code = $this->urlCouponCode($couponCode);
        if ($code === '' || !function_exists('add_query_arg')) {
            return $permalink;
        }
        $url = add_query_arg('coupon', $code, $permalink);
        if (!is_string($url) || $url === '') {
            return $permalink;
        }
        return $url;
    }

    /**
     * @param array<string,mixed> $line
     * @param string              $couponCode
     * @return string
     */
    private function bookingProductHtml(array $line, $couponCode) {
        $name = esc_html((string) ($line['product'] ?? ''));
        $url = $this->bookingLineUrl(isset($line['permalink']) ? (string) $line['permalink'] : '', $couponCode);
        if ($url === '') {
            return $name;
        }
        return '<a href="' . esc_url($url) . '">' . $name . '</a>';
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @param string                         $couponCode
     * @return string
     */
    private function renderBookingLines(array $lines, $couponCode) {
        if ($lines === []) {
            return '<p>No paid bookings in this window.</p>';
        }
        $html = '<table><thead><tr>';
        $html .= '<th>Camp or course</th><th>Date and time (Europe/Zurich)</th><th>Venue</th><th>City</th>';
        $html .= '<th>CHF incl. VAT</th><th>Customer</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $flag = ((string) ($line['customer'] ?? '')) === 'Returning' ? 'Returning' : 'New';
            $html .= '<tr>';
            $html .= '<td>' . $this->bookingProductHtml($line, $couponCode) . '</td>';
            $html .= '<td>' . esc_html((string) ($line['paid_at'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($line['venue'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($line['city'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) ($line['amount_inc_vat'] ?? 0))) . '</td>';
            $html .= '<td>' . esc_html($flag) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @return string
     */
    private function renderOrderList(array $orders) {
        if ($orders === []) {
            return '<p>No paid orders in this window.</p>';
        }
        $html = '<table><thead><tr>';
        $html .= '<th>Date and time (Europe/Zurich)</th><th>Order</th><th>Product</th><th>Venue</th>';
        $html .= '<th>Incl. VAT</th><th>Customer</th><th>Attribution</th><th>Language</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $html .= '<tr>';
            $html .= '<td>' . esc_html((string) ($order['paid_at'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($order['order_number'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($order['products'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($order['venues'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) ($order['net_inc_vat'] ?? 0))) . '</td>';
            $html .= '<td>' . esc_html((string) ($order['customer_type'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($order['attribution'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) ($order['language'] ?? '')) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Email display bucket. FacetNormalizer labels stay unchanged for other reports.
     * Versoix and Cham are not FacetNormalizer regions; they are mapped here.
     *
     * @param string $label
     * @return string
     */
    private function regionDisplayBucket($label) {
        $label = trim((string) $label);
        if ($label === '' || strcasecmp($label, 'Unknown') === 0 || strcasecmp($label, 'not_recorded') === 0) {
            return 'Unknown';
        }
        if (stripos($label, 'unmapped:') === 0) {
            $label = trim(substr($label, strlen('unmapped:')));
        }
        if ($label === '' || strcasecmp($label, 'Unknown') === 0 || strcasecmp($label, 'not_recorded') === 0) {
            return 'Unknown';
        }
        $lower = strtolower(FacetNormalizer::decode_label($label));
        $map = [
            'basel-stadt' => 'Basel',
            'geneva' => 'Geneva/Versoix',
            'genève' => 'Geneva/Versoix',
            'geneve' => 'Geneva/Versoix',
            'genf' => 'Geneva/Versoix',
            'versoix' => 'Geneva/Versoix',
            'champel' => 'Geneva/Versoix',
            'vaud' => 'Vaud',
            'basel' => 'Basel',
            'bâle' => 'Basel',
            'bale' => 'Basel',
            'zoug' => 'Zug/Cham',
            'zug' => 'Zug/Cham',
            'cham' => 'Zug/Cham',
            'zürich' => 'Zurich',
            'zurich' => 'Zurich',
            'zuerich' => 'Zurich',
        ];
        foreach ($map as $needle => $bucket) {
            if ($this->regionLabelMatches($lower, $needle)) {
                return $bucket;
            }
        }
        return 'Unknown';
    }

    /**
     * Exact match, or the needle as a whole word.
     *
     * A letter or number on either side blocks the match, so "cham" matches
     * Cham and not Champel. Accented letters still count as letters.
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    private function regionLabelMatches($haystack, $needle) {
        if ($haystack === $needle) {
            return true;
        }
        if ($needle === '') {
            return false;
        }
        $pattern = '/(?:^|[^\p{L}\p{N}])' . preg_quote($needle, '/') . '(?:$|[^\p{L}\p{N}])/u';
        return preg_match($pattern, $haystack) === 1;
    }

    /**
     * @param string $label
     * @return int
     */
    private function regionDisplayOrder($label) {
        $order = [
            'Geneva/Versoix' => 1,
            'Vaud' => 2,
            'Basel' => 3,
            'Zug/Cham' => 4,
            'Zurich' => 5,
            'Unknown' => 6,
        ];
        return isset($order[$label]) ? $order[$label] : 7;
    }

    /**
     * Primary programme rows, then one Other row for every remaining season and type.
     *
     * @return array<string,string>
     */
    private function programmeLabels() {
        return [
            'autumn-courses' => 'Autumn Courses',
            'winter-courses' => 'Winter Courses',
            'spring-courses' => 'Spring Courses',
            'easter-camps' => 'Easter Camps',
            'summer-camps' => 'Summer Camps',
            'other' => 'Other',
        ];
    }

    /**
     * @param array<string,mixed>|null $row
     * @return string
     */
    private function programmeBucketKey($row) {
        if (!is_array($row)) {
            return 'other';
        }
        $season = isset($row['season']) ? (string) $row['season'] : '';
        $type = isset($row['type']) ? (string) $row['type'] : '';
        $primary = [
            'Autumn|' . ProductTypeClassifierService::TYPE_COURSE => 'autumn-courses',
            'Winter|' . ProductTypeClassifierService::TYPE_COURSE => 'winter-courses',
            'Spring|' . ProductTypeClassifierService::TYPE_COURSE => 'spring-courses',
            'Easter|' . ProductTypeClassifierService::TYPE_CAMP => 'easter-camps',
            'Summer|' . ProductTypeClassifierService::TYPE_CAMP => 'summer-camps',
        ];
        $key = $season . '|' . $type;
        return isset($primary[$key]) ? $primary[$key] : 'other';
    }

    /**
     * @param array<int,array{0:array<string,mixed>|null,1:array<string,mixed>|null}> $pairs
     * @return array<int,array{0:array<string,mixed>,1:array<string,mixed>}>
     */
    private function groupProgrammePairs(array $pairs) {
        $buckets = [];
        foreach ($this->programmeLabels() as $key => $label) {
            $buckets[$key] = [
                $this->moneyRow($key, $label),
                $this->moneyRow($key, $label),
            ];
        }
        foreach ($pairs as $pair) {
            $left = (isset($pair[0]) && is_array($pair[0])) ? $pair[0] : null;
            $right = (isset($pair[1]) && is_array($pair[1])) ? $pair[1] : null;
            $source = $left !== null ? $left : $right;
            $key = $this->programmeBucketKey($source);
            if (!isset($buckets[$key])) {
                $key = 'other';
            }
            if ($left !== null) {
                $this->addMoneyRow($buckets[$key][0], $left);
            }
            if ($right !== null) {
                $this->addMoneyRow($buckets[$key][1], $right);
            }
        }
        $out = [];
        foreach ($buckets as $pair) {
            $out[] = $pair;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $row
     * @return void
     */
    private function addMoneyRow(array &$target, array $row) {
        $target['quantity'] += (int) ($row['quantity'] ?? 0);
        $target['net_ex_vat'] += (float) ($row['net_ex_vat'] ?? 0);
        $target['net_inc_vat'] += (float) ($row['net_inc_vat'] ?? 0);
    }

    /**
     * Largest remainder to one decimal so the row percentages sum to 100.0.
     * Equal remainders prefer the earlier row. A zero total is 0.0% on every row.
     * Only a row with a positive value receives leftover tenths.
     * Sales percentages use the VAT-included amount.
     *
     * @param array<int,float|int> $values
     * @return array<int,string>
     */
    private function percentLabels(array $values) {
        $indexed = array_values($values);
        $count = count($indexed);
        if ($count === 0) {
            return [];
        }
        $total = 0.0;
        foreach ($indexed as $value) {
            $total += (float) $value;
        }
        if ($total <= 0) {
            return array_fill(0, $count, '0.0%');
        }
        $floors = [];
        $remainders = [];
        $used = 0;
        foreach ($indexed as $i => $value) {
            $exact = ((float) $value / $total) * 1000.0;
            if ($exact < 0) {
                $exact = 0.0;
            }
            $floor = (int) floor($exact + 1.0e-8);
            $floors[$i] = $floor;
            $remainders[$i] = $exact - $floor;
            $used += $floor;
        }
        $left = 1000 - $used;
        $order = array_keys($floors);
        usort($order, function ($a, $b) use ($remainders, $indexed) {
            if ($remainders[$a] == $remainders[$b]) {
                if ((float) $indexed[$a] == (float) $indexed[$b]) {
                    return $a <=> $b;
                }
                return ((float) $indexed[$b]) <=> ((float) $indexed[$a]);
            }
            return ($remainders[$a] < $remainders[$b]) ? 1 : -1;
        });
        $guard = 0;
        while ($left > 0 && $guard < 1000) {
            $assigned = false;
            foreach ($order as $index) {
                if ($left <= 0) {
                    break;
                }
                if ((float) $indexed[$index] <= 0) {
                    continue;
                }
                $floors[$index]++;
                $left--;
                $assigned = true;
            }
            if (!$assigned) {
                break;
            }
            $guard++;
        }
        while ($left < 0 && $guard < 2000) {
            foreach (array_reverse($order) as $index) {
                if ($left >= 0) {
                    break;
                }
                if ($floors[$index] <= 0) {
                    continue;
                }
                $floors[$index]--;
                $left++;
            }
            $guard++;
        }
        $out = [];
        foreach ($floors as $tenths) {
            $out[] = number_format($tenths / 10, 1, '.', '') . '%';
        }
        return $out;
    }

    /**
     * @param float|int $total
     * @return string
     */
    private function totalPercentLabel($total) {
        return ((float) $total) > 0 ? '100.0%' : '0.0%';
    }

    /**
     * @param array{0:mixed,1:mixed} $pair
     * @return string
     */
    private function pairLabel(array $pair) {
        $left = (isset($pair[0]) && is_array($pair[0])) ? $pair[0] : [];
        $right = (isset($pair[1]) && is_array($pair[1])) ? $pair[1] : [];
        $label = (string) ($left['label'] ?? '');
        if ($label === '') {
            $label = (string) ($right['label'] ?? '');
        }
        return $label;
    }

    /**
     * Daily and campaign-to-date columns in one table. The amount shown is incl. VAT.
     * Bookings are the quantity already used in the table. Percentages are of that
     * table's own total. The season-and-type collection is grouped into programme rows.
     *
     * @param string              $labelHeading
     * @param array<string,mixed> $daily
     * @param array<string,mixed> $campaign
     * @param string              $collection types|products|regions|seasons|season_types
     * @param bool                $hideEmpty
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
        if ($collection === 'season_types') {
            $pairs = $this->groupProgrammePairs($pairs);
        } elseif ($collection === 'regions') {
            usort($pairs, function ($a, $b) {
                $aLabel = $this->pairLabel($a);
                $bLabel = $this->pairLabel($b);
                $byOrder = $this->regionDisplayOrder($aLabel) <=> $this->regionDisplayOrder($bLabel);
                if ($byOrder !== 0) {
                    return $byOrder;
                }
                return strcmp($aLabel, $bLabel);
            });
        }
        $visible = [];
        foreach ($pairs as $pair) {
            $left = (isset($pair[0]) && is_array($pair[0])) ? $pair[0] : [];
            $right = (isset($pair[1]) && is_array($pair[1])) ? $pair[1] : [];
            $leftQty = (int) ($left['quantity'] ?? 0);
            $rightQty = (int) ($right['quantity'] ?? 0);
            $leftInc = (float) ($left['net_inc_vat'] ?? 0);
            $rightInc = (float) ($right['net_inc_vat'] ?? 0);
            $label = (string) ($left['label'] ?? '');
            if ($label === '') {
                $label = (string) ($right['label'] ?? '');
            }
            if ($hideEmpty && $leftQty === 0 && $rightQty === 0 && $this->comparisonRowHidesWhenEmpty($collection, $label)) {
                continue;
            }
            $visible[] = [
                'label' => $label,
                'left_qty' => $leftQty,
                'left_inc' => $leftInc,
                'right_qty' => $rightQty,
                'right_inc' => $rightInc,
            ];
        }
        $dailyQtyPct = $this->percentLabels(array_column($visible, 'left_qty'));
        $dailyIncPct = $this->percentLabels(array_column($visible, 'left_inc'));
        $campaignQtyPct = $this->percentLabels(array_column($visible, 'right_qty'));
        $campaignIncPct = $this->percentLabels(array_column($visible, 'right_inc'));
        $html = '<table><thead><tr>';
        $html .= '<th>' . esc_html((string) $labelHeading) . '</th>';
        $html .= '<th>Daily qty</th><th>Daily incl. VAT</th><th>Campaign qty</th><th>Campaign incl. VAT</th>';
        $html .= '<th>Daily % bookings</th><th>Daily % sales</th><th>Campaign % bookings</th><th>Campaign % sales</th>';
        $html .= '</tr></thead><tbody>';
        $dailyQty = 0;
        $dailyInc = 0.0;
        $campaignQty = 0;
        $campaignInc = 0.0;
        foreach ($visible as $index => $row) {
            $html .= '<tr>';
            $html .= '<td>' . esc_html((string) $row['label']) . '</td>';
            $html .= '<td>' . esc_html((string) $row['left_qty']) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) $row['left_inc'])) . '</td>';
            $html .= '<td>' . esc_html((string) $row['right_qty']) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) $row['right_inc'])) . '</td>';
            $html .= '<td>' . esc_html(isset($dailyQtyPct[$index]) ? $dailyQtyPct[$index] : '0.0%') . '</td>';
            $html .= '<td>' . esc_html(isset($dailyIncPct[$index]) ? $dailyIncPct[$index] : '0.0%') . '</td>';
            $html .= '<td>' . esc_html(isset($campaignQtyPct[$index]) ? $campaignQtyPct[$index] : '0.0%') . '</td>';
            $html .= '<td>' . esc_html(isset($campaignIncPct[$index]) ? $campaignIncPct[$index] : '0.0%') . '</td>';
            $html .= '</tr>';
            $dailyQty += (int) $row['left_qty'];
            $dailyInc += (float) $row['left_inc'];
            $campaignQty += (int) $row['right_qty'];
            $campaignInc += (float) $row['right_inc'];
        }
        if ($hideEmpty && $visible === []) {
            if ($collection === 'regions') {
                $empty = 'No regions in this range.';
            } elseif ($collection === 'products') {
                $empty = 'No products sold in this range.';
            } else {
                $empty = 'None in this range.';
            }
            $html .= '<tr><td colspan="9">' . esc_html($empty) . '</td></tr>';
        }
        $html .= '<tr><td>Total</td>';
        $html .= '<td>' . esc_html((string) $dailyQty) . '</td>';
        $html .= '<td>' . esc_html($this->money($dailyInc)) . '</td>';
        $html .= '<td>' . esc_html((string) $campaignQty) . '</td>';
        $html .= '<td>' . esc_html($this->money($campaignInc)) . '</td>';
        $html .= '<td>' . esc_html($this->totalPercentLabel($dailyQty)) . '</td>';
        $html .= '<td>' . esc_html($this->totalPercentLabel($dailyInc)) . '</td>';
        $html .= '<td>' . esc_html($this->totalPercentLabel($campaignQty)) . '</td>';
        $html .= '<td>' . esc_html($this->totalPercentLabel($campaignInc)) . '</td></tr>';
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Regions and products drop every empty row. Season and type keeps the
     * five primary programme rows and drops only empty Other and Unknown.
     *
     * @param string $collection
     * @param string $label
     * @return bool
     */
    private function comparisonRowHidesWhenEmpty($collection, $label) {
        if ($collection !== 'season_types') {
            return true;
        }
        return $label === 'Other' || $label === 'Unknown';
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
     * @param array<int,string>   $emails
     * @param string              $subject
     * @param string              $html
     * @param array<string,mixed> $digest
     * @return array{ok:bool,message:string}
     */
    private function deliver(array $emails, $subject, $html, array $digest) {
        if (!function_exists('wp_mail') || $emails === []) {
            return ['ok' => false, 'message' => ''];
        }
        $path = '';
        $dir = '';
        $attachments = [];
        try {
            $built = $this->resolveWorkbook($digest);
            $path = isset($built['path']) ? (string) $built['path'] : '';
            $dir = isset($built['dir']) ? (string) $built['dir'] : '';
            if ($path === '' || !is_file($path)) {
                throw new \RuntimeException('Workbook file was not written');
            }
            $attachments = [$path];
        } catch (\Throwable $e) {
            $this->logWorkbookFailure($e, $digest);
            $this->deleteAttachment($path, $dir);
            $path = '';
            $dir = '';
            $attachments = [];
        }
        $errorMessage = '';
        $listener = function ($wpError) use (&$errorMessage) {
            if (is_object($wpError) && method_exists($wpError, 'get_error_message')) {
                $errorMessage = (string) $wpError->get_error_message();
            }
        };
        add_action('wp_mail_failed', $listener, 10, 1);
        try {
            $to = count($emails) === 1 ? $emails[0] : array_values($emails);
            $sent = wp_mail($to, $subject, $html, $this->mailHeaders(), $attachments);
        } finally {
            remove_action('wp_mail_failed', $listener, 10);
            $this->deleteAttachment($path, $dir);
        }
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
     * @param string $value
     * @return string
     */
    private function stripHeaderControls($value) {
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value);
        return is_string($stripped) ? $stripped : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function cleanSenderName($value) {
        $name = $this->stripHeaderControls((string) $value);
        if (function_exists('sanitize_text_field')) {
            $name = sanitize_text_field($name);
        }
        return trim($this->stripHeaderControls((string) $name));
    }

    /**
     * Display name for a From header. Quotes and backslashes are removed,
     * then leftover runs of spaces are collapsed and the result is trimmed.
     *
     * @param mixed $value
     * @return string
     */
    private function senderDisplayName($value) {
        $name = str_replace(['"', '\\'], '', $this->cleanSenderName($value));
        $collapsed = preg_replace('/ {2,}/', ' ', $name);
        if (!is_string($collapsed)) {
            $collapsed = $name;
        }
        return trim($collapsed);
    }

    /**
     * @param mixed $email
     * @return string
     */
    private function validHeaderEmail($email) {
        $email = trim($this->stripHeaderControls((string) $email));
        if ($email === '' || !function_exists('is_email')) {
            return '';
        }
        $checked = is_email($email);
        if (!is_string($checked) || $checked === '') {
            return '';
        }
        return trim($this->stripHeaderControls($checked));
    }

    /**
     * Content-Type stays first. From and Reply-To are added only for valid
     * addresses. A blank From name uses the filtered site name, then the
     * plain address when that name is empty.
     *
     * @return array<int,string>
     */
    private function mailHeaders() {
        $settings = $this->settings();
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $from = $this->validHeaderEmail(isset($settings['from_email']) ? $settings['from_email'] : '');
        if ($from !== '') {
            $name = $this->senderDisplayName(isset($settings['from_name']) ? $settings['from_name'] : '');
            if ($name === '') {
                $site = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
                if (function_exists('apply_filters')) {
                    $filtered = apply_filters('wp_mail_from_name', $site);
                    $site = is_string($filtered) ? $filtered : '';
                }
                $name = $this->senderDisplayName($site);
            }
            if ($name === '') {
                $headers[] = 'From: ' . $from;
            } else {
                $headers[] = 'From: "' . $name . '" <' . $from . '>';
            }
        }
        $reply = $this->validHeaderEmail(isset($settings['reply_to']) ? $settings['reply_to'] : '');
        if ($reply !== '') {
            $headers[] = 'Reply-To: <' . $reply . '>';
        }
        return $headers;
    }

    /**
     * @param float $amount
     * @return string
     */
    private function money($amount) {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Campaign-window lines and orders for the workbook. The HTML renderer
     * does not read this structure. The customer cell uses the billing name
     * from the order already loaded for status and currency. Booking report
     * emails and attendee names are not copied.
     *
     * @param array<string,mixed> $report
     * @param string              $reportDate
     * @param string              $campaignStart
     * @param string              $couponCode
     * @return array<string,mixed>
     */
    private function workbookFromReport(array $report, $reportDate, $campaignStart, $couponCode) {
        $rows = (isset($report['data']) && is_array($report['data'])) ? $report['data'] : [];
        $lines = [];
        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = (int) ($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $qty = (int) ($row['line_quantity'] ?? 0);
            if ($qty < 0) {
                $qty = 0;
            }
            $type = isset($row['product_type']) ? (string) $row['product_type'] : '';
            $sales = round($this->netIncVat($row), 2);
            $revenue = $this->revenueIncVat($row);
            $name = trim((string) ($row['order_item_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['class_name'] ?? ''));
            }
            $couponQty = $this->couponLineQuantity($row, $couponCode, $qty);
            $paidGmt = isset($row['date_paid_gmt']) ? (string) $row['date_paid_gmt'] : '';
            $createdLocal = isset($row['order_date_local']) ? (string) $row['order_date_local'] : '';
            if (!isset($grouped[$orderId])) {
                $grouped[$orderId] = [
                    'paid_gmt' => $paidGmt,
                    'created_local' => $createdLocal,
                    'revenue' => 0.0,
                    'net_sales' => 0.0,
                    'items' => [],
                    'items_sold' => 0,
                    'coupon_tokens' => [],
                ];
            }
            $grouped[$orderId]['revenue'] = round((float) $grouped[$orderId]['revenue'] + $revenue, 2);
            $grouped[$orderId]['net_sales'] = round((float) $grouped[$orderId]['net_sales'] + $sales, 2);
            $grouped[$orderId]['items'][] = $this->productQuantityLabel($qty, $name);
            $grouped[$orderId]['items_sold'] += $qty;
            $this->addCouponTokens($grouped[$orderId]['coupon_tokens'], isset($row['discount_codes']) ? (string) $row['discount_codes'] : '');
            $season = $this->seasonBucket($row, $type);
            $lines[] = [
                'region' => $this->regionDisplayBucket($this->regionLabel($row)),
                'season_type' => $this->compositeLabel($season, $type),
                'product_type' => $type,
                'quantity' => $qty,
                'sales' => $sales,
                'coupon_qty' => $couponQty,
                'order_number' => (string) $orderId,
            ];
        }
        $orders = [];
        foreach ($grouped as $orderId => $order) {
            $facts = $this->orderFacts((int) $orderId, (string) $order['paid_gmt']);
            $attribution = (string) $facts['attribution'];
            if ($attribution === 'Unknown') {
                $attribution = '';
            }
            $number = (string) $facts['order_number'];
            if ($number === '') {
                $number = (string) $orderId;
            }
            foreach ($lines as $index => $line) {
                if ((string) $line['order_number'] === (string) $orderId) {
                    $lines[$index]['order_number'] = $number;
                }
            }
            $orders[] = [
                'paid_at' => $this->zurichPaidLabel((string) $order['paid_gmt'], (string) $order['created_local']),
                'order_number' => $number,
                'revenue' => round((float) $order['revenue'], 2),
                'net_sales' => round((float) $order['net_sales'], 2),
                'status' => (string) $facts['status'],
                'customer' => (string) $facts['customer'],
                'customer_type' => (string) $facts['customer_type'],
                'products' => $this->joinProductLabels($order['items']),
                'items_sold' => (int) $order['items_sold'],
                'coupons' => implode(', ', $order['coupon_tokens']),
                'attribution' => $attribution,
                'language' => (string) $facts['language'],
                'currency' => (string) $facts['currency'],
            ];
        }
        usort($orders, function ($a, $b) {
            $byTime = strcmp((string) $a['paid_at'], (string) $b['paid_at']);
            if ($byTime !== 0) {
                return $byTime;
            }
            return strcmp((string) $a['order_number'], (string) $b['order_number']);
        });
        return [
            'year' => $this->workbookYear($campaignStart, $reportDate),
            'coupon_code' => trim((string) $couponCode),
            'lines' => $lines,
            'orders' => $orders,
        ];
    }

    /**
     * Synthetic campaign lines and orders. Does not read the shop.
     *
     * @param array<int,array<string,mixed>> $lines
     * @param string                         $campaignStart
     * @param string                         $reportDate
     * @param string                         $couponCode
     * @return array<string,mixed>
     */
    private function sampleWorkbook(array $lines, $campaignStart, $reportDate, $couponCode) {
        $outLines = [];
        $orders = [];
        $index = 0;
        $languages = ['fr', 'de', 'en'];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (int) ($line['campaign_qty'] ?? 0);
            $sales = round((float) ($line['campaign_inc'] ?? 0), 2);
            if ($qty <= 0 && $sales == 0.0) {
                continue;
            }
            $type = (string) ($line['type'] ?? '');
            $plural = (string) ($line['type_plural'] ?? $type);
            $season = (string) ($line['season'] ?? '');
            $label = trim($season . ' ' . $plural);
            $couponQty = ($index === 0) ? $qty : 0;
            $orderNumber = (string) (910001 + $index);
            $name = (string) ($line['product'] ?? 'Sample');
            $outLines[] = [
                'region' => (string) ($line['region'] ?? ''),
                'season_type' => $label,
                'product_type' => $type,
                'quantity' => $qty,
                'sales' => $sales,
                'coupon_qty' => $couponQty,
                'order_number' => $orderNumber,
            ];
            $orders[] = [
                'paid_at' => sprintf('2026-11-10 %02d:15', 9 + $index),
                'order_number' => $orderNumber,
                'revenue' => $sales,
                'net_sales' => $sales,
                'status' => 'Completed',
                'customer' => ($index % 2 === 0) ? 'Sample Parent A' : 'Sample Parent B',
                'customer_type' => ($index % 2 === 0) ? 'Returning' : 'New',
                'products' => $this->productQuantityLabel($qty, $name),
                'items_sold' => $qty,
                'coupons' => $couponQty > 0 ? (string) $couponCode : '',
                'attribution' => ($index === 0) ? 'Organic: Google' : '',
                'language' => $languages[$index % 3],
                'currency' => 'CHF',
            ];
            $index++;
        }
        return [
            'year' => $this->workbookYear($campaignStart, $reportDate),
            'coupon_code' => trim((string) $couponCode),
            'lines' => $outLines,
            'orders' => $orders,
        ];
    }

    /**
     * Incl. VAT before refunds. final_price_raw is the line after discounts
     * and before tax; line_tax_amount is the VAT. Net sales use netIncVat(),
     * which reduces that VAT in proportion to any refund.
     *
     * @param array<string,mixed> $row
     * @return float
     */
    private function revenueIncVat(array $row) {
        $final = (float) ($row['final_price_raw'] ?? 0);
        $tax = (float) ($row['line_tax_amount'] ?? 0);
        $amount = $final + $tax;
        if ($amount < 0) {
            $amount = 0.0;
        }
        return round($amount, 2);
    }

    /**
     * Line quantity when this line's order applied the configured coupon.
     *
     * @param array<string,mixed> $row
     * @param string              $couponCode
     * @param int                 $qty
     * @return int
     */
    private function couponLineQuantity(array $row, $couponCode, $qty) {
        $expected = strtolower(trim((string) $couponCode));
        if ($expected === '' || (int) $qty <= 0) {
            return 0;
        }
        $tokens = [];
        $this->addCouponTokens($tokens, isset($row['discount_codes']) ? (string) $row['discount_codes'] : '');
        foreach ($tokens as $token) {
            if (strtolower($token) === $expected) {
                return (int) $qty;
            }
        }
        return 0;
    }

    /**
     * @param array<int,string> $tokens
     * @param string            $raw
     * @return void
     */
    private function addCouponTokens(array &$tokens, $raw) {
        $seen = [];
        foreach ($tokens as $token) {
            $seen[strtolower((string) $token)] = true;
        }
        foreach (explode(',', (string) $raw) as $part) {
            $part = trim((string) $part);
            $key = strtolower($part);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $tokens[] = $part;
        }
    }

    /**
     * @param int    $qty
     * @param string $name
     * @return string
     */
    private function productQuantityLabel($qty, $name) {
        $name = trim((string) $name);
        if ($name === '') {
            $name = 'Unknown';
        }
        return (int) $qty . '× ' . $name;
    }

    /**
     * @param array<int,string> $labels
     * @return string
     */
    private function joinProductLabels(array $labels) {
        $out = [];
        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $out[] = $label;
        }
        return implode(', ', $out);
    }

    /**
     * First year in campaign start, otherwise the report date.
     *
     * @param string $campaignStart
     * @param string $reportDate
     * @return string
     */
    private function workbookYear($campaignStart, $reportDate) {
        foreach ([$campaignStart, $reportDate] as $value) {
            if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', (string) $value, $matches)) {
                return (string) $matches[1];
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $digest
     * @return array{path:string,dir:string}
     */
    private function resolveWorkbook(array $digest) {
        if ($this->workbookBuilder !== null) {
            $result = call_user_func($this->workbookBuilder, $digest);
            if (!is_string($result) || $result === '' || !is_file($result)) {
                throw new \RuntimeException('Workbook file was not written');
            }
            return ['path' => $result, 'dir' => ''];
        }
        return $this->writeWorkbookAttachment($digest);
    }

    /**
     * @param array<string,mixed> $digest
     * @return array{path:string,dir:string}
     */
    private function writeWorkbookAttachment(array $digest) {
        $dir = $this->makeAttachmentDirectory();
        $path = $dir . '/' . $this->workbookFilename($digest);
        try {
            $this->writeWorkbook($digest, $path);
        } catch (\Throwable $e) {
            $this->deleteAttachment($path, $dir);
            throw $e;
        }
        if (!is_file($path)) {
            $this->deleteAttachment($path, $dir);
            throw new \RuntimeException('Workbook file was not written');
        }
        return ['path' => $path, 'dir' => $dir];
    }

    /**
     * @param array<string,mixed> $digest
     * @return string
     */
    private function workbookFilename(array $digest) {
        $date = isset($digest['report_date']) ? (string) $digest['report_date'] : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = 'undated';
        }
        return 'intersoccer-bf-sales-' . $date . '.xlsx';
    }

    /**
     * @return string
     */
    private function makeAttachmentDirectory() {
        $parent = rtrim((string) sys_get_temp_dir(), '/');
        if (function_exists('wp_tempnam')) {
            $tmp = wp_tempnam('intersoccer-bf-sales');
            if (is_string($tmp) && $tmp !== '') {
                $dirName = dirname($tmp);
                if ($dirName !== '' && $dirName !== '.' && is_dir($dirName)) {
                    $parent = rtrim($dirName, '/');
                }
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }
        }
        $dir = $parent . '/intersoccer-bf-sales-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Workbook temp directory was not created');
        }
        return $dir;
    }

    /**
     * @param string $path
     * @param string $dir
     * @return void
     */
    private function deleteAttachment($path, $dir) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            unlink($path);
        }
        if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $full = $dir . '/' . $item;
                if (is_file($full)) {
                    unlink($full);
                }
            }
        }
        rmdir($dir);
    }

    /**
     * @param \Throwable          $e
     * @param array<string,mixed> $digest
     * @return void
     */
    private function logWorkbookFailure(\Throwable $e, array $digest) {
        $reportDate = isset($digest['report_date']) ? (string) $digest['report_date'] : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) !== 1) {
            $reportDate = '';
        }
        $detail = str_replace(["\r", "\n", "\0"], ' ', (string) $e->getMessage());
        if (strlen($detail) > 300) {
            $detail = substr($detail, 0, 300);
        }
        $redacted = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted]', $detail);
        if (is_string($redacted)) {
            $detail = $redacted;
        }
        $message = 'Daily sales workbook failed: ' . get_class($e) . ': ' . $detail . ' report_date=' . $reportDate;
        try {
            $this->logger->error($message, ['report_date' => $reportDate]);
        } catch (\Throwable $ignored) {
            error_log($message);
        }
    }

    /**
     * @param array<string,mixed> $digest
     * @param string              $path
     * @return void
     */
    private function writeWorkbook(array $digest, $path) {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new \RuntimeException('PhpSpreadsheet is not available');
        }
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        try {
            $summary = $spreadsheet->getActiveSheet();
            $summary->setTitle('Booking Summary');
            $orders = $spreadsheet->createSheet();
            $orders->setTitle('Orders');
            $this->fillBookingSummarySheet($summary, $digest);
            $this->fillOrdersSheet($orders, $digest);
            $spreadsheet->setActiveSheetIndex(0);
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array<string,mixed>                           $digest
     * @return void
     */
    private function fillBookingSummarySheet($sheet, array $digest) {
        $book = $this->workbookPayload($digest);
        $year = (string) $book['year'];
        $title = 'Black Friday Sales';
        if ($year !== '') {
            $title .= ' ' . $year;
        }
        $this->sheetText($sheet, 'A1', $title);
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true);
        $coupon = trim((string) $book['coupon_code']);
        $usageHeader = ($coupon === '') ? 'Coupon Usage' : (strtoupper($coupon) . ' Usage');
        $headers = [
            'A' => 'Courses/Camps',
            'B' => 'Number of bookings',
            'C' => 'Sales Numbers',
            'D' => $usageHeader,
            'E' => '% of Bookings',
            'F' => '% of Sales',
        ];
        foreach ($headers as $column => $header) {
            $this->sheetText($sheet, $column . '2', $header);
        }
        $sheet->getStyle('A2:F2')->getFont()->setBold(true);
        $model = $this->summaryModel($book['lines'], $book['orders']);
        $row = 3;
        $named = $this->summaryRegionOrder();
        foreach ($named as $region) {
            $block = $model['regions'][$region];
            $row = $this->writeRegionBlock($sheet, $row, $region, $block, $model['grand']);
        }
        $unknown = $model['regions']['Unknown'];
        if (!$this->bucketIsZero($unknown['total'])) {
            $row = $this->writeRegionBlock($sheet, $row, 'Unknown', $unknown, $model['grand']);
        }
        $this->sheetText($sheet, 'A' . $row, 'General');
        $row++;
        $row = $this->writeSummaryNumbers(
            $sheet,
            $row,
            'Swiss French',
            $model['swiss_french'],
            $model['swiss_french']['quantity'],
            $model['grand']['quantity'],
            $model['swiss_french']['sales'],
            $model['grand']['sales']
        );
        $row = $this->writeSummaryNumbers(
            $sheet,
            $row,
            'Swiss German',
            $model['swiss_german'],
            $model['swiss_german']['quantity'],
            $model['grand']['quantity'],
            $model['swiss_german']['sales'],
            $model['grand']['sales']
        );
        $row = $this->writeSummaryNumbers(
            $sheet,
            $row,
            'Total Products Sold (Courses/Camps)',
            $model['course_camp'],
            $model['course_camp']['quantity'],
            $model['grand']['quantity'],
            $model['course_camp']['sales'],
            $model['grand']['sales']
        );
        $this->sheetText($sheet, 'A' . $row, 'Birthday Parties');
        $this->sheetNumber($sheet, 'B' . $row, (int) $model['birthday']['quantity'], '0');
        $this->sheetText($sheet, 'C' . $row, '-');
        $this->sheetNumber($sheet, 'D' . $row, (int) $model['birthday']['coupon_qty'], '0');
        $this->sheetText($sheet, 'E' . $row, '-');
        $this->sheetText($sheet, 'F' . $row, '-');
        $row++;
        foreach ($model['season_totals'] as $label => $bucket) {
            $row = $this->writeSummaryNumbers(
                $sheet,
                $row,
                'TOTAL ' . $label,
                $bucket,
                $bucket['quantity'],
                $model['grand']['quantity'],
                $bucket['sales'],
                $model['grand']['sales']
            );
        }
        $this->sheetText($sheet, 'A' . $row, 'Total orders (Courses/Camps)');
        $this->sheetNumber($sheet, 'B' . $row, (int) $model['course_camp_orders'], '0');
        $this->sheetNumber($sheet, 'C' . $row, round((float) $model['course_camp']['sales'], 2), '#,##0.00');
        $this->sheetNumber($sheet, 'D' . $row, (int) $model['course_camp_coupon_orders'], '0');
        $this->sheetText($sheet, 'E' . $row, '-');
        $this->sheetText($sheet, 'F' . $row, '-');
        $row++;
        $whole = $model['customer_whole'];
        foreach (['NEW CUSTOMERS' => 'New', 'RETURNING CUSTOMERS' => 'Returning'] as $label => $key) {
            $group = $model['customers'][$key];
            $groupBucket = [
                'quantity' => (int) $group['orders'],
                'sales' => (float) $group['sales'],
                'coupon_qty' => (int) $group['coupon_qty'],
            ];
            $row = $this->writeSummaryNumbers(
                $sheet,
                $row,
                $label,
                $groupBucket,
                $group['orders'],
                $whole['orders'],
                $group['sales'],
                $whole['sales']
            );
        }
        $this->sheetWidths($sheet, [
            'A' => 42,
            'B' => 22,
            'C' => 18,
            'D' => 24,
            'E' => 16,
            'F' => 14,
        ]);
    }

    /**
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param int                                           $row
     * @param string                                        $region
     * @param array<string,mixed>                           $block
     * @param array<string,mixed>                           $grand
     * @return int
     */
    private function writeRegionBlock($sheet, $row, $region, array $block, array $grand) {
        $this->sheetText($sheet, 'A' . $row, $region);
        $row++;
        $labels = $this->sortSeasonTypeLabels(array_keys($block['rows']));
        $regionTotal = $block['total'];
        foreach ($labels as $label) {
            $bucket = $block['rows'][$label];
            $row = $this->writeSummaryNumbers(
                $sheet,
                $row,
                $label,
                $bucket,
                $bucket['quantity'],
                $regionTotal['quantity'],
                $bucket['sales'],
                $regionTotal['sales']
            );
        }
        return $this->writeSummaryNumbers(
            $sheet,
            $row,
            'Total',
            $regionTotal,
            $regionTotal['quantity'],
            $grand['quantity'],
            $regionTotal['sales'],
            $grand['sales']
        );
    }

    /**
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param int                                           $row
     * @param string                                        $label
     * @param array<string,mixed>                           $bucket
     * @param float|int                                     $qtyPart
     * @param float|int                                     $qtyWhole
     * @param float|int                                     $salesPart
     * @param float|int                                     $salesWhole
     * @return int
     */
    private function writeSummaryNumbers($sheet, $row, $label, array $bucket, $qtyPart, $qtyWhole, $salesPart, $salesWhole) {
        $this->sheetText($sheet, 'A' . $row, $label);
        $this->sheetNumber($sheet, 'B' . $row, (int) $bucket['quantity'], '0');
        $this->sheetNumber($sheet, 'C' . $row, round((float) $bucket['sales'], 2), '#,##0.00');
        $this->sheetNumber($sheet, 'D' . $row, (int) $bucket['coupon_qty'], '0');
        $this->sheetNumber($sheet, 'E' . $row, $this->share($qtyPart, $qtyWhole), '0.0%');
        $this->sheetNumber($sheet, 'F' . $row, $this->share($salesPart, $salesWhole), '0.0%');
        return $row + 1;
    }

    /**
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array<string,mixed>                           $digest
     * @return void
     */
    private function fillOrdersSheet($sheet, array $digest) {
        $book = $this->workbookPayload($digest);
        $headers = [
            'A' => 'Date',
            'B' => 'Order #',
            'C' => 'Revenue',
            'D' => 'Status',
            'E' => 'Customer',
            'F' => 'Customer type',
            'G' => 'Product(s)',
            'H' => 'Items sold',
            'I' => 'Coupon(s)',
            'J' => 'Net Sales',
            'K' => 'Attribution',
            'L' => 'Language',
            'M' => 'Currency',
        ];
        foreach ($headers as $column => $header) {
            $this->sheetText($sheet, $column . '1', $header);
        }
        $sheet->getStyle('A1:M1')->getFont()->setBold(true);
        $row = 2;
        foreach ($book['orders'] as $order) {
            if (!is_array($order)) {
                continue;
            }
            $this->sheetZurichDate($sheet, 'A' . $row, isset($order['paid_at']) ? (string) $order['paid_at'] : '');
            $this->sheetText($sheet, 'B' . $row, isset($order['order_number']) ? (string) $order['order_number'] : '');
            $this->sheetNumber($sheet, 'C' . $row, round((float) ($order['revenue'] ?? 0), 2), '#,##0.00');
            $this->sheetText($sheet, 'D' . $row, isset($order['status']) ? (string) $order['status'] : '');
            $this->sheetText($sheet, 'E' . $row, isset($order['customer']) ? (string) $order['customer'] : '');
            $this->sheetText($sheet, 'F' . $row, isset($order['customer_type']) ? (string) $order['customer_type'] : '');
            $this->sheetText($sheet, 'G' . $row, isset($order['products']) ? (string) $order['products'] : '');
            $this->sheetNumber($sheet, 'H' . $row, (int) ($order['items_sold'] ?? 0), '0');
            $this->sheetText($sheet, 'I' . $row, isset($order['coupons']) ? (string) $order['coupons'] : '');
            $this->sheetNumber($sheet, 'J' . $row, round((float) ($order['net_sales'] ?? 0), 2), '#,##0.00');
            $this->sheetText($sheet, 'K' . $row, isset($order['attribution']) ? (string) $order['attribution'] : '');
            $this->sheetText($sheet, 'L' . $row, isset($order['language']) ? (string) $order['language'] : '');
            $this->sheetText($sheet, 'M' . $row, isset($order['currency']) ? (string) $order['currency'] : '');
            $row++;
        }
        $this->sheetWidths($sheet, [
            'A' => 20,
            'B' => 14,
            'C' => 14,
            'D' => 14,
            'E' => 20,
            'F' => 16,
            'G' => 36,
            'H' => 14,
            'I' => 18,
            'J' => 14,
            'K' => 22,
            'L' => 12,
            'M' => 12,
        ]);
    }

    /**
     * @param array<string,mixed> $digest
     * @return array{year:string,coupon_code:string,lines:array<int,array<string,mixed>>,orders:array<int,array<string,mixed>>}
     */
    private function workbookPayload(array $digest) {
        $book = (isset($digest['workbook']) && is_array($digest['workbook'])) ? $digest['workbook'] : [];
        $year = isset($book['year']) ? trim((string) $book['year']) : '';
        if ($year === '') {
            $year = $this->workbookYear(
                isset($digest['campaign_start']) ? (string) $digest['campaign_start'] : '',
                isset($digest['report_date']) ? (string) $digest['report_date'] : ''
            );
        }
        $coupon = isset($book['coupon_code']) ? trim((string) $book['coupon_code']) : '';
        if ($coupon === '' && isset($digest['coupon_code'])) {
            $coupon = trim((string) $digest['coupon_code']);
        }
        $lines = (isset($book['lines']) && is_array($book['lines'])) ? $book['lines'] : [];
        $orders = (isset($book['orders']) && is_array($book['orders'])) ? $book['orders'] : [];
        return [
            'year' => $year,
            'coupon_code' => $coupon,
            'lines' => $lines,
            'orders' => $orders,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @param array<int,array<string,mixed>> $orders
     * @return array<string,mixed>
     */
    private function summaryModel(array $lines, array $orders) {
        $grouped = [];
        $birthday = ['quantity' => 0, 'coupon_qty' => 0];
        $courseCamp = ['quantity' => 0, 'sales' => 0.0, 'coupon_qty' => 0];
        $courseCampOrders = [];
        $courseCampCouponOrders = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty < 0) {
                $qty = 0;
            }
            $couponQty = (int) ($line['coupon_qty'] ?? 0);
            if ($couponQty < 0) {
                $couponQty = 0;
            }
            $sales = round((float) ($line['sales'] ?? 0), 2);
            $type = (string) ($line['product_type'] ?? '');
            if ($type === ProductTypeClassifierService::TYPE_BIRTHDAY) {
                $birthday['quantity'] += $qty;
                $birthday['coupon_qty'] += $couponQty;
                continue;
            }
            $region = trim((string) ($line['region'] ?? ''));
            if ($region === '') {
                $region = 'Unknown';
            }
            $label = trim((string) ($line['season_type'] ?? ''));
            if ($label === '') {
                $label = 'Other';
            }
            if (!isset($grouped[$region])) {
                $grouped[$region] = [];
            }
            if (!isset($grouped[$region][$label])) {
                $grouped[$region][$label] = ['quantity' => 0, 'sales' => 0.0, 'coupon_qty' => 0];
            }
            $grouped[$region][$label]['quantity'] += $qty;
            $grouped[$region][$label]['sales'] = round((float) $grouped[$region][$label]['sales'] + $sales, 2);
            $grouped[$region][$label]['coupon_qty'] += $couponQty;
            if ($type === ProductTypeClassifierService::TYPE_CAMP || $type === ProductTypeClassifierService::TYPE_COURSE) {
                $courseCamp['quantity'] += $qty;
                $courseCamp['sales'] = round((float) $courseCamp['sales'] + $sales, 2);
                $courseCamp['coupon_qty'] += $couponQty;
                $orderNumber = trim((string) ($line['order_number'] ?? ''));
                if ($orderNumber !== '') {
                    $courseCampOrders[$orderNumber] = true;
                    if ($couponQty > 0) {
                        $courseCampCouponOrders[$orderNumber] = true;
                    }
                }
            }
        }
        $regions = [];
        $grandParts = [];
        $seasonTotals = [];
        foreach (array_merge($this->summaryRegionOrder(), ['Unknown']) as $region) {
            $visible = [];
            $source = isset($grouped[$region]) && is_array($grouped[$region]) ? $grouped[$region] : [];
            foreach ($source as $label => $bucket) {
                if (!is_array($bucket) || $this->bucketIsZero($bucket)) {
                    continue;
                }
                $visible[(string) $label] = $bucket;
                if (!isset($seasonTotals[$label])) {
                    $seasonTotals[$label] = ['quantity' => 0, 'sales' => 0.0, 'coupon_qty' => 0];
                }
                $seasonTotals[$label]['quantity'] += (int) $bucket['quantity'];
                $seasonTotals[$label]['sales'] = round((float) $seasonTotals[$label]['sales'] + (float) $bucket['sales'], 2);
                $seasonTotals[$label]['coupon_qty'] += (int) $bucket['coupon_qty'];
            }
            $total = $this->sumBuckets(array_values($visible));
            $regions[$region] = ['rows' => $visible, 'total' => $total];
            if ($region !== 'Unknown' || !$this->bucketIsZero($total)) {
                $grandParts[] = $total;
            }
        }
        $sortedSeasons = [];
        foreach ($this->sortSeasonTypeLabels(array_keys($seasonTotals)) as $label) {
            $sortedSeasons[$label] = $seasonTotals[$label];
        }
        $customers = [
            'New' => ['orders' => 0, 'sales' => 0.0, 'coupon_qty' => 0],
            'Returning' => ['orders' => 0, 'sales' => 0.0, 'coupon_qty' => 0],
        ];
        $orderType = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $type = ((string) ($order['customer_type'] ?? '')) === 'Returning' ? 'Returning' : 'New';
            $customers[$type]['orders']++;
            $customers[$type]['sales'] = round((float) $customers[$type]['sales'] + (float) ($order['net_sales'] ?? 0), 2);
            $number = trim((string) ($order['order_number'] ?? ''));
            if ($number !== '') {
                $orderType[$number] = $type;
            }
        }
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $number = trim((string) ($line['order_number'] ?? ''));
            if ($number === '' || !isset($orderType[$number])) {
                continue;
            }
            $customers[$orderType[$number]]['coupon_qty'] += max(0, (int) ($line['coupon_qty'] ?? 0));
        }
        return [
            'regions' => $regions,
            'grand' => $this->sumBuckets($grandParts),
            'birthday' => $birthday,
            'course_camp' => $courseCamp,
            'course_camp_orders' => count($courseCampOrders),
            'course_camp_coupon_orders' => count($courseCampCouponOrders),
            'customers' => $customers,
            'customer_whole' => [
                'orders' => (int) $customers['New']['orders'] + (int) $customers['Returning']['orders'],
                'sales' => round((float) $customers['New']['sales'] + (float) $customers['Returning']['sales'], 2),
            ],
            'season_totals' => $sortedSeasons,
            'swiss_french' => $this->sumBuckets([
                $regions['Geneva/Versoix']['total'],
                $regions['Vaud']['total'],
            ]),
            'swiss_german' => $this->sumBuckets([
                $regions['Basel']['total'],
                $regions['Zug/Cham']['total'],
                $regions['Zurich']['total'],
            ]),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function summaryRegionOrder() {
        return ['Geneva/Versoix', 'Vaud', 'Basel', 'Zug/Cham', 'Zurich'];
    }

    /**
     * @param array<string,mixed> $bucket
     * @return bool
     */
    private function bucketIsZero(array $bucket) {
        return (int) ($bucket['quantity'] ?? 0) === 0
            && (float) ($bucket['sales'] ?? 0) == 0.0
            && (int) ($bucket['coupon_qty'] ?? 0) === 0;
    }

    /**
     * @param array<int,array<string,mixed>> $buckets
     * @return array{quantity:int,sales:float,coupon_qty:int}
     */
    private function sumBuckets(array $buckets) {
        $total = ['quantity' => 0, 'sales' => 0.0, 'coupon_qty' => 0];
        foreach ($buckets as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            $total['quantity'] += (int) ($bucket['quantity'] ?? 0);
            $total['sales'] = round((float) $total['sales'] + (float) ($bucket['sales'] ?? 0), 2);
            $total['coupon_qty'] += (int) ($bucket['coupon_qty'] ?? 0);
        }
        return $total;
    }

    /**
     * @param array<int,string> $labels
     * @return array<int,string>
     */
    private function sortSeasonTypeLabels(array $labels) {
        $labels = array_values($labels);
        usort($labels, function ($left, $right) {
            return strcmp($this->seasonTypeRank($left), $this->seasonTypeRank($right));
        });
        return $labels;
    }

    /**
     * @param string $label
     * @return string
     */
    private function seasonTypeRank($label) {
        $label = (string) $label;
        $seasonRank = 90;
        $seasons = [
            'Autumn' => 10,
            'Winter' => 20,
            'Spring' => 30,
            'Easter' => 40,
            'Summer' => 50,
        ];
        foreach ($seasons as $name => $rank) {
            if (strpos($label, $name) === 0) {
                $seasonRank = $rank;
                break;
            }
        }
        $typeRank = 9;
        $types = [
            'Courses' => 1,
            'Camps' => 2,
            'Tournaments' => 3,
            'Birthdays' => 4,
            'Other' => 5,
        ];
        foreach ($types as $name => $rank) {
            $length = strlen($name);
            if ($length > 0 && strlen($label) >= $length && substr($label, -$length) === $name) {
                $typeRank = $rank;
                break;
            }
        }
        return sprintf('%02d-%d-%s', $seasonRank, $typeRank, $label);
    }

    /**
     * @param float|int $part
     * @param float|int $whole
     * @return float
     */
    private function share($part, $whole) {
        $whole = (float) $whole;
        if ($whole == 0.0) {
            return 0.0;
        }
        $part = (float) $part;
        if ($part == 0.0) {
            return 0.0;
        }
        return $part / $whole;
    }

    /**
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param string                                        $cell
     * @param string                                        $value
     * @return void
     */
    private function sheetText($sheet, $cell, $value) {
        $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }

    /**
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param string                                        $cell
     * @param float|int                                     $value
     * @param string                                        $format
     * @return void
     */
    private function sheetNumber($sheet, $cell, $value, $format) {
        $sheet->setCellValueExplicit($cell, 0 + $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
    }

    /**
     * Europe/Zurich wall-clock time as an Excel date serial.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param string                                        $cell
     * @param string                                        $text
     * @return void
     */
    private function sheetZurichDate($sheet, $cell, $text) {
        $text = trim((string) $text);
        $dt = $this->zurichDateFromText($text);
        if (!($dt instanceof \DateTimeImmutable)) {
            $this->sheetText($sheet, $cell, $text);
            return;
        }
        $serial = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($dt);
        if (!is_numeric($serial)) {
            $this->sheetText($sheet, $cell, $text);
            return;
        }
        $this->sheetNumber($sheet, $cell, (float) $serial, 'yyyy-mm-dd hh:mm');
    }

    /**
     * @param string $text
     * @return \DateTimeImmutable|null
     */
    private function zurichDateFromText($text) {
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, (string) $text, new \DateTimeZone(self::TIMEZONE));
            $errors = \DateTimeImmutable::getLastErrors();
            $clean = ($errors === false) || (is_array($errors) && empty($errors['warning_count']) && empty($errors['error_count']));
            if ($parsed instanceof \DateTimeImmutable && $clean) {
                return $parsed;
            }
        }
        return null;
    }

    /**
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array<string,float|int>                       $widths
     * @return void
     */
    private function sheetWidths($sheet, array $widths) {
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension((string) $column)->setWidth((float) $width);
        }
    }
}
