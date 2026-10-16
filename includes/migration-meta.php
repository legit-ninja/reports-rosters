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
    function intersoccer_migration_cleanup_item_meta($item_id, array $alias_map, array $lookup) {
        $item = new WC_Order_Item_Product($item_id);
        $meta_data = array_reverse($item->get_meta_data());
        $seen = [];

        foreach ($meta_data as $meta) {
            $normalized = intersoccer_migration_normalize_meta_key($meta->key);
            if (!isset($lookup[$normalized])) {
                continue;
            }
            $canonical = $lookup[$normalized];
            if (isset($seen[$canonical])) {
                wc_delete_order_item_meta($item_id, $meta->key, $meta->value);
                continue;
            }
            $seen[$canonical] = $meta->key;
        }
    }
}

if (!function_exists('intersoccer_migration_prune_meta_rows')) {
    /**
     * Ensure only one row per canonical field remains at the database level.
     */
    function intersoccer_migration_prune_meta_rows($item_id, array $alias_map, array $lookup) {
        global $wpdb;

        $canonical_to_keys = [];
        foreach ($alias_map as $canonical => $aliases) {
            $canonical_to_keys[$canonical] = array_unique(array_merge([$canonical], $aliases));
        }

        $table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        foreach ($canonical_to_keys as $canonical => $keys) {
            $placeholders = implode(',', array_fill(0, count($keys), '%s'));
            $params = array_merge([$item_id], $keys);

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, meta_key, meta_value FROM {$table} WHERE order_item_id = %d AND meta_key IN ($placeholders) ORDER BY meta_id ASC",
                $params
            )) ?: [];

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
            if (function_exists('intersoccer_get_cached_term_in_default_language')) {
                $term = intersoccer_get_cached_term_in_default_language($value, $taxonomy);
                if ($term && !is_wp_error($term)) {
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
     * Raw pa_* / attribute_pa_* bytes on the line for a slug_meta key (paired keys fall back).
     *
     * @param array<string,string> $existing_line_pa
     */
    function intersoccer_migration_pa_existing_line_value_for_key(array $existing_line_pa, string $meta_key): string {
        if (isset($existing_line_pa[$meta_key]) && $existing_line_pa[$meta_key] !== '') {
            return (string) $existing_line_pa[$meta_key];
        }
        if (preg_match('/^attribute_(pa_.+)$/i', $meta_key, $matches)) {
            $paired = $matches[1];
            if (isset($existing_line_pa[$paired]) && $existing_line_pa[$paired] !== '') {
                return (string) $existing_line_pa[$paired];
            }
        } elseif (preg_match('/^pa_/i', $meta_key)) {
            $paired = 'attribute_' . $meta_key;
            if (isset($existing_line_pa[$paired]) && $existing_line_pa[$paired] !== '') {
                return (string) $existing_line_pa[$paired];
            }
        }

        return '';
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

        foreach ($normalized as $meta_key => $slug) {
            if (!preg_match('/^pa_/i', (string) $meta_key)) {
                continue;
            }
            $taxonomy = (string) $meta_key;
            $attribute_key = 'attribute_' . $meta_key;
            $target_slug = intersoccer_migration_pa_attribute_slug_for_variation($variation, $taxonomy);
            if ($target_slug === '') {
                $target_slug = is_string($slug) ? $slug : '';
            }
            $existing_attr = intersoccer_migration_pa_existing_line_value_for_key($existing_line_pa, $attribute_key);
            if ($existing_attr !== '' && $target_slug !== ''
                && intersoccer_migration_pa_stored_value_same_term_as_slug($taxonomy, $existing_attr, $target_slug)) {
                $normalized[$attribute_key] = $existing_attr;
            } elseif (!isset($normalized[$attribute_key]) || $normalized[$attribute_key] === '') {
                $normalized[$attribute_key] = $normalized[$meta_key];
            } elseif ($target_slug !== ''
                && !intersoccer_migration_pa_stored_value_same_term_as_slug(
                    $taxonomy,
                    (string) $normalized[$attribute_key],
                    $target_slug
                )) {
                $normalized[$attribute_key] = $normalized[$meta_key];
            } elseif (($existing_line_pa[$meta_key] ?? '') !== ''
                && intersoccer_migration_pa_stored_value_same_term_as_slug(
                    $taxonomy,
                    (string) $existing_line_pa[$meta_key],
                    $target_slug
                )) {
                $normalized[$attribute_key] = $normalized[$meta_key];
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
        foreach ($variation->get_variation_attributes() as $attribute_key => $attribute_value) {
            $allowed[(string) $attribute_key] = true;
            $clean_key = str_replace('attribute_', '', (string) $attribute_key);
            $allowed[$clean_key] = true;
        }

        if (function_exists('intersoccer_migration_pa_taxonomy_keys_for_move')
            && method_exists($variation, 'get_attribute')) {
            foreach (intersoccer_migration_pa_taxonomy_keys_for_move() as $taxonomy) {
                $from_variation = $variation->get_attribute($taxonomy);
                if ($from_variation === '' || $from_variation === null) {
                    continue;
                }
                $allowed[$taxonomy] = true;
                $allowed['attribute_' . $taxonomy] = true;
            }
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
     * @return array{human: array<string,string>, other: array<string,string>}
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
            $value = is_array($data['value'] ?? null)
                ? implode(', ', array_map('trim', $data['value']))
                : trim((string) ($data['value'] ?? ''));
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
     * @param array<string,string> $other_meta
     * @param array<int,string>    $existing_line_keys
     * @return array<string,string>
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
        if (isset($allowed['Girls Only'])) {
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
        WC_Product_Variation $variation
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
     */
    function intersoccer_migration_restore_canonical_meta(
        $item_id,
        array $human_alias_map,
        array $human_lookup,
        array $human_meta,
        array $other_meta,
        array $existing_line_keys = []
    ) {
        $item = new WC_Order_Item_Product($item_id);

        foreach ($human_meta as $canonical => $meta_value) {
            $normalized_value = is_array($meta_value)
                ? implode(', ', array_map('trim', $meta_value))
                : trim((string) $meta_value);

            if ($normalized_value === '') {
                continue;
            }

            $aliases = $human_alias_map[$canonical] ?? [$canonical];
            foreach ($aliases as $alias_key) {
                $item->delete_meta_data($alias_key);
            }

            $label = function_exists('intersoccer_migration_pick_meta_label_for_canonical')
                ? intersoccer_migration_pick_meta_label_for_canonical($canonical, $human_alias_map, $existing_line_keys)
                : $aliases[0];
            $item->add_meta_data($label, $normalized_value, true);
        }

        foreach ($other_meta as $meta_key => $meta_value) {
            $normalized_value = is_array($meta_value)
                ? implode(', ', array_map('trim', $meta_value))
                : trim((string) $meta_value);

            $item->delete_meta_data($meta_key);
            if ($normalized_value === '') {
                continue;
            }

            $item->add_meta_data($meta_key, $normalized_value, true);
        }

        $item->save();

        intersoccer_migration_cleanup_item_meta($item_id, $human_alias_map, $human_lookup);
        intersoccer_migration_prune_meta_rows($item_id, $human_alias_map, $human_lookup);

        error_log('InterSoccer: Restored canonical metadata for item ' . $item_id);
    }
}
