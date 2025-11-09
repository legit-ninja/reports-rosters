<?php
/**
 * Placeholder Manager Class
 * 
 * Manages placeholder roster entries for products/variations without real bookings.
 * Placeholders ensure all events appear in roster views even if nobody has booked yet.
 * 
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\EventSignatureGenerator;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Placeholder Manager Class
 * 
 * Creates and manages placeholder roster entries
 */
class PlaceholderManager {
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Roster repository instance
     * 
     * @var RosterRepository
     */
    private $roster_repository;
    
    /**
     * Event signature generator
     * 
     * @var EventSignatureGenerator
     */
    private $signature_generator;
    
    /**
     * Constructor
     * 
     * @param Logger|null $logger Logger instance
     * @param RosterRepository|null $roster_repository Roster repository instance
     * @param EventSignatureGenerator|null $signature_generator Event signature generator
     */
    public function __construct(
        Logger $logger = null,
        RosterRepository $roster_repository = null,
        EventSignatureGenerator $signature_generator = null
    ) {
        $this->logger = $logger ?: new Logger();
        $this->roster_repository = $roster_repository ?: new RosterRepository($this->logger);
        $this->signature_generator = $signature_generator ?: new EventSignatureGenerator($this->logger);
    }
    
    /**
     * Create placeholders for all variations of a product
     * 
     * @param int $product_id Product ID
     * @return array Results ['created' => int, 'updated' => int, 'skipped' => int]
     */
    public function createForProduct($product_id) {
        try {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                $this->logger->warning('Invalid product ID', ['product_id' => $product_id]);
                return ['created' => 0, 'updated' => 0, 'skipped' => 0];
            }
            
            // Only process published variable products
            if ($product->get_status() !== 'publish') {
                $this->logger->debug('Skipping non-published product', ['product_id' => $product_id]);
                return ['created' => 0, 'updated' => 0, 'skipped' => 0];
            }
            
            if (!$product->is_type('variable')) {
                $this->logger->debug('Skipping non-variable product', ['product_id' => $product_id]);
                return ['created' => 0, 'updated' => 0, 'skipped' => 0];
            }
            
            $this->logger->info('Creating placeholders for product', ['product_id' => $product_id]);
            
            $results = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0
            ];
            
            $variations = $product->get_available_variations();
            
            foreach ($variations as $variation_data) {
                $variation_id = $variation_data['variation_id'];
                $result = $this->createFromVariation($variation_id, $product_id);
                
                if (isset($results[$result])) {
                    $results[$result]++;
                }
            }
            
            $this->logger->info('Placeholder creation completed', array_merge(['product_id' => $product_id], $results));
            
            return $results;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create placeholders for product', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }
    }
    
    /**
     * Create placeholder from a single variation
     * 
     * @param int $variation_id Variation ID
     * @param int|null $product_id Parent product ID (optional)
     * @return string Result: 'created', 'updated', or 'skipped'
     */
    public function createFromVariation($variation_id, $product_id = null) {
        try {
            $variation = wc_get_product($variation_id);
            
            if (!$variation) {
                $this->logger->warning('Invalid variation ID', ['variation_id' => $variation_id]);
                return 'skipped';
            }
            
            // Get parent product
            if (!$product_id) {
                $product_id = $variation->get_parent_id();
            }
            
            $parent_product = wc_get_product($product_id);
            
            if (!$parent_product) {
                $this->logger->warning('Invalid parent product', ['product_id' => $product_id]);
                return 'skipped';
            }
            
            // Extract event data
            $event_data = $this->extractEventDataFromVariation($variation, $parent_product);
            
            if (!$event_data) {
                $this->logger->debug('Could not extract event data', ['variation_id' => $variation_id]);
                return 'skipped';
            }
            
            // Generate event signature
            $event_signature = $this->signature_generator->generate($event_data);
            
            // Check if placeholder already exists
            $existing = $this->roster_repository
                ->where(['event_signature' => $event_signature, 'is_placeholder' => 1])
                ->first();
            
            // Prepare placeholder data
            $placeholder_data = $this->preparePlaceholderData($variation_id, $product_id, $event_data, $event_signature);
            
            if ($existing) {
                // Update existing placeholder
                $this->roster_repository->update($existing->id, $placeholder_data);
                
                $this->logger->debug('Updated placeholder', [
                    'variation_id' => $variation_id,
                    'event_signature' => $event_signature
                ]);
                
                return 'updated';
            } else {
                // Create new placeholder
                $this->roster_repository->create($placeholder_data);
                
                $this->logger->debug('Created placeholder', [
                    'variation_id' => $variation_id,
                    'event_signature' => $event_signature
                ]);
                
                return 'created';
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create placeholder from variation', [
                'variation_id' => $variation_id,
                'error' => $e->getMessage()
            ]);
            return 'skipped';
        }
    }
    
    /**
     * Delete placeholder by event signature
     * 
     * @param string $event_signature Event signature
     * @return int Number of placeholders deleted
     */
    public function deleteBySignature($event_signature) {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'intersoccer_rosters';
            
            $deleted = $wpdb->delete(
                $table,
                [
                    'event_signature' => $event_signature,
                    'is_placeholder' => 1
                ],
                ['%s', '%d']
            );
            
            $this->logger->info('Deleted placeholders by signature', [
                'event_signature' => $event_signature,
                'count' => $deleted
            ]);
            
            // Clear caches
            $this->roster_repository->clearAllCaches();
            
            return $deleted ?: 0;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete placeholders', [
                'event_signature' => $event_signature,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Delete all placeholders for a product
     * 
     * @param int $product_id Product ID
     * @return int Number of placeholders deleted
     */
    public function deleteForProduct($product_id) {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'intersoccer_rosters';
            
            $deleted = $wpdb->delete(
                $table,
                [
                    'product_id' => $product_id,
                    'is_placeholder' => 1
                ],
                ['%d', '%d']
            );
            
            $this->logger->info('Deleted placeholders for product', [
                'product_id' => $product_id,
                'count' => $deleted
            ]);
            
            // Clear caches
            $this->roster_repository->clearAllCaches();
            
            return $deleted ?: 0;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete placeholders for product', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Cleanup orphaned placeholders
     * 
     * Removes placeholders for variations/products that no longer exist.
     * 
     * @return array Results ['deleted' => int, 'errors' => int]
     */
    public function cleanup() {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'intersoccer_rosters';
            
            $this->logger->info('Starting placeholder cleanup');
            
            // Find placeholders with invalid variation IDs
            $orphaned = $wpdb->get_results(
                "SELECT r.id, r.variation_id 
                 FROM {$table} r 
                 LEFT JOIN {$wpdb->posts} p ON r.variation_id = p.ID 
                 WHERE r.is_placeholder = 1 
                 AND (p.ID IS NULL OR p.post_status = 'trash')",
                ARRAY_A
            );
            
            $deleted = 0;
            $errors = 0;
            
            foreach ($orphaned as $placeholder) {
                try {
                    $this->roster_repository->delete($placeholder['id']);
                    $deleted++;
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Failed to delete orphaned placeholder', [
                        'placeholder_id' => $placeholder['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $this->logger->info('Placeholder cleanup completed', [
                'deleted' => $deleted,
                'errors' => $errors
            ]);
            
            return [
                'deleted' => $deleted,
                'errors' => $errors
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Placeholder cleanup failed', [
                'error' => $e->getMessage()
            ]);
            return ['deleted' => 0, 'errors' => 1];
        }
    }
    
    /**
     * Sync all placeholders from existing products
     * 
     * @return array Results ['processed' => int, 'created' => int, 'updated' => int]
     */
    public function syncAll() {
        try {
            $this->logger->info('Starting placeholder sync for all products');
            
            $products = wc_get_products([
                'type' => 'variable',
                'status' => 'publish',
                'limit' => -1
            ]);
            
            $results = [
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0
            ];
            
            foreach ($products as $product) {
                $product_id = $product->get_id();
                
                $product_results = $this->createForProduct($product_id);
                
                $results['processed']++;
                $results['created'] += $product_results['created'];
                $results['updated'] += $product_results['updated'];
                $results['skipped'] += $product_results['skipped'];
            }
            
            $this->logger->info('Placeholder sync completed', $results);
            
            return $results;
            
        } catch (\Exception $e) {
            $this->logger->error('Placeholder sync failed', [
                'error' => $e->getMessage()
            ]);
            return ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        }
    }
    
    /**
     * Extract event data from variation and parent product
     * 
     * @param \WC_Product_Variation $variation Variation object
     * @param \WC_Product $parent_product Parent product object
     * @return array|false Event data or false on failure
     */
    private function extractEventDataFromVariation($variation, $parent_product) {
        try {
            $activity_type = $this->getProductAttribute('pa_activity-type', $variation, $parent_product);
            
            // Skip if not an event type
            if (!in_array(strtolower($activity_type), ['camp', 'course', 'birthday'])) {
                return false;
            }
            
            return [
                'activity_type' => $activity_type,
                'venue' => $this->getProductAttribute('pa_intersoccer-venues', $variation, $parent_product),
                'age_group' => $this->getProductAttribute('pa_age-group', $variation, $parent_product),
                'camp_terms' => $this->getProductAttribute('pa_camp-terms', $variation, $parent_product),
                'course_day' => $this->getProductAttribute('pa_course-day', $variation, $parent_product),
                'times' => $this->getProductAttribute('pa_camp-times', $variation, $parent_product) 
                        ?: $this->getProductAttribute('pa_course-times', $variation, $parent_product),
                'season' => $this->getProductAttribute('pa_season', $variation, $parent_product),
                'booking_type' => $this->getProductAttribute('pa_booking-type', $variation, $parent_product),
                'girls_only' => strtolower($activity_type) === 'girls only' ? 1 : 0,
                'product_id' => $parent_product->get_id()
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to extract event data from variation', [
                'variation_id' => $variation->get_id(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get product attribute with fallback to parent
     * 
     * @param string $attribute Attribute name
     * @param \WC_Product_Variation $variation Variation object
     * @param \WC_Product $parent Parent product object
     * @return string Attribute value
     */
    private function getProductAttribute($attribute, $variation, $parent) {
        // Try variation first
        $value = $variation->get_attribute($attribute);
        
        if (empty($value)) {
            // Fallback to parent
            $value = $parent->get_attribute($attribute);
        }
        
        return $value ?: 'N/A';
    }
    
    /**
     * Prepare placeholder roster data
     * 
     * @param int $variation_id Variation ID
     * @param int $product_id Product ID
     * @param array $event_data Event data
     * @param string $event_signature Event signature
     * @return array Placeholder roster data
     */
    private function preparePlaceholderData($variation_id, $product_id, $event_data, $event_signature) {
        $parent_product = wc_get_product($product_id);
        
        return [
            'order_id' => 0,
            'order_item_id' => 0,
            'variation_id' => $variation_id,
            'product_id' => $product_id,
            'player_name' => 'Empty Roster',
            'first_name' => 'Empty',
            'last_name' => 'Roster',
            'player_first_name' => 'Empty',
            'player_last_name' => 'Roster',
            'age' => null,
            'gender' => 'N/A',
            'player_gender' => 'N/A',
            'player_dob' => '1970-01-01',
            'booking_type' => $event_data['booking_type'],
            'selected_days' => 'N/A',
            'camp_terms' => $event_data['camp_terms'],
            'venue' => $event_data['venue'],
            'parent_phone' => 'N/A',
            'parent_email' => 'N/A',
            'parent_first_name' => 'Placeholder',
            'parent_last_name' => 'Entry',
            'emergency_contact' => 'N/A',
            'medical_conditions' => '',
            'player_medical' => '',
            'player_dietary' => '',
            'late_pickup' => 'No',
            'late_pickup_days' => '',
            'day_presence' => json_encode([
                'Monday' => 'No',
                'Tuesday' => 'No',
                'Wednesday' => 'No',
                'Thursday' => 'No',
                'Friday' => 'No'
            ]),
            'age_group' => $event_data['age_group'],
            'start_date' => '1970-01-01',
            'end_date' => '1970-01-01',
            'event_dates' => 'N/A',
            'product_name' => $parent_product ? $parent_product->get_name() : 'Unknown Product',
            'activity_type' => $event_data['activity_type'],
            'shirt_size' => 'N/A',
            'shorts_size' => 'N/A',
            'registration_timestamp' => current_time('mysql'),
            'course_day' => $event_data['course_day'],
            'term' => $event_data['camp_terms'] ?: $event_data['course_day'],
            'times' => $event_data['times'],
            'days_selected' => 'N/A',
            'season' => $event_data['season'],
            'canton_region' => '',
            'city' => '',
            'avs_number' => 'N/A',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'base_price' => 0.00,
            'discount_amount' => 0.00,
            'final_price' => 0.00,
            'reimbursement' => 0.00,
            'discount_codes' => '',
            'girls_only' => $event_data['girls_only'],
            'event_signature' => $event_signature,
            'is_placeholder' => 1,
            'event_completed' => 0
        ];
    }
}



