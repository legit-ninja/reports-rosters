<?php
/**
 * Medical / allergy note visibility for roster and coach UI.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') || exit;

if (!function_exists('intersoccer_rr_medical_roster_field_keys')) {
    /**
     * Roster row keys that may contain medical or dietary text.
     *
     * @return string[]
     */
    function intersoccer_rr_medical_roster_field_keys(): array {
        return [
            'medical_conditions',
            'dietary_needs',
            'player_medical',
            'player_dietary',
        ];
    }
}

if (!function_exists('intersoccer_rr_medical_dob_field_keys')) {
    /**
     * Roster row keys for date of birth (hidden when medical access is denied).
     *
     * @return string[]
     */
    function intersoccer_rr_medical_dob_field_keys(): array {
        return [
            'player_dob',
            'dob',
        ];
    }
}

if (!function_exists('intersoccer_rr_current_user_can_view_medical')) {
    /**
     * Whether the current user may see medical/allergy notes in roster UI.
     *
     * @param int|null $user_id Optional user ID (defaults to current user).
     */
    function intersoccer_rr_current_user_can_view_medical($user_id = null): bool {
        $user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
        $allowed = false;

        if ($user_id > 0) {
            if ($user_id === (int) get_current_user_id()) {
                $allowed = current_user_can('manage_options') || current_user_can('manage_woocommerce');
            } elseif (function_exists('user_can')) {
                $allowed = user_can($user_id, 'manage_options') || user_can($user_id, 'manage_woocommerce');
            }
        }

        /**
         * Filter medical visibility (e.g. role settings from site plugin).
         *
         * @param bool $allowed
         * @param int  $user_id
         */
        $filtered = apply_filters('intersoccer_can_view_medical', $allowed, $user_id);
        if ($filtered === true) {
            return true;
        }
        if ($filtered === false) {
            return false;
        }

        return (bool) $allowed;
    }
}

if (!function_exists('intersoccer_rr_medical_restricted_label')) {
    function intersoccer_rr_medical_restricted_label(): string {
        return (string) __('Restricted', 'intersoccer-reports-rosters');
    }
}

if (!function_exists('intersoccer_rr_medical_not_available_label')) {
    function intersoccer_rr_medical_not_available_label(): string {
        return (string) __('N/A', 'intersoccer-reports-rosters');
    }
}

if (!function_exists('intersoccer_rr_format_medical_display_value')) {
    /**
     * Value for a medical/dietary table cell (escaped by caller).
     *
     * @param mixed $raw Stored medical/dietary text.
     */
    function intersoccer_rr_format_medical_display_value($raw): string {
        if (!intersoccer_rr_current_user_can_view_medical()) {
            return intersoccer_rr_medical_restricted_label();
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return intersoccer_rr_medical_not_available_label();
        }

        return $text;
    }
}

if (!function_exists('intersoccer_rr_camp_medical_cell')) {
    /**
     * Camp coach view cell: none vs text vs restricted.
     *
     * @param mixed $raw Stored medical text.
     * @return array{state:string,text:string}
     */
    function intersoccer_rr_camp_medical_cell($raw): array {
        if (!intersoccer_rr_current_user_can_view_medical()) {
            return [
                'state' => 'restricted',
                'text'  => intersoccer_rr_medical_restricted_label(),
            ];
        }

        $text = trim((string) $raw);
        if ($text === '' || strtolower($text) === 'none') {
            return [
                'state' => 'none',
                'text'  => '',
            ];
        }

        return [
            'state' => 'text',
            'text'  => $text,
        ];
    }
}

if (!function_exists('intersoccer_rr_redact_medical_in_roster_row')) {
    /**
     * Strip medical fields from a roster row for denied users (arrays).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    function intersoccer_rr_redact_medical_in_roster_row(array $row): array {
        if (intersoccer_rr_current_user_can_view_medical()) {
            return $row;
        }

        $restricted = intersoccer_rr_medical_restricted_label();
        foreach (intersoccer_rr_medical_roster_field_keys() as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $restricted;
            }
        }
        foreach (intersoccer_rr_medical_dob_field_keys() as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $restricted;
            }
        }

        return $row;
    }
}

if (!function_exists('intersoccer_rr_strip_medical_from_update_data')) {
    /**
     * Prevent denied users from overwriting stored medical data on save.
     *
     * @param array<string,mixed> $update_data Fields slated for DB update.
     * @return array<string,mixed>
     */
    function intersoccer_rr_strip_medical_from_update_data(array $update_data): array {
        if (intersoccer_rr_current_user_can_view_medical()) {
            return $update_data;
        }

        foreach (intersoccer_rr_medical_roster_field_keys() as $key) {
            unset($update_data[$key]);
        }

        return $update_data;
    }
}
