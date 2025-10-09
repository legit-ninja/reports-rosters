<?php
/**
 * Camp Report
 * 
 * Camp-specific report for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Reports
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\Reports;

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
        
        // Add camp-specific filter
        [$where_conditions, $params] = $this->apply_filters($filters);
        $where_conditions[] = "(product_type LIKE '%camp%' OR product_name LIKE '%camp%' OR product_name LIKE '%summer%')";
        
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
        
        $sql = "SELECT 
                    event_name,
                    venue,
                    MIN(start_date) as start_date,
                    MAX(end_date) as end_date,
                    COUNT(*) as participant_count,
                    MIN(player_age) as min_age,
                    MAX(player_age) as max_age,
                    AVG(player_age) as avg_age,
                    SUM(order_total) as total_revenue,
                    SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) as male_count,
                    SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) as female_count,
                    SUM(CASE WHEN payment_status = 'completed' THEN 1 ELSE 0 END) as paid_count
                FROM {$rosters_table}
                {$where_clause}
                GROUP BY event_name, venue
                ORDER BY start_date ASC, event_name";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        $results = $this->db->get_results($sql);
        
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
                    COUNT(DISTINCT event_name) as total_camps,
                    COUNT(*) as total_participants,
                    COUNT(DISTINCT venue) as venues_used,
                    SUM(order_total) as total_revenue,
                    AVG(order_total) as avg_camp_price,
                    AVG(player_age) as avg_participant_age,
                    MIN(start_date) as earliest_camp,
                    MAX(end_date) as latest_camp
                FROM {$rosters_table}
                {$where_clause}";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        $result = $this->db->get_row($sql);
        
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
            'total_camps' => intval($result->total_camps),
            'total_participants' => intval($result->total_participants),
            'venues_used' => intval($result->venues_used),
            'total_revenue' => $this->format_currency($result->total_revenue),
            'average_camp_price' => $this->format_currency($result->avg_camp_price),
            'average_participant_age' => round($result->avg_participant_age, 1) . ' years',
            'camp_season' => $this->format_date($result->earliest_camp) . ' to ' . $this->format_date($result->latest_camp),
            'most_popular_venue' => $popular_venue ? $popular_venue->venue . ' (' . $popular_venue->count . ' participants)' : 'N/A'
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
                          WEEK(start_date) as week_number,
                          YEAR(start_date) as year,
                          COUNT(*) as participants
                       FROM {$rosters_table}
                       {$where_clause} AND start_date IS NOT NULL
                       GROUP BY year, week_number
                       ORDER BY year, week_number";
        
        if (!empty($params)) {
            $weekly_sql = $this->db->prepare($weekly_sql, $params);
        }
        
        $weekly_attendance = $this->db->get_results($weekly_sql);
        
        // Age group distribution
        $age_distribution_sql = "SELECT 
                                    CASE 
                                        WHEN player_age <= 6 THEN 'U7'
                                        WHEN player_age <= 8 THEN 'U9'
                                        WHEN player_age <= 10 THEN 'U11'
                                        WHEN player_age <= 12 THEN 'U13'
                                        WHEN player_age <= 14 THEN 'U15'
                                        ELSE '15+'
                                    END as age_group,
                                    COUNT(*) as count
                                 FROM {$rosters_table}
                                 {$where_clause} AND player_age IS NOT NULL
                                 GROUP BY age_group
                                 ORDER BY count DESC";
        
        if (!empty($params)) {
            $age_distribution_sql = $this->db->prepare($age_distribution_sql, $params);
        }
        
        $age_distribution = $this->db->get_results($age_distribution_sql);
        
        return [
            'weekly_attendance' => $weekly_attendance,
            'age_distribution' => $age_distribution
        ];
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
}