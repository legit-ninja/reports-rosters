<?php
/**
 * Players Collection Class
 * 
 * Specialized collection for Player model instances with player-specific operations.
 * Provides advanced filtering, grouping, and analysis capabilities for player data.
 * 
 * @package InterSoccer\ReportsRosters\Data\Collections
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Data\Collections;

use InterSoccer\ReportsRosters\Data\Collections\AbstractCollection;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Models\AbstractModel;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Players Collection Class
 * 
 * Specialized collection for managing Player model instances
 */
class PlayersCollection extends AbstractCollection {
    
    /**
     * Add a player to the collection
     * 
     * @param Player $player Player to add
     * @return self
     * @throws \InvalidArgumentException If item is not a Player instance
     */
    public function add(AbstractModel $player) {
        if (!$player instanceof Player) {
            throw new \InvalidArgumentException('PlayersCollection can only contain Player instances');
        }
        
        return parent::add($player);
    }
    
    /**
     * Filter players by age range
     * 
     * @param int $min_age Minimum age
     * @param int $max_age Maximum age
     * @param string|null $event_date Date for age calculation (default: today)
     * @return static New filtered collection
     */
    public function filterByAge($min_age, $max_age, $event_date = null) {
        return $this->filter(function(Player $player) use ($min_age, $max_age, $event_date) {
            $age = $event_date ? $player->getAgeAt($event_date) : $player->getAge();
            return $age >= $min_age && $age <= $max_age;
        });
    }
    
    /**
     * Filter players by gender
     * 
     * @param string $gender Gender to filter by (male, female, other)
     * @return static New filtered collection
     */
    public function filterByGender($gender) {
        return $this->where('gender', $gender);
    }
    
    /**
     * Filter players with medical conditions
     * 
     * @param bool $has_conditions True to get players with conditions, false for without
     * @return static New filtered collection
     */
    public function filterByMedicalConditions($has_conditions = true) {
        return $this->filter(function(Player $player) use ($has_conditions) {
            return $player->hasMedicalConditions() === $has_conditions;
        });
    }
    
    /**
     * Filter players with dietary needs
     * 
     * @param bool $has_needs True to get players with needs, false for without
     * @return static New filtered collection
     */
    public function filterByDietaryNeeds($has_needs = true) {
        return $this->filter(function(Player $player) use ($has_needs) {
            return $player->hasDietaryNeeds() === $has_needs;
        });
    }
    
    /**
     * Filter players with any special needs (medical or dietary)
     * 
     * @param bool $has_special_needs True to get players with special needs
     * @return static New filtered collection
     */
    public function filterBySpecialNeeds($has_special_needs = true) {
        return $this->filter(function(Player $player) use ($has_special_needs) {
            return $player->hasSpecialNeeds() === $has_special_needs;
        });
    }
    
    /**
     * Filter players eligible for a specific age group
     * 
     * @param string $age_group Age group specification (e.g., "5-13y (Full Day)")
     * @param string|null $event_date Event date for eligibility check
     * @return static New filtered collection
     */
    public function filterByAgeGroupEligibility($age_group, $event_date = null) {
        return $this->filter(function(Player $player) use ($age_group, $event_date) {
            return $player->isEligibleForAgeGroup($age_group, $event_date);
        });
    }
    
    /**
     * Filter players by customer ID
     * 
     * @param int $customer_id WordPress user ID
     * @return static New filtered collection
     */
    public function filterByCustomer($customer_id) {
        return $this->where('customer_id', $customer_id);
    }
    
    /**
     * Filter players with complete profiles
     * 
     * @param bool $is_complete True to get complete profiles, false for incomplete
     * @return static New filtered collection
     */
    public function filterByCompleteness($is_complete = true) {
        return $this->filter(function(Player $player) use ($is_complete) {
            return $player->isComplete() === $is_complete;
        });
    }
    
    /**
     * Search players by name (partial match)
     * 
     * @param string $search_term Search term for name
     * @return static New filtered collection
     */
    public function searchByName($search_term) {
        $search_term = strtolower(trim($search_term));
        
        return $this->filter(function(Player $player) use ($search_term) {
            $full_name = strtolower($player->getFullName());
            return strpos($full_name, $search_term) !== false;
        });
    }
    
    /**
     * Get players grouped by age
     * 
     * @param string|null $event_date Date for age calculation
     * @return array Array of collections keyed by age
     */
    public function groupByAge($event_date = null) {
        return $this->groupBy(function(Player $player) use ($event_date) {
            return $event_date ? $player->getAgeAt($event_date) : $player->getAge();
        });
    }
    
    /**
     * Get players grouped by gender
     * 
     * @return array Array of collections keyed by gender
     */
    public function groupByGender() {
        return $this->groupBy('gender');
    }
    
    /**
     * Get players grouped by customer
     * 
     * @return array Array of collections keyed by customer ID
     */
    public function groupByCustomer() {
        return $this->groupBy('customer_id');
    }
    
    /**
     * Get players grouped by age ranges
     * 
     * @param array $age_ranges Age range definitions (e.g., ['0-5', '6-10', '11+'])
     * @param string|null $event_date Date for age calculation
     * @return array Array of collections keyed by age range
     */
    public function groupByAgeRanges(array $age_ranges = null, $event_date = null) {
        if ($age_ranges === null) {
            $age_ranges = [
                '3-5' => ['min' => 3, 'max' => 5],
                '6-8' => ['min' => 6, 'max' => 8],
                '9-12' => ['min' => 9, 'max' => 12],
                '13+' => ['min' => 13, 'max' => 99]
            ];
        }
        
        $groups = [];
        
        // Initialize groups
        foreach ($age_ranges as $range_name => $range_def) {
            $groups[$range_name] = new static();
        }
        
        // Assign players to groups
        foreach ($this->items as $player) {
            $age = $event_date ? $player->getAgeAt($event_date) : $player->getAge();
            
            foreach ($age_ranges as $range_name => $range_def) {
                if ($age >= $range_def['min'] && $age <= $range_def['max']) {
                    $groups[$range_name]->add($player);
                    break; // Player goes in first matching range
                }
            }
        }
        
        return $groups;
    }
    
    /**
     * Sort players by name (last name, first name)
     * 
     * @param bool $descending Sort descending
     * @return static New sorted collection
     */
    public function sortByName($descending = false) {
        return $this->sortBy(function(Player $player) {
            return $player->last_name . ', ' . $player->first_name;
        }, SORT_STRING, $descending);
    }
    
    /**
     * Sort players by age
     * 
     * @param bool $descending Sort descending (oldest first)
     * @param string|null $event_date Date for age calculation
     * @return static New sorted collection
     */
    public function sortByAge($descending = false, $event_date = null) {
        return $this->sortBy(function(Player $player) use ($event_date) {
            return $event_date ? $player->getAgeAt($event_date) : $player->getAge();
        }, SORT_NUMERIC, $descending);
    }
    
    /**
     * Sort players by date of birth
     * 
     * @param bool $descending Sort descending (newest first)
     * @return static New sorted collection
     */
    public function sortByDateOfBirth($descending = false) {
        return $this->sortBy('dob', SORT_STRING, $descending);
    }
    
    /**
     * Sort players by event count
     * 
     * @param bool $descending Sort descending (most events first)
     * @return static New sorted collection
     */
    public function sortByEventCount($descending = true) {
        return $this->sortBy('event_count', SORT_NUMERIC, $descending);
    }
    
    /**
     * Get statistics about the players in this collection
     * 
     * @param string|null $event_date Date for age calculations
     * @return array Player statistics
     */
    public function getStatistics($event_date = null) {
        if ($this->isEmpty()) {
            return [
                'total_players' => 0,
                'average_age' => 0,
                'age_range' => ['min' => 0, 'max' => 0],
                'gender_distribution' => [],
                'customers_count' => 0,
                'special_needs_count' => 0,
                'incomplete_profiles' => 0,
                'total_events' => 0
            ];
        }
        
        // Calculate ages
        $ages = [];
        foreach ($this->items as $player) {
            $ages[] = $event_date ? $player->getAgeAt($event_date) : $player->getAge();
        }
        
        return [
            'total_players' => $this->count(),
            'average_age' => round(array_sum($ages) / count($ages), 1),
            'age_range' => [
                'min' => min($ages),
                'max' => max($ages)
            ],
            'gender_distribution' => $this->countBy('gender'),
            'customers_count' => $this->pluck('customer_id')->unique()->count(),
            'special_needs_count' => $this->filterBySpecialNeeds(true)->count(),
            'medical_conditions_count' => $this->filterByMedicalConditions(true)->count(),
            'dietary_needs_count' => $this->filterByDietaryNeeds(true)->count(),
            'incomplete_profiles' => $this->filterByCompleteness(false)->count(),
            'total_events' => $this->sum('event_count'),
            'age_group_distribution' => $this->getAgeGroupDistribution($event_date)
        ];
    }
    
    /**
     * Get age group distribution
     * 
     * @param string|null $event_date Date for age calculation
     * @return array Age group distribution
     */
    private function getAgeGroupDistribution($event_date = null) {
        $age_groups = $this->groupByAgeRanges(null, $event_date);
        $distribution = [];
        
        foreach ($age_groups as $range => $players) {
            $distribution[$range] = $players->count();
        }
        
        return $distribution;
    }
    
    /**
     * Get players who need attention (incomplete profiles, special needs, etc.)
     * 
     * @return array Array of attention categories with player collections
     */
    public function getPlayersNeedingAttention() {
        return [
            'incomplete_profiles' => $this->filterByCompleteness(false),
            'medical_conditions' => $this->filterByMedicalConditions(true),
            'dietary_needs' => $this->filterByDietaryNeeds(true),
            'missing_emergency_contact' => $this->filter(function(Player $player) {
                return empty($player->emergency_contact) && empty($player->emergency_phone);
            }),
            'invalid_avs_numbers' => $this->filter(function(Player $player) {
                return !empty($player->avs_number) && !$player->isValidAvsNumber();
            })
        ];
    }
    
    /**
     * Find potential duplicates based on name and date of birth
     * 
     * @return array Array of potential duplicate groups
     */
    public function findPotentialDuplicates() {
        $potential_duplicates = [];
        $processed = [];
        
        foreach ($this->items as $index => $player) {
            if (in_array($index, $processed)) {
                continue;
            }
            
            $matches = [];
            $player_key = $this->getDuplicateKey($player);
            
            foreach ($this->items as $other_index => $other_player) {
                if ($index !== $other_index && !in_array($other_index, $processed)) {
                    $other_key = $this->getDuplicateKey($other_player);
                    
                    if ($player_key === $other_key) {
                        if (empty($matches)) {
                            $matches[] = $player;
                        }
                        $matches[] = $other_player;
                        $processed[] = $other_index;
                    }
                }
            }
            
            if (!empty($matches)) {
                $potential_duplicates[] = new static($matches);
                $processed[] = $index;
            }
        }
        
        return $potential_duplicates;
    }
    
    /**
     * Generate duplicate detection key for a player
     * 
     * @param Player $player Player instance
     * @return string Duplicate key
     */
    private function getDuplicateKey(Player $player) {
        $name = strtolower(trim($player->first_name . ' ' . $player->last_name));
        $dob = $player->dob ?: 'unknown';
        return md5($name . '|' . $dob);
    }
    
    /**
     * Get players eligible for specific age groups
     * 
     * @param array $age_groups Array of age group specifications
     * @param string|null $event_date Event date for eligibility
     * @return array Array of age groups with eligible players
     */
    public function getEligibilityMatrix(array $age_groups, $event_date = null) {
        $matrix = [];
        
        foreach ($age_groups as $age_group) {
            $matrix[$age_group] = $this->filterByAgeGroupEligibility($age_group, $event_date);
        }
        
        return $matrix;
    }
    
    /**
     * Get birthday calendar for players
     * 
     * @param int $year Year for birthday calendar
     * @return array Array of months with birthday players
     */
    public function getBirthdayCalendar($year = null) {
        if ($year === null) {
            $year = date('Y');
        }
        
        $calendar = [];
        
        // Initialize months
        for ($month = 1; $month <= 12; $month++) {
            $calendar[date('F', mktime(0, 0, 0, $month, 1))] = new static();
        }
        
        // Group players by birth month
        foreach ($this->items as $player) {
            if (!empty($player->dob)) {
                $birth_month = date('F', strtotime($player->dob));
                if (isset($calendar[$birth_month])) {
                    $calendar[$birth_month]->add($player);
                }
            }
        }
        
        // Sort players within each month by day
        foreach ($calendar as $month => $players) {
            $calendar[$month] = $players->sortBy(function(Player $player) {
                return date('d', strtotime($player->dob));
            });
        }
        
        return $calendar;
    }
    
    /**
     * Export players data for Excel/CSV
     * 
     * @param array $fields Fields to include in export
     * @return array Export data
     */
    public function exportData(array $fields = []) {
        if (empty($fields)) {
            $fields = [
                'First Name', 'Last Name', 'Full Name', 'Date of Birth', 'Age', 
                'Gender', 'Medical Conditions', 'Dietary Needs', 'AVS Number',
                'Emergency Contact', 'Emergency Phone', 'Event Count'
            ];
        }
        
        $export_data = [];
        
        foreach ($this->items as $player) {
            $player_data = $player->getExportData();
            
            // Filter to requested fields
            $filtered_data = [];
            foreach ($fields as $field) {
                $filtered_data[$field] = $player_data[$field] ?? '';
            }
            
            $export_data[] = $filtered_data;
        }
        
        return $export_data;
    }
    
    /**
     * Generate roster summary for coaches
     * 
     * @param string|null $event_date Event date for age calculations
     * @return array Coach-friendly summary data
     */
    public function getCoachSummary($event_date = null) {
        $players_with_special_needs = $this->filterBySpecialNeeds(true);
        
        return [
            'total_players' => $this->count(),
            'age_distribution' => $this->getAgeGroupDistribution($event_date),
            'gender_split' => $this->countBy('gender'),
            'special_needs_count' => $players_with_special_needs->count(),
            'special_needs_details' => $players_with_special_needs->map(function(Player $player) use ($event_date) {
                return [
                    'name' => $player->getFullName(),
                    'age' => $event_date ? $player->getAgeAt($event_date) : $player->getAge(),
                    'medical_conditions' => $player->medical_conditions ?: 'None',
                    'dietary_needs' => $player->dietary_needs ?: 'None',
                    'emergency_contact' => $player->emergency_contact ?: 'Not provided',
                    'emergency_phone' => $player->emergency_phone ?: 'Not provided'
                ];
            })->toArray(),
            'emergency_contacts' => $this->getEmergencyContactsSummary()
        ];
    }
    
    /**
     * Get emergency contacts summary
     * 
     * @return array Emergency contacts summary
     */
    private function getEmergencyContactsSummary() {
        $contacts = [];
        
        foreach ($this->items as $player) {
            $contact_name = $player->emergency_contact ?: ($player->getFullName() . ' Parent');
            $contact_phone = $player->emergency_phone ?: 'Not provided';
            
            $contacts[] = [
                'player' => $player->getFullName(),
                'contact_name' => $contact_name,
                'contact_phone' => $contact_phone
            ];
        }
        
        return $contacts;
    }
    
    /**
     * Validate all players in the collection
     * 
     * @return array Validation results
     */
    public function validateAll() {
        $validation_results = [
            'valid_count' => 0,
            'invalid_count' => 0,
            'errors' => []
        ];
        
        foreach ($this->items as $index => $player) {
            try {
                $player->validate();
                $validation_results['valid_count']++;
            } catch (\Exception $e) {
                $validation_results['invalid_count']++;
                $validation_results['errors'][$index] = [
                    'player' => $player->getFullName(),
                    'player_id' => $player->getUniqueId(),
                    'errors' => $e->getMessage()
                ];
            }
        }
        
        return $validation_results;
    }
    
    /**
     * Create a new PlayersCollection from array data
     * 
     * @param array $players_data Array of player data
     * @return static New PlayersCollection
     */
    public static function fromArray(array $players_data) {
        $collection = new static();
        
        foreach ($players_data as $player_data) {
            if ($player_data instanceof Player) {
                $collection->add($player_data);
            } elseif (is_array($player_data)) {
                $player = new Player($player_data);
                $collection->add($player);
            }
        }
        
        return $collection;
    }
    
    /**
     * Create a PlayersCollection from WordPress user metadata
     * 
     * @param int $customer_id WordPress user ID
     * @param array $metadata_array Player metadata array
     * @return static New PlayersCollection
     */
    public static function fromUserMetadata($customer_id, array $metadata_array) {
        $collection = new static();
        
        foreach ($metadata_array as $index => $player_data) {
            $player = Player::fromUserMetadata($customer_id, $index, $player_data);
            $collection->add($player);
        }
        
        return $collection;
    }
    
    /**
     * Convert collection to user metadata format
     * 
     * @return array Array formatted for WordPress user metadata
     */
    public function toUserMetadata() {
        $metadata = [];
        
        foreach ($this->items as $player) {
            $metadata[] = $player->toUserMetadata();
        }
        
        return $metadata;
    }
    
    /**
     * Get collection summary for logging
     * 
     * @return array Log-friendly summary
     */
    public function getLogSummary() {
        return [
            'total_players' => $this->count(),
            'customers' => $this->pluck('customer_id')->unique()->count(),
            'age_range' => [
                'min' => $this->min('age'),
                'max' => $this->max('age')
            ],
            'special_needs' => $this->filterBySpecialNeeds(true)->count(),
            'incomplete' => $this->filterByCompleteness(false)->count()
        ];
    }
    
    /**
     * Find players by partial name match
     * 
     * @param string $name_part Part of name to search for
     * @param bool $case_sensitive Case sensitive search
     * @return static New filtered collection
     */
    public function findByName($name_part, $case_sensitive = false) {
        $search_term = $case_sensitive ? $name_part : strtolower($name_part);
        
        return $this->filter(function(Player $player) use ($search_term, $case_sensitive) {
            $full_name = $case_sensitive ? $player->getFullName() : strtolower($player->getFullName());
            return strpos($full_name, $search_term) !== false;
        });
    }
    
    /**
     * Get players who haven't attended any events
     * 
     * @return static New filtered collection
     */
    public function getInactivePlayers() {
        return $this->where('event_count', 0);
    }
    
    /**
     * Get most active players
     * 
     * @param int $limit Number of players to return
     * @return static New sorted and limited collection
     */
    public function getMostActiveUsers($limit = 10) {
        return $this->sortByEventCount(true)->take($limit);
    }
    
    /**
     * Check for data quality issues
     * 
     * @return array Data quality report
     */
    public function getDataQualityReport() {
        return [
            'total_players' => $this->count(),
            'incomplete_profiles' => $this->filterByCompleteness(false)->count(),
            'missing_dob' => $this->whereNull('dob')->count(),
            'missing_gender' => $this->whereNull('gender')->count(),
            'invalid_avs' => $this->filter(function(Player $player) {
                return !empty($player->avs_number) && !$player->isValidAvsNumber();
            })->count(),
            'missing_emergency_contact' => $this->filter(function(Player $player) {
                return empty($player->emergency_contact) && empty($player->emergency_phone);
            })->count(),
            'potential_duplicates' => count($this->findPotentialDuplicates()),
            'data_completeness_score' => $this->calculateCompletenessScore()
        ];
    }
    
    /**
     * Calculate overall data completeness score
     * 
     * @return float Completeness score (0-100)
     */
    private function calculateCompletenessScore() {
        if ($this->isEmpty()) {
            return 0;
        }
        
        $total_score = 0;
        $required_fields = ['first_name', 'last_name', 'dob', 'gender'];
        $optional_fields = ['emergency_contact', 'emergency_phone', 'avs_number'];
        
        foreach ($this->items as $player) {
            $player_score = 0;
            $max_score = count($required_fields) * 2 + count($optional_fields); // Required fields worth 2 points
            
            // Required fields (2 points each)
            foreach ($required_fields as $field) {
                if (!empty($player->getAttribute($field))) {
                    $player_score += 2;
                }
            }
            
            // Optional fields (1 point each)
            foreach ($optional_fields as $field) {
                if (!empty($player->getAttribute($field))) {
                    $player_score += 1;
                }
            }
            
            $total_score += ($player_score / $max_score) * 100;
        }
        
        return round($total_score / $this->count(), 1);
    }
}