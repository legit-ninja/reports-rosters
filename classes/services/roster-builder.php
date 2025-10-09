<?php
/**
 * Roster Builder Service
 * 
 * Core service for building rosters from WooCommerce orders and player data.
 * Handles complex order processing, player assignment, and roster generation with validation.
 * 
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;
use InterSoccer\ReportsRosters\Exceptions\DatabaseException;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Roster Builder Service
 * 
 * Orchestrates the complex process of building rosters from orders and player data
 */
class RosterBuilder {
    
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
     * Player repository
     * 
     * @var PlayerRepository
     */
    private $player_repository;
    
    /**
     * Roster repository
     * 
     * @var RosterRepository
     */
    private $roster_repository;
    
    /**
     * Data validator service
     * 
     * @var DataValidator
     */
    private $validator;
    
    /**
     * Event matcher service
     * 
     * @var EventMatcher
     */
    private $event_matcher;
    
    /**
     * Player matcher service
     * 
     * @var PlayerMatcher
     */
    private $player_matcher;
    
    /**
     * Build statistics
     * 
     * @var array
     */
    private $build_stats = [];
    
    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     * @param Database $database Database instance
     * @param PlayerRepository $player_repository Player repository
     * @param RosterRepository $roster_repository Roster repository
     * @param DataValidator $validator Data validator
     * @param EventMatcher $event_matcher Event matcher
     * @param PlayerMatcher $player_matcher Player matcher
     */
    public function __construct(
        Logger $logger,
        Database $database,
        PlayerRepository $player_repository,
        RosterRepository $roster_repository,
        DataValidator $validator,
        EventMatcher $event_matcher,
        PlayerMatcher $player_matcher
    ) {
        $this->logger = $logger;
        $this->database = $database;
        $this->player_repository = $player_repository;
        $this->roster_repository = $roster_repository;
        $this->validator = $validator;
        $this->event_matcher = $event_matcher;
        $this->player_matcher = $player_matcher;
        
        $this->init_build_stats();
    }
    
    /**
     * Initialize build statistics
     * 
     * @return void
     */
    private function init_build_stats() {
        $this->build_stats = [
            'orders_processed' => 0,
            'rosters_created' => 0,
            'rosters_updated' => 0,
            'players_processed' => 0,
            'validation_errors' => 0,
            'skipped_orders' => 0,
            'start_time' => microtime(true),
            'end_time' => null,
            'errors' => [],
            'warnings' => []
        ];
    }
    
    /**
     * Build rosters from WooCommerce orders
     * 
     * @param array $options Build options
     * @return array Build results with statistics
     */
    public function buildRosters(array $options = []) {
        try {
            $this->logger->info('Starting roster build process', $options);
            
            $this->init_build_stats();
            
            // Set default options
            $options = array_merge([
                'clear_existing' => false,
                'order_statuses' => ['completed'],
                'date_range' => null,
                'batch_size' => 100,
                'validate_data' => true,
                'skip_duplicates' => true
            ], $options);
            
            // Clear existing rosters if requested
            if ($options['clear_existing']) {
                $this->clearExistingRosters();
            }
            
            // Process orders in transaction
            return $this->database->transaction(function() use ($options) {
                return $this->processOrdersInBatches($options);
            });
            
        } catch (\Exception $e) {
            $this->logger->error('Roster build process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'stats' => $this->build_stats
            ]);
            
            $this->build_stats['end_time'] = microtime(true);
            $this->build_stats['errors'][] = $e->getMessage();
            
            return $this->build_stats;
        }
    }
    
    /**
     * Build roster from a single WooCommerce order
     * 
     * @param int $order_id WooCommerce order ID
     * @param array $options Build options
     * @return RostersCollection Collection of created/updated rosters
     */
    public function buildRosterFromOrder($order_id, array $options = []) {
        try {
            $this->logger->debug('Building roster from single order', [
                'order_id' => $order_id,
                'options' => $options
            ]);
            
            $options = array_merge([
                'validate_data' => true,
                'skip_duplicates' => true,
                'update_existing' => true
            ], $options);
            
            // Get order data
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new \Exception("Order not found: {$order_id}");
            }
            
            // Validate order status
            if (!in_array($order->get_status(), ['completed', 'processing'])) {
                $this->logger->warning('Skipping order with invalid status', [
                    'order_id' => $order_id,
                    'status' => $order->get_status()
                ]);
                return new RostersCollection();
            }
            
            return $this->processOrderItems($order, $options);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to build roster from order', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
            
            return new RostersCollection();
        }
    }
    
    /**
     * Process orders in batches
     * 
     * @param array $options Processing options
     * @return array Build results
     */
    private function processOrdersInBatches(array $options) {
        $batch_size = $options['batch_size'];
        $offset = 0;
        $total_processed = 0;
        
        do {
            // Get batch of orders
            $orders = $this->getOrdersBatch($offset, $batch_size, $options);
            $batch_count = count($orders);
            
            if ($batch_count === 0) {
                break;
            }
            
            $this->logger->debug('Processing order batch', [
                'offset' => $offset,
                'batch_size' => $batch_count,
                'total_processed' => $total_processed
            ]);
            
            // Process each order in the batch
            foreach ($orders as $order_id) {
                try {
                    $this->processOrderItems(wc_get_order($order_id), $options);
                    $this->build_stats['orders_processed']++;
                    $total_processed++;
                } catch (\Exception $e) {
                    $this->build_stats['skipped_orders']++;
                    $this->build_stats['errors'][] = "Order {$order_id}: " . $e->getMessage();
                    $this->logger->error('Failed to process order in batch', [
                        'order_id' => $order_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $offset += $batch_size;
            
            // Memory cleanup
            if ($total_processed % 500 === 0) {
                $this->logger->info('Batch processing checkpoint', [
                    'processed' => $total_processed,
                    'memory_usage' => memory_get_usage(true)
                ]);
            }
            
        } while ($batch_count === $batch_size);
        
        $this->build_stats['end_time'] = microtime(true);
        
        $this->logger->info('Roster build process completed', $this->build_stats);
        
        return $this->build_stats;
    }
    
    /**
     * Get batch of order IDs
     * 
     * @param int $offset Offset for pagination
     * @param int $limit Batch size
     * @param array $options Query options
     * @return array Order IDs
     */
    private function getOrdersBatch($offset, $limit, array $options) {
        $args = [
            'limit' => $limit,
            'offset' => $offset,
            'status' => $options['order_statuses'],
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'ASC'
        ];
        
        // Add date range filter if specified
        if (!empty($options['date_range'])) {
            $args['date_created'] = $options['date_range'];
        }
        
        return wc_get_orders($args);
    }
    
    /**
     * Process items from a WooCommerce order
     * 
     * @param \WC_Order $order WooCommerce order instance
     * @param array $options Processing options
     * @return RostersCollection Collection of processed rosters
     */
    private function processOrderItems(\WC_Order $order, array $options) {
        $rosters = new RostersCollection();
        $order_id = $order->get_id();
        $customer_id = $order->get_customer_id();
        
        $this->logger->debug('Processing order items', [
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'item_count' => count($order->get_items())
        ]);
        
        if (!$customer_id) {
            throw new \Exception("Order {$order_id} has no customer ID");
        }
        
        // Get customer players
        $customer_players = $this->player_repository->getPlayersByCustomerId($customer_id);
        
        // Get customer contact information
        $customer_data = $this->extractCustomerData($order);
        
        foreach ($order->get_items() as $item_id => $item) {
            try {
                $item_rosters = $this->processOrderItem(
                    $order, 
                    $item_id, 
                    $item, 
                    $customer_players, 
                    $customer_data, 
                    $options
                );
                
                $rosters = $rosters->merge($item_rosters);
                
            } catch (\Exception $e) {
                $this->build_stats['validation_errors']++;
                $this->build_stats['errors'][] = "Order {$order_id}, Item {$item_id}: " . $e->getMessage();
                
                $this->logger->error('Failed to process order item', [
                    'order_id' => $order_id,
                    'item_id' => $item_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $rosters;
    }
    
    /**
     * Process a single order item
     * 
     * @param \WC_Order $order WooCommerce order
     * @param int $item_id Order item ID
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $customer_players Customer's players
     * @param array $customer_data Customer contact data
     * @param array $options Processing options
     * @return RostersCollection Collection of rosters for this item
     */
    private function processOrderItem(
        \WC_Order $order, 
        $item_id, 
        \WC_Order_Item_Product $item, 
        PlayersCollection $customer_players, 
        array $customer_data, 
        array $options
    ) {
        $rosters = new RostersCollection();
        
        // Extract order item data
        $order_data = $this->extractOrderItemData($order, $item_id, $item);
        
        // Validate order item has required activity type
        if (empty($order_data['activity_type'])) {
            throw new ValidationException("Order item missing activity type");
        }
        
        // Get assigned players for this item
        $assigned_players = $this->player_matcher->getAssignedPlayers($item, $customer_players);
        
        if ($assigned_players->isEmpty()) {
            $this->build_stats['warnings'][] = "Order {$order->get_id()}, Item {$item_id}: No assigned players found";
            $this->logger->warning('Order item has no assigned players', [
                'order_id' => $order->get_id(),
                'item_id' => $item_id
            ]);
            return $rosters;
        }
        
        // Create roster entry for each assigned player
        foreach ($assigned_players as $player) {
            try {
                $roster_data = $this->buildRosterData($order_data, $player, $customer_data);
                
                // Validate roster data
                if ($options['validate_data']) {
                    $this->validator->validateRosterData($roster_data);
                }
                
                // Create or update roster entry
                $roster = $this->createOrUpdateRoster($roster_data, $options);
                
                if ($roster) {
                    $rosters->add($roster);
                    $this->build_stats['players_processed']++;
                }
                
            } catch (\Exception $e) {
                $this->build_stats['validation_errors']++;
                $this->build_stats['errors'][] = "Player {$player->getFullName()}: " . $e->getMessage();
                
                $this->logger->error('Failed to create roster for player', [
                    'order_id' => $order->get_id(),
                    'player' => $player->getLogSummary(),
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $rosters;
    }
    
    /**
     * Extract order item data including metadata
     * 
     * @param \WC_Order $order WooCommerce order
     * @param int $item_id Order item ID
     * @param \WC_Order_Item_Product $item Order item
     * @return array Order item data
     */
    private function extractOrderItemData(\WC_Order $order, $item_id, \WC_Order_Item_Product $item) {
        $product = $item->get_product();
        $variation_id = $item->get_variation_id();
        
        // Base order data
        $order_data = [
            'order_id' => $order->get_id(),
            'order_item_id' => $item_id,
            'product_id' => $item->get_product_id(),
            'variation_id' => $variation_id ?: null,
            'customer_id' => $order->get_customer_id(),
            'order_status' => $order->get_status()
        ];
        
        // Extract metadata from order item
        $meta_data = $item->get_meta_data();
        foreach ($meta_data as $meta) {
            $key = $meta->get_data()['key'];
            $value = $meta->get_data()['value'];
            
            // Map metadata keys to roster fields
            $field_mapping = [
                'InterSoccer Venues' => 'venue',
                'Age Group' => 'age_group',
                'Camp Terms' => 'event_type',
                'Camp Times' => 'camp_times',
                'Course Day' => 'course_day',
                'Course Times' => 'course_times',
                'Booking Type' => 'booking_type',
                'Assigned Attendee' => 'assigned_attendee',
                'Days Selected' => 'selected_days',
                'Season' => 'season',
                'Canton / Region' => 'region',
                'City' => 'city',
                'Activity Type' => 'activity_type',
                'Start Date' => 'start_date',
                'End Date' => 'end_date',
                'Discount' => 'discount_applied',
                'assigned_player' => 'player_index'
            ];
            
            if (isset($field_mapping[$key])) {
                $order_data[$field_mapping[$key]] = $value;
            }
        }
        
        // Extract additional data from product attributes
        if ($product && $variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $attributes = $variation->get_attributes();
                foreach ($attributes as $attr_name => $attr_value) {
                    $clean_name = str_replace('pa_', '', $attr_name);
                    if (!isset($order_data[$clean_name])) {
                        $order_data[$clean_name] = $attr_value;
                    }
                }
            }
        }
        
        // Parse and validate dates
        $order_data = $this->parseDates($order_data);
        
        return $order_data;
    }
    
    /**
     * Extract customer contact data
     * 
     * @param \WC_Order $order WooCommerce order
     * @return array Customer data
     */
    private function extractCustomerData(\WC_Order $order) {
        return [
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'emergency_contact' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'emergency_phone' => $order->get_billing_phone()
        ];
    }
    
    /**
     * Build complete roster data from order and player data
     * 
     * @param array $order_data Order item data
     * @param Player $player Player instance
     * @param array $customer_data Customer contact data
     * @return array Complete roster data
     */
    private function buildRosterData(array $order_data, Player $player, array $customer_data) {
        // Merge all data sources
        $roster_data = array_merge($order_data, [
            // Player data
            'player_index' => $player->player_index,
            'first_name' => $player->first_name,
            'last_name' => $player->last_name,
            'dob' => $player->dob,
            'gender' => $player->gender,
            'medical_conditions' => $player->medical_conditions,
            'dietary_needs' => $player->dietary_needs,
            
            // Customer contact data
            'parent_email' => $customer_data['email'],
            'parent_phone' => $customer_data['phone'],
            'emergency_contact' => $player->emergency_contact ?: $customer_data['emergency_contact'],
            'emergency_phone' => $player->emergency_phone ?: $customer_data['emergency_phone'],
        ]);
        
        // Parse event details from various sources
        $roster_data['event_details'] = $this->buildEventDetails($roster_data);
        
        return $roster_data;
    }
    
    /**
     * Build event details JSON from roster data
     * 
     * @param array $roster_data Roster data
     * @return array Event details
     */
    private function buildEventDetails(array $roster_data) {
        $details = [];
        
        // Add relevant details based on activity type
        if (!empty($roster_data['activity_type'])) {
            switch ($roster_data['activity_type']) {
                case 'Camp':
                    $details = [
                        'camp_terms' => $roster_data['event_type'] ?? null,
                        'camp_times' => $roster_data['camp_times'] ?? null,
                        'booking_type' => $roster_data['booking_type'] ?? null,
                        'selected_days' => $roster_data['selected_days'] ?? null
                    ];
                    break;
                    
                case 'Course':
                    $details = [
                        'course_day' => $roster_data['course_day'] ?? null,
                        'course_times' => $roster_data['course_times'] ?? null,
                        'booking_type' => $roster_data['booking_type'] ?? null
                    ];
                    break;
                    
                case 'Birthday Party':
                    $details = [
                        'party_date' => $roster_data['start_date'] ?? null,
                        'party_time' => $roster_data['camp_times'] ?? $roster_data['course_times'] ?? null
                    ];
                    break;
            }
        }
        
        // Add common details
        $details['season'] = $roster_data['season'] ?? null;
        $details['region'] = $roster_data['region'] ?? null;
        $details['city'] = $roster_data['city'] ?? null;
        $details['discount_applied'] = $roster_data['discount_applied'] ?? null;
        
        return array_filter($details); // Remove null values
    }
    
    /**
     * Parse and validate dates in roster data
     * 
     * @param array $data Data with potential date fields
     * @return array Data with parsed dates
     */
    private function parseDates(array $data) {
        $date_fields = ['start_date', 'end_date'];
        
        foreach ($date_fields as $field) {
            if (!empty($data[$field])) {
                // Try to parse various date formats
                $parsed_date = $this->parseDate($data[$field]);
                if ($parsed_date) {
                    $data[$field] = $parsed_date;
                } else {
                    unset($data[$field]); // Remove unparseable dates
                    $this->build_stats['warnings'][] = "Could not parse date: {$data[$field]}";
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Parse date string into Y-m-d format
     * 
     * @param string $date_string Date string
     * @return string|null Parsed date or null
     */
    private function parseDate($date_string) {
        $formats = [
            'Y-m-d',
            'd/m/Y',
            'm/d/Y',
            'd-m-Y',
            'm-d-Y',
            'Y/m/d'
        ];
        
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $date_string);
            if ($date && $date->format($format) === $date_string) {
                return $date->format('Y-m-d');
            }
        }
        
        // Try strtotime as fallback
        $timestamp = strtotime($date_string);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return null;
    }
    
    /**
     * Create or update roster entry
     * 
     * @param array $roster_data Complete roster data
     * @param array $options Processing options
     * @return Roster|null Created or updated roster
     */
    private function createOrUpdateRoster(array $roster_data, array $options) {
        // Check for existing roster
        $existing_roster = null;
        if ($options['skip_duplicates'] || $options['update_existing']) {
            $existing_roster = $this->findExistingRoster($roster_data);
        }
        
        if ($existing_roster) {
            if ($options['skip_duplicates'] && !$options['update_existing']) {
                $this->logger->debug('Skipping duplicate roster entry', [
                    'existing_id' => $existing_roster->id,
                    'order_id' => $roster_data['order_id']
                ]);
                return $existing_roster;
            }
            
            if ($options['update_existing']) {
                $updated_roster = $this->roster_repository->update($existing_roster->id, $roster_data);
                if ($updated_roster) {
                    $this->build_stats['rosters_updated']++;
                    $this->logger->debug('Updated existing roster entry', [
                        'id' => $existing_roster->id,
                        'order_id' => $roster_data['order_id']
                    ]);
                }
                return $updated_roster;
            }
        }
        
        // Create new roster
        $roster = $this->roster_repository->create($roster_data);
        if ($roster) {
            $this->build_stats['rosters_created']++;
            $this->logger->debug('Created new roster entry', [
                'id' => $roster->id,
                'order_id' => $roster_data['order_id']
            ]);
        }
        
        return $roster;
    }
    
    /**
     * Find existing roster entry
     * 
     * @param array $roster_data Roster data to match
     * @return Roster|null Existing roster or null
     */
    private function findExistingRoster(array $roster_data) {
        $criteria = [
            'order_id' => $roster_data['order_id'],
            'order_item_id' => $roster_data['order_item_id'],
            'player_index' => $roster_data['player_index'] ?? 0
        ];
        
        return $this->roster_repository->first($criteria);
    }
    
    /**
     * Clear existing roster entries
     * 
     * @return void
     */
    private function clearExistingRosters() {
        $this->logger->info('Clearing existing roster entries');
        
        $deleted_count = $this->roster_repository->deleteWhere([]);
        
        $this->logger->info('Cleared existing rosters', [
            'deleted_count' => $deleted_count
        ]);
    }
    
    /**
     * Get build statistics
     * 
     * @return array Build statistics
     */
    public function getBuildStatistics() {
        $stats = $this->build_stats;
        
        if ($stats['end_time']) {
            $stats['total_time'] = round($stats['end_time'] - $stats['start_time'], 2);
            $stats['orders_per_second'] = $stats['total_time'] > 0 ? 
                round($stats['orders_processed'] / $stats['total_time'], 2) : 0;
        }
        
        $stats['success_rate'] = $stats['orders_processed'] > 0 ? 
            round((($stats['orders_processed'] - $stats['skipped_orders']) / $stats['orders_processed']) * 100, 1) : 0;
            
        return $stats;
    }
    
    /**
     * Validate roster build integrity
     * 
     * @return array Validation results
     */
    public function validateBuildIntegrity() {
        try {
            $this->logger->info('Starting roster build integrity validation');
            
            $validation_results = [
                'total_rosters' => 0,
                'valid_rosters' => 0,
                'invalid_rosters' => 0,
                'missing_players' => 0,
                'orphaned_rosters' => 0,
                'date_conflicts' => 0,
                'age_group_mismatches' => 0,
                'issues' => []
            ];
            
            // Get all rosters for validation
            $all_rosters = $this->roster_repository->all();
            $validation_results['total_rosters'] = $all_rosters->count();
            
            // Validate each roster
            foreach ($all_rosters as $roster) {
                $roster_issues = $this->validateRosterIntegrity($roster);
                
                if (empty($roster_issues)) {
                    $validation_results['valid_rosters']++;
                } else {
                    $validation_results['invalid_rosters']++;
                    $validation_results['issues'][] = [
                        'roster_id' => $roster->id,
                        'order_id' => $roster->order_id,
                        'player' => $roster->getFullName(),
                        'issues' => $roster_issues
                    ];
                    
                    // Count specific issue types
                    foreach ($roster_issues as $issue) {
                        if (strpos($issue, 'missing player') !== false) {
                            $validation_results['missing_players']++;
                        } elseif (strpos($issue, 'orphaned') !== false) {
                            $validation_results['orphaned_rosters']++;
                        } elseif (strpos($issue, 'date conflict') !== false) {
                            $validation_results['date_conflicts']++;
                        } elseif (strpos($issue, 'age group') !== false) {
                            $validation_results['age_group_mismatches']++;
                        }
                    }
                }
            }
            
            $this->logger->info('Roster integrity validation completed', $validation_results);
            
            return $validation_results;
            
        } catch (\Exception $e) {
            $this->logger->error('Roster integrity validation failed', [
                'error' => $e->getMessage()
            ]);
            
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Validate individual roster integrity
     * 
     * @param Roster $roster Roster to validate
     * @return array Array of validation issues
     */
    private function validateRosterIntegrity(Roster $roster) {
        $issues = [];
        
        try {
            // Check if associated player exists
            $player = $this->player_repository->find($roster->customer_id . '_' . $roster->player_index);
            if (!$player) {
                $issues[] = 'Associated player data missing from user metadata';
            } else {
                // Validate player data consistency
                if ($player->first_name !== $roster->first_name || $player->last_name !== $roster->last_name) {
                    $issues[] = 'Player name mismatch between roster and player data';
                }
                
                if ($player->dob !== $roster->dob) {
                    $issues[] = 'Player date of birth mismatch';
                }
                
                // Check age group eligibility
                if (!empty($roster->age_group) && !$player->isEligibleForAgeGroup($roster->age_group, $roster->start_date)) {
                    $issues[] = 'Player not eligible for assigned age group';
                }
            }
            
            // Check if WooCommerce order exists
            $order = wc_get_order($roster->order_id);
            if (!$order) {
                $issues[] = 'Associated WooCommerce order not found - orphaned roster';
            } else {
                // Check order status consistency
                if ($order->get_status() !== $roster->order_status) {
                    $issues[] = 'Order status mismatch between WooCommerce and roster';
                }
                
                // Check customer ID consistency
                if ($order->get_customer_id() !== $roster->customer_id) {
                    $issues[] = 'Customer ID mismatch between order and roster';
                }
            }
            
            // Validate dates
            if (!empty($roster->start_date) && !empty($roster->end_date)) {
                if ($roster->end_date < $roster->start_date) {
                    $issues[] = 'End date is before start date';
                }
            }
            
            // Check for scheduling conflicts
            $conflicts = $this->findPlayerScheduleConflicts($roster);
            if (!empty($conflicts)) {
                $issues[] = 'Schedule conflict detected with other events';
            }
            
            return $issues;
            
        } catch (\Exception $e) {
            return ['Validation error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Find schedule conflicts for a player
     * 
     * @param Roster $roster Roster to check
     * @return array Conflicting rosters
     */
    private function findPlayerScheduleConflicts(Roster $roster) {
        $criteria = [
            'customer_id' => $roster->customer_id,
            'player_index' => $roster->player_index
        ];
        
        $player_rosters = $this->roster_repository->where($criteria);
        
        $conflicts = [];
        foreach ($player_rosters as $other_roster) {
            if ($other_roster->id !== $roster->id && $roster->conflictsWith($other_roster)) {
                $conflicts[] = $other_roster;
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Rebuild rosters for specific orders
     * 
     * @param array $order_ids Array of order IDs to rebuild
     * @param array $options Rebuild options
     * @return array Rebuild results
     */
    public function rebuildSpecificOrders(array $order_ids, array $options = []) {
        try {
            $this->logger->info('Rebuilding specific orders', [
                'order_ids' => $order_ids,
                'count' => count($order_ids)
            ]);
            
            $this->init_build_stats();
            $options = array_merge([
                'validate_data' => true,
                'update_existing' => true,
                'skip_duplicates' => false
            ], $options);
            
            $results = new RostersCollection();
            
            $this->database->transaction(function() use ($order_ids, $options, &$results) {
                foreach ($order_ids as $order_id) {
                    try {
                        // Remove existing rosters for this order
                        $this->roster_repository->deleteWhere(['order_id' => $order_id]);
                        
                        // Rebuild rosters for this order
                        $order_rosters = $this->buildRosterFromOrder($order_id, $options);
                        $results = $results->merge($order_rosters);
                        
                        $this->build_stats['orders_processed']++;
                        
                    } catch (\Exception $e) {
                        $this->build_stats['skipped_orders']++;
                        $this->build_stats['errors'][] = "Order {$order_id}: " . $e->getMessage();
                        
                        $this->logger->error('Failed to rebuild order', [
                            'order_id' => $order_id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            });
            
            $this->build_stats['end_time'] = microtime(true);
            
            $this->logger->info('Specific orders rebuild completed', $this->build_stats);
            
            return [
                'rosters' => $results,
                'statistics' => $this->getBuildStatistics()
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Specific orders rebuild failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'rosters' => new RostersCollection(),
                'statistics' => $this->getBuildStatistics(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Clean up orphaned roster entries
     * 
     * @return array Cleanup results
     */
    public function cleanupOrphanedRosters() {
        try {
            $this->logger->info('Starting orphaned roster cleanup');
            
            $cleanup_results = [
                'total_checked' => 0,
                'orphaned_found' => 0,
                'deleted' => 0,
                'errors' => []
            ];
            
            // Get all rosters
            $all_rosters = $this->roster_repository->all();
            $cleanup_results['total_checked'] = $all_rosters->count();
            
            $orphaned_rosters = [];
            
            // Check each roster for orphaned status
            foreach ($all_rosters as $roster) {
                // Check if WooCommerce order exists
                $order = wc_get_order($roster->order_id);
                if (!$order) {
                    $orphaned_rosters[] = $roster->id;
                    $cleanup_results['orphaned_found']++;
                    continue;
                }
                
                // Check if player still exists
                $player = $this->player_repository->find($roster->customer_id . '_' . $roster->player_index);
                if (!$player) {
                    $orphaned_rosters[] = $roster->id;
                    $cleanup_results['orphaned_found']++;
                    continue;
                }
            }
            
            // Delete orphaned rosters
            if (!empty($orphaned_rosters)) {
                $this->database->transaction(function() use ($orphaned_rosters, &$cleanup_results) {
                    foreach ($orphaned_rosters as $roster_id) {
                        try {
                            if ($this->roster_repository->delete($roster_id)) {
                                $cleanup_results['deleted']++;
                            }
                        } catch (\Exception $e) {
                            $cleanup_results['errors'][] = "Failed to delete roster {$roster_id}: " . $e->getMessage();
                        }
                    }
                });
            }
            
            $this->logger->info('Orphaned roster cleanup completed', $cleanup_results);
            
            return $cleanup_results;
            
        } catch (\Exception $e) {
            $this->logger->error('Orphaned roster cleanup failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'total_checked' => 0,
                'orphaned_found' => 0,
                'deleted' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
    }
    
    /**
     * Get roster build recommendations
     * 
     * @return array Build recommendations
     */
    public function getBuildRecommendations() {
        try {
            $recommendations = [
                'rebuild_recommended' => false,
                'reasons' => [],
                'priority' => 'low',
                'estimated_time' => '5-10 minutes',
                'affected_orders' => 0,
                'data_quality_issues' => []
            ];
            
            // Check for recent orders without rosters
            $recent_orders = wc_get_orders([
                'limit' => 100,
                'status' => ['completed', 'processing'],
                'date_created' => '>' . (time() - 7 * DAY_IN_SECONDS)
            ]);
            
            $orders_without_rosters = 0;
            foreach ($recent_orders as $order) {
                $existing_rosters = $this->roster_repository->where(['order_id' => $order->get_id()]);
                if ($existing_rosters->isEmpty()) {
                    $orders_without_rosters++;
                }
            }
            
            if ($orders_without_rosters > 0) {
                $recommendations['rebuild_recommended'] = true;
                $recommendations['reasons'][] = "{$orders_without_rosters} recent orders missing roster entries";
                $recommendations['affected_orders'] = $orders_without_rosters;
                $recommendations['priority'] = $orders_without_rosters > 10 ? 'high' : 'medium';
            }
            
            // Check for data integrity issues
            $integrity_validation = $this->validateBuildIntegrity();
            if ($integrity_validation['invalid_rosters'] > 0) {
                $recommendations['rebuild_recommended'] = true;
                $recommendations['reasons'][] = "{$integrity_validation['invalid_rosters']} rosters have data integrity issues";
                $recommendations['data_quality_issues'] = $integrity_validation['issues'];
                
                if ($integrity_validation['invalid_rosters'] > 20) {
                    $recommendations['priority'] = 'high';
                    $recommendations['estimated_time'] = '15-30 minutes';
                }
            }
            
            // Check for plugin updates that might affect roster structure
            $plugin_version = get_option('intersoccer_plugin_version');
            $db_version = get_option('intersoccer_db_version');
            
            if (version_compare($plugin_version, $db_version, '>')) {
                $recommendations['rebuild_recommended'] = true;
                $recommendations['reasons'][] = "Plugin updated - roster rebuild recommended for compatibility";
                $recommendations['priority'] = 'medium';
            }
            
            return $recommendations;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get build recommendations', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'rebuild_recommended' => true,
                'reasons' => ['Error checking system status - rebuild recommended as precaution'],
                'priority' => 'medium',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Preview roster build without making changes
     * 
     * @param array $options Preview options
     * @return array Preview results
     */
    public function previewRosterBuild(array $options = []) {
        try {
            $this->logger->info('Starting roster build preview');
            
            $options = array_merge([
                'limit' => 10,
                'order_statuses' => ['completed']
            ], $options);
            
            // Get sample orders
            $sample_orders = wc_get_orders([
                'limit' => $options['limit'],
                'status' => $options['order_statuses'],
                'return' => 'objects'
            ]);
            
            $preview_results = [
                'orders_sampled' => count($sample_orders),
                'estimated_rosters' => 0,
                'estimated_players' => 0,
                'activity_breakdown' => [],
                'venue_breakdown' => [],
                'potential_issues' => [],
                'sample_rosters' => []
            ];
            
            foreach ($sample_orders as $order) {
                try {
                    $customer_players = $this->player_repository->getPlayersByCustomerId($order->get_customer_id());
                    $customer_data = $this->extractCustomerData($order);
                    
                    foreach ($order->get_items() as $item_id => $item) {
                        $order_data = $this->extractOrderItemData($order, $item_id, $item);
                        $assigned_players = $this->player_matcher->getAssignedPlayers($item, $customer_players);
                        
                        if (!empty($order_data['activity_type'])) {
                            $preview_results['activity_breakdown'][$order_data['activity_type']] = 
                                ($preview_results['activity_breakdown'][$order_data['activity_type']] ?? 0) + $assigned_players->count();
                        }
                        
                        if (!empty($order_data['venue'])) {
                            $preview_results['venue_breakdown'][$order_data['venue']] = 
                                ($preview_results['venue_breakdown'][$order_data['venue']] ?? 0) + $assigned_players->count();
                        }
                        
                        $preview_results['estimated_rosters'] += $assigned_players->count();
                        $preview_results['estimated_players'] += $assigned_players->count();
                        
                        // Add sample roster data
                        if (count($preview_results['sample_rosters']) < 5 && !$assigned_players->isEmpty()) {
                            $sample_player = $assigned_players->first();
                            $sample_roster_data = $this->buildRosterData($order_data, $sample_player, $customer_data);
                            
                            $preview_results['sample_rosters'][] = [
                                'order_id' => $order->get_id(),
                                'player_name' => $sample_player->getFullName(),
                                'activity_type' => $sample_roster_data['activity_type'] ?? 'Unknown',
                                'venue' => $sample_roster_data['venue'] ?? 'Unknown',
                                'start_date' => $sample_roster_data['start_date'] ?? 'Unknown'
                            ];
                        }
                        
                        // Check for potential issues
                        if ($assigned_players->isEmpty()) {
                            $preview_results['potential_issues'][] = "Order {$order->get_id()}, Item {$item_id}: No assigned players";
                        }
                        
                        if (empty($order_data['activity_type'])) {
                            $preview_results['potential_issues'][] = "Order {$order->get_id()}, Item {$item_id}: Missing activity type";
                        }
                    }
                    
                } catch (\Exception $e) {
                    $preview_results['potential_issues'][] = "Order {$order->get_id()}: " . $e->getMessage();
                }
            }
            
            $this->logger->info('Roster build preview completed', $preview_results);
            
            return $preview_results;
            
        } catch (\Exception $e) {
            $this->logger->error('Roster build preview failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => $e->getMessage(),
                'orders_sampled' => 0,
                'estimated_rosters' => 0
            ];
        }
    }
}