# Order Item Metadata Date Format Analysis

## Summary of Findings

Based on the SQL investigation queries, we've identified the root causes of the date parsing issues.

## Date Formats Found in Metadata

### 1. **Primary Format: `F j, Y` (Most Common)**
- Examples: "August 17, 2025", "December 14, 2025", "September 5, 2025"
- **Count**: ~1,000+ occurrences
- **Status**: ✅ Should parse correctly with `DateTime::createFromFormat('F j, Y', $date)`
- **Used in**: Both Start Date and End Date metadata

### 2. **Secondary Format: `d/m/Y` (European Format)**
- Examples: "17/08/2025", "21/12/2025", "14/12/2025"
- **Count**: ~200+ occurrences
- **Status**: ⚠️ **PROBLEMATIC** - Ambiguous with `m/d/Y` format
- **Issue**: When code tries `m/d/Y` first, it incorrectly interprets "17/08/2025" as month 17, day 08, year 2025 → fails or creates invalid dates

### 3. **Problematic Format: 2-Digit Year `d/m/y` or `m/d/y`**
- Examples: "09/08/25", "17/08/25", "02/03/25"
- **Count**: ~30+ occurrences
- **Status**: ❌ **CRITICAL ISSUE** - Not handled by current parsing logic
- **Problem**: 
  - "09/08/25" is being parsed as `m/d/Y` → month 09, day 08, year 0025 → **0025-09-08** (malformed year!)
  - Should be parsed as `d/m/y` → day 09, month 08, year 2025 → **2025-08-09**
  - This is the source of malformed dates like `0025-09-08`, `0026-05-08`, `0027-06-08`

## Root Cause Analysis

### Issue #1: Format Order Matters
**Location**: `includes/db.php` line 724
```php
$possible_formats = ['F j, Y', 'm/d/Y', 'd/m/Y', 'Y-m-d', 'j F Y', 'M j, Y'];
```

**Problem**: 
- When parsing "09/08/25", it tries `m/d/Y` first
- `DateTime::createFromFormat('m/d/Y', '09/08/25')` interprets as: month=09, day=08, year=0025
- This creates malformed dates like `0025-09-08`
- The code never tries `d/m/y` (2-digit year) format

### Issue #2: Missing 2-Digit Year Support
**Location**: All date parsing functions

**Problem**:
- No format strings for 2-digit years: `'d/m/y'`, `'m/d/y'`
- Dates like "09/08/25" cannot be correctly parsed
- `strtotime()` might work but is unreliable for ambiguous dates

### Issue #3: Ambiguous Date Interpretation
**Location**: `includes/utils.php` line 288

**Problem**:
- Only tries `'F j, Y'` then `'m/d/Y'`
- Never tries `'d/m/Y'` or 2-digit year formats
- European dates (d/m/Y) are incorrectly parsed as American dates (m/d/Y)

### Issue #4: Inconsistent Parsing Logic
**Location**: Multiple files with different parsing approaches

**Files with date parsing**:
1. `includes/utils.php` - Lines 281-300 (limited formats)
2. `includes/db.php` - Lines 724-742 (more formats, wrong order)
3. `classes/services/roster-builder.php` - Lines 1099-1123 (missing `F j, Y` format)

**Problem**: Each file has different format lists and orders, leading to inconsistent results.

## Evidence from SQL Queries

### Query #3 Results (Malformed Dates)
- 37 entries with malformed start dates (starting with `00`)
- Pattern: Metadata has "09/08/25" → Stored as "0025-09-08"
- Pattern: Metadata has "17/08/25" → Stored as "0026-05-08" (incorrect parsing)

### Query #4 Results (Date Comparison)
- Older orders (registered before August 2025) have `d/m/Y` format dates
- Newer orders (registered after August 2025) have `F j, Y` format dates
- This suggests a change in how dates are stored in metadata over time

### Query #7 Results (Metadata Inconsistencies)
- Some courses have multiple different end dates in metadata
- Example: `geneva-stade-chenois-thonex` has 6 different end dates:
  - "07/12/2025", "21/12/2025", "December 1, 2025", "December 22, 2025", "December 7, 2025", "December 8, 2025"
- This indicates the metadata itself may have inconsistencies, but the parsing is also contributing to the problem

### Query #10 Results (Format Distribution)
- Top format: `F j, Y` (e.g., "August 17, 2025") - 152 occurrences for Start Date
- Second: `d/m/Y` format (e.g., "17/08/2025") - 44 occurrences
- Problematic: 2-digit year format (e.g., "09/08/25") - 10+ occurrences

## Recommended Fix Strategy

### 1. **Create a Unified Date Parser Function**
- Single source of truth for date parsing
- Handle all formats found in metadata
- Proper format order (most specific first, then fallbacks)

### 2. **Format Priority Order**
1. `'F j, Y'` - "August 17, 2025" (most common, unambiguous)
2. `'M j, Y'` - "Aug 17, 2025" (abbreviated month)
3. `'Y-m-d'` - "2025-08-17" (ISO format, unambiguous)
4. `'d/m/Y'` - "17/08/2025" (European format, try before m/d/Y)
5. `'m/d/Y'` - "08/17/2025" (American format)
6. `'d/m/y'` - "17/08/25" (European 2-digit year)
7. `'m/d/y'` - "08/17/25" (American 2-digit year)
8. `strtotime()` - Last resort fallback

### 3. **Year Validation**
- After parsing, validate year is reasonable (e.g., 2000-2100)
- If year < 100, assume it's 20XX (e.g., 25 → 2025)
- Reject dates with year < 1900 or > 2100

### 4. **Date Context Validation**
- For courses: Validate start_date < end_date
- Validate dates are within reasonable range for the season
- Log warnings for dates that seem incorrect

### 5. **Consolidate Parsing Logic**
- Use the unified parser in all three locations:
  - `includes/utils.php`
  - `includes/db.php`
  - `classes/services/roster-builder.php`

## Next Steps

1. ✅ **Investigation Complete** - We now understand the date formats in use
2. ⏭️ **Implement Unified Parser** - Create a single, robust date parsing function
3. ⏭️ **Update All Parsing Locations** - Replace existing parsing with unified function
4. ⏭️ **Add Validation** - Validate parsed dates before storing
5. ⏭️ **Test with Real Data** - Verify fixes work with actual metadata formats
6. ⏭️ **Data Cleanup** - Optionally fix existing malformed dates in database

