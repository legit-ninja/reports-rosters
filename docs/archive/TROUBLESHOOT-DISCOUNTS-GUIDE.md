# InterSoccer Discount Detection Troubleshooting Guide

## Overview
This guide helps diagnose why InterSoccer discounts are not being detected correctly in the booking report.

## Prerequisites
- Access to your WordPress database (via phpMyAdmin, MySQL command line, or similar)
- Know your WordPress table prefix (default is `wp_`)

## Important Notes
- **Replace `wp_` with your actual table prefix** in all queries if different
- **Replace `ORDER_ID`** in Query 7 with an actual order ID from your system
- Run queries on a **staging/test database** first if possible

## Query Descriptions

### Query 1: Check if Discount Metadata Exists
**Purpose**: Identifies which order items have `_intersoccer_item_discounts` metadata stored.

**What to Look For**:
- If no results: Discount metadata is not being stored at all
- If results exist: Check the `value_preview` to see the data format
- Check `value_length` - if it's very short, the data might be truncated

**Expected Result**: Should show serialized array data starting with `a:` (array) or `O:` (object)

---

### Query 2: Check Total Discount Metadata
**Purpose**: Shows order items with stored total discount amounts and their breakdowns.

**What to Look For**:
- `total_discount` should match the actual discount amount
- `breakdown_preview` should show discount names and types
- If `total_discount` is 0 but breakdown exists, there's a calculation issue

**Expected Result**: Should show discount amounts > 0 and breakdown arrays

---

### Query 3: Find Orders with Price Differences
**Purpose**: Identifies orders that have discounts (price difference) but may be missing metadata.

**What to Look For**:
- `calculated_discount` > 0 but `stored_total_discount` is NULL = Missing metadata
- `calculated_discount` != `stored_total_discount` = Data mismatch
- `metadata_status` = 'NO METADATA' = Discounts exist but not stored

**Expected Result**: Should help identify orders where discounts exist but metadata is missing

---

### Query 4: Check Discount Data Format
**Purpose**: Examines the raw format of stored discount breakdown data.

**What to Look For**:
- `first_char` = 'a' (ASCII 97) = PHP serialized array
- `first_char` = 'O' (ASCII 79) = PHP serialized object
- `first_char` = '{' = JSON format
- `first_char` = '[' = JSON array format
- If different, the data might be in an unexpected format

**Expected Result**: Should start with 'a' (PHP serialized array) for WooCommerce metadata

---

### Query 5: Count Orders with vs without Discount Metadata
**Purpose**: Provides a high-level overview of the discount metadata situation.

**What to Look For**:
- High count of "Has Discount (No Metadata)" = Metadata not being stored
- High count of "Has Discount Metadata" = Metadata exists, detection issue
- Compare `total_discount_calculated` vs `total_discount_metadata` for discrepancies

**Expected Result**: Should show distribution of orders with/without metadata

---

### Query 6: Find Recent Orders with Discounts
**Purpose**: Identifies recent orders that should have discount metadata for examination.

**What to Look For**:
- Orders with `discount` > 0 but `stored_discount_metadata` is NULL
- Recent orders to check if the issue is ongoing or historical
- Order dates to see if issue started at a specific time

**Expected Result**: List of recent orders with discounts to investigate

---

### Query 7: Check All Discount Metadata for Specific Order
**Purpose**: Shows all discount-related metadata keys for a specific order item.

**What to Look For**:
- All metadata keys containing "discount" or "Discount"
- Check if keys are named differently than expected
- Verify data format and content

**How to Use**:
1. Find an order ID from Query 6 that has discounts
2. Replace `ORDER_ID` in the query with that order ID
3. Run the query to see all discount-related metadata

**Expected Result**: Should show `_intersoccer_item_discounts` and `_intersoccer_total_item_discount` keys

---

### Query 8: Find Alternative Discount Metadata Keys
**Purpose**: Discovers if discount metadata is stored under different key names.

**What to Look For**:
- Keys with similar names (e.g., `intersoccer_discounts`, `item_discounts`)
- Keys with different capitalization
- Keys with underscores vs hyphens

**Expected Result**: Should show all discount-related metadata keys in use

---

### Query 9: Compare Calculated vs Stored Discounts
**Purpose**: Identifies discrepancies between calculated discounts and stored metadata.

**What to Look For**:
- `difference` > 0.01 = Mismatch between calculated and stored
- Large differences indicate calculation or storage issues
- Small differences might be rounding issues

**Expected Result**: Should show minimal differences (within rounding tolerance)

---

### Query 10: Check Empty or Zero Discount Metadata
**Purpose**: Identifies cases where metadata keys exist but have no meaningful value.

**What to Look For**:
- `value_status` = 'NULL' = Key doesn't exist
- `value_status` = 'EMPTY STRING' = Key exists but empty
- `value_status` = 'ZERO' = Key exists but value is 0
- `value_status` = 'HAS VALUE' = Key exists with valid data

**Expected Result**: Should identify why metadata exists but isn't being used

---

## Common Issues and Solutions

### Issue 1: No Discount Metadata Found
**Symptoms**: Query 1 and Query 2 return no results
**Possible Causes**:
- Product Variations plugin not storing metadata
- Plugin version mismatch
- Checkout process not completing properly

**Next Steps**:
- Verify Product Variations plugin is active
- Check plugin version compatibility
- Review checkout logs for errors

### Issue 2: Metadata Exists but Wrong Format
**Symptoms**: Query 4 shows unexpected format
**Possible Causes**:
- Data stored in JSON instead of PHP serialized format
- Data corrupted during storage
- Different plugin version storing different format

**Next Steps**:
- Check Product Variations plugin version
- Verify WooCommerce version compatibility
- Check for data corruption

### Issue 3: Metadata Exists but Detection Fails
**Symptoms**: Query 2 shows data but report doesn't detect it
**Possible Causes**:
- unserialize() failing
- Array structure different than expected
- Data type mismatch

**Next Steps**:
- Check Query 4 for data format
- Verify array structure matches expected format
- Test unserialize() on sample data

### Issue 4: Partial Metadata (Total but No Breakdown)
**Symptoms**: Query 2 shows `total_discount` but `breakdown` is NULL
**Possible Causes**:
- Only total stored, breakdown not stored
- Breakdown stored under different key
- Breakdown storage failed

**Next Steps**:
- Check Query 8 for alternative keys
- Verify Product Variations plugin stores both
- Check for storage errors in logs

---

## Next Steps After Running Queries

1. **Document Findings**: Note which queries show issues
2. **Identify Pattern**: Is the issue affecting all orders or specific ones?
3. **Check Timing**: When did the issue start? (Compare old vs new orders)
4. **Verify Plugin**: Ensure Product Variations plugin is working correctly
5. **Share Results**: Provide query results for code fixes

---

## Sample Expected Data Format

When discount metadata is stored correctly, `_intersoccer_item_discounts` should contain:
```
a:1:{i:0;a:3:{s:4:"name";s:25:"20% Camp Sibling Discount";s:4:"type";s:7:"sibling";s:6:"amount";d:45.5;}}
```

This is a PHP serialized array with structure:
```php
[
    [
        'name' => '20% Camp Sibling Discount',
        'type' => 'sibling',
        'amount' => 45.50
    ]
]
```

