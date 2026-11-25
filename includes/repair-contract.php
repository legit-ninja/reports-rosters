<?php
/**
 * Preview / Apply / backup / rollback contract for roster repairs.
 *
 * Office staff use Advanced tools. Preview writes nothing. Apply requires a
 * matching Preview, the typed phrase APPLY, and GLOBAL when the scope is
 * shop-wide. Deletes require the extra phrase DELETE.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!defined('INTERSOCCER_RR_REPAIR_CAP')) {
    define('INTERSOCCER_RR_REPAIR_CAP', 'intersoccer_run_repairs');
}
if (!defined('INTERSOCCER_RR_ADVANCED_TOOLS_PAGE')) {
    define('INTERSOCCER_RR_ADVANCED_TOOLS_PAGE', 'intersoccer-advanced-tools');
}
if (!defined('INTERSOCCER_RR_REPAIR_NONCE')) {
    define('INTERSOCCER_RR_REPAIR_NONCE', 'intersoccer_rr_advanced_tools');
}
if (!defined('INTERSOCCER_RR_REPAIR_APPLY_PHRASE')) {
    define('INTERSOCCER_RR_REPAIR_APPLY_PHRASE', 'APPLY');
}
if (!defined('INTERSOCCER_RR_REPAIR_GLOBAL_PHRASE')) {
    define('INTERSOCCER_RR_REPAIR_GLOBAL_PHRASE', 'GLOBAL');
}
if (!defined('INTERSOCCER_RR_REPAIR_DELETE_PHRASE')) {
    define('INTERSOCCER_RR_REPAIR_DELETE_PHRASE', 'DELETE');
}
if (!defined('INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP')) {
    define('INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP', 200);
}
if (!defined('INTERSOCCER_RR_REPAIR_PREVIEW_TTL')) {
    $intersoccer_rr_repair_minute = defined('MINUTE_IN_SECONDS') ? (int) MINUTE_IN_SECONDS : 60;
    define('INTERSOCCER_RR_REPAIR_PREVIEW_TTL', 30 * $intersoccer_rr_repair_minute);
    unset($intersoccer_rr_repair_minute);
}

if (!function_exists('intersoccer_rr_repair_grant_administrator_cap')) {
    /**
     * Grant the repair capability to the Administrator role only.
     */
    function intersoccer_rr_repair_grant_administrator_cap(): void {
        $role = get_role('administrator');
        if (!$role || !method_exists($role, 'has_cap') || !method_exists($role, 'add_cap')) {
            return;
        }
        if ($role->has_cap(INTERSOCCER_RR_REPAIR_CAP)) {
            return;
        }
        $role->add_cap(INTERSOCCER_RR_REPAIR_CAP, true);
    }
}

if (!function_exists('intersoccer_rr_user_can_run_repairs')) {
    function intersoccer_rr_user_can_run_repairs(): bool {
        return current_user_can(INTERSOCCER_RR_REPAIR_CAP);
    }
}

if (!function_exists('intersoccer_rr_repair_require_cap')) {
    function intersoccer_rr_repair_require_cap(): void {
        if (!intersoccer_rr_user_can_run_repairs()) {
            wp_die(esc_html__('You do not have permission to run repairs.', 'intersoccer-reports-rosters'));
        }
    }
}

if (!function_exists('intersoccer_rr_repair_state_get')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function intersoccer_rr_repair_state_get(string $key, $default = null) {
        if (isset($GLOBALS['intersoccer_test_option_store']) && is_array($GLOBALS['intersoccer_test_option_store'])
            && array_key_exists($key, $GLOBALS['intersoccer_test_option_store'])) {
            $value = $GLOBALS['intersoccer_test_option_store'][$key];
            return $value === null ? $default : $value;
        }
        if (function_exists('get_option')) {
            $missing = new stdClass();
            $value = get_option($key, $missing);
            if ($value !== $missing && $value !== false && $value !== null) {
                return $value;
            }
        }
        if (isset($GLOBALS['intersoccer_rr_repair_memory']) && is_array($GLOBALS['intersoccer_rr_repair_memory'])
            && array_key_exists($key, $GLOBALS['intersoccer_rr_repair_memory'])
            && $GLOBALS['intersoccer_rr_repair_memory'][$key] !== null) {
            return $GLOBALS['intersoccer_rr_repair_memory'][$key];
        }
        return $default;
    }
}

if (!function_exists('intersoccer_rr_repair_state_set')) {
    /**
     * @param mixed $value
     */
    function intersoccer_rr_repair_state_set(string $key, $value): void {
        if (!isset($GLOBALS['intersoccer_rr_repair_memory']) || !is_array($GLOBALS['intersoccer_rr_repair_memory'])) {
            $GLOBALS['intersoccer_rr_repair_memory'] = [];
        }
        $GLOBALS['intersoccer_rr_repair_memory'][$key] = $value;
        if (isset($GLOBALS['intersoccer_test_option_store']) && is_array($GLOBALS['intersoccer_test_option_store'])) {
            $GLOBALS['intersoccer_test_option_store'][$key] = $value;
        }
        if (function_exists('update_option')) {
            update_option($key, $value, false);
        }
    }
}

if (!function_exists('intersoccer_rr_repair_reset_state')) {
    function intersoccer_rr_repair_reset_state(): void {
        $GLOBALS['intersoccer_rr_repair_memory'] = [];
        if (isset($GLOBALS['intersoccer_test_option_store']) && is_array($GLOBALS['intersoccer_test_option_store'])) {
            foreach (array_keys($GLOBALS['intersoccer_test_option_store']) as $key) {
                if (is_string($key) && strpos($key, 'intersoccer_rr_repair_') === 0) {
                    unset($GLOBALS['intersoccer_test_option_store'][$key]);
                }
            }
        }
    }
}

if (!function_exists('intersoccer_rr_repair_normalize_scope')) {
    function intersoccer_rr_repair_normalize_scope(array $scope): array {
        ksort($scope);
        foreach ($scope as $key => $value) {
            if (is_array($value)) {
                $flat = array_map('strval', $value);
                sort($flat);
                $scope[$key] = $flat;
            } elseif (is_bool($value)) {
                $scope[$key] = $value ? '1' : '0';
            } else {
                $scope[$key] = (string) $value;
            }
        }
        return $scope;
    }
}

if (!function_exists('intersoccer_rr_repair_fingerprint')) {
    function intersoccer_rr_repair_fingerprint(array $scope): string {
        return hash('sha256', (string) wp_json_encode(intersoccer_rr_repair_normalize_scope($scope)));
    }
}

if (!function_exists('intersoccer_rr_repair_rows_fingerprint')) {
    function intersoccer_rr_repair_rows_fingerprint(array $rows): string {
        return hash('sha256', (string) wp_json_encode(array_values($rows)));
    }
}

if (!function_exists('intersoccer_rr_repair_preview_key')) {
    function intersoccer_rr_repair_preview_key(int $user_id, string $tool): string {
        return 'intersoccer_rr_repair_preview_' . $user_id . '_' . sanitize_key($tool);
    }
}

if (!function_exists('intersoccer_rr_repair_store_preview')) {
    function intersoccer_rr_repair_store_preview(int $user_id, string $tool, array $scope, array $result): void {
        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $changed = (int) ($counts['changed'] ?? 0);
        if ($changed === 0) {
            $changed = (int) ($counts['added'] ?? 0) + (int) ($counts['updated'] ?? 0) + (int) ($counts['deleted'] ?? 0);
            $counts['changed'] = $changed;
        }
        intersoccer_rr_repair_state_set(intersoccer_rr_repair_preview_key($user_id, $tool), [
            'user_id' => $user_id,
            'tool' => sanitize_key($tool),
            'scope' => intersoccer_rr_repair_normalize_scope($scope),
            'fingerprint' => intersoccer_rr_repair_fingerprint($scope),
            'rows_fingerprint' => intersoccer_rr_repair_rows_fingerprint($rows),
            'counts' => $counts,
            'rows' => $rows,
            'summary' => (string) ($result['summary'] ?? ''),
            'shop_wide' => !empty($result['shop_wide']),
            'deletes' => (int) ($counts['deleted'] ?? 0),
            'changed' => $changed,
            'over_cap' => !empty($result['over_cap']),
            'created_at' => time(),
        ]);
    }
}

if (!function_exists('intersoccer_rr_repair_get_preview')) {
    function intersoccer_rr_repair_get_preview(int $user_id, string $tool): ?array {
        $stored = intersoccer_rr_repair_state_get(intersoccer_rr_repair_preview_key($user_id, $tool), null);
        if (!is_array($stored) || empty($stored['fingerprint']) || !empty($stored['consumed'])) {
            return null;
        }
        $created = (int) ($stored['created_at'] ?? 0);
        if ($created <= 0 || (time() - $created) > (int) INTERSOCCER_RR_REPAIR_PREVIEW_TTL) {
            return null;
        }
        return $stored;
    }
}

if (!function_exists('intersoccer_rr_repair_clear_preview')) {
    function intersoccer_rr_repair_clear_preview(int $user_id, string $tool): void {
        intersoccer_rr_repair_state_set(intersoccer_rr_repair_preview_key($user_id, $tool), ['consumed' => true]);
    }
}

if (!function_exists('intersoccer_rr_repair_verify_nonce')) {
    function intersoccer_rr_repair_verify_nonce(string $action = INTERSOCCER_RR_REPAIR_NONCE): bool {
        if (array_key_exists('intersoccer_rr_test_nonce_valid', $GLOBALS)) {
            return (bool) $GLOBALS['intersoccer_rr_test_nonce_valid'];
        }
        $nonce = '';
        if (isset($_POST['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash((string) $_POST['_wpnonce']));
        } elseif (isset($_POST['intersoccer_rr_repair_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash((string) $_POST['intersoccer_rr_repair_nonce']));
        }
        if ($nonce === '') {
            return false;
        }
        return (bool) wp_verify_nonce($nonce, $action);
    }
}

if (!function_exists('intersoccer_rr_repair_gate_apply')) {
    /**
     * @return array{ok:bool,error:string,code:string}
     */
    function intersoccer_rr_repair_gate_apply(int $user_id, string $tool, array $scope, string $typed_apply, string $typed_global, string $typed_delete): array {
        $preview = intersoccer_rr_repair_get_preview($user_id, $tool);
        if ($preview === null) {
            return [
                'ok' => false,
                'error' => __('Run Preview before Apply.', 'intersoccer-reports-rosters'),
                'code' => 'no_preview',
            ];
        }
        if ((string) ($preview['fingerprint'] ?? '') !== intersoccer_rr_repair_fingerprint($scope)) {
            return [
                'ok' => false,
                'error' => __('The change set no longer matches the Preview. Run Preview again.', 'intersoccer-reports-rosters'),
                'code' => 'mismatch',
            ];
        }
        if (trim($typed_apply) !== INTERSOCCER_RR_REPAIR_APPLY_PHRASE) {
            return [
                'ok' => false,
                'error' => __('Type APPLY to confirm.', 'intersoccer-reports-rosters'),
                'code' => 'apply_phrase',
            ];
        }
        if (!empty($preview['shop_wide']) && trim($typed_global) !== INTERSOCCER_RR_REPAIR_GLOBAL_PHRASE) {
            return [
                'ok' => false,
                'error' => __('This scope is shop-wide. Type GLOBAL as well as APPLY.', 'intersoccer-reports-rosters'),
                'code' => 'global_phrase',
            ];
        }
        if ((int) ($preview['deletes'] ?? 0) > 0 && trim($typed_delete) !== INTERSOCCER_RR_REPAIR_DELETE_PHRASE) {
            return [
                'ok' => false,
                'error' => __('Preview includes row deletes. Type DELETE to confirm those deletes.', 'intersoccer-reports-rosters'),
                'code' => 'delete_phrase',
            ];
        }
        if ((int) ($preview['changed'] ?? 0) === 0 && (int) ($preview['deletes'] ?? 0) === 0) {
            return [
                'ok' => false,
                'error' => __('Preview has no changes. Apply was not run.', 'intersoccer-reports-rosters'),
                'code' => 'empty',
            ];
        }
        $preview_rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
        if (!empty($preview['over_cap']) || count($preview_rows) > (int) INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP) {
            return [
                'ok' => false,
                'error' => intersoccer_rr_repair_over_cap_message(max(count($preview_rows), (int) ($preview['changed'] ?? 0))),
                'code' => 'over_cap',
            ];
        }
        return ['ok' => true, 'error' => '', 'code' => 'ok'];
    }
}

if (!function_exists('intersoccer_rr_repair_backup_uploads_dir')) {
    /**
     * Fallback directory under the uploads basedir.
     */
    function intersoccer_rr_repair_backup_uploads_dir(): string {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => sys_get_temp_dir()];
        $base = isset($uploads['basedir']) && is_string($uploads['basedir']) && $uploads['basedir'] !== ''
            ? $uploads['basedir']
            : sys_get_temp_dir();
        return rtrim($base, '/') . '/intersoccer-rr-repair-backups';
    }
}

if (!function_exists('intersoccer_rr_repair_backup_dir_choose')) {
    /**
     * Prefer a directory outside the web root.
     *
     * Backups store roster fields, which can include personal data. The
     * preferred path is a sibling of the WordPress content directory:
     * dirname(WP_CONTENT_DIR) . '/intersoccer-rr-repair-backups'. That sits
     * beside wp-content, outside the public tree when content lives inside
     * the site directory. When that parent is missing or not writable, the
     * fallback is the uploads directory from
     * intersoccer_rr_repair_backup_uploads_dir(). The caller still writes
     * index.php and a deny-web .htaccess, and each file name keeps its long
     * random suffix.
     *
     * @param string|null $content_dir WordPress content directory. Null uses WP_CONTENT_DIR.
     */
    function intersoccer_rr_repair_backup_dir_choose(?string $content_dir = null): string {
        $fallback = intersoccer_rr_repair_backup_uploads_dir();
        if ($content_dir === null && defined('WP_CONTENT_DIR')) {
            $content_dir = (string) WP_CONTENT_DIR;
        }
        $content_dir = rtrim((string) $content_dir, '/');
        if ($content_dir === '') {
            return $fallback;
        }
        $parent = dirname($content_dir);
        if ($parent === '' || $parent === '.' || $parent === $content_dir || !is_dir($parent) || !is_writable($parent)) {
            return $fallback;
        }
        return $parent . '/intersoccer-rr-repair-backups';
    }
}

if (!function_exists('intersoccer_rr_repair_backup_dir')) {
    function intersoccer_rr_repair_backup_dir(): string {
        if (!empty($GLOBALS['intersoccer_rr_test_backup_dir'])) {
            $dir = (string) $GLOBALS['intersoccer_rr_test_backup_dir'];
        } else {
            $dir = intersoccer_rr_repair_backup_dir_choose();
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                $dir = intersoccer_rr_repair_backup_uploads_dir();
            }
        }
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        $deny = $dir . '/.htaccess';
        if (!file_exists($deny)) {
            file_put_contents($deny, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        return $dir;
    }
}

if (!function_exists('intersoccer_rr_repair_write_backup')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    function intersoccer_rr_repair_write_backup(string $tool, int $user_id, array $rows): string {
        $dir = intersoccer_rr_repair_backup_dir();
        $name = sanitize_key($tool) . '-' . gmdate('Ymd-His') . '-' . $user_id . '-' . bin2hex(random_bytes(16)) . '.json';
        $payload = [
            'tool' => sanitize_key($tool),
            'user_id' => $user_id,
            'created_at' => gmdate('c'),
            'rows' => $rows,
        ];
        file_put_contents($dir . '/' . $name, (string) wp_json_encode($payload));
        $index = intersoccer_rr_repair_state_get('intersoccer_rr_repair_backup_index', []);
        if (!is_array($index)) {
            $index = [];
        }
        $index[] = [
            'file' => $name,
            'tool' => sanitize_key($tool),
            'user_id' => $user_id,
            'created_at' => $payload['created_at'],
        ];
        intersoccer_rr_repair_state_set('intersoccer_rr_repair_backup_index', $index);
        return $name;
    }
}

if (!function_exists('intersoccer_rr_repair_replace_backup_rows')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    function intersoccer_rr_repair_replace_backup_rows(string $filename, array $rows): void {
        $filename = basename($filename);
        if (!preg_match('/^[a-z0-9._-]+\.json$/', $filename)) {
            return;
        }
        $data = intersoccer_rr_repair_read_backup($filename);
        if ($data === null) {
            return;
        }
        $data['rows'] = $rows;
        file_put_contents(intersoccer_rr_repair_backup_dir() . '/' . $filename, (string) wp_json_encode($data));
    }
}

if (!function_exists('intersoccer_rr_repair_read_backup')) {
    function intersoccer_rr_repair_read_backup(string $filename): ?array {
        $filename = basename($filename);
        if (!preg_match('/^[a-z0-9._-]+\.json$/', $filename)) {
            return null;
        }
        $path = intersoccer_rr_repair_backup_dir() . '/' . $filename;
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('intersoccer_rr_repair_values_equal')) {
    /**
     * @param mixed $left
     * @param mixed $right
     */
    function intersoccer_rr_repair_values_equal($left, $right): bool {
        if (is_array($right)) {
            if (!is_array($left)) {
                return false;
            }
            foreach ($right as $key => $value) {
                if (!array_key_exists($key, $left) || !intersoccer_rr_repair_values_equal($left[$key], $value)) {
                    return false;
                }
            }
            return true;
        }
        if (is_array($left)) {
            return false;
        }
        return (string) $left === (string) $right;
    }
}

if (!function_exists('intersoccer_rr_repair_rollback_rows')) {
    /**
     * Restore rows whose current value still equals what Apply wrote.
     *
     * @param array<string,mixed> $backup
     * @return array{restored:int,conflicts:array<int,mixed>}
     */
    function intersoccer_rr_repair_rollback_rows(array $backup, callable $read_current, callable $restore): array {
        $restored = 0;
        $conflicts = [];
        $rows = is_array($backup['rows'] ?? null) ? $backup['rows'] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('id', $row)) {
                continue;
            }
            $before = $row['before'] ?? null;
            $after = array_key_exists('after', $row) ? $row['after'] : null;
            $current = $read_current($row['id']);
            if (intersoccer_rr_repair_values_equal($current, $before)) {
                continue;
            }
            $after_known = $after !== null && !intersoccer_rr_repair_values_equal($before, $after);
            if ($after_known && !intersoccer_rr_repair_values_equal($current, $after)) {
                $conflicts[] = $row['id'];
                continue;
            }
            $restore($row['id'], $before);
            $restored++;
        }
        return ['restored' => $restored, 'conflicts' => $conflicts];
    }
}

if (!function_exists('intersoccer_rr_repair_log')) {
    function intersoccer_rr_repair_log(array $entry): void {
        $log = intersoccer_rr_repair_state_get('intersoccer_rr_repair_run_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        unset($entry['rows'], $entry['customer'], $entry['email']);
        $entry['user_id'] = (int) ($entry['user_id'] ?? get_current_user_id());
        $entry['at'] = gmdate('c');
        $entry['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        $log[] = $entry;
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        intersoccer_rr_repair_state_set('intersoccer_rr_repair_run_log', $log);
    }
}

if (!function_exists('intersoccer_rr_repair_log_entries')) {
    function intersoccer_rr_repair_log_entries(): array {
        $log = intersoccer_rr_repair_state_get('intersoccer_rr_repair_run_log', []);
        return is_array($log) ? $log : [];
    }
}

if (!function_exists('intersoccer_rr_repair_block_legacy_ajax')) {
    /**
     * Legacy admin-ajax repair actions are not the Advanced tools contract.
     * Full rebuild and global signature rebuild are not offered in admin.
     */
    function intersoccer_rr_repair_block_legacy_ajax(string $tool): void {
        $removed = in_array($tool, ['rebuild_rosters', 'rebuild_event_signatures'], true);
        if ($removed || !intersoccer_rr_user_can_run_repairs()) {
            $message = $removed
                ? __('This repair is not available in admin.', 'intersoccer-reports-rosters')
                : __('You do not have permission to run repairs.', 'intersoccer-reports-rosters');
            wp_send_json_error(['message' => $message], 403);
            return;
        }
        wp_send_json_error([
            'message' => __('This repair runs only from Advanced tools.', 'intersoccer-reports-rosters'),
        ], 403);
    }
}

if (!function_exists('intersoccer_rr_scope_is_shop_wide')) {
    function intersoccer_rr_scope_is_shop_wide(string $tool, array $scope): bool {
        $blank = static function ($value): bool {
            return trim((string) $value) === '';
        };
        switch ($tool) {
            case 'reconcile_rosters':
                return !intersoccer_rr_repair_scope_has_ids($scope['order_ids'] ?? '')
                    && !intersoccer_rr_repair_scope_has_ids($scope['product_ids'] ?? '');
            case 'financial_backfill':
                return $blank($scope['start_date'] ?? '');
            case 'fix_issues':
                return $blank($scope['order_id'] ?? '') && $blank($scope['year'] ?? '');
            case 'upgrade_database':
            case 'normalize_language':
            case 'sync_placeholders':
                return true;
            default:
                return false;
        }
    }
}

if (!function_exists('intersoccer_rr_repair_over_cap_message')) {
    function intersoccer_rr_repair_over_cap_message(int $count): string {
        return sprintf(
            /* translators: 1: row count, 2: backup row cap */
            __('This change set has %1$d rows, above the backup limit of %2$d. Narrow the scope and run Preview again. Apply was not run.', 'intersoccer-reports-rosters'),
            $count,
            (int) INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP
        );
    }
}

if (!function_exists('intersoccer_rr_repair_scope_has_ids')) {
    /**
     * True when the field contains at least one positive id.
     * Dates are not ids. A blank or non-numeric list is not a scope.
     *
     * @param mixed $raw
     */
    function intersoccer_rr_repair_scope_has_ids($raw): bool {
        $raw = is_array($raw) ? implode(',', $raw) : (string) $raw;
        if (function_exists('intersoccer_rr_tool_id_list')) {
            return intersoccer_rr_tool_id_list($raw) !== [];
        }
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        foreach ($parts as $part) {
            if ((int) $part > 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('intersoccer_rr_repair_tool_supports_rollback')) {
    function intersoccer_rr_repair_tool_supports_rollback(string $tool): bool {
        if (function_exists('intersoccer_rr_advanced_tools_definitions')) {
            $definitions = intersoccer_rr_advanced_tools_definitions();
            if (isset($definitions[$tool])) {
                return empty($definitions[$tool]['no_rollback']);
            }
        }
        return !in_array($tool, ['upgrade_database', 'fix_issues', 'financial_backfill', 'sync_placeholders'], true);
    }
}

if (!function_exists('intersoccer_rr_repair_uninstall')) {
    /**
     * Remove the repair capability and repair options. Roster tables stay.
     */
    function intersoccer_rr_repair_uninstall(): void {
        $names = ['administrator', 'shop_manager', 'coach'];
        if (isset($GLOBALS['intersoccer_test_roles']) && is_array($GLOBALS['intersoccer_test_roles'])) {
            $names = array_values(array_unique(array_merge($names, array_keys($GLOBALS['intersoccer_test_roles']))));
        }
        if (function_exists('wp_roles')) {
            $wp_roles = wp_roles();
            if (is_object($wp_roles) && isset($wp_roles->role_names) && is_array($wp_roles->role_names)) {
                $names = array_values(array_unique(array_merge($names, array_keys($wp_roles->role_names))));
            }
        }
        foreach ($names as $name) {
            $role = get_role($name);
            if ($role && method_exists($role, 'remove_cap')) {
                $role->remove_cap(INTERSOCCER_RR_REPAIR_CAP);
            }
        }
        if (isset($GLOBALS['intersoccer_test_option_store']) && is_array($GLOBALS['intersoccer_test_option_store'])) {
            foreach (array_keys($GLOBALS['intersoccer_test_option_store']) as $key) {
                if (is_string($key) && strpos($key, 'intersoccer_rr_repair_') === 0) {
                    unset($GLOBALS['intersoccer_test_option_store'][$key]);
                }
            }
        }
        if (isset($GLOBALS['intersoccer_rr_repair_memory']) && is_array($GLOBALS['intersoccer_rr_repair_memory'])) {
            foreach (array_keys($GLOBALS['intersoccer_rr_repair_memory']) as $key) {
                if (is_string($key) && strpos($key, 'intersoccer_rr_repair_') === 0) {
                    unset($GLOBALS['intersoccer_rr_repair_memory'][$key]);
                }
            }
        }
        global $wpdb;
        if (isset($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'query') && method_exists($wpdb, 'prepare')) {
            $like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like('intersoccer_rr_repair_') . '%' : 'intersoccer_rr_repair_%';
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        }
    }
}

add_action('init', 'intersoccer_rr_repair_grant_administrator_cap', 20);
