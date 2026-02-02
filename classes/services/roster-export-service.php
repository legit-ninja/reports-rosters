<?php
/**
 * Roster Export Service
 *
 * Provides filtered roster datasets for CSV / XLS exports.
 *
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;

defined('ABSPATH') or die('Restricted access');

class RosterExportService {

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Database
     */
    private $database;

    /**
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Constructor.
     */
    public function __construct(Logger $logger = null, Database $database = null) {
        $this->logger = $logger ?: new Logger();
        $this->database = $database ?: new Database($this->logger);
        $this->wpdb = $this->database->get_wpdb();
    }

    /**
     * Retrieve roster rows for export based on provided filters.
     *
     * @param array $filters Export filters (see intersoccer_export_roster for available keys).
     * @return array
     */
    public function getExportRows(array $filters): array {
        $use_fields = !empty($filters['use_fields']);

        if ($use_fields) {
            return $this->fetchUsingFieldFilters($filters);
        }

        return $this->fetchUsingVariations($filters);
    }

    /**
     * Fetch rows using the field-based filtering (used by roster details exports).
     */
    private function fetchUsingFieldFilters(array $filters): array {
        $table = $this->wpdb->prefix . 'intersoccer_rosters';

        $variation_id   = isset($filters['variation_id']) ? (int) $filters['variation_id'] : 0;
        $variation_ids  = isset($filters['variation_ids']) ? array_filter(array_map('intval', (array) $filters['variation_ids'])) : [];
        $order_item_ids = isset($filters['order_item_ids']) ? array_filter(array_map('intval', (array) $filters['order_item_ids'])) : [];
        $event_signature = $filters['event_signature'] ?? '';
        $product_id     = isset($filters['product_id']) ? (int) $filters['product_id'] : 0;
        $camp_terms     = $filters['camp_terms'] ?? '';
        $course_day     = $filters['course_day'] ?? '';
        $venue          = $filters['venue'] ?? '';
        $age_group      = $filters['age_group'] ?? '';
        $times          = $filters['times'] ?? '';
        $girls_only     = isset($filters['girls_only']) ? (int) $filters['girls_only'] : 0;
        $activity_types = isset($filters['activity_types']) ? array_filter((array) $filters['activity_types']) : [];

        $where = [];
        $params = [];

        if ($variation_id > 0) {
            $where[] = 'variation_id = %d';
            $params[] = $variation_id;
        } elseif (!empty($order_item_ids)) {
            $placeholders = implode(',', array_fill(0, count($order_item_ids), '%d'));
            $where[] = "order_item_id IN ({$placeholders})";
            $params = array_merge($params, $order_item_ids);
        } elseif (!empty($event_signature)) {
            $where[] = 'event_signature = %s';
            $params[] = $event_signature;
        } elseif (!empty($variation_ids)) {
            $placeholders = implode(',', array_fill(0, count($variation_ids), '%d'));
            $where[] = "variation_id IN ({$placeholders})";
            $params = array_merge($params, $variation_ids);
        } else {
            if (!empty($activity_types)) {
                $like_conditions = [];
                foreach ($activity_types as $type) {
                    $like_conditions[] = 'activity_type LIKE %s';
                    $params[] = '%' . $this->wpdb->esc_like($type) . '%';
                }

                if (in_array("girls' only", $activity_types, true) || in_array("girls\' only", $activity_types, true)) {
                    $where[] = 'activity_type LIKE %s';
                    $params[] = "%girls' only%";
                } else {
                    $where[] = '(' . implode(' OR ', $like_conditions) . ')';
                }
            }

            if ($product_id > 0) {
                $where[] = 'product_id = %d';
                $params[] = $product_id;
            }

            if (!empty($camp_terms)) {
                $where[] = '(camp_terms = %s OR camp_terms LIKE %s OR (camp_terms IS NULL AND %s = "N/A"))';
                $params[] = $camp_terms;
                $params[] = '%' . $this->wpdb->esc_like($camp_terms) . '%';
                $params[] = $camp_terms;
            }

            if (!empty($course_day)) {
                $where[] = '(course_day = %s OR course_day LIKE %s OR (course_day IS NULL AND %s = "N/A"))';
                $params[] = $course_day;
                $params[] = '%' . $this->wpdb->esc_like($course_day) . '%';
                $params[] = $course_day;
            }

            if (!empty($venue)) {
                $where[] = '(venue = %s OR venue LIKE %s OR (venue IS NULL AND %s = "N/A"))';
                $params[] = $venue;
                $params[] = '%' . $this->wpdb->esc_like($venue) . '%';
                $params[] = $venue;
            }

            if (!empty($age_group)) {
                $where[] = '(age_group = %s OR age_group LIKE %s)';
                $params[] = $age_group;
                $params[] = '%' . $this->wpdb->esc_like($age_group) . '%';
            }

            if (!empty($times)) {
                $where[] = '(times = %s OR (times IS NULL AND %s = "N/A"))';
                $params[] = $times;
                $params[] = $times;
            }

            if ($girls_only) {
                $where[] = 'girls_only = %d';
                $params[] = $girls_only;
            }
        }

        $sql = "SELECT player_name, first_name, last_name, gender, parent_phone, parent_email, age, player_dob, medical_conditions, late_pickup, late_pickup_days, booking_type, day_presence, age_group, activity_type, product_name, product_id, camp_terms, course_day, venue, times, shirt_size, shorts_size, avs_number FROM {$table}";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY registration_timestamp DESC';

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        
        // Log SQL errors if any
        if ($this->wpdb->last_error) {
            $this->logger->error('RosterExportService: SQL error in fetchUsingFieldFilters', [
                'error' => $this->wpdb->last_error,
                'query' => $sql,
                'params' => $params,
            ]);
        }

        $row_count = is_array($rows) ? count($rows) : 0;
        $this->logger->debug('RosterExportService: Fetched rows via field filters', [
            'count' => $row_count,
            'filters' => $filters,
            'sql' => $sql,
            'last_error' => $this->wpdb->last_error,
        ]);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Fetch rows using explicit variation IDs or event signature.
     */
    private function fetchUsingVariations(array $filters): array {
        $table = $this->wpdb->prefix . 'intersoccer_rosters';

        $variation_ids  = isset($filters['variation_ids']) ? array_filter(array_map('intval', (array) $filters['variation_ids'])) : [];
        $event_signature = $filters['event_signature'] ?? '';
        $product_id     = isset($filters['product_id']) ? (int) $filters['product_id'] : 0;
        $age_group      = $filters['age_group'] ?? '';

        $sql = "SELECT player_name, first_name, last_name, gender, parent_phone, parent_email, age, player_dob, medical_conditions, late_pickup, late_pickup_days, booking_type, day_presence, age_group, activity_type, product_name, product_id, camp_terms, course_day, venue, times, shirt_size, shorts_size, avs_number FROM {$table}";
        $params = [];
        $where = [];

        if (!empty($event_signature)) {
            $where[] = 'event_signature = %s';
            $params[] = $event_signature;
        } elseif (!empty($variation_ids)) {
            $placeholders = implode(',', array_fill(0, count($variation_ids), '%d'));
            $where[] = "variation_id IN ({$placeholders})";
            $params = array_merge($params, $variation_ids);
        } else {
            $this->logger->warning('RosterExportService: No variation IDs or event signature supplied for export.');
            return [];
        }

        if ($product_id > 0) {
            $where[] = 'product_id = %d';
            $params[] = $product_id;
        }

        if (!empty($age_group)) {
            $where[] = '(age_group = %s OR age_group LIKE %s)';
            $params[] = $age_group;
            $params[] = '%' . $this->wpdb->esc_like($age_group) . '%';
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY registration_timestamp DESC';

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        
        // Log SQL errors if any
        if ($this->wpdb->last_error) {
            $this->logger->error('RosterExportService: SQL error in fetchUsingVariations', [
                'error' => $this->wpdb->last_error,
                'query' => $sql,
                'params' => $params,
            ]);
        }

        $this->logger->debug('RosterExportService: Fetched rows via variation filters', [
            'count' => is_array($rows) ? count($rows) : 0,
            'filters' => $filters,
            'sql' => $sql,
            'last_error' => $this->wpdb->last_error,
        ]);

        return is_array($rows) ? $rows : [];
    }
}


