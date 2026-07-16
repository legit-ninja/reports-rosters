# Troubleshooting Courses Roster Date Issues

## Problem
Dates displayed on Courses Roster cards are showing incorrect years (2025 vs 2027).

## Root Cause Analysis

The issue occurs because:
1. Multiple roster entries share the same `event_signature` (same course)
2. These entries may have different `start_date` and `end_date` values
3. The current code uses `GROUP_CONCAT(DISTINCT r.start_date)` which concatenates all dates
4. It then takes the first element from the comma-separated string
5. The order of dates in `GROUP_CONCAT` is not guaranteed, so the "first" date may be wrong

## SQL Troubleshooting Queries

Use the queries in `troubleshoot-course-dates.sql` to identify problematic records.

### Quick Start

1. **Find courses with multiple dates** (Query #1):
   - Shows which courses have inconsistent dates
   - Identifies `event_signature` values with multiple start/end dates

2. **Compare current vs correct approach** (Query #3):
   - Shows what `GROUP_CONCAT` returns vs what `MIN/MAX` would return
   - Demonstrates the exact problem

3. **Find 2027 dates** (Query #4):
   - Identifies courses with dates in 2027 (likely incorrect)

4. **See the difference** (Query #10):
   - Shows what current code displays vs what it should display
   - Highlights courses where dates are wrong

### Important Notes

- **Table Prefix**: Replace `wp_` with your actual WordPress table prefix if different
- **Run queries one at a time** to avoid overwhelming the database
- **Start with Query #1** to get an overview of the problem
- **Use Query #2** with a specific `event_signature` to drill down

## Expected Results

### Query #1 Should Show:
- Courses with `distinct_start_dates > 1` or `distinct_end_dates > 1`
- The `all_start_dates` and `all_end_dates` columns show all dates concatenated
- Compare `earliest_start` vs `latest_start` to see the date range

### Query #3 Should Show:
- `group_concat_start_dates`: What current code gets (unordered)
- `ordered_start_dates`: What GROUP_CONCAT returns when ordered
- `first_start_date`: What current code uses (first element)
- `min_start_date`: What it should use (earliest date)
- If `first_start_date != min_start_date`, that's the problem!

### Query #10 Should Show:
- `current_code_start` vs `should_show_start`: The difference
- `comparison = 'DIFFERENT'`: Courses where dates are wrong

## Next Steps

Once you've identified the problematic records:

1. **Verify the data**: Check if the dates in the database are correct
2. **Identify the source**: Determine why multiple dates exist for the same course
3. **Fix the code**: Change from `GROUP_CONCAT` to `MIN/MAX` approach
4. **Clean up data** (if needed): Update inconsistent dates in the database

## Code Fix Location

The fix should be applied in:
- File: `includes/rosters.php`
- Function: `intersoccer_render_courses_page()`
- Lines: ~1343-1344 (SQL query) and ~1417-1427 (processing logic)

Change from:
```sql
GROUP_CONCAT(DISTINCT r.start_date) as start_dates,
GROUP_CONCAT(DISTINCT r.end_date) as end_dates
```

To:
```sql
MIN(r.start_date) as start_date,
MAX(r.end_date) as end_date
```

