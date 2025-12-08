<?php
/**
 * Event Signature Generator Class
 * 
 * Generates stable event signatures for roster grouping that are language-agnostic
 * and don't rely on variation IDs. This ensures rosters remain properly grouped
 * even when product variations are deleted or language is changed.
 * 
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event Signature Generator Class
 * 
 * Generates MD5 signatures from event data for grouping rosters by event
 */
class EventSignatureGenerator {
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Constructor
     * 
     * @param Logger|null $logger Logger instance
     */
    public function __construct(Logger $logger = null) {
        $this->logger = $logger ?: new Logger();
    }
    
    /**
     * Generate event signature from roster/event data
     * 
     * @param array $event_data Event data array
     * @return string MD5 signature
     */
    public function generate(array $event_data) {
        try {
            // Normalize data to language-agnostic format
            $normalized = $this->normalize($event_data);
            
            // Build signature components in consistent order
            $components = [
                'activity_type' => $normalized['activity_type'] ?? '',
                'venue' => $normalized['venue'] ?? '',
                'age_group' => $normalized['age_group'] ?? '',
                'camp_terms' => $normalized['camp_terms'] ?? '',
                'course_day' => $normalized['course_day'] ?? '',
                'times' => $normalized['times'] ?? '',
                'season' => $normalized['season'] ?? '',
                'girls_only' => $normalized['girls_only'] ?? 0,
                'city' => $normalized['city'] ?? '',
                'canton_region' => $normalized['canton_region'] ?? '',
                'product_id' => $normalized['product_id'] ?? 0,
            ];
            
            // For tournaments, include the date in the signature to distinguish between different tournament dates
            // Tournaments are typically one-day events, so we use start_date
            $activity_type = strtolower($components['activity_type'] ?? '');
            if ($activity_type === 'tournament' && !empty($event_data['start_date'])) {
                // Normalize date to Y-m-d format for consistent signatures
                $date_value = $event_data['start_date'];
                // If date is not already in Y-m-d format, try to parse it
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_value) === 0) {
                    if (function_exists('intersoccer_parse_date_unified')) {
                        $parsed_date = intersoccer_parse_date_unified($date_value, 'event_signature');
                        if ($parsed_date) {
                            $date_value = $parsed_date;
                        }
                    }
                }
                $components['start_date'] = $date_value;
            }
            
            // Create signature string
            $signature_string = implode('|', $components);
            
            // Generate MD5 hash
            $signature = md5($signature_string);
            
            $this->logger->debug('Generated event signature', [
                'components' => $components,
                'signature' => $signature
            ]);
            
            return $signature;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate event signature', [
                'event_data' => $event_data,
                'error' => $e->getMessage()
            ]);
            
            // Return empty string on error (safe fallback)
            return '';
        }
    }
    
    /**
     * Normalize event data to language-agnostic format
     * 
     * Converts translated term names to English slugs for consistent signatures
     * across languages.
     * 
     * @param array $event_data Raw event data
     * @return array Normalized event data
     */
    public function normalize(array $event_data) {
        $normalized = $event_data;
        
        // Store and switch language context if WPML is active
        $original_lang = '';
        $default_lang = '';
        
        if (function_exists('wpml_get_current_language') && function_exists('wpml_get_default_language')) {
            $original_lang = wpml_get_current_language();
            $default_lang = wpml_get_default_language();
            
            if ($original_lang !== $default_lang) {
                do_action('wpml_switch_language', $default_lang);
            }
        }
        
        try {
            // Normalize venue (taxonomy term -> slug)
            if (!empty($event_data['venue'])) {
                $normalized['venue'] = $this->getTermSlug($event_data['venue'], 'pa_intersoccer-venues');
            }
            
            // Normalize age group (taxonomy term -> slug)
            if (!empty($event_data['age_group'])) {
                $normalized['age_group'] = $this->getTermSlug($event_data['age_group'], 'pa_age-group');
            }
            
            // Normalize camp_terms (taxonomy term -> slug)
            if (!empty($event_data['camp_terms'])) {
                $normalized['camp_terms'] = $this->getTermSlug($event_data['camp_terms'], 'pa_camp-terms');
            }
            
            // Normalize course_day (taxonomy term -> slug)
            if (!empty($event_data['course_day'])) {
                $normalized['course_day'] = $this->getTermSlug($event_data['course_day'], 'pa_course-day');
            }
            
            // Normalize times (taxonomy term -> slug) - try different taxonomies
            if (!empty($event_data['times'])) {
                $times_slug = null;
                $taxonomies = ['pa_camp-times', 'pa_course-times'];
                foreach ($taxonomies as $taxonomy) {
                    $times_slug = $this->getTermSlug($event_data['times'], $taxonomy);
                    if ($times_slug && $times_slug !== $event_data['times']) {
                        break; // Found a match
                    }
                }
                $normalized['times'] = $times_slug ?: $this->getTermSlug($event_data['times'], 'pa_camp-times');
            }
            
            // Normalize activity type (translate to English)
            if (!empty($event_data['activity_type'])) {
                $normalized['activity_type'] = $this->normalizeActivityType($event_data['activity_type']);
            }
            
            // Normalize season (translate to English)
            if (!empty($event_data['season'])) {
                $normalized['season'] = $this->normalizeSeason($event_data['season']);
            }
            
            // Normalize city (taxonomy term -> slug) - important for tournaments
            if (!empty($event_data['city'])) {
                $normalized['city'] = $this->getTermSlug($event_data['city'], 'pa_city');
            }
            
            // Normalize canton_region (taxonomy term -> slug) - important for tournaments
            if (!empty($event_data['canton_region'])) {
                $normalized['canton_region'] = $this->getTermSlug($event_data['canton_region'], 'pa_canton-region');
            }
            
            // Normalize girls_only to boolean
            $normalized['girls_only'] = !empty($event_data['girls_only']) ? 1 : 0;
            
            // Normalize product_id for WPML translations to ensure consistent signatures across languages
            // This ensures French and English versions of the same product generate the same signature
            $product_id = isset($event_data['product_id']) ? (int)$event_data['product_id'] : 0;
            if (!empty($product_id) && function_exists('apply_filters') && !empty($default_lang)) {
                // First, ensure we have the parent product ID (in case product_id is a variation)
                $product = wc_get_product($product_id);
                if ($product && method_exists($product, 'get_parent_id')) {
                    $parent_id = $product->get_parent_id();
                    if ($parent_id > 0) {
                        // Use parent product ID instead of variation ID
                        $product_id = $parent_id;
                        $this->logger->debug('Using parent product ID for variation', [
                            'variation_id' => $event_data['product_id'],
                            'parent_id' => $product_id
                        ]);
                    }
                }
                
                // Now normalize the product_id to the default language version
                // Try with return_original_if_missing = false first to see if translation exists
                $original_product_id = apply_filters('wpml_object_id', $product_id, 'product', false, $default_lang);
                if ($original_product_id && $original_product_id != $product_id) {
                    $this->logger->debug('Normalizing product_id for WPML translation', [
                        'original' => $product_id,
                        'normalized' => $original_product_id,
                        'default_lang' => $default_lang
                    ]);
                    $product_id = $original_product_id;
                } else {
                    // If no translation found, try with return_original_if_missing = true
                    $original_product_id = apply_filters('wpml_object_id', $product_id, 'product', true, $default_lang);
                    if ($original_product_id && $original_product_id != $product_id) {
                        $this->logger->debug('Normalizing product_id for WPML translation (fallback)', [
                            'original' => $product_id,
                            'normalized' => $original_product_id,
                            'default_lang' => $default_lang
                        ]);
                        $product_id = $original_product_id;
                    } else {
                        // Check if this product might be a translation by checking element type
                        if (function_exists('wpml_get_element_trid')) {
                            $trid = wpml_get_element_trid($product_id, 'post_product');
                            if ($trid) {
                                $translations = apply_filters('wpml_get_element_translations', null, $trid, 'post_product');
                                if ($translations && is_array($translations)) {
                                    // Find the default language translation
                                    foreach ($translations as $lang_code => $translation) {
                                        if ($lang_code === $default_lang && isset($translation->element_id)) {
                                            $default_product_id = (int)$translation->element_id;
                                            if ($default_product_id != $product_id) {
                                                $this->logger->debug('Found default language product via TRID lookup', [
                                                    'original' => $product_id,
                                                    'normalized' => $default_product_id,
                                                    'default_lang' => $default_lang
                                                ]);
                                                $product_id = $default_product_id;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        if ($product_id == ($event_data['product_id'] ?? 0)) {
                            $this->logger->debug('Product ID - no translation found or already in default language', [
                                'product_id' => $product_id,
                                'default_lang' => $default_lang
                            ]);
                        }
                    }
                }
            }
            $normalized['product_id'] = $product_id;
            
        } finally {
            // Switch back to original language if we changed it
            if ($original_lang && $original_lang !== $default_lang) {
                do_action('wpml_switch_language', $original_lang);
            }
        }
        
        return $normalized;
    }
    
    /**
     * Get taxonomy term slug by name
     * 
     * Uses the robust intersoccer_get_term_by_translated_name() function for consistency
     * with legacy code and to handle unsynchronized/mistranslated taxonomy terms.
     * 
     * @param string $name Term name
     * @param string $taxonomy Taxonomy name
     * @return string Term slug or normalized fallback if not found
     */
    private function getTermSlug($name, $taxonomy) {
        if (empty($name) || empty($taxonomy)) {
            return $name;
        }
        
        // Use the robust normalization function for consistency with legacy code
        if (function_exists('intersoccer_get_term_by_translated_name')) {
            $term = intersoccer_get_term_by_translated_name($name, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $this->logger->debug('Found term slug via robust normalization', [
                    'name' => $name,
                    'taxonomy' => $taxonomy,
                    'slug' => $term->slug
                ]);
                return $term->slug;
            }
        }
        
        // Fallback: try direct lookup (for backwards compatibility)
        $term = get_term_by('name', $name, $taxonomy);
        if ($term && !is_wp_error($term)) {
            $this->logger->debug('Found term slug via direct lookup', [
                'name' => $name,
                'taxonomy' => $taxonomy,
                'slug' => $term->slug
            ]);
            return $term->slug;
        }
        
        // Check if name is already a slug
        $term = get_term_by('slug', $name, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return $term->slug;
        }
        
        // Use fallback normalization for consistent signatures
        if (function_exists('intersoccer_normalize_term_fallback')) {
            $fallback = intersoccer_normalize_term_fallback($name);
            $this->logger->warning('Using fallback normalization for term', [
                'name' => $name,
                'taxonomy' => $taxonomy,
                'fallback' => $fallback
            ]);
            return $fallback;
        }
        
        // Last resort: sanitized name
        return sanitize_title($name);
    }
    
    /**
     * Normalize activity type to English
     * 
     * Enhanced to handle Tournament/Tournoi and other variations with case-insensitive matching.
     * 
     * @param string $activity_type Activity type string
     * @return string Normalized activity type
     */
    private function normalizeActivityType($activity_type) {
        if (empty($activity_type)) {
            return '';
        }
        
        // Use the legacy function if available for consistency
        if (function_exists('intersoccer_normalize_activity_type')) {
            $normalized = intersoccer_normalize_activity_type($activity_type);
            $this->logger->debug('Normalized activity type using legacy function', [
                'original' => $activity_type,
                'normalized' => $normalized
            ]);
            return $normalized;
        }
        
        // Fallback: Convert to lowercase and trim
        $normalized = strtolower(trim($activity_type));
        
        // Translation map (French/German/Italian -> English)
        $translations = [
            'camp' => ['camp', 'camp de vacances', 'lager', 'campeggio'],
            'course' => ['course', 'cours', 'kurs', 'corso', 'stage'],
            'birthday' => ['birthday', 'anniversaire', 'geburtstag', 'compleanno'],
            'tournament' => ['tournament', 'tournoi', 'tournois', 'turnier', 'torneo'], // Added tournament variations
            'girls only' => ['girls only', 'filles seulement', 'nur mädchen', 'solo ragazze'],
        ];
        
        // Check each translation group
        foreach ($translations as $english => $variants) {
            foreach ($variants as $variant) {
                if (strpos($normalized, $variant) !== false) {
                    $this->logger->debug('Normalized activity type', [
                        'original' => $activity_type,
                        'normalized' => $english
                    ]);
                    return $english;
                }
            }
        }
        
        // Return normalized version if no match
        $this->logger->debug('Activity type not matched, using normalized', [
            'original' => $activity_type,
            'normalized' => $normalized
        ]);
        return $normalized;
    }
    
    /**
     * Normalize season to English
     * 
     * Enhanced to handle more variations and ensure consistent normalization.
     * 
     * @param string $season Season string
     * @return string Normalized season
     */
    private function normalizeSeason($season) {
        if (empty($season)) {
            return $season;
        }
        
        // Translation map (French/German/Italian -> English)
        // Using case-insensitive replacement
        $translations = [
            'Hiver' => 'Winter',
            'hiver' => 'winter',
            'Été' => 'Summer',
            'été' => 'summer',
            'Printemps' => 'Spring',
            'printemps' => 'spring',
            'Automne' => 'Fall',
            'automne' => 'fall',
            'Autumn' => 'Fall', // Handle both Autumn and Fall
            'autumn' => 'fall',
            'Winter' => 'Winter',
            'Sommer' => 'Summer',
            'Frühling' => 'Spring',
            'Herbst' => 'Fall',
            'Inverno' => 'Winter',
            'Estate' => 'Summer',
            'Primavera' => 'Spring',
            'Autunno' => 'Fall',
        ];
        
        // Replace all translations (case-sensitive first, then case-insensitive)
        $normalized = $season;
        foreach ($translations as $from => $to) {
            $normalized = str_replace($from, $to, $normalized);
        }
        
        // Also do case-insensitive replacement for any remaining variations
        $normalized = str_ireplace(['Hiver', 'hiver'], 'Winter', $normalized);
        $normalized = str_ireplace(['Été', 'été'], 'Summer', $normalized);
        $normalized = str_ireplace(['Printemps', 'printemps'], 'Spring', $normalized);
        $normalized = str_ireplace(['Automne', 'automne', 'Autumn', 'autumn'], 'Fall', $normalized);
        
        // Capitalize first letter for consistency
        $normalized = ucfirst(strtolower(trim($normalized)));
        
        $this->logger->debug('Normalized season', [
            'original' => $season,
            'normalized' => $normalized
        ]);
        
        return $normalized;
    }
    
    /**
     * Rebuild event signatures for existing roster entries
     * 
     * @param array $roster_entries Array of roster entry data
     * @return array Results ['updated' => int, 'errors' => int]
     */
    public function rebuild(array $roster_entries) {
        $this->logger->info('Starting event signature rebuild', [
            'count' => count($roster_entries)
        ]);
        
        $results = [
            'updated' => 0,
            'errors' => 0
        ];
        
        foreach ($roster_entries as $entry) {
            try {
                $signature = $this->generate($entry);
                
                if (!empty($signature)) {
                    $results['updated']++;
                    // Signature would be updated in database by calling code
                } else {
                    $results['errors']++;
                    $this->logger->warning('Generated empty signature', [
                        'roster_id' => $entry['id'] ?? 'unknown'
                    ]);
                }
                
            } catch (\Exception $e) {
                $results['errors']++;
                $this->logger->error('Failed to rebuild signature for entry', [
                    'roster_id' => $entry['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->logger->info('Event signature rebuild completed', $results);
        
        return $results;
    }
}

