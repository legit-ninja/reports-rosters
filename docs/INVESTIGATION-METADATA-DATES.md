# Investigation: Order Item Metadata Date Formats

## Purpose
Before implementing fixes for the end date issues, we need to understand:
1. What date formats are actually stored in order item metadata
2. Whether the metadata itself has inconsistencies
3. How the parsing logic is handling (or failing to handle) these formats
4. Why malformed dates like `0026-05-08` are being created

## SQL Queries to Run

Use the queries in `investigate-order-metadata-dates.sql` to examine the actual data.

### Key Queries

**Query #1**: Shows actual date values and detected formats
- See what date strings are stored in metadata
- Identify which formats are most common

**Query #2**: Groups by format to see distribution
- Shows which date formats are used most frequently
- Helps prioritize which formats to support

**Query #3**: Finds problematic dates
- Compares metadata dates with stored dates
- Identifies entries with malformed years (0026, 0027, etc.)

**Query #4**: Detailed comparison for courses with issues
- Shows metadata vs stored dates side-by-side
- Includes registration dates to see if dates changed over time

**Query #5 & #6**: Unique date formats for Start/End dates
- Lists all unique date strings found
- Shows how many times each format appears

**Query #7**: Checks if metadata itself has inconsistencies
- Shows if different orders for the same course have different end dates in metadata
- Helps determine if the issue is in metadata or parsing

**Query #8**: Complete date-related metadata for sample orders
- Shows all date-related metadata keys and values
- Helps identify if we're missing any date sources

**Query #9**: Finds all date-related metadata keys
- Identifies any date metadata keys we might not be using
- Ensures we're not missing important date sources

**Query #10**: Parsing analysis
- Shows which formats should parse correctly
- Identifies formats that might fail

## What to Look For

### Expected Findings

1. **Date Format Distribution**: 
   - Most likely: `'F j, Y'` format (e.g., "December 14, 2025")
   - Possibly: `'m/d/Y'` format (e.g., "12/14/2025")
   - Maybe: Already in `'Y-m-d'` format

2. **Malformed Date Patterns**:
   - Dates starting with `00` (e.g., `0026-05-08`)
   - These suggest parsing failures or incorrect format assumptions

3. **Metadata Inconsistencies**:
   - Same course having different end dates in metadata
   - This would explain why roster entries have different dates

4. **Missing Date Sources**:
   - Other metadata keys that contain dates we're not using
   - Alternative date sources we should consider

## Next Steps After Investigation

Once you've run these queries and reviewed the results:

1. **Identify the most common date formats** - We'll prioritize supporting these
2. **Understand why malformed dates are created** - Fix the parsing logic
3. **Determine if metadata has inconsistencies** - Decide if we need data cleanup
4. **Choose the correct date source** - If multiple sources exist, pick the most reliable one
5. **Implement robust date parsing** - Support all formats found in the data

## Questions to Answer

1. What is the most common date format in the metadata?
2. Are there multiple date formats being used?
3. Why are dates like `0026-05-08` being created?
4. Do different orders for the same course have different end dates in metadata?
5. Are we using the correct metadata keys for dates?
6. Is there a pattern to which dates are wrong (e.g., only certain date formats)?

