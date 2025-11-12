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
     * Canonical mapping between order item metadata keys and roster fields.
     *
     * @var array<string,string>
     */
    private $order_meta_field_map = [
        'InterSoccer Venues' => 'venue',
        'Age Group' => 'age_group',
        'Camp Terms' => 'event_type',
        'Camp Times' => 'camp_times',
        'Course Day' => 'course_day',
        'Course Times' => 'course_times',
        'Booking Type' => 'booking_type',
        'Assigned Attendee' => 'assigned_attendee',
        'Player Index' => 'player_index',
        'Days Selected' => 'selected_days',
        'Season' => 'season',
        'Canton / Region' => 'region',
        'City' => 'city',
        'Activity Type' => 'activity_type',
        'Start Date' => 'start_date',
        'End Date' => 'end_date',
        'Holidays' => 'holidays',
        'Discount' => 'discount_applied',
        'Discount Amount' => 'discount_amount',
        'Base Price' => 'base_price',
        'Remaining Sessions' => 'remaining_sessions',
        'Late Pickup Type' => 'late_pickup_type',
        'Late Pickup Days' => 'late_pickup_days',
        'Late Pickup Cost' => 'late_pickup_cost',
    ];

    /**
     * Cached lookup table of localized metadata key variants.
     *
     * @var array<string,array<int,string>>|null
     */
    private $order_meta_key_variants = null;

    /**
     * Cache for previously normalized metadata keys.
     *
     * @var array<string,string>
     */
    private $order_meta_key_cache = [];
    
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
        Logger $logger = null,
        Database $database = null,
        PlayerRepository $player_repository = null,
        RosterRepository $roster_repository = null,
        DataValidator $validator = null,
        EventMatcher $event_matcher = null,
        PlayerMatcher $player_matcher = null
    ) {
        $this->logger = $logger ?: new Logger();
        $this->database = $database ?: new Database($this->logger);
        $this->player_repository = $player_repository ?: new PlayerRepository($this->logger, $this->database);
        $this->roster_repository = $roster_repository ?: new RosterRepository($this->logger, $this->database);
        $this->validator = $validator ?: new DataValidator($this->logger);
        $this->event_matcher = $event_matcher ?: new EventMatcher($this->logger);
        $this->player_matcher = $player_matcher ?: new PlayerMatcher($this->logger);
        
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
     * Reconcile rosters with current WooCommerce orders
     *
     * Syncs existing roster entries with current orders, adds missing entries,
     * and removes obsolete entries when requested.
     *
     * @param array $options Reconcile options
     * @return array Results containing synced, deleted, and error metrics
     */
    public function reconcile(array $options = []) {
        $start_time = microtime(true);

        $defaults = [
            'status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
            'delete_obsolete' => true,
            'validate_data' => true,
            'skip_duplicates' => false,
            'update_existing' => true,
        ];
        $options = array_merge($defaults, $options);

        $results = [
            'synced' => 0,
            'deleted' => 0,
            'errors' => 0,
            'error_messages' => [],
            'start_time' => current_time('mysql'),
            'end_time' => null,
            'duration' => 0,
        ];

        $this->logger->info('Starting roster reconciliation', $options);

        try {
            $wpdb = $this->database->get_wpdb();
            $table = $wpdb->prefix . 'intersoccer_rosters';

            $existing_item_ids = $wpdb->get_col("SELECT DISTINCT order_item_id FROM {$table}");
            $existing_item_map = [];
            foreach ($existing_item_ids as $item_id) {
                if ($item_id !== null && $item_id !== '') {
                    $existing_item_map[(int) $item_id] = true;
                }
            }

            $orders = wc_get_orders([
                'limit' => -1,
                'status' => $options['status'],
                'return' => 'objects',
            ]);

            $this->logger->info('Orders fetched for reconciliation', ['count' => count($orders)]);

            foreach ($orders as $order) {
                $order_id = $order->get_id();

                try {
                    $customer_id = $order->get_customer_id();
                    if (!$customer_id) {
                        throw new \Exception("Order {$order_id} has no customer ID");
                    }

                    $customer_players = $this->player_repository->getPlayersByCustomerId($customer_id);
                    $customer_data = $this->extractCustomerData($order);

                    foreach ($order->get_items() as $item_id => $item) {
                        try {
                            $item_rosters = $this->processOrderItem(
                                $order,
                                $item_id,
                                $item,
                                $customer_players,
                                $customer_data,
                                [
                                    'validate_data' => $options['validate_data'],
                                    'skip_duplicates' => false,
                                    'update_existing' => true,
                                ]
                            );

                            if ($item_rosters->count() > 0) {
                                $results['synced'] += $item_rosters->count();
                                unset($existing_item_map[$item_id]);
                            }

                        } catch (\Exception $e) {
                            $results['errors']++;
                            $error_msg = "Order {$order_id}, Item {$item_id}: {$e->getMessage()}";
                            $results['error_messages'][] = $error_msg;
                            $this->logger->error('Failed to reconcile order item', [
                                'order_id' => $order_id,
                                'item_id' => $item_id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                } catch (\Exception $e) {
                    $results['errors']++;
                    $results['error_messages'][] = "Order {$order_id}: " . $e->getMessage();
                    $this->logger->error('Failed to reconcile order', [
                        'order_id' => $order_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if ($options['delete_obsolete'] && !empty($existing_item_map)) {
                foreach (array_keys($existing_item_map) as $obsolete_item_id) {
                    try {
                        $deleted_count = $this->roster_repository->deleteWhere(['order_item_id' => $obsolete_item_id]);
                        $results['deleted'] += $deleted_count;
                        $this->logger->debug('Deleted obsolete roster entries', [
                            'order_item_id' => $obsolete_item_id,
                            'deleted' => $deleted_count
                        ]);
                    } catch (\Exception $e) {
                        $results['errors']++;
                        $results['error_messages'][] = "Failed to delete obsolete roster {$obsolete_item_id}: {$e->getMessage()}";
                        $this->logger->error('Failed to delete obsolete roster entry', [
                            'order_item_id' => $obsolete_item_id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            $results['end_time'] = current_time('mysql');
            $results['duration'] = round(microtime(true) - $start_time, 2);

            $this->logger->info('Roster reconciliation completed', $results);

            return $results;

        } catch (\Exception $e) {
            $results['end_time'] = current_time('mysql');
            $results['duration'] = round(microtime(true) - $start_time, 2);
            $results['errors']++;
            $results['error_messages'][] = 'Reconciliation failed: ' . $e->getMessage();

            $this->logger->error('Roster reconciliation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $results;
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
            $data = $meta->get_data();
            $raw_key = isset($data['key']) ? (string) $data['key'] : '';
            $value = $data['value'] ?? '';

            if ($raw_key === '') {
                continue;
            }

            $canonical_key = $this->normalizeOrderMetaKey($raw_key);

            if (isset($this->order_meta_field_map[$canonical_key])) {
                $field = $this->order_meta_field_map[$canonical_key];

                if ($field === 'activity_type') {
                    $order_data[$field] = $this->normalizeActivityTypeValue($value);
                } else {
                    $order_data[$field] = $value;
                }
                continue;
            }

            // Legacy support for keys stored using raw attribute slugs.
            if ($canonical_key === 'assigned_player' || $raw_key === 'assigned_player') {
                $order_data['player_index'] = $value;
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
     * Normalize localized order metadata keys to their canonical English equivalents.
     *
     * @param string $raw_key Metadata key as stored on the order item.
     * @return string Canonical English key if detected, otherwise the original key.
     */
    private function normalizeOrderMetaKey(string $raw_key): string {
        if ($raw_key === '') {
            return $raw_key;
        }

        if (isset($this->order_meta_key_cache[$raw_key])) {
            return $this->order_meta_key_cache[$raw_key];
        }

        $comparison_value = $this->normalizeComparisonString($raw_key);

        foreach ($this->getOrderMetaKeyVariants() as $canonical => $variants) {
            if (in_array($comparison_value, $variants, true)) {
                $this->order_meta_key_cache[$raw_key] = $canonical;
                return $canonical;
            }
        }

        $this->order_meta_key_cache[$raw_key] = $raw_key;
        return $raw_key;
    }

    /**
     * Build and cache the list of known metadata key variants across languages.
     *
     * @return array<string,array<int,string>>
     */
    private function getOrderMetaKeyVariants(): array {
        if ($this->order_meta_key_variants !== null) {
            return $this->order_meta_key_variants;
        }

        $variants = [];
        foreach (array_keys($this->order_meta_field_map) as $canonical) {
            $variants[$canonical] = [$this->normalizeComparisonString($canonical)];
        }

        // Manual aliases for known translations / wording variations.
        $manual_aliases = [
            'InterSoccer Venues' => [
                'lieux intersoccer',
                'lieu intersoccer',
                'intersoccer-standorte',
            ],
            'Age Group' => [
                'groupe dage',
                'groupe d age',
                'groupe d\'âge',
                'altersgruppe',
            ],
            'Camp Terms' => [
                'conditions de camp',
                'camp begriffe',
            ],
            'Camp Times' => [
                'horaires du camp',
                'camp zeiten',
            ],
            'Course Day' => [
                'jour de cours',
                'kurstag',
            ],
            'Course Times' => [
                'horaires du cours',
                'kurszeiten',
            ],
            'Booking Type' => [
                'type de réservation',
                'buchungstyp',
            ],
            'Assigned Attendee' => [
                'participant assigné',
                'zugewiesener teilnehmer',
            ],
            'Days Selected' => [
                'jours sélectionnés',
                'ausgewählte tage',
            ],
            'Season' => [
                'saison',
                'saison (programm)',
                'jahreszeit',
            ],
            'Canton / Region' => [
                'canton region',
                'canton / région',
                'kanton region',
            ],
            'City' => [
                'ville',
                'stadt',
            ],
            'Activity Type' => [
                'type d activite',
                'type d\'activite',
                'type d’activité',
                'type d\'activité',
                'aktivitätstyp',
            ],
            'Start Date' => [
                'date de début',
                'startdatum',
            ],
            'End Date' => [
                'date de fin',
                'enddatum',
            ],
            'Holidays' => [
                'vacances',
                'ferien',
            ],
            'Discount' => [
                'remise',
                'rabatt',
            ],
            'Discount Amount' => [
                'montant de la remise',
                'rabattbetrag',
            ],
            'Base Price' => [
                'prix de base',
                'grundpreis',
            ],
            'Remaining Sessions' => [
                'séances restantes',
                'verbleibende termine',
            ],
            'Late Pickup Type' => [
                'type de ramassage tardif',
                'späte abholung typ',
            ],
            'Late Pickup Days' => [
                'jours de ramassage tardif',
                'tage für späte abholung',
            ],
            'Late Pickup Cost' => [
                'coût ramassage tardif',
                'kosten späte abholung',
            ],
            'Variation ID' => [
                'id de variation',
                'varianten id',
            ],
        ];

        foreach ($manual_aliases as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $variants[$canonical][] = $this->normalizeComparisonString($alias);
            }
        }

        // Include dynamic WPML translations when available.
        $can_switch_language = function_exists('wpml_get_active_languages') && function_exists('wpml_get_current_language') && function_exists('icl_t');

        if ($can_switch_language) {
            $active_languages = wpml_get_active_languages();
            $original_language = wpml_get_current_language();

            if (!empty($active_languages)) {
                foreach (array_keys($active_languages) as $language_code) {
                    do_action('wpml_switch_language', $language_code);

                    foreach (array_keys($this->order_meta_field_map) as $canonical) {
                        $translated = icl_t('intersoccer-product-variations', $canonical, $canonical);
                        if (!empty($translated)) {
                            $variants[$canonical][] = $this->normalizeComparisonString($translated);
                        }
                    }
                }

                // Restore the original language context
                if (!empty($original_language)) {
                    do_action('wpml_switch_language', $original_language);
                }
            }
        }

        foreach ($variants as &$variant_list) {
            $variant_list = array_values(array_unique(array_filter($variant_list)));
        }

        return $this->order_meta_key_variants = $variants;
    }

    /**
     * Normalize a string for reliable comparisons (lowercase, accent-free).
     *
     * @param string $value Input string.
     * @return string
     */
    private function normalizeComparisonString(string $value): string {
        $normalized = strtolower(trim($value));

        if (function_exists('remove_accents')) {
            $normalized = remove_accents($normalized);
        }

        // Remove punctuation (keep slashes for compound keys like "Canton / Region")
        $normalized = preg_replace('/[^a-z0-9\/ ]+/u', '', $normalized);

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * Normalize activity type values across languages.
     *
     * @param mixed $value Raw value from order meta.
     * @return string Canonical activity type.
     */
    private function normalizeActivityTypeValue($value): string {
        if (!is_string($value) || $value === '') {
            return (string) $value;
        }

        $normalized = $this->normalizeComparisonString($value);

        $map = [
            'camp' => 'Camp',
            'cours' => 'Course',
            'course' => 'Course',
            'stage' => 'Course',
            'training' => 'Course',
            'anniversaire' => 'Birthday Party',
            'birthday' => 'Birthday Party',
            'birthday party' => 'Birthday Party',
        ];

        foreach ($map as $needle => $canonical) {
            if (strpos($normalized, $needle) !== false) {
                return $canonical;
            }
        }

        // Default to original human readable value to preserve context.
        return ucwords(trim($value));
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