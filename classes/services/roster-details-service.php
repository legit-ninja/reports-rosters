<?php
/**
 * Roster Details Service
 *
 * Provides roster detail datasets for admin UI using the OOP data layer.
 *
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;

defined('ABSPATH') or die('Restricted access');

class RosterDetailsService {

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var RosterRepository
     */
    private $repository;

    /**
     * @var Database
     */
    private $database;

    /**
     * @var array
     */
    private $allowed_statuses = ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'];

    /**
     * Constructor.
     */
    public function __construct(Logger $logger = null, RosterRepository $repository = null, Database $database = null) {
        $this->logger = $logger ?: new Logger();
        $this->database = $database ?: new Database($this->logger);
        $this->repository = $repository ?: new RosterRepository($this->logger, $this->database);
    }

    /**
     * Build roster detail context for rendering.
     */
    public function getRosterContext(array $filters, array $context = []) {
        $filters = $this->normaliseFilters($filters);
        $context = $this->normaliseContext($context);

        $criteria = $this->buildCriteria($filters, $context, false);
        $this->logger->debug('RosterDetailsService: Primary criteria', $criteria);

        $collection = $this->repository->where($criteria);
        $rosterModels = $this->filterByValidOrderStatus($collection);

        // Fallback when event signature present but no records found (mimics legacy LIKE behaviour)
        if (empty($rosterModels) && !empty($filters['event_signature']) && $filters['event_signature'] !== 'N/A') {
            $fallbackCriteria = $this->buildCriteria($filters, $context, true);
            $this->logger->warning('RosterDetailsService: Fallback criteria used for roster details', $fallbackCriteria);
            $collection = $this->repository->where($fallbackCriteria);
            $rosterModels = $this->filterByValidOrderStatus($collection);
        }

        if (empty($rosterModels)) {
            return [
                'success' => false,
                'error' => __('No rosters found for the provided parameters.', 'intersoccer-reports-rosters'),
            ];
        }

        $rosters = $this->hydrateAndSortRosters($rosterModels, $context['sort_by'], $context['sort_order']);
        $baseRoster = $rosters[0] ?? null;

        if (!$baseRoster) {
            return [
                'success' => false,
                'error' => __('Unable to determine base roster for the provided parameters.', 'intersoccer-reports-rosters'),
            ];
        }

        $availableRosters = $this->buildRosterSummaries($baseRoster, $filters['variation_id'], false);
        $crossGenderRosters = $this->buildRosterSummaries($baseRoster, $filters['variation_id'], true);
        $unknownCount = $this->countUnknownAttendees($rosters);

        return [
            'success' => true,
            'rosters' => $rosters,
            'base_roster' => $baseRoster,
            'available_rosters' => $availableRosters,
            'cross_gender_rosters' => $crossGenderRosters,
            'unknown_count' => $unknownCount,
        ];
    }

    private function normaliseFilters(array $filters): array {
        $filters['product_id'] = isset($filters['product_id']) ? (int) $filters['product_id'] : 0;
        $filters['variation_id'] = isset($filters['variation_id']) ? (int) $filters['variation_id'] : 0;
        $filters['variation_ids'] = isset($filters['variation_ids']) ? array_filter(array_map('intval', (array) $filters['variation_ids'])) : [];
        $filters['event_signature'] = isset($filters['event_signature']) ? trim($filters['event_signature']) : '';
        $filters['camp_terms'] = isset($filters['camp_terms']) ? trim($filters['camp_terms']) : '';
        $filters['course_day'] = isset($filters['course_day']) ? trim($filters['course_day']) : '';
        $filters['venue'] = isset($filters['venue']) ? trim($filters['venue']) : '';
        $filters['age_group'] = isset($filters['age_group']) ? trim($filters['age_group']) : '';
        $filters['times'] = isset($filters['times']) ? trim($filters['times']) : '';
        $filters['season'] = isset($filters['season']) ? trim($filters['season']) : '';
        $filters['girls_only'] = !empty($filters['girls_only']);

        return $filters;
    }

    private function normaliseContext(array $context): array {
        $context['is_from_camps_page'] = !empty($context['is_from_camps_page']);
        $context['is_from_courses_page'] = !empty($context['is_from_courses_page']);
        $context['is_from_girls_only_page'] = !empty($context['is_from_girls_only_page']);
        $context['is_from_tournaments_page'] = !empty($context['is_from_tournaments_page']);

        $sortBy = isset($context['sort_by']) ? $context['sort_by'] : 'order_date';
        $allowedSortFields = ['order_date', 'player_name', 'last_name', 'gender', 'age', 'age_group'];
        if (!in_array($sortBy, $allowedSortFields, true)) {
            $sortBy = 'order_date';
        }

        $sortOrder = strtolower(isset($context['sort_order']) ? $context['sort_order'] : 'desc');
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        $context['sort_by'] = $sortBy;
        $context['sort_order'] = $sortOrder;

        return $context;
    }

    private function buildCriteria(array $filters, array $context, bool $ignoreEventSignature): array {
        $criteria = [];

        if ($filters['product_id'] > 0) {
            $criteria['product_id'] = $filters['product_id'];
        }

        if ($filters['variation_id'] > 0) {
            $criteria['variation_id'] = $filters['variation_id'];
        } elseif (!empty($filters['variation_ids'])) {
            $criteria['variation_id'] = $filters['variation_ids'];
        }

        if (!$ignoreEventSignature && $filters['event_signature'] && $filters['event_signature'] !== 'N/A') {
            $criteria['event_signature'] = $filters['event_signature'];
        }

        if ($context['is_from_girls_only_page'] || $filters['girls_only']) {
            $criteria['girls_only'] = 1;
        } elseif ($context['is_from_camps_page']) {
            $criteria['activity_type'] = 'Camp';
            $criteria['girls_only'] = 0;
        } elseif ($context['is_from_courses_page']) {
            $criteria['activity_type'] = 'Course';
            $criteria['girls_only'] = 0;
        } elseif ($context['is_from_tournaments_page']) {
            $criteria['activity_type'] = 'Tournament';
            $criteria['girls_only'] = 0;
        }

        $useAdditionalFilters = $ignoreEventSignature || empty($criteria['event_signature']);

        if ($useAdditionalFilters) {
            if ($filters['camp_terms'] && $filters['camp_terms'] !== 'N/A') {
                $criteria['camp_terms'] = $filters['camp_terms'];
            }
            if ($filters['course_day'] && $filters['course_day'] !== 'N/A') {
                $criteria['course_day'] = $filters['course_day'];
            }
            if ($filters['venue']) {
                $criteria['venue'] = $filters['venue'];
            }
            if ($filters['age_group']) {
                $criteria['age_group'] = $filters['age_group'];
            }
            if ($filters['times']) {
                $criteria['times'] = $filters['times'];
            }
        }

        if ($filters['season']) {
            $criteria['season'] = $filters['season'];
        }

        return $criteria;
    }

    private function filterByValidOrderStatus($collection): array {
        $filtered = [];

        foreach ($collection as $model) {
            if (!$model->order_id) {
                continue;
            }

            $status = get_post_status($model->order_id);
            if (!$status || !in_array($status, $this->allowed_statuses, true)) {
                continue;
            }

            if ((int) $model->is_placeholder === 1) {
                continue;
            }

            $filtered[] = $model;
        }

        return $filtered;
    }

    private function hydrateAndSortRosters(array $models, string $sortBy, string $sortOrder): array {
        $rosters = [];

        foreach ($models as $model) {
            $data = $model->toArray();
            $post = get_post($model->order_id);
            $data['order_date'] = $post ? $post->post_date : null;
            $data['girls_only'] = isset($data['girls_only']) ? (int) $data['girls_only'] : 0;
            $rosters[] = (object) $data;
        }

        $this->sortRosters($rosters, $sortBy, $sortOrder);

        return array_values($rosters);
    }

    private function sortRosters(array &$rosters, string $sortBy, string $sortOrder): void {
        $fieldMap = [
            'order_date' => 'order_date',
            'player_name' => 'first_name',
            'last_name' => 'last_name',
            'gender' => 'gender',
            'age' => 'age',
            'age_group' => 'age_group',
        ];

        $primaryField = $fieldMap[$sortBy] ?? 'order_date';
        $direction = $sortOrder === 'asc' ? 1 : -1;

        usort($rosters, function ($a, $b) use ($primaryField, $direction) {
            $comparison = $this->compareField($a, $b, $primaryField);

            if ($comparison === 0) {
                $comparison = $this->compareField($a, $b, 'first_name');
            }

            if ($comparison === 0) {
                $comparison = $this->compareField($a, $b, 'last_name');
            }

            return $comparison * $direction;
        });
    }

    private function compareField($a, $b, string $field): int {
        $valueA = $a->{$field} ?? null;
        $valueB = $b->{$field} ?? null;

        if ($valueA === $valueB) {
            return 0;
        }

        if ($field === 'order_date') {
            $timeA = $valueA ? strtotime($valueA) : 0;
            $timeB = $valueB ? strtotime($valueB) : 0;
            return $timeA <=> $timeB;
        }

        if ($field === 'age') {
            return (float) $valueA <=> (float) $valueB;
        }

        return strcasecmp((string) $valueA, (string) $valueB);
    }

    private function buildRosterSummaries(object $baseRoster, int $currentVariationId, bool $crossGender): array {
        $activityType = $baseRoster->activity_type ?? '';

        if (!$activityType) {
            return [];
        }

        $collection = $this->repository->where(['activity_type' => $activityType]);
        $summaries = [];

        foreach ($collection as $model) {
            if ((int) $model->is_placeholder === 1) {
                continue;
            }

            if ((int) $model->variation_id === $currentVariationId) {
                continue;
            }

            $status = get_post_status($model->order_id);
            if (!$status || !in_array($status, $this->allowed_statuses, true)) {
                continue;
            }

            $isGirlsOnly = (int) $model->girls_only === 1;
            $baseGirlsOnly = (int) $baseRoster->girls_only === 1;

            if ($crossGender) {
                if ($isGirlsOnly === $baseGirlsOnly) {
                    continue;
                }
            } else {
                if ($isGirlsOnly !== $baseGirlsOnly) {
                    continue;
                }
            }

            $keyParts = [
                (int) $model->product_id,
                (int) $model->variation_id,
                (string) $model->product_name,
                (string) $model->venue,
                (string) $model->age_group,
                (string) $model->activity_type,
                (string) $model->camp_terms,
                (string) $model->course_day,
                (string) $model->times,
                (string) $model->season,
                $isGirlsOnly ? '1' : '0',
            ];

            $key = implode('|', $keyParts);

            if (!isset($summaries[$key])) {
                $summaries[$key] = (object) [
                    'product_id' => (int) $model->product_id,
                    'variation_id' => (int) $model->variation_id,
                    'product_name' => $model->product_name,
                    'venue' => $model->venue,
                    'age_group' => $model->age_group,
                    'activity_type' => $model->activity_type,
                    'camp_terms' => $model->camp_terms,
                    'course_day' => $model->course_day,
                    'times' => $model->times,
                    'season' => $model->season,
                    'girls_only' => $isGirlsOnly ? 1 : 0,
                    'current_players' => 0,
                    '_order_item_ids' => [],
                ];
            }

            $orderItemId = $model->order_item_id;
            if ($orderItemId && !in_array($orderItemId, $summaries[$key]->_order_item_ids, true)) {
                $summaries[$key]->_order_item_ids[] = $orderItemId;
                $summaries[$key]->current_players++;
            }
        }

        return array_values(array_map(function ($summary) {
            unset($summary->_order_item_ids);
            return $summary;
        }, $summaries));
    }

    private function countUnknownAttendees(array $rosters): int {
        $count = 0;

        foreach ($rosters as $roster) {
            if (!empty($roster->player_name) && $roster->player_name === 'Unknown Attendee') {
                $count++;
            }
        }

        return $count;
    }
}


