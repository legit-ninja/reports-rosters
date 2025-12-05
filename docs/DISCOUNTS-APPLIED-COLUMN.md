# Discounts Applied Column - Implementation

## Overview
Added a new "Discounts Applied" column to the booking report that displays all discounts applied to each order item, combining both InterSoccer discounts and WooCommerce coupon codes.

## Implementation Details

### Column Content
The "Discounts Applied" column shows:
1. **InterSoccer Discounts**: Discounts stored in order item metadata (e.g., "Sibling Discount", "Same Season Discount") with their amounts
2. **WooCommerce Coupon Codes**: Coupon codes applied to the order (e.g., "Coupon: SUMMER2024")

### Format
- Multiple discounts are separated by semicolons (`;`)
- InterSoccer discounts show the discount name followed by the amount in parentheses
- Example: `20% Camp Sibling Discount (45.00 CHF); Coupon: SUMMER2024`
- If no discounts are applied, displays: `None`

### Data Sources

#### 1. InterSoccer Discounts
- **Source**: Order item metadata key `_intersoccer_item_discounts`
- **Format**: Serialized array containing discount objects with:
  - `name`: Discount name/description (e.g., "20% Camp Sibling Discount")
  - `type`: Discount type (sibling, same_season, coupon, etc.)
  - `amount`: Discount amount in CHF
- **Stored by**: Product Variations plugin during checkout

#### 2. WooCommerce Coupon Codes
- **Source**: WooCommerce order items with `order_item_type = 'coupon'`
- **Format**: Coupon code names from the order
- **Prefix**: All coupon codes are prefixed with "Coupon: " for clarity

## Files Modified

### 1. `includes/reporting-discounts.php`
- **Function**: `intersoccer_get_financial_booking_report()`
- **Changes**:
  - Extracts InterSoccer discount breakdown from `_intersoccer_item_discounts` metadata
  - Formats discount names with amounts
  - Combines InterSoccer discounts and coupon codes
  - Adds `discounts_applied` field to report data array

### 2. `classes/services/financial-report-service.php`
- **Method**: `getFinancialBookingReport()`
- **Changes**:
  - Same logic as legacy function for consistency
  - Uses stored discount metadata when available
  - Falls back to price difference calculation if metadata not available
  - Adds `discounts_applied` field to report data array

### 3. `includes/reports.php`
- **Changes**:
  - Added `discounts_applied` to column definitions
  - Added column header for Excel export
  - Column appears in both table view and Excel export

### 4. `includes/reports-ajax.php`
- **Changes**:
  - Added `discounts_applied` to column definitions for AJAX table rendering

## Column Display

### Table View
- Column header: "Discounts Applied"
- Shows formatted discount information for each booking
- Can be toggled on/off via column selector

### Excel Export
- Column included in exported Excel files
- Same formatting as table view
- Appears after "Total Discount (CHF)" column

## Example Output

### Single InterSoccer Discount
```
20% Camp Sibling Discount (45.00 CHF)
```

### Multiple Discounts
```
20% Course Sibling Discount (30.00 CHF); 50% Same Season Course Discount (75.00 CHF)
```

### With Coupon Code
```
20% Camp Sibling Discount (45.00 CHF); Coupon: SUMMER2024
```

### No Discounts
```
None
```

## Benefits

1. **Complete Discount Visibility**: Shows all discounts applied, not just the total amount
2. **Discount Type Identification**: Clearly identifies InterSoccer discounts vs. coupon codes
3. **Amount Transparency**: Shows individual discount amounts for detailed analysis
4. **Financial Accuracy**: Uses stored discount metadata for 100% accurate reporting
5. **Easy Filtering**: Can identify which bookings used which discount types

## Testing Recommendations

1. **Test with Various Discount Types**:
   - Sibling discounts (camp, course, tournament)
   - Same season discounts
   - Coupon codes
   - Combinations of multiple discounts

2. **Verify Data Accuracy**:
   - Compare column values with order details
   - Ensure discount amounts match stored metadata
   - Verify coupon codes are correctly displayed

3. **Check Display**:
   - Verify column appears in table view
   - Verify column appears in Excel export
   - Test column toggle functionality
   - Check formatting with long discount names

4. **Edge Cases**:
   - Orders with no discounts (should show "None")
   - Orders with only InterSoccer discounts
   - Orders with only coupon codes
   - Orders with both types of discounts

