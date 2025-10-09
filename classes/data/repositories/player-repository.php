<?php
/**
 * Player Repository Class
 * 
 * Manages database operations for player data stored in WordPress user metadata.
 * Handles the intersoccer_players metadata field with validation and caching.
 * 
 * @package InterSoccer\ReportsRosters\Data\Repositories
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Data\Repositories;

use InterSoccer\ReportsRosters\Data\Repositories\RepositoryInterface;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
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
 * Player Repository Class
 * 
 * Handles player data operations with WordPress user metadata
 */
class PlayerRepository implements RepositoryInterface {
    
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
     * Metadata key for player data
     * 
     * @var string
     */
    const META_KEY = 'intersoccer_players';
    
    /**
     * Cache key prefix
     * 
     * @var string
     */
    const CACHE_PREFIX = 'intersoccer_players_';
    
    /**
     * Cache expiry time (1 hour)
     * 
     * @var int
     */
    const CACHE_EXPIRY = 3600;
    
    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     * @param Database $database Database instance
     * @param CacheManager $cache Cache manager instance
     */
    public function __construct(Logger $logger, Database $database, CacheManager $cache) {
        $this->logger = $logger;
        $this->database = $database;
        $this->cache = $cache;
    }
    
    /**
     * Find a player by customer ID and player index
     * 
     * @param mixed $id String in format "customer_id_player_index"
     * @return Player|null Player instance or null if not found
     */
    public function find($id) {
        try {
            list($customer_id, $player_index) = $this->parsePlayerId($id);
            
            $players = $this->getPlayersByCustomerId($customer_id);
            
            return $players->firstWhere('player_index', $player_index);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to find player', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Find a player by ID or throw exception
     * 
     * @param mixed $id Player ID
     * @throws \Exception If player not found
     * @return Player Player instance
     */
    public function findOrFail($id) {
        $player = $this->find($id);
        
        if (!$player) {
            throw new \Exception("Player not found: {$id}");
        }
        
        return $player;
    }
    
    /**
     * Find players by multiple IDs
     * 
     * @param array $ids Array of player IDs
     * @return PlayersCollection Collection of players
     */
    public function findMany(array $ids) {
        $players = new PlayersCollection();
        
        foreach ($ids as $id) {
            $player = $this->find($id);
            if ($player) {
                $players->add($player);
            }
        }
        
        return $players;
    }
    
    /**
     * Get all players
     * 
     * @param array $columns Not used in this implementation
     * @return PlayersCollection Collection of all players
     */
    public function all(array $columns = ['*']) {
        try {
            $this->logger->debug('Retrieving all players');
            
            // Get all customers with player data
            $customer_ids = $this->getAllCustomerIdsWithPlayers();
            
            $all_players = new PlayersCollection();
            
            foreach ($customer_ids as $customer_id) {
                $players = $this->getPlayersByCustomerId($customer_id);
                $all_players = $all_players->merge($players);
            }
            
            $this->logger->info('Retrieved all players', [
                'total_customers' => count($customer_ids),
                'total_players' => $all_players->count()
            ]);
            
            return $all_players;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve all players', [
                'error' => $e->getMessage()
            ]);
            return new PlayersCollection();
        }
    }
    
    /**
     * Get players with pagination
     * 
     * @param int $per_page Players per page
     * @param int $page Current page (1-based)
     * @param array $columns Not used in this implementation
     * @return array Paginated results
     */
    public function paginate($per_page = 15, $page = 1, array $columns = ['*']) {
        try {
            $all_players = $this->all();
            $total = $all_players->count();
            $offset = ($page - 1) * $per_page;
            
            $paginated_players = $all_players->slice($offset, $per_page);
            
            return [
                'data' => $paginated_players,
                'total' => $total,
                'per_page' => $per_page,
                'current_page' => $page,
                'last_page' => ceil($total / $per_page),
                'from' => $offset + 1,
                'to' => min($offset + $per_page, $total)
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to paginate players', [
                'error' => $e->getMessage(),
                'per_page' => $per_page,
                'page' => $page
            ]);
            
            return [
                'data' => new PlayersCollection(),
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
     * Find first player matching criteria
     * 
     * @param array $criteria Search criteria
     * @param array $columns Not used in this implementation
     * @return Player|null Player instance or null if not found
     */
    public function first(array $criteria = [], array $columns = ['*']) {
        $players = $this->where($criteria, ['limit' => 1]);
        return $players->first();
    }
    
    /**
     * Find first player matching criteria or throw exception
     * 
     * @param array $criteria Search criteria
     * @param array $columns Not used in this implementation
     * @throws \Exception If player not found
     * @return Player Player instance
     */
    public function firstOrFail(array $criteria = [], array $columns = ['*']) {
        $player = $this->first($criteria, $columns);
        
        if (!$player) {
            throw new \Exception('Player not found matching criteria: ' . json_encode($criteria));
        }
        
        return $player;
    }
    
    /**
     * Find players matching criteria
     * 
     * @param array $criteria Search criteria
     * @param array $options Query options (order_by, limit, offset)
     * @param array $columns Not used in this implementation
     * @return PlayersCollection Collection of players
     */
    public function where(array $criteria, array $options = [], array $columns = ['*']) {
        try {
            $this->logger->debug('Searching players with criteria', $criteria);
            
            $all_players = $this->all();
            $filtered_players = $all_players->filter(function(Player $player) use ($criteria) {
                return $player->matches($criteria);
            });
            
            // Apply ordering
            if (isset($options['order_by'])) {
                $filtered_players = $this->applyOrdering($filtered_players, $options['order_by']);
            }
            
            // Apply limit and offset
            if (isset($options['offset'])) {
                $filtered_players = $filtered_players->slice($options['offset']);
            }
            
            if (isset($options['limit'])) {
                $filtered_players = $filtered_players->take($options['limit']);
            }
            
            $this->logger->debug('Player search completed', [
                'criteria' => $criteria,
                'results_count' => $filtered_players->count()
            ]);
            
            return $filtered_players;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to search players', [
                'criteria' => $criteria,
                'error' => $e->getMessage()
            ]);
            return new PlayersCollection();
        }
    }
    
    /**
     * Count players matching criteria
     * 
     * @param array $criteria Search criteria
     * @return int Player count
     */
    public function count(array $criteria = []) {
        return $this->where($criteria)->count();
    }
    
    /**
     * Check if players exist matching criteria
     * 
     * @param array $criteria Search criteria
     * @return bool Players exist
     */
    public function exists(array $criteria = []) {
        return $this->count($criteria) > 0;
    }
    
    /**
     * Create a new player
     * 
     * @param array $data Player data
     * @return Player Created player instance
     */
    public function create(array $data) {
        try {
            $this->logger->debug('Creating new player', $data);
            
            // Validate required fields
            if (empty($data['customer_id'])) {
                throw new ValidationException('Customer ID is required');
            }
            
            $customer_id = $data['customer_id'];
            
            // Get existing players for this customer
            $existing_players = $this->getPlayersByCustomerId($customer_id);
            
            // Determine next player index
            $player_index = $data['player_index'] ?? $existing_players->count();
            $data['player_index'] = $player_index;
            
            // Create player model
            $player = new Player($data);
            
            // Validate player data
            $player->validate();
            
            // Add to existing players
            $existing_players->add($player);
            
            // Save updated players data
            $this->savePlayersForCustomer($customer_id, $existing_players);
            
            $this->logger->info('Player created successfully', [
                'customer_id' => $customer_id,
                'player_index' => $player_index,
                'player_name' => $player->getFullName()
            ]);
            
            return $player;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create player', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Create multiple players
     * 
     * @param array $records Array of player data
     * @return PlayersCollection Collection of created players
     */
    public function createMany(array $records) {
        $created_players = new PlayersCollection();
        
        $this->database->transaction(function() use ($records, &$created_players) {
            foreach ($records as $record) {
                $player = $this->create($record);
                $created_players->add($player);
            }
        });
        
        return $created_players;
    }
    
    /**
     * Update a player by ID
     * 
     * @param mixed $id Player ID
     * @param array $data Data to update
     * @return Player|null Updated player or null if not found
     */
    public function update($id, array $data) {
        try {
            list($customer_id, $player_index) = $this->parsePlayerId($id);
            
            $this->logger->debug('Updating player', [
                'customer_id' => $customer_id,
                'player_index' => $player_index,
                'data' => $data
            ]);
            
            // Get existing players
            $players = $this->getPlayersByCustomerId($customer_id);
            $player = $players->firstWhere('player_index', $player_index);
            
            if (!$player) {
                $this->logger->warning('Player not found for update', [
                    'customer_id' => $customer_id,
                    'player_index' => $player_index
                ]);
                return null;
            }
            
            // Update player data
            $player->fill($data);
            $player->setAttribute('last_updated', current_time('mysql'));
            
            // Validate updated data
            $player->validate();
            
            // Save updated players
            $this->savePlayersForCustomer($customer_id, $players);
            
            $this->logger->info('Player updated successfully', [
                'customer_id' => $customer_id,
                'player_index' => $player_index,
                'player_name' => $player->getFullName()
            ]);
            
            return $player;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to update player', [
                'id' => $id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Update players matching criteria
     * 
     * @param array $criteria Search criteria
     * @param array $data Data to update
     * @return int Number of updated players
     */
    public function updateWhere(array $criteria, array $data) {
        $players_to_update = $this->where($criteria);
        $updated_count = 0;
        
        $this->database->transaction(function() use ($players_to_update, $data, &$updated_count) {
            foreach ($players_to_update as $player) {
                $player_id = $player->getUniqueId();
                if ($this->update($player_id, $data)) {
                    $updated_count++;
                }
            }
        });
        
        return $updated_count;
    }
    
    /**
     * Create or update a player (update)
     * 
     * @param array $criteria Unique criteria to match existing player
     * @param array $data Data to create/update
     * @return Player Player instance
     */
    public function createOrUpdate(array $criteria, array $data) {
        $existing_player = $this->first($criteria);
        
        if ($existing_player) {
            return $this->update($existing_player->getUniqueId(), $data);
        } else {
            return $this->create(array_merge($criteria, $data));
        }
    }
    
    /**
     * Delete a player by ID
     * 
     * @param mixed $id Player ID
     * @return bool Success status
     */
    public function delete($id) {
        try {
            list($customer_id, $player_index) = $this->parsePlayerId($id);
            
            $this->logger->debug('Deleting player', [
                'customer_id' => $customer_id,
                'player_index' => $player_index
            ]);
            
            // Get existing players
            $players = $this->getPlayersByCustomerId($customer_id);
            $player_to_delete = $players->firstWhere('player_index', $player_index);
            
            if (!$player_to_delete) {
                $this->logger->warning('Player not found for deletion', [
                    'customer_id' => $customer_id,
                    'player_index' => $player_index
                ]);
                return false;
            }
            
            // Remove player from collection
            $updated_players = $players->reject(function(Player $player) use ($player_index) {
                return $player->player_index === $player_index;
            });
            
            // Reindex remaining players
            $reindexed_players = new PlayersCollection();
            $new_index = 0;
            
            foreach ($updated_players as $player) {
                $player->setAttribute('player_index', $new_index);
                $reindexed_players->add($player);
                $new_index++;
            }
            
            // Save updated players
            $this->savePlayersForCustomer($customer_id, $reindexed_players);
            
            $this->logger->info('Player deleted successfully', [
                'customer_id' => $customer_id,
                'deleted_index' => $player_index,
                'player_name' => $player_to_delete->getFullName(),
                'remaining_players' => $reindexed_players->count()
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete player', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Delete players matching criteria
     * 
     * @param array $criteria Search criteria
     * @return int Number of deleted players
     */
    public function deleteWhere(array $criteria) {
        $players_to_delete = $this->where($criteria);
        $deleted_count = 0;
        
        $this->database->transaction(function() use ($players_to_delete, &$deleted_count) {
            foreach ($players_to_delete as $player) {
                if ($this->delete($player->getUniqueId())) {
                    $deleted_count++;
                }
            }
        });
        
        return $deleted_count;
    }
    
    /**
     * Get players by customer ID
     * 
     * @param int $customer_id WordPress user ID
     * @return PlayersCollection Collection of players
     */
    public function getPlayersByCustomerId($customer_id) {
        try {
            $cache_key = self::CACHE_PREFIX . $customer_id;
            
            // Try cache first
            $cached_players = $this->cache->get($cache_key);
            if ($cached_players !== null) {
                $this->logger->debug('Retrieved players from cache', [
                    'customer_id' => $customer_id,
                    'count' => $cached_players->count()
                ]);
                return $cached_players;
            }
            
            // Get from database
            $players_data = get_user_meta($customer_id, self::META_KEY, true);
            $players = new PlayersCollection();
            
            if (is_array($players_data)) {
                foreach ($players_data as $index => $player_data) {
                    $player = Player::fromUserMetadata($customer_id, $index, $player_data);
                    $players->add($player);
                }
            }
            
            // Cache the result
            $this->cache->set($cache_key, $players, self::CACHE_EXPIRY);
            
            $this->logger->debug('Retrieved players from database', [
                'customer_id' => $customer_id,
                'count' => $players->count()
            ]);
            
            return $players;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get players by customer ID', [
                'customer_id' => $customer_id,
                'error' => $e->getMessage()
            ]);
            return new PlayersCollection();
        }
    }
    
    /**
     * Save players for a customer
     * 
     * @param int $customer_id WordPress user ID
     * @param PlayersCollection $players Players to save
     * @return bool Success status
     */
    private function savePlayersForCustomer($customer_id, PlayersCollection $players) {
        try {
            // Convert players to metadata format
            $players_data = [];
            foreach ($players as $player) {
                $players_data[] = $player->toUserMetadata();
            }
            
            // Update user metadata
            $result = update_user_meta($customer_id, self::META_KEY, $players_data);
            
            if ($result === false) {
                throw new DatabaseException('Failed to update player metadata');
            }
            
            // Clear cache
            $cache_key = self::CACHE_PREFIX . $customer_id;
            $this->cache->forget($cache_key);
            
            $this->logger->debug('Saved players for customer', [
                'customer_id' => $customer_id,
                'player_count' => count($players_data)
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to save players for customer', [
                'customer_id' => $customer_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Get all customer IDs that have player data
     * 
     * @return array Customer IDs
     */
    private function getAllCustomerIdsWithPlayers() {
        global $wpdb;
        
        try {
            $cache_key = 'intersoccer_customers_with_players';
            $cached_ids = $this->cache->get($cache_key);
            
            if ($cached_ids !== null) {
                return $cached_ids;
            }
            
            $customer_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
                self::META_KEY
            ));
            
            $customer_ids = array_map('intval', $customer_ids);
            
            // Cache for 30 minutes
            $this->cache->set($cache_key, $customer_ids, 1800);
            
            return $customer_ids;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get customer IDs with players', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Parse player ID string
     * 
     * @param string $id Player ID in format "customer_id_player_index"
     * @return array [customer_id, player_index]
     */
    private function parsePlayerId($id) {
        if (!is_string($id) || strpos($id, '_') === false) {
            throw new \InvalidArgumentException('Invalid player ID format. Expected: customer_id_player_index');
        }
        
        $parts = explode('_', $id);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Invalid player ID format. Expected: customer_id_player_index');
        }
        
        return [(int) $parts[0], (int) $parts[1]];
    }
    
    /**
     * Apply ordering to player collection
     * 
     * @param PlayersCollection $players Players collection
     * @param string $order_by Order specification
     * @return PlayersCollection Ordered collection
     */
    private function applyOrdering(PlayersCollection $players, $order_by) {
        $parts = explode(' ', trim($order_by));
        $field = $parts[0];
        $direction = strtolower($parts[1] ?? 'asc');
        
        return $players->sortBy($field, SORT_REGULAR, $direction === 'desc');
    }
    
    /**
     * Clear all player cache
     * 
     * @return void
     */
    public function clearCache() {
        $customer_ids = $this->getAllCustomerIdsWithPlayers();
        
        foreach ($customer_ids as $customer_id) {
            $cache_key = self::CACHE_PREFIX . $customer_id;
            $this->cache->forget($cache_key);
        }
        
        $this->cache->forget('intersoccer_customers_with_players');
        
        $this->logger->info('Cleared player cache for all customers', [
            'customer_count' => count($customer_ids)
        ]);
    }
    
    /**
     * Get players by age group
     * 
     * @param string $age_group Age group specification
     * @param string|null $event_date Event date for age calculation
     * @return PlayersCollection Eligible players
     */
    public function getPlayersByAgeGroup($age_group, $event_date = null) {
        return $this->all()->filter(function(Player $player) use ($age_group, $event_date) {
            return $player->isEligibleForAgeGroup($age_group, $event_date);
        });
    }
    
    /**
     * Get players with special needs
     * 
     * @return PlayersCollection Players with special needs
     */
    public function getPlayersWithSpecialNeeds() {
        return $this->where(['has_special_needs' => true]);
    }
    
    /**
     * Search players by name
     * 
     * @param string $name Name to search for
     * @return PlayersCollection Matching players
     */
    public function searchByName($name) {
        return $this->where(['name' => $name]);
    }
    
    /**
     * Get statistics about players
     * 
     * @return array Player statistics
     */
    public function getStatistics() {
        $all_players = $this->all();
        
        return [
            'total_players' => $all_players->count(),
            'total_customers' => count($this->getAllCustomerIdsWithPlayers()),
            'by_gender' => $all_players->countBy('gender'),
            'by_age_group' => $this->getAgeGroupStatistics($all_players),
            'with_medical_conditions' => $all_players->filter(function(Player $player) {
                return $player->hasMedicalConditions();
            })->count(),
            'with_dietary_needs' => $all_players->filter(function(Player $player) {
                return $player->hasDietaryNeeds();
            })->count(),
            'incomplete_profiles' => $all_players->filter(function(Player $player) {
                return !$player->isComplete();
            })->count()
        ];
    }
    
    /**
     * Get age group statistics
     * 
     * @param PlayersCollection $players Players collection
     * @return array Age group statistics
     */
    private function getAgeGroupStatistics(PlayersCollection $players) {
        $age_groups = [
            '0-3' => 0,
            '4-6' => 0,
            '7-10' => 0,
            '11-13' => 0,
            '14+' => 0
        ];
        
        foreach ($players as $player) {
            $age = $player->getAge();
            
            if ($age <= 3) {
                $age_groups['0-3']++;
            } elseif ($age <= 6) {
                $age_groups['4-6']++;
            } elseif ($age <= 10) {
                $age_groups['7-10']++;
            } elseif ($age <= 13) {
                $age_groups['11-13']++;
            } else {
                $age_groups['14+']++;
            }
        }
        
        return $age_groups;
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
        return new Player($attributes);
    }
    
    public function getModelClass() {
        return Player::class;
    }
    
    public function getTable() {
        return 'usermeta'; // WordPress user metadata table
    }
    
    public function getKeyName() {
        return 'customer_id_player_index'; // Composite key format
    }
}