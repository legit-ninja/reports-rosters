<?php
/**
 * Office 365 Sync Service
 *
 * Uploads roster and report Excel files to OneDrive or SharePoint via Microsoft Graph API.
 * Uses application permissions (client credentials). Token is cached in transients.
 *
 * @package InterSoccer\ReportsRosters\Office365
 */

namespace InterSoccer\ReportsRosters\Office365;

defined('ABSPATH') or die('Restricted access');

class SyncService {

    const OPTION_NAME = 'intersoccer_office365_sync_settings';
    const TOKEN_CACHE_KEY = 'intersoccer_office365_graph_token';
    const TOKEN_CACHE_EXPIRY = 3500; // seconds (tokens typically 1h; refresh a bit early)
    const SITE_ID_CACHE_KEY_PREFIX = 'intersoccer_office365_site_id_';
    const SITE_ID_CACHE_EXPIRY = 3600; // 1 hour

    const DESTINATION_ONEDRIVE = 'onedrive';
    const DESTINATION_SHAREPOINT = 'sharepoint';

    const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    const LOGIN_BASE = 'https://login.microsoftonline.com';

    /**
     * @var array
     */
    private $settings;

    /**
     * @var \InterSoccer\ReportsRosters\Core\Logger|null
     */
    private $logger;

    public function __construct(array $settings = null, $logger = null) {
        $this->settings = $settings !== null ? $settings : $this->getStoredSettings();
        $this->logger = $logger;
    }

    /**
     * Whether sync is enabled and configured.
     *
     * @return bool
     */
    public function isEnabled(): bool {
        return !empty($this->settings['enabled'])
            && !empty($this->settings['tenant_id'])
            && !empty($this->settings['client_id'])
            && !empty($this->settings['client_secret']);
    }

    /**
     * Get stored settings from options.
     *
     * @return array
     */
    public static function getStoredSettings(): array {
        $defaults = [
            'enabled' => false,
            'tenant_id' => '',
            'client_id' => '',
            'client_secret' => '',
            'destination_type' => self::DESTINATION_ONEDRIVE,
            'onedrive_user_id' => '',
            'onedrive_folder_path' => '',
            'sharepoint_site_url' => '',
            'sharepoint_folder_path' => '',
            'auto_sync_enabled' => false,
            'auto_sync_schedule' => 'daily',
            'auto_sync_jobs' => [],
        ];
        $stored = get_option(self::OPTION_NAME, []);
        return is_array($stored) ? array_merge($defaults, $stored) : $defaults;
    }

    /**
     * Upload a file to the configured Office 365 destination.
     *
     * @param string $filename Filename (e.g. roster_xxx.xlsx).
     * @param string $content  Raw file content.
     * @return array{ success: bool, message?: string, error?: string }
     */
    public function uploadFile(string $filename, string $content): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => __('Office 365 sync is not enabled or configured.', 'intersoccer-reports-rosters')];
        }

        $token = $this->getAccessToken();
        if ($token === null || $token === '') {
            return ['success' => false, 'error' => __('Failed to obtain Microsoft Graph access token.', 'intersoccer-reports-rosters')];
        }

        $url = $this->buildUploadUrl($filename);
        if ($url === '') {
            return ['success' => false, 'error' => __('Invalid Office 365 destination configuration.', 'intersoccer-reports-rosters')];
        }

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Length' => (string) strlen($content),
            ],
            'body' => $content,
            'timeout' => 60,
        ]);

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            if ($this->logger) {
                $this->logger->info('Office 365 sync: file uploaded', ['filename' => $filename]);
            }
            return ['success' => true, 'message' => sprintf(__('File %s synced to Office 365.', 'intersoccer-reports-rosters'), $filename)];
        }

        $error_message = $this->parseGraphError($body, $code);
        if ($this->logger) {
            $this->logger->error('Office 365 sync: upload failed', [
                'filename' => $filename,
                'code' => $code,
                'body' => $body,
            ]);
        }
        return ['success' => false, 'error' => $error_message];
    }

    /**
     * Get access token (client credentials). Uses transient cache.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string {
        $cached = get_transient(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tenant = $this->settings['tenant_id'] ?? '';
        $client_id = $this->settings['client_id'] ?? '';
        $client_secret = $this->settings['client_secret'] ?? '';
        if ($tenant === '' || $client_id === '' || $client_secret === '') {
            return null;
        }

        $url = self::LOGIN_BASE . '/' . trim($tenant, '/') . '/oauth2/v2.0/token';
        $response = wp_remote_post($url, [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'scope' => 'https://graph.microsoft.com/.default',
            ],
            'timeout' => 15,
        ]);

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || empty($data['access_token'])) {
            if ($this->logger) {
                $this->logger->warning('Office 365: token request failed', ['code' => $code, 'body' => $body]);
            }
            return null;
        }

        set_transient(self::TOKEN_CACHE_KEY, $data['access_token'], self::TOKEN_CACHE_EXPIRY);
        return $data['access_token'];
    }

    /**
     * Invalidate token cache (e.g. after settings change).
     */
    public static function clearTokenCache(): void {
        delete_transient(self::TOKEN_CACHE_KEY);
    }

    /**
     * Build the Graph API URL for uploading a file (PUT .../content).
     *
     * @param string $filename
     * @return string
     */
    private function buildUploadUrl(string $filename): string {
        $type = $this->settings['destination_type'] ?? self::DESTINATION_ONEDRIVE;

        if ($type === self::DESTINATION_ONEDRIVE) {
            $user_id = $this->settings['onedrive_user_id'] ?? '';
            $folder = trim($this->settings['onedrive_folder_path'] ?? '', "/ \t\n\r");
            if ($user_id === '') {
                return '';
            }
            $path = $folder !== '' ? $folder . '/' . $filename : $filename;
            return self::GRAPH_BASE . '/users/' . rawurlencode($user_id) . '/drive/root:/' . rawurlencode($path) . ':/content';
        }

        if ($type === self::DESTINATION_SHAREPOINT) {
            $site_id = $this->getSharePointSiteId();
            if ($site_id === '') {
                return '';
            }
            $folder = trim($this->settings['sharepoint_folder_path'] ?? '', "/ \t\n\r");
            $path = $folder !== '' ? $folder . '/' . $filename : $filename;
            return self::GRAPH_BASE . '/sites/' . $site_id . '/drive/root:/' . rawurlencode($path) . ':/content';
        }

        return '';
    }

    /**
     * Resolve SharePoint site ID from site URL (cached in transient).
     *
     * @return string
     */
    private function getSharePointSiteId(): string {
        $url = trim($this->settings['sharepoint_site_url'] ?? '');
        if ($url === '') {
            return '';
        }

        $cache_key = self::SITE_ID_CACHE_KEY_PREFIX . md5($url);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $parsed = wp_parse_url($url);
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';
        if ($path === '' || $path === '/') {
            $path = '/';
        }
        if ($host === '') {
            return '';
        }

        $token = $this->getAccessToken();
        if ($token === null || $token === '') {
            return '';
        }

        $request_url = self::GRAPH_BASE . '/sites/' . $host . ':' . $path;
        $response = wp_remote_get($request_url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 15,
        ]);

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || empty($data['id'])) {
            return '';
        }
        set_transient($cache_key, $data['id'], self::SITE_ID_CACHE_EXPIRY);
        return $data['id'];
    }

    /**
     * Parse Graph API error response for user-facing message.
     *
     * @param string $body
     * @param int    $code
     * @return string
     */
    private function parseGraphError(string $body, int $code): string {
        $data = json_decode($body, true);
        if (!empty($data['error']['message'])) {
            return sprintf(__('Office 365 error: %s', 'intersoccer-reports-rosters'), $data['error']['message']);
        }
        return sprintf(__('Office 365 upload failed (HTTP %d).', 'intersoccer-reports-rosters'), $code);
    }

    /**
     * Test connection: get token and optionally upload a tiny file.
     *
     * @param bool $test_upload Whether to perform a test file upload.
     * @return array{ success: bool, message: string }
     */
    public function testConnection(bool $test_upload = true): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => __('Office 365 sync is disabled or missing credentials.', 'intersoccer-reports-rosters')];
        }

        $token = $this->getAccessToken();
        if ($token === null || $token === '') {
            return ['success' => false, 'message' => __('Could not obtain access token. Check Tenant ID, Client ID, and Client Secret.', 'intersoccer-reports-rosters')];
        }

        if (!$test_upload) {
            return ['success' => true, 'message' => __('Connection successful. Token obtained.', 'intersoccer-reports-rosters')];
        }

        $test_filename = 'intersoccer_test_' . date('Y-m-d_His') . '.txt';
        $test_content = 'InterSoccer Office 365 connection test. You can delete this file.';
        $result = $this->uploadFile($test_filename, $test_content);
        if ($result['success']) {
            return ['success' => true, 'message' => __('Connection and upload test successful. A test file was created in your Office 365 folder.', 'intersoccer-reports-rosters')];
        }
        return ['success' => false, 'message' => $result['error'] ?? __('Upload test failed.', 'intersoccer-reports-rosters')];
    }
}
