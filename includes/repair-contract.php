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

if (!function_exists('intersoccer_rr_repair_grant_administrator_cap')) {
    /**
     * Grant the repair capability to the Administrator role only.
     */
    function intersoccer_rr_repair_grant_administrator_cap(): void {
        $role = get_role('administrator');
        if ($role && method_exists($role, 'add_cap')) {
            $role->add_cap(INTERSOCCER_RR_REPAIR_CAP, true);
        }
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
        return ['ok' => true, 'error' => '', 'code' => 'ok'];
    }
}

if (!function_exists('intersoccer_rr_repair_backup_dir')) {
    function intersoccer_rr_repair_backup_dir(): string {
        if (!empty($GLOBALS['intersoccer_rr_test_backup_dir'])) {
            $dir = (string) $GLOBALS['intersoccer_rr_test_backup_dir'];
        } else {
            $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => sys_get_temp_dir()];
            $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : sys_get_temp_dir();
            $dir = rtrim($base, '/') . '/intersoccer-rr-repair-backups';
        }
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
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
        $name = sanitize_key($tool) . '-' . gmdate('Ymd-His') . '-' . $user_id . '-' . bin2hex(random_bytes(3)) . '.json';
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
            $current = $read_current($row['id']);
            if (intersoccer_rr_repair_values_equal($current, $row['after'] ?? null)) {
                $restore($row['id'], $row['before'] ?? null);
                $restored++;
            } else {
                $conflicts[] = $row['id'];
            }
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
                return $blank($scope['date_from'] ?? '')
                    && $blank($scope['date_to'] ?? '')
                    && $blank($scope['order_ids'] ?? '')
                    && $blank($scope['product_ids'] ?? '');
            case 'financial_backfill':
                return $blank($scope['start_date'] ?? '');
            case 'fix_issues':
                return $blank($scope['order_id'] ?? '') && $blank($scope['year'] ?? '');
            case 'upgrade_database':
            case 'process_orders':
            case 'normalize_language':
            case 'sync_placeholders':
                return true;
            default:
                return false;
        }
    }
}

add_action('init', 'intersoccer_rr_repair_grant_administrator_cap', 20);
