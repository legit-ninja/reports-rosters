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
        $slug = '';
        if (function_exists('intersoccer_migration_pa_attribute_slug_for_variation')) {
            $slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, $pa_taxonomy);
        }
        if ($slug === '') {
            $slug = intersoccer_migration_slug_meta_pa_value($slug_meta, $pa_taxonomy);
        }
        if ($slug !== '' && function_exists('intersoccer_migration_default_language_term_slug')) {
            $slug = intersoccer_migration_default_language_term_slug($pa_taxonomy, $slug);
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

            $slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, $taxonomy);
            if ($slug === '') {
                if ($facet === 'times') {
                    $slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, 'pa_course-times');
                }
                if ($slug === '') {
                    continue;
                }
                $taxonomy = 'pa_course-times';
            }

            $en_slug = intersoccer_migration_default_language_term_slug($taxonomy, $slug);
            if ($en_slug === '') {
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

        $slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, 'pa_booking-type');
        if ($slug === '') {
            return $booking;
        }

        $en_slug = intersoccer_migration_default_language_term_slug('pa_booking-type', $slug);
        if ($en_slug === '') {
            $en_slug = $slug;
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

        if ($term && !is_wp_error($term)) {
            $en_id = intersoccer_migration_term_id_in_default_language((int) $term->term_id, $taxonomy);
            $en_term = function_exists('get_term') ? get_term($en_id, $taxonomy) : $term;
            if ($en_term && !is_wp_error($en_term) && !empty($en_term->slug)) {
                return (string) $en_term->slug;
            }

            return (string) $term->slug;
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

if (!function_exists('intersoccer_migration_pa_attribute_slug_for_variation')) {
    /**
     * Term slug WooCommerce stores for a variation attribute (variation attrs first, then parent).
     */
    function intersoccer_migration_pa_attribute_slug_for_variation(WC_Product_Variation $variation, string $taxonomy): string {
        $taxonomy = str_replace('attribute_', '', $taxonomy);

        foreach ($variation->get_variation_attributes() as $attribute_key => $attribute_value) {
            $clean_key = str_replace('attribute_', '', (string) $attribute_key);
            if ($clean_key !== $taxonomy) {
                continue;
            }
            $raw = is_array($attribute_value) ? (string) reset($attribute_value) : (string) $attribute_value;

            return intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $raw);
        }

        if (method_exists($variation, 'get_attribute')) {
            $from_variation = $variation->get_attribute($taxonomy);
            if ($from_variation !== '' && $from_variation !== null) {
                return intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, (string) $from_variation);
            }
        }

        $parent_id = (int) $variation->get_parent_id();
        if ($parent_id > 0 && function_exists('wc_get_product')) {
            $parent = wc_get_product($parent_id);
            if ($parent && method_exists($parent, 'get_attribute')) {
                $from_parent = $parent->get_attribute($taxonomy);
                if ($from_parent !== '' && $from_parent !== null) {
                    return intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, (string) $from_parent);
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
     * Bytes when the target term differs from the line: match slug vs display form per key; missing → slug.
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

        if (intersoccer_migration_pa_line_value_uses_slug_form($taxonomy, $existing_on_key)) {
            return $slug;
        }

        return intersoccer_migration_pa_target_term_display_for_variation($taxonomy, $variation, $slug);
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
            $slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, $taxonomy);
            if ($slug === '') {
                $slug = intersoccer_migration_coerce_pa_taxonomy_slug(
                    $taxonomy,
                    is_array($meta_value) ? (string) reset($meta_value) : (string) $meta_value
                );
            }
            if ($slug === '') {
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
        $value = intersoccer_migration_coerce_pa_taxonomy_slug($taxonomy, $value);

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
