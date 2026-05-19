<?php
/**
 * Shared order-item metadata aliases for migration and language repair.
 *
 * @package InterSoccer\ReportsRosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_migration_human_alias_map')) {
    /**
     * Map canonical metadata identifiers to their human-readable key variants.
     *
     * @return array<string,array<int,string>>
     */
    function intersoccer_migration_human_alias_map() {
        return [
            'intersoccer_venues' => [
                'InterSoccer Venues',
                'Sites InterSoccer',
                'Lieux InterSoccer',
            ],
            'age_group' => [
                'Age Group',
                "Groupe d'âge",
                'Groupe dage',
            ],
            'camp_terms' => [
                'Camp Terms',
                'Conditions du camp',
            ],
            'course_day' => [
                'Course Day',
                'Jour de cours',
            ],
            'course_times' => [
                'Course Times',
                'Horaires du cours',
            ],
            'camp_times' => [
                'Camp Times',
                'Horaires du camp',
            ],
            'activity_type' => [
                'Activity Type',
                "Type d'activité",
            ],
            'season' => [
                'Season',
                'Saison',
            ],
            'booking_type' => [
                'Booking Type',
                'Type de réservation',
                'Buchungstyp',
            ],
            'selected_days' => [
                'Days Selected',
                'Jours sélectionnés',
                'Ausgewählte Tage',
            ],
            'canton_region' => [
                'Canton / Region',
                'Canton / Région',
            ],
            'city' => [
                'City',
                'Ville',
            ],
        ];
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
        array $other_meta
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

            $item->add_meta_data($aliases[0], $normalized_value, true);
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
