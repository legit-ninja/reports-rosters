# Tournament Event Signature Issue

## Problem Identified

When tournaments were added as a new activity type, the `event_signature` generation was NOT updated to include tournament-specific product attributes.

### Current Event Signature Components

The event signature currently includes:
- `activity_type`
- `venue`
- `age_group`
- `camp_terms`
- `course_day` (also used for tournament day)
- `times`
- `season`
- `girls_only`
- `product_id`

### Missing Components for Tournaments

Tournaments use these additional distinguishing attributes that are NOT in the signature:
- **`city`** - Critical for tournaments (e.g., "Geneva", "Lausanne")
- **`canton_region`** - Canton/Region where tournament is held

### Impact

**Severe Roster Grouping Issue**: Two different tournaments could have the same event_signature if they have:
- Same venue
- Same age group
- Same tournament day
- Same times
- Same season
- **BUT different cities or canton/regions**

This would cause players from different tournaments to be grouped on the SAME roster, which is incorrect.

### Example Scenario

```
Tournament A:
- Venue: "InterSoccer Facility"
- Age Group: "U13"
- Tournament Day: "Saturday"
- Times: "9:00am-5:00pm"
- Season: "Fall 2025"
- City: "Geneva"
- Canton: "Geneva"

Tournament B:
- Venue: "InterSoccer Facility"
- Age Group: "U13"
- Tournament Day: "Saturday"
- Times: "9:00am-5:00pm"
- Season: "Fall 2025"
- City: "Lausanne"  ← DIFFERENT
- Canton: "Vaud"    ← DIFFERENT

Current Behavior: Same event_signature → Players grouped on ONE roster (WRONG!)
Expected Behavior: Different event_signatures → Separate rosters
```

## Data Evidence

### 1. Database Schema
From `includes/db.php` lines 67-68:
```php
canton_region varchar(100) DEFAULT '',
city varchar(100) DEFAULT '',
```

### 2. Data Extraction
From `includes/utils.php` lines 310-311:
```php
$canton_region = $item_meta['pa_canton-region'] ?? $item_meta['Canton / Region'] ?? '';
$city = $item_meta['City'] ?? '';
```

### 3. Data Storage
From `includes/utils.php` lines 504-505:
```php
'canton_region' => substr((string)($canton_region ?: ''), 0, 100),
'city' => substr((string)($city ?: ''), 0, 100),
```

### 4. Signature Generation (MISSING city and canton_region!)
From `includes/utils.php` lines 1253-1265:
```php
function intersoccer_generate_event_signature($event_data) {
    $normalized_components = [
        'activity_type' => $event_data['activity_type'] ?? '',
        'venue' => intersoccer_get_term_slug_by_name($event_data['venue'] ?? '', 'pa_intersoccer-venues'),
        'age_group' => intersoccer_get_term_slug_by_name($event_data['age_group'] ?? '', 'pa_age-group'),
        'camp_terms' => $event_data['camp_terms'] ?? '',
        'course_day' => intersoccer_get_term_slug_by_name($event_data['course_day'] ?? '', 'pa_course-day'),
        'times' => $event_data['times'] ?? '',
        'season' => intersoccer_get_term_slug_by_name($event_data['season'] ?? '', 'pa_program-season'),
        'girls_only' => $event_data['girls_only'] ? '1' : '0',
        'product_id' => $event_data['product_id'] ?? '',
        // MISSING: city, canton_region
    ];
    // ...
}
```

## Product Attributes Used by Tournaments

From `intersoccer-product-variations` plugin analysis:

### Registered Attributes
- `pa_activity-type` → "Tournament"
- `pa_intersoccer-venues` → Venue name
- `pa_program-season` → Season
- `pa_age-group` → Age group
- **`pa_canton-region`** → Canton/Region (TRANSLATABLE via WPML)
- **`pa_city`** → City (TRANSLATABLE via WPML)
- `pa_tournament-day` → Day of week (custom taxonomy)
- `pa_tournament-time` → Time slot (custom taxonomy)

## Solution

### 1. Update Event Signature Generation

Add `city` and `canton_region` to the signature components:

**Files to modify:**
- `includes/utils.php` - `intersoccer_generate_event_signature()`
- `includes/utils.php` - `intersoccer_normalize_event_data_for_signature()`
- `includes/utils.php` - Event data array passed to signature generation
- `classes/services/event-signature-generator.php` - OOP version
- `includes/db.php` - `intersoccer_rebuild_event_signatures()`

### 2. Add Taxonomy Normalization

Since `pa_city` and `pa_canton-region` are translatable via WPML, they need normalization:

```php
// In intersoccer_normalize_event_data_for_signature()
if (!empty($event_data['city'])) {
    $term = intersoccer_get_term_by_translated_name($event_data['city'], 'pa_city');
    if ($term) {
        $normalized['city'] = $term->name;
    }
}

if (!empty($event_data['canton_region'])) {
    $term = intersoccer_get_term_by_translated_name($event_data['canton_region'], 'pa_canton-region');
    if ($term) {
        $normalized['canton_region'] = $term->name;
    }
}
```

```php
// In intersoccer_generate_event_signature()
$normalized_components = [
    // ... existing components ...
    'city' => intersoccer_get_term_slug_by_name($event_data['city'] ?? '', 'pa_city'),
    'canton_region' => intersoccer_get_term_slug_by_name($event_data['canton_region'] ?? '', 'pa_canton-region'),
];
```

### 3. Rebuild Signatures

After updating the code, run:
```php
intersoccer_rebuild_event_signatures();
```

This will regenerate all event_signatures with the new city/canton_region components.

## Implementation Checklist

- [ ] Update `intersoccer_generate_event_signature()` to include city and canton_region
- [ ] Update `intersoccer_normalize_event_data_for_signature()` to normalize city and canton_region
- [ ] Update event data array in `intersoccer_process_order_to_roster()` to pass city and canton_region
- [ ] Update OOP `EventSignatureGenerator` class
- [ ] Update `intersoccer_rebuild_event_signatures()` to include new fields
- [ ] Test with existing tournament data
- [ ] Rebuild event signatures for all existing records
- [ ] Verify tournaments are properly separated by city/canton

## Testing

1. Create test orders for tournaments in different cities
2. Verify they generate different event_signatures
3. Verify they appear on separate rosters
4. Verify French/German translations are normalized correctly
5. Run signature rebuild and verify consistency

## Notes

- This affects ALL activity types, not just tournaments (city/canton will be included even if empty for camps/courses)
- Empty values will be normalized to empty strings, so they won't break existing signatures for non-tournament activities
- The fix is backward-compatible because existing signatures will be regenerated during the rebuild


