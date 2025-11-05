<?php
/**
 * InterSoccer Pricing Calculator Service
 * 
 * Handles complex pricing calculations including:
 * - Sibling discounts
 * - Combo offers (Camps/Courses)
 * - Pro-rated pricing for courses
 * - Age group pricing variations
 * 
 * @package InterSoccer\ReportsRosters\Services
 * @subpackage Services
 * @version 1.0.0
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Utils\DateHelper;
use InterSoccer\ReportsRosters\Utils\ValidationHelper;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;

if (!defined('ABSPATH')) {
    exit;
}

class PricingCalculator {

    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;

    /**
     * Discount rules configuration
     * 
     * @var array
     */
    private $discount_rules = [
        'camp_combo' => [
            'second_child' => 0.20,    // 20% discount
            'additional_child' => 0.25  // 25% discount for 3rd+ children
        ],
        'course_combo' => [
            'second_child' => 0.20,     // 20% discount for 2nd child
            'additional_child' => 0.30, // 30% discount for 3rd+ children
            'same_season_second' => 0.50 // 50% discount for 2nd course same season
        ],
        'sibling' => [
            'lesser_amount' => true // Discount applied to lesser of two amounts
        ]
    ];

    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     */
    public function __construct(Logger $logger = null) {
        $this->logger = $logger ?: new Logger();
        
        // Log initialization with discount rules
        $this->logger->debug('PricingCalculator initialized with discount rules', [
            'rules' => $this->discount_rules
        ]);
    }

    /**
     * Calculate pricing for a cart with discount rules applied
     * 
     * @param array $cart_items Array of cart items with product data
     * @param int   $user_id    User ID for player assignment
     * 
     * @return array Calculated pricing with discounts applied
     * @throws ValidationException If cart data is invalid
     */
    public function calculate_cart_pricing($cart_items, $user_id) {
        try {
            $this->logger->info('Starting cart pricing calculation', [
                'user_id' => $user_id,
                'item_count' => count($cart_items)
            ]);

            // Validate input data
            if (empty($cart_items) || !is_array($cart_items)) {
                throw new ValidationException('Cart items must be a non-empty array');
            }

            if (!ValidationHelper::is_valid_user_id($user_id)) {
                throw new ValidationException('Invalid user ID provided');
            }

            // Process cart items and group by type
            $processed_items = $this->process_cart_items($cart_items);
            
            // Apply discounts
            $pricing_result = $this->apply_discount_rules($processed_items, $user_id);
            
            $this->logger->info('Cart pricing calculation completed', [
                'original_total' => $pricing_result['original_total'],
                'discounted_total' => $pricing_result['final_total'],
                'total_savings' => $pricing_result['total_savings']
            ]);

            return $pricing_result;

        } catch (ValidationException $e) {
            $this->logger->error('Validation error in cart pricing calculation', [
                'error' => $e->getMessage(),
                'user_id' => $user_id
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error in cart pricing calculation', [
                'error' => $e->getMessage(),
                'user_id' => $user_id,
                'trace' => $e->getTraceAsString()
            ]);
            throw new ValidationException('Error calculating cart pricing: ' . $e->getMessage());
        }
    }

    /**
     * Process cart items and organize by event type and assigned players
     * 
     * @param array $cart_items Raw cart items
     * 
     * @return array Processed and organized cart items
     */
    private function process_cart_items($cart_items) {
        $processed = [
            'camps' => [],
            'courses' => [],
            'other' => []
        ];

        foreach ($cart_items as $item) {
            // Determine event type from activity_type attribute
            $activity_type = $this->get_activity_type($item);
            $item_data = $this->normalize_item_data($item);
            
            // Group by event type
            switch (strtolower($activity_type)) {
                case 'camp':
                    $processed['camps'][] = $item_data;
                    break;
                case 'course':
                    $processed['courses'][] = $item_data;
                    break;
                default:
                    $processed['other'][] = $item_data;
                    break;
            }
        }

        $this->logger->debug('Cart items processed by type', [
            'camps' => count($processed['camps']),
            'courses' => count($processed['courses']),
            'other' => count($processed['other'])
        ]);

        return $processed;
    }

    /**
     * Get activity type from cart item
     * 
     * @param array $item Cart item data
     * 
     * @return string Activity type (Camp, Course, etc.)
     */
    private function get_activity_type($item) {
        // Check for activity_type in variation data
        if (isset($item['variation_data']['Activity Type'])) {
            return $item['variation_data']['Activity Type'];
        }

        // Check for activity_type in meta data
        if (isset($item['meta_data'])) {
            foreach ($item['meta_data'] as $meta) {
                if ($meta['key'] === 'Activity Type') {
                    return $meta['value'];
                }
            }
        }

        // Check product attributes
        if (isset($item['product_attributes']['pa_activity-type'])) {
            return $item['product_attributes']['pa_activity-type'];
        }

        $this->logger->warning('Could not determine activity type for item', [
            'item_id' => $item['product_id'] ?? 'unknown'
        ]);

        return 'Other';
    }

    /**
     * Normalize item data for consistent processing
     * 
     * @param array $item Raw cart item
     * 
     * @return array Normalized item data
     */
    private function normalize_item_data($item) {
        return [
            'product_id' => $item['product_id'] ?? 0,
            'variation_id' => $item['variation_id'] ?? 0,
            'quantity' => $item['quantity'] ?? 1,
            'price' => floatval($item['price'] ?? 0),
            'assigned_player' => $item['assigned_player'] ?? null,
            'assigned_attendee' => $item['assigned_attendee'] ?? '',
            'season' => $item['season'] ?? '',
            'age_group' => $item['age_group'] ?? '',
            'booking_type' => $item['booking_type'] ?? '',
            'venue' => $item['venue'] ?? '',
            'start_date' => $item['start_date'] ?? '',
            'end_date' => $item['end_date'] ?? '',
            'activity_type' => $this->get_activity_type($item),
            'raw_data' => $item
        ];
    }

    /**
     * Apply all discount rules to processed items
     * 
     * @param array $processed_items Items organized by type
     * @param int   $user_id         User ID
     * 
     * @return array Pricing result with discounts
     */
    private function apply_discount_rules($processed_items, $user_id) {
        $result = [
            'items' => [],
            'original_total' => 0,
            'discounts_applied' => [],
            'total_savings' => 0,
            'final_total' => 0
        ];

        // Calculate original total
        foreach ($processed_items as $type => $items) {
            foreach ($items as $item) {
                $result['original_total'] += $item['price'] * $item['quantity'];
                $result['items'][] = [
                    'type' => $type,
                    'data' => $item,
                    'original_price' => $item['price'],
                    'discounted_price' => $item['price'], // Will be updated
                    'discounts' => []
                ];
            }
        }

        // Apply camp combo discounts
        if (count($processed_items['camps']) > 1) {
            $this->apply_camp_combo_discounts($result, $processed_items['camps']);
        }

        // Apply course combo discounts
        if (count($processed_items['courses']) > 1) {
            $this->apply_course_combo_discounts($result, $processed_items['courses']);
        }

        // Apply sibling discounts
        $this->apply_sibling_discounts($result, $user_id);

        // Apply pro-rated pricing for courses
        $this->apply_prorated_pricing($result, $processed_items['courses']);

        // Calculate final total
        foreach ($result['items'] as $item) {
            $result['final_total'] += $item['discounted_price'] * $item['data']['quantity'];
        }

        $result['total_savings'] = $result['original_total'] - $result['final_total'];

        return $result;
    }

    /**
     * Apply camp combo discount rules
     * 
     * @param array &$result Reference to pricing result
     * @param array $camp_items Camp items
     */
    private function apply_camp_combo_discounts(&$result, $camp_items) {
        if (count($camp_items) < 2) {
            return;
        }

        $this->logger->debug('Applying camp combo discounts', [
            'camp_count' => count($camp_items)
        ]);

        // Group camps by assigned player to identify multi-child bookings
        $camps_by_child = [];
        foreach ($result['items'] as $key => $item) {
            if ($item['type'] === 'camps' && !empty($item['data']['assigned_player'])) {
                $child_id = $item['data']['assigned_player'];
                if (!isset($camps_by_child[$child_id])) {
                    $camps_by_child[$child_id] = [];
                }
                $camps_by_child[$child_id][] = $key;
            }
        }

        // Apply discounts if multiple children have camps
        $children_with_camps = array_keys($camps_by_child);
        if (count($children_with_camps) > 1) {
            // Sort children by total camp cost (highest first)
            usort($children_with_camps, function($a, $b) use ($camps_by_child, $result) {
                $total_a = 0;
                $total_b = 0;
                
                foreach ($camps_by_child[$a] as $item_key) {
                    $total_a += $result['items'][$item_key]['discounted_price'];
                }
                foreach ($camps_by_child[$b] as $item_key) {
                    $total_b += $result['items'][$item_key]['discounted_price'];
                }
                
                return $total_b <=> $total_a;
            });

            // Apply discounts: 20% for 2nd child, 25% for 3rd+ children
            for ($i = 1; $i < count($children_with_camps); $i++) {
                $child_id = $children_with_camps[$i];
                $discount_rate = ($i === 1) ? $this->discount_rules['camp_combo']['second_child'] 
                                            : $this->discount_rules['camp_combo']['additional_child'];
                
                foreach ($camps_by_child[$child_id] as $item_key) {
                    $original_price = $result['items'][$item_key]['discounted_price'];
                    $discount_amount = $original_price * $discount_rate;
                    
                    $result['items'][$item_key]['discounted_price'] -= $discount_amount;
                    $result['items'][$item_key]['discounts'][] = [
                        'type' => 'camp_combo',
                        'description' => sprintf('Camp combo discount (%d%% off)', $discount_rate * 100),
                        'amount' => $discount_amount,
                        'rate' => $discount_rate
                    ];
                    
                    $result['discounts_applied'][] = [
                        'type' => 'camp_combo',
                        'child_index' => $i + 1,
                        'amount' => $discount_amount
                    ];
                }
            }
        }
    }

    /**
     * Apply course combo discount rules
     * 
     * @param array &$result Reference to pricing result
     * @param array $course_items Course items
     */
    private function apply_course_combo_discounts(&$result, $course_items) {
        if (count($course_items) < 2) {
            return;
        }

        $this->logger->debug('Applying course combo discounts', [
            'course_count' => count($course_items)
        ]);

        // Group courses by assigned player and season
        $courses_by_child = [];
        $courses_by_child_season = [];
        
        foreach ($result['items'] as $key => $item) {
            if ($item['type'] === 'courses' && !empty($item['data']['assigned_player'])) {
                $child_id = $item['data']['assigned_player'];
                $season = $item['data']['season'];
                
                // Group by child
                if (!isset($courses_by_child[$child_id])) {
                    $courses_by_child[$child_id] = [];
                }
                $courses_by_child[$child_id][] = $key;
                
                // Group by child and season for same-season discount
                if (!isset($courses_by_child_season[$child_id])) {
                    $courses_by_child_season[$child_id] = [];
                }
                if (!isset($courses_by_child_season[$child_id][$season])) {
                    $courses_by_child_season[$child_id][$season] = [];
                }
                $courses_by_child_season[$child_id][$season][] = $key;
            }
        }

        // Apply same-season discount (50% off 2nd course in same season)
        foreach ($courses_by_child_season as $child_id => $seasons) {
            foreach ($seasons as $season => $course_keys) {
                if (count($course_keys) > 1) {
                    // Sort by price (highest first) and discount the lower-priced ones
                    usort($course_keys, function($a, $b) use ($result) {
                        return $result['items'][$b]['discounted_price'] <=> $result['items'][$a]['discounted_price'];
                    });
                    
                    // Apply 50% discount to 2nd course in same season
                    for ($i = 1; $i < count($course_keys); $i++) {
                        $item_key = $course_keys[$i];
                        $original_price = $result['items'][$item_key]['discounted_price'];
                        $discount_amount = $original_price * $this->discount_rules['course_combo']['same_season_second'];
                        
                        $result['items'][$item_key]['discounted_price'] -= $discount_amount;
                        $result['items'][$item_key]['discounts'][] = [
                            'type' => 'course_same_season',
                            'description' => 'Same season course discount (50% off)',
                            'amount' => $discount_amount,
                            'rate' => $this->discount_rules['course_combo']['same_season_second']
                        ];
                    }
                }
            }
        }

        // Apply multi-child course discounts (20% for 2nd child, 30% for 3rd+ children)
        $children_with_courses = array_keys($courses_by_child);
        if (count($children_with_courses) > 1) {
            // Sort children by total course cost (highest first)
            usort($children_with_courses, function($a, $b) use ($courses_by_child, $result) {
                $total_a = 0;
                $total_b = 0;
                
                foreach ($courses_by_child[$a] as $item_key) {
                    $total_a += $result['items'][$item_key]['discounted_price'];
                }
                foreach ($courses_by_child[$b] as $item_key) {
                    $total_b += $result['items'][$item_key]['discounted_price'];
                }
                
                return $total_b <=> $total_a;
            });

            // Apply discounts: 20% for 2nd child, 30% for 3rd+ children  
            for ($i = 1; $i < count($children_with_courses); $i++) {
                $child_id = $children_with_courses[$i];
                $discount_rate = ($i === 1) ? $this->discount_rules['course_combo']['second_child'] 
                                            : $this->discount_rules['course_combo']['additional_child'];
                
                foreach ($courses_by_child[$child_id] as $item_key) {
                    $original_price = $result['items'][$item_key]['discounted_price'];
                    $discount_amount = $original_price * $discount_rate;
                    
                    $result['items'][$item_key]['discounted_price'] -= $discount_amount;
                    $result['items'][$item_key]['discounts'][] = [
                        'type' => 'course_combo',
                        'description' => sprintf('Course combo discount (%d%% off)', $discount_rate * 100),
                        'amount' => $discount_amount,
                        'rate' => $discount_rate
                    ];
                }
            }
        }
    }

    /**
     * Apply sibling discounts (same camp/course type, discount lesser amount)
     * 
     * @param array &$result Reference to pricing result
     * @param int   $user_id User ID
     */
    private function apply_sibling_discounts(&$result, $user_id) {
        // Group items by type and variation for sibling comparison
        $items_by_variation = [];
        
        foreach ($result['items'] as $key => $item) {
            $variation_key = $item['type'] . '_' . $item['data']['variation_id'];
            if (!isset($items_by_variation[$variation_key])) {
                $items_by_variation[$variation_key] = [];
            }
            $items_by_variation[$variation_key][] = $key;
        }

        // Apply sibling discounts where same variations have different prices
        foreach ($items_by_variation as $variation_key => $item_keys) {
            if (count($item_keys) > 1) {
                // Check if items are for different children
                $children = [];
                foreach ($item_keys as $key) {
                    $assigned_player = $result['items'][$key]['data']['assigned_player'];
                    if (!empty($assigned_player) && !in_array($assigned_player, $children)) {
                        $children[] = $assigned_player;
                    }
                }
                
                if (count($children) > 1) {
                    // Sort by price and apply discount to lesser amounts
                    usort($item_keys, function($a, $b) use ($result) {
                        return $result['items'][$b]['discounted_price'] <=> $result['items'][$a]['discounted_price'];
                    });
                    
                    // Apply sibling discount to all but the highest-priced item
                    for ($i = 1; $i < count($item_keys); $i++) {
                        $item_key = $item_keys[$i];
                        $sibling_discount_rate = 0.10; // 10% sibling discount
                        
                        $original_price = $result['items'][$item_key]['discounted_price'];
                        $discount_amount = $original_price * $sibling_discount_rate;
                        
                        $result['items'][$item_key]['discounted_price'] -= $discount_amount;
                        $result['items'][$item_key]['discounts'][] = [
                            'type' => 'sibling',
                            'description' => 'Sibling discount (10% off)',
                            'amount' => $discount_amount,
                            'rate' => $sibling_discount_rate
                        ];
                    }
                }
            }
        }
    }

    /**
     * Apply pro-rated pricing for courses based on start date
     * 
     * @param array &$result Reference to pricing result
     * @param array $course_items Course items
     */
    private function apply_prorated_pricing(&$result, $course_items) {
        foreach ($result['items'] as &$item) {
            if ($item['type'] === 'courses' && !empty($item['data']['start_date'])) {
                $prorated_discount = $this->calculate_prorated_discount(
                    $item['data']['start_date'],
                    $item['data']['end_date']
                );
                
                if ($prorated_discount > 0) {
                    $discount_amount = $item['discounted_price'] * $prorated_discount;
                    $item['discounted_price'] -= $discount_amount;
                    $item['discounts'][] = [
                        'type' => 'prorated',
                        'description' => sprintf('Pro-rated pricing (%.1f%% off)', $prorated_discount * 100),
                        'amount' => $discount_amount,
                        'rate' => $prorated_discount
                    ];
                }
            }
        }
    }

    /**
     * Calculate pro-rated discount based on course dates
     * 
     * @param string $start_date Course start date
     * @param string $end_date   Course end date
     * 
     * @return float Discount rate (0.0 to 1.0)
     */
    private function calculate_prorated_discount($start_date, $end_date) {
        try {
            $today = new \DateTime();
            $start = new \DateTime($start_date);
            $end = new \DateTime($end_date);
            
            // If course hasn't started yet, no discount
            if ($today < $start) {
                return 0.0;
            }
            
            // If course has ended, should not be bookable
            if ($today > $end) {
                return 0.0;
            }
            
            // Calculate remaining weeks
            $total_weeks = DateHelper::get_weeks_between($start, $end);
            $remaining_weeks = DateHelper::get_weeks_between($today, $end);
            
            if ($total_weeks <= 0) {
                return 0.0;
            }
            
            $discount_rate = 1.0 - ($remaining_weeks / $total_weeks);
            return max(0.0, min(1.0, $discount_rate));
            
        } catch (\Exception $e) {
            $this->logger->error('Error calculating pro-rated discount', [
                'start_date' => $start_date,
                'end_date' => $end_date,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Get pricing summary for display
     * 
     * @param array $pricing_result Result from calculate_cart_pricing
     * 
     * @return array Formatted pricing summary
     */
    public function get_pricing_summary($pricing_result) {
        return [
            'subtotal' => number_format($pricing_result['original_total'], 2),
            'discounts' => number_format($pricing_result['total_savings'], 2),
            'total' => number_format($pricing_result['final_total'], 2),
            'discount_details' => $this->format_discount_details($pricing_result['discounts_applied']),
            'savings_percentage' => $pricing_result['original_total'] > 0 
                ? round(($pricing_result['total_savings'] / $pricing_result['original_total']) * 100, 1)
                : 0
        ];
    }

    /**
     * Format discount details for display
     * 
     * @param array $discounts_applied Array of applied discounts
     * 
     * @return array Formatted discount details
     */
    private function format_discount_details($discounts_applied) {
        $details = [];
        $grouped_discounts = [];
        
        // Group similar discounts
        foreach ($discounts_applied as $discount) {
            $key = $discount['type'];
            if (!isset($grouped_discounts[$key])) {
                $grouped_discounts[$key] = [
                    'type' => $discount['type'],
                    'total_amount' => 0,
                    'count' => 0
                ];
            }
            $grouped_discounts[$key]['total_amount'] += $discount['amount'];
            $grouped_discounts[$key]['count']++;
        }
        
        // Format for display
        foreach ($grouped_discounts as $discount) {
            $description = $this->get_discount_description($discount['type'], $discount['count']);
            $details[] = [
                'description' => $description,
                'amount' => number_format($discount['total_amount'], 2),
                'count' => $discount['count']
            ];
        }
        
        return $details;
    }

    /**
     * Get user-friendly discount description
     * 
     * @param string $type  Discount type
     * @param int    $count Number of items with this discount
     * 
     * @return string User-friendly description
     */
    private function get_discount_description($type, $count) {
        switch ($type) {
            case 'camp_combo':
                return $count > 1 ? 'Multi-child camp discounts' : 'Multi-child camp discount';
            case 'course_combo':
                return $count > 1 ? 'Multi-child course discounts' : 'Multi-child course discount';
            case 'course_same_season':
                return $count > 1 ? 'Same season course discounts' : 'Same season course discount';
            case 'sibling':
                return $count > 1 ? 'Sibling discounts' : 'Sibling discount';
            case 'prorated':
                return $count > 1 ? 'Pro-rated course prices' : 'Pro-rated course price';
            default:
                return ucfirst(str_replace('_', ' ', $type)) . ' discount';
        }
    }
}