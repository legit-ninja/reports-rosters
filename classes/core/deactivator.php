<?php
/**
 * Deactivator Class
 * 
 * Handles plugin deactivation for InterSoccer Reports & Rosters.
 * Cleans up temporary data and scheduled tasks.
 * 
 * @package InterSoccer\ReportsRosters\Core
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deactivator Class
 * 
 * Manages plugin deactivation process
 */
class Deactivator {
    
    /**
     * Database instance
     * 
     * @var Database
     */
    private $database;
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Constructor
     * 
     * @param Database $database Database instance
     * @param Logger $logger Logger instance
     */
    public function __construct(Database $database, Logger $logger) {
        $this->database = $database;
        $this->logger = $logger;
    }
    
    /**
     * Run plugin deactivation
     * 
     * @return bool Success status
     */
    public function deactivate() {
        try {
            $this->logger->info('Running deactivation tasks');
            
            // Unschedule cron jobs
            $this->unschedule_cron_jobs();
            
            // Clear transients
            $this->clear_transients();
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
            $this->logger->info('Deactivation completed successfully');
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Deactivation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Unschedule cron jobs
     * 
     * @return void
     */
    private function unschedule_cron_jobs() {
        $hooks = [
            'intersoccer_cleanup_logs',
            'intersoccer_cache_cleanup',
            'intersoccer_roster_rebuild'
        ];
        
        foreach ($hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                $this->logger->debug('Unscheduled cron hook', ['hook' => $hook]);
            }
        }
    }
    
    /**
     * Clear plugin transients
     * 
     * @return void
     */
    private function clear_transients() {
        global $wpdb;
        
        if (!$wpdb) {
            return;
        }
        
        // Delete all intersoccer transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_intersoccer_%' 
             OR option_name LIKE '_transient_timeout_intersoccer_%'"
        );
        
        $this->logger->debug('Cleared plugin transients');
    }
    
    /**
     * Get deactivation status
     * 
     * @return array Deactivation status
     */
    public function get_status() {
        return [
            'deactivated' => true,
            'timestamp' => time(),
            'cron_jobs_cleared' => true,
            'transients_cleared' => true
        ];
    }
}

