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

if (!function_exists('intersoccer_rr_sensitive_export_field_keys')) {
    /**
     * Roster export row keys removed entirely when medical access is denied.
     *
     * @return string[]
     */
    function intersoccer_rr_sensitive_export_field_keys(): array {
        return array_values(array_unique(array_merge(
            intersoccer_rr_medical_roster_field_keys(),
            intersoccer_rr_medical_dob_field_keys(),
            [
                'medical_conditions',
                'dietary_needs',
                'player_dietary',
                'player_medical',
                'allergy',
                'allergies',
                'food_allergies',
                'dietary_requirements',
            ]
        )));
    }
}

if (!function_exists('intersoccer_rr_strip_sensitive_fields_from_export_row')) {
    /**
     * Remove sensitive columns from one export row (export-all CSV uses array_keys).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    function intersoccer_rr_strip_sensitive_fields_from_export_row(array $row): array {
        if (intersoccer_rr_current_user_can_view_medical()) {
            return $row;
        }

        foreach (intersoccer_rr_sensitive_export_field_keys() as $key) {
            unset($row[$key]);
        }

        return $row;
    }
}

if (!function_exists('intersoccer_rr_strip_sensitive_fields_from_export_rows')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_rr_strip_sensitive_fields_from_export_rows(array $rows): array {
        return array_map(
            static function ($row) {
                return is_array($row) ? intersoccer_rr_strip_sensitive_fields_from_export_row($row) : $row;
            },
            $rows
        );
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
        foreach (intersoccer_rr_medical_dob_field_keys() as $key) {
            unset($update_data[$key]);
        }

        return $update_data;
    }
}

if (!function_exists('intersoccer_rr_roster_export_personal_headers')) {
    /**
     * Spreadsheet personal columns (aligned with export row builder).
     *
     * @return string[]
     */
    function intersoccer_rr_roster_export_personal_headers(): array {
        $headers = [
            'First Name',
            'Surname',
            'Gender',
            'Phone',
            'Email',
            'Age',
        ];
        if (intersoccer_rr_current_user_can_view_medical()) {
            $headers[] = 'Date of Birth';
            $headers[] = 'Medical/Dietary Conditions';
        }
        $headers[] = 'AVS Number';

        return $headers;
    }
}

if (!function_exists('intersoccer_rr_roster_export_personal_row_values')) {
    /**
     * Personal column values for one export row (no camp/course tail).
     *
     * @param array<string,mixed> $player
     */
    function intersoccer_rr_roster_export_personal_row_values(
        array $player,
        string $processed_phone,
        string $formatted_birth_date,
        string $export_medical
    ): array {
        $export_first = function_exists('intersoccer_roster_field_player_first_name')
            ? intersoccer_roster_field_player_first_name($player)
            : ($player['first_name'] ?? '');
        $export_last = function_exists('intersoccer_roster_field_player_last_name')
            ? intersoccer_roster_field_player_last_name($player)
            : ($player['last_name'] ?? '');
        $export_gender = function_exists('intersoccer_roster_field_player_gender')
            ? intersoccer_roster_field_player_gender($player)
            : ($player['gender'] ?? '');
        $avs_export_value = isset($player['avs_number']) ? (string) $player['avs_number'] : 'N/A';

        $row = [
            $export_first !== '' ? $export_first : 'N/A',
            $export_last !== '' ? $export_last : 'N/A',
            $export_gender !== '' ? $export_gender : 'N/A',
            $processed_phone,
            $player['parent_email'] ?? 'N/A',
            $player['age'] ?? 'N/A',
        ];
        if (intersoccer_rr_current_user_can_view_medical()) {
            $row[] = $formatted_birth_date;
            $row[] = $export_medical !== '' ? $export_medical : 'N/A';
        }
        $row[] = $avs_export_value;

        return $row;
    }
}
