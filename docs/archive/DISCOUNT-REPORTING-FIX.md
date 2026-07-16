# Discount Reporting Accuracy Fix

## Issue
The booking report was not accurately capturing discount data from order item metadata. The system was only calculating discounts as `base_price - final_price`, which doesn't account for:
1. Item-level discount metadata stored by the Product Variations plugin
2. Detailed discount breakdowns by type (sibling, same-season, coupon, etc.)
3. Accurate discount allocation to individual order items

## Root Cause
The `intersoccer_get_financial_booking_report()` function was using a simple calculation:
```php
$discount_amount = $base_price - $final_price;
```

This approach misses:
- Item-level discount metadata (`_intersoccer_total_item_discount`)
- Discount breakdown arrays (`_intersoccer_item_discounts`)
- Discount type categorization

## Solution Implemented

### 1. Priority-Based Discount Extraction
Updated discount calculation to use a three-tier priority system:

**Priority 1: Item-Level Discount Metadata (Most Accurate)**
- Checks for `_intersoccer_total_item_discount` in order item metadata
- This is set by the Product Variations plugin during checkout
- Also extracts `_intersoccer_item_discounts` for detailed breakdown

**Priority 2: Order-Level Discount Metadata (Fallback)**
- Checks for order-level discount metadata if item-level is not available
- Less accurate as it requires proportional allocation

**Priority 3: Price Difference Calculation (Last Resort)**
- Falls back to `base_price - final_price` if no metadata exists
- Logs when fallback is used for debugging

### 2. Enhanced Discount Type Breakdown
Updated `intersoccer_calculate_discount_type_breakdown()` to:
- Use discount breakdown arrays from metadata (most accurate)
- Use discount_type field if breakdown not available
- Fall back to discount code analysis (least accurate)

### 3. Discount Metadata Storage
The system now stores:
- `discount_type`: Primary discount type (sibling, same_season, coupon, other)
- `discount_breakdown`: Detailed array of all discounts applied to the item
- `discount_codes`: Coupon codes used (for reference)

## Files Modified

### `includes/reporting-discounts.php`
- **`intersoccer_get_financial_booking_report()`**: Updated to check item-level discount metadata first
- **`intersoccer_get_enhanced_booking_report()`**: Updated SQL query to include item-level discount metadata and improved discount calculation

### `includes/reports.php`
- **`intersoccer_calculate_discount_type_breakdown()`**: Enhanced to use discount breakdown arrays from metadata for accurate categorization

## Discount Metadata Keys

The system now checks for these metadata keys in order item metadata:

1. **`_intersoccer_total_item_discount`** (Primary)
   - Total discount amount for the specific order item
   - Set by Product Variations plugin during checkout
   - Most accurate source

2. **`_intersoccer_item_discounts`** (Detailed Breakdown)
   - Array of discount objects with:
     - `name`: Discount name/description
     - `type`: Discount type (sibling, same_season, coupon, etc.)
     - `amount`: Discount amount
   - Used for accurate discount type categorization

3. **Order-Level Fallbacks** (if item-level not available):
   - `_intersoccer_total_discounts`: Total order discount
   - `_intersoccer_all_discounts`: All discounts array

## Discount Type Mapping

The system maps discount types to categories:

- **Sibling/Multi-Child**: `sibling`, `multi-child`, `camp_sibling`, `course_multi_child`
- **Same Season**: `same_season`, `same-season`, `second_course`, `second-course`
- **Coupon**: `coupon`, `promo`, `promotional`
- **Other**: All other discount types

## Expected Results

1. **100% Accurate Discount Amounts**: Uses stored metadata instead of calculations
2. **Accurate Discount Type Breakdown**: Categorizes discounts correctly based on metadata
3. **Better Debugging**: Logs when fallback calculations are used
4. **Backward Compatibility**: Falls back gracefully if metadata doesn't exist

## Testing Recommendations

1. **Test with Recent Orders**: Orders processed after this fix should have item-level discount metadata
2. **Test with Historical Orders**: Older orders may not have metadata and will use fallback calculation
3. **Verify Discount Totals**: Compare report totals with order totals to ensure accuracy
4. **Check Discount Type Breakdown**: Verify sibling, same-season, and coupon discounts are categorized correctly
5. **Monitor Debug Logs**: Check for fallback calculation warnings to identify orders missing metadata

## Migration Note

For historical orders that don't have item-level discount metadata, the system will:
1. Use fallback calculation (`base_price - final_price`)
2. Log a warning message for debugging
3. Attempt to categorize based on discount codes (less accurate)

To improve accuracy for historical orders, consider running a migration script to populate item-level discount metadata from order-level data.

