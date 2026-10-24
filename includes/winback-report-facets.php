<?php
/**
 * Win-back facet normalization (EN slugs, venue/age aliases).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_winback_facet_alias_maps')) {
	/**
	 * Known roster / catalogue strings → canonical EN attribute slugs.
	 *
	 * @return array{venue:array<string,string>,age_group:array<string,string>}
	 */
	function intersoccer_winback_facet_alias_maps() {
		static $maps = null;
		if ($maps !== null) {
			return $maps;
		}
		$maps = [
			'venue' => [
				'lausanne - centre sportif de rochettaz, pully' => 'lausanne-stade-de-rochettaz-pully',
				'lausanne- stade de rochettaz, pully' => 'lausanne-stade-de-rochettaz-pully',
				'3-5y-half-day-5-13y-full-day' => '',
			],
			'age_group' => [
				'3-5y-half-day-5-13y-full-day' => '',
				'5-12' => '5-12y',
				'5-12-jahre' => '5-12y',
				'6-12-jahre' => '6-12y',
				'pre-k to g5' => '',
			],
		];

		$maps['age_group'] = apply_filters('intersoccer_winback_age_group_aliases', $maps['age_group']);

		return $maps;
	}
}

if (!function_exists('intersoccer_winback_apply_age_group_slug_alias')) {
	/**
	 * Map DE catalogue slugs (e.g. 5-12-jahre) to EN equivalents when known.
	 *
	 * @param string $slug Lowercase slug-like token.
	 */
	function intersoccer_winback_apply_age_group_slug_alias($slug) {
		$slug = strtolower(trim((string) $slug));
		if ($slug === '') {
			return '';
		}
		$maps = intersoccer_winback_facet_alias_maps();
		if (isset($maps['age_group'][ $slug ])) {
			return (string) $maps['age_group'][ $slug ];
		}
		if (preg_match('/^(\d+)-(\d+)-jahre$/', $slug, $m)) {
			return $m[1] . '-' . $m[2] . 'y';
		}

		return $slug;
	}
}

if (!function_exists('intersoccer_winback_register_unmapped_facet')) {
	/**
	 * @internal Tests reset via intersoccer_winback_reset_unmapped_facets().
	 */
	function &intersoccer_winback_unmapped_facet_store() {
		static $store = [];
		return $store;
	}

	function intersoccer_winback_reset_unmapped_facets() {
		$store = &intersoccer_winback_unmapped_facet_store();
		$store = [];
	}

	/**
	 * @param string $kind venue|age_group
	 * @param string $raw  Raw roster or attribute value.
	 */
	function intersoccer_winback_register_unmapped_facet($kind, $raw) {
		$raw = trim((string) $raw);
		if ($raw === '') {
			return;
		}
		$store = &intersoccer_winback_unmapped_facet_store();
		$kind = $kind === 'age_group' ? 'age_group' : 'venue';
		if (!isset($store[$kind][$raw])) {
			$store[$kind][$raw] = 0;
		}
		$store[$kind][$raw]++;
	}

	/**
	 * @return array{venue:array<string,int>,age_group:array<string,int>}
	 */
	function intersoccer_winback_get_unmapped_facets() {
		$store = intersoccer_winback_unmapped_facet_store();
		return [
			'venue' => $store['venue'] ?? [],
			'age_group' => $store['age_group'] ?? [],
		];
	}
}

if (!function_exists('intersoccer_winback_parse_age_group_bounds')) {
	/**
	 * Parse min/max child age from a label or slug. Unparseable bands return null.
	 *
	 * @param string $label Age group display name or slug.
	 * @return array{min:int,max:int}|null
	 */
	function intersoccer_winback_parse_age_group_bounds($label) {
		$label = trim((string) $label);
		if ($label === '') {
			return null;
		}
		$lower = strtolower($label);
		if (strpos($lower, 'pre-k') !== false || strpos($lower, 'g5') !== false) {
			return null;
		}
		if (preg_match('/(\d+)\s*-\s*(\d+)\s*y/i', $label, $m)) {
			return ['min' => (int) $m[1], 'max' => (int) $m[2]];
		}
		if (preg_match('/(\d+)\s*-\s*(\d+)(?:\s|$)/', $label, $m)) {
			return ['min' => (int) $m[1], 'max' => (int) $m[2]];
		}
		if (preg_match('/u(\d+)/i', $label, $m) || preg_match('/under\s*(\d+)/i', $label, $m)) {
			return ['min' => 0, 'max' => (int) $m[1]];
		}

		return null;
	}
}

if (!function_exists('intersoccer_winback_compute_age_at_date')) {
	/**
	 * @param string $dob     Y-m-d.
	 * @param string $on_date Y-m-d.
	 */
	function intersoccer_winback_compute_age_at_date($dob, $on_date) {
		$dob = trim((string) $dob);
		$on_date = trim((string) $on_date);
		if (!intersoccer_roster_report_has_real_date($dob) || !intersoccer_roster_report_has_real_date($on_date)) {
			return null;
		}
		try {
			$born = new \DateTimeImmutable($dob);
			$at = new \DateTimeImmutable($on_date);

			return (int) $born->diff($at)->y;
		} catch (\Exception $e) {
			return null;
		}
	}
}

if (!function_exists('intersoccer_winback_normalize_facet_slug')) {
	/**
	 * Normalize a roster facet value to an EN taxonomy slug (pure path for tests).
	 *
	 * @param string $raw       Roster or attribute value.
	 * @param string $taxonomy  pa_intersoccer-venues|pa_age-group|...
	 * @param string $kind      venue|age_group for alias + unmapped tracking.
	 * @param callable|null     $slug_resolver Optional ($name,$taxonomy) => slug.
	 */
	function intersoccer_winback_normalize_facet_slug($raw, $taxonomy, $kind = 'venue', $slug_resolver = null) {
		$raw = trim((string) $raw);
		if ($raw === '' || $raw === 'N/A') {
			return '';
		}
		$maps = intersoccer_winback_facet_alias_maps();
		$alias_key = strtolower(preg_replace('/\s+/', ' ', $raw));
		if (isset($maps[$kind][$alias_key])) {
			return (string) $maps[$kind][$alias_key];
		}
		if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', strtolower($raw))) {
			$slug = strtolower($raw);
			if ($kind === 'age_group') {
				return intersoccer_winback_apply_age_group_slug_alias($slug);
			}

			return $slug;
		}
		if (is_callable($slug_resolver)) {
			$slug = (string) $slug_resolver($raw, $taxonomy);
			if ($slug !== '') {
				return $slug;
			}
		} elseif (function_exists('intersoccer_get_term_slug_by_name')) {
			$slug = (string) intersoccer_get_term_slug_by_name($raw, $taxonomy);
			if ($slug !== '') {
				return $slug;
			}
		}
		intersoccer_winback_register_unmapped_facet($kind, $raw);

		return '';
	}
}

if (!function_exists('intersoccer_winback_dedupe_catalogue_variations')) {
	/**
	 * Dedupe WPML translation groups by default-language variation id.
	 *
	 * @param array<int,array<string,mixed>> $variations Each must include variation_id and source_variation_id.
	 * @return array<int,array<string,mixed>>
	 */
	function intersoccer_winback_dedupe_catalogue_variations(array $variations) {
		$by_source = [];
		foreach ($variations as $row) {
			if (!is_array($row)) {
				continue;
			}
			$source_id = (int) ($row['source_variation_id'] ?? $row['variation_id'] ?? 0);
			if ($source_id <= 0) {
				continue;
			}
			$start = (string) ($row['start_date'] ?? '');
			if (!isset($by_source[$source_id]) || $start < (string) ($by_source[$source_id]['start_date'] ?? '')) {
				$by_source[$source_id] = $row;
				continue;
			}
			if ($start === (string) ($by_source[$source_id]['start_date'] ?? '')) {
				$by_source[$source_id] = $row;
			}
		}

		return array_values($by_source);
	}
}

if (!function_exists('intersoccer_winback_detect_rebooked_flags')) {
	/**
	 * J2b: upcoming roster rows for the same player/kind.
	 *
	 * @param array<int,array<string,mixed>> $upcoming Rows with player_key, kind, venue_slug.
	 * @return array{same_venue:bool,elsewhere:bool}
	 */
	function intersoccer_winback_detect_rebooked_flags($player_key, $kind, $history_venue_slug, array $upcoming, $identity_map = null) {
		$same_venue = false;
		$elsewhere = false;
		foreach ($upcoming as $row) {
			if (!is_array($row)) {
				continue;
			}
			if ((string) ($row['kind'] ?? '') !== (string) $kind) {
				continue;
			}
			$upcoming_key = (string) ($row['player_key'] ?? '');
			$same_player = $upcoming_key === (string) $player_key;
			if (!$same_player && $identity_map instanceof \Intersoccer_Roster_Report_Player_Identity_Map) {
				$same_player = $identity_map->same_player((string) $player_key, $upcoming_key);
			}
			if (!$same_player) {
				continue;
			}
			$row_venue = (string) ($row['venue_slug'] ?? '');
			if ($history_venue_slug !== '' && $row_venue !== '' && $row_venue === $history_venue_slug) {
				$same_venue = true;
			} else {
				$elsewhere = true;
			}
		}

		return ['same_venue' => $same_venue, 'elsewhere' => $elsewhere];
	}
}

if (!function_exists('intersoccer_winback_girls_only_compatible')) {
	/**
	 * J13: girls-only history can match mixed upcoming and vice versa.
	 *
	 * @param int $history_girls_only 0|1
	 * @param int $catalogue_girls_only 0|1
	 */
	function intersoccer_winback_girls_only_compatible($history_girls_only, $catalogue_girls_only) {
		return true;
	}
}

if (!function_exists('intersoccer_winback_pick_target_age_slug')) {
	/**
	 * Choose target age slug for catalogue lookup (J1 aged-up uses eligible slug when known).
	 *
	 * @param string               $booked_age_slug Last booked EN slug.
	 * @param string|null          $dob
	 * @param string               $target_start    Y-m-d catalogue start.
	 * @param array<int,string>    $available_slugs Slugs offered at venue in catalogue.
	 */
	function intersoccer_winback_pick_target_age_slug($booked_age_slug, $dob, $target_start, array $available_slugs) {
		$booked_age_slug = trim((string) $booked_age_slug);
		$bounds = intersoccer_winback_parse_age_group_bounds($booked_age_slug);
		$age = intersoccer_winback_compute_age_at_date($dob, $target_start);
		$aged_up = false;
		if ($bounds !== null && $age !== null && $age > $bounds['max']) {
			$aged_up = true;
			$candidates = [];
			foreach ($available_slugs as $slug) {
				$b = intersoccer_winback_parse_age_group_bounds($slug);
				if ($b === null) {
					continue;
				}
				if ($age >= $b['min'] && $age <= $b['max']) {
					$candidates[] = $slug;
				}
			}
			if (!empty($candidates)) {
				usort($candidates, static function ($a, $b) {
					$ba = intersoccer_winback_parse_age_group_bounds($a);
					$bb = intersoccer_winback_parse_age_group_bounds($b);
					return ($ba['min'] ?? 0) <=> ($bb['min'] ?? 0);
				});

				return ['slug' => $candidates[0], 'aged_up' => true];
			}
		}

		return ['slug' => $booked_age_slug, 'aged_up' => $aged_up];
	}
}
