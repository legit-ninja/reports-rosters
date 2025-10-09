<?php
/**
 * Rosters Collection Class
 * 
 * Specialized collection for Roster model instances with roster-specific operations.
 * Provides advanced filtering, grouping, and analysis capabilities for roster data.
 * 
 * @package InterSoccer\ReportsRosters\Data\Collections
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Data\Collections;

use InterSoccer\ReportsRosters\Data\Collections\AbstractCollection;
use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Data\Models\AbstractModel;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rosters Collection Class
 * 
 * Specialized collection for managing Roster model instances
 */
class RostersCollection extends AbstractCollection {
    
    /**
     * Add a roster to the collection
     * 
     * @param Roster $roster Roster to add
     * @return self
     * @throws \InvalidArgumentException If item is not a Roster instance
     */
    public function add(AbstractModel $roster) {
        if (!$roster instanceof Roster) {
            throw new \InvalidArgumentException('RostersCollection can only contain Roster instances');
        }
        
        return parent::add($roster);
    }
    
    /**
     * Filter rosters by activity type
     * 
     * @param string $activity_type Activity type (Camp, Course, Birthday Party)
     * @return static New filtered collection
     */
    public function filterByActivityType($activity_type) {
        return $this->where('activity_type', $activity_type);
    }
    
    /**
     * Filter rosters by multiple activity types
     * 
     * @param array $activity_types Array of activity types
     * @return static New filtered collection
     */
    public function filterByActivityTypes(array $activity_types) {
        return $this->whereIn('activity_type', $activity_types);
    }
    
    /**
     * Filter rosters by venue
     * 
     * @param string $venue Venue name
     * @return static New filtered collection
     */
    public function filterByVenue($venue) {
        return $this->where('venue', $venue);
    }
    
    /**
     * Filter rosters by multiple venues
     * 
     * @param array $venues Array of venue names
     * @return static New filtered collection
     */
    public function filterByVenues(array $venues) {
        return $this->whereIn('venue', $venues);
    }
    
    /**
     * Filter rosters by season
     * 
     * @param string $season Season name
     * @return static New filtered collection
     */
    public function filterBySeason($season) {
        return $this->where('season', $season);
    }
    
    /**
     * Filter rosters by region
     * 
     * @param string $region Region name
     * @return static New filtered collection
     */
    public function filterByRegion($region) {
        return $this->where('region', $region);
    }
    
    /**
     * Filter rosters by order status
     * 
     * @param string $status Order status
     * @return static New filtered collection
     */
    public function filterByOrderStatus($status) {
        return $this->where('order_status', $status);
    }
    
    /**
     * Filter rosters by multiple order statuses
     * 
     * @param array $statuses Array of order statuses
     * @return static New filtered collection
     */
    public function filterByOrderStatuses(array $statuses) {
        return $this->whereIn('order_status', $statuses);
    }
    
    /**
     * Filter rosters by date range
     * 
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @return static New filtered collection
     */
    public function filterByDateRange($start_date, $end_date) {
        return $this->filter(function(Roster $roster) use ($start_date, $end_date) {
            $roster_start = $roster->start_date;
            return $roster_start >= $start_date && $roster_start <= $end_date;
        });
    }
    
    /**
     * Filter active rosters (currently happening events)
     * 
     * @return static New filtered collection
     */
    public function filterActive() {
        return $this->filter(function(Roster $roster) {
            return $roster->isActive();
        });
    }
    
    /**
     * Filter upcoming rosters (future events)
     * 
     * @return static New filtered collection
     */
    public function filterUpcoming() {
        return $this->filter(function(Roster $roster) {
            return $roster->isFutureEvent();
        });
    }
    
    /**
     * Filter past rosters (completed events)
     * 
     * @return static New filtered collection
     */
    public function filterPast() {
        return $this->filter(function(Roster $roster) {
            return $roster->isPastEvent();
        });
    }
    
    /**
     * Filter rosters with special needs players
     * 
     * @param bool $has_special_needs True to get rosters with special needs
     * @return static New filtered collection
     */
    public function filterBySpecialNeeds($has_special_needs = true) {
        return $this->filter(function(Roster $roster) use ($has_special_needs) {
            return $roster->hasSpecialNeeds() === $has_special_needs;
        });
    }
    
    /**
     * Filter rosters by gender
     * 
     * @param string $gender Gender to filter by
     * @return static New filtered collection
     */
    public function filterByGender($gender) {
        return $this->where('gender', $gender);
    }
    
    /**
     * Filter rosters by age range at event
     * 
     * @param int $min_age Minimum age
     * @param int $max_age Maximum age
     * @return static New filtered collection
     */
    public function filterByAgeRange($min_age, $max_age) {
        return $this->filter(function(Roster $roster) use ($min_age, $max_age) {
            $age = $roster->getAgeAtEvent();
            return $age >= $min_age && $age <= $max_age;
        });
    }
    
    /**
     * Filter rosters by customer ID
     * 
     * @param int $customer_id WordPress user ID
     * @return static New filtered collection
     */
    public function filterByCustomer($customer_id) {
        return $this->where('customer_id', $customer_id);
    }
    
    /**
     * Filter camp rosters only
     * 
     * @return static New filtered collection
     */
    public function filterCamps() {
        return $this->filter(function(Roster $roster) {
            return $roster->isCamp();
        });
    }
    
    /**
     * Filter course rosters only
     * 
     * @return static New filtered collection
     */
    public function filterCourses() {
        return $this->filter(function(Roster $roster) {
            return $roster->isCourse();
        });
    }
    
    /**
     * Filter birthday party rosters only
     * 
     * @return static New filtered collection
     */
    public function filterBirthdays() {
        return $this->filter(function(Roster $roster) {
            return $roster->isBirthday();
        });
    }
    
    /**
     * Filter full week/term bookings
     * 
     * @return static New filtered collection
     */
    public function filterFullBookings() {
        return $this->filter(function(Roster $roster) {
            return $roster->isFullBooking();
        });
    }
    
    /**
     * Filter partial/single day bookings
     * 
     * @return static New filtered collection
     */
    public function filterPartialBookings() {
        return $this->filter(function(Roster $roster) {
            return $roster->isPartialBooking();
        });
    }
    
    /**
     * Search rosters by player name
     * 
     * @param string $search_term Search term for player name
     * @return static New filtered collection
     */
    public function searchByPlayerName($search_term) {
        $search_term = strtolower(trim($search_term));
        
        return $this->filter(function(Roster $roster) use ($search_term) {
            $full_name = strtolower($roster->getFullName());
            return strpos($full_name, $search_term) !== false;
        });
    }
    
    /**
     * Group rosters by activity type
     * 
     * @return array Array of collections keyed by activity type
     */
    public function groupByActivityType() {
        return $this->groupBy('activity_type');
    }
    
    /**
     * Group rosters by venue
     * 
     * @return array Array of collections keyed by venue
     */
    public function groupByVenue() {
        return $this->groupBy('venue');
    }
    
    /**
     * Group rosters by season
     * 
     * @return array Array of collections keyed by season
     */
    public function groupBySeason() {
        return $this->groupBy('season');
    }
    
    /**
     * Group rosters by region
     * 
     * @return array Array of collections keyed by region
     */
    public function groupByRegion() {
        return $this->groupBy('region');
    }
    
    /**
     * Group rosters by order status
     * 
     * @return array Array of collections keyed by order status
     */
    public function groupByOrderStatus() {
        return $this->groupBy('order_status');
    }
    
    /**
     * Group rosters by event status (active, upcoming, past)
     * 
     * @return array Array of collections keyed by event status
     */
    public function groupByEventStatus() {
        return $this->groupBy(function(Roster $roster) {
            return $roster->getEventStatus();
        });
    }
    
    /**
     * Group rosters by customer
     * 
     * @return array Array of collections keyed by customer ID
     */
    public function groupByCustomer() {
        return $this->groupBy('customer_id');
    }
    
    /**
     * Group rosters by age groups
     * 
     * @return array Array of collections keyed by age group
     */
    public function groupByAgeGroup() {
        return $this->groupBy('age_group');
    }
    
    /**
     * Group rosters by month of event start date
     * 
     * @return array Array of collections keyed by month name
     */
    public function groupByMonth() {
        return $this->groupBy(function(Roster $roster) {
            return date('F Y', strtotime($roster->start_date));
        });
    }
    
    /**
     * Sort rosters by event start date
     * 
     * @param bool $descending Sort descending (newest first)
     * @return static New sorted collection
     */
    public function sortByEventDate($descending = false) {
        return $this->sortBy('start_date', SORT_STRING, $descending);
    }
    
    /**
     * Sort rosters by player name
     * 
     * @param bool $descending Sort descending
     * @return static New sorted collection
     */
    public function sortByPlayerName($descending = false) {
        return $this->sortBy(function(Roster $roster) {
            return $roster->getFullName();
        }, SORT_STRING, $descending);
    }
    
    /**
     * Sort rosters by venue
     * 
     * @param bool $descending Sort descending
     * @return static New sorted collection
     */
    public function sortByVenue($descending = false) {
        return $this->sortBy('venue', SORT_STRING, $descending);
    }
    
    /**
     * Sort rosters by priority (for coach attention)
     * 
     * @param bool $descending Sort descending (highest priority first)
     * @return static New sorted collection
     */
    public function sortByPriority($descending = true) {
        return $this->sortBy(function(Roster $roster) {
            return $roster->getPriority();
        }, SORT_NUMERIC, $descending);
    }
    
    /**
     * Sort rosters by age at event
     * 
     * @param bool $descending Sort descending (oldest first)
     * @return static New sorted collection
     */
    public function sortByAge($descending = false) {
        return $this->sortBy(function(Roster $roster) {
            return $roster->getAgeAtEvent();
        }, SORT_NUMERIC, $descending);
    }
    
    /**
     * Get roster statistics
     * 
     * @return array Roster statistics
     */
    public function getStatistics() {
        if ($this->isEmpty()) {
            return [
                'total_rosters' => 0,
                'activity_types' => [],
                'venues' => [],
                'seasons' => [],
                'order_statuses' => [],
                'event_statuses' => [],
                'age_stats' => [],
                'gender_distribution' => [],
                'special_needs_count' => 0,
                'date_range' => null
            ];
        }
        
        $ages = $this->map(function(Roster $roster) {
            return $roster->getAgeAtEvent();
        })->toArray();
        
        return [
            'total_rosters' => $this->count(),
            'unique_players' => $this->unique(function(Roster $roster) {
                return $roster->customer_id . '_' . $roster->player_index;
            })->count(),
            'unique_customers' => $this->pluck('customer_id')->unique()->count(),
            'activity_types' => $this->countBy('activity_type'),
            'venues' => $this->countBy('venue'),
            'seasons' => $this->countBy('season'),
            'regions' => $this->countBy('region'),
            'order_statuses' => $this->countBy('order_status'),
            'event_statuses' => $this->countBy(function(Roster $roster) {
                return $roster->getEventStatus();
            }),
            'age_stats' => [
                'min' => min($ages),
                'max' => max($ages),
                'average' => round(array_sum($ages) / count($ages), 1)
            ],
            'gender_distribution' => $this->countBy('gender'),
            'age_group_distribution' => $this->countBy('age_group'),
            'booking_types' => $this->countBy('booking_type'),
            'special_needs_count' => $this->filterBySpecialNeeds(true)->count(),
            'date_range' => [
                'earliest' => $this->min('start_date'),
                'latest' => $this->max('end_date') ?: $this->max('start_date')
            ],
            'duration_stats' => $this->getDurationStatistics()
        ];
    }
    
    /**
     * Get duration statistics
     * 
     * @return array Duration statistics
     */
    private function getDurationStatistics() {
        $durations = $this->map(function(Roster $roster) {
            return $roster->getEventDurationDays();
        })->toArray();
        
        if (empty($durations)) {
            return ['min' => 0, 'max' => 0, 'average' => 0];
        }
        
        return [
            'min' => min($durations),
            'max' => max($durations),
            'average' => round(array_sum($durations) / count($durations), 1)
        ];
    }
    
    /**
     * Get rosters that need attention (conflicts, special needs, etc.)
     * 
     * @return array Array of attention categories with roster collections
     */
    public function getRostersNeedingAttention() {
        return [
            'conflicts' => $this->findConflicts(),
            'special_needs' => $this->filterBySpecialNeeds(true),
            'incomplete_emergency_info' => $this->filter(function(Roster $roster) {
                $contact = $roster->getEmergencyContact();
                return empty($contact['phone']) || empty($contact['name']);
            }),
            'upcoming_events' => $this->filterUpcoming()->take(10),
            'cancelled_orders' => $this->filterByOrderStatus('cancelled'),
            'pending_orders' => $this->filterByOrderStatus('pending')
        ];
    }
    
    /**
     * Find conflicting roster entries (same player, overlapping dates)
     * 
     * @return static Collection of conflicting rosters
     */
    public function findConflicts() {
        $conflicts = new static();
        $processed = [];
        
        foreach ($this->items as $index => $roster) {
            if (in_array($index, $processed)) {
                continue;
            }
            
            foreach ($this->items as $other_index => $other_roster) {
                if ($index !== $other_index && !in_array($other_index, $processed)) {
                    if ($roster->conflictsWith($other_roster)) {
                        $conflicts->add($roster);
                        $conflicts->add($other_roster);
                        $processed[] = $index;
                        $processed[] = $other_index;
                    }
                }
            }
        }
        
        return $conflicts->unique(function(Roster $roster) {
            return $roster->id;
        });
    }
    
    /**
     * Get venue utilization statistics
     * 
     * @return array Venue utilization data
     */
    public function getVenueUtilization() {
        $venue_groups = $this->groupByVenue();
        $utilization = [];
        
        foreach ($venue_groups as $venue => $rosters) {
            $utilization[$venue] = [
                'total_events' => $rosters->count(),
                'unique_players' => $rosters->unique(function(Roster $roster) {
                    return $roster->customer_id . '_' . $roster->player_index;
                })->count(),
                'activity_breakdown' => $rosters->countBy('activity_type'),
                'season_breakdown' => $rosters->countBy('season'),
                'capacity_utilization' => $this->calculateCapacityUtilization($rosters),
                'upcoming_events' => $rosters->filterUpcoming()->count(),
                'special_needs_count' => $rosters->filterBySpecialNeeds(true)->count()
            ];
        }
        
        return $utilization;
    }
    
    /**
     * Calculate capacity utilization for a venue
     * 
     * @param RostersCollection $venue_rosters Rosters for specific venue
     * @return array Capacity utilization data
     */
    private function calculateCapacityUtilization(RostersCollection $venue_rosters) {
        // Group by event dates to see how many players per event
        $events_by_date = $venue_rosters->groupBy(function(Roster $roster) {
            return $roster->start_date . '_' . $roster->activity_type;
        });
        
        $utilization_data = [];
        foreach ($events_by_date as $event_key => $event_rosters) {
            $parts = explode('_', $event_key);
            $date = $parts[0];
            $activity_type = $parts[1];
            
            $utilization_data[] = [
                'date' => $date,
                'activity_type' => $activity_type,
                'player_count' => $event_rosters->count(),
                'age_groups' => $event_rosters->countBy('age_group')
            ];
        }
        
        return $utilization_data;
    }
    
    /**
     * Generate coach briefing data
     * 
     * @param string|null $venue Specific venue filter
     * @param string|null $date Specific date filter
     * @return array Coach briefing data
     */
    public function getCoachBriefing($venue = null, $date = null) {
        $filtered_rosters = $this;
        
        if ($venue) {
            $filtered_rosters = $filtered_rosters->filterByVenue($venue);
        }
        
        if ($date) {
            $filtered_rosters = $filtered_rosters->filter(function(Roster $roster) use ($date) {
                return $roster->start_date === $date;
            });
        }
        
        $special_needs_rosters = $filtered_rosters->filterBySpecialNeeds(true);
        
        return [
            'total_players' => $filtered_rosters->count(),
            'age_distribution' => $filtered_rosters->countBy(function(Roster $roster) {
                return $roster->getAgeAtEvent() . ' years';
            }),
            'gender_split' => $filtered_rosters->countBy('gender'),
            'activity_breakdown' => $filtered_rosters->countBy('activity_type'),
            'special_needs_count' => $special_needs_rosters->count(),
            'special_needs_details' => $special_needs_rosters->map(function(Roster $roster) {
                return $roster->getCoachInfo();
            })->toArray(),
            'emergency_contacts' => $this->getEmergencyContactsList($filtered_rosters),
            'attendance_summary' => $this->getAttendanceSummary($filtered_rosters)
        ];
    }
    
    /**
     * Get emergency contacts list
     * 
     * @param RostersCollection $rosters Rosters to process
     * @return array Emergency contacts list
     */
    private function getEmergencyContactsList(RostersCollection $rosters) {
        $contacts = [];
        
        foreach ($rosters as $roster) {
            $contact_info = $roster->getEmergencyContact();
            $contacts[] = [
                'player_name' => $roster->getFullName(),
                'contact_name' => $contact_info['name'],
                'contact_phone' => $contact_info['phone'],
                'contact_email' => $contact_info['email']
            ];
        }
        
        return $contacts;
    }
    
    /**
     * Get attendance summary
     * 
     * @param RostersCollection $rosters Rosters to process
     * @return array Attendance summary
     */
    private function getAttendanceSummary(RostersCollection $rosters) {
        return [
            'total_expected' => $rosters->count(),
            'by_booking_type' => $rosters->countBy('booking_type'),
            'by_age_group' => $rosters->countBy('age_group'),
            'full_week_attendees' => $rosters->filterFullBookings()->count(),
            'partial_attendees' => $rosters->filterPartialBookings()->count(),
            'selected_days_breakdown' => $this->getSelectedDaysBreakdown($rosters)
        ];
    }
    
    /**
     * Get breakdown of selected days for partial bookings
     * 
     * @param RostersCollection $rosters Rosters to process
     * @return array Selected days breakdown
     */
    private function getSelectedDaysBreakdown(RostersCollection $rosters) {
        $days_breakdown = [];
        $partial_rosters = $rosters->filterPartialBookings();
        
        foreach ($partial_rosters as $roster) {
            $selected_days = $roster->getSelectedDaysArray();
            foreach ($selected_days as $day) {
                $days_breakdown[$day] = ($days_breakdown[$day] ?? 0) + 1;
            }
        }
        
        return $days_breakdown;
    }
    
    /**
     * Export rosters data for Excel/CSV
     * 
     * @param array $fields Fields to include in export
     * @param string $export_type Type of export (camp, course, all)
     * @return array Export data
     */
    public function exportData(array $fields = [], $export_type = 'all') {
        // Define default fields based on export type
        if (empty($fields)) {
            $base_fields = [
                'Order ID', 'First Name', 'Last Name', 'Full Name', 
                'Age at Event', 'Gender', 'Activity Type', 'Venue'
            ];
            
            switch ($export_type) {
                case 'camp':
                    $fields = array_merge($base_fields, [
                        'Camp Terms', 'Camp Times', 'Booking Type', 'Selected Days',
                        'Season', 'Medical Conditions', 'Dietary Needs', 
                        'Parent Email', 'Parent Phone', 'Emergency Contact', 'Order Status'
                    ]);
                    break;
                    
                case 'course':
                    $fields = array_merge($base_fields, [
                        'Course Day', 'Course Times', 'Start Date', 'End Date',
                        'Season', 'Medical Conditions', 'Dietary Needs',
                        'Parent Email', 'Parent Phone', 'Emergency Contact', 'Order Status'
                    ]);
                    break;
                    
                default:
                    $fields = array_merge($base_fields, [
                        'Start Date', 'End Date', 'Booking Type', 'Season', 'Region',
                        'Medical Conditions', 'Dietary Needs', 'Parent Email', 'Parent Phone',
                        'Emergency Contact', 'Emergency Phone', 'Order Status'
                    ]);
            }
        }
        
        $export_data = [];
        
        foreach ($this->items as $roster) {
            $roster_data = $roster->getExportData();
            
            // Filter to requested fields
            $filtered_data = [];
            foreach ($fields as $field) {
                $filtered_data[$field] = $roster_data[$field] ?? '';
            }
            
            $export_data[] = $filtered_data;
        }
        
        return $export_data;
    }
    
    /**
     * Generate financial summary
     * 
     * @return array Financial summary data
     */
    public function getFinancialSummary() {
        // This would integrate with WooCommerce order totals
        // For now, we'll return basic structure
        
        $completed_rosters = $this->filterByOrderStatus('completed');
        $cancelled_rosters = $this->filterByOrderStatus('cancelled');
        
        return [
            'total_bookings' => $this->count(),
            'completed_bookings' => $completed_rosters->count(),
            'cancelled_bookings' => $cancelled_rosters->count(),
            'completion_rate' => $this->count() > 0 ? round(($completed_rosters->count() / $this->count()) * 100, 1) : 0,
            'activity_breakdown' => [
                'camps' => $this->filterCamps()->count(),
                'courses' => $this->filterCourses()->count(),
                'birthdays' => $this->filterBirthdays()->count()
            ],
            'booking_type_breakdown' => $this->countBy('booking_type'),
            'discount_applications' => $this->whereNotNull('discount_applied')->count()
        ];
    }
    
    /**
     * Get weekly schedule view
     * 
     * @param string $week_start Start of week (Y-m-d format)
     * @return array Weekly schedule organized by day
     */
    public function getWeeklySchedule($week_start) {
        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
        
        $week_rosters = $this->filterByDateRange($week_start, $week_end);
        
        $schedule = [];
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        
        // Initialize days
        foreach ($days as $day) {
            $schedule[$day] = new static();
        }
        
        // Group rosters by day of week
        foreach ($week_rosters as $roster) {
            $day_of_week = date('l', strtotime($roster->start_date));
            if (isset($schedule[$day_of_week])) {
                $schedule[$day_of_week]->add($roster);
            }
        }
        
        return $schedule;
    }
    
    /**
     * Validate all rosters in the collection
     * 
     * @return array Validation results
     */
    public function validateAll() {
        $validation_results = [
            'valid_count' => 0,
            'invalid_count' => 0,
            'errors' => []
        ];
        
        foreach ($this->items as $index => $roster) {
            try {
                $roster->validate();
                $validation_results['valid_count']++;
            } catch (\Exception $e) {
                $validation_results['invalid_count']++;
                $validation_results['errors'][$index] = [
                    'roster_id' => $roster->id,
                    'player' => $roster->getFullName(),
                    'order_id' => $roster->order_id,
                    'errors' => $e->getMessage()
                ];
            }
        }
        
        return $validation_results;
    }
    
    /**
     * Create a new RostersCollection from array data
     * 
     * @param array $rosters_data Array of roster data
     * @return static New RostersCollection
     */
    public static function fromArray(array $rosters_data) {
        $collection = new static();
        
        foreach ($rosters_data as $roster_data) {
            if ($roster_data instanceof Roster) {
                $collection->add($roster_data);
            } elseif (is_array($roster_data)) {
                $roster = new Roster($roster_data);
                $collection->add($roster);
            }
        }
        
        return $collection;
    }
    
    /**
     * Get collection summary for logging
     * 
     * @return array Log-friendly summary
     */
    public function getLogSummary() {
        return [
            'total_rosters' => $this->count(),
            'unique_customers' => $this->pluck('customer_id')->unique()->count(),
            'activity_types' => $this->countBy('activity_type'),
            'venues' => array_keys($this->countBy('venue')),
            'date_range' => [
                'start' => $this->min('start_date'),
                'end' => $this->max('end_date')
            ],
            'special_needs' => $this->filterBySpecialNeeds(true)->count(),
            'order_statuses' => $this->countBy('order_status')
        ];
    }
    
    /**
     * Get attendance predictions based on historical data
     * 
     * @return array Attendance prediction data
     */
    public function getAttendancePredictions() {
        $completed_rosters = $this->filterByOrderStatus('completed');
        $total_expected = $this->count();
        
        if ($total_expected === 0) {
            return ['expected_attendance' => 0, 'confidence' => 'low'];
        }
        
        $completion_rate = $completed_rosters->count() / $total_expected;
        
        // Simple prediction model based on completion rates
        $confidence = 'medium';
        if ($completion_rate >= 0.9) {
            $confidence = 'high';
        } elseif ($completion_rate < 0.7) {
            $confidence = 'low';
        }
        
        return [
            'expected_attendance' => round($total_expected * $completion_rate),
            'completion_rate' => round($completion_rate * 100, 1),
            'confidence' => $confidence,
            'factors' => [
                'historical_completion_rate' => round($completion_rate * 100, 1) . '%',
                'special_needs_considerations' => $this->filterBySpecialNeeds(true)->count(),
                'partial_bookings' => $this->filterPartialBookings()->count(),
                'upcoming_events' => $this->filterUpcoming()->count()
            ]
        ];
    }
    
    /**
     * Check data quality across the roster collection
     * 
     * @return array Data quality report
     */
    public function getDataQualityReport() {
        return [
            'total_rosters' => $this->count(),
            'missing_player_info' => $this->filter(function(Roster $roster) {
                return empty($roster->first_name) || empty($roster->last_name);
            })->count(),
            'missing_contact_info' => $this->filter(function(Roster $roster) {
                return empty($roster->parent_email) && empty($roster->parent_phone);
            })->count(),
            'missing_emergency_contact' => $this->filter(function(Roster $roster) {
                $contact = $roster->getEmergencyContact();
                return empty($contact['phone']);
            })->count(),
            'invalid_dates' => $this->filter(function(Roster $roster) {
                return empty($roster->start_date) || 
                       (!empty($roster->end_date) && $roster->end_date < $roster->start_date);
            })->count(),
            'inconsistent_age_groups' => $this->filter(function(Roster $roster) {
                return !empty($roster->age_group) && 
                       !$roster->getPlayer()->isEligibleForAgeGroup($roster->age_group, $roster->start_date);
            })->count(),
            'conflicts_detected' => $this->findConflicts()->count(),
            'data_completeness_score' => $this->calculateDataCompletenessScore()
        ];
    }
    
    /**
     * Calculate overall data completeness score
     * 
     * @return float Completeness score (0-100)
     */
    private function calculateDataCompletenessScore() {
        if ($this->isEmpty()) {
            return 0;
        }
        
        $total_score = 0;
        $required_fields = ['first_name', 'last_name', 'dob', 'gender', 'venue', 'start_date'];
        $important_fields = ['parent_email', 'parent_phone', 'age_group', 'activity_type'];
        
        foreach ($this->items as $roster) {
            $roster_score = 0;
            $max_score = count($required_fields) * 2 + count($important_fields); // Required worth 2 points
            
            // Required fields (2 points each)
            foreach ($required_fields as $field) {
                if (!empty($roster->getAttribute($field))) {
                    $roster_score += 2;
                }
            }
            
            // Important fields (1 point each)
            foreach ($important_fields as $field) {
                if (!empty($roster->getAttribute($field))) {
                    $roster_score += 1;
                }
            }
            
            $total_score += ($roster_score / $max_score) * 100;
        }
        
        return round($total_score / $this->count(), 1);
    }
}