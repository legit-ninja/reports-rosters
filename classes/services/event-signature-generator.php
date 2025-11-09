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
                'product_id' => $normalized['product_id'] ?? 0,
            ];
            
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
            
            // Normalize activity type (translate to English)
            if (!empty($event_data['activity_type'])) {
                $normalized['activity_type'] = $this->normalizeActivityType($event_data['activity_type']);
            }
            
            // Normalize season (translate to English)
            if (!empty($event_data['season'])) {
                $normalized['season'] = $this->normalizeSeason($event_data['season']);
            }
            
            // Normalize girls_only to boolean
            $normalized['girls_only'] = !empty($event_data['girls_only']) ? 1 : 0;
            
            // Ensure product_id is int
            $normalized['product_id'] = isset($event_data['product_id']) ? (int)$event_data['product_id'] : 0;
            
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
     * @param string $name Term name
     * @param string $taxonomy Taxonomy name
     * @return string Term slug or original name if not found
     */
    private function getTermSlug($name, $taxonomy) {
        if (empty($name) || empty($taxonomy)) {
            return $name;
        }
        
        // Try to find term by name
        $term = get_term_by('name', $name, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return $term->slug;
        }
        
        // Try translated name if WPML is active
        if (function_exists('wpml_get_term_by_translated_name')) {
            $term = wpml_get_term_by_translated_name($name, $taxonomy);
            if ($term && !is_wp_error($term)) {
                return $term->slug;
            }
        }
        
        // Check if name is already a slug
        $term = get_term_by('slug', $name, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return $term->slug;
        }
        
        // Fallback to sanitized name
        return sanitize_title($name);
    }
    
    /**
     * Normalize activity type to English
     * 
     * @param string $activity_type Activity type string
     * @return string Normalized activity type
     */
    private function normalizeActivityType($activity_type) {
        // Convert to lowercase and trim
        $normalized = strtolower(trim($activity_type));
        
        // Translation map (French/German/Italian -> English)
        $translations = [
            'camp' => ['camp', 'camp de vacances', 'lager', 'campeggio'],
            'course' => ['course', 'cours', 'kurs', 'corso', 'stage'],
            'birthday' => ['birthday', 'anniversaire', 'geburtstag', 'compleanno'],
            'girls only' => ['girls only', 'filles seulement', 'nur mädchen', 'solo ragazze'],
        ];
        
        // Check each translation group
        foreach ($translations as $english => $variants) {
            foreach ($variants as $variant) {
                if (strpos($normalized, $variant) !== false) {
                    return $english;
                }
            }
        }
        
        // Return normalized version if no match
        return $normalized;
    }
    
    /**
     * Normalize season to English
     * 
     * @param string $season Season string
     * @return string Normalized season
     */
    private function normalizeSeason($season) {
        if (empty($season)) {
            return $season;
        }
        
        // Translation map (French/German/Italian -> English)
        $translations = [
            'Hiver' => 'Winter',
            'hiver' => 'winter',
            'Été' => 'Summer',
            'été' => 'summer',
            'Printemps' => 'Spring',
            'printemps' => 'spring',
            'Automne' => 'Fall',
            'automne' => 'fall',
            'Winter' => 'Winter',
            'Sommer' => 'Summer',
            'Frühling' => 'Spring',
            'Herbst' => 'Fall',
            'Inverno' => 'Winter',
            'Estate' => 'Summer',
            'Primavera' => 'Spring',
            'Autunno' => 'Fall',
        ];
        
        // Replace all translations
        $normalized = str_replace(array_keys($translations), array_values($translations), $season);
        
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

