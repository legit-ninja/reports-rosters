<?php
/**
 * Remove roster-row keys mistakenly stored inside event_details JSON.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Services;

defined('ABSPATH') or die('Restricted access');

class EventDetailsCleanupService {
    /**
     * Keys RosterBuilder may legitimately write into event_details.
     *
     * @return list<string>
     */
    public static function legitimateEventDetailKeys(): array {
        return [
            'camp_terms',
            'camp_times',
            'booking_type',
            'selected_days',
            'days_unknown',
            'course_day',
            'course_times',
            'party_date',
            'party_time',
            'season',
            'region',
            'city',
            'discount_applied',
            'product_deleted',
            'name_not_english',
            'under_age_at_start',
            'is_buyclub',
        ];
    }

    /**
     * Roster table / build-time row keys that must not appear inside event_details.
     *
     * @return list<string>
     */
    public static function rosterRowKeysEligibleForRemoval(): array {
        $legit = array_fill_keys(self::legitimateEventDetailKeys(), true);
        $candidates = [
            'id',
            'order_id',
            'order_item_id',
            'variation_id',
            'product_id',
            'customer_id',
            'player_index',
            'first_name',
            'last_name',
            'dob',
            'gender',
            'medical_conditions',
            'dietary_needs',
            'emergency_contact',
            'emergency_phone',
            'parent_email',
            'parent_phone',
            'parent_first_name',
            'parent_last_name',
            'avs_number',
            'event_type',
            'activity_type',
            'venue',
            'age_group',
            'start_date',
            'end_date',
            'event_details',
            'camp_terms',
            'camp_times',
            'times',
            'term',
            'days_selected',
            'canton_region',
            'order_status',
            'event_signature',
            'girls_only',
            'is_placeholder',
            'product_name',
            'player_name',
            'player_first_name',
            'player_last_name',
            'player_dob',
            'player_gender',
            'player_medical',
            'player_dietary',
            'age',
            'base_price',
            'discount_amount',
            'final_price',
            'reimbursement',
            'discount_codes',
            'late_pickup',
            'late_pickup_days',
            'day_presence',
            'registration_timestamp',
            'line_subtotal',
            'line_total',
            'assigned_attendee',
            '_catalog_product_resolved',
            '_name_not_english',
            'product_deleted',
        ];

        $removal = [];
        foreach ($candidates as $key) {
            if (!isset($legit[$key])) {
                $removal[] = $key;
            }
        }

        return $removal;
    }

    /**
     * @param array<string,mixed>|string|null $raw
     * @return array{cleaned:array<string,mixed>,removed_keys:list<string>,changed:bool}
     */
    public function cleanDecodedEventDetails($raw): array {
        $details = $this->decodeEventDetails($raw);
        if ($details === []) {
            return ['cleaned' => [], 'removed_keys' => [], 'changed' => false];
        }

        // Affected rows hold the copied roster row at top level and the real event details
        // nested under 'event_details'; only is_buyclub was added on top after the copy.
        if (isset($details['event_details']) && is_array($details['event_details'])) {
            $merged = array_merge($details['event_details'], array_intersect_key($details, ['is_buyclub' => true]));
        } else {
            $merged = $details;
        }

        $cleaned = [];
        foreach (self::legitimateEventDetailKeys() as $key) {
            if (!array_key_exists($key, $merged)) {
                continue;
            }
            $value = $merged[$key];
            if ($value === null) {
                continue;
            }
            $cleaned[$key] = $value;
        }

        $removed = [];
        foreach (array_keys($details) as $key) {
            if ($key === 'event_details') {
                $removed[] = $key;
                continue;
            }
            if (!in_array($key, self::legitimateEventDetailKeys(), true)) {
                $removed[] = $key;
            }
        }
        $removed = array_values(array_unique($removed));
        sort($removed);

        $changed = $removed !== [] || $cleaned !== $this->legitSubsetFromMerged($merged);

        return [
            'cleaned' => $cleaned,
            'removed_keys' => $removed,
            'changed' => $changed,
        ];
    }

    /**
     * @param array{int_cursor?:int,chunk?:int,apply?:bool} $args
     * @return array{
     *   scanned:int,
     *   rows_would_change:int,
     *   rows_changed:int,
     *   removed_key_counts:array<string,int>,
     *   next_cursor:int,
     *   exhausted:bool
     * }
     */
    public function processBatch(array $args = []): array {
        global $wpdb;

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $cursor = isset($args['int_cursor']) ? max(0, (int) $args['int_cursor']) : 0;
        $chunk = isset($args['chunk']) ? max(50, min(2000, (int) $args['chunk'])) : 500;
        $apply = !empty($args['apply']);

        $sql = $wpdb->prepare(
            "SELECT id, event_details FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
            $cursor,
            $chunk
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            $rows = [];
        }

        $scanned = 0;
        $would_change = 0;
        $changed = 0;
        $removed_key_counts = [];
        $next_cursor = $cursor;
        $exhausted = count($rows) < $chunk;

        foreach ($rows as $row) {
            $scanned++;
            $row_id = (int) ($row['id'] ?? 0);
            $next_cursor = max($next_cursor, $row_id);
            $result = $this->cleanDecodedEventDetails($row['event_details'] ?? null);
            if (!$result['changed']) {
                continue;
            }
            $would_change++;
            foreach ($result['removed_keys'] as $key) {
                if (!isset($removed_key_counts[$key])) {
                    $removed_key_counts[$key] = 0;
                }
                $removed_key_counts[$key]++;
            }
            if ($apply) {
                $encoded = wp_json_encode($result['cleaned']);
                if ($encoded === false) {
                    continue;
                }
                $updated = $wpdb->update(
                    $table,
                    ['event_details' => $encoded],
                    ['id' => $row_id],
                    ['%s'],
                    ['%d']
                );
                if ($updated !== false) {
                    $changed++;
                }
            }
        }

        ksort($removed_key_counts);

        return [
            'scanned' => $scanned,
            'rows_would_change' => $would_change,
            'rows_changed' => $changed,
            'removed_key_counts' => $removed_key_counts,
            'next_cursor' => $next_cursor,
            'exhausted' => $exhausted || $scanned === 0,
        ];
    }

    /**
     * @param array<string,mixed>|string|null $raw
     * @return array<string,mixed>
     */
    private function decodeEventDetails($raw): array {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $merged
     * @return array<string,mixed>
     */
    private function legitSubsetFromMerged(array $merged): array {
        $subset = [];
        foreach (self::legitimateEventDetailKeys() as $key) {
            if (!array_key_exists($key, $merged) || $merged[$key] === null) {
                continue;
            }
            $subset[$key] = $merged[$key];
        }
        return $subset;
    }
}
