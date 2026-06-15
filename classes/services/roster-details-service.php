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

        $hasEventSignature = !empty($filters['event_signatures'])
            || (!empty($filters['event_signature']) && $filters['event_signature'] !== 'N/A');
        if ($hasEventSignature && empty($filters['order_item_ids']) && empty($criteria['event_signature'])) {
            if (!empty($filters['event_signatures'])) {
                $criteria['event_signature'] = $filters['event_signatures'];
            } else {
                $criteria['event_signature'] = $filters['event_signature'];
            }
        }

        $this->repository->clearQueryCache();
        $collection = $this->repository->where($criteria, ['skip_cache' => true]);
        $allow_missing_status = !empty($filters['order_item_ids']) || $hasEventSignature;
        $rosterModels = $this->filterByValidOrderStatus($collection, $allow_missing_status);
        $loadCriteria = $criteria;
        $supplementMerged = false;

        if (!empty($rosterModels)) {
            $primaryModels = $rosterModels;
            $supplement = $this->fetchRosterModelsInConsolidatedGroup($filters, $context, $primaryModels);
            if (!empty($supplement)) {
                $supplementMerged = true;
                $this->logger->debug('RosterDetailsService: Merged roster rows in same consolidated event group', [
                    'added' => count($supplement),
                ]);
                // Season filter applies to listing-primary rows only; facet-scoped supplement rows
                // may use a different persisted season label (e.g. Summer-courses-2026 vs Spring/Summer 2026).
                if (!empty($filters['season'])) {
                    $primaryModels = $this->filterModelsByListingSeason($primaryModels, $filters['season'], $context);
                    $rosterModels = array_merge($primaryModels, $supplement);
                } else {
                    $rosterModels = array_merge($primaryModels, $supplement);
                }
            } elseif (!empty($filters['season'])) {
                $rosterModels = $this->filterModelsByListingSeason($rosterModels, $filters['season'], $context);
            }
        }

        // When event_signature returns 0: roster rows may have NULL/empty event_signature; the listing
        // displays a computed fallback (md5) but DB has empty. Use order_item_ids, variation_ids, or camp_terms+venue as fallback.
        if (empty($rosterModels) && $hasEventSignature) {
            $fallbackCriteria = [];
            if (!empty($filters['order_item_ids'])) {
                $fallbackCriteria['order_item_id'] = $filters['order_item_ids'];
            } elseif (!empty($filters['variation_ids'])) {
                $fallbackCriteria['variation_id'] = $filters['variation_ids'];
            } elseif (($context['is_from_camps_page'] || $context['is_from_girls_only_page']) && $filters['camp_terms'] && $filters['camp_terms'] !== 'N/A' && $filters['venue']) {
                $fallbackCriteria['camp_terms'] = $filters['camp_terms'];
                $fallbackCriteria['venue'] = $filters['venue'];
            } elseif (($context['is_from_courses_page'] || $context['is_from_girls_only_page']) && $filters['course_day'] && $filters['course_day'] !== 'N/A' && $filters['venue']) {
                $fallbackCriteria['course_day'] = $filters['course_day'];
                $fallbackCriteria['venue'] = $filters['venue'];
                if ($filters['age_group'] && $filters['age_group'] !== 'N/A') {
                    $fallbackCriteria['age_group'] = $filters['age_group'];
                }
                if ($filters['times'] && $filters['times'] !== 'N/A') {
                    $fallbackCriteria['times'] = $filters['times'];
                }
            }
            if (!empty($fallbackCriteria)) {
                if (empty($fallbackCriteria['order_item_id'])) {
                    if ($context['is_from_camps_page'] || ($context['is_from_girls_only_page'] && !empty($fallbackCriteria['camp_terms']))) {
                        $fallbackCriteria['activity_type'] = ['Camp', 'Camp, Girls Only', "Camp, Girls' only"];
                        $fallbackCriteria['girls_only'] = $context['is_from_girls_only_page'] || $filters['girls_only'] ? 1 : 0;
                    } elseif ($context['is_from_courses_page'] || ($context['is_from_girls_only_page'] && !empty($fallbackCriteria['course_day']))) {
                        $fallbackCriteria['activity_type'] = ['Course', 'Course, Girls Only', "Course, Girls' only"];
                        $fallbackCriteria['girls_only'] = $context['is_from_girls_only_page'] || $filters['girls_only'] ? 1 : 0;
                    } elseif ($context['is_from_girls_only_page'] || $filters['girls_only']) {
                        $fallbackCriteria['girls_only'] = 1;
                    }
                }
                $this->logger->debug('RosterDetailsService: Fallback (event_signature had no match)', $fallbackCriteria);
                $collection = $this->repository->where($fallbackCriteria, ['skip_cache' => true]);
                $rosterModels = $this->filterByValidOrderStatus($collection);
                if (!empty($filters['season'])) {
                    $rosterModels = $this->filterModelsByListingSeason($rosterModels, $filters['season'], $context);
                }
                $loadCriteria = $fallbackCriteria;
                $filteredOrderItemIds = $this->extractOrderItemIdsFromModels($rosterModels);
                if (!empty($filteredOrderItemIds)) {
                    $loadCriteria = ['order_item_id' => $filteredOrderItemIds];
                }
            }
        }

        if (empty($rosterModels) && !empty($filters['order_item_ids']) && function_exists('intersoccer_attempt_roster_build_for_order_item_ids')) {
            intersoccer_attempt_roster_build_for_order_item_ids($filters['order_item_ids']);
            $this->repository->clearQueryCache();
            $retry_criteria = ['order_item_id' => $filters['order_item_ids']];
            $collection = $this->repository->where($retry_criteria, ['skip_cache' => true]);
            $rosterModels = $this->filterByValidOrderStatus($collection, $allow_missing_status);
            $loadCriteria = $retry_criteria;
        }

        if (empty($rosterModels)) {
            return [
                'success' => false,
                'error' => __('No rosters found for the provided parameters.', 'intersoccer-reports-rosters'),
            ];
        }

        $resolvedOrderItemIds = $this->extractOrderItemIdsFromModels($rosterModels);
        if (!empty($resolvedOrderItemIds)) {
            $loadCriteria = ['order_item_id' => $resolvedOrderItemIds];
        } elseif ($supplementMerged || count($rosterModels) > 1) {
            $rosterIds = $this->extractRosterIdsFromModels($rosterModels);
            if (!empty($rosterIds)) {
                $loadCriteria = ['id' => $rosterIds];
            }
        }

        $this->repairPlayerNamesOnModels($rosterModels);

        // Reload from DB after repairs/rebuilds (use same criteria as initial load, including fallback).
        $this->repository->clearQueryCache();
        $collection = $this->repository->where($loadCriteria, ['skip_cache' => true]);
        $rosterModels = $this->filterByValidOrderStatus($collection, $allow_missing_status);

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
        $filters['variation_ids'] = array_values(array_unique($filters['variation_ids']));
        sort($filters['variation_ids'], SORT_NUMERIC);
        $filters['order_item_ids'] = isset($filters['order_item_ids']) ? array_filter(array_map('intval', (array) $filters['order_item_ids'])) : [];
        $filters['order_item_ids'] = array_values(array_unique($filters['order_item_ids']));
        sort($filters['order_item_ids'], SORT_NUMERIC);
        $filters['event_signature'] = isset($filters['event_signature']) ? trim($filters['event_signature']) : '';
        $filters['event_signatures'] = isset($filters['event_signatures'])
            ? array_values(array_filter(array_map('trim', (array) $filters['event_signatures'])))
            : [];
        $filters['event_signatures'] = array_values(array_filter($filters['event_signatures'], function ($sig) {
            return strcasecmp((string) $sig, 'N/A') !== 0;
        }));
        $filters['event_signatures'] = array_values(array_unique($filters['event_signatures']));
        sort($filters['event_signatures'], SORT_STRING);
        if (strcasecmp($filters['event_signature'], 'N/A') === 0) {
            $filters['event_signature'] = '';
        }
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
            $sortOrder = 'desc';
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

        $has_event_signature_filter = !$ignoreEventSignature && (
            !empty($filters['event_signatures'])
            || ($filters['event_signature'] !== '' && $filters['event_signature'] !== 'N/A')
        );

        if (!empty($filters['order_item_ids'])) {
            $criteria['order_item_id'] = $filters['order_item_ids'];
        } elseif ($has_event_signature_filter && !empty($filters['event_signatures'])) {
            $criteria['event_signature'] = $filters['event_signatures'];
        } elseif ($has_event_signature_filter) {
            $criteria['event_signature'] = $filters['event_signature'];
        }

        // When order_item_ids or event_signature is present, they uniquely identify the roster group - do NOT add
        // activity_type or girls_only, as those can exclude valid rows if DB values differ.
        $useOrderItemIdsOnly = !empty($criteria['order_item_id']);
        $useEventSignatureOnly = !empty($criteria['event_signature']);
        if (!$useEventSignatureOnly && !$useOrderItemIdsOnly) {
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
        }

        $useAdditionalFilters = ($ignoreEventSignature || empty($criteria['event_signature'])) && empty($criteria['order_item_id']);

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

        if ($filters['season'] && !$has_event_signature_filter && empty($criteria['order_item_id'])) {
            $criteria['season'] = $filters['season'];
        }

        return $criteria;
    }

    /**
     * Narrow fallback roster models to the listing season (display label may differ from DB season).
     *
     * @param array<int,\InterSoccer\ReportsRosters\Data\Models\Roster> $models
     * @return array<int,\InterSoccer\ReportsRosters\Data\Models\Roster>
     */
    private function filterModelsByListingSeason(array $models, string $filterSeason, array $context): array {
        if ($filterSeason === '' || empty($models)) {
            return $models;
        }

        $is_course_context = $context['is_from_courses_page']
            || ($context['is_from_girls_only_page'] && function_exists('intersoccer_roster_course_season_filter_matches'));

        if ($is_course_context && function_exists('intersoccer_roster_course_season_filter_matches')) {
            return array_values(array_filter($models, function ($model) use ($filterSeason) {
                if (!is_object($model) || !method_exists($model, 'getAttribute')) {
                    return false;
                }
                $group = [
                    'season' => (string) $model->getAttribute('season'),
                    'season_raw' => (string) $model->getAttribute('season'),
                    'product_name' => (string) $model->getAttribute('product_name'),
                    'course_day' => (string) $model->getAttribute('course_day'),
                ];
                return intersoccer_roster_course_season_filter_matches($group, $filterSeason);
            }));
        }

        return array_values(array_filter($models, function ($model) use ($filterSeason) {
            if (!is_object($model) || !method_exists($model, 'getAttribute')) {
                return false;
            }
            $season = trim((string) $model->getAttribute('season'));
            return strcasecmp($season, $filterSeason) === 0;
        }));
    }

    /**
     * @param array<int,\InterSoccer\ReportsRosters\Data\Models\Roster> $models
     * @return array<int,int>
     */
    private function extractOrderItemIdsFromModels(array $models): array {
        $ids = [];
        foreach ($models as $model) {
            if (!is_object($model)) {
                continue;
            }
            $oid = 0;
            if (method_exists($model, 'getAttribute')) {
                $oid = (int) $model->getAttribute('order_item_id');
            }
            if ($oid <= 0 && isset($model->order_item_id)) {
                $oid = (int) $model->order_item_id;
            }
            if ($oid > 0) {
                $ids[$oid] = $oid;
            }
        }
        return array_values($ids);
    }

    /**
     * @param array<int,\InterSoccer\ReportsRosters\Data\Models\Roster> $models
     * @return array<int,int>
     */
    private function extractRosterIdsFromModels(array $models): array {
        $ids = [];
        foreach ($models as $model) {
            if (!is_object($model)) {
                continue;
            }
            $rid = 0;
            if (method_exists($model, 'getAttribute')) {
                $rid = (int) $model->getAttribute('id');
            }
            if ($rid <= 0 && isset($model->id)) {
                $rid = (int) $model->id;
            }
            if ($rid > 0) {
                $ids[$rid] = $rid;
            }
        }
        return array_values($ids);
    }

    /**
     * Keep only roster models in the same consolidated listing group as the anchor row.
     *
     * @param array<int,\InterSoccer\ReportsRosters\Data\Models\Roster> $models
     * @return array<int,\InterSoccer\ReportsRosters\Data\Models\Roster>
     */
    private function filterModelsByConsolidatedGroupKey(array $models, array $context): array {
        if (count($models) <= 1 || !function_exists('intersoccer_consolidated_roster_group_key')) {
            return $models;
        }

        $anchor = $models[0];
        if (!is_object($anchor) || !method_exists($anchor, 'toArray')) {
            return $models;
        }

        $kind = ($context['is_from_courses_page'] || $context['is_from_girls_only_page']) ? 'course' : 'camp';
        $anchorKey = intersoccer_consolidated_roster_group_key($anchor->toArray(), $kind);
        if ($anchorKey === '') {
            return [$anchor];
        }

        return array_values(array_filter($models, function ($model) use ($anchorKey, $kind) {
            if (!is_object($model) || !method_exists($model, 'toArray')) {
                return false;
            }
            return intersoccer_consolidated_roster_group_key($model->toArray(), $kind) === $anchorKey;
        }));
    }

    private function filterByValidOrderStatus($collection, bool $allow_missing_status = false): array {
        $filtered = [];

        foreach ($collection as $model) {
            if (!$model->order_id) {
                continue;
            }

            $status = get_post_status($model->order_id);
            if (!$status) {
                if (!$allow_missing_status) {
                    continue;
                }
            } elseif (!in_array($status, $this->allowed_statuses, true)) {
                continue;
            }

            if ((int) $model->is_placeholder === 1) {
                continue;
            }

            $filtered[] = $model;
        }

        return $filtered;
    }

    /**
     * Backfill and persist player name columns for all roster models on the details page.
     *
     * @param array<int,\InterSoccer\ReportsRosters\Data\Models\Roster> $models
     */
    private function repairPlayerNamesOnModels(array $models): void {
        if (!function_exists('intersoccer_roster_backfill_player_name_fields')
            || !function_exists('intersoccer_roster_row_names_incomplete')) {
            return;
        }

        $repaired = 0;
        $rebuilt_orders = 0;
        $order_ids_to_rebuild = [];

        foreach ($models as $model) {
            if (!is_object($model) || !method_exists($model, 'getAttribute')) {
                continue;
            }
            $id = (int) $model->getAttribute('id');
            if ($id <= 0) {
                continue;
            }
            $rows = $this->database->get_roster_entries(['id' => $id], ['limit' => 1]);
            if (empty($rows[0]) || !is_array($rows[0])) {
                continue;
            }
            $raw = $rows[0];
            if (!intersoccer_roster_row_names_incomplete($raw)) {
                continue;
            }
            $filled = intersoccer_roster_backfill_player_name_fields($raw);
            if (!intersoccer_roster_row_names_incomplete($filled)) {
                if (function_exists('intersoccer_roster_persist_player_name_fields')
                    && intersoccer_roster_persist_player_name_fields($filled)) {
                    $repaired++;
                    if (method_exists($model, 'fill')) {
                        $model->fill($filled);
                    }
                }
                continue;
            }
            $oid = (int) ($raw['order_id'] ?? 0);
            if ($oid > 0) {
                $order_ids_to_rebuild[$oid] = true;
            }
        }

        if (!empty($order_ids_to_rebuild) && function_exists('intersoccer_oop_get_roster_builder')) {
            $builder = intersoccer_oop_get_roster_builder();
            foreach (array_keys($order_ids_to_rebuild) as $order_id) {
                try {
                    $builder->buildRosterFromOrder((int) $order_id, [
                        'validate_data' => true,
                        'skip_duplicates' => false,
                        'update_existing' => true,
                    ]);
                    $rebuilt_orders++;
                } catch (\Throwable $e) {
                    $this->logger->warning('RosterDetailsService: order rebuild for names failed', [
                        'order_id' => $order_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $this->repository->clearQueryCache();
        }
    }

    private function hydrateAndSortRosters(array $models, string $sortBy, string $sortOrder): array {
        $rosters = [];

        foreach ($models as $model) {
            $data = $model->toArray();
            if (function_exists('intersoccer_roster_normalize_row_facets_for_display')) {
                $data = intersoccer_roster_normalize_row_facets_for_display($data);
            }
            if (function_exists('intersoccer_roster_persist_player_name_fields') && !empty($data['id'])) {
                intersoccer_roster_persist_player_name_fields($data);
            }
            $post = get_post($model->order_id);
            $data['order_date'] = $post ? $post->post_date : null;
            $data['girls_only'] = isset($data['girls_only']) ? (int) $data['girls_only'] : 0;
            // When age is missing or zero but DOB exists, compute age for display (e.g. from intersoccer_players)
            if (isset($data['age']) && (int) $data['age'] > 0) {
                // already set
            } else {
                $dob = isset($data['dob']) && $data['dob'] && $data['dob'] !== '1970-01-01'
                    ? $data['dob']
                    : (isset($data['player_dob']) && $data['player_dob'] && $data['player_dob'] !== '1970-01-01' ? $data['player_dob'] : null);
                if ($dob) {
                    $refDate = isset($data['start_date']) && $data['start_date'] && $data['start_date'] !== '1970-01-01'
                        ? $data['start_date']
                        : date('Y-m-d');
                    $data['age'] = $this->computeAgeFromDob($dob, $refDate);
                }
            }
            $rosters[] = (object) $data;
        }

        $this->sortRosters($rosters, $sortBy, $sortOrder);

        return array_values($rosters);
    }

    /**
     * Compute age in years from date of birth and a reference date.
     *
     * @param string $dob Date of birth (Y-m-d)
     * @param string $refDate Reference date (Y-m-d), e.g. event start or today
     * @return int Age in years
     */
    private function computeAgeFromDob(string $dob, string $refDate): int {
        try {
            $birth = new \DateTime($dob);
            $ref = new \DateTime($refDate);
            $interval = $birth->diff($ref);
            return max(0, (int) $interval->y);
        } catch (\Exception $e) {
            return 0;
        }
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

        $summaryValues = array_values(array_map(function ($summary) {
            unset($summary->_order_item_ids);
            return $summary;
        }, $summaries));
        usort($summaryValues, static function ($a, $b) {
            $productCompare = strnatcasecmp((string) ($a->product_name ?? ''), (string) ($b->product_name ?? ''));
            if ($productCompare !== 0) {
                return $productCompare;
            }
            $venueCompare = strnatcasecmp((string) ($a->venue ?? ''), (string) ($b->venue ?? ''));
            if ($venueCompare !== 0) {
                return $venueCompare;
            }
            return strnatcasecmp((string) ($a->age_group ?? ''), (string) ($b->age_group ?? ''));
        });

        $labels = array_map(function ($summary) {
            return trim(((string) ($summary->product_name ?? '')) . ' | ' . ((string) ($summary->venue ?? '')) . ' | ' . ((string) ($summary->age_group ?? '')));
        }, $summaryValues);

        return $summaryValues;
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

    /**
     * Include roster rows in the same consolidated listing group as the anchor (any event_signature, including empty).
     *
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $context
     * @param array<int,\InterSoccer\ReportsRosters\Data\Models\Roster> $loaded
     * @return array<int,\InterSoccer\ReportsRosters\Data\Models\Roster>
     */
    private function fetchRosterModelsInConsolidatedGroup(array $filters, array $context, array $loaded): array {
        $anchor = $loaded[0] ?? null;
        if (!$anchor || !is_object($anchor) || !method_exists($anchor, 'toArray')) {
            return [];
        }

        $anchorRow = $anchor->toArray();
        $kind = ($context['is_from_courses_page'] || $context['is_from_girls_only_page']) ? 'course' : 'camp';

        $venue = trim((string) ($filters['venue'] ?? $anchorRow['venue'] ?? ''));
        if ($venue === '' || strcasecmp($venue, 'N/A') === 0) {
            return [];
        }

        $criteria = ['venue' => $venue];
        if (!empty($filters['variation_ids'])) {
            $criteria['variation_id'] = $filters['variation_ids'];
        } elseif (!empty($filters['variation_id'])) {
            $criteria['variation_id'] = (int) $filters['variation_id'];
        } elseif (!empty($anchorRow['variation_id'])) {
            $criteria['variation_id'] = (int) $anchorRow['variation_id'];
        }

        if ($context['is_from_courses_page'] || $context['is_from_girls_only_page']) {
            $course_day = trim((string) ($filters['course_day'] ?? $anchorRow['course_day'] ?? ''));
            if ($course_day !== '' && strcasecmp($course_day, 'N/A') !== 0) {
                $criteria['course_day'] = $course_day;
            }
            $age_group = trim((string) ($filters['age_group'] ?? $anchorRow['age_group'] ?? ''));
            if ($age_group !== '' && strcasecmp($age_group, 'N/A') !== 0) {
                $criteria['age_group'] = $age_group;
            }
            $times = trim((string) ($filters['times'] ?? $anchorRow['times'] ?? ''));
            if ($times !== '' && strcasecmp($times, 'N/A') !== 0) {
                $criteria['times'] = $times;
            }
            $criteria['activity_type'] = ['Course', 'Course, Girls Only', "Course, Girls' only"];
        } elseif ($context['is_from_camps_page']) {
            $camp_terms = trim((string) ($filters['camp_terms'] ?? $anchorRow['camp_terms'] ?? ''));
            if ($camp_terms !== '' && strcasecmp($camp_terms, 'N/A') !== 0) {
                $criteria['camp_terms'] = $camp_terms;
            }
            $criteria['activity_type'] = ['Camp', 'Camp, Girls Only', "Camp, Girls' only"];
        }

        if (empty($criteria['variation_id']) && empty($criteria['course_day']) && empty($criteria['camp_terms'])) {
            return [];
        }

        $this->repository->clearQueryCache();
        $collection = $this->repository->where($criteria, ['skip_cache' => true]);
        $candidates = $this->filterByValidOrderStatus($collection, true);

        $loaded_ids = [];
        $loaded_order_item_ids = [];
        foreach ($loaded as $model) {
            if (!is_object($model)) {
                continue;
            }
            if (isset($model->id)) {
                $loaded_ids[(int) $model->id] = true;
            }
            $order_item_id = method_exists($model, 'getAttribute')
                ? (int) $model->getAttribute('order_item_id')
                : (int) ($model->order_item_id ?? 0);
            if ($order_item_id > 0) {
                $loaded_order_item_ids[$order_item_id] = true;
            }
        }

        $added = [];
        foreach ($candidates as $model) {
            if (!is_object($model) || !isset($model->id)) {
                continue;
            }
            $id = (int) $model->id;
            if (isset($loaded_ids[$id])) {
                continue;
            }
            if (!method_exists($model, 'toArray')) {
                continue;
            }
            $row = $model->toArray();
            $order_item_id = (int) ($row['order_item_id'] ?? 0);
            if ($order_item_id > 0 && isset($loaded_order_item_ids[$order_item_id])) {
                continue;
            }
            $loaded_ids[$id] = true;
            if ($order_item_id > 0) {
                $loaded_order_item_ids[$order_item_id] = true;
            }
            $added[] = $model;
        }

        return $added;
    }

}


