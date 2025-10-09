<?php
/**
 * Overview Report
 * 
 * Overview dashboard report for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Reports
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\Reports;

defined('ABSPATH') or die('Restricted access');

class OverviewReport extends AbstractReport {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
        
        $this->title = 'Overview Dashboard';
        $this->description = 'General overview of all InterSoccer activities and statistics';
        
        $this->available_filters = [
            'date_from' => 'Start Date',
            'date_to' => 'End Date',
            'venue' => 'Venue',
            'event_type' => 'Event Type'
        ];
        
        $this->columns = [
            'venue' => 'Venue',
            'event_count' => 'Total Events',
            'participant_count' => 'Total Participants',
            'revenue' => 'Total Revenue',
            'avg_age' => 'Average Age',
            'gender_distribution' => 'Gender Distribution'
        ];
    }
    
    /**
     * Generate overview report data
     * 
     * @param array $filters
     * @return array
     */
    public function generate($filters = []) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        [$where_conditions, $params] = $this->apply_filters($filters);
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        // Get venue statistics
        $venue_stats = $this->get_venue_statistics($where_clause, $params);
        
        // Get overall statistics
        $overall_stats = $this->get_overall_statistics($where_clause, $params);
        
        // Get chart data
        $chart_data = [
            'venue_distribution' => $this->get_venue_distribution($where_clause, $params),
            'age_distribution' => $this->get_age_distribution($where_clause, $params),
            'gender_distribution' => $this->get_gender_distribution($where_clause, $params),
            'monthly_trends' => $this->get_monthly_trends($where_clause, $params)
        ];
        
        $this->log_report_generation($filters, count($venue_stats));
        
        return [
            'venue_stats' => $venue_stats,
            'overall_stats' => $overall_stats,
            'chart_data' => $chart_data
        ];
    }
    
    /**
     * Get venue statistics
     * 
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    private function get_venue_statistics($where_clause, $params) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        $sql = "SELECT 
                    venue,
                    COUNT(*) as participant_count,
                    COUNT(DISTINCT event_name) as event_count,
                    SUM(order_total) as total_revenue,
                    AVG(player_age) as avg_age,
                    SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) as male_count,
                    SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) as female_count
                FROM {$rosters_table}
                {$where_clause}
                GROUP BY venue
                ORDER BY participant_count DESC";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        $results = $this->db->get_results($sql);
        
        // Format the results
        foreach ($results as &$result) {
            $result->revenue = $this->format_currency($result->total_revenue);
            $result->avg_age = round($result->avg_age, 1) . ' years';
            
            $total_gendered = $result->male_count + $result->female_count;
            if ($total_gendered > 0) {
                $male_pct = $this->calculate_percentage($result->male_count, $total_gendered);
                $female_pct = $this->calculate_percentage($result->female_count, $total_gendered);
                $result->gender_distribution = "M: {$male_pct}%, F: {$female_pct}%";
            } else {
                $result->gender_distribution = 'N/A';
            }
        }
        
        return $results;
    }
    
    /**
     * Get overall statistics
     * 
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    private function get_overall_statistics($where_clause, $params) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        $sql = "SELECT 
                    COUNT(*) as total_participants,
                    COUNT(DISTINCT event_name) as total_events,
                    COUNT(DISTINCT venue) as total_venues,
                    SUM(order_total) as total_revenue,
                    AVG(player_age) as avg_age,
                    SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) as male_count,
                    SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) as female_count,
                    SUM(CASE WHEN payment_status = 'completed' THEN 1 ELSE 0 END) as paid_count
                FROM {$rosters_table}
                {$where_clause}";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        $result = $this->db->get_row($sql);
        
        return [
            'total_participants' => intval($result->total_participants),
            'total_events' => intval($result->total_events),
            'total_venues' => intval($result->total_venues),
            'total_revenue' => $this->format_currency($result->total_revenue),
            'average_age' => round($result->avg_age, 1) . ' years',
            'male_participants' => intval($result->male_count),
            'female_participants' => intval($result->female_count),
            'paid_registrations' => intval($result->paid_count),
            'payment_rate' => $this->calculate_percentage($result->paid_count, $result->total_participants) . '%'
        ];
    }
    
    /**
     * Get venue distribution for charts
     * 
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    private function get_venue_distribution($where_clause, $params) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        $sql = "SELECT venue, COUNT(*) as count
                FROM {$rosters_table}
                {$where_clause}
                GROUP BY venue
                ORDER BY count DESC
                LIMIT 10";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        return $this->db->get_results($sql);
    }
    
    /**
     * Get age distribution for charts
     * 
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    private function get_age_distribution($where_clause, $params) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        $sql = "SELECT 
                    CASE 
                        WHEN player_age <= 6 THEN 'U7'
                        WHEN player_age <= 8 THEN 'U9'
                        WHEN player_age <= 10 THEN 'U11'
                        WHEN player_age <= 12 THEN 'U13'
                        WHEN player_age <= 14 THEN 'U15'
                        WHEN player_age <= 16 THEN 'U17'
                        WHEN player_age <= 18 THEN 'U19'
                        ELSE 'Adult'
                    END as age_group,
                    COUNT(*) as count
                FROM {$rosters_table}
                {$where_clause} AND player_age IS NOT NULL AND player_age > 0
                GROUP BY age_group
                ORDER BY 
                    CASE age_group
                        WHEN 'U7' THEN 1
                        WHEN 'U9' THEN 2
                        WHEN 'U11' THEN 3
                        WHEN 'U13' THEN 4
                        WHEN 'U15' THEN 5
                        WHEN 'U17' THEN 6
                        WHEN 'U19' THEN 7
                        WHEN 'Adult' THEN 8
                    END";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        return $this->db->get_results($sql);
    }
    
    /**
     * Get gender distribution for charts
     * 
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    private function get_gender_distribution($where_clause, $params) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        $sql = "SELECT 
                    CASE 
                        WHEN gender = 'male' THEN 'Male'
                        WHEN gender = 'female' THEN 'Female'
                        ELSE 'Other'
                    END as gender,
                    COUNT(*) as count
                FROM {$rosters_table}
                {$where_clause} AND gender IS NOT NULL AND gender != ''
                GROUP BY gender
                ORDER BY count DESC";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        return $this->db->get_results($sql);
    }
    
    /**
     * Get monthly trends for charts
     * 
     * @param string $where_clause
     * @param array $params
     * @return array
     */
    private function get_monthly_trends($where_clause, $params) {
        $rosters_table = $this->db->prefix . 'intersoccer_rosters';
        
        $sql = "SELECT 
                    DATE_FORMAT(start_date, '%Y-%m') as month,
                    COUNT(*) as participant_count,
                    SUM(order_total) as revenue
                FROM {$rosters_table}
                {$where_clause} AND start_date IS NOT NULL
                GROUP BY month
                ORDER BY month ASC
                LIMIT 12";
        
        if (!empty($params)) {
            $sql = $this->db->prepare($sql, $params);
        }
        
        $results = $this->db->get_results($sql);
        
        // Format results
        foreach ($results as &$result) {
            $result->month_name = date('M Y', strtotime($result->month . '-01'));
            $result->formatted_revenue = $this->format_currency($result->revenue);
        }
        
        return $results;
    }
    
    /**
     * Get summary statistics
     * 
     * @param array $data
     * @return array
     */
    public function get_summary($data) {
        if (!isset($data['overall_stats'])) {
            return parent::get_summary([]);
        }
        
        $stats = $data['overall_stats'];
        
        return [
            'total_participants' => $stats['total_participants'],
            'total_events' => $stats['total_events'],
            'total_venues' => $stats['total_venues'],
            'total_revenue' => $stats['total_revenue'],
            'average_age' => $stats['average_age'],
            'payment_rate' => $stats['payment_rate'],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
}