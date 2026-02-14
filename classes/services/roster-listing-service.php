<?php
/**
 * Roster Listing Service
 *
 * Aggregates roster data for the admin roster listing pages (camps/courses/etc)
 * using the OOP data layer.
 *
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Data\Models\Roster;

defined('ABSPATH') or die('Restricted access');

class RosterListingService {

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var RosterRepository
     */
    private $repository;

    /**
     * Allowed WooCommerce order statuses for roster listings.
     *
     * @var string[]
     */
    private $allowed_statuses = ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'];

    public function __construct(Logger $logger = null, RosterRepository $repository = null) {
        $this->logger = $logger ?: new Logger();
        $this->repository = $repository ?: new RosterRepository($this->logger);
    }

    /**
     * Aggregate roster data for the Camps page.
     *
     * @param array $filters Selected filter values (season, venue, camp_terms, age_group, city)
     * @param array $context Context data (is_coach, accessible_venues)
     * @param bool  $girls_only Whether to fetch girls-only camps
     * @return array Structured data for the page
     */
    public function getCampListings(array $filters = [], array $context = [], bool $girls_only = false): array {
        $filters = $this->normaliseCampFilters($filters);
        $context = $this->normaliseContext($context);

        if ($context['is_coach'] && empty($context['accessible_venues'])) {
            return $this->emptyCampResponse();
        }

        $criteria = [
            'activity_type' => [Roster::ACTIVITY_CAMP, 'Camp, Girls Only', "Camp, Girls' only"],
            'girls_only' => $girls_only ? 1 : 0,
            'is_placeholder' => 0,
        ];

        // Add event_completed filter based on status
        if (isset($filters['status']) && $filters['status'] === 'closed') {
            $criteria['event_completed'] = 1;
        } elseif (!isset($filters['status']) || $filters['status'] !== 'all') {
            // Default: exclude closed rosters
            $criteria['event_completed'] = 0;
        }
        // If status is 'all', don't filter by event_completed

        if (!empty($context['accessible_venues'])) {
            $criteria['venue'] = $context['accessible_venues'];
        }

        $start_time = microtime(true);
        $collection = $this->repository->where($criteria);
        $rosters = $this->prepareRosterArray($collection);
        $query_time = microtime(true) - $start_time;

        if (empty($rosters)) {
            return $this->emptyCampResponse($query_time);
        }

        $aggregation = $this->aggregateCampGroups($rosters, $girls_only);

        $all_groups = array_values($aggregation['groups']);
        $filter_sets = $aggregation['filters'];

        $display_groups = $this->applyCampFilters($all_groups, $filters);
        $grouped_by_season = $this->groupCampsBySeason($display_groups);

        return [
            'query_time' => $query_time,
            'all_groups' => $all_groups,
            'display_groups' => $display_groups,
            'grouped' => $grouped_by_season,
            'filters' => [
                'seasons' => $filter_sets['seasons'],
                'venues' => $filter_sets['venues'],
                'camp_terms' => $filter_sets['camp_terms'],
                'age_groups' => $filter_sets['age_groups'],
                'cities' => $filter_sets['cities'],
            ],
        ];
    }

    private function normaliseCampFilters(array $filters): array {
        return [
            'season' => isset($filters['season']) ? sanitize_text_field($filters['season']) : '',
            'venue' => isset($filters['venue']) ? sanitize_text_field($filters['venue']) : '',
            'camp_terms' => isset($filters['camp_terms']) ? sanitize_text_field($filters['camp_terms']) : '',
            'age_group' => isset($filters['age_group']) ? sanitize_text_field($filters['age_group']) : '',
            'city' => isset($filters['city']) ? sanitize_text_field($filters['city']) : '',
            'status' => isset($filters['status']) ? sanitize_text_field($filters['status']) : '',
        ];
    }

    private function normaliseContext(array $context): array {
        return [
            'is_coach' => !empty($context['is_coach']),
            'accessible_venues' => !empty($context['accessible_venues']) ? array_filter((array) $context['accessible_venues']) : [],
        ];
    }

    private function emptyCampResponse(float $query_time = 0.0): array {
        return [
            'query_time' => $query_time,
            'all_groups' => [],
            'display_groups' => [],
            'grouped' => [],
            'filters' => [
                'seasons' => [],
                'venues' => [],
                'camp_terms' => [],
                'age_groups' => [],
                'cities' => [],
            ],
        ];
    }

    private function prepareRosterArray($collection): array {
        $rows = [];

        foreach ($collection as $model) {
            if (!$model->order_id) {
                continue;
            }

            $status = get_post_status($model->order_id);
            if (!$status || !in_array($status, $this->allowed_statuses, true)) {
                continue;
            }

            $data = $model->toArray();
            $data['girls_only'] = isset($data['girls_only']) ? (int) $data['girls_only'] : 0;
            $data['order_status'] = $status;
            $data['event_signature'] = $data['event_signature'] ?? '';
            $data['city'] = $data['city'] ?? 'N/A';
            $data['camp_terms'] = $data['camp_terms'] ?? 'N/A';
            $data['times'] = $data['times'] ?? ($data['camp_times'] ?? ($data['course_times'] ?? 'N/A'));
            $data['product_name'] = $data['product_name'] ?? ($data['event_type'] ?? 'N/A');

            $rows[] = $data;
        }

        return $rows;
    }

    /**
     * Aggregate roster data for the Courses page.
     */
    public function getCourseListings(array $filters = [], array $context = [], bool $girls_only = false): array {
        $filters = $this->normaliseCourseFilters($filters);
        $context = $this->normaliseContext($context);

        if ($context['is_coach'] && empty($context['accessible_venues'])) {
            return $this->emptyCourseResponse();
        }

        $criteria = [
            'activity_type' => [Roster::ACTIVITY_COURSE, 'Course, Girls Only', "Course, Girls' only"],
            'girls_only' => $girls_only ? 1 : 0,
            'is_placeholder' => 0,
        ];

        // Add event_completed filter based on status
        if (isset($filters['status']) && $filters['status'] === 'closed') {
            $criteria['event_completed'] = 1;
        } elseif (!isset($filters['status']) || $filters['status'] !== 'all') {
            // Default: exclude closed rosters
            $criteria['event_completed'] = 0;
        }
        // If status is 'all', don't filter by event_completed

        if (!empty($context['accessible_venues'])) {
            $criteria['venue'] = $context['accessible_venues'];
        }

        $start_time = microtime(true);
        $collection = $this->repository->where($criteria);
        $rosters = $this->prepareRosterArray($collection);
        $query_time = microtime(true) - $start_time;

        if (empty($rosters)) {
            return $this->emptyCourseResponse($query_time);
        }

        $aggregation = $this->aggregateCourseGroups($rosters, $girls_only);

        $all_groups = array_values($aggregation['groups']);
        $filter_sets = $aggregation['filters'];

        $display_groups = $this->applyCourseFilters($all_groups, $filters);
        $grouped = $this->groupCoursesBySeasonDay($display_groups);

        return [
            'query_time' => $query_time,
            'all_groups' => $all_groups,
            'display_groups' => $display_groups,
            'grouped' => $grouped,
            'filters' => [
                'seasons' => $filter_sets['seasons'],
                'venues' => $filter_sets['venues'],
                'course_days' => $filter_sets['course_days'],
                'age_groups' => $filter_sets['age_groups'],
                'cities' => $filter_sets['cities'],
            ],
        ];
    }

    private function normaliseCourseFilters(array $filters): array {
        return [
            'season' => isset($filters['season']) ? sanitize_text_field($filters['season']) : '',
            'venue' => isset($filters['venue']) ? sanitize_text_field($filters['venue']) : '',
            'course_day' => isset($filters['course_day']) ? sanitize_text_field($filters['course_day']) : '',
            'age_group' => isset($filters['age_group']) ? sanitize_text_field($filters['age_group']) : '',
            'city' => isset($filters['city']) ? sanitize_text_field($filters['city']) : '',
            'status' => isset($filters['status']) ? sanitize_text_field($filters['status']) : '',
        ];
    }

    private function emptyCourseResponse(float $query_time = 0.0): array {
        return [
            'query_time' => $query_time,
            'all_groups' => [],
            'display_groups' => [],
            'grouped' => [],
            'filters' => [
                'seasons' => [],
                'venues' => [],
                'course_days' => [],
                'age_groups' => [],
                'cities' => [],
            ],
        ];
    }

    private function aggregateCourseGroups(array $rosters, bool $girls_only): array {
        $groups = [];
        $filters = [
            'seasons' => [],
            'venues' => [],
            'course_days' => [],
            'age_groups' => [],
            'cities' => [],
        ];

        foreach ($rosters as $row) {
            $signature = $row['event_signature'];
            if (empty($signature)) {
                $signature = md5(($row['product_id'] ?? 0) . '|' . ($row['course_day'] ?? '') . '|' . ($row['venue'] ?? '') . '|' . ($row['times'] ?? ''));
            }

            if (!isset($groups[$signature])) {
                $season_raw = $row['season'] ?? 'N/A';
                $season_display = \intersoccer_normalize_season_for_display($season_raw);

                $groups[$signature] = [
                    'event_signature' => $row['event_signature'] ?: $signature,
                    'season' => $season_display,
                    'season_raw' => $season_raw,
                    'venue' => $row['venue'] ?? 'N/A',
                    'city' => $row['city'] ?? 'N/A',
                    'age_group' => $row['age_group'] ?? 'N/A',
                    'times' => $row['times'] ?? 'N/A',
                    'course_day' => $row['course_day'] ?? 'N/A',
                    'product_name' => $row['product_name'] ?? 'N/A',
                    'girls_only' => $girls_only ? 1 : 0,
                    'variation_ids' => [],
                    'order_item_ids' => [],
                    'start_dates' => [],
                    'end_dates' => [],
                ];
            }

            $group =& $groups[$signature];

            if (!empty($row['variation_id'])) {
                $group['variation_ids'][$row['variation_id']] = $row['variation_id'];
            }

            if (!empty($row['order_item_id'])) {
                $group['order_item_ids'][$row['order_item_id']] = true;
            }

            if (!empty($row['start_date'])) {
                $group['start_dates'][] = $row['start_date'];
            }

            if (!empty($row['end_date'])) {
                $group['end_dates'][] = $row['end_date'];
            }

            if (empty($group['product_name']) || $group['product_name'] === 'N/A') {
                $group['product_name'] = $row['product_name'] ?? 'N/A';
            }
        }

        foreach ($groups as &$group) {
            $group['variation_ids'] = array_values($group['variation_ids']);
            $group['order_item_ids'] = array_keys($group['order_item_ids']);
            $group['total_players'] = count($group['order_item_ids']);

            $group['corrected_start_date'] = $this->getEarliestDate($group['start_dates']);
            $group['corrected_end_date'] = $this->getLatestDate($group['end_dates']);

            $filters['seasons'][$group['season']] = $group['season'];
            $filters['venues'][$group['venue']] = $group['venue'];
            if (!empty($group['course_day']) && $group['course_day'] !== 'N/A') {
                $filters['course_days'][$group['course_day']] = $group['course_day'];
            }
            if (!empty($group['age_group']) && $group['age_group'] !== 'N/A') {
                $filters['age_groups'][$group['age_group']] = $group['age_group'];
            }
            if (!empty($group['city']) && $group['city'] !== 'N/A') {
                $filters['cities'][$group['city']] = $group['city'];
            }

            unset($group['start_dates'], $group['end_dates']);
        }

        foreach ($filters as $key => $values) {
            $filters[$key] = array_values($values);
            sort($filters[$key], SORT_NATURAL | SORT_FLAG_CASE);
        }

        return [
            'groups' => $groups,
            'filters' => $filters,
        ];
    }

    private function applyCourseFilters(array $groups, array $filters): array {
        return array_values(array_filter($groups, function ($group) use ($filters) {
            if ($filters['season'] && $group['season'] !== $filters['season'] && $group['season_raw'] !== $filters['season']) {
                return false;
            }
            if ($filters['venue'] && $group['venue'] !== $filters['venue']) {
                return false;
            }
            if ($filters['course_day'] && $group['course_day'] !== $filters['course_day']) {
                return false;
            }
            if ($filters['age_group'] && $group['age_group'] !== $filters['age_group']) {
                return false;
            }
            if ($filters['city'] && $group['city'] !== $filters['city']) {
                return false;
            }
            return true;
        }));
    }

    private function groupCoursesBySeasonDay(array $groups): array {
        usort($groups, function ($a, $b) {
            return strcmp($a['season'], $b['season'])
                ?: strcmp($a['course_day'], $b['course_day'])
                ?: strcmp($a['venue'], $b['venue'])
                ?: strcmp($a['age_group'], $b['age_group']);
        });

        $grouped = [];
        foreach ($groups as $group) {
            $season = $group['season'] ?: 'N/A';
            $day = $group['course_day'] ?: 'N/A';
            $grouped[$season][$day][] = $group;
        }

        if (!empty($grouped)) {
            krsort($grouped, SORT_NATURAL);
            foreach ($grouped as &$days) {
                ksort($days, SORT_NATURAL);
            }
        }

        return $grouped;
    }

    /**
     * Aggregate roster data for Girls Only listings (camps & courses).
     */
    public function getGirlsOnlyListings(array $filters = [], array $context = []): array {
        $filters = $this->normaliseGirlsFilters($filters);
        $context = $this->normaliseContext($context);

        if ($context['is_coach'] && empty($context['accessible_venues'])) {
            return $this->emptyGirlsResponse();
        }

        $criteria = [
            'girls_only' => 1,
            'is_placeholder' => 0,
        ];

        if (!empty($context['accessible_venues'])) {
            $criteria['venue'] = $context['accessible_venues'];
        }

        $start_time = microtime(true);
        $collection = $this->repository->where($criteria);
        $rosters = $this->prepareRosterArray($collection);
        $query_time = microtime(true) - $start_time;

        if (empty($rosters)) {
            return $this->emptyGirlsResponse($query_time);
        }

        $aggregation = $this->aggregateGirlsGroups($rosters);
        $all_groups = array_merge($aggregation['camps'], $aggregation['courses']);
        $filter_sets = $aggregation['filters'];

        $display_groups = $this->applyGirlsFilters($all_groups, $filters);
        $grouped = $this->groupGirlsBySeasonType($display_groups);

        return [
            'query_time' => $query_time,
            'all_groups' => $all_groups,
            'display_groups' => $display_groups,
            'grouped_camps' => $grouped['camps'],
            'grouped_courses' => $grouped['courses'],
            'filters' => [
                'seasons' => $filter_sets['seasons'],
                'venues' => $filter_sets['venues'],
                'camp_terms' => $filter_sets['camp_terms'],
                'course_days' => $filter_sets['course_days'],
                'age_groups' => $filter_sets['age_groups'],
                'cities' => $filter_sets['cities'],
            ],
        ];
    }

    /**
     * Aggregate roster data for Tournament listings.
     */
    public function getTournamentListings(array $filters = [], array $context = []): array {
        $filters = $this->normaliseTournamentFilters($filters);
        $context = $this->normaliseContext($context);

        if ($context['is_coach'] && empty($context['accessible_venues'])) {
            return $this->emptyTournamentResponse();
        }

        $criteria = [
            'activity_type' => ['Tournament', 'Tournament, Girls Only', "Tournament, Girls' only"],
            'is_placeholder' => 0,
        ];

        if (!empty($context['accessible_venues'])) {
            $criteria['venue'] = $context['accessible_venues'];
        }

        $start_time = microtime(true);
        $collection = $this->repository->where($criteria);
        $rosters = $this->prepareRosterArray($collection);
        $query_time = microtime(true) - $start_time;

        if (empty($rosters)) {
            return $this->emptyTournamentResponse($query_time);
        }

        $aggregation = $this->aggregateTournamentGroups($rosters);
        $all_groups = array_values($aggregation['groups']);
        $filter_sets = $aggregation['filters'];

        $display_groups = $this->applyTournamentFilters($all_groups, $filters);
        $grouped = $this->groupTournamentsBySeason($display_groups);

        return [
            'query_time' => $query_time,
            'all_groups' => $all_groups,
            'display_groups' => $display_groups,
            'grouped' => $grouped,
            'filters' => [
                'seasons' => $filter_sets['seasons'],
                'venues' => $filter_sets['venues'],
                'age_groups' => $filter_sets['age_groups'],
                'cities' => $filter_sets['cities'],
                'times' => $filter_sets['times'],
            ],
        ];
    }

    private function normaliseGirlsFilters(array $filters): array {
        return [
            'season' => isset($filters['season']) ? sanitize_text_field($filters['season']) : '',
            'venue' => isset($filters['venue']) ? sanitize_text_field($filters['venue']) : '',
            'camp_terms' => isset($filters['camp_terms']) ? sanitize_text_field($filters['camp_terms']) : '',
            'course_day' => isset($filters['course_day']) ? sanitize_text_field($filters['course_day']) : '',
            'age_group' => isset($filters['age_group']) ? sanitize_text_field($filters['age_group']) : '',
            'city' => isset($filters['city']) ? sanitize_text_field($filters['city']) : '',
        ];
    }

    private function emptyGirlsResponse(float $query_time = 0.0): array {
        return [
            'query_time' => $query_time,
            'all_groups' => [],
            'display_groups' => [],
            'grouped_camps' => [],
            'grouped_courses' => [],
            'filters' => [
                'seasons' => [],
                'venues' => [],
                'camp_terms' => [],
                'course_days' => [],
                'age_groups' => [],
                'cities' => [],
            ],
        ];
    }

    private function aggregateGirlsGroups(array $rosters): array {
        $camps = [];
        $courses = [];
        $filters = [
            'seasons' => [],
            'venues' => [],
            'camp_terms' => [],
            'course_days' => [],
            'age_groups' => [],
            'cities' => [],
        ];

        foreach ($rosters as $row) {
            $isCamp = ($row['activity_type'] ?? '') === Roster::ACTIVITY_CAMP || stripos((string)$row['activity_type'], 'camp') !== false;
            $signature = $row['event_signature'];
            if (empty($signature)) {
                $signature = md5(($row['product_id'] ?? 0) . '|' . ($row['activity_type'] ?? '') . '|' . ($row['venue'] ?? '') . '|' . ($row['times'] ?? ''));
            }

            if ($isCamp) {
                if (!isset($camps[$signature])) {
                    $season_raw = $this->deriveSeasonFromCamp($row);
                    $camps[$signature] = $this->initialGirlsGroup($row, $season_raw, 'camp_terms');
                }
                $group =& $camps[$signature];
            } else {
                if (!isset($courses[$signature])) {
                    $season_raw = $row['season'] ?? 'N/A';
                    $courses[$signature] = $this->initialGirlsGroup($row, $season_raw, 'course_day');
                    $courses[$signature]['course_day'] = $row['course_day'] ?? 'N/A';
                }
                $group =& $courses[$signature];
            }

            if (!empty($row['variation_id'])) {
                $group['variation_ids'][$row['variation_id']] = $row['variation_id'];
            }
            if (!empty($row['order_item_id'])) {
                $group['order_item_ids'][$row['order_item_id']] = true;
            }
            if (!empty($row['start_date'])) {
                $group['start_dates'][] = $row['start_date'];
            }
            if (!empty($row['end_date'])) {
                $group['end_dates'][] = $row['end_date'];
            }
            if (empty($group['product_name']) || $group['product_name'] === 'N/A') {
                $group['product_name'] = $row['product_name'] ?? 'N/A';
            }
        }

        $camps_list = $this->finaliseGirlsGroups($camps, $filters, true);
        $courses_list = $this->finaliseGirlsGroups($courses, $filters, false);

        return [
            'camps' => $camps_list,
            'courses' => $courses_list,
            'filters' => $filters,
        ];
    }

    private function initialGirlsGroup(array $row, string $season_raw, string $term_key): array {
        $season_display = \intersoccer_normalize_season_for_display($season_raw);

        return [
            'event_signature' => $row['event_signature'] ?: md5(json_encode($row)),
            'season' => $season_display,
            'season_raw' => $season_raw,
            'venue' => $row['venue'] ?? 'N/A',
            'city' => $row['city'] ?? 'N/A',
            'age_group' => $row['age_group'] ?? 'N/A',
            'times' => $row['times'] ?? 'N/A',
            'product_name' => $row['product_name'] ?? 'N/A',
            'variation_ids' => [],
            'order_item_ids' => [],
            'start_dates' => [],
            'end_dates' => [],
            'camp_terms' => $term_key === 'camp_terms' ? ($row['camp_terms'] ?? 'N/A') : 'N/A',
            'course_day' => $term_key === 'course_day' ? ($row['course_day'] ?? 'N/A') : 'N/A',
            'total_players' => 0,
        ];
    }

    private function finaliseGirlsGroups(array $groups, array &$filters, bool $isCamp): array {
        foreach ($groups as &$group) {
            $group['variation_ids'] = array_values($group['variation_ids']);
            $group['order_item_ids'] = array_keys($group['order_item_ids']);
            $group['total_players'] = count($group['order_item_ids']);

            $group['corrected_start_date'] = $this->getEarliestDate($group['start_dates']);
            $group['corrected_end_date'] = $this->getLatestDate($group['end_dates']);

            $filters['seasons'][$group['season']] = $group['season'];
            $filters['venues'][$group['venue']] = $group['venue'];
            if (!empty($group['age_group']) && $group['age_group'] !== 'N/A') {
                $filters['age_groups'][$group['age_group']] = $group['age_group'];
            }
            if (!empty($group['city']) && $group['city'] !== 'N/A') {
                $filters['cities'][$group['city']] = $group['city'];
            }

            if ($isCamp) {
                if (!empty($group['camp_terms']) && $group['camp_terms'] !== 'N/A') {
                    $filters['camp_terms'][$group['camp_terms']] = $group['camp_terms'];
                }
            } else {
                if (!empty($group['course_day']) && $group['course_day'] !== 'N/A') {
                    $filters['course_days'][$group['course_day']] = $group['course_day'];
                }
            }

            unset($group['start_dates'], $group['end_dates']);
        }

        return array_values($groups);
    }

    private function applyGirlsFilters(array $groups, array $filters): array {
        return array_values(array_filter($groups, function ($group) use ($filters) {
            if ($filters['season'] && $group['season'] !== $filters['season'] && $group['season_raw'] !== $filters['season']) {
                return false;
            }
            if ($filters['venue'] && $group['venue'] !== $filters['venue']) {
                return false;
            }
            if ($filters['camp_terms'] && (!isset($group['camp_terms']) || $group['camp_terms'] !== $filters['camp_terms'])) {
                return false;
            }
            if ($filters['course_day'] && (!isset($group['course_day']) || $group['course_day'] !== $filters['course_day'])) {
                return false;
            }
            if ($filters['age_group'] && $group['age_group'] !== $filters['age_group']) {
                return false;
            }
            if ($filters['city'] && $group['city'] !== $filters['city']) {
                return false;
            }
            return true;
        }));
    }

    private function groupGirlsBySeasonType(array $groups): array {
        $buckets = [
            'camps' => [],
            'courses' => [],
        ];

        foreach ($groups as $group) {
            $season = $group['season'] ?: 'N/A';
            $isCamp = !empty($group['camp_terms']) && $group['camp_terms'] !== 'N/A';
            $bucketKey = $isCamp ? 'camps' : 'courses';

            if (!isset($buckets[$bucketKey][$season])) {
                $buckets[$bucketKey][$season] = [];
            }

            $buckets[$bucketKey][$season][] = $group;
        }

        foreach ($buckets as $type => $seasons) {
            if (empty($seasons)) {
                $buckets[$type] = [];
                continue;
            }

            krsort($seasons, SORT_NATURAL);

            foreach ($seasons as &$items) {
                usort($items, function ($a, $b) {
                    return strcmp($a['venue'], $b['venue'])
                        ?: strcmp($a['age_group'], $b['age_group']);
                });
            }
            unset($items);

            $buckets[$type] = $seasons;
        }

        return $buckets;
    }

    private function normaliseTournamentFilters(array $filters): array {
        return [
            'season' => isset($filters['season']) ? sanitize_text_field($filters['season']) : '',
            'venue' => isset($filters['venue']) ? sanitize_text_field($filters['venue']) : '',
            'age_group' => isset($filters['age_group']) ? sanitize_text_field($filters['age_group']) : '',
            'city' => isset($filters['city']) ? sanitize_text_field($filters['city']) : '',
            'times' => isset($filters['times']) ? sanitize_text_field($filters['times']) : '',
        ];
    }

    private function emptyTournamentResponse(float $query_time = 0.0): array {
        return [
            'query_time' => $query_time,
            'all_groups' => [],
            'display_groups' => [],
            'grouped' => [],
            'filters' => [
                'seasons' => [],
                'venues' => [],
                'age_groups' => [],
                'cities' => [],
                'times' => [],
            ],
        ];
    }

    private function aggregateTournamentGroups(array $rosters): array {
        $groups = [];
        $filters = [
            'seasons' => [],
            'venues' => [],
            'age_groups' => [],
            'cities' => [],
            'times' => [],
        ];

        foreach ($rosters as $row) {
            $season_raw = isset($row['season']) && $row['season'] !== '' ? $row['season'] : 'N/A';
            $season_display = \intersoccer_normalize_season_for_display($season_raw);
            $venue = isset($row['venue']) && $row['venue'] !== '' ? $row['venue'] : 'N/A';
            $city = isset($row['city']) && $row['city'] !== '' ? $row['city'] : 'N/A';
            $age_group = isset($row['age_group']) && $row['age_group'] !== '' ? $row['age_group'] : 'N/A';
            $times = isset($row['times']) && $row['times'] !== '' ? $row['times'] : 'N/A';
            $product_name = isset($row['product_name']) && $row['product_name'] !== '' ? $row['product_name'] : __('Tournament', 'intersoccer-reports-rosters');

            $product_id = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            if (function_exists('intersoccer_get_english_product_name')) {
                $product_name = intersoccer_get_english_product_name($product_name, $product_id);
            }

            $signature = !empty($row['event_signature'])
                ? $row['event_signature']
                : md5($product_name . '|' . $venue . '|' . $times . '|' . ($row['start_date'] ?? ''));

            if (!isset($groups[$signature])) {
                $groups[$signature] = [
                    'event_signature' => !empty($row['event_signature']) ? $row['event_signature'] : $signature,
                    'season' => $season_display,
                    'season_raw' => $season_raw,
                    'venue' => $venue,
                    'city' => $city,
                    'age_group' => $age_group,
                    'times' => $times,
                    'product_name' => $product_name,
                    'variation_ids' => [],
                    'order_item_ids' => [],
                    'start_dates' => [],
                    'end_dates' => [],
                    'total_players' => 0,
                ];
            }

            $group =& $groups[$signature];
            if (!empty($row['variation_id'])) {
                $group['variation_ids'][$row['variation_id']] = $row['variation_id'];
            }
            if (!empty($row['order_item_id'])) {
                $group['order_item_ids'][$row['order_item_id']] = true;
            }
            if (!empty($row['start_date']) && $row['start_date'] !== '1970-01-01') {
                $group['start_dates'][] = $row['start_date'];
            }
            if (!empty($row['end_date']) && $row['end_date'] !== '1970-01-01') {
                $group['end_dates'][] = $row['end_date'];
            }

            if ($season_display !== 'N/A') {
                $filters['seasons'][$season_display] = $season_display;
            }
            if ($venue !== 'N/A') {
                $filters['venues'][$venue] = $venue;
            }
            if ($age_group !== 'N/A') {
                $filters['age_groups'][$age_group] = $age_group;
            }
            if ($city !== 'N/A') {
                $filters['cities'][$city] = $city;
            }
            if ($times !== 'N/A') {
                $filters['times'][$times] = $times;
            }
        }

        foreach ($groups as &$group) {
            $group['variation_ids'] = array_values($group['variation_ids']);
            $group['order_item_ids'] = array_keys($group['order_item_ids']);
            $group['total_players'] = count($group['order_item_ids']);
            $group['corrected_start_date'] = $this->getEarliestDate($group['start_dates']);
            $group['corrected_end_date'] = $this->getLatestDate($group['end_dates']);
            unset($group['start_dates'], $group['end_dates']);
        }
        unset($group);

        foreach ($filters as $key => $values) {
            $filters[$key] = array_values(array_unique($values));
            sort($filters[$key], SORT_NATURAL | SORT_FLAG_CASE);
        }

        return [
            'groups' => $groups,
            'filters' => $filters,
        ];
    }

    private function applyTournamentFilters(array $groups, array $filters): array {
        return array_values(array_filter($groups, function ($group) use ($filters) {
            if ($filters['season'] && $group['season'] !== $filters['season'] && ($group['season_raw'] ?? '') !== $filters['season']) {
                return false;
            }
            if ($filters['venue'] && $group['venue'] !== $filters['venue']) {
                return false;
            }
            if ($filters['age_group'] && $group['age_group'] !== $filters['age_group']) {
                return false;
            }
            if ($filters['city'] && $group['city'] !== $filters['city']) {
                return false;
            }
            if ($filters['times'] && $group['times'] !== $filters['times']) {
                return false;
            }
            return true;
        }));
    }

    private function groupTournamentsBySeason(array $groups): array {
        usort($groups, function ($a, $b) {
            return strcmp($a['venue'], $b['venue'])
                ?: strcmp($a['age_group'], $b['age_group'])
                ?: strcmp($a['times'], $b['times']);
        });

        $grouped = [];
        foreach ($groups as $group) {
            $season = $group['season'] ?: 'N/A';
            if (!isset($grouped[$season])) {
                $grouped[$season] = [];
            }
            $grouped[$season][] = $group;
        }

        if (!empty($grouped)) {
            krsort($grouped, SORT_NATURAL);
        }

        return $grouped;
    }

    private function aggregateCampGroups(array $rosters, bool $girls_only): array {
        $groups = [];

        $filters = [
            'seasons' => [],
            'venues' => [],
            'camp_terms' => [],
            'age_groups' => [],
            'cities' => [],
        ];

        foreach ($rosters as $row) {
            $signature = $row['event_signature'];
            if (empty($signature)) {
                $signature = md5(($row['product_id'] ?? 0) . '|' . ($row['start_date'] ?? '') . '|' . ($row['venue'] ?? '') . '|' . ($row['camp_terms'] ?? ''));
            }

            if (!isset($groups[$signature])) {
                $season_raw = $this->deriveSeasonFromCamp($row);
                $season_display = \intersoccer_normalize_season_for_display($season_raw);

                $groups[$signature] = [
                    'event_signature' => $row['event_signature'] ?: $signature,
                    'camp_terms' => $row['camp_terms'] ?? 'N/A',
                    'venue' => $row['venue'] ?? 'N/A',
                    'city' => $row['city'] ?? 'N/A',
                    'age_group' => $row['age_group'] ?? 'N/A',
                    'times' => $row['times'] ?? 'N/A',
                    'product_name' => $row['product_name'] ?? 'N/A',
                    'girls_only' => $girls_only ? 1 : 0,
                    'season' => $season_display,
                    'season_raw' => $season_raw,
                    'event_completed' => !empty($row['event_completed']) ? 1 : 0,
                    'variation_ids' => [],
                    'order_item_ids' => [],
                    'start_dates' => [],
                    'end_dates' => [],
                ];
            }

            $group =& $groups[$signature];
            $group['event_completed'] = max((int) ($group['event_completed'] ?? 0), !empty($row['event_completed']) ? 1 : 0);

            if (!empty($row['variation_id'])) {
                $group['variation_ids'][$row['variation_id']] = $row['variation_id'];
            }

            if (!empty($row['order_item_id'])) {
                $group['order_item_ids'][$row['order_item_id']] = true;
            }

            if (!empty($row['start_date'])) {
                $group['start_dates'][] = $row['start_date'];
            }

            if (!empty($row['end_date'])) {
                $group['end_dates'][] = $row['end_date'];
            }

            if (empty($group['product_name']) || $group['product_name'] === 'N/A') {
                $group['product_name'] = $row['product_name'] ?? 'N/A';
            }
        }

        foreach ($groups as &$group) {
            $group['variation_ids'] = array_values($group['variation_ids']);
            $group['order_item_ids'] = array_keys($group['order_item_ids']);
            $group['total_players'] = count($group['order_item_ids']);

            $group['corrected_start_date'] = $this->getEarliestDate($group['start_dates']);
            $group['corrected_end_date'] = $this->getLatestDate($group['end_dates']);

            $filters['seasons'][$group['season']] = $group['season'];
            $filters['venues'][$group['venue']] = $group['venue'];
            if (!empty($group['camp_terms']) && $group['camp_terms'] !== 'N/A') {
                $filters['camp_terms'][$group['camp_terms']] = $group['camp_terms'];
            }
            if (!empty($group['age_group']) && $group['age_group'] !== 'N/A') {
                $filters['age_groups'][$group['age_group']] = $group['age_group'];
            }
            if (!empty($group['city']) && $group['city'] !== 'N/A') {
                $filters['cities'][$group['city']] = $group['city'];
            }

            unset($group['start_dates'], $group['end_dates']);
        }

        foreach ($filters as $key => $values) {
            $filters[$key] = array_values($values);
            sort($filters[$key], SORT_NATURAL | SORT_FLAG_CASE);
        }

        return [
            'groups' => $groups,
            'filters' => $filters,
        ];
    }

    private function deriveSeasonFromCamp(array $row): string {
        if (!empty($row['season']) && $row['season'] !== 'N/A') {
            return $row['season'];
        }

        $camp_terms = $row['camp_terms'] ?? '';
        if (empty($camp_terms) || $camp_terms === 'N/A') {
            return 'N/A';
        }

        $parts = explode('-', $camp_terms);
        return !empty($parts[0]) ? ucfirst(trim($parts[0])) : 'N/A';
    }

    private function applyCampFilters(array $groups, array $filters): array {
        return array_values(array_filter($groups, function ($group) use ($filters) {
            if ($filters['season'] && $group['season'] !== $filters['season'] && $group['season_raw'] !== $filters['season']) {
                return false;
            }
            if ($filters['venue'] && $group['venue'] !== $filters['venue']) {
                return false;
            }
            if ($filters['camp_terms'] && $group['camp_terms'] !== $filters['camp_terms']) {
                return false;
            }
            if ($filters['age_group'] && $group['age_group'] !== $filters['age_group']) {
                return false;
            }
            if ($filters['city'] && $group['city'] !== $filters['city']) {
                return false;
            }
            return true;
        }));
    }

    private function groupCampsBySeason(array $groups): array {
        usort($groups, function ($a, $b) {
            return strcmp($a['camp_terms'], $b['camp_terms'])
                ?: strcmp($a['venue'], $b['venue'])
                ?: strcmp($a['age_group'], $b['age_group']);
        });

        $grouped = [];
        foreach ($groups as $group) {
            $season = $group['season'] ?: 'N/A';
            $grouped[$season][] = $group;
        }

        if (!empty($grouped)) {
            krsort($grouped, SORT_NATURAL);
        }

        return $grouped;
    }

    private function getEarliestDate(array $dates): string {
        $dates = array_filter(array_map('strtotime', $dates));
        if (empty($dates)) {
            return '1970-01-01';
        }
        return date('Y-m-d', min($dates));
    }

    private function getLatestDate(array $dates): string {
        $dates = array_filter(array_map('strtotime', $dates));
        if (empty($dates)) {
            return '1970-01-01';
        }
        return date('Y-m-d', max($dates));
    }
}


