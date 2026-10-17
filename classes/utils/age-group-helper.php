<?php
/**
 * Shared age-group range parsing (aligned with DataValidator format rules and EventMatcher normalization).
 *
 * @package InterSoccer\ReportsRosters\Utils
 */

namespace InterSoccer\ReportsRosters\Utils;

if (!defined('ABSPATH')) {
    exit;
}

class AgeGroupHelper {

    /**
     * @var array<string, true>
     */
    private static $logged_unparseable = [];

    /**
     * @param array<string, array<string, array{min: int, max: int}>> $catalog
     * @return array<string, array{min: int, max: int}>
     */
    public static function flattenPredefinedCatalog(array $catalog): array {
        $flat = [];
        foreach ($catalog as $groups) {
            if (!is_array($groups)) {
                continue;
            }
            foreach ($groups as $label => $range) {
                if (is_array($range) && isset($range['min'], $range['max'])) {
                    $flat[(string) $label] = [
                        'min' => (int) $range['min'],
                        'max' => (int) $range['max'],
                    ];
                }
            }
        }
        return $flat;
    }

    public static function normalizeLabel(string $age_group): string {
        $normalized = preg_replace('/\s+/u', ' ', trim($age_group));
        return preg_replace('/[–—−]/u', '-', $normalized);
    }

    /**
     * @param array<string, array{min: int, max: int}> $predefined_exact
     * @return array{min: int, max: int}|null
     */
    public static function parseMinMax(string $age_group, array $predefined_exact = []): ?array {
        if ($age_group === '') {
            return null;
        }

        if (isset($predefined_exact[$age_group])) {
            return $predefined_exact[$age_group];
        }

        if (preg_match('/U(\d+)/i', $age_group, $matches) ||
            preg_match('/Under\s*(\d+)/i', $age_group, $matches)) {
            return [
                'min' => 0,
                'max' => (int) $matches[1],
            ];
        }

        $normalized = self::normalizeLabel($age_group);

        if (preg_match(
            '/^(\d+)\s*-\s*(\d+)(?:\s*(?:y|years?|ans|jahre?))?(?:\s*\([^)]+\))?$/iu',
            $normalized,
            $matches
        )) {
            return [
                'min' => (int) $matches[1],
                'max' => (int) $matches[2],
            ];
        }

        if (preg_match('/^(\d+)\+$/', $normalized, $matches)) {
            return [
                'min' => (int) $matches[1],
                'max' => 99,
            ];
        }

        self::logUnparseableOnce($age_group);

        return null;
    }

    /**
     * @param array<string, array{min: int, max: int}> $predefined_exact
     * @return array{min: int, max: int}|null
     */
    public static function parseMinMaxAsOnRelease21015(string $age_group, array $predefined_exact = []): ?array {
        if ($age_group === '') {
            return null;
        }

        if (isset($predefined_exact[$age_group])) {
            return $predefined_exact[$age_group];
        }

        if (preg_match('/U(\d+)/i', $age_group, $matches) ||
            preg_match('/Under\s*(\d+)/i', $age_group, $matches)) {
            return [
                'min' => 0,
                'max' => (int) $matches[1],
            ];
        }

        if (preg_match('/(\d+)-(\d+)y/', $age_group, $matches)) {
            return [
                'min' => (int) $matches[1],
                'max' => (int) $matches[2],
            ];
        }

        return null;
    }

    public static function logUnparseableOnce(string $age_group): void {
        if (isset(self::$logged_unparseable[$age_group])) {
            return;
        }
        self::$logged_unparseable[$age_group] = true;
        error_log('InterSoccer Reports: unparseable age group: ' . $age_group);
    }
}
