<?php
/**
 * Single place for EN/FR/DE + HTML-entity normalisation until PV canonical writers land.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class FacetNormalizer {

	/**
	 * Canonical meta keys agreed with product-variations ORDER-META-CONTRACT.
	 *
	 * @return array<string,string> meta_key => logical field
	 */
	public static function canonical_meta_map() {
		return [
			'_intersoccer_canonical_activity_type' => 'activity_type',
			'_intersoccer_canonical_girls_only' => 'girls_only',
			'_intersoccer_canonical_booking_type' => 'booking_type',
			'_intersoccer_canonical_venue' => 'venue',
			'_intersoccer_canonical_canton' => 'region',
			'_intersoccer_canonical_age_group' => 'age_group',
			'_intersoccer_canonical_camp_terms' => 'camp_week_key',
		];
	}

	/**
	 * Decode HTML entities and normalize whitespace/apostrophes.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function decode_label($value) {
		$value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = str_replace(["\xC2\xA0", "\xE2\x80\xAF", '’', '‘', '`'], [' ', ' ', "'", "'", "'"], $value);
		$value = preg_replace('/\s+/u', ' ', $value);
		return trim((string) $value);
	}

	/**
	 * @param array<string,mixed> $raw Meta key => value (pivoted once)
	 * @return array<string,mixed> Normalized facets
	 */
	public function normalize_from_meta(array $raw) {
		$out = [
			'activity_type' => 'other',
			'girls_only' => 0,
			'booking_type' => 'other',
			'day_length' => 'other',
			'venue' => 'not_recorded',
			'region' => 'not_recorded',
			'age_group' => 'not_recorded',
			'camp_week' => 'not_recorded',
			'camp_week_index' => null,
			'product_name' => '',
			'season' => '',
		];

		foreach (self::canonical_meta_map() as $meta_key => $field) {
			if (!array_key_exists($meta_key, $raw) || $raw[$meta_key] === '' || $raw[$meta_key] === null) {
				continue;
			}
			$val = is_scalar($raw[$meta_key]) ? (string) $raw[$meta_key] : '';
			if ($field === 'girls_only') {
				$out['girls_only'] = in_array(strtolower($val), ['1', 'yes', 'true'], true) ? 1 : 0;
			} elseif ($field === 'activity_type') {
				$out['activity_type'] = $this->normalize_activity_type($val);
			} elseif ($field === 'booking_type') {
				$out['booking_type'] = $this->normalize_booking_type($val);
			} elseif ($field === 'venue') {
				$out['venue'] = $this->normalize_venue($val);
			} elseif ($field === 'region') {
				$out['region'] = $this->normalize_region($val);
			} elseif ($field === 'age_group') {
				$out['age_group'] = $this->normalize_age_group($val);
			} elseif ($field === 'camp_week_key') {
				$out['camp_week'] = $this->normalize_camp_week($val);
			}
		}

		// Display-label fallbacks when canonical absent.
		$activity_raw = $this->first_meta($raw, ['Activity Type', 'pa_activity-type', 'attribute_pa_activity-type']);
		if ($out['activity_type'] === 'other' && $activity_raw !== '') {
			$out['activity_type'] = $this->normalize_activity_type($activity_raw);
		}
		if (!$out['girls_only'] && $this->text_indicates_girls_only($activity_raw)) {
			$out['girls_only'] = 1;
		}
		if (isset($raw['_intersoccer_girls_only']) && in_array(strtolower((string) $raw['_intersoccer_girls_only']), ['1', 'yes', 'true'], true)) {
			$out['girls_only'] = 1;
		}

		$bt = $this->first_meta($raw, ['Booking Type', 'pa_booking-type', 'attribute_pa_booking-type']);
		if ($out['booking_type'] === 'other' && $bt !== '') {
			$out['booking_type'] = $this->normalize_booking_type($bt);
		}

		$venue = $this->first_meta($raw, ['Sites InterSoccer', 'InterSoccer Venues', 'pa_intersoccer-venues', 'attribute_pa_intersoccer-venues']);
		if ($out['venue'] === 'not_recorded' && $venue !== '') {
			$out['venue'] = $this->normalize_venue($venue);
		}

		$region = $this->first_meta($raw, ['Canton / Region', 'pa_canton-region', 'attribute_pa_canton-region']);
		if ($out['region'] === 'not_recorded' && $region !== '') {
			$out['region'] = $this->normalize_region($region);
		}

		$age = $this->first_meta($raw, ['Age Group', 'pa_age-group', 'attribute_pa_age-group']);
		if ($out['age_group'] === 'not_recorded' && $age !== '') {
			$out['age_group'] = $this->normalize_age_group($age);
		}

		$week_index = $this->first_meta($raw, ['_camp_week_index', 'Camp Week Index']);
		if ($week_index !== '' && is_numeric($week_index)) {
			$out['camp_week_index'] = (int) $week_index;
		}

		$camp_terms = $this->first_meta($raw, ['Camp Terms', 'pa_camp-terms', 'attribute_pa_camp-terms', '_intersoccer_canonical_camp_terms']);
		if ($out['camp_week'] === 'not_recorded') {
			if ($camp_terms !== '') {
				$out['camp_week'] = $this->normalize_camp_week($camp_terms);
			} elseif ($out['camp_week_index']) {
				$out['camp_week'] = 'week_' . $out['camp_week_index'];
			}
		}

		$course_terms = $this->first_meta($raw, ['Course Terms', 'pa_course-day', 'Season']);
		if ($out['camp_week'] === 'not_recorded' && $course_terms !== '' && $out['activity_type'] === 'course') {
			$out['camp_week'] = $this->normalize_course_term($course_terms);
		}

		// Day length follows MASTER B.6: Age Group display label (+ times), not pa_* slug alone.
		$age_label = $this->first_meta($raw, ['Age Group']);
		$times = $this->first_meta($raw, ['Camp Times', 'pa_camp-times', 'Course Times', 'pa_course-times']);
		$out['day_length'] = $this->normalize_day_length($age_label, $times);

		$out['product_name'] = $this->first_meta($raw, ['_product_name', 'product_name']);
		$out['season'] = $this->first_meta($raw, ['Season', 'pa_program-season']);

		return $out;
	}

	/**
	 * @param string $raw
	 * @return string
	 */
	public function normalize_activity_type($raw) {
		$t = strtolower(self::decode_label($raw));
		if ($this->text_indicates_girls_only($t)) {
			// Strip girls-only composite to base type.
			$t = preg_replace('/,?\s*girls[\'’`]?\s*only/i', '', $t);
			$t = trim($t, " \t\n\r\0\x0B,");
		}
		$map = [
			'camp' => 'camp',
			'camps' => 'camp',
			'course' => 'course',
			'courses' => 'course',
			'cours' => 'course',
			'tournament' => 'tournament',
			'tournaments' => 'tournament',
			'tournoi' => 'tournament',
			'birthday' => 'birthday',
			'birthday party' => 'birthday',
			'event' => 'event',
		];
		if (isset($map[$t])) {
			return $map[$t];
		}
		foreach ($map as $needle => $slug) {
			if (strpos($t, $needle) !== false) {
				return $slug;
			}
		}
		if ($t === '' || $t === 'unknown') {
			return 'other';
		}
		return 'unmapped:' . self::decode_label($raw);
	}

	/**
	 * @param string $raw
	 * @return bool
	 */
	public function text_indicates_girls_only($raw) {
		$t = strtolower(self::decode_label($raw));
		return (bool) preg_match('/girls[\'’`]?\s*only|filles?\s*seulement|nur\s*m[aä]dchen/i', $t);
	}

	/**
	 * @param string $raw
	 * @return string
	 */
	public function normalize_booking_type($raw) {
		$t = strtolower(self::decode_label($raw));
		$t = str_replace(['_', '–', '—'], ['-', '-', '-'], $t);
		if ($t === '' || $t === 'unknown') {
			return 'not_recorded';
		}
		// EN / FR / DE + slug leaks (MASTER B.6).
		if (strpos($t, 'full week') !== false
			|| strpos($t, 'semaine compl') !== false
			|| strpos($t, 'semaine-compl') !== false
			|| strpos($t, 'ganze woche') !== false
			|| strpos($t, 'ganze-woche') !== false
			|| $t === 'full-week') {
			return 'full-week';
		}
		if (strpos($t, 'single') !== false
			|| strpos($t, 'individual') !== false
			|| strpos($t, 'la journ') !== false
			|| strpos($t, 'a-la-journee') !== false
			|| strpos($t, 'einzelne') !== false
			|| strpos($t, 'jour') !== false
			|| $t === 'single-days'
			|| $t === 'single-day') {
			return 'single-days';
		}
		if (strpos($t, 'full term') !== false
			|| strpos($t, 'trimestre') !== false
			|| strpos($t, 'volle laufzeit') !== false
			|| (strpos($t, 'term') !== false && strpos($t, 'full') !== false)
			|| $t === 'full-term') {
			return 'full-term';
		}
		if (strpos($t, 'buyclub') !== false || strpos($t, 'buy club') !== false) {
			return 'buyclub';
		}
		return 'unmapped:' . self::decode_label($raw);
	}

	/**
	 * @param string $age_group
	 * @param string $times
	 * @return string full-day|half-day-mini|other
	 */
	public function normalize_day_length($age_group, $times = '') {
		$a = strtolower(self::decode_label($age_group . ' ' . $times));
		// Align with MASTER B.6 (Age Group tokens only — do not infer from 6-9 / 10-13 alone).
		if (strpos($a, 'half') !== false
			|| strpos($a, 'mini') !== false
			|| strpos($a, 'demi') !== false
			|| strpos($a, '3-5') !== false
			|| strpos($a, '3–5') !== false) {
			return 'half-day-mini';
		}
		if (strpos($a, 'full') !== false || strpos($a, 'compl') !== false) {
			return 'full-day';
		}
		return 'other';
	}

	/**
	 * @param string $raw
	 * @return string
	 */
	public function normalize_venue($raw) {
		$t = self::decode_label($raw);
		if ($t === '') {
			return 'not_recorded';
		}
		// Substring map mirrors MASTER round3 D.1 / Section E short labels.
		$lower = strtolower($t);
		$aliases = [
			'vessy' => 'Vessy',
			'varemb' => 'Varembé',
			'frontenex' => 'Frontenex',
			'saconnex' => 'Grand-Saconnex',
			'sismondi' => 'Sismondi',
			'thonex' => 'Thônex',
			'chenois' => 'Thônex',
			'versoix' => 'Versoix',
			'seefeld' => 'FC Seefeld',
			'langnau' => 'Langnau am Albis',
			'tufi' => 'Tufi Adliswil',
			'adliswil' => 'Tufi Adliswil',
			'rochettaz' => 'Rochettaz',
			'dorigny' => 'Dorigny UNIL',
			'unil' => 'Dorigny UNIL',
			'colovray' => 'Colovray',
			'etoy' => 'Etoy',
			'walterwil' => 'ISZL Walterwil',
			'iszl' => 'ISZL Walterwil',
			'rankhof' => 'Rankhof',
		];
		foreach ($aliases as $needle => $label) {
			if (strpos($lower, $needle) !== false) {
				return $label;
			}
		}
		return $t;
	}

	/**
	 * @param string $raw
	 * @return string
	 */
	public function normalize_region($raw) {
		$t = strtolower(self::decode_label($raw));
		if ($t === '') {
			return 'not_recorded';
		}
		$map = [
			'geneva' => 'Geneva',
			'genève' => 'Geneva',
			'geneve' => 'Geneva',
			'genf' => 'Geneva',
			'zurich' => 'Zurich',
			'zürich' => 'Zurich',
			'vaud' => 'Vaud',
			'basel' => 'Basel-Stadt',
			'basel-stadt' => 'Basel-Stadt',
			'bâle' => 'Basel-Stadt',
			'bale' => 'Basel-Stadt',
			'zug' => 'Zug',
			'zoug' => 'Zug',
		];
		foreach ($map as $needle => $label) {
			if ($t === $needle || strpos($t, $needle) !== false) {
				return $label;
			}
		}
		return 'unmapped:' . self::decode_label($raw);
	}

	/**
	 * @param string $raw
	 * @return string
	 */
	public function normalize_age_group($raw) {
		$t = self::decode_label($raw);
		return $t === '' ? 'not_recorded' : $t;
	}

	/**
	 * Merge EN/FR camp week labels into a stable key (e.g. summer_week_5).
	 *
	 * @param string $raw
	 * @return string
	 */
	public function normalize_camp_week($raw) {
		$t = self::decode_label($raw);
		if ($t === '') {
			return 'not_recorded';
		}

		// Extract first week number when a multi-week string appears.
		// Also match FR slug leaks: semaine-dete-5, semaine-d-ete-5 (MASTER B.7 / E trap).
		if (preg_match('/(?:summer|printemps|été|ete|autumn|automne|winter|hiver|easter|pâques|paques)?\s*week\s*(\d+)/i', $t, $m)
			|| preg_match('/semaine(?:\s+d[\'’`]?\s*ét[ée])?\s*(\d+)/iu', $t, $m)
			|| preg_match('/semaine\s*(\d+)/i', $t, $m)
			|| preg_match('/semaine[-_]?d[\'’`]?e?te[-_]?(\d+)/i', $t, $m)
			|| preg_match('/\bwoche\s*(\d+)/i', $t, $m)) {
			$n = (int) $m[1];
			$season = 'summer';
			$lower = strtolower($t);
			if (preg_match('/autumn|automne|fall/i', $lower)) {
				$season = 'autumn';
			} elseif (preg_match('/winter|hiver/i', $lower)) {
				$season = 'winter';
			} elseif (preg_match('/easter|pâques|paques/i', $lower)) {
				$season = 'easter';
			}
			return $season . '_week_' . $n;
		}

		if (preg_match('/autumn|automne/i', $t) && preg_match('/course/i', $t)) {
			return 'autumn_courses';
		}
		if (preg_match('/autumn|automne/i', $t)) {
			return 'autumn_courses';
		}

		$slug = function_exists('sanitize_title') ? sanitize_title($t) : preg_replace('/[^a-z0-9]+/i', '-', strtolower($t));
		return 'camp_' . trim((string) $slug, '-');
	}

	/**
	 * @param string $raw
	 * @return string
	 */
	public function normalize_course_term($raw) {
		$t = self::decode_label($raw);
		if (preg_match('/autumn|automne/i', $t)) {
			return 'autumn_courses';
		}
		$slug = function_exists('sanitize_title') ? sanitize_title($t) : preg_replace('/[^a-z0-9]+/i', '-', strtolower($t));
		return 'course_' . trim((string) $slug, '-');
	}

	/**
	 * Human label for a normalized camp week key.
	 *
	 * @param string $key
	 * @return string
	 */
	public function camp_week_display($key) {
		if (preg_match('/^summer_week_(\d+)$/', $key, $m)) {
			return 'Summer Week ' . $m[1];
		}
		if ($key === 'autumn_courses') {
			return 'Autumn courses';
		}
		if (preg_match('/^week_(\d+)$/', $key, $m)) {
			return 'Week ' . $m[1];
		}
		return $key === 'not_recorded' ? 'not recorded' : $key;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param string[]            $keys
	 * @return string
	 */
	private function first_meta(array $raw, array $keys) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $raw)) {
				continue;
			}
			$v = $raw[$key];
			if (is_array($v)) {
				// Duplicate pa_* rows: take first scalar.
				foreach ($v as $item) {
					if (is_scalar($item) && (string) $item !== '') {
						return (string) $item;
					}
				}
				continue;
			}
			if ($v !== null && (string) $v !== '') {
				return (string) $v;
			}
		}
		return '';
	}
}
