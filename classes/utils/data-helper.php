<?php
/**
 * InterSoccer Date Helper Utility
 * 
 * Handles date calculations specific to InterSoccer requirements:
 * - Pro-rated course pricing calculations
 * - Holiday adjustments for camps
 * - Season date ranges
 * - Week calculations
 * 
 * @package InterSoccer\ReportsRosters\Utils
 * @subpackage Utils
 * @version 1.0.0
 */

namespace InterSoccer\ReportsRosters\Utils;

if (!defined('ABSPATH')) {
    exit;
}

class DateHelper {

    /**
     * Swiss holidays that affect camp scheduling
     * 
     * @var array
     */
    private static $swiss_holidays = [
        // Fixed holidays
        '01-01' => 'New Year\'s Day',
        '08-01' => 'Swiss National Day',
        '12-25' => 'Christmas Day',
        '12-26' => 'Boxing Day',
        
        // Variable holidays (calculated each year)
        // Easter Monday, Ascension Day, Whit Monday, etc.
    ];

    /**
     * Canton-specific holidays
     * 
     * @var array
     */
    private static $canton_holidays = [
        'geneva' => [
            '12-31' => 'Restoration Day'
        ],
        'basel' => [
            // Fasnacht dates
        ],
        'zurich' => [
            '01-02' => 'Berchtoldstag'
        ]
    ];

    /**
     * Get the number of weeks between two dates
     * 
     * @param \DateTime|string $start_date Start date
     * @param \DateTime|string $end_date   End date
     * 
     * @return float Number of weeks (can be fractional)
     */
    public static function get_weeks_between($start_date, $end_date) {
        try {
            if (is_string($start_date)) {
                $start_date = new \DateTime($start_date);
            }
            if (is_string($end_date)) {
                $end_date = new \DateTime($end_date);
            }
            
            $interval = $start_date->diff($end_date);
            $days = $interval->days;
            
            return $days / 7.0;
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error calculating weeks between dates: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get the number of business days between two dates (excluding weekends)
     * 
     * @param \DateTime|string $start_date Start date
     * @param \DateTime|string $end_date   End date
     * 
     * @return int Number of business days
     */
    public static function get_business_days_between($start_date, $end_date) {
        try {
            if (is_string($start_date)) {
                $start_date = new \DateTime($start_date);
            }
            if (is_string($end_date)) {
                $end_date = new \DateTime($end_date);
            }
            
            $business_days = 0;
            $current_date = clone $start_date;
            
            while ($current_date <= $end_date) {
                $day_of_week = $current_date->format('N'); // 1 = Monday, 7 = Sunday
                if ($day_of_week >= 1 && $day_of_week <= 5) { // Monday to Friday
                    $business_days++;
                }
                $current_date->add(new \DateInterval('P1D'));
            }
            
            return $business_days;
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error calculating business days: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Adjust camp dates to skip holidays
     * 
     * @param \DateTime $start_date  Camp start date
     * @param \DateTime $end_date    Camp end date
     * @param string    $canton      Canton for local holidays
     * 
     * @return array Adjusted dates with holidays skipped
     */
    public static function adjust_camp_dates_for_holidays($start_date, $end_date, $canton = null) {
        try {
            if (is_string($start_date)) {
                $start_date = new \DateTime($start_date);
            }
            if (is_string($end_date)) {
                $end_date = new \DateTime($end_date);
            }
            
            $holidays = self::get_holidays_in_range($start_date, $end_date, $canton);
            $camp_days = [];
            $current_date = clone $start_date;
            
            while ($current_date <= $end_date) {
                $date_string = $current_date->format('Y-m-d');
                $day_of_week = $current_date->format('N');
                
                // Include only weekdays that are not holidays
                if ($day_of_week >= 1 && $day_of_week <= 5 && !in_array($date_string, $holidays)) {
                    $camp_days[] = clone $current_date;
                }
                
                $current_date->add(new \DateInterval('P1D'));
            }
            
            return [
                'camp_days' => $camp_days,
                'holidays_skipped' => $holidays,
                'total_camp_days' => count($camp_days)
            ];
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error adjusting camp dates: ' . $e->getMessage());
            return [
                'camp_days' => [],
                'holidays_skipped' => [],
                'total_camp_days' => 0
            ];
        }
    }

    /**
     * Get holidays within a date range
     * 
     * @param \DateTime $start_date Start date
     * @param \DateTime $end_date   End date
     * @param string    $canton     Canton for local holidays
     * 
     * @return array Array of holiday dates (Y-m-d format)
     */
    public static function get_holidays_in_range($start_date, $end_date, $canton = null) {
        $holidays = [];
        $current_year = intval($start_date->format('Y'));
        $end_year = intval($end_date->format('Y'));
        
        for ($year = $current_year; $year <= $end_year; $year++) {
            // Fixed holidays
            foreach (self::$swiss_holidays as $date_key => $name) {
                $holiday_date = new \DateTime("{$year}-{$date_key}");
                if ($holiday_date >= $start_date && $holiday_date <= $end_date) {
                    $holidays[] = $holiday_date->format('Y-m-d');
                }
            }
            
            // Canton-specific holidays
            if ($canton && isset(self::$canton_holidays[strtolower($canton)])) {
                foreach (self::$canton_holidays[strtolower($canton)] as $date_key => $name) {
                    $holiday_date = new \DateTime("{$year}-{$date_key}");
                    if ($holiday_date >= $start_date && $holiday_date <= $end_date) {
                        $holidays[] = $holiday_date->format('Y-m-d');
                    }
                }
            }
            
            // Variable holidays (Easter-based)
            $easter_holidays = self::get_easter_holidays($year);
            foreach ($easter_holidays as $holiday_date) {
                if ($holiday_date >= $start_date && $holiday_date <= $end_date) {
                    $holidays[] = $holiday_date->format('Y-m-d');
                }
            }
        }
        
        return array_unique($holidays);
    }

    /**
     * Get Easter-based holidays for a given year
     * 
     * @param int $year Year
     * 
     * @return array Array of DateTime objects for Easter-based holidays
     */
    public static function get_easter_holidays($year) {
        $holidays = [];
        
        try {
            // Calculate Easter Sunday
            $easter = new \DateTime("@" . easter_date($year));
            
            // Good Friday (2 days before Easter)
            $good_friday = clone $easter;
            $good_friday->sub(new \DateInterval('P2D'));
            $holidays[] = $good_friday;
            
            // Easter Monday (day after Easter)
            $easter_monday = clone $easter;
            $easter_monday->add(new \DateInterval('P1D'));
            $holidays[] = $easter_monday;
            
            // Ascension Day (39 days after Easter)
            $ascension = clone $easter;
            $ascension->add(new \DateInterval('P39D'));
            $holidays[] = $ascension;
            
            // Whit Monday (50 days after Easter)
            $whit_monday = clone $easter;
            $whit_monday->add(new \DateInterval('P50D'));
            $holidays[] = $whit_monday;
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error calculating Easter holidays: ' . $e->getMessage());
        }
        
        return $holidays;
    }

    /**
     * Get season date ranges
     * 
     * @param string $season Season name (Spring, Summer, Autumn, Winter)
     * @param int    $year   Year
     * 
     * @return array Start and end dates for the season
     */
    public static function get_season_dates($season, $year) {
        $season_dates = [
            'spring' => [
                'start' => "{$year}-03-20",
                'end' => "{$year}-06-20"
            ],
            'summer' => [
                'start' => "{$year}-06-21",
                'end' => "{$year}-09-22"
            ],
            'autumn' => [
                'start' => "{$year}-09-23",
                'end' => "{$year}-12-20"
            ],
            'winter' => [
                'start' => "{$year}-12-21",
                'end' => ($year + 1) . "-03-19"
            ]
        ];
        
        $season_key = strtolower($season);
        
        if (!isset($season_dates[$season_key])) {
            return [
                'start' => "{$year}-01-01",
                'end' => "{$year}-12-31"
            ];
        }
        
        return [
            'start' => new \DateTime($season_dates[$season_key]['start']),
            'end' => new \DateTime($season_dates[$season_key]['end'])
        ];
    }

    /**
     * Get camp weeks for a season
     * 
     * @param string $season Season name
     * @param int    $year   Year
     * 
     * @return array Array of week objects with start/end dates and week numbers
     */
    public static function get_camp_weeks($season, $year) {
        $season_dates = self::get_season_dates($season, $year);
        $weeks = [];
        
        try {
            $current_date = clone $season_dates['start'];
            $week_number = 1;
            
            // Find the first Monday of the season
            while ($current_date->format('N') != 1) {
                $current_date->add(new \DateInterval('P1D'));
            }
            
            while ($current_date <= $season_dates['end']) {
                $week_start = clone $current_date;
                $week_end = clone $current_date;
                $week_end->add(new \DateInterval('P4D')); // Friday
                
                // Don't include weeks that go beyond the season
                if ($week_end > $season_dates['end']) {
                    break;
                }
                
                $weeks[] = [
                    'week_number' => $week_number,
                    'start_date' => $week_start,
                    'end_date' => $week_end,
                    'display_name' => ucfirst($season) . " Week {$week_number}: " . 
                                     $week_start->format('M j') . "-" . $week_end->format('j'),
                    'full_display' => ucfirst($season) . " Week {$week_number}: " . 
                                      $week_start->format('F j') . "-" . $week_end->format('j') . 
                                      " (" . self::get_business_days_between($week_start, $week_end) . " days)"
                ];
                
                $current_date->add(new \DateInterval('P7D'));
                $week_number++;
            }
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error generating camp weeks: ' . $e->getMessage());
        }
        
        return $weeks;
    }

    /**
     * Calculate pro-rated discount based on remaining course time
     * 
     * @param \DateTime|string $course_start Course start date
     * @param \DateTime|string $course_end   Course end date
     * @param \DateTime|string $join_date    Date when student joins (default: today)
     * 
     * @return array Pro-rating details
     */
    public static function calculate_prorated_discount($course_start, $course_end, $join_date = null) {
        try {
            if (is_string($course_start)) {
                $course_start = new \DateTime($course_start);
            }
            if (is_string($course_end)) {
                $course_end = new \DateTime($course_end);
            }
            if ($join_date === null) {
                $join_date = new \DateTime();
            } elseif (is_string($join_date)) {
                $join_date = new \DateTime($join_date);
            }
            
            // If joining before course starts, no discount
            if ($join_date <= $course_start) {
                return [
                    'discount_rate' => 0.0,
                    'weeks_total' => self::get_weeks_between($course_start, $course_end),
                    'weeks_remaining' => self::get_weeks_between($course_start, $course_end),
                    'weeks_missed' => 0,
                    'description' => 'Full term - no discount'
                ];
            }
            
            // If joining after course ends, should not be allowed
            if ($join_date >= $course_end) {
                return [
                    'discount_rate' => 1.0,
                    'weeks_total' => self::get_weeks_between($course_start, $course_end),
                    'weeks_remaining' => 0,
                    'weeks_missed' => self::get_weeks_between($course_start, $course_end),
                    'description' => 'Course has ended'
                ];
            }
            
            $total_weeks = self::get_weeks_between($course_start, $course_end);
            $weeks_missed = self::get_weeks_between($course_start, $join_date);
            $weeks_remaining = self::get_weeks_between($join_date, $course_end);
            
            $discount_rate = $total_weeks > 0 ? $weeks_missed / $total_weeks : 0;
            
            return [
                'discount_rate' => min(1.0, max(0.0, $discount_rate)),
                'weeks_total' => round($total_weeks, 1),
                'weeks_remaining' => round($weeks_remaining, 1),
                'weeks_missed' => round($weeks_missed, 1),
                'description' => round($weeks_remaining, 1) . " weeks remaining of " . round($total_weeks, 1)
            ];
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error calculating pro-rated discount: ' . $e->getMessage());
            return [
                'discount_rate' => 0.0,
                'weeks_total' => 0,
                'weeks_remaining' => 0,
                'weeks_missed' => 0,
                'description' => 'Error calculating discount'
            ];
        }
    }

    /**
     * Format date range for display
     * 
     * @param \DateTime|string $start_date Start date
     * @param \DateTime|string $end_date   End date
     * @param string           $format     Display format (short, medium, long)
     * 
     * @return string Formatted date range
     */
    public static function format_date_range($start_date, $end_date, $format = 'medium') {
        try {
            if (is_string($start_date)) {
                $start_date = new \DateTime($start_date);
            }
            if (is_string($end_date)) {
                $end_date = new \DateTime($end_date);
            }
            
            switch ($format) {
                case 'short':
                    if ($start_date->format('Y-m') === $end_date->format('Y-m')) {
                        // Same month
                        return $start_date->format('M j') . '-' . $end_date->format('j, Y');
                    } else {
                        return $start_date->format('M j') . ' - ' . $end_date->format('M j, Y');
                    }
                    
                case 'long':
                    return $start_date->format('l, F j, Y') . ' to ' . $end_date->format('l, F j, Y');
                    
                case 'medium':
                default:
                    if ($start_date->format('Y-m') === $end_date->format('Y-m')) {
                        // Same month
                        return $start_date->format('F j') . '-' . $end_date->format('j, Y');
                    } else {
                        return $start_date->format('F j') . ' - ' . $end_date->format('F j, Y');
                    }
            }
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error formatting date range: ' . $e->getMessage());
            return 'Invalid date range';
        }
    }

    /**
     * Get age from date of birth
     * 
     * @param \DateTime|string $dob       Date of birth
     * @param \DateTime|string $reference Reference date (default: today)
     * 
     * @return int Age in years
     */
    public static function get_age($dob, $reference = null) {
        try {
            if (is_string($dob)) {
                $dob = new \DateTime($dob);
            }
            if ($reference === null) {
                $reference = new \DateTime();
            } elseif (is_string($reference)) {
                $reference = new \DateTime($reference);
            }
            
            $interval = $dob->diff($reference);
            return $interval->y;
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error calculating age: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if date falls within age group range
     * 
     * @param \DateTime|string $dob        Date of birth
     * @param string           $age_group  Age group (e.g., "3-5y", "5-13y (Full Day)")
     * @param \DateTime|string $reference  Reference date
     * 
     * @return bool True if age matches the group
     */
    public static function is_age_in_group($dob, $age_group, $reference = null) {
        try {
            $age = self::get_age($dob, $reference);
            
            // Extract age range from group string
            preg_match('/(\d+)-(\d+)y/', $age_group, $matches);
            
            if (count($matches) >= 3) {
                $min_age = intval($matches[1]);
                $max_age = intval($matches[2]);
                
                return $age >= $min_age && $age <= $max_age;
            }
            
            return false;
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error checking age group: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get next occurrence of a day of the week
     * 
     * @param string           $day_of_week Day name (Monday, Tuesday, etc.)
     * @param \DateTime|string $from_date   Starting date (default: today)
     * 
     * @return \DateTime Next occurrence of the day
     */
    public static function get_next_day_of_week($day_of_week, $from_date = null) {
        try {
            if ($from_date === null) {
                $from_date = new \DateTime();
            } elseif (is_string($from_date)) {
                $from_date = new \DateTime($from_date);
            }
            
            $target_day = clone $from_date;
            $target_day->modify("next {$day_of_week}");
            
            return $target_day;
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error finding next day of week: ' . $e->getMessage());
            return new \DateTime();
        }
    }

    /**
     * Get all occurrences of a day of the week within a date range
     * 
     * @param string           $day_of_week Day name
     * @param \DateTime|string $start_date  Range start
     * @param \DateTime|string $end_date    Range end
     * 
     * @return array Array of DateTime objects
     */
    public static function get_day_occurrences($day_of_week, $start_date, $end_date) {
        try {
            if (is_string($start_date)) {
                $start_date = new \DateTime($start_date);
            }
            if (is_string($end_date)) {
                $end_date = new \DateTime($end_date);
            }
            
            $occurrences = [];
            $current_date = clone $start_date;
            
            // Find first occurrence of the day
            while ($current_date->format('l') !== $day_of_week && $current_date <= $end_date) {
                $current_date->add(new \DateInterval('P1D'));
            }
            
            // Collect all occurrences
            while ($current_date <= $end_date) {
                $occurrences[] = clone $current_date;
                $current_date->add(new \DateInterval('P7D')); // Add one week
            }
            
            return $occurrences;
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error finding day occurrences: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Convert timezone for display
     * 
     * @param \DateTime|string $datetime     DateTime to convert
     * @param string           $from_tz      Source timezone
     * @param string           $to_tz        Target timezone
     * 
     * @return \DateTime Converted DateTime
     */
    public static function convert_timezone($datetime, $from_tz = 'UTC', $to_tz = 'Europe/Zurich') {
        try {
            if (is_string($datetime)) {
                $datetime = new \DateTime($datetime, new \DateTimeZone($from_tz));
            }
            
            $datetime->setTimezone(new \DateTimeZone($to_tz));
            return $datetime;
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error converting timezone: ' . $e->getMessage());
            return new \DateTime();
        }
    }

    /**
     * Check if a date is a business day (not weekend or holiday)
     * 
     * @param \DateTime|string $date   Date to check
     * @param string           $canton Canton for holiday checking
     * 
     * @return bool True if it's a business day
     */
    public static function is_business_day($date, $canton = null) {
        try {
            if (is_string($date)) {
                $date = new \DateTime($date);
            }
            
            // Check if weekend
            $day_of_week = $date->format('N');
            if ($day_of_week >= 6) { // Saturday or Sunday
                return false;
            }
            
            // Check if holiday
            $holidays = self::get_holidays_in_range($date, $date, $canton);
            return !in_array($date->format('Y-m-d'), $holidays);
            
        } catch (\Exception $e) {
            error_log('InterSoccer DateHelper: Error checking business day: ' . $e->getMessage());
            return false;
        }
    }
}