# Tournament Event Signature Fix - Implementation Complete

## Overview

Fixed a critical bug where tournament event signatures did not include `city` and `canton_region` attributes, causing tournaments in different cities to be incorrectly grouped together on the same roster.

## Changes Implemented

### 1. Event Signature Generation (`includes/utils.php`)

**Function: `intersoccer_generate_event_signature()`** (lines 1253-1278)

Added city and canton_region to the normalized components:

```php
$normalized_components = [
    'activity_type' => $event_data['activity_type'] ?? '',
    'venue' => intersoccer_get_term_slug_by_name($event_data['venue'] ?? '', 'pa_intersoccer-venues'),
    'age_group' => intersoccer_get_term_slug_by_name($event_data['age_group'] ?? '', 'pa_age-group'),
    'camp_terms' => $event_data['camp_terms'] ?? '',
    'course_day' => intersoccer_get_term_slug_by_name($event_data['course_day'] ?? '', 'pa_course-day'),
    'times' => $event_data['times'] ?? '',
    'season' => intersoccer_get_term_slug_by_name($event_data['season'] ?? '', 'pa_program-season'),
    'girls_only' => $event_data['girls_only'] ? '1' : '0',
    'city' => intersoccer_get_term_slug_by_name($event_data['city'] ?? '', 'pa_city'),              // NEW
    'canton_region' => intersoccer_get_term_slug_by_name($event_data['canton_region'] ?? '', 'pa_canton-region'),  // NEW
    'product_id' => $event_data['product_id'] ?? '',
];
```

### 2. Event Data Normalization (`includes/utils.php`)

**Function: `intersoccer_normalize_event_data_for_signature()`** (lines 1061-1177)

Added normalization for city and canton_region taxonomy terms:

```php
// Normalize city (taxonomy term name) - important for tournaments
if (!empty($event_data['city'])) {
    $term = intersoccer_get_term_by_translated_name($event_data['city'], 'pa_city');
    if ($term) {
        $normalized['city'] = $term->name;
    }
}

// Normalize canton_region (taxonomy term name) - important for tournaments
if (!empty($event_data['canton_region'])) {
    $term = intersoccer_get_term_by_translated_name($event_data['canton_region'], 'pa_canton-region');
    if ($term) {
        $normalized['canton_region'] = $term->name;
    }
}
```

**Why normalization matters**: City and canton_region are translatable via WPML (French/German/English). We need to normalize them to English slugs to ensure orders placed in different languages still generate the same event signature.

### 3. Event Data Array (`includes/utils.php`)

**Function: `intersoccer_process_order_to_roster()`** (lines 517-528)

Added city and canton_region to the event data passed to signature generation:

```php
$original_event_data = [
    'activity_type' => $activity_type,
    'venue' => $venue,
    'age_group' => $age_group,
    'camp_terms' => $camp_terms,
    'course_day' => $course_day,
    'times' => $times,
    'season' => $season,
    'girls_only' => $girls_only,
    'city' => $city,                      // NEW
    'canton_region' => $canton_region,    // NEW
    'product_id' => $product_id,
];
```

### 4. Signature Rebuild Function (`includes/db.php`)

**Function: `intersoccer_rebuild_event_signatures()`** (lines 196-238)

Updated the SQL SELECT and normalization array to include city and canton_region:

```php
// Get all records that need signature updates (including city and canton_region for tournaments)
$records = $wpdb->get_results("SELECT id, activity_type, venue, age_group, camp_terms, course_day, times, season, girls_only, city, canton_region, product_id FROM $rosters_table", ARRAY_A);

$updated = 0;
foreach ($records as $record) {
    $normalized_data = intersoccer_normalize_event_data_for_signature([
        'activity_type' => $record['activity_type'],
        'venue' => $record['venue'],
        'age_group' => $record['age_group'],
        'camp_terms' => $record['camp_terms'],
        'course_day' => $record['course_day'],
        'times' => $record['times'],
        'season' => $record['season'],
        'girls_only' => $record['girls_only'],
        'city' => $record['city'] ?? '',              // NEW
        'canton_region' => $record['canton_region'] ?? '',  // NEW
        'product_id' => $record['product_id'],
    ]);
    // ...
}
```

### 5. OOP EventSignatureGenerator Class (`classes/services/event-signature-generator.php`)

**Method: `generate()`** (lines 58-68)

Added city and canton_region to components array:

```php
$components = [
    'activity_type' => $normalized['activity_type'] ?? '',
    'venue' => $normalized['venue'] ?? '',
    'age_group' => $normalized['age_group'] ?? '',
    'camp_terms' => $normalized['camp_terms'] ?? '',
    'course_day' => $normalized['course_day'] ?? '',
    'times' => $normalized['times'] ?? '',
    'season' => $normalized['season'] ?? '',
    'girls_only' => $normalized['girls_only'] ?? 0,
    'city' => $normalized['city'] ?? '',              // NEW
    'canton_region' => $normalized['canton_region'] ?? '',  // NEW
    'product_id' => $normalized['product_id'] ?? 0,
];
```

**Method: `normalize()`** (lines 138-146)

Added taxonomy normalization for city and canton_region:

```php
// Normalize city (taxonomy term -> slug) - important for tournaments
if (!empty($event_data['city'])) {
    $normalized['city'] = $this->getTermSlug($event_data['city'], 'pa_city');
}

// Normalize canton_region (taxonomy term -> slug) - important for tournaments
if (!empty($event_data['canton_region'])) {
    $normalized['canton_region'] = $this->getTermSlug($event_data['canton_region'], 'pa_canton-region');
}
```

## Impact

### Before Fix
Two tournaments with:
- Same venue, age group, tournament day, times, season
- **Different cities or cantons**
- Would generate the **SAME event_signature**
- Players would be **incorrectly grouped on ONE roster**

### After Fix
Two tournaments with:
- Same venue, age group, tournament day, times, season
- **Different cities or cantons**
- Generate **DIFFERENT event_signatures**
- Players appear on **SEPARATE rosters** (correct behavior)

## Backward Compatibility

✅ **Fully backward compatible**

- Empty city/canton_region values are normalized to empty strings
- Camps and Courses without city/canton data are unaffected
- Existing signatures can be regenerated via rebuild function
- No database schema changes required (columns already exist)

## Next Steps

### 1. Test Implementation

Create test scenarios:
```php
// Test Case 1: Two tournaments in different cities
Tournament A: Geneva
Tournament B: Lausanne
Expected: Different event_signatures

// Test Case 2: Two tournaments in same city
Tournament A: Geneva
Tournament B: Geneva
Expected: Same event_signature (if all other attributes match)

// Test Case 3: Multilingual orders
Order in French: "Genève"
Order in English: "Geneva"
Expected: Same event_signature (normalization working)
```

### 2. Rebuild Event Signatures

After deploying, run the rebuild function to regenerate all existing signatures:

**Via Admin UI:**
Navigate to: InterSoccer Reports > Advanced > Rebuild Event Signatures

**Via Code:**
```php
$result = intersoccer_rebuild_event_signatures();
error_log('Rebuild result: ' . print_r($result, true));
```

**Via WP-CLI** (if available):
```bash
wp eval "echo json_encode(intersoccer_rebuild_event_signatures());"
```

### 3. Verify Tournament Rosters

After rebuild:
1. Navigate to InterSoccer Reports > Tournaments
2. Verify each tournament appears as a separate roster card
3. Check that players are correctly grouped by city/canton
4. Verify filtering works correctly

### 4. Monitor Logs

Check `debug.log` for signature generation logs:
```
InterSoccer Signature: Generated event_signature=... for Order=..., Item=..., Product=..., Venue=..., Camp/Course=...
InterSoccer: Generated normalized event signature: ... from components: {...}
```

Verify that city and canton_region are included in the logged components.

## Files Modified

1. ✅ `includes/utils.php` - Signature generation and normalization
2. ✅ `includes/db.php` - Signature rebuild function
3. ✅ `classes/services/event-signature-generator.php` - OOP version

## Testing Checklist

- [ ] Create test tournament orders with different cities
- [ ] Verify different event_signatures are generated
- [ ] Verify tournaments appear on separate roster pages
- [ ] Test multilingual orders (French/German/English)
- [ ] Verify signature normalization handles translated city names
- [ ] Run signature rebuild on existing data
- [ ] Verify no roster grouping issues for camps/courses
- [ ] Check logs for proper signature generation

## Rollback Plan

If issues occur:
1. Revert the 3 modified files to previous versions
2. Run `intersoccer_rebuild_event_signatures()` to regenerate signatures with old logic
3. Investigate and fix issues before re-deploying

## Documentation Updated

- [x] TOURNAMENT-EVENT-SIGNATURE-FIX.md - Problem analysis
- [x] TOURNAMENT-SIGNATURE-IMPLEMENTATION.md - Implementation details (this file)
- [ ] Update MULTILINGUAL-EVENT-SIGNATURES.md with city/canton_region examples

## Version

Implemented: December 5, 2025
Target Release: v1.12.2


