# Multilingual Event Signature System

## Overview

The **Event Signature** is a unique identifier that groups order items belonging to the same physical event (camp session, course, birthday party) regardless of the language used when the customer purchased the product.

## The Problem

InterSoccer supports multiple languages (English, French, German) via WPML. When a customer purchases a product:

- **Product attributes** (venue, age group, camp terms, course day, etc.) are stored in the order item metadata **in the language the customer used**
- Without normalization, the same camp would appear as **3 different events** in roster reports:
  - English: "Summer Week 1 - July 7-July 11 - 5 days"
  - French: "Été Semaine 1 - juillet 7-juillet 11 - 5 jours"  
  - German: "Sommer Woche 1 - Juli 7-Juli 11 - 5 Tage"

This creates **major problems**:
1. **Roster fragmentation**: One camp appears as 3 separate rosters
2. **Confusing reports**: Staff can't see the full picture of registrations
3. **Export chaos**: Excel exports split the same event across multiple rows
4. **Inaccurate counts**: Player counts are divided across language variants

## The Solution: Language-Agnostic Event Signatures

All product variations for the same physical event must generate **identical event signatures** regardless of purchase language.

### Core Principle

**Event signatures are based on normalized (English) attribute values, not the translated display values.**

## How It Works

### Step 1: Order Item Data Collection

When an order is processed, order item metadata contains **translated attribute values**:

```php
// Example: French customer purchases a camp
$item_meta = [
    'Venue' => 'Genève Centre',           // Translated term name
    'Age Group' => '5-13a (Journée complète)',  // Translated
    'Camp Terms' => 'Été Semaine 1 - juillet 7-juillet 11 - 5 jours',
    'Season' => 'Été 2025',
    'Times' => '9h00-16h00',
    // ... other metadata
];
```

### Step 2: Event Data Normalization

The `intersoccer_normalize_event_data_for_signature()` function normalizes all translatable attributes to **English default language values**:

```php
$normalized_event_data = intersoccer_normalize_event_data_for_signature([
    'activity_type' => 'Camp',
    'venue' => 'Genève Centre',           // French input
    'age_group' => '5-13a (Journée complète)',  // French input
    'camp_terms' => 'Été Semaine 1 - juillet 7-juillet 11 - 5 jours',  // French
    'course_day' => 'N/A',
    'times' => '9h00-16h00',
    'season' => 'Été 2025',               // French input
    'girls_only' => false,
    'product_id' => 12345,
]);

// Result after normalization:
[
    'activity_type' => 'Camp',
    'venue' => 'Geneva Centre',           // Normalized to English
    'age_group' => '5-13y (Full Day)',    // Normalized to English
    'camp_terms' => 'Summer Week 1 - July 7-July 11 - 5 days',  // Normalized
    'course_day' => 'N/A',
    'times' => '9:00am-4:00pm',           // Normalized to English format
    'season' => 'Summer 2025',            // Normalized to English
    'girls_only' => false,
    'product_id' => 12345,
]
```

### Step 3: Normalization Process Details

#### A. WPML Language Switching
```php
// Store current language
$current_lang = wpml_get_current_language();  // e.g., 'fr'

// Switch to default language (English)
$default_lang = wpml_get_default_language();  // 'en'
do_action('wpml_switch_language', $default_lang);
```

#### B. Taxonomy Term Resolution

For each translatable attribute (venue, age_group, camp_terms, etc.):

1. **Search by translated name** in all language versions of the taxonomy
2. **Get the term in default language** (English)
3. **Use the English term name** in the normalized data

```php
// Example: Normalize venue
$translated_name = 'Genève Centre';  // French customer input
$term = intersoccer_get_term_by_translated_name($translated_name, 'pa_intersoccer-venues');

// This function:
// 1. Gets all terms in pa_intersoccer-venues taxonomy
// 2. Checks each term's name across all languages
// 3. Uses WPML's wpml_get_element_translations() if needed
// 4. Returns the term object in the default language

if ($term) {
    $normalized['venue'] = $term->name;  // "Geneva Centre" in English
}
```

#### C. Manual String Normalization (Fallback)

For season strings that might not be taxonomy terms:

```php
$season = 'Été 2025';  // French input

// Manual replacement as fallback
$season = str_ireplace('Hiver', 'Winter', $season);
$season = str_ireplace('Été', 'Summer', $season);
$season = str_ireplace('Printemps', 'Spring', $season);
$season = str_ireplace('Automne', 'Autumn', $season);

// Result: 'Summer 2025'
```

#### D. Language Context Restoration

```php
// Switch back to customer's original language
do_action('wpml_switch_language', $current_lang);
```

### Step 4: Signature Generation

The `intersoccer_generate_event_signature()` function creates an MD5 hash from the normalized data:

```php
function intersoccer_generate_event_signature($event_data) {
    // Convert term names to slugs for additional normalization
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
    ];

    // Create signature string
    $signature_string = implode('|', array_map(function($key, $value) {
        return $key . ':' . trim(strtolower($value));
    }, array_keys($normalized_components), $normalized_components));
    
    // Example signature string:
    // "activity_type:camp|venue:geneva-centre|age_group:5-13y-full-day|camp_terms:summer week 1 - july 7-july 11 - 5 days|course_day:|times:9-00am-4-00pm|season:summer-2025|girls_only:0|product_id:12345"

    return md5($signature_string);  // e.g., "a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6"
}
```

### Step 5: Consistent Grouping

The event signature is stored in the `intersoccer_rosters` table:

```sql
CREATE TABLE intersoccer_rosters (
    -- ... other columns ...
    event_signature VARCHAR(32) NOT NULL,
    INDEX idx_event_signature (event_signature)
);
```

Roster queries group by `event_signature`:

```sql
SELECT 
    event_signature,
    venue,
    age_group,
    camp_terms,
    COUNT(DISTINCT order_item_id) as total_players
FROM intersoccer_rosters
WHERE activity_type = 'Camp'
GROUP BY event_signature
```

**Result**: All orders for the same camp (regardless of purchase language) appear in ONE roster.

## Taxonomy Attributes That Require Normalization

### WooCommerce Product Attributes (Taxonomies)

All of these are translatable via WPML and must be normalized:

| Attribute | Taxonomy Key | Example EN | Example FR | Example DE |
|-----------|--------------|------------|------------|------------|
| **Venue** | `pa_intersoccer-venues` | Geneva Centre | Genève Centre | Genf Zentrum |
| **Age Group** | `pa_age-group` | 5-13y (Full Day) | 5-13a (Journée complète) | 5-13J (Ganztägig) |
| **Camp Terms** | `pa_camp-terms` | Summer Week 1 - July 7-July 11 - 5 days | Été Semaine 1 - juillet 7-juillet 11 - 5 jours | Sommer Woche 1 - Juli 7-Juli 11 - 5 Tage |
| **Course Day** | `pa_course-day` | Monday | Lundi | Montag |
| **Camp Times** | `pa_camp-times` | 9:00am-4:00pm | 9h00-16h00 | 9:00-16:00 |
| **Course Times** | `pa_course-times` | 9:00am-4:00pm | 9h00-16h00 | 9:00-16:00 |
| **Season** | `pa_program-season` | Summer 2025 | Été 2025 | Sommer 2025 |
| **City** | `pa_city` | Geneva | Genève | Genf |
| **Canton/Region** | `pa_canton-region` | Geneva | Genève | Genf |

### Non-Taxonomy Fields

These don't require WPML normalization but may need string normalization:

| Field | Handling |
|-------|----------|
| **Activity Type** | Direct value ("Camp", "Course", "Birthday") - check for French/German translations |
| **Product ID** | Integer - no translation needed |
| **Girls Only** | Boolean (0/1) - no translation needed |

## Expected Behavior

### Scenario 1: Three Customers, Same Camp, Different Languages

**Setup:**
- Camp: "Summer Week 1 - July 7-July 11 - 5 days"
- Venue: "Geneva Centre"
- Age Group: "5-13y (Full Day)"
- Product ID: 12345

**Purchases:**
1. **English customer**: Sees and purchases in English
   - Order metadata: "Summer Week 1 - July 7-July 11 - 5 days"
   
2. **French customer**: Sees and purchases in French
   - Order metadata: "Été Semaine 1 - juillet 7-juillet 11 - 5 jours"
   
3. **German customer**: Sees and purchases in German
   - Order metadata: "Sommer Woche 1 - Juli 7-Juli 11 - 5 Tage"

**Expected Result:**
- All three orders have **identical event_signature**: `a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6`
- Roster view shows **ONE camp** with **3 registered players**
- Export shows **ONE row** for this camp

### Scenario 2: Same Product, Different Variations

**Setup:**
- Product: "Summer Camp 2025"
- Variation 1: Week 1 (July 7-11)
- Variation 2: Week 2 (July 14-18)

**Expected Result:**
- Each variation has a **different event_signature**
- Week 1 and Week 2 appear as **separate rosters**
- Language doesn't affect which variation they're grouped with

### Scenario 3: Different Products, Same Event Details

**Setup:**
- Two separate products with identical attributes
- Product A (ID: 12345): Summer Week 1
- Product B (ID: 67890): Summer Week 1

**Expected Result:**
- Different `product_id` in signature → **different event_signatures**
- Each product appears as a **separate roster**
- This prevents confusion between products

## Edge Cases & Considerations

### 1. Term Not Found in Default Language

**Problem**: Customer purchased in French, but the French term doesn't have an English translation in WPML.

**Handling**:
```php
$term = intersoccer_get_term_by_translated_name('Genève Centre', 'pa_intersoccer-venues');
if (!$term) {
    // Fallback: Use the original French name
    $normalized['venue'] = 'Genève Centre';
}
```

**Impact**: Orders with untranslated terms will still group together (same French name), but won't group with properly translated versions.

**Solution**: Ensure ALL taxonomy terms have translations in WPML.

### 2. Manual Season String Without Taxonomy

**Problem**: Season might be a free-text string instead of a taxonomy term.

**Handling**:
```php
// Try taxonomy first
$term = intersoccer_get_term_by_translated_name($season, 'pa_program-season');
if ($term) {
    $normalized['season'] = $term->name;
} else {
    // Manual string replacement fallback
    $normalized['season'] = str_ireplace('Été', 'Summer', $season);
}
```

**Impact**: Works for both taxonomy terms and free-text seasons.

### 3. WPML Not Activated

**Problem**: Site might temporarily run without WPML active.

**Handling**:
```php
if (function_exists('wpml_get_current_language')) {
    // WPML normalization logic
} else {
    // Use values as-is
    $normalized = $event_data;
}
```

**Impact**: If WPML is disabled, system falls back to using original values. Rosters might temporarily split by language until WPML is reactivated.

### 4. Mid-Season Taxonomy Changes

**Problem**: Admin renames a term mid-season (e.g., "Geneva Centre" → "Geneva Central").

**Handling**: The slug should remain the same in WPML. If slug changes:
```php
// Signature uses slugs, not names
'venue' => intersoccer_get_term_slug_by_name($venue, 'pa_intersoccer-venues')
```

**Impact**: Slug changes would create a new event_signature. **Avoid renaming term slugs mid-season.**

### 5. Special Characters & Encoding

**Problem**: Accented characters (è, é, ü, ö) might encode differently.

**Handling**:
```php
// All strings are trimmed and lowercased in signature generation
$signature_string = implode('|', array_map(function($key, $value) {
    return $key . ':' . trim(strtolower($value));
}, ...));
```

**Impact**: Consistent encoding ensures identical signatures.

## Verification & Testing

### Using the Event Signature Verifier Tool

The easiest way to verify multilingual signatures is using the built-in admin tool:

1. **Navigate to**: WP Admin → **InterSoccer** → **Advanced**
2. **Scroll to**: **🔍 Event Signature Verifier** section
3. **Quick Load**: Select a recent event from the dropdown
4. **Test**: Click "Test Signature Generation"
5. **Switch Language**: Change WPML admin language and refresh
6. **Retest**: Load the same event (now in different language)
7. **Verify**: Signatures should be identical

**For detailed instructions, see `SIGNATURE-VERIFIER-USAGE.md`**

### Manual Verification Steps

1. **Create Test Orders in Multiple Languages**:
   - Switch WPML to French
   - Purchase the same product variation
   - Switch WPML to German
   - Purchase the same product variation
   - Switch WPML to English
   - Purchase the same product variation

2. **Check Database**:
   ```sql
   SELECT 
       order_id,
       player_name,
       venue,
       age_group,
       camp_terms,
       event_signature
   FROM intersoccer_rosters
   WHERE product_id = 12345
   ORDER BY event_signature;
   ```

3. **Expected Result**:
   - All three orders have **identical event_signature**
   - `venue`, `age_group`, `camp_terms` might show in different languages in the database
   - But they all have the **same event_signature**

4. **Check Roster View**:
   - Navigate to **InterSoccer > Camps**
   - Find the camp
   - Click "View Roster"
   - Should show **all three orders in ONE roster**

### Debug Logging

Enable detailed logging to track normalization:

```php
// In intersoccer_normalize_event_data_for_signature()
error_log('InterSoccer: Normalized event data for signature: ' . json_encode([
    'original' => $event_data,
    'normalized' => $normalized
]));

// In intersoccer_generate_event_signature()
error_log('InterSoccer: Generated normalized event signature: ' . $signature . 
    ' from components: ' . json_encode($normalized_components));
```

Check `wp-content/debug.log` for entries like:
```
[2025-11-03 14:23:45] InterSoccer: Normalized event data for signature: {
    "original": {
        "venue": "Genève Centre",
        "camp_terms": "Été Semaine 1 - juillet 7-juillet 11 - 5 jours"
    },
    "normalized": {
        "venue": "Geneva Centre",
        "camp_terms": "Summer Week 1 - July 7-July 11 - 5 days"
    }
}
[2025-11-03 14:23:45] InterSoccer: Generated normalized event signature: a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6
```

### Automated Testing (Future Enhancement)

Create a PHPUnit test suite:

```php
public function test_event_signature_identical_across_languages() {
    // Given: Same product variation
    $product_id = 12345;
    $variation_id = 67890;
    
    // When: Creating event data in different languages
    $event_data_en = ['venue' => 'Geneva Centre', ...];
    $event_data_fr = ['venue' => 'Genève Centre', ...];
    $event_data_de = ['venue' => 'Genf Zentrum', ...];
    
    // Normalize all three
    $normalized_en = intersoccer_normalize_event_data_for_signature($event_data_en);
    $normalized_fr = intersoccer_normalize_event_data_for_signature($event_data_fr);
    $normalized_de = intersoccer_normalize_event_data_for_signature($event_data_de);
    
    // Then: All should have identical normalized values
    $this->assertEquals($normalized_en, $normalized_fr);
    $this->assertEquals($normalized_en, $normalized_de);
    
    // And: All should generate identical signatures
    $sig_en = intersoccer_generate_event_signature($normalized_en);
    $sig_fr = intersoccer_generate_event_signature($normalized_fr);
    $sig_de = intersoccer_generate_event_signature($normalized_de);
    
    $this->assertEquals($sig_en, $sig_fr);
    $this->assertEquals($sig_en, $sig_de);
}
```

## Maintenance & Best Practices

### For Developers

1. **Never bypass normalization**: Always use `intersoccer_normalize_event_data_for_signature()` before generating signatures
2. **Test with WPML active**: Normalization only works when WPML is properly configured
3. **Log liberally**: Debug logs are essential for troubleshooting multilingual issues
4. **Verify term translations**: Ensure all taxonomy terms have complete WPML translations

### For Site Administrators

1. **Complete WPML translations**: Every taxonomy term must be translated to ALL active languages
2. **Consistent term slugs**: Never change term slugs mid-season
3. **Test before season launch**: Create test orders in all languages and verify they group correctly
4. **Monitor debug logs**: Check for normalization warnings or fallbacks

### For Content Managers

1. **Use taxonomy terms**: Prefer taxonomy-based attributes over free-text fields
2. **Consistent naming**: Keep attribute values consistent across translations
3. **Verify translations**: After adding new terms, verify they appear correctly in all languages

## Files Involved

| File | Purpose |
|------|---------|
| `includes/utils.php` | Core normalization and signature generation functions |
| `includes/rosters.php` | Roster queries that group by `event_signature` |
| `includes/roster-details.php` | Detail views filtered by `event_signature` |
| `intersoccer-reports-rosters.php` | Database schema with `event_signature` column |

## Related Documentation

- **WPML Configuration**: See WPML setup guide for taxonomy translation
- **Roster Generation**: See main plugin documentation for roster logic
- **Database Schema**: See database upgrade scripts for `event_signature` column

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2025-11-03 | Initial documentation of multilingual event signature system |

---

**Last Updated**: November 3, 2025  
**Author**: InterSoccer Development Team  
**Status**: Active Implementation

