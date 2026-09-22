<?php
/**
 * Product Type Classifier Service
 *
 * Resolves product/order-item types using the classification chain:
 * 1. Order-item `_intersoccer_canonical_activity_type`
 * 2. FacetNormalizer (Activity Type display label)
 * 3. `intersoccer_get_product_type(product_id)` from PV
 * 4. Other/Unmapped
 *
 * @package InterSoccer\ReportsRosters\Services
 * @version 1.0.0
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Campaign\FacetNormalizer;

defined('ABSPATH') or die('Restricted access');

class ProductTypeClassifierService {

    /**
     * Display label buckets for revenue reporting.
     */
    public const TYPE_COURSE = 'Course';
    public const TYPE_CAMP = 'Camp';
    public const TYPE_BIRTHDAY = 'Birthday';
    public const TYPE_TOURNAMENT = 'Tournament';
    public const TYPE_OTHER = 'Other/Unmapped';

    /**
     * All valid product type buckets for revenue reporting.
     *
     * @return string[]
     */
    public static function getValidBuckets(): array {
        return [
            self::TYPE_COURSE,
            self::TYPE_CAMP,
            self::TYPE_BIRTHDAY,
            self::TYPE_TOURNAMENT,
            self::TYPE_OTHER,
        ];
    }

    /**
     * Map internal slugs to display labels.
     *
     * @var array<string,string>
     */
    private static $slugToLabel = [
        'course' => self::TYPE_COURSE,
        'courses' => self::TYPE_COURSE,
        'cours' => self::TYPE_COURSE,
        'camp' => self::TYPE_CAMP,
        'camps' => self::TYPE_CAMP,
        'birthday' => self::TYPE_BIRTHDAY,
        'birthday party' => self::TYPE_BIRTHDAY,
        'birthday-party' => self::TYPE_BIRTHDAY,
        'anniversaire' => self::TYPE_BIRTHDAY,
        'tournament' => self::TYPE_TOURNAMENT,
        'tournaments' => self::TYPE_TOURNAMENT,
        'tournoi' => self::TYPE_TOURNAMENT,
        'tournois' => self::TYPE_TOURNAMENT,
        'turnier' => self::TYPE_TOURNAMENT,
        'turniere' => self::TYPE_TOURNAMENT,
        'event' => self::TYPE_OTHER,
        'other' => self::TYPE_OTHER,
        '' => self::TYPE_OTHER,
    ];

    /**
     * @var FacetNormalizer|null
     */
    private $facetNormalizer;

    /**
     * Constructor.
     */
    public function __construct(FacetNormalizer $facetNormalizer = null) {
        $this->facetNormalizer = $facetNormalizer;
    }

    /**
     * Classify a single order line item into a product type bucket.
     *
     * Classification chain:
     * 1. Order-item `_intersoccer_canonical_activity_type`
     * 2. FacetNormalizer (Activity Type display label)
     * 3. `intersoccer_get_product_type(product_id)` from PV
     * 4. Other/Unmapped
     *
     * @param array<string,mixed> $itemMeta Flat order item meta (meta_key => value).
     * @param int|null            $productId Product ID for fallback lookup.
     * @return string Display label (Course, Camp, Birthday, Tournament, Other/Unmapped).
     */
    public function classifyOrderItem(array $itemMeta, ?int $productId = null): string {
        // 1. Prefer canonical activity type from order-item meta
        $canonicalType = $this->extractCanonicalActivityType($itemMeta);
        if ($canonicalType !== null) {
            return $this->mapSlugToLabel($canonicalType);
        }

        // 2. Try FacetNormalizer with Activity Type display label
        $activityTypeLabel = $this->extractActivityTypeLabel($itemMeta);
        if ($activityTypeLabel !== null) {
            $normalizedType = $this->normalizeViaFacetNormalizer($activityTypeLabel);
            if ($normalizedType !== null && $normalizedType !== 'other' && strpos($normalizedType, 'unmapped:') !== 0) {
                return $this->mapSlugToLabel($normalizedType);
            }
        }

        // 3. Fallback to product-level type detection from PV
        if ($productId !== null && $productId > 0) {
            $productType = $this->getProductTypeFromPV($productId);
            if ($productType !== null) {
                return $this->mapSlugToLabel($productType);
            }
        }

        // 4. Default to Other/Unmapped
        return self::TYPE_OTHER;
    }

    /**
     * Classify multiple order line items and return aggregated counts by type.
     *
     * @param array<int,array{meta:array<string,mixed>,product_id:int|null}> $items
     * @return array<string,int> Type label => count.
     */
    public function classifyBatch(array $items): array {
        $counts = array_fill_keys(self::getValidBuckets(), 0);

        foreach ($items as $item) {
            $meta = $item['meta'] ?? [];
            $productId = $item['product_id'] ?? null;
            $type = $this->classifyOrderItem($meta, $productId);
            $counts[$type]++;
        }

        return $counts;
    }

    /**
     * Extract canonical activity type from order-item meta.
     *
     * @param array<string,mixed> $meta
     * @return string|null Lowercase slug or null.
     */
    private function extractCanonicalActivityType(array $meta): ?string {
        $key = '_intersoccer_canonical_activity_type';
        if (!array_key_exists($key, $meta)) {
            return null;
        }

        $value = $meta[$key];
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }
        $value = trim((string) $value);

        return $value !== '' ? strtolower($value) : null;
    }

    /**
     * Extract Activity Type display label from order-item meta (EN/FR/DE keys).
     *
     * @param array<string,mixed> $meta
     * @return string|null
     */
    private function extractActivityTypeLabel(array $meta): ?string {
        $candidates = [
            'Activity Type',
            'pa_activity-type',
            'attribute_pa_activity-type',
            '_intersoccer_activity_slug',
            'Type d\'activité',
            "Type d'activité",
            'Aktivitätstyp',
        ];

        foreach ($candidates as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }

            $value = $meta[$key];
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Normalize activity type label using FacetNormalizer.
     *
     * @param string $label
     * @return string|null Normalized slug or null.
     */
    private function normalizeViaFacetNormalizer(string $label): ?string {
        if ($this->facetNormalizer === null) {
            $this->facetNormalizer = new FacetNormalizer();
        }

        $normalized = $this->facetNormalizer->normalize_activity_type($label);
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Get product type from Product Variations plugin.
     *
     * @param int $productId
     * @return string|null Slug (camp, course, birthday, tournament) or null.
     */
    private function getProductTypeFromPV(int $productId): ?string {
        // Try the PV function if available
        if (function_exists('intersoccer_get_product_type')) {
            $type = intersoccer_get_product_type($productId);
            if ($type !== null && $type !== '') {
                return strtolower((string) $type);
            }
        }

        // Fallback: check product meta directly
        if (function_exists('get_post_meta')) {
            $metaType = get_post_meta($productId, '_intersoccer_product_type', true);
            if (!empty($metaType)) {
                return strtolower((string) $metaType);
            }
        }

        return null;
    }

    /**
     * Map a slug to a display label.
     *
     * @param string $slug
     * @return string Display label.
     */
    public function mapSlugToLabel(string $slug): string {
        $slug = strtolower(trim($slug));

        // Handle unmapped prefix
        if (strpos($slug, 'unmapped:') === 0) {
            return self::TYPE_OTHER;
        }

        return self::$slugToLabel[$slug] ?? self::TYPE_OTHER;
    }

    /**
     * Calculate revenue breakdown by product type from booking report data.
     *
     * @param array<int,array<string,mixed>> $reportData Line-level report rows with meta + financials.
     * @return array{
     *   by_type: array<string,array{gross:float,final:float,net:float,count:int}>,
     *   totals: array{gross:float,final:float,net:float,count:int}
     * }
     */
    public function calculateRevenueByType(array $reportData): array {
        $byType = [];
        foreach (self::getValidBuckets() as $bucket) {
            $byType[$bucket] = [
                'gross' => 0.0,
                'final' => 0.0,
                'net' => 0.0,
                'count' => 0,
            ];
        }

        $totals = [
            'gross' => 0.0,
            'final' => 0.0,
            'net' => 0.0,
            'count' => 0,
        ];

        foreach ($reportData as $row) {
            // Extract meta for classification
            $itemMeta = $row['_item_meta'] ?? [];
            $productId = isset($row['_product_id']) ? (int) $row['_product_id'] : null;

            // If no embedded meta, try to build from row keys (legacy compatibility)
            if (empty($itemMeta) && isset($row['activity_type'])) {
                $itemMeta['Activity Type'] = $row['activity_type'];
            }
            if (empty($itemMeta) && isset($row['_intersoccer_canonical_activity_type'])) {
                $itemMeta['_intersoccer_canonical_activity_type'] = $row['_intersoccer_canonical_activity_type'];
            }

            $type = $this->classifyOrderItem($itemMeta, $productId);

            // Parse financial values (may be formatted strings)
            $gross = $this->parseAmount($row['base_price'] ?? $row['gross'] ?? 0);
            $final = $this->parseAmount($row['final_price'] ?? $row['final'] ?? 0);
            $reimbursement = $this->parseAmount($row['reimbursement'] ?? $row['refund'] ?? 0);
            $net = $final - $reimbursement;

            $byType[$type]['gross'] += $gross;
            $byType[$type]['final'] += $final;
            $byType[$type]['net'] += $net;
            $byType[$type]['count']++;

            $totals['gross'] += $gross;
            $totals['final'] += $final;
            $totals['net'] += $net;
            $totals['count']++;
        }

        // Round values
        foreach ($byType as $bucket => $values) {
            $byType[$bucket]['gross'] = round($values['gross'], 2);
            $byType[$bucket]['final'] = round($values['final'], 2);
            $byType[$bucket]['net'] = round($values['net'], 2);
        }
        $totals['gross'] = round($totals['gross'], 2);
        $totals['final'] = round($totals['final'], 2);
        $totals['net'] = round($totals['net'], 2);

        return [
            'by_type' => $byType,
            'totals' => $totals,
        ];
    }

    /**
     * Parse amount from string or number.
     *
     * @param mixed $value
     * @return float
     */
    private function parseAmount($value): float {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            // Remove currency symbols, commas, whitespace
            $cleaned = preg_replace('/[^\d.-]/', '', $value);
            return (float) $cleaned;
        }

        return 0.0;
    }
}
