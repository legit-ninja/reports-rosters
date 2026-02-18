<?php
/**
 * Roster Repository Class
 * 
 * Manages database operations for roster data in the intersoccer_rosters table.
 * Provides reliable CRUD operations with transaction support and comprehensive validation.
 * 
 * @package InterSoccer\ReportsRosters\Data\Repositories
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Data\Repositories;

use InterSoccer\ReportsRosters\Data\Repositories\RepositoryInterface;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Services\CacheManager;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;
use InterSoccer\ReportsRosters\Exceptions\DatabaseException;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Roster Repository Class
 * 
 * Handles roster data operations with comprehensive validation and caching
 */
class RosterRepository implements RepositoryInterface {
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Database instance
     * 
     * @var Database
     */
    private $database;
    
    /**
     * Cache manager instance
     * 
     * @var CacheManager
     */
    private $cache;
    
    /**
     * Table name
     * 
     * @var string
     */
    private $table = 'intersoccer_rosters';
    
    /**
     * Primary key field
     * 
     * @var string
     */
    private $primary_key = 'id';
    
    /**
     * Cache key prefix
     * 
     * @var string
     */
    const CACHE_PREFIX = 'roster_';
    
    /**
     * Cache expiry time (30 minutes)
     * 
     * @var int
     */
    const CACHE_EXPIRY = 1800;
    
    /**
     * Constructor
     * 
     * @param Logger|null $logger Logger instance
     * @param Database|null $database Database instance
     * @param CacheManager|null $cache Cache manager instance
     */
    public function __construct(Logger $logger = null, Database $database = null, CacheManager $cache = null) {
        $this->logger = $logger ?: new Logger();
        $this->database = $database ?: new Database($this->logger);
        $this->cache = $cache ?: new CacheManager($this->logger);
    }
    
    /**
     * Find a roster entry by ID
     * 
     * @param mixed $id Primary key value
     * @return Roster|null Roster instance or null if not found
     */
    public function find($id) {
        try {
            $cache_key = self::CACHE_PREFIX . $id;
            
            // Try cache first
            $cached_roster = $this->cache->get($cache_key);
            if ($cached_roster !== null) {
                $this->logger->debug('Retrieved roster from cache', ['id' => $id]);
                return $cached_roster;
            }
            
            // Get from database
            $roster_data = $this->database->get_roster_entries(['id' => $id], ['limit' => 1]);
            
            if (empty($roster_data)) {
                return null;
            }
            
            $data = $roster_data[0];
            $roster = new Roster($data);
            if (isset($data['id'])) {
                $roster->setKey($data['id']);
            }
            $roster->setExists(true);
            
            // Cache the result
            $this->cache->set($cache_key, $roster, self::CACHE_EXPIRY);
            
            $this->logger->debug('Retrieved roster from database', [
                'id' => $id,
                'order_id' => $roster->order_id
            ]);
            
            return $roster;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to find roster entry', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Find a roster entry by ID or throw exception
     * 
     * @param mixed $id Primary key value
     * @throws \Exception If roster not found
     * @return Roster Roster instance
     */
    public function findOrFail($id) {
        $roster = $this->find($id);
        
        if (!$roster) {
            throw new \Exception("Roster entry not found: {$id}");
        }
        
        return $roster;
    }
    
    /**
     * Find roster entries by multiple IDs
     * 
     * @param array $ids Array of primary key values
     * @return RostersCollection Collection of roster entries
     */
    public function findMany(array $ids) {
        try {
            $rosters = new RostersCollection();
            
            // Check cache first
            $uncached_ids = [];
            foreach ($ids as $id) {
                $cache_key = self::CACHE_PREFIX . $id;
                $cached_roster = $this->cache->get($cache_key);
                
                if ($cached_roster !== null) {
                    $rosters->add($cached_roster);
                } else {
                    $uncached_ids[] = $id;
                }
            }
            
            // Get uncached entries from database
            if (!empty($uncached_ids)) {
                $roster_data = $this->database->get_roster_entries(['id' => $uncached_ids]);
                
                foreach ($roster_data as $data) {
                    $roster = new Roster($data);
                    $roster->setExists(true);
                    $rosters->add($roster);
                    
                    // Cache individual roster
                    $cache_key = self::CACHE_PREFIX . $roster->id;
                    $this->cache->set($cache_key, $roster, self::CACHE_EXPIRY);
                }
            }
            
            return $rosters;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to find multiple roster entries', [
                'ids' => $ids,
                'error' => $e->getMessage()
            ]);
            return new RostersCollection();
        }
    }
    
    /**
     * Get all roster entries
     * 
     * @param array $columns Columns to select
     * @return RostersCollection Collection of roster entries
     */
    public function all(array $columns = ['*']) {
        try {
            $cache_key = 'all_rosters';
            
            // Try cache first (short cache for all entries)
            $cached_rosters = $this->cache->get($cache_key);
            if ($cached_rosters !== null) {
                $this->logger->debug('Retrieved all rosters from cache');
                return $cached_rosters;
            }
            
            $roster_data = $this->database->get_roster_entries();
            $rosters = new RostersCollection();
            
            foreach ($roster_data as $data) {
                $roster = new Roster($data);
                $roster->setExists(true);
                $rosters->add($roster);
            }
            
            // Cache for 5 minutes (shorter since it's all data)
            $this->cache->set($cache_key, $rosters, 300);
            
            $this->logger->info('Retrieved all roster entries', [
                'count' => $rosters->count()
            ]);
            
            return $rosters;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve all roster entries', [
                'error' => $e->getMessage()
            ]);
            return new RostersCollection();
        }
    }
    
    /**
     * Get roster entries with pagination
     * 
     * @param int $per_page Entries per page
     * @param int $page Current page (1-based)
     * @param array $columns Columns to select
     * @return array Paginated results
     */
    public function paginate($per_page = 15, $page = 1, array $columns = ['*']) {
        try {
            $offset = ($page - 1) * $per_page;
            
            // Get total count
            $total = $this->database->get_roster_entries_count();
            
            // Get paginated data
            $roster_data = $this->database->get_roster_entries([], [
                'limit' => $per_page,
                'offset' => $offset,
                'order_by' => 'start_date DESC, created_at DESC'
            ]);
            
            $rosters = new RostersCollection();
            foreach ($roster_data as $data) {
                $roster = new Roster($data);
                $roster->setExists(true);
                $rosters->add($roster);
            }
            
            return [
                'data' => $rosters,
                'total' => $total,
                'per_page' => $per_page,
                'current_page' => $page,
                'last_page' => ceil($total / $per_page),
                'from' => $offset + 1,
                'to' => min($offset + $per_page, $total)
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to paginate roster entries', [
                'error' => $e->getMessage(),
                'per_page' => $per_page,
                'page' => $page
            ]);
            
            return [
                'data' => new RostersCollection(),
                'total' => 0,
                'per_page' => $per_page,
                'current_page' => $page,
                'last_page' => 0,
                'from' => 0,
                'to' => 0
            ];
        }
    }
    
    /**
     * Find first roster entry matching criteria
     * 
     * @param array $criteria Search criteria
     * @param array $columns Columns to select
     * @return Roster|null Roster instance or null if not found
     */
    public function first(array $criteria = [], array $columns = ['*']) {
        $rosters = $this->where($criteria, ['limit' => 1], $columns);
        return $rosters->first();
    }
    
    /**
     * Find first roster entry matching criteria or throw exception
     * 
     * @param array $criteria Search criteria
     * @param array $columns Columns to select
     * @throws \Exception If roster not found
     * @return Roster Roster instance
     */
    public function firstOrFail(array $criteria = [], array $columns = ['*']) {
        $roster = $this->first($criteria, $columns);
        
        if (!$roster) {
            throw new \Exception('Roster entry not found matching criteria: ' . json_encode($criteria));
        }
        
        return $roster;
    }
    
    /**
     * Find roster entries matching criteria
     * 
     * @param array $criteria Search criteria
     * @param array $options Query options (order_by, limit, offset)
     * @param array $columns Columns to select
     * @return RostersCollection Collection of roster entries
     */
    public function where(array $criteria, array $options = [], array $columns = ['*']) {
        try {
            $this->logger->debug('Searching roster entries with criteria', $criteria);
            
            // Build cache key for complex queries
            $cache_key = 'roster_query_' . md5(serialize(['criteria' => $criteria, 'options' => $options]));
            
            // Try cache for complex queries (only if no limit/offset)
            if (empty($options['limit']) && empty($options['offset'])) {
                $cached_result = $this->cache->get($cache_key);
                if ($cached_result !== null) {
                    $this->logger->debug('Retrieved roster query from cache');
                    return $cached_result;
                }
            }
            
            $roster_data = $this->database->get_roster_entries($criteria, $options);
            $rosters = new RostersCollection();
            
            foreach ($roster_data as $data) {
                $roster = new Roster($data);
                // Primary key is not in fillable; set it when loading from DB so update() can find the row
                if (isset($data['id'])) {
                    $roster->setKey($data['id']);
                }
                $roster->setExists(true);
                $rosters->add($roster);
            }
            
            // Cache result if it's a reasonable size
            if ($rosters->count() < 1000 && empty($options['limit']) && empty($options['offset'])) {
                $this->cache->set($cache_key, $rosters, self::CACHE_EXPIRY);
            }
            
            $this->logger->debug('Roster search completed', [
                'criteria' => $criteria,
                'results_count' => $rosters->count()
            ]);
            
            return $rosters;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to search roster entries', [
                'criteria' => $criteria,
                'error' => $e->getMessage()
            ]);
            return new RostersCollection();
        }
    }
    
    /**
     * Count roster entries matching criteria
     * 
     * @param array $criteria Search criteria
     * @return int Roster entry count
     */
    public function count(array $criteria = []) {
        try {
            return $this->database->get_roster_entries_count($criteria);
        } catch (\Exception $e) {
            $this->logger->error('Failed to count roster entries', [
                'criteria' => $criteria,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Check if roster entries exist matching criteria
     * 
     * @param array $criteria Search criteria
     * @return bool Roster entries exist
     */
    public function exists(array $criteria = []) {
        return $this->count($criteria) > 0;
    }
    
    /**
     * Create a new roster entry
     * 
     * @param array $data Roster data
     * @return Roster Created roster instance
     */
    public function create(array $data) {
        try {
            $this->logger->debug('Creating new roster entry', $data);
            
            // Create roster model
            $roster = new Roster($data);
            
            // Validate roster data
            $roster->validate();
            
            // Check for duplicate entry
            $existing = $this->findExistingEntry($roster);
            if ($existing) {
                $this->logger->warning('Duplicate roster entry detected', [
                    'existing_id' => $existing->id,
                    'order_id' => $roster->order_id,
                    'player' => $roster->getFullName()
                ]);
                
                // Return existing entry instead of creating duplicate
                return $existing;
            }
            
            // Insert into database
            $inserted_id = $this->database->insert_roster_entry($roster->toArray());
            
            if (!$inserted_id) {
                throw new DatabaseException('Failed to insert roster entry');
            }
            
            $roster->setKey($inserted_id);
            $roster->setExists(true);
            $roster->syncOriginal();
            
            // Cache the new entry
            $cache_key = self::CACHE_PREFIX . $inserted_id;
            $this->cache->set($cache_key, $roster, self::CACHE_EXPIRY);
            
            // Clear related caches
            $this->clearRelatedCaches();
            
            $this->logger->info('Roster entry created successfully', [
                'id' => $inserted_id,
                'order_id' => $roster->order_id,
                'player_name' => $roster->getFullName(),
                'activity_type' => $roster->activity_type
            ]);
            
            return $roster;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create roster entry', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Create multiple roster entries
     * 
     * @param array $records Array of roster data
     * @return RostersCollection Collection of created roster entries
     */
    public function createMany(array $records) {
        $created_rosters = new RostersCollection();
        
        $this->database->transaction(function() use ($records, &$created_rosters) {
            foreach ($records as $record) {
                try {
                    $roster = $this->create($record);
                    $created_rosters->add($roster);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to create roster in batch', [
                        'record' => $record,
                        'error' => $e->getMessage()
                    ]);
                    // Continue with other records
                }
            }
        });
        
        return $created_rosters;
    }
    
    /**
     * Update a roster entry by ID
     * 
     * @param mixed $id Primary key value
     * @param array $data Data to update
     * @return Roster|null Updated roster or null if not found
     */
    public function update($id, array $data) {
        try {
            $this->logger->debug('Updating roster entry', [
                'id' => $id,
                'data' => $data
            ]);
            
            // Find existing roster
            $roster = $this->find($id);
            if (!$roster) {
                $this->logger->warning('Roster not found for update', ['id' => $id]);
                return null;
            }
            
            // Update roster data
            $roster->fill($data);
            $roster->setAttribute('updated_at', current_time('mysql'));
            
            // Validate updated data
            $roster->validate();
            
            // Update database
            $success = $this->database->update_roster_entry([
                'id' => $id,
                ...$roster->getDirtyAttributes()
            ]);
            
            if (!$success) {
                throw new DatabaseException('Failed to update roster entry');
            }
            
            $roster->syncOriginal();
            
            // Update cache
            $cache_key = self::CACHE_PREFIX . $id;
            $this->cache->set($cache_key, $roster, self::CACHE_EXPIRY);
            
            // Clear related caches
            $this->clearRelatedCaches();
            
            $this->logger->info('Roster entry updated successfully', [
                'id' => $id,
                'player_name' => $roster->getFullName(),
                'changes' => array_keys($roster->getDirtyAttributes())
            ]);
            
            return $roster;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to update roster entry', [
                'id' => $id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Update roster entries matching criteria
     * 
     * @param array $criteria Search criteria
     * @param array $data Data to update
     * @return int Number of updated roster entries
     */
    public function updateWhere(array $criteria, array $data) {
        try {
            $rosters_to_update = $this->where($criteria);
            $updated_count = 0;
            
            $this->database->transaction(function() use ($rosters_to_update, $data, &$updated_count) {
                foreach ($rosters_to_update as $roster) {
                    if ($this->update($roster->id, $data)) {
                        $updated_count++;
                    }
                }
            });
            
            $this->logger->info('Bulk roster update completed', [
                'criteria' => $criteria,
                'updated_count' => $updated_count,
                'total_matching' => $rosters_to_update->count()
            ]);
            
            return $updated_count;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to bulk update roster entries', [
                'criteria' => $criteria,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Create or update a roster entry (update)
     * 
     * @param array $criteria Unique criteria to match existing entry
     * @param array $data Data to create/update
     * @return Roster Roster instance
     */
    public function createOrUpdate(array $criteria, array $data) {
        $existing_roster = $this->first($criteria);
        
        if ($existing_roster) {
            return $this->update($existing_roster->id, $data);
        } else {
            return $this->create(array_merge($criteria, $data));
        }
    }
    
    /**
     * Delete a roster entry by ID
     * 
     * @param mixed $id Primary key value
     * @return bool Success status
     */
    public function delete($id) {
        try {
            $this->logger->debug('Deleting roster entry', ['id' => $id]);
            
            // Find roster for logging
            $roster = $this->find($id);
            
            // Delete from database
            $deleted_count = $this->database->delete_roster_entries(['id' => $id]);
            
            if ($deleted_count === 0) {
                $this->logger->warning('No roster entry found to delete', ['id' => $id]);
                return false;
            }
            
            // Remove from cache
            $cache_key = self::CACHE_PREFIX . $id;
            $this->cache->forget($cache_key);
            
            // Clear related caches
            $this->clearRelatedCaches();
            
            $this->logger->info('Roster entry deleted successfully', [
                'id' => $id,
                'player_name' => $roster ? $roster->getFullName() : 'Unknown',
                'order_id' => $roster ? $roster->order_id : 'Unknown'
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete roster entry', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Delete roster entries matching criteria
     * 
     * @param array $criteria Search criteria
     * @return int Number of deleted roster entries
     */
    public function deleteWhere(array $criteria) {
        try {
            $this->logger->debug('Bulk deleting roster entries', $criteria);
            
            // Get entries for logging before deletion
            $rosters_to_delete = $this->where($criteria);
            
            $this->database->transaction(function() use ($criteria) {
                $deleted_count = $this->database->delete_roster_entries($criteria);
                return $deleted_count;
            });
            
            // Clear affected caches
            foreach ($rosters_to_delete as $roster) {
                $cache_key = self::CACHE_PREFIX . $roster->id;
                $this->cache->forget($cache_key);
            }
            
            $this->clearRelatedCaches();
            
            $deleted_count = $rosters_to_delete->count();
            
            $this->logger->info('Bulk roster deletion completed', [
                'criteria' => $criteria,
                'deleted_count' => $deleted_count
            ]);
            
            return $deleted_count;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to bulk delete roster entries', [
                'criteria' => $criteria,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Find existing roster entry to prevent duplicates
     * 
     * @param Roster $roster Roster to check
     * @return Roster|null Existing roster or null
     */
    private function findExistingEntry(Roster $roster) {
        $criteria = [
            'order_id' => $roster->order_id,
            'order_item_id' => $roster->order_item_id,
            'player_index' => $roster->player_index
        ];
        
        return $this->first($criteria);
    }
    
    /**
     * Clear related caches
     * 
     * @return void
     */
    private function clearRelatedCaches() {
        // Clear common cached queries
        $this->cache->forget('all_rosters');
        
        // Clear pattern-based caches
        $this->cache->forgetPattern('roster_query_*');
        $this->cache->forgetPattern('roster_stats_*');
    }
    
    /**
     * Get roster entries by activity type
     * 
     * @param string $activity_type Activity type (Camp, Course, Birthday Party)
     * @param array $additional_criteria Additional search criteria
     * @return RostersCollection Matching roster entries
     */
    public function getByActivityType($activity_type, array $additional_criteria = []) {
        $criteria = array_merge(['activity_type' => $activity_type], $additional_criteria);
        return $this->where($criteria);
    }
    
    /**
     * Get roster entries by venue
     * 
     * @param string $venue Venue name
     * @param array $additional_criteria Additional search criteria
     * @return RostersCollection Matching roster entries
     */
    public function getByVenue($venue, array $additional_criteria = []) {
        $criteria = array_merge(['venue' => $venue], $additional_criteria);
        return $this->where($criteria);
    }
    
    /**
     * Get roster entries by date range
     * 
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @param array $additional_criteria Additional search criteria
     * @return RostersCollection Matching roster entries
     */
    public function getByDateRange($start_date, $end_date, array $additional_criteria = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . $this->table;
        
        // Build custom query for date range
        $sql = "SELECT * FROM {$table_name} WHERE start_date >= %s AND start_date <= %s";
        $params = [$start_date, $end_date];
        
        // Add additional criteria
        foreach ($additional_criteria as $field => $value) {
            $sql .= " AND {$field} = %s";
            $params[] = $value;
        }
        
        $sql .= " ORDER BY start_date ASC, venue ASC";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        
        $rosters = new RostersCollection();
        foreach ($results as $data) {
            $roster = new Roster($data);
            $roster->setExists(true);
            $rosters->add($roster);
        }
        
        return $rosters;
    }
    
    /**
     * Get roster entries with special needs
     * 
     * @return RostersCollection Roster entries with special needs
     */
    public function getWithSpecialNeeds() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . $this->table;
        
        $sql = "SELECT * FROM {$table_name} 
                WHERE (medical_conditions IS NOT NULL AND medical_conditions != '') 
                   OR (dietary_needs IS NOT NULL AND dietary_needs != '')
                ORDER BY start_date DESC";
        
        $results = $wpdb->get_results($sql, ARRAY_A);
        
        $rosters = new RostersCollection();
        foreach ($results as $data) {
            $roster = new Roster($data);
            $roster->setExists(true);
            $rosters->add($roster);
        }
        
        return $rosters;
    }
    
    /**
     * Get roster entries by customer
     * 
     * @param int $customer_id WordPress user ID
     * @return RostersCollection Customer's roster entries
     */
    public function getByCustomer($customer_id) {
        return $this->where(['customer_id' => $customer_id], ['order_by' => 'start_date DESC']);
    }
    
    /**
     * Get active roster entries (current events)
     * 
     * @return RostersCollection Active roster entries
     */
    public function getActive() {
        $today = current_time('Y-m-d');
        
        global $wpdb;
        $table_name = $wpdb->prefix . $this->table;
        
        $sql = "SELECT * FROM {$table_name} 
                WHERE start_date <= %s 
                AND (end_date >= %s OR end_date IS NULL)
                AND order_status = 'completed'
                ORDER BY start_date ASC";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $today, $today), ARRAY_A);
        
        $rosters = new RostersCollection();
        foreach ($results as $data) {
            $roster = new Roster($data);
            $roster->setExists(true);
            $rosters->add($roster);
        }
        
        return $rosters;
    }
    
    /**
     * Get upcoming roster entries
     * 
     * @param int $days_ahead Days to look ahead (default: 30)
     * @return RostersCollection Upcoming roster entries
     */
    public function getUpcoming($days_ahead = 30) {
        $today = current_time('Y-m-d');
        $future_date = date('Y-m-d', strtotime("+{$days_ahead} days"));
        
        return $this->getByDateRange($today, $future_date, ['order_status' => 'completed']);
    }
    
    /**
     * Get roster statistics
     * 
     * @return array Roster statistics
     */
    public function getStatistics() {
        try {
            $cache_key = 'roster_stats_general';
            $cached_stats = $this->cache->get($cache_key);
            
            if ($cached_stats !== null) {
                return $cached_stats;
            }
            
            $all_rosters = $this->all();
            
            $stats = [
                'total_entries' => $all_rosters->count(),
                'by_activity_type' => $all_rosters->countBy('activity_type'),
                'by_venue' => $all_rosters->countBy('venue'),
                'by_season' => $all_rosters->countBy('season'),
                'by_gender' => $all_rosters->countBy('gender'),
                'by_order_status' => $all_rosters->countBy('order_status'),
                'active_entries' => $this->getActive()->count(),
                'with_special_needs' => $this->getWithSpecialNeeds()->count(),
                'unique_customers' => $all_rosters->pluck('customer_id')->unique()->count(),
                'date_range' => [
                    'earliest' => $all_rosters->min('start_date'),
                    'latest' => $all_rosters->max('end_date') ?: $all_rosters->max('start_date')
                ]
            ];
            
            // Cache for 1 hour
            $this->cache->set($cache_key, $stats, 3600);
            
            return $stats;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get roster statistics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Rebuild roster entries from WooCommerce orders
     * 
     * @param array $options Rebuild options
     * @return array Rebuild results
     */
    public function rebuildFromOrders(array $options = []) {
        try {
            $this->logger->info('Starting roster rebuild from orders');
            
            $results = $this->database->rebuild_rosters($options);
            
            // Clear all caches after rebuild
            $this->clearAllCaches();
            
            $this->logger->info('Roster rebuild completed', $results);
            
            return $results;
            
        } catch (\Exception $e) {
            $this->logger->error('Roster rebuild failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Clear all roster caches
     * 
     * @return void
     */
    public function clearAllCaches() {
        $this->cache->forgetPattern(self::CACHE_PREFIX . '*');
        $this->cache->forgetPattern('roster_*');
        $this->cache->forget('all_rosters');
        
        $this->logger->info('Cleared all roster caches');
    }
    
    // Repository interface implementation
    
    public function beginTransaction() {
        return $this->database->begin_transaction();
    }
    
    public function commitTransaction() {
        return $this->database->commit_transaction();
    }
    
    public function rollbackTransaction() {
        return $this->database->rollback_transaction();
    }
    
    public function transaction(callable $callback) {
        return $this->database->transaction($callback);
    }
    
    public function newModel(array $attributes = []) {
        return new Roster($attributes);
    }
    
    public function getModelClass() {
        return Roster::class;
    }
    
    public function getTable() {
        return $this->table;
    }
    
    public function getKeyName() {
        return $this->primary_key;
    }
}