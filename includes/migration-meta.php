<?php
/**
 * Shared order-item metadata aliases for migration and language repair.
 *
 * @package InterSoccer\ReportsRosters
 */

defined('ABSPATH') or die('Restricted access');

require_once dirname(__FILE__) . '/roster-move-item-renormalize-suppress.php';

if (!function_exists('intersoccer_migration_human_alias_map')) {
    /**
     * Map canonical metadata identifiers to their human-readable key variants.
     *
     * Delegates to the PV attribute registry via the bridge when available;
     * falls back to a static map otherwise.
     *
     * @return array<string,array<int,string>>
     */
    function intersoccer_migration_human_alias_map() {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $internal_to_slug = [
            'intersoccer_venues' => 'intersoccer-venues',
            'age_group'          => 'age-group',
            'camp_terms'         => 'camp-terms',
            'course_day'         => 'course-day',
            'course_times'       => 'course-times',
            'camp_times'         => 'camp-times',
            'activity_type'      => 'activity-type',
            'season'             => 'program-season',
            'booking_type'       => 'booking-type',
            'selected_days'      => null,
            'canton_region'      => 'canton-region',
            'city'               => 'city',
        ];

        if (!function_exists('intersoccer_reports_attr_legacy_order_meta_labels')
            || !function_exists('intersoccer_reports_attr_order_meta_label')) {
            $contract = dirname(__FILE__) . '/attribute-contract.php';
            if (is_readable($contract)) {
                require_once $contract;
            }
        }

        $can_bridge = function_exists('intersoccer_reports_attr_legacy_order_meta_labels')
            && function_exists('intersoccer_reports_attr_order_meta_label');

        $map = [];
        foreach ($internal_to_slug as $internal => $slug) {
            if ($slug !== null && $can_bridge) {
                $canonical_label = intersoccer_reports_attr_order_meta_label($slug);
                $legacy = intersoccer_reports_attr_legacy_order_meta_labels($slug);
                if ($canonical_label !== '') {
                    $map[$internal] = array_merge([$canonical_label], $legacy);
                    continue;
                }
            }

            $map[$internal] = intersoccer_migration_human_alias_map_static_fallback($internal);
        }

        foreach (['girls_only', 'program_year'] as $canonical_extra) {
            $aliases = intersoccer_migration_human_alias_map_static_fallback($canonical_extra);
            if ($aliases === []) {
                continue;
            }
            $map[$canonical_extra] = array_values(array_unique(array_merge(
                $map[$canonical_extra] ?? [],
                $aliases
            )));
        }

        return $map;
    }
}

if (!function_exists('intersoccer_migration_human_alias_map_static_fallback')) {
    /**
     * @param string $internal
     * @return array<int,string>
     */
    function intersoccer_migration_human_alias_map_static_fallback($internal) {
        static $fallback = [
            'intersoccer_venues' => ['Sites InterSoccer', 'InterSoccer Venues', 'Lieux InterSoccer'],
            'age_group'          => ['Age Group', "Groupe d'âge", 'Groupe dage'],
            'camp_terms'         => ['Camp Terms', 'Conditions du camp'],
            'course_day'         => ['Course Day', 'Jour de cours'],
            'course_times'       => ['Course Times', 'Horaires du cours'],
            'camp_times'         => ['Camp Times', 'Horaires du camp'],
            'activity_type'      => ['Activity Type', "Type d'activité"],
            'season'             => ['Season', 'Saison'],
            'booking_type'       => ['Booking Type', 'Type de réservation', 'Buchungstyp'],
            'selected_days'      => ['Days Selected', 'Jours sélectionnés', 'Ausgewählte Tage'],
            'canton_region'      => ['Canton / Region', 'Canton / Région'],
            'city'               => ['City', 'Ville'],
            'program_year'       => ['Year'],
            'girls_only'         => ['Girls Only', 'girls_only'],
        ];

        return $fallback[$internal] ?? [];
    }
}

if (!function_exists('intersoccer_migration_pick_meta_label_for_canonical')) {
    /**
     * Prefer the human-meta key shape already stored on the line (e.g. girls_only vs Girls Only).
     *
     * @param array<int,string> $existing_line_keys
     */
    function intersoccer_migration_pick_meta_label_for_canonical(
        string $canonical,
        array $human_alias_map,
        array $existing_line_keys
    ): string {
        $aliases = $human_alias_map[$canonical] ?? [$canonical];
        $flip = array_fill_keys($existing_line_keys, true);
        foreach ($aliases as $alias) {
            if (isset($flip[$alias])) {
                return $alias;
            }
        }
        if (isset($flip[$canonical])) {
            return $canonical;
        }

        return $aliases[0] ?? $canonical;
    }
}

if (!function_exists('intersoccer_migration_normalize_meta_key')) {
    /**
     * Normalize a metadata key for comparison (lowercase, remove accents/punctuation).
     */
    function intersoccer_migration_normalize_meta_key($key) {
        if (function_exists('intersoccer_normalize_meta_key_for_lookup')) {
            return intersoccer_normalize_meta_key_for_lookup($key);
        }

        $normalized = strtolower(remove_accents((string) $key));
        $normalized = str_replace(['attribute_', '_'], ['', '-'], $normalized);
        $normalized = preg_replace('/[^a-z0-9\- ]+/u', '', $normalized);
        $normalized = preg_replace('/\s+/', '-', trim($normalized));
        return $normalized;
    }
}

if (!function_exists('intersoccer_migration_build_lookup')) {
    /**
     * Build a lookup from normalized key to canonical identifier.
     *
     * @param array<string,array<int,string>> $alias_map
     * @return array<string,string>
     */
    function intersoccer_migration_build_lookup(array $alias_map) {
        $lookup = [];
        foreach ($alias_map as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[intersoccer_migration_normalize_meta_key($alias)] = $canonical;
            }
            $lookup[intersoccer_migration_normalize_meta_key($canonical)] = $canonical;
        }
        return $lookup;
    }
}

if (!function_exists('intersoccer_migration_canonical_to_facet_key')) {
    /**
     * Map canonical order-meta keys to roster facet field names.
     *
     * @return array<string,string>
     */
    function intersoccer_migration_canonical_to_facet_key() {
        return [
            'intersoccer_venues' => 'venue',
            'age_group'          => 'age_group',
            'camp_terms'         => 'camp_terms',
            'course_day'         => 'course_day',
            'course_times'       => 'times',
            'camp_times'         => 'times',
            'activity_type'      => 'activity_type',
            'season'             => 'season',
            'program_year'       => 'program_year',
            'booking_type'       => 'booking_type',
            'selected_days'      => 'selected_days',
            'canton_region'      => 'canton_region',
            'city'               => 'city',
        ];
    }
}

if (!function_exists('intersoccer_migration_english_meta_label')) {
    /**
     * English WooCommerce order item meta label for a canonical key.
     *
     * @param string $canonical
     * @return string
     */
    function intersoccer_migration_english_meta_label($canonical) {
        $aliases = intersoccer_migration_human_alias_map();
        if (isset($aliases[$canonical][0])) {
            return $aliases[$canonical][0];
        }
        return ucwords(str_replace('_', ' ', $canonical));
    }
}

if (!function_exists('intersoccer_migration_cleanup_item_meta')) {
    /**
     * Remove duplicate human-readable metadata entries, keeping the most recently written value.
     *
     * @param int $item_id
     * @param array<string,array<int,string>> $alias_map
     * @param array<string,string> $lookup
     */
    function intersoccer_migration_cleanup_item_meta(
        $item_id,
        array $alias_map,
        array $lookup,
        array $existing_line_keys = []
    ) {
        $item = new WC_Order_Item_Product($item_id);
        $meta_data = array_reverse($item->get_meta_data());
        $existing_flip = array_fill_keys($existing_line_keys, true);
        $seen_key = [];
        $canonical_keys_kept = [];

        foreach ($meta_data as $meta) {
            $key = (string) $meta->key;
            $normalized = intersoccer_migration_normalize_meta_key($key);
            if (!isset($lookup[$normalized])) {
                continue;
            }
            $canonical = $lookup[$normalized];

            if (isset($seen_key[$key])) {
                wc_delete_order_item_meta($item_id, $key, $meta->value);
                continue;
            }
            $seen_key[$key] = true;

            $keep = $existing_line_keys === []
                || isset($existing_flip[$key])
                || !isset($canonical_keys_kept[$canonical])
                || $canonical_keys_kept[$canonical] === [];
            if ($keep) {
                $canonical_keys_kept[$canonical][$key] = true;
                continue;
            }

            wc_delete_order_item_meta($item_id, $key, $meta->value);
        }
    }
}

if (!function_exists('intersoccer_migration_prune_meta_rows')) {
    /**
     * Ensure only one row per canonical field remains at the database level.
     */
    function intersoccer_migration_prune_meta_rows(
        $item_id,
        array $alias_map,
        array $lookup,
        array $existing_line_keys = []
    ) {
        global $wpdb;

        $canonical_to_keys = [];
        foreach ($alias_map as $canonical => $aliases) {
            $canonical_to_keys[$canonical] = array_unique(array_merge([$canonical], $aliases));
        }

        $existing_flip = array_fill_keys($existing_line_keys, true);
        $table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        foreach ($canonical_to_keys as $canonical => $keys) {
            $placeholders = implode(',', array_fill(0, count($keys), '%s'));
            $params = array_merge([$item_id], $keys);

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, meta_key, meta_value FROM {$table} WHERE order_item_id = %d AND meta_key IN ($placeholders) ORDER BY meta_id ASC",
                $params
            )) ?: [];

            if ($existing_line_keys !== []) {
                $rows_by_key = [];
                foreach ($rows as $row) {
                    $normalized = intersoccer_migration_normalize_meta_key($row->meta_key);
                    if ($normalized !== $canonical && (!isset($lookup[$normalized]) || $lookup[$normalized] !== $canonical)) {
                        continue;
                    }
                    $rows_by_key[$row->meta_key][] = $row;
                }
                foreach ($rows_by_key as $meta_key => $key_rows) {
                    if (!isset($existing_flip[$meta_key])) {
                        foreach ($key_rows as $row) {
                            $wpdb->delete($table, ['meta_id' => (int) $row->meta_id], ['%d']);
                        }
                        continue;
                    }
                    $keep = end($key_rows);
                    foreach ($key_rows as $row) {
                        if ((int) $row->meta_id === (int) $keep->meta_id) {
                            continue;
                        }
                        $wpdb->delete($table, ['meta_id' => (int) $row->meta_id], ['%d']);
                    }
                }
                continue;
            }

            $chosen_meta_id = null;
            $chosen_key = null;
            $chosen_value = null;
            foreach ($rows as $row) {
                $normalized = intersoccer_migration_normalize_meta_key($row->meta_key);
                if ($normalized !== $canonical && (!isset($lookup[$normalized]) || $lookup[$normalized] !== $canonical)) {
                    continue;
                }

                if ($chosen_meta_id === null) {
                    $chosen_meta_id = (int) $row->meta_id;
                    $chosen_value = $row->meta_value;
                    $chosen_key = $row->meta_key;
                } else {
                    $wpdb->delete($table, ['meta_id' => (int) $row->meta_id], ['%d']);
                }
            }

            if ($chosen_meta_id !== null) {
                $wpdb->update(
                    $table,
                    ['meta_key' => $chosen_key, 'meta_value' => $chosen_value],
                    ['meta_id' => $chosen_meta_id],
                    ['%s', '%s'],
                    ['%d']
                );
            }
        }
    }
}

if (!function_exists('intersoccer_migration_activity_type_for_girls_only_tristate')) {
    /**
     * Activity Type label from base activity text and unified girls-only resolution.
     */
    function intersoccer_migration_activity_type_for_girls_only_tristate(string $activity_type, string $girls_only_tristate): string {
        if ($girls_only_tristate !== 'yes') {
            return function_exists('intersoccer_canonical_activity_type_for_roster')
                ? (string) intersoccer_canonical_activity_type_for_roster($activity_type)
                : trim($activity_type);
        }

        $base = trim($activity_type);
        if ($base !== '' && function_exists('intersoccer_text_indicates_girls_only')
            && intersoccer_text_indicates_girls_only($base)) {
            $stripped = preg_replace('/,?\s*girls[\s\'\-]*only/iu', '', $base);
            $base = trim((string) $stripped, ', ');
            if ($base === '') {
                $base = 'Course';
            }
        }

        return intersoccer_migration_activity_type_with_girls_only_flag($base, 1);
    }
}

if (!function_exists('intersoccer_migration_activity_type_with_girls_only_flag')) {
    /**
     * Canonical order-item Activity Type label when girls_only is set on the target variation.
     */
    function intersoccer_migration_activity_type_with_girls_only_flag(string $activity_type, int $girls_only_flag): string {
        $activity_type = trim($activity_type);
        if ($activity_type === '' || $girls_only_flag !== 1) {
            return function_exists('intersoccer_canonical_activity_type_for_roster')
                ? (string) intersoccer_canonical_activity_type_for_roster($activity_type)
                : $activity_type;
        }

        if (function_exists('intersoccer_text_indicates_girls_only')
            && intersoccer_text_indicates_girls_only($activity_type)) {
            return function_exists('intersoccer_canonical_activity_type_for_roster')
                ? (string) intersoccer_canonical_activity_type_for_roster($activity_type)
                : $activity_type;
        }

        $base = function_exists('intersoccer_canonical_activity_type_for_roster')
            ? (string) intersoccer_canonical_activity_type_for_roster($activity_type)
            : ucfirst(strtolower($activity_type));

        if ($base === 'Camp') {
            return 'Camp, Girls Only';
        }
        if ($base === 'Course') {
            return 'Course, Girls Only';
        }
        if ($base === 'Girls Only') {
            return 'Girls Only';
        }

        return $base . ', Girls Only';
    }
}

if (!function_exists('intersoccer_migration_is_player_attendee_meta_key')) {
    /**
     * Order-item keys that must survive a roster move (player / attendee, not variation facets).
     */
    function intersoccer_migration_is_player_attendee_meta_key(string $key): bool {
        if ($key === 'assigned_player' || $key === 'assigned_player_id') {
            return true;
        }

        if (!function_exists('intersoccer_get_order_meta_field_map')) {
            $path = dirname(__FILE__) . '/order-meta-keys.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }

        if (!function_exists('intersoccer_get_order_meta_field_map')) {
            return in_array($key, [
                'Assigned Attendee',
                'Player Index',
                'Attendee DOB',
                'Attendee Gender',
                'Medical Conditions',
            ], true);
        }

        $map = intersoccer_get_order_meta_field_map();
        if (!isset($map[$key])) {
            return false;
        }

        return in_array($map[$key], [
            'assigned_attendee',
            'player_index',
            'player_id',
            'attendee_dob',
            'attendee_gender',
            'medical_conditions',
            'dietary_needs',
        ], true);
    }
}

if (!function_exists('intersoccer_migration_is_variation_rewrite_meta_key')) {
    /**
     * Whether this order-item meta key should be removed and rewritten from the target variation.
     *
     * @param array<string,string> $human_lookup
     */
    function intersoccer_migration_is_variation_rewrite_meta_key(string $key, array $human_lookup): bool {
        if ($key === '') {
            return false;
        }

        if (intersoccer_migration_is_player_attendee_meta_key($key)) {
            return false;
        }

        if (function_exists('intersoccer_order_item_meta_key_is_canonical')
            && intersoccer_order_item_meta_key_is_canonical($key)) {
            return true;
        }

        if (preg_match('/^(attribute_)?pa_/i', $key)) {
            return true;
        }

        $normalized = intersoccer_migration_normalize_meta_key($key);
        if (isset($human_lookup[$normalized])) {
            return true;
        }

        static $extra_variation_labels = [
            'variation id',
            'start date',
            'end date',
            'holidays',
            'year',
            'note',
            'girls only',
            'girls-only',
            'discount',
            'discount amount',
            'base price',
            'remaining sessions',
            'late pickup type',
            'late pickup days',
            'late pickup cost',
            'camp start date',
            'camp end date',
            'camp week index',
        ];

        return in_array($normalized, $extra_variation_labels, true);
    }
}

if (!function_exists('intersoccer_migration_snapshot_player_attendee_meta')) {
    /**
     * @param \WC_Order_Item_Product $item
     * @return array<string,mixed>
     */
    function intersoccer_migration_snapshot_player_attendee_meta($item): array {
        $snapshot = [];
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $key = (string) ($data['key'] ?? '');
            if ($key === '' || !intersoccer_migration_is_player_attendee_meta_key($key)) {
                continue;
            }
            $snapshot[$key] = $data['value'] ?? '';
        }

        return $snapshot;
    }
}

if (!function_exists('intersoccer_migration_apply_player_attendee_meta_snapshot')) {
    /**
     * @param int $item_id
     * @param array<string,mixed> $snapshot
     */
    function intersoccer_migration_apply_player_attendee_meta_snapshot($item_id, array $snapshot): void {
        if ($item_id <= 0 || $snapshot === []) {
            return;
        }

        $item = new WC_Order_Item_Product($item_id);
        foreach ($snapshot as $key => $value) {
            $item->update_meta_data((string) $key, $value);
        }
        $item->save();
    }
}

if (!function_exists('intersoccer_migration_resolve_destination_canonical_girls_only_for_move')) {
    /**
     * Destination roster girls-only flag for order-line canonical meta (0|1 as string).
     *
     * Uses the same variation resolver as roster move writes. When the destination is unknown,
     * returns null so callers keep the line's existing value.
     */
    function intersoccer_migration_resolve_destination_canonical_girls_only_for_move(
        WC_Product_Variation $variation,
        ?string $existing_value = null
    ): ?string {
        if (!function_exists('intersoccer_roster_move_resolve_variation_girls_only_tristate')) {
            return null;
        }

        $tristate = intersoccer_roster_move_resolve_variation_girls_only_tristate($variation);
        if ($tristate === 'unknown') {
            if ($existing_value !== null && $existing_value !== '') {
                return (string) $existing_value;
            }

            return null;
        }

        $flag = function_exists('intersoccer_roster_move_resolve_variation_girls_only_flag')
            ? intersoccer_roster_move_resolve_variation_girls_only_flag($variation)
            : ($tristate === 'yes' ? 1 : 0);

        return (string) (int) $flag;
    }
}

if (!function_exists('intersoccer_migration_apply_destination_canonical_girls_only_for_move')) {
    /**
     * Set a single `_intersoccer_canonical_girls_only` row from the destination variation roster.
     */
    function intersoccer_migration_apply_destination_canonical_girls_only_for_move(
        int $item_id,
        WC_Product_Variation $variation,
        ?string $existing_value = null
    ): void {
        if ($item_id <= 0) {
            return;
        }

        $resolved = intersoccer_migration_resolve_destination_canonical_girls_only_for_move(
            $variation,
            $existing_value
        );
        if ($resolved === null) {
            return;
        }

        $item = new WC_Order_Item_Product($item_id);
        $item->delete_meta_data('_intersoccer_canonical_girls_only');
        $item->update_meta_data('_intersoccer_canonical_girls_only', $resolved);
        $item->save();
    }
}

if (!function_exists('intersoccer_migration_canonical_order_meta_key_to_pa_taxonomy')) {
    /**
     * Map `_intersoccer_canonical_*` keys to WooCommerce pa_* taxonomies (EN slugs on the line).
     *
     * @return array<string,string>
     */
    function intersoccer_migration_canonical_order_meta_key_to_pa_taxonomy(): array {
        return [
            '_intersoccer_canonical_activity_type' => 'pa_activity-type',
            '_intersoccer_canonical_booking_type' => 'pa_booking-type',
            '_intersoccer_canonical_venue' => 'pa_intersoccer-venues',
            '_intersoccer_canonical_canton' => 'pa_canton-region',
            '_intersoccer_canonical_age_group' => 'pa_age-group',
            '_intersoccer_canonical_camp_terms' => 'pa_camp-terms',
        ];
    }
}

if (!function_exists('intersoccer_migration_slug_meta_pa_value')) {
    /**
     * Read a pa_* slug from migration slug_meta (attribute_pa_* or bare pa_*).
     */
    function intersoccer_migration_slug_meta_pa_value(array $slug_meta, string $pa_taxonomy): string {
        if ($pa_taxonomy === '') {
            return '';
        }
        foreach (['attribute_' . $pa_taxonomy, $pa_taxonomy] as $key) {
            if (!empty($slug_meta[$key])) {
                return trim((string) $slug_meta[$key]);
            }
        }

        return '';
    }
}

if (!function_exists('intersoccer_migration_resolve_destination_canonical_order_meta_for_move')) {
    /**
     * Destination value for one existing `_intersoccer_canonical_*` row (null = leave unchanged).
     *
     * @param array<string,string> $slug_meta
     */
    function intersoccer_migration_resolve_destination_canonical_order_meta_for_move(
        string $meta_key,
        WC_Product_Variation $variation,
        array $slug_meta,
        ?string $existing_value = null
    ): ?string {
        if ($meta_key === '_intersoccer_canonical_girls_only') {
            return intersoccer_migration_resolve_destination_canonical_girls_only_for_move(
                $variation,
                $existing_value
            );
        }

        $pa_map = intersoccer_migration_canonical_order_meta_key_to_pa_taxonomy();
        if (!isset($pa_map[$meta_key])) {
            return null;
        }

        $pa_taxonomy = $pa_map[$meta_key];
        $raw_slug = '';
        if (function_exists('intersoccer_migration_pa_attribute_slug_for_variation')) {
            $raw_slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, $pa_taxonomy, false);
        }
        $slug = $raw_slug;
        if ($slug === '') {
            $slug = intersoccer_migration_slug_meta_pa_value($slug_meta, $pa_taxonomy);
        }
        if ($slug !== '' && function_exists('intersoccer_migration_default_language_term_slug_strict')) {
            $strict_input = $raw_slug !== '' ? $raw_slug : $slug;
            $resolved_slug = intersoccer_migration_default_language_term_slug_strict($pa_taxonomy, $strict_input);
            if ($resolved_slug === null) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(
                        'InterSoccer move: destination taxonomy value did not resolve to a term; keeping existing line value'
                    );
                }

                return null;
            }
            $slug = $resolved_slug;
        }

        if ($meta_key === '_intersoccer_canonical_activity_type' && $slug === '') {
            $product_type = '';
            if (function_exists('intersoccer_get_product_type_safe')) {
                $product_type = (string) intersoccer_get_product_type_safe(
                    (int) $variation->get_parent_id(),
                    (int) $variation->get_id()
                );
            } elseif (function_exists('intersoccer_get_product_type')) {
                $product_type = (string) intersoccer_get_product_type((int) $variation->get_parent_id());
            }
            $product_type = trim(strtolower($product_type));
            if ($product_type !== '') {
                $slug = $product_type;
            }
        }

        if ($slug === '') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    'InterSoccer move: destination variation has no slug for canonical order meta key; keeping existing line value'
                );
            }

            return null;
        }

        if ($meta_key === '_intersoccer_canonical_activity_type'
            && function_exists('intersoccer_canonical_activity_type_for_roster')) {
            return strtolower((string) intersoccer_canonical_activity_type_for_roster($slug));
        }

        if ($meta_key === '_intersoccer_canonical_booking_type'
            && function_exists('intersoccer_normalize_booking_type_slug_for_reports')) {
            $normalized_slug = intersoccer_normalize_booking_type_slug_for_reports($slug);
            if ($normalized_slug !== '' && $normalized_slug !== 'other') {
                return $normalized_slug;
            }
        }

        return $slug;
    }
}

if (!function_exists('intersoccer_migration_supplement_roster_normalized_from_variation')) {
    /**
     * Fill blank roster facet columns from the destination variation (EN canonical storage shape).
     *
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $record
     */
    function intersoccer_migration_supplement_roster_normalized_from_variation(
        array $normalized,
        array $record,
        WC_Product_Variation $variation
    ): array {
        if (!function_exists('intersoccer_roster_facet_value_or_record')
            || !function_exists('intersoccer_migration_pa_attribute_slug_for_variation')) {
            return $normalized;
        }

        $facet_taxonomies = [
            'venue'         => 'pa_intersoccer-venues',
            'age_group'     => 'pa_age-group',
            'camp_terms'    => 'pa_camp-terms',
            'course_day'    => 'pa_course-day',
            'times'         => 'pa_camp-times',
            'city'          => 'pa_city',
            'canton_region' => 'pa_canton-region',
        ];

        foreach ($facet_taxonomies as $facet => $taxonomy) {
            if (intersoccer_roster_facet_value_or_record($normalized, $record, $facet) !== '') {
                continue;
            }

            $raw_slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, $taxonomy, false);
            if ($raw_slug === '') {
                if ($facet === 'times') {
                    $raw_slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, 'pa_course-times', false);
                    if ($raw_slug !== '') {
                        $taxonomy = 'pa_course-times';
                    }
                }
                if ($raw_slug === '') {
                    continue;
                }
            }

            $en_slug = null;
            if (function_exists('intersoccer_migration_default_language_term_slug_strict')) {
                $en_slug = intersoccer_migration_default_language_term_slug_strict($taxonomy, $raw_slug);
            }
            if ($en_slug === null || $en_slug === '') {
                continue;
            }

            if (function_exists('intersoccer_get_term_name')) {
                $name = intersoccer_get_term_name($en_slug, $taxonomy);
                if ($name !== '' && $name !== 'N/A') {
                    $normalized[$facet] = $name;
                    continue;
                }
            }

            $normalized[$facet] = $en_slug;
        }

        return $normalized;
    }
}

if (!function_exists('intersoccer_migration_roster_booking_from_destination_variation')) {
    /**
     * When order item meta has no booking keys, derive roster booking_type from the destination variation.
     *
     * @param array<string,mixed> $booking
     * @param array<string,mixed> $record
     */
    function intersoccer_migration_roster_booking_from_destination_variation(
        array $booking,
        array $record,
        WC_Product_Variation $variation
    ): array {
        $current = trim((string) ($booking['booking_type'] ?? ''));
        if ($current !== '' && strcasecmp($current, 'Unknown') !== 0) {
            return $booking;
        }

        if (!function_exists('intersoccer_migration_pa_attribute_slug_for_variation')) {
            return $booking;
        }

        $raw_slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, 'pa_booking-type', false);
        if ($raw_slug === '') {
            return $booking;
        }

        $en_slug = '';
        if (function_exists('intersoccer_migration_default_language_term_slug_strict')) {
            $resolved = intersoccer_migration_default_language_term_slug_strict('pa_booking-type', $raw_slug);
            if ($resolved !== null) {
                $en_slug = $resolved;
            }
        }
        if ($en_slug === '') {
            return $booking;
        }

        if (function_exists('intersoccer_normalize_booking_type_slug_for_reports')) {
            $en_slug = intersoccer_normalize_booking_type_slug_for_reports($en_slug);
        }

        static $slug_labels = [
            'full-week'   => 'Full Week',
            'single-days' => 'Single Day(s)',
            'full-term'   => 'Full Term',
        ];
        if (isset($slug_labels[$en_slug])) {
            $booking['booking_type'] = $slug_labels[$en_slug];
        } elseif (function_exists('intersoccer_normalize_booking_type_for_storage')) {
            $booking['booking_type'] = intersoccer_normalize_booking_type_for_storage($en_slug);
        }

        return $booking;
    }
}

if (!function_exists('intersoccer_migration_apply_destination_canonical_order_meta_for_move')) {
    /**
     * Update existing `_intersoccer_canonical_*` rows from the destination variation; never add keys.
     *
     * @param array<int,string>       $existing_line_keys
     * @param array<string,string>    $slug_meta
     * @param array<string,string>    $prior_canonical_order_meta Values captured before strip (optional).
     */
    function intersoccer_migration_apply_destination_canonical_order_meta_for_move(
        int $item_id,
        WC_Product_Variation $variation,
        array $existing_line_keys,
        array $slug_meta,
        array $prior_canonical_order_meta = []
    ): void {
        if ($item_id <= 0 || ($existing_line_keys === [] && $prior_canonical_order_meta === [])) {
            return;
        }

        if (!function_exists('intersoccer_get_canonical_order_meta_field_map')) {
            $path = dirname(__FILE__) . '/order-meta-keys.php';
            if (is_readable($path)) {
                require_once $path;
            }
        }
        if (!function_exists('intersoccer_get_canonical_order_meta_field_map')) {
            return;
        }

        $existing_flip = array_fill_keys(
            array_unique(array_merge($existing_line_keys, array_keys($prior_canonical_order_meta))),
            true
        );
        $item = new WC_Order_Item_Product($item_id);
        $meta_changed = false;

        foreach (intersoccer_get_canonical_order_meta_field_map() as $meta_key => $_field) {
            if (!isset($existing_flip[$meta_key])) {
                continue;
            }

            $existing_value = $prior_canonical_order_meta[$meta_key] ?? null;
            if ($existing_value === null || $existing_value === '') {
                $existing_value = (string) $item->get_meta($meta_key, true);
                if ($existing_value === '') {
                    $existing_value = null;
                }
            }

            $resolved = intersoccer_migration_resolve_destination_canonical_order_meta_for_move(
                (string) $meta_key,
                $variation,
                $slug_meta,
                $existing_value
            );
            if ($resolved === null) {
                continue;
            }

            $current = (string) $item->get_meta($meta_key, true);
            if ($current !== (string) $resolved) {
                $meta_changed = true;
            }
            $item->delete_meta_data($meta_key);
            $item->update_meta_data($meta_key, $resolved);
        }

        if ($meta_changed) {
            $item->save();
        }
    }
}

if (!function_exists('intersoccer_migration_pa_taxonomy_keys_for_move')) {
    /**
     * @return array<int,string>
     */
    function intersoccer_migration_pa_taxonomy_keys_for_move(): array {
        return [
            'pa_intersoccer-venues',
            'pa_age-group',
            'pa_camp-terms',
            'pa_course-day',
            'pa_course-times',
            'pa_camp-times',
            'pa_activity-type',
            'pa_program-season',
            'pa_booking-type',
            'pa_canton-region',
            'pa_city',
            'pa_girls-only',
        ];
    }
}

if (!function_exists('intersoccer_migration_term_id_in_default_language')) {
    /**
     * Map a taxonomy term ID to the WPML default-language (English) term ID.
     */
    function intersoccer_migration_term_id_in_default_language(int $term_id, string $taxonomy): int {
        if ($term_id <= 0 || $taxonomy === '' || !function_exists('apply_filters')) {
            return $term_id;
        }

        $default_lang = apply_filters('wpml_default_language', null);
        if (empty($default_lang)) {
            return $term_id;
        }

        $mapped = (int) apply_filters('wpml_object_id', $term_id, $taxonomy, true, $default_lang);

        return $mapped > 0 ? $mapped : $term_id;
    }
}

if (!function_exists('intersoccer_migration_resolve_default_language_term_slug_from_lookup')) {
    /**
     * Map a taxonomy slug or label to the default-language term slug when a term exists.
     *
     * @return string|null EN slug when resolved; null when no matching term.
     */
    function intersoccer_migration_resolve_default_language_term_slug_from_lookup(string $taxonomy, string $value): ?string {
        $value = trim($value);
        if ($value === '' || $taxonomy === '') {
            return null;
        }

        $term = null;
        if (function_exists('intersoccer_get_term_in_default_language')) {
            $term = intersoccer_get_term_in_default_language($value, $taxonomy);
        }
        if ((!$term || is_wp_error($term)) && function_exists('get_term_by')) {
            $term = get_term_by('slug', $value, $taxonomy);
            if (!$term || is_wp_error($term)) {
                $term = get_term_by('name', $value, $taxonomy);
            }
        }

        if (!$term || is_wp_error($term)) {
            return null;
        }

        $en_id = intersoccer_migration_term_id_in_default_language((int) $term->term_id, $taxonomy);
        $en_term = function_exists('get_term') ? get_term($en_id, $taxonomy) : $term;
        if ($en_term && !is_wp_error($en_term) && !empty($en_term->slug)) {
            return (string) $en_term->slug;
        }

        return (string) $term->slug;
    }
}

if (!function_exists('intersoccer_migration_default_language_term_slug_strict')) {
    /**
     * Resolve to default-language slug only when a taxonomy term matches (no label/slug invention).
     */
    function intersoccer_migration_default_language_term_slug_strict(string $taxonomy, string $value): ?string {
        $value = trim($value);
        if ($value === '' || $taxonomy === '') {
            return null;
        }

        if ($taxonomy === 'pa_girls-only') {
            $coerced = intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $value);
            if ($coerced === '') {
                return null;
            }

            return $coerced;
        }

        $resolved = intersoccer_migration_resolve_default_language_term_slug_from_lookup($taxonomy, $value);
        if ($resolved !== null) {
            return $resolved;
        }

        return null;
    }
}

if (!function_exists('intersoccer_migration_default_language_term_slug')) {
    /**
     * Resolve a taxonomy value (slug or label) to the default-language term slug (EN on WPML sites).
     */
    function intersoccer_migration_default_language_term_slug(string $taxonomy, string $value): string {
        $value = trim($value);
        if ($value === '' || $taxonomy === '') {
            return '';
        }

        if ($taxonomy === 'pa_girls-only') {
            return intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $value);
        }

        $resolved = intersoccer_migration_resolve_default_language_term_slug_from_lookup($taxonomy, $value);
        if ($resolved !== null) {
            return $resolved;
        }

        if (strpos($value, ' ') !== false) {
            return function_exists('sanitize_title') ? sanitize_title($value) : strtolower(str_replace(' ', '-', $value));
        }

        return $value;
    }
}

if (!function_exists('intersoccer_migration_coerce_pa_taxonomy_slug')) {
    /**
     * Normalize a pa_* meta value to the WooCommerce term slug (not display name).
     */
    function intersoccer_migration_coerce_pa_taxonomy_slug(string $taxonomy, string $value): string {
        $value = trim((string) $value);
        if ($value === '' || $taxonomy === '') {
            return '';
        }

        if ($taxonomy === 'pa_girls-only') {
            $lower = strtolower($value);
            if (function_exists('intersoccer_pa_girls_only_slug_is_yes') && intersoccer_pa_girls_only_slug_is_yes($lower)) {
                return 'yes';
            }
            if (in_array($lower, ['no', 'mixed'], true)) {
                return 'no';
            }

            return $lower;
        }

        if (function_exists('taxonomy_exists') && taxonomy_exists($taxonomy)) {
            if (function_exists('intersoccer_migration_default_language_term_slug')) {
                $slug = intersoccer_migration_default_language_term_slug($taxonomy, $value);
                if ($slug !== '') {
                    return $slug;
                }
            }
            if (function_exists('intersoccer_get_cached_term_in_default_language')) {
                $term = intersoccer_get_cached_term_in_default_language($value, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $en_id = intersoccer_migration_term_id_in_default_language((int) $term->term_id, $taxonomy);
                    $en_term = get_term($en_id, $taxonomy);
                    if ($en_term && !is_wp_error($en_term)) {
                        return (string) $en_term->slug;
                    }

                    return (string) $term->slug;
                }
            }
            if (function_exists('intersoccer_get_term_slug_by_name')) {
                return (string) intersoccer_get_term_slug_by_name($value, $taxonomy);
            }
        }

        if (strpos($value, ' ') === false) {
            return $value;
        }

        return function_exists('sanitize_title') ? sanitize_title($value) : strtolower(str_replace(' ', '-', $value));
    }
}

if (!function_exists('intersoccer_migration_coerce_pa_taxonomy_slug_strict')) {
    /**
     * Normalize a pa_* value only when a real taxonomy term matches (no label/slug invention).
     */
    function intersoccer_migration_coerce_pa_taxonomy_slug_strict(string $taxonomy, string $value): ?string {
        if (function_exists('intersoccer_migration_default_language_term_slug_strict')) {
            return intersoccer_migration_default_language_term_slug_strict($taxonomy, $value);
        }

        return null;
    }
}

if (!function_exists('intersoccer_migration_pa_attribute_slug_for_variation')) {
    /**
     * Term slug WooCommerce stores for a variation attribute (variation attrs first, then parent).
     */
    function intersoccer_migration_pa_attribute_slug_for_variation(
        WC_Product_Variation $variation,
        string $taxonomy,
        bool $coerce = true
    ): string {
        $taxonomy = str_replace('attribute_', '', $taxonomy);

        $normalize = static function (string $tax, string $raw) use ($coerce): string {
            if (!$coerce || !function_exists('intersoccer_migration_coerce_pa_taxonomy_slug')) {
                return trim($raw);
            }

            return intersoccer_migration_coerce_pa_taxonomy_slug($tax, $raw);
        };

        foreach ($variation->get_variation_attributes() as $attribute_key => $attribute_value) {
            $clean_key = str_replace('attribute_', '', (string) $attribute_key);
            if ($clean_key !== $taxonomy) {
                continue;
            }
            $raw = is_array($attribute_value) ? (string) reset($attribute_value) : (string) $attribute_value;

            return $normalize($taxonomy, $raw);
        }

        if (method_exists($variation, 'get_attribute')) {
            $from_variation = $variation->get_attribute($taxonomy);
            if ($from_variation !== '' && $from_variation !== null) {
                return $normalize($taxonomy, (string) $from_variation);
            }
        }

        $parent_id = (int) $variation->get_parent_id();
        if ($parent_id > 0 && function_exists('wc_get_product')) {
            $parent = wc_get_product($parent_id);
            if ($parent && method_exists($parent, 'get_attribute')) {
                $from_parent = $parent->get_attribute($taxonomy);
                if ($from_parent !== '' && $from_parent !== null) {
                    return $normalize($taxonomy, (string) $from_parent);
                }
            }
        }

        return '';
    }
}

if (!function_exists('intersoccer_migration_pa_variation_attribute_stored_form')) {
    /**
     * Raw attribute value WooCommerce exposes for a variation (often display text).
     */
    function intersoccer_migration_pa_variation_attribute_stored_form(
        WC_Product_Variation $variation,
        string $taxonomy,
        string $target_slug
    ): string {
        $raw = '';
        foreach ($variation->get_variation_attributes() as $attribute_key => $attribute_value) {
            $clean_key = str_replace('attribute_', '', (string) $attribute_key);
            if ($clean_key !== $taxonomy) {
                continue;
            }
            $raw = is_array($attribute_value) ? (string) reset($attribute_value) : (string) $attribute_value;
            break;
        }
        $variation_display = '';
        if (is_object($variation) && is_callable([$variation, 'get_attribute'])) {
            $from_attr = $variation->get_attribute($taxonomy);
            if ($from_attr !== '' && $from_attr !== null) {
                $variation_display = (string) $from_attr;
                if ($raw === '') {
                    $raw = $variation_display;
                }
            }
        }
        $parent_display = '';
        if (method_exists($variation, 'get_parent_id') && function_exists('wc_get_product')) {
            $parent_id = (int) $variation->get_parent_id();
            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);
                if ($parent && method_exists($parent, 'get_attribute')) {
                    $from_parent = $parent->get_attribute($taxonomy);
                    if ($from_parent !== '' && $from_parent !== null) {
                        $parent_display = (string) $from_parent;
                        if ($raw === '') {
                            $raw = $parent_display;
                        }
                    }
                }
            }
        }
        if ($raw !== '' && $target_slug !== ''
            && intersoccer_migration_pa_stored_value_same_term_as_slug($taxonomy, $raw, $target_slug)) {
            $coerced_raw = intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $raw);
            if ($raw === $coerced_raw) {
                if ($variation_display !== ''
                    && $variation_display !== $raw
                    && intersoccer_migration_pa_stored_value_same_term_as_slug($taxonomy, $variation_display, $target_slug)) {
                    return $variation_display;
                }
                if ($parent_display !== ''
                    && $parent_display !== $raw
                    && intersoccer_migration_pa_stored_value_same_term_as_slug($taxonomy, $parent_display, $target_slug)) {
                    return $parent_display;
                }
            }

            return $raw;
        }

        if ($parent_display !== '' && $target_slug !== ''
            && intersoccer_migration_pa_stored_value_same_term_as_slug($taxonomy, $parent_display, $target_slug)) {
            return $parent_display;
        }

        return $target_slug;
    }
}

if (!function_exists('intersoccer_migration_pa_existing_line_value_for_key')) {
    /**
     * Raw bytes for one pa_* / attribute_pa_* key on the line (no paired-key fallback).
     *
     * @param array<string,string> $existing_line_pa
     */
    function intersoccer_migration_pa_existing_line_value_for_key(array $existing_line_pa, string $meta_key): string {
        if (!isset($existing_line_pa[$meta_key]) || $existing_line_pa[$meta_key] === '') {
            return '';
        }

        return (string) $existing_line_pa[$meta_key];
    }
}

if (!function_exists('intersoccer_migration_pa_line_value_uses_slug_form')) {
    /**
     * Whether the line already stores a taxonomy value as the term slug (not a display label).
     */
    function intersoccer_migration_pa_line_value_uses_slug_form(string $taxonomy, string $existing): bool {
        $existing = trim($existing);
        if ($existing === '') {
            return true;
        }
        $coerced = intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $existing);

        return $coerced !== '' && $coerced === $existing;
    }
}

if (!function_exists('intersoccer_migration_pa_target_term_display_for_variation')) {
    /**
     * Display label for the target term on this variation (fallback: WP term name).
     */
    function intersoccer_migration_pa_target_term_display_for_variation(
        string $taxonomy,
        WC_Product_Variation $variation,
        string $target_slug
    ): string {
        $target_slug = trim($target_slug);
        if ($target_slug === '') {
            return '';
        }

        $preferred = intersoccer_migration_pa_variation_attribute_stored_form($variation, $taxonomy, $target_slug);
        if ($preferred !== ''
            && intersoccer_migration_pa_stored_value_same_term_as_slug($taxonomy, $preferred, $target_slug)
            && !intersoccer_migration_pa_line_value_uses_slug_form($taxonomy, $preferred)) {
            return $preferred;
        }

        if (is_object($variation) && is_callable([$variation, 'get_attribute'])) {
            $from_attr = $variation->get_attribute($taxonomy);
            if ($from_attr !== '' && $from_attr !== null
                && intersoccer_migration_pa_stored_value_same_term_as_slug($taxonomy, (string) $from_attr, $target_slug)
                && !intersoccer_migration_pa_line_value_uses_slug_form($taxonomy, (string) $from_attr)) {
                return (string) $from_attr;
            }
        }

        if (function_exists('taxonomy_exists') && taxonomy_exists($taxonomy)) {
            if (function_exists('intersoccer_get_cached_term_in_default_language')) {
                $term = intersoccer_get_cached_term_in_default_language($target_slug, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    return (string) $term->name;
                }
            }
            $term = get_term_by('slug', $target_slug, $taxonomy);
            if ($term && !is_wp_error($term)) {
                return (string) $term->name;
            }
        }

        return $target_slug;
    }
}

if (!function_exists('intersoccer_migration_pa_value_when_term_changes')) {
    /**
     * When the target term differs from the line, pa_* keys always store the resolved term slug.
     */
    function intersoccer_migration_pa_value_when_term_changes(
        string $taxonomy,
        WC_Product_Variation $variation,
        string $slug,
        string $existing_on_key
    ): string {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        if (trim($existing_on_key) === '') {
            return $slug;
        }

        return $slug;
    }
}

if (!function_exists('intersoccer_migration_read_variation_camp_schedule_ymd')) {
    /**
     * @return array{start:string,end:string,week_index:string}
     */
    function intersoccer_migration_read_variation_camp_schedule_ymd(int $variation_id): array {
        $empty = ['start' => '', 'end' => '', 'week_index' => ''];
        if ($variation_id <= 0) {
            return $empty;
        }

        $start = '';
        $end = '';
        $week_index = '';

        if (function_exists('intersoccer_get_camp_schedule_meta')) {
            $meta = intersoccer_get_camp_schedule_meta($variation_id);
            if (is_array($meta)) {
                $start = trim((string) ($meta['start'] ?? ''));
                $end = trim((string) ($meta['end'] ?? ''));
                $week_raw = $meta['week'] ?? $meta['week_index'] ?? null;
                if ($week_raw !== '' && $week_raw !== null && is_numeric($week_raw)) {
                    $week_index = (string) (int) $week_raw;
                }
            }
        } else {
            $start = trim((string) get_post_meta($variation_id, '_camp_start_date', true));
            $end = trim((string) get_post_meta($variation_id, '_camp_end_date', true));
            $week_index = trim((string) get_post_meta($variation_id, '_camp_week_index', true));
        }

        if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = '';
        }
        if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = '';
        }
        if ($week_index !== '' && !is_numeric($week_index)) {
            $week_index = '';
        }

        return [
            'start'       => $start,
            'end'         => $end,
            'week_index'  => $week_index,
        ];
    }
}

if (!function_exists('intersoccer_migration_camp_schedule_other_meta_for_move')) {
    /**
     * Camp schedule values for order-item keys (Y-m-d / week index). Caller filters to existing line keys.
     *
     * @return array<string,string>
     */
    function intersoccer_migration_camp_schedule_other_meta_for_move(int $variation_id): array {
        $schedule = intersoccer_migration_read_variation_camp_schedule_ymd($variation_id);
        if ($schedule['start'] === '') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    'InterSoccer move: destination variation has no camp schedule meta; keeping existing line camp dates'
                );
            }

            return [];
        }

        $end = $schedule['end'] !== '' ? $schedule['end'] : $schedule['start'];
        $week = $schedule['week_index'];

        $values = [
            'start' => $schedule['start'],
            'end'   => $end,
            'week'  => $week,
        ];

        $key_groups = [
            'start' => ['Camp Start Date', 'Date de début du camp', 'Camp-Startdatum', '_camp_start_date'],
            'end'   => ['Camp End Date', 'Date de fin du camp', 'Camp-Enddatum', '_camp_end_date'],
            'week'  => ['Camp Week Index', '_camp_week_index'],
        ];

        if (function_exists('intersoccer_get_order_meta_manual_aliases')) {
            foreach (['Camp Start Date', 'Camp End Date', 'Camp Week Index'] as $canonical) {
                $field = $canonical === 'Camp Start Date' ? 'start' : ($canonical === 'Camp End Date' ? 'end' : 'week');
                foreach (intersoccer_get_order_meta_manual_aliases()[$canonical] ?? [] as $alias) {
                    $key_groups[$field][] = $alias;
                }
            }
        }

        $out = [];
        foreach ($key_groups as $field => $keys) {
            $val = $values[$field];
            if ($field === 'week' && $val === '') {
                continue;
            }
            foreach (array_unique($keys) as $meta_key) {
                if ($meta_key !== '') {
                    $out[$meta_key] = $val;
                }
            }
        }

        return $out;
    }
}

if (!function_exists('intersoccer_migration_roster_row_needs_blank_fill_from_variation')) {
    /**
     * Whether rebuild should load the line variation for blank-field fill (excludes canton_region).
     *
     * @param array<string,mixed> $roster_data
     */
    function intersoccer_migration_roster_row_needs_blank_fill_from_variation(array $roster_data): bool {
        if ((int) ($roster_data['variation_id'] ?? 0) <= 0) {
            return false;
        }

        $blank = static function ($value): bool {
            $value = trim((string) $value);

            return $value === '' || strcasecmp($value, 'N/A') === 0;
        };

        $activity = trim((string) ($roster_data['activity_type'] ?? ''));
        $is_camp = strcasecmp($activity, 'Camp') === 0
            || strcasecmp($activity, 'Girls Only') === 0
            || stripos($activity, 'Camp') !== false;

        $facet_keys = [
            'venue',
            'age_group',
            'camp_terms',
            'course_day',
            'times',
            'season',
            'city',
            'activity_type',
        ];
        foreach ($facet_keys as $facet) {
            if ($is_camp && $facet === 'course_day') {
                continue;
            }
            $current = (string) ($roster_data[$facet] ?? '');
            if ($facet === 'camp_terms' && $blank($current)) {
                $current = (string) ($roster_data['event_type'] ?? '');
            }
            if ($facet === 'times' && $blank($current)) {
                $current = (string) (($roster_data['camp_times'] ?? '') ?: ($roster_data['course_times'] ?? ''));
            }
            if ($blank($current)) {
                return true;
            }
        }

        $booking_type = trim((string) ($roster_data['booking_type'] ?? ''));

        return $booking_type === ''
            || strcasecmp($booking_type, 'Unknown') === 0
            || strcasecmp($booking_type, 'unknown') === 0;
    }
}

if (!function_exists('intersoccer_migration_apply_blank_roster_fill_from_variation')) {
    /**
     * Fill blank roster facets/booking from the line's variation (same rules as post-move roster write).
     *
     * @param array<string,mixed> $roster_data
     * @return array<string,mixed>
     */
    function intersoccer_migration_apply_blank_roster_fill_from_variation(
        array $roster_data,
        WC_Product_Variation $variation
    ): array {
        if (!function_exists('intersoccer_migration_supplement_roster_normalized_from_variation')) {
            return $roster_data;
        }

        $event_data = [
            'activity_type' => $roster_data['activity_type'] ?? '',
            'venue'         => $roster_data['venue'] ?? '',
            'age_group'     => $roster_data['age_group'] ?? '',
            'camp_terms'    => $roster_data['camp_terms'] ?? $roster_data['event_type'] ?? '',
            'course_day'    => $roster_data['course_day'] ?? '',
            'times'         => $roster_data['times']
                ?? $roster_data['camp_times']
                ?? $roster_data['course_times']
                ?? '',
            'season'        => $roster_data['season'] ?? '',
            'city'          => $roster_data['city'] ?? '',
            'canton_region' => $roster_data['canton_region'] ?? $roster_data['region'] ?? '',
            'girls_only'    => (int) ($roster_data['girls_only'] ?? 0),
            'product_id'    => (int) ($roster_data['product_id'] ?? 0),
        ];

        $normalized = function_exists('intersoccer_normalize_roster_facets_for_storage')
            ? intersoccer_normalize_roster_facets_for_storage($event_data)
            : $event_data;

        $normalized = intersoccer_migration_supplement_roster_normalized_from_variation(
            $normalized,
            $roster_data,
            $variation
        );

        $facet_to_row = [
            'venue'         => ['venue'],
            'age_group'     => ['age_group'],
            'camp_terms'    => ['camp_terms', 'event_type'],
            'course_day'    => ['course_day'],
            'times'         => ['times', 'camp_times', 'course_times'],
            'season'        => ['season'],
            'city'          => ['city'],
            'activity_type' => ['activity_type'],
        ];

        foreach ($facet_to_row as $facet => $row_keys) {
            $record_val = trim((string) ($roster_data[$facet] ?? ''));
            if ($record_val === '' && $facet === 'canton_region') {
                $record_val = trim((string) ($roster_data['region'] ?? ''));
            }
            if ($record_val !== '' && strcasecmp($record_val, 'N/A') !== 0) {
                continue;
            }
            $new_val = trim((string) ($normalized[$facet] ?? ''));
            if ($new_val === '') {
                continue;
            }
            foreach ($row_keys as $row_key) {
                $current = trim((string) ($roster_data[$row_key] ?? ''));
                if ($current === '' || strcasecmp($current, 'N/A') === 0) {
                    $roster_data[$row_key] = $new_val;
                }
            }
        }

        if (function_exists('intersoccer_migration_roster_booking_from_destination_variation')
            && function_exists('intersoccer_normalize_roster_booking_columns')) {
            $booking = intersoccer_normalize_roster_booking_columns($roster_data, [
                'booking_type'  => $roster_data['booking_type'] ?? '',
                'selected_days' => $roster_data['selected_days'] ?? '',
            ]);
            $booking = intersoccer_migration_roster_booking_from_destination_variation(
                $booking,
                $roster_data,
                $variation
            );
            $current_type = trim((string) ($roster_data['booking_type'] ?? ''));
            if ($current_type === ''
                || strcasecmp($current_type, 'Unknown') === 0
                || strcasecmp($current_type, 'unknown') === 0) {
                $filled = trim((string) ($booking['booking_type'] ?? ''));
                if ($filled !== '') {
                    $roster_data['booking_type'] = $filled;
                }
            }
        }

        return $roster_data;
    }
}

if (!function_exists('intersoccer_migration_pa_stored_value_same_term_as_slug')) {
    /**
     * Whether a stored pa_* / attribute_pa_* value refers to the same taxonomy term as the target slug.
     */
    function intersoccer_migration_pa_stored_value_same_term_as_slug(string $taxonomy, string $stored, string $target_slug): bool {
        $stored = trim($stored);
        $target_slug = trim($target_slug);
        if ($stored === '' || $target_slug === '') {
            return false;
        }
        if ($stored === $target_slug) {
            return true;
        }

        $stored_slug = intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $stored);
        $target_norm = intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $target_slug);

        return $stored_slug !== '' && $target_norm !== '' && $stored_slug === $target_norm;
    }
}

if (!function_exists('intersoccer_migration_normalize_slug_meta_for_variation')) {
    /**
     * @param array<string,string> $slug_meta
     * @param array<string,string> $existing_line_pa Raw pa_* values on the line before rewrite (optional).
     * @return array<string,string>
     */
    function intersoccer_migration_normalize_slug_meta_for_variation(
        array $slug_meta,
        WC_Product_Variation $variation,
        array $existing_line_pa = []
    ): array {
        $normalized = [];
        foreach ($slug_meta as $meta_key => $meta_value) {
            if ($meta_key === '' || $meta_value === '' || $meta_value === null) {
                continue;
            }
            if (!preg_match('/^(attribute_)?pa_/i', (string) $meta_key)) {
                $normalized[$meta_key] = is_array($meta_value) ? (string) reset($meta_value) : (string) $meta_value;
                continue;
            }
            $taxonomy = preg_replace('/^attribute_/i', '', (string) $meta_key);
            $raw_from_variation = intersoccer_migration_pa_attribute_slug_for_variation($variation, $taxonomy, false);
            $slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, $taxonomy);
            if ($raw_from_variation !== '' && function_exists('intersoccer_migration_coerce_pa_taxonomy_slug_strict')) {
                $strict_from_variation = intersoccer_migration_coerce_pa_taxonomy_slug_strict(
                    $taxonomy,
                    $raw_from_variation
                );
                if ($strict_from_variation === null) {
                    $slug = '';
                } else {
                    $slug = $strict_from_variation;
                }
            }
            if ($slug === '') {
                $fallback_raw = is_array($meta_value) ? (string) reset($meta_value) : (string) $meta_value;
                if (function_exists('intersoccer_migration_coerce_pa_taxonomy_slug_strict')) {
                    $strict_slug = intersoccer_migration_coerce_pa_taxonomy_slug_strict($taxonomy, $fallback_raw);
                    $slug = $strict_slug !== null ? $strict_slug : '';
                } elseif (function_exists('intersoccer_migration_coerce_pa_taxonomy_slug')) {
                    $slug = intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $fallback_raw);
                }
            }
            if ($slug === '') {
                $existing_on_empty = intersoccer_migration_pa_existing_line_value_for_key(
                    $existing_line_pa,
                    (string) $meta_key
                );
                if ($existing_on_empty !== '') {
                    $normalized[$meta_key] = $existing_on_empty;
                }
                continue;
            }
            $existing = intersoccer_migration_pa_existing_line_value_for_key($existing_line_pa, (string) $meta_key);
            if ($existing !== '' && intersoccer_migration_pa_stored_value_same_term_as_slug($taxonomy, $existing, $slug)) {
                $normalized[$meta_key] = $existing;
            } else {
                $normalized[$meta_key] = intersoccer_migration_pa_value_when_term_changes(
                    $taxonomy,
                    $variation,
                    $slug,
                    $existing
                );
            }
        }

        return $normalized;
    }
}

if (!function_exists('intersoccer_migration_set_slug_meta_pair')) {
    /**
     * @param array<string,string> $slug_meta
     */
    function intersoccer_migration_set_slug_meta_pair(array &$slug_meta, string $taxonomy, string $value): void {
        $value = trim((string) $value);
        if ($taxonomy === '' || $value === '') {
            return;
        }
        if (function_exists('intersoccer_migration_coerce_pa_taxonomy_slug_strict')) {
            $strict_value = intersoccer_migration_coerce_pa_taxonomy_slug_strict($taxonomy, $value);
            if ($strict_value === null || $strict_value === '') {
                return;
            }
            $value = $strict_value;
        } elseif (function_exists('intersoccer_migration_coerce_pa_taxonomy_slug')) {
            $value = intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $value);
        }

        if (!isset($slug_meta[$taxonomy]) || $slug_meta[$taxonomy] === '') {
            $slug_meta[$taxonomy] = $value;
        }
        $attribute_key = 'attribute_' . $taxonomy;
        if (!isset($slug_meta[$attribute_key]) || $slug_meta[$attribute_key] === '') {
            $slug_meta[$attribute_key] = $value;
        }
    }
}

if (!function_exists('intersoccer_migration_enrich_slug_meta_from_variation')) {
    /**
     * Fill pa_* slugs from variation then parent (WooCommerce line-item shape).
     *
     * @param \WC_Product_Variation $variation
     * @param array<string,string>  $slug_meta
     * @return array<string,string>
     */
    function intersoccer_migration_enrich_slug_meta_from_variation(WC_Product_Variation $variation, array $slug_meta): array {
        $slug_meta = intersoccer_migration_normalize_slug_meta_for_variation($slug_meta, $variation);

        foreach (intersoccer_migration_pa_taxonomy_keys_for_move() as $taxonomy) {
            $slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, $taxonomy);
            if ($slug !== '') {
                intersoccer_migration_set_slug_meta_pair($slug_meta, $taxonomy, $slug);
            }
        }

        return intersoccer_migration_normalize_slug_meta_for_variation($slug_meta, $variation);
    }
}

if (!function_exists('intersoccer_migration_snapshot_line_pa_slug_values')) {
    /**
     * Raw pa_* / attribute_pa_* values on the line before a move rewrite.
     *
     * @return array<string,string>
     */
    function intersoccer_migration_snapshot_line_pa_slug_values($item): array {
        $values = [];
        foreach ($item->get_meta_data() as $meta) {
            if (is_object($meta) && method_exists($meta, 'get_data')) {
                $data = $meta->get_data();
                $key = (string) ($data['key'] ?? '');
                $value = $data['value'] ?? '';
            } elseif (is_string($meta)) {
                continue;
            } else {
                continue;
            }
            if ($key === '' || !preg_match('/^(attribute_)?pa_/i', $key)) {
                continue;
            }
            $values[$key] = is_array($value) ? (string) reset($value) : (string) $value;
        }

        return $values;
    }
}

if (!function_exists('intersoccer_migration_snapshot_existing_pa_slug_meta_keys')) {
    /**
     * pa_* / attribute_pa_* keys already stored on the line (before rewrite).
     *
     * @param \WC_Order_Item_Product $item
     * @return array<int,string>
     */
    function intersoccer_migration_snapshot_existing_pa_slug_meta_keys($item): array {
        $keys = [];
        foreach ($item->get_meta_data() as $meta) {
            $key = (string) ($meta->get_data()['key'] ?? '');
            if ($key !== '' && preg_match('/^(attribute_)?pa_/i', $key)) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}

if (!function_exists('intersoccer_migration_filter_slug_meta_for_move')) {
    /**
     * Keep slug rewrites on keys the line already had, plus variation attributes Woo would store.
     *
     * @param array<string,string>  $slug_meta
     * @param array<int,string>     $existing_pa_keys
     * @param \WC_Product_Variation $variation
     * @return array<string,string>
     */
    function intersoccer_migration_filter_slug_meta_for_move(
        array $slug_meta,
        array $existing_pa_keys,
        WC_Product_Variation $variation,
        array $existing_line_pa = []
    ): array {
        $allowed = [];
        foreach ($existing_pa_keys as $key) {
            $allowed[$key] = true;
        }
        $filtered = [];
        foreach ($slug_meta as $meta_key => $meta_value) {
            if (!isset($allowed[$meta_key])) {
                continue;
            }
            if ($meta_value === '' || $meta_value === null) {
                continue;
            }
            $filtered[$meta_key] = is_array($meta_value) ? (string) reset($meta_value) : (string) $meta_value;
        }

        return intersoccer_migration_normalize_slug_meta_for_variation($filtered, $variation, $existing_line_pa);
    }
}

if (!function_exists('intersoccer_migration_apply_slug_meta_for_move')) {
    /**
     * @param array<string,string> $slug_meta
     */
    function intersoccer_migration_apply_slug_meta_for_move(int $item_id, array $slug_meta): void {
        if ($item_id <= 0 || $slug_meta === []) {
            return;
        }

        $item = new WC_Order_Item_Product($item_id);
        foreach ($slug_meta as $meta_key => $meta_value) {
            if ($meta_key === '' || $meta_value === '' || $meta_value === null) {
                continue;
            }
            $item->update_meta_data((string) $meta_key, is_array($meta_value) ? reset($meta_value) : $meta_value);
        }
        $item->save();
    }
}

if (!function_exists('intersoccer_migration_remove_variation_meta_for_move')) {
    /**
     * Strip variation-derived meta before WooCommerce saves the new line item; preserve player/attendee keys.
     *
     * @param \WC_Order_Item_Product $item
     * @param array<string,string>   $human_lookup
     */
    function intersoccer_migration_remove_variation_meta_for_move($item, array $human_lookup): void {
        foreach ($item->get_meta_data() as $meta) {
            $key = (string) $meta->get_data()['key'];
            if (!intersoccer_migration_is_variation_rewrite_meta_key($key, $human_lookup)) {
                continue;
            }
            $item->delete_meta_data($key);
        }
    }
}

if (!function_exists('intersoccer_migration_move_meta_array_is_nested')) {
    /**
     * True when an order-item meta array contains nested arrays/objects (e.g. discount breakdown).
     */
    function intersoccer_migration_move_meta_array_is_nested(array $value): bool {
        foreach ($value as $element) {
            if (is_array($element) || is_object($element)) {
                return true;
            }
            if (!is_scalar($element) && $element !== null) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('intersoccer_migration_coerce_move_line_meta_value')) {
    /**
     * Normalize a line meta value for move snapshot/restore without touching nested structures.
     *
     * @param mixed $raw
     * @return mixed
     */
    function intersoccer_migration_coerce_move_line_meta_value($raw) {
        if (is_array($raw)) {
            if (intersoccer_migration_move_meta_array_is_nested($raw)) {
                return $raw;
            }
            foreach ($raw as $part) {
                if (!is_scalar($part)) {
                    return $raw;
                }
            }

            return $raw;
        }
        if (is_scalar($raw) || $raw === null) {
            return trim((string) $raw);
        }

        return $raw;
    }
}

if (!function_exists('intersoccer_migration_move_meta_value_is_blank')) {
    /**
     * @param mixed $value
     */
    function intersoccer_migration_move_meta_value_is_blank($value): bool {
        if (is_array($value)) {
            return $value === [];
        }
        if (is_scalar($value) || $value === null) {
            return trim((string) $value) === '';
        }

        return false;
    }
}

if (!function_exists('intersoccer_migration_snapshot_line_meta_keys')) {
    /**
     * @param \WC_Order_Item_Product $item
     * @return array<int,string>
     */
    function intersoccer_migration_snapshot_line_meta_keys($item): array {
        $keys = [];
        foreach ($item->get_meta_data() as $meta) {
            $key = (string) ($meta->get_data()['key'] ?? '');
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}

if (!function_exists('intersoccer_migration_snapshot_line_meta_values')) {
    /**
     * @return array{human: array<string,mixed>, other: array<string,mixed>}
     */
    function intersoccer_migration_snapshot_line_meta_values(
        $item,
        array $human_alias_map,
        array $human_lookup
    ): array {
        $human = [];
        $other = [];
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $key = (string) ($data['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $value = intersoccer_migration_coerce_move_line_meta_value($data['value'] ?? '');
            $normalized = intersoccer_migration_normalize_meta_key($key);
            if (isset($human_lookup[$normalized])) {
                $human[$human_lookup[$normalized]] = $value;
                continue;
            }
            $other[$key] = $value;
        }

        return ['human' => $human, 'other' => $other];
    }
}

if (!function_exists('intersoccer_migration_allowed_canonicals_for_move')) {
    /**
     * @param array<int,string>              $existing_line_keys
     * @param array<string,array<int,string>> $human_alias_map
     * @param array<string,string>           $human_lookup
     * @return array<int,string>
     */
    function intersoccer_migration_allowed_canonicals_for_move(
        array $existing_line_keys,
        array $human_alias_map,
        array $human_lookup
    ): array {
        $allowed = [];
        $existing_flip = array_fill_keys($existing_line_keys, true);

        foreach ($existing_line_keys as $key) {
            $normalized = intersoccer_migration_normalize_meta_key($key);
            if (isset($human_lookup[$normalized])) {
                $allowed[$human_lookup[$normalized]] = true;
            }
        }

        foreach (['Girls Only', 'girls_only', 'pa_girls-only', 'attribute_pa_girls-only'] as $girls_key) {
            if (isset($existing_flip[$girls_key])) {
                $allowed['girls_only'] = true;
                break;
            }
        }

        if (isset($existing_flip['Year'])) {
            $allowed['program_year'] = true;
        }

        return array_keys($allowed);
    }
}

if (!function_exists('intersoccer_migration_human_canonical_to_pa_taxonomy')) {
    /**
     * @return array<string,string>
     */
    function intersoccer_migration_human_canonical_to_pa_taxonomy(): array {
        return [
            'intersoccer_venues' => 'pa_intersoccer-venues',
            'age_group'          => 'pa_age-group',
            'camp_terms'         => 'pa_camp-terms',
            'course_day'         => 'pa_course-day',
            'course_times'       => 'pa_course-times',
            'camp_times'         => 'pa_camp-times',
            'activity_type'      => 'pa_activity-type',
            'season'             => 'pa_program-season',
            'canton_region'      => 'pa_canton-region',
            'city'               => 'pa_city',
        ];
    }
}

if (!function_exists('intersoccer_migration_human_canonical_to_order_meta_key')) {
    /**
     * @return array<string,string>
     */
    function intersoccer_migration_human_canonical_to_order_meta_key(): array {
        return [
            'intersoccer_venues' => '_intersoccer_canonical_venue',
            'age_group'          => '_intersoccer_canonical_age_group',
            'camp_terms'         => '_intersoccer_canonical_camp_terms',
            'canton_region'      => '_intersoccer_canonical_canton',
            'activity_type'      => '_intersoccer_canonical_activity_type',
        ];
    }
}

if (!function_exists('intersoccer_migration_resolve_move_en_slug_from_variation')) {
    /**
     * @param array<string,string> $slug_meta
     */
    function intersoccer_migration_resolve_move_en_slug_from_variation(
        string $pa_taxonomy,
        WC_Product_Variation $variation,
        array $slug_meta
    ): ?string {
        if ($pa_taxonomy === '') {
            return null;
        }

        $raw_slug = '';
        if (function_exists('intersoccer_migration_pa_attribute_slug_for_variation')) {
            $raw_slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, $pa_taxonomy, false);
        }
        if ($raw_slug === '' && function_exists('intersoccer_migration_slug_meta_pa_value')) {
            $raw_slug = intersoccer_migration_slug_meta_pa_value($slug_meta, $pa_taxonomy);
        }
        if ($raw_slug === '') {
            return null;
        }

        if (function_exists('intersoccer_migration_coerce_pa_taxonomy_slug_strict')) {
            $strict = intersoccer_migration_coerce_pa_taxonomy_slug_strict($pa_taxonomy, $raw_slug);
            if ($strict === null) {
                return null;
            }
            $raw_slug = $strict;
        }

        if (function_exists('intersoccer_migration_default_language_term_slug_strict')) {
            return intersoccer_migration_default_language_term_slug_strict($pa_taxonomy, $raw_slug);
        }

        return $raw_slug;
    }
}

if (!function_exists('intersoccer_migration_resolve_move_en_slug_from_line')) {
    /**
     * @param array<string,string> $prior_canonical_order_meta
     * @param array<string,string> $existing_line_pa
     * @param array<string,string> $line_human_snapshot Canonical-keyed visible labels from the line.
     */
    function intersoccer_migration_resolve_move_en_slug_from_line(
        string $pa_taxonomy,
        ?string $canonical_order_meta_key,
        array $prior_canonical_order_meta,
        array $existing_line_pa,
        array $line_human_snapshot = [],
        string $canonical_internal = ''
    ): ?string {
        $resolve_strict_en = static function (string $taxonomy, string $value): ?string {
            if ($taxonomy === '' || trim($value) === '') {
                return null;
            }
            $stored = trim($value);
            if (function_exists('intersoccer_migration_coerce_pa_taxonomy_slug_strict')) {
                $strict = intersoccer_migration_coerce_pa_taxonomy_slug_strict($taxonomy, $stored);
                if ($strict === null) {
                    return null;
                }
                $stored = $strict;
            }
            if (function_exists('intersoccer_migration_default_language_term_slug_strict')) {
                return intersoccer_migration_default_language_term_slug_strict($taxonomy, $stored);
            }

            return $stored;
        };

        if ($canonical_order_meta_key !== null
            && $canonical_order_meta_key !== ''
            && isset($prior_canonical_order_meta[$canonical_order_meta_key])
            && $prior_canonical_order_meta[$canonical_order_meta_key] !== ''
        ) {
            $from_canonical = $resolve_strict_en(
                $pa_taxonomy,
                (string) $prior_canonical_order_meta[$canonical_order_meta_key]
            );
            if ($from_canonical !== null) {
                return $from_canonical;
            }
        }

        if ($pa_taxonomy === '') {
            return null;
        }

        foreach (['attribute_' . $pa_taxonomy, $pa_taxonomy] as $pa_key) {
            if (!isset($existing_line_pa[$pa_key]) || $existing_line_pa[$pa_key] === '') {
                continue;
            }
            $from_pa = $resolve_strict_en($pa_taxonomy, (string) $existing_line_pa[$pa_key]);
            if ($from_pa !== null) {
                return $from_pa;
            }

            return null;
        }

        if ($canonical_internal !== ''
            && isset($line_human_snapshot[$canonical_internal])
            && trim((string) $line_human_snapshot[$canonical_internal]) !== ''
        ) {
            return $resolve_strict_en($pa_taxonomy, (string) $line_human_snapshot[$canonical_internal]);
        }

        return null;
    }
}

if (!function_exists('intersoccer_migration_course_schedule_fingerprint')) {
    /**
     * Normalized course schedule from variation meta (_course_*), for source/destination comparison.
     *
     * @return array{start:string,end:string,holidays:string[]}|null
     */
    function intersoccer_migration_course_schedule_fingerprint(int $variation_id): ?array {
        if ($variation_id <= 0 || !function_exists('intersoccer_get_course_meta')) {
            return null;
        }

        $start = trim((string) intersoccer_get_course_meta($variation_id, '_course_start_date', ''));
        $end = trim((string) intersoccer_get_course_meta($variation_id, '_end_date', ''));
        $holidays_raw = intersoccer_get_course_meta($variation_id, '_course_holiday_dates', []);
        if (!is_array($holidays_raw)) {
            $holidays_raw = [];
        }
        $holidays = [];
        foreach ($holidays_raw as $holiday) {
            $holiday = trim((string) $holiday);
            if ($holiday !== '') {
                $holidays[] = $holiday;
            }
        }
        sort($holidays);

        return [
            'start'    => $start,
            'end'      => $end,
            'holidays' => $holidays,
        ];
    }
}

if (!function_exists('intersoccer_migration_course_schedule_order_meta_keys')) {
    /**
     * @return array<int,string>
     */
    function intersoccer_migration_course_schedule_order_meta_keys(): array {
        $keys = ['Start Date', 'End Date', 'Holidays'];
        if (function_exists('intersoccer_get_order_meta_manual_aliases')) {
            $aliases = intersoccer_get_order_meta_manual_aliases();
            foreach (['Start Date', 'End Date', 'Holidays'] as $canonical) {
                foreach ($aliases[$canonical] ?? [] as $alias) {
                    $keys[] = (string) $alias;
                }
            }
        }

        return array_values(array_unique(array_filter($keys, static function ($key) {
            return trim((string) $key) !== '';
        })));
    }
}

if (!function_exists('intersoccer_migration_camp_schedule_order_meta_keys')) {
    /**
     * @return array<int,string>
     */
    function intersoccer_migration_camp_schedule_order_meta_keys(): array {
        $key_groups = [
            ['Camp Start Date', 'Date de début du camp', 'Camp-Startdatum', '_camp_start_date'],
            ['Camp End Date', 'Date de fin du camp', 'Camp-Enddatum', '_camp_end_date'],
            ['Camp Week Index', '_camp_week_index'],
        ];
        if (function_exists('intersoccer_get_order_meta_manual_aliases')) {
            $aliases = intersoccer_get_order_meta_manual_aliases();
            foreach (['Camp Start Date', 'Camp End Date', 'Camp Week Index'] as $canonical) {
                foreach ($aliases[$canonical] ?? [] as $alias) {
                    $key_groups[] = [(string) $alias];
                }
            }
        }
        $keys = [];
        foreach ($key_groups as $group) {
            foreach ($group as $key) {
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }
}

if (!function_exists('intersoccer_migration_build_parent_other_meta_for_variation')) {
    /**
     * Parent product attributes written into other_meta during a move (excludes human-mapped facets).
     *
     * @return array<string,string>
     */
    function intersoccer_migration_build_parent_other_meta_for_variation(WC_Product_Variation $variation): array {
        if (!function_exists('intersoccer_get_parent_product_attributes')) {
            return [];
        }

        $human_alias_map = intersoccer_migration_human_alias_map();
        $human_lookup    = intersoccer_migration_build_lookup($human_alias_map);
        $parent_attributes = intersoccer_get_parent_product_attributes(
            (int) $variation->get_parent_id(),
            (int) $variation->get_id()
        );
        $other = [];
        foreach ($parent_attributes as $label => $value) {
            $normalized_label = intersoccer_migration_normalize_meta_key($label);
            $string_value = is_array($value) ? implode(', ', $value) : (string) $value;
            if (!isset($human_lookup[$normalized_label])) {
                $other[(string) $label] = $string_value;
            }
        }

        return $other;
    }
}

if (!function_exists('intersoccer_migration_preserve_unchanged_other_meta_for_move')) {
    /**
     * When source and destination variation agree on course/camp schedule or parent other_meta, keep line bytes.
     *
     * @param array<string,mixed> $other_meta
     * @param array<string,mixed> $line_other_snapshot
     * @param array<int,string>   $existing_line_keys
     * @return array<string,mixed>
     */
    function intersoccer_migration_preserve_unchanged_other_meta_for_move(
        array $other_meta,
        array $line_other_snapshot,
        int $source_variation_id,
        WC_Product_Variation $destination_variation,
        array $existing_line_keys
    ): array {
        if ($source_variation_id <= 0) {
            return $other_meta;
        }

        $mapped_source_variation_id = $source_variation_id;
        if (function_exists('intersoccer_resolve_variation_for_roster')) {
            $resolved_source = (int) intersoccer_resolve_variation_for_roster($source_variation_id);
            if ($resolved_source > 0) {
                $mapped_source_variation_id = $resolved_source;
            }
        }

        $existing_flip = array_fill_keys($existing_line_keys, true);
        $destination_id = (int) $destination_variation->get_id();
        $dest_parent_id = (int) $destination_variation->get_parent_id();
        $source_parent_id = 0;
        $mapped_source_product = wc_get_product($mapped_source_variation_id);
        if ($mapped_source_product instanceof WC_Product_Variation) {
            $source_parent_id = (int) $mapped_source_product->get_parent_id();
        }

        $source_product_type = '';
        $dest_product_type = '';
        if (function_exists('intersoccer_get_product_type_safe')) {
            if ($source_parent_id > 0) {
                $source_product_type = (string) intersoccer_get_product_type_safe($source_parent_id, $mapped_source_variation_id);
            }
            if ($dest_parent_id > 0) {
                $dest_product_type = (string) intersoccer_get_product_type_safe($dest_parent_id, $destination_id);
            }
        }

        $source_course = intersoccer_migration_course_schedule_fingerprint($mapped_source_variation_id);
        $dest_course = intersoccer_migration_course_schedule_fingerprint($destination_id);
        if ($source_product_type === 'course' && $dest_product_type === 'course'
            && $source_course !== null && $dest_course !== null
            && ($source_course['start'] ?? '') !== '' && ($dest_course['start'] ?? '') !== ''
        ) {
            foreach (intersoccer_migration_course_schedule_order_meta_keys() as $meta_key) {
                if (!isset($existing_flip[$meta_key], $line_other_snapshot[$meta_key])) {
                    continue;
                }
                $field = intersoccer_migration_course_schedule_field_for_order_meta_key($meta_key);
                if ($field === null) {
                    continue;
                }
                if (!intersoccer_migration_course_schedule_field_unchanged($field, $source_course, $dest_course)) {
                    continue;
                }
                $other_meta[$meta_key] = $line_other_snapshot[$meta_key];
            }
        }

        $source_camp = function_exists('intersoccer_migration_read_variation_camp_schedule_ymd')
            ? intersoccer_migration_read_variation_camp_schedule_ymd($mapped_source_variation_id)
            : ['start' => '', 'end' => '', 'week_index' => ''];
        $dest_camp = function_exists('intersoccer_migration_read_variation_camp_schedule_ymd')
            ? intersoccer_migration_read_variation_camp_schedule_ymd($destination_id)
            : ['start' => '', 'end' => '', 'week_index' => ''];
        if ($source_camp['start'] !== '' && $dest_camp['start'] !== ''
            && $source_camp['start'] === $dest_camp['start']
            && ($source_camp['end'] !== '' ? $source_camp['end'] : $source_camp['start'])
                === ($dest_camp['end'] !== '' ? $dest_camp['end'] : $dest_camp['start'])
            && (string) ($source_camp['week_index'] ?? '') === (string) ($dest_camp['week_index'] ?? '')
        ) {
            foreach (intersoccer_migration_camp_schedule_order_meta_keys() as $meta_key) {
                if (!isset($existing_flip[$meta_key], $line_other_snapshot[$meta_key])) {
                    continue;
                }
                $other_meta[$meta_key] = $line_other_snapshot[$meta_key];
            }
        }

        if ($mapped_source_product instanceof WC_Product_Variation) {
            $source_parent_other = intersoccer_migration_build_parent_other_meta_for_variation($mapped_source_product);
            $dest_parent_other = intersoccer_migration_build_parent_other_meta_for_variation($destination_variation);
            foreach ($dest_parent_other as $label => $dest_value) {
                if (!array_key_exists($label, $source_parent_other)) {
                    continue;
                }
                if ((string) $source_parent_other[$label] !== (string) $dest_value) {
                    continue;
                }
                if (!isset($existing_flip[$label], $line_other_snapshot[$label])) {
                    continue;
                }
                $other_meta[$label] = $line_other_snapshot[$label];
            }
        }

        return $other_meta;
    }
}

if (!function_exists('intersoccer_migration_course_schedule_field_for_order_meta_key')) {
    /**
     * @return 'start'|'end'|'holidays'|null
     */
    function intersoccer_migration_course_schedule_field_for_order_meta_key(string $meta_key): ?string {
        $meta_key = trim($meta_key);
        if ($meta_key === '') {
            return null;
        }

        $canonical_map = [
            'Start Date' => 'start',
            'End Date' => 'end',
            'Holidays' => 'holidays',
        ];
        if (isset($canonical_map[$meta_key])) {
            return $canonical_map[$meta_key];
        }

        if (function_exists('intersoccer_get_order_meta_manual_aliases')) {
            $aliases = intersoccer_get_order_meta_manual_aliases();
            foreach ($canonical_map as $canonical => $field) {
                foreach ($aliases[$canonical] ?? [] as $alias) {
                    if ((string) $alias === $meta_key) {
                        return $field;
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('intersoccer_migration_course_schedule_field_unchanged')) {
    /**
     * @param array{start:string,end:string,holidays:string[]} $source_course
     * @param array{start:string,end:string,holidays:string[]} $dest_course
     */
    function intersoccer_migration_course_schedule_field_unchanged(
        string $field,
        array $source_course,
        array $dest_course
    ): bool {
        if ($field === 'start') {
            return (string) ($source_course['start'] ?? '') === (string) ($dest_course['start'] ?? '');
        }
        if ($field === 'end') {
            return (string) ($source_course['end'] ?? '') === (string) ($dest_course['end'] ?? '');
        }
        if ($field === 'holidays') {
            $source_holidays = $source_course['holidays'] ?? [];
            $dest_holidays = $dest_course['holidays'] ?? [];
            if (!is_array($source_holidays)) {
                $source_holidays = [];
            }
            if (!is_array($dest_holidays)) {
                $dest_holidays = [];
            }

            return $source_holidays === $dest_holidays;
        }

        return false;
    }
}

if (!function_exists('intersoccer_migration_move_booking_type_label_meta_keys')) {
    /**
     * @return array<int,string>
     */
    function intersoccer_migration_move_booking_type_label_meta_keys(): array {
        $keys = ['Booking Type', 'Type de réservation', 'Buchungstyp'];
        if (function_exists('intersoccer_get_order_meta_manual_aliases')) {
            $aliases = intersoccer_get_order_meta_manual_aliases();
            foreach ($aliases['Booking Type'] ?? [] as $alias) {
                $keys[] = (string) $alias;
            }
        }

        return array_values(array_unique(array_filter($keys, static function ($key) {
            return trim((string) $key) !== '';
        })));
    }
}

if (!function_exists('intersoccer_migration_move_days_selected_meta_keys')) {
    /**
     * @return array<int,string>
     */
    function intersoccer_migration_move_days_selected_meta_keys(): array {
        $keys = ['Days Selected', 'Jours sélectionnés', 'Ausgewählte Tage'];
        if (function_exists('intersoccer_get_order_meta_manual_aliases')) {
            $aliases = intersoccer_get_order_meta_manual_aliases();
            foreach ($aliases['Days Selected'] ?? [] as $alias) {
                $keys[] = (string) $alias;
            }
        }

        return array_values(array_unique(array_filter($keys, static function ($key) {
            return trim((string) $key) !== '';
        })));
    }
}

if (!function_exists('intersoccer_migration_move_classify_raw_booking_type_value')) {
    /**
     * @return array{kind:'missing'|'known'|'unknown',slug:?string}
     */
    function intersoccer_migration_move_classify_raw_booking_type_value(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return ['kind' => 'missing', 'slug' => null];
        }

        $en_slug = $raw;
        if (function_exists('intersoccer_migration_default_language_term_slug_strict')) {
            $resolved = intersoccer_migration_default_language_term_slug_strict('pa_booking-type', $raw);
            if ($resolved !== null && $resolved !== '') {
                $en_slug = $resolved;
            }
        }

        if (!function_exists('intersoccer_normalize_booking_type_slug_for_reports')) {
            return ['kind' => 'known', 'slug' => $en_slug !== '' ? $en_slug : null];
        }

        $normalized = intersoccer_normalize_booking_type_slug_for_reports($en_slug);
        if ($normalized === '' || $normalized === 'other') {
            return ['kind' => 'unknown', 'slug' => null];
        }

        return ['kind' => 'known', 'slug' => $normalized];
    }
}

if (!function_exists('intersoccer_migration_move_variation_is_camp')) {
    function intersoccer_migration_move_variation_is_camp(WC_Product_Variation $variation): bool {
        if (!function_exists('intersoccer_get_product_type_safe')) {
            return false;
        }

        $parent_id = (int) $variation->get_parent_id();
        if ($parent_id <= 0) {
            return false;
        }

        return intersoccer_get_product_type_safe($parent_id, (int) $variation->get_id()) === 'camp';
    }
}

if (!function_exists('intersoccer_migration_move_activity_type_label_meta_keys')) {
    /**
     * @return array<int,string>
     */
    function intersoccer_migration_move_activity_type_label_meta_keys(): array {
        $keys = ['Activity Type', 'activity_type'];
        if (function_exists('intersoccer_get_order_meta_manual_aliases')) {
            $aliases = intersoccer_get_order_meta_manual_aliases();
            foreach ($aliases['Activity Type'] ?? [] as $alias) {
                $keys[] = (string) $alias;
            }
        } else {
            foreach (intersoccer_migration_human_alias_map_static_fallback('activity_type') as $alias) {
                $keys[] = (string) $alias;
            }
        }

        return array_values(array_unique(array_filter($keys, static function ($key) {
            return trim((string) $key) !== '';
        })));
    }
}

if (!function_exists('intersoccer_migration_move_activity_label_resolves_camp')) {
    /**
     * @return bool|null True/false when the label resolves to a camp or non-camp program; null when inconclusive.
     */
    function intersoccer_migration_move_activity_label_resolves_camp(string $raw): ?bool {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $canonical = function_exists('intersoccer_canonical_activity_type_for_roster')
            ? trim((string) intersoccer_canonical_activity_type_for_roster($raw))
            : $raw;
        if ($canonical === '') {
            return null;
        }
        if (strcasecmp($canonical, 'Camp') === 0) {
            return true;
        }

        static $non_camp = ['Course', 'Tournament', 'Birthday Party', 'Birthday', 'Event', 'Other'];
        foreach ($non_camp as $type) {
            if (strcasecmp($canonical, $type) === 0) {
                return false;
            }
        }

        // Bare 'Girls Only' is inconclusive on purpose (camp facet, not a program type).
        return null;
    }
}

if (!function_exists('intersoccer_migration_move_days_selected_is_placeholder')) {
    function intersoccer_migration_move_days_selected_is_placeholder(string $raw): bool {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') {
            return true;
        }

        static $placeholders = ['n/a', 'none'];
        foreach ($placeholders as $placeholder) {
            if (strcasecmp($raw, $placeholder) === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('intersoccer_migration_move_offered_camp_weekdays_from_product')) {
    /**
     * @param WC_Product|WC_Product_Variation $product
     * @return list<string> Canonical English weekday names, or empty when none found.
     */
    function intersoccer_migration_move_offered_camp_weekdays_from_product($product): array {
        if (!is_object($product)) {
            return [];
        }

        $raw_candidates = [];
        foreach (['days-of-week', 'pa_days-of-week'] as $attr_name) {
            $attr = trim((string) $product->get_attribute($attr_name));
            if ($attr !== '') {
                $raw_candidates[] = $attr;
            }
        }
        if (method_exists($product, 'get_meta')) {
            $meta_dow = trim((string) $product->get_meta('Days of Week', true));
            if ($meta_dow !== '') {
                $raw_candidates[] = $meta_dow;
            }
        }

        foreach ($raw_candidates as $raw) {
            if (!function_exists('intersoccer_normalize_selected_days_string_for_reports')) {
                continue;
            }
            $normalized = trim(intersoccer_normalize_selected_days_string_for_reports($raw));
            if ($normalized === '') {
                continue;
            }
            $days = array_values(array_unique(array_filter(array_map('trim', explode(',', $normalized)))));
            if ($days !== []) {
                return $days;
            }
        }

        return [];
    }
}

if (!function_exists('intersoccer_migration_move_order_item_offered_camp_weekdays')) {
    /**
     * @return list<string> Canonical English weekday names offered for the line's camp week.
     */
    function intersoccer_migration_move_order_item_offered_camp_weekdays(int $order_item_id): array {
        static $default = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

        $variation_id = 0;
        $parent_id_from_line = 0;
        if (function_exists('wc_get_order_item_meta')) {
            $variation_id = (int) wc_get_order_item_meta($order_item_id, '_variation_id', true);
            $parent_id_from_line = (int) wc_get_order_item_meta($order_item_id, '_product_id', true);
        }
        if ($variation_id <= 0 && function_exists('wc_get_order_id_by_order_item_id') && function_exists('wc_get_order')) {
            $order_id = (int) wc_get_order_id_by_order_item_id($order_item_id);
            if ($order_id > 0) {
                $order = wc_get_order($order_id);
                if ($order && is_callable([$order, 'get_item'])) {
                    $item = $order->get_item($order_item_id);
                    if ($item && is_a($item, 'WC_Order_Item_Product')) {
                        $variation_id = (int) $item->get_variation_id();
                        if ($parent_id_from_line <= 0) {
                            $parent_id_from_line = (int) $item->get_product_id();
                        }
                    }
                }
            }
        }

        if (!function_exists('wc_get_product')) {
            return $default;
        }

        $parent_id = 0;
        if ($variation_id > 0) {
            $resolved_variation_id = $variation_id;
            if (function_exists('intersoccer_resolve_variation_for_roster')) {
                $resolved_variation_id = (int) intersoccer_resolve_variation_for_roster($variation_id);
            }
            if ($resolved_variation_id > 0) {
                $resolved_variation = wc_get_product($resolved_variation_id);
                if ($resolved_variation instanceof WC_Product_Variation) {
                    $parent_id = (int) $resolved_variation->get_parent_id();
                }
            }
        }
        if ($parent_id <= 0 && $parent_id_from_line > 0) {
            $parent_id = $parent_id_from_line;
        }

        if ($parent_id > 0) {
            $parent = wc_get_product($parent_id);
            if ($parent) {
                $from_parent = intersoccer_migration_move_offered_camp_weekdays_from_product($parent);
                if ($from_parent !== []) {
                    return $from_parent;
                }
            }
        }

        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if ($variation instanceof WC_Product_Variation) {
                $from_variation = intersoccer_migration_move_offered_camp_weekdays_from_product($variation);
                if ($from_variation !== []) {
                    return $from_variation;
                }
            }
        }

        return $default;
    }
}

if (!function_exists('intersoccer_migration_move_days_selected_booking_type_outcome')) {
    /**
     * @return array{kind:'missing'|'known'|'unknown',slug:?string}
     */
    function intersoccer_migration_move_days_selected_booking_type_outcome(int $order_item_id, string $raw): array {
        if (intersoccer_migration_move_days_selected_is_placeholder($raw)) {
            return ['kind' => 'missing', 'slug' => null];
        }

        if (!function_exists('intersoccer_normalize_selected_days_string_for_reports')) {
            return ['kind' => 'unknown', 'slug' => null];
        }

        $normalized = trim(intersoccer_normalize_selected_days_string_for_reports($raw));
        if ($normalized === '') {
            return ['kind' => 'unknown', 'slug' => null];
        }

        $selected_days = array_values(array_unique(array_filter(array_map('trim', explode(',', $normalized)))));
        $offered = intersoccer_migration_move_order_item_offered_camp_weekdays($order_item_id);
        if ($offered !== [] && count($selected_days) >= count($offered)) {
            return ['kind' => 'missing', 'slug' => null];
        }

        return ['kind' => 'known', 'slug' => 'single-days'];
    }
}

if (!function_exists('intersoccer_migration_move_order_item_is_camp')) {
    function intersoccer_migration_move_order_item_is_camp(int $order_item_id): bool {
        if ($order_item_id <= 0) {
            return false;
        }

        $variation_id = 0;
        $product_id = 0;
        if (function_exists('wc_get_order_item_meta')) {
            $variation_id = (int) wc_get_order_item_meta($order_item_id, '_variation_id', true);
            $product_id = (int) wc_get_order_item_meta($order_item_id, '_product_id', true);
        }
        if ($variation_id <= 0 && function_exists('wc_get_order_id_by_order_item_id') && function_exists('wc_get_order')) {
            $order_id = (int) wc_get_order_id_by_order_item_id($order_item_id);
            if ($order_id > 0) {
                $order = wc_get_order($order_id);
                if ($order && is_callable([$order, 'get_item'])) {
                    $item = $order->get_item($order_item_id);
                    if ($item && is_a($item, 'WC_Order_Item_Product')) {
                        $variation_id = (int) $item->get_variation_id();
                        $product_id = (int) $item->get_product_id();
                    }
                }
            }
        }
        if ($variation_id <= 0 || $product_id <= 0) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb) && !empty($wpdb->prefix)) {
                $table = $wpdb->prefix . 'intersoccer_rosters';
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT variation_id, product_id FROM {$table} WHERE order_item_id = %d LIMIT 1",
                        $order_item_id
                    ),
                    ARRAY_A
                );
                if (is_array($row)) {
                    if ($variation_id <= 0) {
                        $variation_id = (int) ($row['variation_id'] ?? 0);
                    }
                    if ($product_id <= 0) {
                        $product_id = (int) ($row['product_id'] ?? 0);
                    }
                }
            }
        }

        if ($variation_id > 0 && function_exists('intersoccer_get_product_type_safe')) {
            if (function_exists('intersoccer_resolve_variation_for_roster')) {
                $resolved_variation_id = (int) intersoccer_resolve_variation_for_roster($variation_id);
                if ($resolved_variation_id > 0) {
                    $variation_id = $resolved_variation_id;
                }
            }
            if ($product_id <= 0 && function_exists('wc_get_product')) {
                $variation_product = wc_get_product($variation_id);
                if ($variation_product instanceof WC_Product_Variation) {
                    $product_id = (int) $variation_product->get_parent_id();
                }
            }
            if ($product_id > 0) {
                $product_type = strtolower(trim((string) intersoccer_get_product_type_safe($product_id, $variation_id)));
                if ($product_type !== '' && $product_type !== 'unknown') {
                    return $product_type === 'camp';
                }
            }
        }

        if (function_exists('wc_get_order_item_meta')) {
            $canonical_activity = wc_get_order_item_meta($order_item_id, '_intersoccer_canonical_activity_type', true);
            if ($canonical_activity !== '' && $canonical_activity !== null) {
                $slug = strtolower(trim((string) $canonical_activity));
                if ($slug === 'camp') {
                    return true;
                }
                if ($slug !== '') {
                    return false;
                }
            }

            foreach (intersoccer_migration_move_activity_type_label_meta_keys() as $meta_key) {
                $activity = wc_get_order_item_meta($order_item_id, $meta_key, true);
                if ($activity === '' || $activity === null) {
                    continue;
                }
                $resolved = intersoccer_migration_move_activity_label_resolves_camp((string) $activity);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && !empty($wpdb->prefix)) {
            $table = $wpdb->prefix . 'intersoccer_rosters';
            $activity_type = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT activity_type FROM {$table} WHERE order_item_id = %d LIMIT 1",
                    $order_item_id
                )
            );
            if (is_string($activity_type) && trim($activity_type) !== '') {
                $resolved = intersoccer_migration_move_activity_label_resolves_camp($activity_type);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return false;
    }
}

if (!function_exists('intersoccer_migration_move_variation_booking_type_outcome')) {
    /**
     * @return array{kind:'missing'|'known'|'unknown',slug:?string}
     */
    function intersoccer_migration_move_variation_booking_type_outcome(WC_Product_Variation $variation): array {
        if (!function_exists('intersoccer_migration_pa_attribute_slug_for_variation')) {
            return ['kind' => 'missing', 'slug' => null];
        }

        $raw_slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, 'pa_booking-type', false);
        if ($raw_slug === '') {
            return ['kind' => 'missing', 'slug' => null];
        }

        return intersoccer_migration_move_classify_raw_booking_type_value($raw_slug);
    }
}

if (!function_exists('intersoccer_migration_move_en_booking_type_slug_from_variation')) {
    function intersoccer_migration_move_en_booking_type_slug_from_variation(WC_Product_Variation $variation): ?string {
        $outcome = intersoccer_migration_move_variation_booking_type_outcome($variation);

        return $outcome['kind'] === 'known' ? $outcome['slug'] : null;
    }
}

if (!function_exists('intersoccer_migration_move_order_item_booking_type_outcome')) {
    /**
     * @return array{kind:'missing'|'known'|'unknown',slug:?string}
     */
    function intersoccer_migration_move_order_item_booking_type_outcome(int $order_item_id): array {
        if ($order_item_id <= 0) {
            return ['kind' => 'missing', 'slug' => null];
        }

        $first_known = null;
        $saw_present_unrecognized = false;

        $consider_classified = static function (array $outcome) use (&$first_known, &$saw_present_unrecognized): void {
            if ($outcome['kind'] === 'known') {
                if ($first_known === null) {
                    $first_known = $outcome;
                }

                return;
            }
            if ($outcome['kind'] === 'unknown') {
                $saw_present_unrecognized = true;
            }
        };

        $consider_raw_booking_value = static function (string $raw) use ($consider_classified): void {
            $raw = trim($raw);
            if ($raw === '') {
                return;
            }
            $consider_classified(intersoccer_migration_move_classify_raw_booking_type_value($raw));
        };

        $canonical = wc_get_order_item_meta($order_item_id, '_intersoccer_canonical_booking_type', true);
        if ($canonical !== '' && $canonical !== null) {
            $consider_raw_booking_value((string) $canonical);
        }

        foreach (['pa_booking-type', 'attribute_pa_booking-type'] as $meta_key) {
            $raw = wc_get_order_item_meta($order_item_id, $meta_key, true);
            if ($raw === '' || $raw === null) {
                continue;
            }
            $consider_raw_booking_value((string) $raw);
        }

        foreach (intersoccer_migration_move_booking_type_label_meta_keys() as $meta_key) {
            $raw = wc_get_order_item_meta($order_item_id, $meta_key, true);
            if ($raw === '' || $raw === null) {
                continue;
            }
            $consider_raw_booking_value((string) $raw);
        }

        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && !empty($wpdb->prefix)) {
            $table = $wpdb->prefix . 'intersoccer_rosters';
            $booking_type = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT booking_type FROM {$table} WHERE order_item_id = %d LIMIT 1",
                    $order_item_id
                )
            );
            if ($booking_type !== null && trim((string) $booking_type) !== '') {
                $consider_raw_booking_value((string) $booking_type);
            }
        }

        foreach (intersoccer_migration_move_days_selected_meta_keys() as $meta_key) {
            $days = wc_get_order_item_meta($order_item_id, $meta_key, true);
            if (!is_string($days)) {
                continue;
            }
            $consider_classified(
                intersoccer_migration_move_days_selected_booking_type_outcome($order_item_id, $days)
            );
        }

        if ($first_known !== null) {
            return $first_known;
        }
        if ($saw_present_unrecognized) {
            return ['kind' => 'unknown', 'slug' => null];
        }

        return ['kind' => 'missing', 'slug' => null];
    }
}

if (!function_exists('intersoccer_migration_move_en_booking_type_slug_from_order_item')) {
    function intersoccer_migration_move_en_booking_type_slug_from_order_item(int $order_item_id): ?string {
        $outcome = intersoccer_migration_move_order_item_booking_type_outcome($order_item_id);

        return $outcome['kind'] === 'known' ? $outcome['slug'] : null;
    }
}

if (!function_exists('intersoccer_migration_move_hard_block_for_booking_type_mismatch')) {
    /**
     * @param array<int,int|string> $order_item_ids
     */
    function intersoccer_migration_move_hard_block_for_booking_type_mismatch(
        WC_Product_Variation $destination_variation,
        array $order_item_ids
    ): ?string {
        $block_message = function_exists('intersoccer_roster_move_ui_string')
            ? intersoccer_roster_move_ui_string('booking_type_mismatch')
            : 'This roster has a different booking type (for example single days vs full week). Moving between booking types isn\'t supported yet.';

        $dest_is_camp = intersoccer_migration_move_variation_is_camp($destination_variation);
        $dest_outcome = intersoccer_migration_move_variation_booking_type_outcome($destination_variation);
        if ($dest_is_camp && $dest_outcome['kind'] === 'unknown') {
            return $block_message;
        }

        $dest_slug = $dest_outcome['kind'] === 'known' ? $dest_outcome['slug'] : null;
        if (!$dest_is_camp && $dest_slug === null) {
            return null;
        }

        foreach ($order_item_ids as $raw_item_id) {
            $item_id = (int) $raw_item_id;
            if ($item_id <= 0) {
                continue;
            }

            $line_is_camp = intersoccer_migration_move_order_item_is_camp($item_id);
            $line_outcome = intersoccer_migration_move_order_item_booking_type_outcome($item_id);

            if ($line_is_camp && $line_outcome['kind'] === 'unknown') {
                return $block_message;
            }

            $line_slug = $line_outcome['kind'] === 'known' ? $line_outcome['slug'] : null;

            if (!$line_is_camp) {
                if ($dest_slug !== null && $line_slug !== null && $line_slug !== $dest_slug) {
                    return $block_message;
                }
                continue;
            }

            if ($dest_slug !== null && $line_slug !== null && $line_slug !== $dest_slug) {
                return $block_message;
            }
        }

        return null;
    }
}

if (!function_exists('intersoccer_migration_preserve_unchanged_human_meta_for_move')) {
    /**
     * When destination and source resolve to the same EN taxonomy slug, keep localized visible labels.
     *
     * @param array<string,string> $human_meta
     * @param array<string,string> $line_human_snapshot
     * @param array<string,string> $prior_canonical_order_meta
     * @param array<string,string> $existing_line_pa
     * @param array<string,string> $slug_meta
     * @param array<int,string>    $allowed_canonicals
     * @return array<string,string>
     */
    function intersoccer_migration_preserve_unchanged_human_meta_for_move(
        array $human_meta,
        array $line_human_snapshot,
        array $prior_canonical_order_meta,
        array $existing_line_pa,
        WC_Product_Variation $variation,
        array $slug_meta,
        array $allowed_canonicals
    ): array {
        if ($allowed_canonicals === []) {
            return $human_meta;
        }

        $allowed_flip = array_fill_keys($allowed_canonicals, true);
        $canonical_to_pa = intersoccer_migration_human_canonical_to_pa_taxonomy();
        $canonical_to_order_meta = intersoccer_migration_human_canonical_to_order_meta_key();

        foreach ($human_meta as $canonical => $destination_value) {
            if (!isset($allowed_flip[$canonical])) {
                continue;
            }
            if (in_array($canonical, ['booking_type', 'girls_only', 'selected_days', 'program_year', 'activity_type'], true)) {
                continue;
            }

            $pa_taxonomy = $canonical_to_pa[$canonical] ?? '';
            if ($pa_taxonomy === '') {
                continue;
            }

            if ($canonical === 'camp_times' || $canonical === 'course_times') {
                $dest_slug = intersoccer_migration_resolve_move_en_slug_from_variation($pa_taxonomy, $variation, $slug_meta);
                $source_slug = intersoccer_migration_resolve_move_en_slug_from_line(
                    $pa_taxonomy,
                    null,
                    $prior_canonical_order_meta,
                    $existing_line_pa,
                    $line_human_snapshot,
                    $canonical
                );
            } else {
                $order_meta_key = $canonical_to_order_meta[$canonical] ?? null;
                $dest_slug = intersoccer_migration_resolve_move_en_slug_from_variation($pa_taxonomy, $variation, $slug_meta);
                $source_slug = intersoccer_migration_resolve_move_en_slug_from_line(
                    $pa_taxonomy,
                    $order_meta_key,
                    $prior_canonical_order_meta,
                    $existing_line_pa,
                    $line_human_snapshot,
                    $canonical
                );
            }

            if ($dest_slug === null || $source_slug === null || $dest_slug !== $source_slug) {
                continue;
            }

            if (isset($line_human_snapshot[$canonical]) && $line_human_snapshot[$canonical] !== '') {
                $human_meta[$canonical] = $line_human_snapshot[$canonical];
            }
        }

        return $human_meta;
    }
}

if (!function_exists('intersoccer_migration_filter_human_meta_for_move')) {
    /**
     * @param array<string,string> $human_meta
     * @param array<int,string>    $allowed_canonicals
     * @return array<string,string>
     */
    function intersoccer_migration_filter_human_meta_for_move(array $human_meta, array $allowed_canonicals): array {
        if ($allowed_canonicals === []) {
            return $human_meta;
        }

        $flip = array_fill_keys($allowed_canonicals, true);
        $filtered = [];
        foreach ($human_meta as $canonical => $value) {
            if (isset($flip[$canonical])) {
                $filtered[$canonical] = $value;
            }
        }

        return $filtered;
    }
}

if (!function_exists('intersoccer_migration_filter_other_meta_for_move')) {
    /**
     * @param array<string,mixed> $other_meta
     * @param array<int,string>    $existing_line_keys
     * @return array<string,mixed>
     */
    function intersoccer_migration_filter_other_meta_for_move(array $other_meta, array $existing_line_keys): array {
        $flip = array_fill_keys($existing_line_keys, true);
        $filtered = [];
        foreach ($other_meta as $key => $value) {
            if (isset($flip[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}

if (!function_exists('intersoccer_migration_prune_unexpected_move_meta_keys')) {
    /**
     * @param int                  $item_id
     * @param array<int,string>    $existing_line_keys
     * @param array<string,string> $human_lookup
     */
    function intersoccer_migration_prune_unexpected_move_meta_keys(
        int $item_id,
        array $existing_line_keys,
        array $human_lookup
    ): void {
        $allowed = array_fill_keys($existing_line_keys, true);
        foreach ($existing_line_keys as $key) {
            if (preg_match('/^attribute_(pa_.+)$/i', (string) $key, $matches)) {
                $allowed[$matches[1]] = true;
            } elseif (preg_match('/^pa_/i', (string) $key)) {
                $allowed['attribute_' . $key] = true;
            }
        }
        if (isset($allowed['Girls Only']) || isset($allowed['girls_only'])) {
            foreach (['Girls Only', 'girls_only', 'pa_girls-only', 'attribute_pa_girls-only'] as $related) {
                $allowed[$related] = true;
            }
        }
        $item = new WC_Order_Item_Product($item_id);
        foreach ($item->get_meta_data() as $meta) {
            $key = (string) ($meta->get_data()['key'] ?? '');
            if ($key === '' || isset($allowed[$key])) {
                continue;
            }
            if (function_exists('intersoccer_roster_move_stock_delta_meta_key')
                && $key === intersoccer_roster_move_stock_delta_meta_key()) {
                continue;
            }
            if (function_exists('intersoccer_roster_history_meta_key')
                && $key === intersoccer_roster_history_meta_key()) {
                continue;
            }
            if (!intersoccer_migration_is_variation_rewrite_meta_key($key, $human_lookup)) {
                continue;
            }
            $item->delete_meta_data($key);
        }
        $item->save();
    }
}

if (!function_exists('intersoccer_migration_finalize_move_item_meta')) {
    /**
     * @param \WC_Product_Variation $variation
     */
    function intersoccer_migration_finalize_move_item_meta(
        int $item_id,
        array $human_alias_map,
        array $human_lookup,
        array $human_meta,
        array $other_meta,
        array $slug_meta,
        array $existing_line_keys,
        WC_Product_Variation $variation,
        array $prior_canonical_order_meta = []
    ): void {
        if ($item_id <= 0) {
            return;
        }

        $allowed_canonicals = intersoccer_migration_allowed_canonicals_for_move(
            $existing_line_keys,
            $human_alias_map,
            $human_lookup
        );
        $human_meta = intersoccer_migration_filter_human_meta_for_move($human_meta, $allowed_canonicals);
        $other_meta = intersoccer_migration_filter_other_meta_for_move($other_meta, $existing_line_keys);

        $existing_pa = array_values(array_filter(
            $existing_line_keys,
            static function ($key) {
                return preg_match('/^(attribute_)?pa_/i', (string) $key);
            }
        ));
        $existing_line_pa = [];
        if (function_exists('wc_get_order_item_meta') && $item_id > 0) {
            foreach ($existing_pa as $pa_key) {
                $existing_line_pa[$pa_key] = (string) wc_get_order_item_meta($item_id, $pa_key, true);
            }
        }
        if (function_exists('intersoccer_migration_filter_slug_meta_for_move')) {
            $slug_meta = intersoccer_migration_filter_slug_meta_for_move(
                $slug_meta,
                $existing_pa,
                $variation,
                $existing_line_pa
            );
        } elseif (function_exists('intersoccer_migration_normalize_slug_meta_for_variation')) {
            $slug_meta = intersoccer_migration_normalize_slug_meta_for_variation($slug_meta, $variation, $existing_line_pa);
        }

        intersoccer_migration_restore_canonical_meta(
            $item_id,
            $human_alias_map,
            $human_lookup,
            $human_meta,
            $other_meta,
            $existing_line_keys
        );

        if ($slug_meta !== [] && function_exists('intersoccer_migration_apply_slug_meta_for_move')) {
            intersoccer_migration_apply_slug_meta_for_move($item_id, $slug_meta);
        }

        intersoccer_migration_prune_unexpected_move_meta_keys($item_id, $existing_line_keys, $human_lookup);

        if (function_exists('intersoccer_migration_apply_destination_canonical_order_meta_for_move')) {
            intersoccer_migration_apply_destination_canonical_order_meta_for_move(
                $item_id,
                $variation,
                $existing_line_keys,
                $slug_meta,
                $prior_canonical_order_meta
            );
        }
    }
}

if (!function_exists('intersoccer_migration_restore_canonical_meta')) {
    /**
     * Reapply canonical checkout metadata (English keys and values).
     *
     * @param int   $item_id
     * @param array $human_alias_map
     * @param array $human_lookup
     * @param array $human_meta
     * @param array $other_meta
     * @param bool  $restrict_to_existing_line_keys Admin language repair: never add keys or merge/prune rows.
     * @return bool True when order item meta was changed.
     */
    function intersoccer_migration_restore_canonical_meta(
        $item_id,
        array $human_alias_map,
        array $human_lookup,
        array $human_meta,
        array $other_meta,
        array $existing_line_keys = [],
        bool $restrict_to_existing_line_keys = false
    ) {
        $item = new WC_Order_Item_Product($item_id);
        $existing_flip = array_fill_keys($existing_line_keys, true);
        $meta_changed = false;
        $meta_values_differ = static function ($current, $normalized): bool {
            if (is_array($current) || is_array($normalized)) {
                return $current !== $normalized;
            }

            return (string) $current !== (string) $normalized;
        };
        $meta_row_key = static function ($meta): string {
            if (!is_object($meta)) {
                return '';
            }
            if (isset($meta->key)) {
                return (string) $meta->key;
            }
            if (method_exists($meta, 'get_data')) {
                $data = $meta->get_data();

                return (string) ($data['key'] ?? '');
            }

            return '';
        };
        $meta_row_value = static function ($meta) {
            if (!is_object($meta)) {
                return '';
            }
            if (isset($meta->value)) {
                return $meta->value;
            }
            if (method_exists($meta, 'get_data')) {
                $data = $meta->get_data();

                return $data['value'] ?? '';
            }

            return '';
        };
        $meta_row_id = static function ($meta): int {
            if (!is_object($meta)) {
                return 0;
            }
            if (isset($meta->id)) {
                return (int) $meta->id;
            }
            if (method_exists($meta, 'get_data')) {
                $data = $meta->get_data();

                return (int) ($data['id'] ?? 0);
            }

            return 0;
        };

        foreach ($human_meta as $canonical => $meta_value) {
            $normalized_value = intersoccer_migration_coerce_move_line_meta_value($meta_value);

            if (intersoccer_migration_move_meta_value_is_blank($normalized_value)) {
                continue;
            }

            $aliases = array_unique(array_merge(
                [$canonical],
                $human_alias_map[$canonical] ?? []
            ));

            if ($restrict_to_existing_line_keys) {
                $alias_flip = [];
                foreach ($aliases as $alias_key) {
                    if (isset($existing_flip[$alias_key])) {
                        $alias_flip[$alias_key] = true;
                    }
                }
                if ($alias_flip === []) {
                    continue;
                }
                foreach ($item->get_meta_data() as $meta) {
                    $row_key = $meta_row_key($meta);
                    if ($row_key === '' || !isset($alias_flip[$row_key])) {
                        continue;
                    }
                    $current = $meta_row_value($meta);
                    if ($meta_values_differ($current, $normalized_value)) {
                        $meta_changed = true;
                    }
                    $row_id = $meta_row_id($meta);
                    if ($row_id > 0) {
                        $item->update_meta_data($row_key, $normalized_value, $row_id);
                    } else {
                        $item->update_meta_data($row_key, $normalized_value);
                    }
                }
                continue;
            }

            $wrote = false;
            foreach ($aliases as $alias_key) {
                if ($existing_line_keys !== [] && !isset($existing_flip[$alias_key])) {
                    continue;
                }
                $current = $item->get_meta($alias_key, true);
                if ($meta_values_differ($current, $normalized_value)) {
                    $meta_changed = true;
                }
                $item->delete_meta_data($alias_key);
                $item->add_meta_data($alias_key, $normalized_value, true);
                $wrote = true;
            }
            if (!$wrote) {
                $label = function_exists('intersoccer_migration_pick_meta_label_for_canonical')
                    ? intersoccer_migration_pick_meta_label_for_canonical($canonical, $human_alias_map, $existing_line_keys)
                    : $aliases[0];
                $current = $item->get_meta($label, true);
                if ($meta_values_differ($current, $normalized_value)) {
                    $meta_changed = true;
                }
                $item->delete_meta_data($label);
                $item->add_meta_data($label, $normalized_value, true);
            }
        }

        foreach ($other_meta as $meta_key => $meta_value) {
            if ($restrict_to_existing_line_keys && !isset($existing_flip[$meta_key])) {
                continue;
            }

            $normalized_value = intersoccer_migration_coerce_move_line_meta_value($meta_value);
            $current = $item->get_meta($meta_key, true);

            if ($restrict_to_existing_line_keys) {
                foreach ($item->get_meta_data() as $meta) {
                    $row_key = $meta_row_key($meta);
                    if ($row_key !== (string) $meta_key) {
                        continue;
                    }
                    $row_current = $meta_row_value($meta);
                    if (intersoccer_migration_move_meta_value_is_blank($normalized_value)) {
                        if ($meta_values_differ($row_current, '')) {
                            $meta_changed = true;
                        }
                        $row_id = $meta_row_id($meta);
                        if ($row_id > 0) {
                            $item->update_meta_data($row_key, '', $row_id);
                        } else {
                            $item->update_meta_data($row_key, '');
                        }
                        continue;
                    }
                    if ($meta_values_differ($row_current, $normalized_value)) {
                        $meta_changed = true;
                    }
                    $row_id = $meta_row_id($meta);
                    if ($row_id > 0) {
                        $item->update_meta_data($row_key, $normalized_value, $row_id);
                    } else {
                        $item->update_meta_data($row_key, $normalized_value);
                    }
                }
                continue;
            }

            $item->delete_meta_data($meta_key);
            if (intersoccer_migration_move_meta_value_is_blank($normalized_value)) {
                if (!$meta_values_differ($current, '')) {
                    // unchanged
                } else {
                    $meta_changed = true;
                }
                continue;
            }

            if ($meta_values_differ($current, $normalized_value)) {
                $meta_changed = true;
            }

            $item->add_meta_data($meta_key, $normalized_value, true);
        }

        if (!$meta_changed) {
            return false;
        }

        $item->save();

        if (!$restrict_to_existing_line_keys) {
            intersoccer_migration_cleanup_item_meta($item_id, $human_alias_map, $human_lookup, $existing_line_keys);
            intersoccer_migration_prune_meta_rows($item_id, $human_alias_map, $human_lookup, $existing_line_keys);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Restored canonical metadata for order item');
        }

        return true;
    }
}

if (!function_exists('intersoccer_migration_activity_type_for_girls_only_tristate')) {
    /**
     * Activity Type label from base activity text and unified girls-only resolution (storage shape).
     */
    function intersoccer_migration_activity_type_for_girls_only_tristate(string $activity_type, string $girls_only_tristate): string {
        $base = function_exists('intersoccer_canonical_activity_type_for_roster')
            ? (string) intersoccer_canonical_activity_type_for_roster($activity_type)
            : trim($activity_type);

        if ($girls_only_tristate !== 'yes') {
            return $base;
        }

        return intersoccer_migration_activity_type_with_girls_only_flag($base, 1);
    }
}

if (!function_exists('intersoccer_migration_activity_type_with_girls_only_flag')) {
    /**
     * Canonical order-item / roster activity_type when girls_only is set (base type only).
     */
    function intersoccer_migration_activity_type_with_girls_only_flag(string $activity_type, int $girls_only_flag): string {
        $activity_type = trim($activity_type);
        if ($activity_type === '' || $girls_only_flag !== 1) {
            return function_exists('intersoccer_canonical_activity_type_for_roster')
                ? (string) intersoccer_canonical_activity_type_for_roster($activity_type)
                : $activity_type;
        }

        $base = function_exists('intersoccer_canonical_activity_type_for_roster')
            ? (string) intersoccer_canonical_activity_type_for_roster($activity_type)
            : ucfirst(strtolower($activity_type));

        if ($base === 'Girls Only') {
            return 'Girls Only';
        }

        return $base;
    }
}
