# Unified Date Parser Implementation

## Summary

A unified date parser function has been implemented to handle all date formats found in order item metadata. This replaces inconsistent parsing logic across multiple files and fixes the root cause of malformed dates (e.g., `0025-09-08`, `0026-05-08`).

## Changes Made

### 1. New Function: `intersoccer_parse_date_unified()`

**Location**: `includes/utils.php` (lines 48-186)

**Purpose**: Single source of truth for date parsing across the entire plugin.

**Features**:
- Handles 11 different date formats in priority order
- Validates years (1900-2100 range)
- Correctly handles 2-digit years (assumes 20XX)
- Prevents false positives with strict format matching
- Comprehensive error logging with context

**Supported Formats** (in priority order):
1. `F j, Y` - "August 17, 2025" (most common, unambiguous)
2. `M j, Y` - "Aug 17, 2025" (abbreviated month)
3. `Y-m-d` - "2025-08-17" (ISO format)
4. `d/m/Y` - "17/08/2025" (European format - tried before m/d/Y)
5. `m/d/Y` - "08/17/2025" (American format)
6. `d/m/y` - "17/08/25" (European 2-digit year - **CRITICAL FIX**)
7. `m/d/y` - "08/17/25" (American 2-digit year)
8. `j F Y` - "17 August 2025" (alternative format)
9. `d-m-Y` - "17-08-2025" (European with dashes)
10. `m-d-Y` - "08-17-2025" (American with dashes)
11. `Y/m/d` - "2025/08/17" (ISO with slashes)
12. `strtotime()` - Last resort fallback

**Key Fixes**:
- **2-digit year handling**: Dates like "09/08/25" are now correctly parsed as day 09, month 08, year 2025 (not 0025)
- **Format order**: `d/m/Y` is tried before `m/d/Y` to correctly handle European dates
- **Year validation**: Rejects years < 1900 or > 2100
- **Strict matching**: Prevents false positives by requiring exact format matches

### 2. Updated Files

#### `includes/utils.php` (lines 418-432)
- **Before**: Limited parsing with only `F j, Y` and `m/d/Y` formats
- **After**: Uses `intersoccer_parse_date_unified()` for all date parsing
- **Impact**: Now handles all date formats, including 2-digit years

#### `includes/db.php` (lines 719-732)
- **Before**: Tried 6 formats but in wrong order (`m/d/Y` before `d/m/Y`)
- **After**: Uses `intersoccer_parse_date_unified()` with proper format priority
- **Impact**: Fixes malformed dates from European format dates

#### `classes/services/roster-builder.php` (lines 1099-1123)
- **Before**: Limited format support, missing `F j, Y` and 2-digit year formats
- **After**: Uses `intersoccer_parse_date_unified()` for consistency
- **Impact**: OOP service now uses same parsing logic as legacy code

## Root Cause Fixes

### Problem 1: Malformed Years (0025, 0026, 0027)
**Root Cause**: Dates like "09/08/25" were parsed with `m/d/Y` format, interpreting as month=09, day=08, year=0025.

**Fix**: 
- Added `d/m/y` format support (tried before `m/d/Y`)
- Correctly interprets as day=09, month=08, year=2025
- Validates and corrects 2-digit years to 20XX

### Problem 2: European Date Format Issues
**Root Cause**: `m/d/Y` was tried before `d/m/Y`, causing "17/08/2025" to be incorrectly parsed.

**Fix**: 
- `d/m/Y` is now tried before `m/d/Y` in the format priority list
- Strict format matching prevents false positives

### Problem 3: Inconsistent Parsing Logic
**Root Cause**: Three different files had different parsing logic with different format lists.

**Fix**: 
- Single unified function used everywhere
- Consistent behavior across all code paths

## Testing Recommendations

### Test Cases to Verify

1. **2-digit year dates** (critical fix):
   - "09/08/25" → Should parse to "2025-08-09" (not "0025-09-08")
   - "17/08/25" → Should parse to "2025-08-17" (not "0026-05-08")

2. **European format dates**:
   - "17/08/2025" → Should parse to "2025-08-17"
   - "21/12/2025" → Should parse to "2025-12-21"

3. **Most common format**:
   - "August 17, 2025" → Should parse to "2025-08-17"
   - "December 14, 2025" → Should parse to "2025-12-14"

4. **Edge cases**:
   - Invalid dates should return `null`
   - Years < 1900 or > 2100 should be rejected
   - Empty/null strings should return `null`

### How to Test

1. **Reconcile Rosters**: Run "Reconcile Rosters" operation to reprocess existing orders
2. **Check Database**: Query for dates starting with "00" - should be fixed
3. **New Orders**: Process new orders and verify dates are correct
4. **Error Logs**: Check `debug.log` for parsing messages

## Migration Notes

### Existing Data

Existing malformed dates in the database (e.g., `0025-09-08`) will be fixed when:
- "Reconcile Rosters" is run
- Orders are reprocessed
- New roster entries are created

### Backward Compatibility

The function is backward compatible:
- All previously working date formats still work
- Additional formats are now supported
- No breaking changes to existing functionality

## Next Steps

1. ✅ **Implementation Complete** - Unified parser created and integrated
2. ⏭️ **Test on Staging** - Verify with real order data
3. ⏭️ **Reconcile Rosters** - Run on production to fix existing malformed dates
4. ⏭️ **Monitor Logs** - Check for any parsing failures
5. ⏭️ **Data Validation** - Verify dates are correct after reconciliation

## Files Modified

- `includes/utils.php` - Added unified parser function, updated date parsing
- `includes/db.php` - Updated to use unified parser
- `classes/services/roster-builder.php` - Updated to use unified parser

## Related Documentation

- `METADATA-DATE-ANALYSIS.md` - Analysis of date formats found in metadata
- `ANALYSIS-END-DATE-ISSUES.md` - Original analysis of date issues
- `investigate-order-metadata-dates.sql` - SQL queries for date investigation

