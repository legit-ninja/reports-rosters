<?php
/**
 * Detects roster rows whose stored event_signature differs from the canonical generator output.
 *
 * @package InterSoccer\ReportsRosters\Services
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class SignatureDriftService {
    /** @var Logger */
    private $logger;

    /** @var EventSignatureGenerator */
    private $generator;

    public function __construct(Logger $logger = null, EventSignatureGenerator $generator = null) {
        $this->logger = $logger ?: new Logger();
        $this->generator = $generator ?: new EventSignatureGenerator();
    }

    /**
     * Build event_data array for signature generation from a DB row (same keys as RosterBuilder::rebuildSignatures).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function eventDataFromRow(array $row): array {
        return [
            'activity_type' => isset($row['activity_type']) ? (string) $row['activity_type'] : '',
            'venue' => isset($row['venue']) ? (string) $row['venue'] : '',
            'age_group' => isset($row['age_group']) ? (string) $row['age_group'] : '',
            'camp_terms' => isset($row['camp_terms']) ? (string) $row['camp_terms'] : '',
            'course_day' => isset($row['course_day']) ? (string) $row['course_day'] : '',
            'times' => isset($row['times']) ? (string) $row['times'] : '',
            'season' => isset($row['season']) ? (string) $row['season'] : '',
            'girls_only' => isset($row['girls_only']) ? (int) $row['girls_only'] : 0,
            'city' => isset($row['city']) ? (string) $row['city'] : '',
            'canton_region' => isset($row['canton_region']) ? (string) $row['canton_region'] : '',
            'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : 0,
            'start_date' => isset($row['start_date']) ? (string) $row['start_date'] : '',
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    public function expectedSignatureForRow(array $row): string {
        $event_data = self::eventDataFromRow($row);
        $sig = $this->generator->generate($event_data);

        return is_string($sig) ? $sig : '';
    }

    /**
     * @param array<string,mixed> $row
     */
    public function rowHasDrift(array $row): bool {
        $stored = isset($row['event_signature']) ? (string) $row['event_signature'] : '';
        $expected = $this->expectedSignatureForRow($row);

        if ($expected === '') {
            return false;
        }

        return $stored !== $expected;
    }

    /**
     * Scan a chunk of rows (by id) and return drift rows plus cursor for the next chunk.
     *
     * @param array{season?:string,activity_type?:string,girls_only?:string,int_cursor?:int,chunk?:int,max_drifts?:int} $args
     * @return array{drifts: array<int,array<string,mixed>>, next_cursor: int, scanned: int, exhausted: bool}
     */
    public function scanChunk(array $args = []): array {
        global $wpdb;

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $cursor = isset($args['int_cursor']) ? max(0, (int) $args['int_cursor']) : 0;
        $chunk = isset($args['chunk']) ? max(50, min(2000, (int) $args['chunk'])) : 500;
        $max_drifts = isset($args['max_drifts']) ? max(1, min(500, (int) $args['max_drifts'])) : 100;

        $where = ['1=1'];
        $params = [];

        if (!empty($args['season'])) {
            $where[] = 'season = %s';
            $params[] = (string) $args['season'];
        }
        if (!empty($args['activity_type'])) {
            $where[] = 'activity_type = %s';
            $params[] = (string) $args['activity_type'];
        }
        if (isset($args['girls_only']) && $args['girls_only'] !== '' && $args['girls_only'] !== null) {
            $where[] = 'girls_only = %d';
            $params[] = (int) $args['girls_only'];
        }

        $where_sql = implode(' AND ', $where);
        $params[] = $cursor;
        $params[] = $chunk;

        $sql = "SELECT id, order_id, order_item_id, variation_id, product_id, activity_type, venue, age_group,
                camp_terms, course_day, times, season, girls_only, city, canton_region, start_date,
                event_signature, product_name
                FROM {$table}
                WHERE {$where_sql} AND id > %d
                ORDER BY id ASC
                LIMIT %d";

        $prepared = $wpdb->prepare($sql, $params);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        if (!is_array($rows)) {
            $rows = [];
        }

        $drifts = [];
        $last_id = $cursor;
        $scanned = count($rows);

        foreach ($rows as $row) {
            $last_id = (int) ($row['id'] ?? $last_id);
            if ($this->rowHasDrift($row)) {
                $row['expected_signature'] = $this->expectedSignatureForRow($row);
                if (count($drifts) < $max_drifts) {
                    $drifts[] = $row;
                }
            }
        }

        $exhausted = $scanned < $chunk;

        return [
            'drifts' => $drifts,
            'next_cursor' => $last_id,
            'scanned' => $scanned,
            'exhausted' => $exhausted,
        ];
    }

    /**
     * Rows sharing product/variation/season/activity/girls_only but multiple distinct signatures (hint list).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSplitGroupHints(int $limit = 100): array {
        global $wpdb;

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $limit = max(1, min(500, $limit));

        $sql = "SELECT product_id, variation_id, season, activity_type, girls_only,
                COUNT(DISTINCT event_signature) AS signature_count,
                GROUP_CONCAT(DISTINCT event_signature ORDER BY event_signature SEPARATOR ',') AS signatures
                FROM {$table}
                WHERE event_signature != ''
                GROUP BY product_id, variation_id, season, activity_type, girls_only
                HAVING signature_count > 1
                ORDER BY signature_count DESC, product_id ASC
                LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param int[] $ids
     * @return array{updated:int,failed:int}
     */
    public function rebuildSignaturesForIds(array $ids): array {
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        }));
        if ($ids === []) {
            return ['updated' => 0, 'failed' => 0];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $updated = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, activity_type, venue, age_group, camp_terms, course_day, times, season,
                    girls_only, city, canton_region, product_id, start_date, event_signature
                    FROM {$table} WHERE id = %d",
                    $id
                ),
                ARRAY_A
            );
            if (!is_array($row)) {
                $failed++;
                continue;
            }
            $expected = $this->expectedSignatureForRow($row);
            if ($expected === '') {
                $failed++;
                continue;
            }
            $ok = $wpdb->update(
                $table,
                ['event_signature' => $expected],
                ['id' => $id],
                ['%s'],
                ['%d']
            );
            if ($ok === false) {
                $failed++;
            } else {
                $updated++;
            }
        }

        if ($updated > 0) {
            delete_transient('intersoccer_rosters_cache');
        }

        return ['updated' => $updated, 'failed' => $failed];
    }
}
