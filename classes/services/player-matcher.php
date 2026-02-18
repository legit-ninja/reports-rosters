<?php
/**
 * Player Matcher Service
 * 
 * Handles matching players to WooCommerce order items.
 * Manages player assignment logic and validates player-event compatibility.
 * 
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Player Matcher Service
 * 
 * Sophisticated player assignment and validation system
 */
class PlayerMatcher {
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Player assignment cache
     * 
     * @var array
     */
    private $assignment_cache = [];
    
    /**
     * Assignment strategies
     * 
     * @var array
     */
    private $assignment_strategies = [
        'metadata_index', // assigned_player index in metadata
        'attendee_name',  // Assigned Attendee metadata
        'order_quantity', // Based on order quantity
        'customer_players' // All customer players
    ];
    
    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }
    
    /**
     * Get assigned players for an order item
     * 
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $customer_players Available customer players
     * @param array $options Optional. 'skip_age_gender_validation' => true to skip age/gender checks when building rosters.
     * @return PlayersCollection Assigned players
     */
    public function getAssignedPlayers(\WC_Order_Item_Product $item, PlayersCollection $customer_players, array $options = []) {
        try {
            $cache_key = $this->buildCacheKey($item, $customer_players, $options);
            
            if (isset($this->assignment_cache[$cache_key])) {
                $this->logger->debug('Retrieved player assignment from cache', [
                    'item_id' => $item->get_id(),
                    'cache_key' => $cache_key
                ]);
                return $this->assignment_cache[$cache_key];
            }
            
            $this->logger->debug('Matching players to order item', [
                'item_id' => $item->get_id(),
                'available_players' => $customer_players->count(),
                'product_id' => $item->get_product_id()
            ]);
            
            $assigned_players = new PlayersCollection();
            
            // Try each assignment strategy in order
            foreach ($this->assignment_strategies as $strategy) {
                $strategy_players = $this->applyAssignmentStrategy($strategy, $item, $customer_players);
                
                if ($strategy_players->isNotEmpty()) {
                    $assigned_players = $strategy_players;
                    $this->logger->debug('Successfully assigned players using strategy', [
                        'strategy' => $strategy,
                        'assigned_count' => $assigned_players->count(),
                        'item_id' => $item->get_id()
                    ]);
                    break;
                }
            }
            
            // Validate player assignments (age/gender can be skipped for roster build)
            $validated_players = $this->validatePlayerAssignments($assigned_players, $item, $options);
            
            // Cache the result
            $this->assignment_cache[$cache_key] = $validated_players;
            
            $this->logger->info('Player assignment completed', [
                'item_id' => $item->get_id(),
                'assigned_players' => $validated_players->count(),
                'player_names' => $validated_players->pluck('first_name')->values()
            ]);
            
            return $validated_players;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to assign players to order item', [
                'item_id' => $item->get_id(),
                'error' => $e->getMessage()
            ]);
            
            return new PlayersCollection();
        }
    }
    
    /**
     * Apply specific assignment strategy
     * 
     * @param string $strategy Assignment strategy name
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $customer_players Available players
     * @return PlayersCollection Assigned players
     */
    private function applyAssignmentStrategy($strategy, \WC_Order_Item_Product $item, PlayersCollection $customer_players) {
        $this->logger->debug('Applying assignment strategy', [
            'strategy' => $strategy,
            'item_id' => $item->get_id()
        ]);
        
        switch ($strategy) {
            case 'metadata_index':
                return $this->assignByMetadataIndex($item, $customer_players);
                
            case 'attendee_name':
                return $this->assignByAttendeeName($item, $customer_players);
                
            case 'order_quantity':
                return $this->assignByOrderQuantity($item, $customer_players);
                
            case 'customer_players':
                return $this->assignAllCustomerPlayers($item, $customer_players);
                
            default:
                $this->logger->warning('Unknown assignment strategy', ['strategy' => $strategy]);
                return new PlayersCollection();
        }
    }
    
    /**
     * Assign player by metadata index (assigned_player field)
     * 
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $customer_players Available players
     * @return PlayersCollection Assigned players
     */
    private function assignByMetadataIndex(\WC_Order_Item_Product $item, PlayersCollection $customer_players) {
        $assigned_player_index = $item->get_meta('assigned_player');
        
        if ($assigned_player_index !== '') {
            $player_index = (int) $assigned_player_index;
            
            $assigned_player = $customer_players->firstWhere('player_index', $player_index);
            
            if ($assigned_player) {
                $this->logger->debug('Found player by metadata index', [
                    'player_index' => $player_index,
                    'player_name' => $assigned_player->getFullName()
                ]);
                
                $collection = new PlayersCollection();
                $collection->add($assigned_player);
                return $collection;
            } else {
                $this->logger->warning('Player not found for metadata index', [
                    'player_index' => $player_index,
                    'available_players' => $customer_players->count()
                ]);
            }
        }
        
        return new PlayersCollection();
    }
    
    /**
     * Assign player by attendee name
     * 
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $customer_players Available players
     * @return PlayersCollection Assigned players
     */
    /**
     * Resolve assigned attendee name from order item meta, supporting localized meta keys
     * (e.g. "Participant assigné", "Zugewiesener Teilnehmer") so roster build works in any language.
     *
     * @param \WC_Order_Item_Product $item Order item
     * @return string Attendee name or empty string
     */
    private function getAssignedAttendeeValue(\WC_Order_Item_Product $item) {
        $value = $item->get_meta('Assigned Attendee');
        if ($value !== '' && $value !== null) {
            return (string) $value;
        }
        $normalized_variants = [
            $this->normalizeMetaKeyForComparison('Assigned Attendee'),
            $this->normalizeMetaKeyForComparison('Participant assigné'),
            $this->normalizeMetaKeyForComparison('Zugewiesener Teilnehmer'),
        ];
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $key = isset($data['key']) ? (string) $data['key'] : '';
            if ($key === '') {
                continue;
            }
            $normalized = $this->normalizeMetaKeyForComparison($key);
            if (in_array($normalized, $normalized_variants, true)) {
                $val = $data['value'] ?? '';
                return $val !== null ? (string) $val : '';
            }
        }
        return '';
    }

    /**
     * Normalize a meta key for comparison (language-agnostic).
     *
     * @param string $key Meta key
     * @return string Normalized key
     */
    private function normalizeMetaKeyForComparison(string $key): string {
        if (function_exists('intersoccer_normalize_comparison_string')) {
            return intersoccer_normalize_comparison_string($key);
        }
        $n = strtolower(trim($key));
        if (function_exists('remove_accents')) {
            $n = remove_accents($n);
        }
        $n = preg_replace('/[^a-z0-9\s]/u', '', $n);
        $n = preg_replace('/\s+/', ' ', $n);
        return trim($n);
    }

    private function assignByAttendeeName(\WC_Order_Item_Product $item, PlayersCollection $customer_players) {
        $assigned_attendee = $this->getAssignedAttendeeValue($item);
        if (!empty($assigned_attendee)) {
            // Try exact match first
            $exact_match = $customer_players->first(function(Player $player) use ($assigned_attendee) {
                return $player->getFullName() === $assigned_attendee;
            });
            
            if ($exact_match) {
                $this->logger->debug('Found player by exact name match', [
                    'attendee_name' => $assigned_attendee,
                    'player_name' => $exact_match->getFullName()
                ]);
                
                $collection = new PlayersCollection();
                $collection->add($exact_match);
                return $collection;
            }
            
            // Try fuzzy matching
            $fuzzy_match = $this->findPlayerByFuzzyName($assigned_attendee, $customer_players);
            
            if ($fuzzy_match) {
                $this->logger->debug('Found player by fuzzy name match', [
                    'attendee_name' => $assigned_attendee,
                    'player_name' => $fuzzy_match->getFullName(),
                    'confidence' => $this->calculateNameMatchConfidence($assigned_attendee, $fuzzy_match->getFullName())
                ]);
                
                $collection = new PlayersCollection();
                $collection->add($fuzzy_match);
                return $collection;
            }
            
            $this->logger->warning('No player found for assigned attendee', [
                'assigned_attendee' => $assigned_attendee,
                'available_players' => $customer_players->pluck('full_name')->values()
            ]);
        }
        
        return new PlayersCollection();
    }
    
    /**
     * Assign players based on order quantity
     * 
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $customer_players Available players
     * @return PlayersCollection Assigned players
     */
    private function assignByOrderQuantity(\WC_Order_Item_Product $item, PlayersCollection $customer_players) {
        $quantity = $item->get_quantity();
        
        if ($quantity > 0 && $quantity <= $customer_players->count()) {
            // Take the first N players based on quantity
            $assigned_players = $customer_players->take($quantity);
            
            $this->logger->debug('Assigned players by quantity', [
                'quantity' => $quantity,
                'assigned_count' => $assigned_players->count(),
                'player_names' => $assigned_players->pluck('full_name')->values()
            ]);
            
            return $assigned_players;
        }
        
        return new PlayersCollection();
    }
    
    /**
     * Assign all customer players (fallback strategy)
     * 
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $customer_players Available players
     * @return PlayersCollection Assigned players
     */
    private function assignAllCustomerPlayers(\WC_Order_Item_Product $item, PlayersCollection $customer_players) {
        if ($customer_players->isNotEmpty()) {
            $this->logger->debug('Using fallback strategy - assigning all customer players', [
                'player_count' => $customer_players->count(),
                'item_id' => $item->get_id()
            ]);
            
            return $customer_players;
        }
        
        return new PlayersCollection();
    }
    
    /**
     * Find player by fuzzy name matching
     * 
     * @param string $search_name Name to search for
     * @param PlayersCollection $players Available players
     * @return Player|null Matched player or null
     */
    private function findPlayerByFuzzyName($search_name, PlayersCollection $players) {
        $best_match = null;
        $highest_confidence = 0.7; // Minimum confidence threshold
        
        foreach ($players as $player) {
            $confidence = $this->calculateNameMatchConfidence($search_name, $player->getFullName());
            
            if ($confidence > $highest_confidence) {
                $highest_confidence = $confidence;
                $best_match = $player;
            }
            
            // Also check reversed name order
            $reversed_confidence = $this->calculateNameMatchConfidence(
                $search_name, 
                $player->last_name . ' ' . $player->first_name
            );
            
            if ($reversed_confidence > $highest_confidence) {
                $highest_confidence = $reversed_confidence;
                $best_match = $player;
            }
        }
        
        return $best_match;
    }
    
    /**
     * Calculate name matching confidence
     * 
     * @param string $name1 First name
     * @param string $name2 Second name
     * @return float Confidence score (0-1)
     */
    private function calculateNameMatchConfidence($name1, $name2) {
        $name1 = strtolower(trim($name1));
        $name2 = strtolower(trim($name2));
        
        // Exact match
        if ($name1 === $name2) {
            return 1.0;
        }
        
        // Remove common prefixes/suffixes and normalize
        $name1_clean = $this->cleanNameForMatching($name1);
        $name2_clean = $this->cleanNameForMatching($name2);
        
        if ($name1_clean === $name2_clean) {
            return 0.95;
        }
        
        // Calculate Levenshtein distance
        $max_len = max(strlen($name1_clean), strlen($name2_clean));
        if ($max_len === 0) {
            return 0;
        }
        
        $distance = levenshtein($name1_clean, $name2_clean);
        $similarity = 1 - ($distance / $max_len);
        
        // Boost similarity if all words match (different order)
        $words1 = explode(' ', $name1_clean);
        $words2 = explode(' ', $name2_clean);
        
        if (count($words1) === count($words2)) {
            $word_matches = 0;
            foreach ($words1 as $word1) {
                if (in_array($word1, $words2)) {
                    $word_matches++;
                }
            }
            
            if ($word_matches === count($words1)) {
                $similarity = max($similarity, 0.9); // High similarity for word order difference
            }
        }
        
        return $similarity;
    }
    
    /**
     * Clean name for matching
     * 
     * @param string $name Name to clean
     * @return string Cleaned name
     */
    private function cleanNameForMatching($name) {
        $name = strtolower(trim($name));
        
        // Remove common prefixes and suffixes
        $prefixes = ['mr.', 'mrs.', 'ms.', 'dr.', 'prof.'];
        $suffixes = ['jr.', 'sr.', 'ii', 'iii'];
        
        foreach ($prefixes as $prefix) {
            if (strpos($name, $prefix) === 0) {
                $name = trim(substr($name, strlen($prefix)));
            }
        }
        
        foreach ($suffixes as $suffix) {
            if (substr($name, -strlen($suffix)) === $suffix) {
                $name = trim(substr($name, 0, -strlen($suffix)));
            }
        }
        
        // Remove extra spaces
        $name = preg_replace('/\s+/', ' ', $name);
        
        return $name;
    }
    
    /**
     * Validate player assignments
     * 
     * @param PlayersCollection $assigned_players Assigned players
     * @param \WC_Order_Item_Product $item Order item
     * @return PlayersCollection Validated players
     */
    private function validatePlayerAssignments(PlayersCollection $assigned_players, \WC_Order_Item_Product $item, array $options = []) {
        if ($assigned_players->isEmpty()) {
            return $assigned_players;
        }
        
        $validated_players = new PlayersCollection();
        $skip_age_gender = !empty($options['skip_age_gender_validation']);

        foreach ($assigned_players as $player) {
            try {
                $this->validatePlayerForItem($player, $item, $skip_age_gender);
                $validated_players->add($player);
            } catch (ValidationException $e) {
                $this->logger->warning('Player failed validation for item', [
                    'player_id' => $player->getUniqueId(),
                    'player_name' => $player->getFullName(),
                    'item_id' => $item->get_id(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $validated_players;
    }
    
    /**
     * Validate individual player for order item.
     *
     * @param Player $player Player to validate
     * @param \WC_Order_Item_Product $item Order item
     * @param bool $skip_age_gender_validation If true, skip age and gender checks (e.g. when building rosters so explicit customer assignment is honoured).
     * @return void
     * @throws ValidationException If validation fails
     */
    private function validatePlayerForItem(Player $player, \WC_Order_Item_Product $item, $skip_age_gender_validation = false) {
        if (!$player->isComplete()) {
            $missing = $player->getMissingInformation();
            throw new ValidationException('Player has incomplete information: ' . implode(', ', $missing));
        }

        if ($skip_age_gender_validation) {
            return;
        }

        $age_group = $item->get_meta('Age Group');
        $start_date = $item->get_meta('Start Date');

        if (!empty($age_group)) {
            $event_date = !empty($start_date) ? $start_date : null;
            if (!$player->isEligibleForAgeGroup($age_group, $event_date)) {
                $age = $event_date ? $player->getAgeAt($event_date) : $player->getAge();
                throw new ValidationException(
                    "Player age ({$age}) not eligible for age group: {$age_group}"
                );
            }
        }

        $event_type = $item->get_meta('Camp Terms') ?: $item->get_meta('Event Type');
        if (!empty($event_type) && stripos($event_type, 'girls') !== false) {
            if (strtolower($player->gender) !== 'female') {
                throw new ValidationException('Player gender not eligible for girls-only event');
            }
        }
    }
    
    /**
     * Build cache key for player assignment
     *
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $customer_players Available players
     * @param array $options Options that affect result (e.g. skip_age_gender_validation)
     * @return string Cache key
     */
    private function buildCacheKey(\WC_Order_Item_Product $item, PlayersCollection $customer_players, array $options = []) {
        $key_parts = [
            $item->get_id(),
            $item->get_product_id(),
            $item->get_variation_id(),
            $customer_players->count(),
            md5(serialize($customer_players->pluck('player_index')->sort()->values())),
            !empty($options['skip_age_gender_validation']) ? '1' : '0',
        ];
        return implode('_', array_filter($key_parts));
    }
    
    /**
     * Get assignment suggestions for manual review
     * 
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $customer_players Available players
     * @return array Assignment suggestions
     */
    public function getAssignmentSuggestions(\WC_Order_Item_Product $item, PlayersCollection $customer_players) {
        $suggestions = [
            'automatic_assignment' => null,
            'alternative_assignments' => [],
            'validation_issues' => [],
            'recommendations' => []
        ];
        
        try {
            // Get automatic assignment
            $automatic = $this->getAssignedPlayers($item, $customer_players);
            if ($automatic->isNotEmpty()) {
                $suggestions['automatic_assignment'] = [
                    'players' => $automatic->map(function(Player $player) {
                        return [
                            'id' => $player->getUniqueId(),
                            'name' => $player->getFullName(),
                            'age' => $player->getAge(),
                            'gender' => $player->gender
                        ];
                    })->toArray(),
                    'confidence' => $this->calculateAssignmentConfidence($automatic, $item)
                ];
            }
            
            // Get alternative assignments
            foreach ($customer_players as $player) {
                try {
                    $this->validatePlayerForItem($player, $item);
                    
                    $suggestions['alternative_assignments'][] = [
                        'id' => $player->getUniqueId(),
                        'name' => $player->getFullName(),
                        'age' => $player->getAge(),
                        'gender' => $player->gender,
                        'eligible' => true,
                        'issues' => []
                    ];
                    
                } catch (ValidationException $e) {
                    $suggestions['alternative_assignments'][] = [
                        'id' => $player->getUniqueId(),
                        'name' => $player->getFullName(),
                        'age' => $player->getAge(),
                        'gender' => $player->gender,
                        'eligible' => false,
                        'issues' => [$e->getMessage()]
                    ];
                }
            }
            
            // Add recommendations
            if ($automatic->isEmpty()) {
                $suggestions['recommendations'][] = 'No automatic assignment could be made. Please review available players.';
            }
            
            $age_group = $item->get_meta('Age Group');
            if (!empty($age_group)) {
                $eligible_count = count(array_filter($suggestions['alternative_assignments'], function($alt) {
                    return $alt['eligible'];
                }));
                
                $suggestions['recommendations'][] = "{$eligible_count} of {$customer_players->count()} players are eligible for age group: {$age_group}";
            }
            
        } catch (\Exception $e) {
            $suggestions['validation_issues'][] = 'Error generating suggestions: ' . $e->getMessage();
        }
        
        return $suggestions;
    }
    
    /**
     * Calculate assignment confidence score
     * 
     * @param PlayersCollection $assigned_players Assigned players
     * @param \WC_Order_Item_Product $item Order item
     * @return float Confidence score (0-1)
     */
    private function calculateAssignmentConfidence(PlayersCollection $assigned_players, \WC_Order_Item_Product $item) {
        if ($assigned_players->isEmpty()) {
            return 0.0;
        }
        
        $confidence_factors = [];
        
        // Factor 1: Assignment method used
        $assigned_attendee = $item->get_meta('Assigned Attendee');
        $assigned_player_index = $item->get_meta('assigned_player');
        
        if (!empty($assigned_player_index)) {
            $confidence_factors[] = 0.95; // High confidence for direct index assignment
        } elseif (!empty($assigned_attendee)) {
            // Calculate name match confidence
            $name_confidence = $this->calculateNameMatchConfidence(
                $assigned_attendee,
                $assigned_players->first()->getFullName()
            );
            $confidence_factors[] = $name_confidence;
        } else {
            $confidence_factors[] = 0.5; // Medium confidence for quantity/fallback assignment
        }
        
        // Factor 2: Player eligibility
        $eligible_count = 0;
        foreach ($assigned_players as $player) {
            try {
                $this->validatePlayerForItem($player, $item);
                $eligible_count++;
            } catch (ValidationException $e) {
                // Player not eligible
            }
        }
        
        $eligibility_ratio = $eligible_count / $assigned_players->count();
        $confidence_factors[] = $eligibility_ratio;
        
        // Factor 3: Data completeness
        $complete_players = $assigned_players->filterByCompleteness(true);
        $completeness_ratio = $complete_players->count() / $assigned_players->count();
        $confidence_factors[] = $completeness_ratio;
        
        // Calculate overall confidence (weighted average)
        return array_sum($confidence_factors) / count($confidence_factors);
    }
    
    /**
     * Manual assignment of players to order item
     * 
     * @param \WC_Order_Item_Product $item Order item
     * @param array $player_ids Array of player IDs (customer_id_player_index format)
     * @return PlayersCollection Assigned players
     */
    public function manualAssignPlayers(\WC_Order_Item_Product $item, array $player_ids) {
        try {
            $this->logger->info('Manual player assignment requested', [
                'item_id' => $item->get_id(),
                'player_ids' => $player_ids
            ]);
            
            $assigned_players = new PlayersCollection();
            
            // Get customer ID from order
            $order = $item->get_order();
            $customer_id = $order->get_customer_id();
            
            // Validate and collect players
            foreach ($player_ids as $player_id) {
                // Extract customer_id and player_index from ID format
                if (!preg_match('/^(\d+)_(\d+)$/', $player_id, $matches)) {
                    throw new ValidationException("Invalid player ID format: {$player_id}");
                }
                
                $extracted_customer_id = (int) $matches[1];
                $player_index = (int) $matches[2];
                
                // Verify customer ID matches order
                if ($extracted_customer_id !== $customer_id) {
                    throw new ValidationException("Player {$player_id} does not belong to order customer");
                }
                
                // Load player from metadata
                $player_data = get_user_meta($customer_id, 'intersoccer_players', true);
                
                if (!isset($player_data[$player_index])) {
                    throw new ValidationException("Player not found: {$player_id}");
                }
                
                $player = Player::fromUserMetadata($customer_id, $player_index, $player_data[$player_index]);
                
                // Validate player for this item
                $this->validatePlayerForItem($player, $item);
                
                $assigned_players->add($player);
            }
            
            // Update order item metadata with manual assignment
            $this->updateOrderItemWithAssignment($item, $assigned_players);
            
            // Clear cache for this item
            $this->clearCacheForItem($item);
            
            $this->logger->info('Manual player assignment completed', [
                'item_id' => $item->get_id(),
                'assigned_players' => $assigned_players->count(),
                'player_names' => $assigned_players->pluck('full_name')->values()
            ]);
            
            return $assigned_players;
            
        } catch (\Exception $e) {
            $this->logger->error('Manual player assignment failed', [
                'item_id' => $item->get_id(),
                'player_ids' => $player_ids,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Update order item metadata with player assignment
     * 
     * @param \WC_Order_Item_Product $item Order item
     * @param PlayersCollection $assigned_players Assigned players
     * @return void
     */
    private function updateOrderItemWithAssignment(\WC_Order_Item_Product $item, PlayersCollection $assigned_players) {
        if ($assigned_players->count() === 1) {
            $player = $assigned_players->first();
            
            // Update metadata for single player assignment
            $item->update_meta_data('assigned_player', $player->player_index);
            $item->update_meta_data('Assigned Attendee', $player->getFullName());
        } elseif ($assigned_players->count() > 1) {
            // For multiple players, store as comma-separated list
            $names = $assigned_players->pluck('full_name')->values();
            $indexes = $assigned_players->pluck('player_index')->values();
            
            $item->update_meta_data('assigned_player', implode(',', $indexes));
            $item->update_meta_data('Assigned Attendee', implode(', ', $names));
        }
        
        $item->save_meta_data();
        
        $this->logger->debug('Updated order item metadata with player assignment', [
            'item_id' => $item->get_id(),
            'assigned_players' => $assigned_players->count()
        ]);
    }
    
    /**
     * Clear assignment cache for specific item
     * 
     * @param \WC_Order_Item_Product $item Order item
     * @return void
     */
    private function clearCacheForItem(\WC_Order_Item_Product $item) {
        // Remove all cache entries that contain this item ID
        $item_id = $item->get_id();
        
        $keys_to_remove = [];
        foreach (array_keys($this->assignment_cache) as $cache_key) {
            if (strpos($cache_key, $item_id . '_') === 0) {
                $keys_to_remove[] = $cache_key;
            }
        }
        
        foreach ($keys_to_remove as $key) {
            unset($this->assignment_cache[$key]);
        }
        
        $this->logger->debug('Cleared assignment cache for item', [
            'item_id' => $item_id,
            'cleared_entries' => count($keys_to_remove)
        ]);
    }
    
    /**
     * Get assignment statistics
     * 
     * @return array Assignment statistics
     */
    public function getAssignmentStatistics() {
        return [
            'cached_assignments' => count($this->assignment_cache),
            'assignment_strategies' => $this->assignment_strategies,
            'cache_memory_usage' => strlen(serialize($this->assignment_cache))
        ];
    }
    
    /**
     * Clear all assignment cache
     * 
     * @return void
     */
    public function clearCache() {
        $cache_count = count($this->assignment_cache);
        $this->assignment_cache = [];
        
        $this->logger->debug('Cleared all player assignment cache', [
            'cleared_entries' => $cache_count
        ]);
    }
    
    /**
     * Add custom assignment strategy
     * 
     * @param string $strategy_name Strategy name
     * @param int $priority Priority (lower numbers = higher priority)
     * @return void
     */
    public function addAssignmentStrategy($strategy_name, $priority = null) {
        if ($priority !== null) {
            array_splice($this->assignment_strategies, $priority, 0, $strategy_name);
        } else {
            $this->assignment_strategies[] = $strategy_name;
        }
        
        $this->logger->debug('Added custom assignment strategy', [
            'strategy' => $strategy_name,
            'priority' => $priority
        ]);
    }
    
    /**
     * Match a player by index from a collection
     * 
     * @param int $player_index Player index to find
     * @param PlayersCollection $players Collection to search
     * @return Player|null Matched player or null
     */
    public function matchByIndex($player_index, PlayersCollection $players) {
        foreach ($players as $player) {
            if (isset($player->player_index) && $player->player_index == $player_index) {
                $this->logger->debug('Matched player by index', [
                    'player_index' => $player_index,
                    'player' => $player->first_name . ' ' . $player->last_name
                ]);
                return $player;
            }
        }
        
        $this->logger->warning('No player found for index', [
            'player_index' => $player_index,
            'available_players' => $players->count()
        ]);
        
        return null;
    }
}