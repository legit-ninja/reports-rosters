<?php
/**
 * Daily sales email for a coupon window.
 *
 * Enable is off by default. Recipients come only from the Send to setting.
 * The schedule follows the coupon's Europe/Zurich active days. The morning
 * after expiry is the final campaign email: it reports the expiry day's paid
 * sales and the running total for the whole campaign (coupon start through
 * expiry). After that send, nothing more goes out for the campaign. A test
 * send goes to one typed address and does not mark the day.
 *
 * @package InterSoccer\ReportsRosters\Services
 */

namespace InterSoccer\ReportsRosters\Services;

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
    const FOOTER_CREATED_FALLBACK = 'This report includes orders with no paid date; those orders are counted on their created date.';
    const FINAL_CAMPAIGN_LINE = 'Final campaign totals';

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
     * Register cron, the admin page, and the request handler.
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
    }

    /**
     * Schedule an hourly check. The Zurich clock, not a fixed 24-hour offset, decides the send.
     *
     * @return void
     */
    public static function ensureScheduled() {
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
     * @return void
     */
    public static function unschedule() {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
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
            $class = ($notice === 'test_sent' || $notice === 'saved') ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($detail) . '</p></div>';
        }
        echo '<p>Enable is off until you turn it on. Recipients come only from Send to. Nothing is hardcoded.</p>';
        echo '<p>The email sends each morning at the chosen Europe/Zurich time while yesterday falls on a day the coupon is active. The morning after expiry is the final campaign email. It reports the expiry day\'s paid sales and the running total for the whole campaign (coupon start through expiry), with Final in the subject. After that send, nothing more goes out for the campaign.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">';
        $this->nonceField(self::NONCE_SETTINGS);
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Enable</th><td><label><input type="checkbox" name="bf_enabled" value="1"' . $checked . ' /> Send the daily email</label></td></tr>';
        echo '<tr><th scope="row"><label for="bf_recipients">Send to</label></th><td>';
        echo '<textarea name="bf_recipients" id="bf_recipients" rows="3" cols="50" class="large-text">' . esc_html($settings['recipients']) . '</textarea>';
        echo '<p class="description">Comma-separated email addresses. An empty list, or any invalid address, sends nothing.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="bf_send_time">Send time</label></th><td>';
        echo '<input type="text" name="bf_send_time" id="bf_send_time" value="' . esc_attr($settings['send_time']) . '" pattern="[0-2][0-9]:[0-5][0-9]" />';
        echo '<p class="description">HH:MM on the Europe/Zurich clock. Daylight saving is handled by that timezone.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="bf_coupon_code">Coupon code</label></th><td>';
        echo '<input type="text" name="bf_coupon_code" id="bf_coupon_code" value="' . esc_attr($settings['coupon_code']) . '" class="regular-text" />';
        echo '<p class="description">Schedule start is the coupon <code>_date_starts</code> value (Unix timestamp or YYYY-MM-DD). Schedule end is the WooCommerce coupon expiry. Both are required. Dates are read in Europe/Zurich.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row">Last sent</th><td><p>' . esc_html($state['last_sent_at'] !== '' ? $state['last_sent_at'] . ' Europe/Zurich' : 'Never') . '</p></td></tr>';
        echo '<tr><th scope="row">Last result</th><td><p>' . esc_html($this->resultLabel($state['last_result'])) . '</p></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" name="bf_save" value="1" class="button button-primary">Save settings</button></p>';
        echo '</form>';

        echo '<hr />';
        echo '<h2>Send test email</h2>';
        echo '<p>Sends yesterday\'s report to one address, with [TEST] in the subject. It does not use the Send to list, does not require Enable, and does not mark the day as sent.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '">';
        $this->nonceField(self::NONCE_TEST);
        echo '<p><label for="bf_test_address">Test address</label><br />';
        echo '<input type="text" name="bf_test_address" id="bf_test_address" value="' . esc_attr($prefill) . '" class="regular-text" /></p>';
        echo '<p><button type="submit" name="bf_test_send" value="1" class="button">Send test email</button></p>';
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
        $dailyReport = $this->loadReport($reportDate, $reportDate);
        $daily = $this->summarize(is_array($dailyReport) ? $dailyReport : []);
        $couponStatus = $this->couponStatus($this->settings()['coupon_code']);
        $campaignStart = '';
        $campaign = null;
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
                $campaignReport = $this->loadReport($campaignStart, $reportDate);
                $campaign = $this->summarize(is_array($campaignReport) ? $campaignReport : []);
            } else {
                $campaign = $this->emptySummary();
            }
        }
        $fallback = !empty($daily['used_created_fallback']);
        if (is_array($campaign) && !empty($campaign['used_created_fallback'])) {
            $fallback = true;
        }
        return [
            'report_date' => $reportDate,
            'campaign_start' => $campaignStart,
            'campaign_available' => $couponStatus['coupon'] !== null,
            'campaign_started' => $campaignStarted,
            'daily' => $daily,
            'campaign' => $campaign,
            'used_created_fallback' => $fallback,
            'final_campaign' => $finalCampaign,
            'booking_report_url' => $this->bookingReportUrl($reportDate),
        ];
    }

    /**
     * @param array<string,mixed> $digest
     * @return string
     */
    public function renderHtml(array $digest) {
        $reportDate = (string) ($digest['report_date'] ?? '');
        $daily = isset($digest['daily']) && is_array($digest['daily']) ? $digest['daily'] : $this->emptySummary();
        $html = '<div>';
        $html .= '<h2>Yesterday (' . esc_html($reportDate) . ', Europe/Zurich)</h2>';
        $html .= '<p>Orders: ' . esc_html((string) (int) ($daily['order_count'] ?? 0)) . '</p>';
        $html .= $this->renderTypeTable($daily);
        $html .= '<h3>Products sold</h3>';
        $html .= $this->renderProductTable($daily);
        if (!empty($digest['final_campaign'])) {
            $html .= '<p>' . esc_html(self::FINAL_CAMPAIGN_LINE) . '</p>';
        }
        $html .= '<h2>Running total since campaign start</h2>';
        if (empty($digest['campaign_available'])) {
            $html .= '<p>The coupon window is not available, so the running total is empty.</p>';
        } elseif (empty($digest['campaign_started'])) {
            $html .= '<p>The campaign has not started, so the running total is empty.</p>';
        } else {
            $campaign = isset($digest['campaign']) && is_array($digest['campaign']) ? $digest['campaign'] : $this->emptySummary();
            $html .= '<p>' . esc_html((string) ($digest['campaign_start'] ?? '')) . ' through ' . esc_html($reportDate) . ' (Europe/Zurich, paid date).</p>';
            $html .= '<p>Orders: ' . esc_html((string) (int) ($campaign['order_count'] ?? 0)) . '</p>';
            $html .= $this->renderTypeTable($campaign);
            $html .= '<h3>Products sold since campaign start</h3>';
            $html .= $this->renderProductTable($campaign);
            $html .= '<p>Campaign net ex-VAT: ' . esc_html($this->money((float) ($campaign['net_ex_vat'] ?? 0))) . ' CHF. Campaign net inc-VAT: ' . esc_html($this->money((float) ($campaign['net_inc_vat'] ?? 0))) . ' CHF.</p>';
        }
        $url = (string) ($digest['booking_report_url'] ?? '');
        $html .= '<p><a href="' . esc_url($url) . '">Booking report for this day</a></p>';
        $html .= '<p>Net ex-VAT is the booking-report net after discounts and refunds, tax excluded. Net inc-VAT adds the line tax that remains after refunds.</p>';
        $html .= '<p>' . esc_html(self::FOOTER_PAID_DATE) . '</p>';
        if (!empty($digest['used_created_fallback'])) {
            $html .= '<p>' . esc_html(self::FOOTER_CREATED_FALLBACK) . '</p>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * @param string $raw
     * @return array{ok:bool,reason:string,emails:array<int,string>}
     */
    public function parseRecipientList($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['ok' => false, 'reason' => 'empty_recipients', 'emails' => []];
        }
        $parts = explode(',', $raw);
        $emails = [];
        $sawToken = false;
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '') {
                continue;
            }
            $sawToken = true;
            if (strpos($token, ';') !== false || preg_match('/\s/', $token) || !function_exists('is_email') || !is_email($token)) {
                return ['ok' => false, 'reason' => 'invalid_recipient', 'emails' => []];
            }
            $emails[] = $this->stripHeaderBreaks($token);
        }
        if (!$sawToken || $emails === []) {
            return ['ok' => false, 'reason' => 'empty_recipients', 'emails' => []];
        }
        return ['ok' => true, 'reason' => '', 'emails' => $emails];
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
     * The morning after expiry reports the expiry day, which still overlaps.
     * That expiry day is the final campaign day. The following morning does not.
     *
     * @param string $ymd
     * @param int    $startUnix
     * @param int    $expiresUnix
     * @return bool
     */
    public static function zurichDayOverlapsWindow($ymd, $startUnix, $expiresUnix) {
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
        if ($dayStart->getTimestamp() > (int) $expiresUnix) {
            return false;
        }
        return true;
    }

    /**
     * A reported day is final when it is the last Zurich calendar day that
     * overlaps the coupon window: the expiry day.
     *
     * @param string $reportDate
     * @param int    $startUnix
     * @param int    $expiresUnix
     * @return bool
     */
    public static function isFinalCampaignDay($reportDate, $startUnix, $expiresUnix) {
        $expiryDay = self::zurichYmdFromTimestamp((int) $expiresUnix);
        if ((string) $reportDate !== $expiryDay) {
            return false;
        }
        return self::zurichDayOverlapsWindow($reportDate, $startUnix, $expiresUnix);
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
     * @return array{enabled:bool,recipients:string,send_time:string,coupon_code:string}
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
        return [
            'enabled' => !empty($stored['enabled']),
            'recipients' => isset($stored['recipients']) ? (string) $stored['recipients'] : '',
            'send_time' => $sendTime,
            'coupon_code' => isset($stored['coupon_code']) ? (string) $stored['coupon_code'] : '',
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
        $code = isset($post['bf_coupon_code']) ? (string) $post['bf_coupon_code'] : '';
        $code = function_exists('sanitize_text_field') ? sanitize_text_field($code) : trim($code);
        if (strlen($code) > 64) {
            $code = substr($code, 0, 64);
        }
        update_option(self::OPTION_SETTINGS, [
            'enabled' => !empty($post['bf_enabled']),
            'recipients' => $recipients,
            'send_time' => $sendTime,
            'coupon_code' => $code,
        ], false);
        return [
            'ok' => true,
            'code' => 'saved',
            'message' => 'Settings saved.',
        ];
    }

    /**
     * @param string $startYmd
     * @param string $endYmd
     * @return array<string,mixed>
     */
    private function loadReport($startYmd, $endYmd) {
        $bounds = self::rangeBounds($startYmd, $endYmd, $this->siteTimezone());
        $options = [
            'date_basis' => 'paid_or_created',
            'paid_start_unix' => $bounds['paid_start_unix'],
            'paid_end_unix' => $bounds['paid_end_unix'],
            'created_start_local' => $bounds['created_start_local'],
            'created_end_local' => $bounds['created_end_local'],
        ];
        return $this->reportService()->getFinancialBookingReport($startYmd, $endYmd, '', '', $options);
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
        uasort($products, function ($a, $b) {
            return strcmp((string) $a['label'], (string) $b['label']);
        });
        return [
            'order_count' => count($orders),
            'types' => array_values($types),
            'products' => array_values($products),
            'used_created_fallback' => $fallback,
            'net_ex_vat' => round($netEx, 2),
            'net_inc_vat' => round($netInc, 2),
        ];
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
            'used_created_fallback' => false,
            'net_ex_vat' => 0.0,
            'net_inc_vat' => 0.0,
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @return string
     */
    private function renderTypeTable(array $summary) {
        $html = '<table><thead><tr>';
        $html .= '<th>Product type</th><th>Qty</th><th>Gross ex-VAT</th><th>Discounts</th><th>Refunds</th><th>Net ex-VAT</th><th>Net inc-VAT</th>';
        $html .= '</tr></thead><tbody>';
        $types = isset($summary['types']) && is_array($summary['types']) ? $summary['types'] : [];
        foreach ($types as $type) {
            if (!is_array($type)) {
                continue;
            }
            $html .= '<tr>';
            $html .= '<td>' . esc_html((string) ($type['label'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) (int) ($type['quantity'] ?? 0)) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) ($type['gross_ex_vat'] ?? 0))) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) ($type['discounts'] ?? 0))) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) ($type['refunds'] ?? 0))) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) ($type['net_ex_vat'] ?? 0))) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) ($type['net_inc_vat'] ?? 0))) . '</td>';
            $html .= '</tr>';
        }
        $html .= '<tr><td>Total</td><td></td><td></td><td></td><td></td>';
        $html .= '<td>' . esc_html($this->money((float) ($summary['net_ex_vat'] ?? 0))) . '</td>';
        $html .= '<td>' . esc_html($this->money((float) ($summary['net_inc_vat'] ?? 0))) . '</td></tr>';
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * @param array<string,mixed> $summary
     * @return string
     */
    private function renderProductTable(array $summary) {
        $html = '<table><thead><tr><th>Product</th><th>Qty</th><th>Net ex-VAT</th><th>Net inc-VAT</th></tr></thead><tbody>';
        $products = isset($summary['products']) && is_array($summary['products']) ? $summary['products'] : [];
        if ($products === []) {
            $html .= '<tr><td colspan="4">No products sold in this range.</td></tr>';
        }
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $html .= '<tr>';
            $html .= '<td>' . esc_html((string) ($product['label'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html((string) (int) ($product['quantity'] ?? 0)) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) ($product['net_ex_vat'] ?? 0))) . '</td>';
            $html .= '<td>' . esc_html($this->money((float) ($product['net_inc_vat'] ?? 0))) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
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
        $raw = null;
        if ($this->couponLoader !== null) {
            $raw = call_user_func($this->couponLoader, $code);
        } else {
            $raw = $this->loadCouponFromWoo($code);
        }
        if (!is_array($raw)) {
            return ['coupon' => null, 'reason' => 'coupon_not_found'];
        }
        $start = $this->parseInstant(isset($raw['start']) ? $raw['start'] : null);
        $expires = $this->parseInstant(isset($raw['expires']) ? $raw['expires'] : null);
        if ($start === null || $expires === null) {
            return ['coupon' => null, 'reason' => 'coupon_missing_dates'];
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
     * @param string $code
     * @return array{start:mixed,expires:mixed}|null
     */
    private function loadCouponFromWoo($code) {
        if (!function_exists('wc_get_coupon_id_by_code') || !function_exists('get_post_meta')) {
            return null;
        }
        $id = (int) wc_get_coupon_id_by_code($code);
        if ($id <= 0) {
            return null;
        }
        $start = get_post_meta($id, '_date_starts', true);
        if ($start === '' || $start === false || $start === null) {
            $start = get_post_meta($id, 'date_starts', true);
        }
        $expires = get_post_meta($id, '_date_expires', true);
        if (function_exists('wc_get_coupon')) {
            $coupon = wc_get_coupon($id);
            if (is_object($coupon) && method_exists($coupon, 'get_date_starts')) {
                $started = $coupon->get_date_starts();
                if ($started instanceof \DateTimeInterface) {
                    $start = $started->getTimestamp();
                }
            }
            if (is_object($coupon) && method_exists($coupon, 'get_date_expires')) {
                $expiry = $coupon->get_date_expires();
                if ($expiry instanceof \DateTimeInterface) {
                    $expires = $expiry->getTimestamp();
                }
            }
        }
        return [
            'start' => $start,
            'expires' => $expires,
        ];
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
            'invalid_recipient' => 'Not sent: the Send to list contains an invalid address',
            'outside_window' => 'Not sent: outside the coupon window',
            'coupon_missing' => 'Not sent: no coupon code is set',
            'coupon_not_found' => 'Not sent: the coupon was not found',
            'coupon_missing_dates' => 'Not sent: the coupon is missing a start or expiry',
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
