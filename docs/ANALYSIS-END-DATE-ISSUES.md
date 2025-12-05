# Analysis: End Date Issues in intersoccer_rosters Table

## Summary of Findings from debug.log

Based on the SQL query results, there are **multiple critical issues** with end dates:

### Issue #1: Malformed Dates in Database
**Problem**: Dates are being stored with incorrect years:
- `0026-05-08` instead of `2026-05-08`
- `0027-07-08` instead of `2027-07-08`
- `0025-09-08` instead of `2025-09-08`

**Root Cause**: The date parsing logic in `includes/utils.php` (lines 281-300) and `includes/db.php` (lines 728-742) uses `DateTime::createFromFormat()` but doesn't properly validate the result before formatting. When parsing fails, it may create malformed dates.

**Evidence from debug.log**:
- Line 28: `earliest_start = 0026-05-08` (should be `2026-05-08`)
- Line 30: `earliest_start = 0027-07-08` (should be `2027-07-08`)
- Line 31: `earliest_start = 0025-09-08` (should be `2025-09-08`)

### Issue #2: Multiple Different End Dates for Same Course
**Problem**: The same `event_signature` (same course) has multiple different end dates stored in different roster entries.

**Evidence from debug.log**:
- Line 28: `all_end_dates = 2025-07-12,2025-12-01,2025-12-07,2025-12-08,2025-12-22,2026-09-12` (6 different end dates!)
- Line 30: `all_end_dates = 2025-12-15,2025-12-21,2026-09-12` (3 different end dates)
- Line 32: `all_end_dates = 2025-12-20,2025-12-22,2025-12-27,2026-09-12,2027-03-12` (5 different end dates, including 2027!)

**Why This Happens**:
1. Different orders registered at different times may have different "End Date" values in their metadata
2. The course end date might have been updated in the product/variation between registrations
3. Date parsing may be extracting different values from metadata (e.g., "Course End Date" vs "End Date")
4. The date parsing logic may be failing for some orders and succeeding for others

### Issue #3: Current Code Takes Wrong End Date
**Problem**: The current code uses `GROUP_CONCAT(DISTINCT r.end_date)` and takes the first element, which is often the **earliest** end date, not the **latest** (correct) one.

**Evidence from debug.log** (Query #10):
- Line 409: `current_code_end = 2025-12-15` but `should_show_end = 2026-09-12` ❌
- Line 410: `current_code_end = 2025-07-12` but `should_show_end = 2026-09-12` ❌
- Line 411: `current_code_end = 2025-12-01` but `should_show_end = 2026-03-12` ❌
- Line 412: `current_code_end = 2025-11-07` but `should_show_end = 2026-05-12` ❌

**All 30 courses with inconsistencies show `comparison = 'DIFFERENT'`**, meaning the current code is displaying the wrong end date.

### Issue #4: Dates in Wrong Years (2027)
**Problem**: Some courses have end dates in 2027, which seems incorrect for "Autumn 2025" courses.

**Evidence from debug.log** (Query #4):
- Line 157-162: Multiple courses with `end_year = 2027`
- Line 159: `end_dates = 2027-03-12` for an "Autumn 2025" course
- Line 160-162: Courses with `end_dates = 2027-05-06` or `2027-06-06`

**Possible Causes**:
1. Date parsing is misinterpreting the year (e.g., "May 6" being parsed as "2027-05-06" instead of "2025-05-06")
2. The metadata contains incorrect dates
3. Date calculation logic is adding wrong number of days/weeks

## Root Cause Analysis

### Date Parsing Logic Issues

**Location**: `includes/utils.php` lines 279-300

```php
$start_date_obj = DateTime::createFromFormat('F j, Y', $start_date);
$end_date_obj = DateTime::createFromFormat('F j, Y', $end_date);
if ($start_date_obj && $end_date_obj) {
    $start_date = $start_date_obj->format('Y-m-d');
    $end_date = $end_date_obj->format('Y-m-d');
```

**Problems**:
1. **No validation of parsed dates**: If `createFromFormat()` returns `false`, the code doesn't check before calling `format()`, which could cause issues
2. **Limited format support**: Only tries `'F j, Y'` (e.g., "December 14, 2025") and `'m/d/Y'` formats
3. **No fallback for partial dates**: If the date string is malformed or incomplete, it defaults to `1970-01-01` but doesn't log why
4. **No year validation**: Doesn't check if the parsed year makes sense (e.g., 2027 for a 2025 course)

**Location**: `includes/db.php` lines 728-742

Similar issues - tries multiple formats but doesn't validate results properly.

### Why Multiple End Dates Exist

1. **Different metadata sources**: Different orders may have end dates from:
   - Product variation metadata (`_course_end_date`)
   - Order item metadata (`End Date`)
   - Calculated dates (course start date + duration)

2. **Date updates over time**: If a course's end date is updated in the product between registrations, older orders will have the old date, newer orders will have the new date.

3. **Date parsing inconsistencies**: Some dates parse correctly, others don't, leading to different stored values.

## Recommendations

### Immediate Fixes Needed

1. **Fix date parsing validation**: Add proper error checking after `DateTime::createFromFormat()`
2. **Add more date format support**: Support additional formats that might be in the metadata
3. **Add year validation**: Check if parsed year is reasonable (e.g., within 2 years of current year)
4. **Fix the display query**: Change from `GROUP_CONCAT` to `MIN(start_date)` and `MAX(end_date)`
5. **Add logging**: Log when date parsing fails or produces unexpected results

### Data Cleanup Needed

1. **Identify malformed dates**: Find all dates with years like `0026`, `0027`, `0025`
2. **Fix malformed dates**: Update them to correct years (likely `2026`, `2027`, `2025`)
3. **Standardize end dates**: For courses with multiple end dates, determine the correct one and update all entries

### Long-term Improvements

1. **Standardize date source**: Always use the same source for course end dates (e.g., product variation metadata)
2. **Add date validation**: Validate dates before storing them
3. **Add data integrity checks**: Periodic checks to ensure all roster entries for the same course have consistent dates

## Next Steps

1. **Examine actual order item metadata** to see what date formats are being stored
2. **Check date parsing logic** to see why it's creating malformed dates
3. **Identify the correct end date source** (product metadata vs order metadata)
4. **Fix the date parsing** to handle all formats correctly
5. **Update the display query** to use MIN/MAX instead of GROUP_CONCAT
6. **Create a data cleanup script** to fix existing malformed dates

