<?php
/**
 * Camp Report
 * 
 * Camp-specific report for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccer\ReportsRosters\Reports
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Reports;

defined('ABSPATH') or die('Restricted access');

class CampReport extends AbstractReport {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
        
        $this->title = 'Camp Activities Report';
        $this->description = 'Detailed report focusing on camp activities and participants';
        
        $this->available_filters = [
            'date_from' => 'Start Date',
            'date_to' => 'End Date',
            'venue' => 'Venue',
            'age_group' => 'Age Group',
            'gender' => 'Gender'
        ];
        
        $this->columns = [
            'event_name' => 'Camp Name',
            'venue' => 'Venue',
            'date_range' => 'Dates',
            'participant_count' => 'Participants',
            'age_range' => 'Age Range',
            'revenue' => 'Revenue',
            'capacity_utilization' => 'Capacity %'
        ];
    }
    
    /**
     * Generate camp report data
     * 
     * @param array $filters
     * @return array
     */
    public function generate($filters = []) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        // event_type and payment_status filter on columns the roster table does not have
        // (product_type, payment_status); this report does not offer them.
        unset($filters['event_type'], $filters['payment_status']);
        [$where_conditions, $params] = $this->apply_filters($filters);
        // Camps: activity type or product name. Literal percent signs cannot sit in a string passed
        // to prepare(), so the LIKE patterns are %s arguments and %camp%/%summer% stay wildcards.
        $where_conditions[] = '(activity_type LIKE %s OR product_name LIKE %s OR product_name LIKE %s)';
        $params[] = '%camp%';
        $params[] = '%camp%';
        $params[] = '%summer%';
        // Placeholder rows hold a roster slot, not a participant.
        $where_conditions[] = 'COALESCE(is_placeholder, 0) = 0';
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // Get camp details
        $camps = $this->get_camp_details($where_clause, $params);
        
        // Get camp statistics
        $statistics = $this->get_camp_statistics($where_clause, $params);
        
        // Get attendance patterns
        $attendance_patterns = $this->get_attendance_patterns($where_clause, $params);
        
        $this->log_report_generation($filters, count($camps));
        
        return [
            'camps' => $camps,
            'statistics' => $statistics,
            'attendance_patterns' => $attendance_patterns
        ];
    }
    
    /**
     * Get detailed camp information
     * 
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    private function get_camp_details($where_clause, $params) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        // Roster columns: product_name (camp name), age, final_price, player_gender/gender, order_status.
        $gender = "LOWER(COALESCE(NULLIF(player_gender, ''), gender))";
        $sql = "SELECT 
                    product_name AS event_name,
                    venue,
                    MIN(start_date) as start_date,
                    MAX(end_date) as end_date,
                    COUNT(*) as participant_count,
                    MIN(NULLIF(age, 0)) as min_age,
                    MAX(NULLIF(age, 0)) as max_age,
                    AVG(NULLIF(age, 0)) as avg_age,
                    SUM(final_price) as total_revenue,
                    SUM(CASE WHEN {$gender} = 'male' THEN 1 ELSE 0 END) as male_count,
                    SUM(CASE WHEN {$gender} = 'female' THEN 1 ELSE 0 END) as female_count,
                    SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) as paid_count
                FROM {$rosters_table}
                {$where_clause}
                GROUP BY product_name, venue
                ORDER BY start_date ASC, event_name";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        $results = $this->db->get_results($sql);
        if (!is_array($results)) {
            $this->log_query_failure('camp_details');
            return [];
        }
        
        // Format results
        foreach ($results as &$camp) {
            $camp->date_range = $this->format_date($camp->start_date) . 
                               ($camp->end_date && $camp->end_date != $camp->start_date ? 
                                ' - ' . $this->format_date($camp->end_date) : '');
            
            if ($camp->min_age && $camp->max_age) {
                $camp->age_range = $camp->min_age . '-' . $camp->max_age . ' years (avg: ' . round($camp->avg_age, 1) . ')';
            } else {
                $camp->age_range = 'N/A';
            }
            
            $camp->revenue = $this->format_currency($camp->total_revenue);
            $camp->payment_rate = $this->calculate_percentage($camp->paid_count, $camp->participant_count) . '%';
            
            // Gender distribution
            $total_gendered = $camp->male_count + $camp->female_count;
            if ($total_gendered > 0) {
                $male_pct = $this->calculate_percentage($camp->male_count, $total_gendered);
                $female_pct = $this->calculate_percentage($camp->female_count, $total_gendered);
                $camp->gender_split = "M: {$male_pct}%, F: {$female_pct}%";
            } else {
                $camp->gender_split = 'N/A';
            }
        }
        
        return $results;
    }
    
    /**
     * Get camp statistics
     * 
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    private function get_camp_statistics($where_clause, $params) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        $sql = "SELECT 
                    COUNT(DISTINCT product_name) as total_camps,
                    COUNT(*) as total_participants,
                    COUNT(DISTINCT venue) as venues_used,
                    SUM(final_price) as total_revenue,
                    AVG(final_price) as avg_camp_price,
                    AVG(NULLIF(age, 0)) as avg_participant_age,
                    MIN(start_date) as earliest_camp,
                    MAX(end_date) as latest_camp
                FROM {$rosters_table}
                {$where_clause}";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        $result = $this->db->get_row($sql);
        if (!is_object($result)) {
            $this->log_query_failure('camp_statistics');
            $result = (object) [
                'total_camps' => 0,
                'total_participants' => 0,
                'venues_used' => 0,
                'total_revenue' => 0,
                'avg_camp_price' => 0,
                'avg_participant_age' => null,
                'earliest_camp' => null,
                'latest_camp' => null,
            ];
        }
        
        // Get most popular venue
        $popular_venue_sql = "SELECT venue, COUNT(*) as count
                             FROM {$rosters_table}
                             {$where_clause}
                             GROUP BY venue
                             ORDER BY count DESC
                             LIMIT 1";
        
        if (!empty($params)) {
            $popular_venue_sql = $this->db->prepare($popular_venue_sql, $params);
        }
        
        $popular_venue = $this->db->get_row($popular_venue_sql);
        
        return [
            'total_camps' => (int) ($result->total_camps ?? 0),
            'total_participants' => (int) ($result->total_participants ?? 0),
            'venues_used' => (int) ($result->venues_used ?? 0),
            'total_revenue' => $this->format_currency($result->total_revenue ?? 0),
            'average_camp_price' => $this->format_currency($result->avg_camp_price ?? 0),
            'average_participant_age' => ($result->avg_participant_age ?? null) !== null
                ? round((float) $result->avg_participant_age, 1) . ' years'
                : 'N/A',
            'camp_season' => (!empty($result->earliest_camp) && !empty($result->latest_camp))
                ? $this->format_date($result->earliest_camp) . ' to ' . $this->format_date($result->latest_camp)
                : 'N/A',
            'most_popular_venue' => is_object($popular_venue) && isset($popular_venue->venue)
                ? $popular_venue->venue . ' (' . (int) ($popular_venue->count ?? 0) . ' participants)'
                : 'N/A'
        ];
    }
    
    /**
     * Get attendance patterns
     * 
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    private function get_attendance_patterns($where_clause, $params) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        // Weekly attendance pattern
        $weekly_sql = "SELECT 
                          CASE
                            WHEN start_date IS NULL OR start_date IN ('', '1970-01-01', '0000-00-00') THEN NULL
                            ELSE WEEK(start_date)
                          END as week_number,
                          CASE
                            WHEN start_date IS NULL OR start_date IN ('', '1970-01-01', '0000-00-00') THEN NULL
                            ELSE YEAR(start_date)
                          END as year,
                          COUNT(*) as participants
                       FROM {$rosters_table}
                       {$where_clause}
                       GROUP BY year, week_number
                       ORDER BY year, week_number";
        
        if (!empty($params)) {
            $weekly_sql = $this->db->prepare($weekly_sql, $params);
        }
        
        $weekly_attendance = $this->db->get_results($weekly_sql);
        if (!is_array($weekly_attendance)) {
            $this->log_query_failure('camp_weekly_attendance');
            $weekly_attendance = [];
        }

        $no_date_label = __('No date', 'intersoccer-reports-rosters');
        foreach ($weekly_attendance as &$row) {
            if ($row->week_number === null || $row->week_number === ''
                || $row->year === null || $row->year === '') {
                $row->week_label = $no_date_label;
            } else {
                $row->week_label = sprintf(
                    __('Week %1$s, %2$s', 'intersoccer-reports-rosters'),
                    $row->week_number,
                    $row->year
                );
            }
        }
        unset($row);
        
        // Age group distribution
        $age_distribution_sql = "SELECT 
                                    CASE 
                                        WHEN age <= 6 THEN 'U7'
                                        WHEN age <= 8 THEN 'U9'
                                        WHEN age <= 10 THEN 'U11'
                                        WHEN age <= 12 THEN 'U13'
                                        WHEN age <= 14 THEN 'U15'
                                        ELSE '15+'
                                    END as age_band,
                                    COUNT(*) as count
                                 FROM {$rosters_table}
                                 {$where_clause} AND age IS NOT NULL AND age > 0
                                 GROUP BY age_band
                                 ORDER BY count DESC";
        
        if (!empty($params)) {
            $age_distribution_sql = $this->db->prepare($age_distribution_sql, $params);
        }
        
        $age_distribution = $this->db->get_results($age_distribution_sql);
        if (!is_array($age_distribution)) {
            $this->log_query_failure('camp_age_distribution');
            $age_distribution = [];
        }
        // Keep the age_group key callers read.
        foreach ($age_distribution as $band_row) {
            if (is_object($band_row) && isset($band_row->age_band) && !isset($band_row->age_group)) {
                $band_row->age_group = $band_row->age_band;
            }
        }
        
        return [
            'weekly_attendance' => $weekly_attendance,
            'age_distribution' => $age_distribution
        ];
    }
    
    /**
     * Log a failed report query once, with the database error (no personal data).
     */
    private function log_query_failure(string $query): void {
        $error = isset($this->db->last_error) ? (string) $this->db->last_error : '';
        if (function_exists('error_log')) {
            error_log('InterSoccer CampReport: ' . $query . ' query failed' . ($error !== '' ? ': ' . $error : ''));
        }
    }

    /**
     * Get summary statistics for camps
     * 
     * @param array $data
     * @return array
     */
    public function get_summary($data) {
        if (!isset($data['statistics'])) {
            return parent::get_summary([]);
        }
        
        $stats = $data['statistics'];
        
        return [
            'total_camps' => $stats['total_camps'],
            'total_participants' => $stats['total_participants'],
            'total_revenue' => $stats['total_revenue'],
            'average_camp_price' => $stats['average_camp_price'],
            'average_age' => $stats['average_participant_age'],
            'most_popular_venue' => $stats['most_popular_venue'],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Filter report data by date range
     * 
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return array Filtered data
     */
    public function filterByDateRange($start_date, $end_date) {
        $filters = [
            'date_from' => $start_date,
            'date_to' => $end_date
        ];
        
        return $this->generate($filters);
    }
}