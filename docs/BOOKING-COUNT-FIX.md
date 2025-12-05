# Booking Count Discrepancy Fix

## Issue
There was a discrepancy between:
- **"records found"**: 2,418 (count of order items/rows)
- **"Total Bookings"**: 1,880 (sum of quantity fields)

## Root Cause
The "Total Bookings" calculation was summing the `quantity` field from order items:
```php
$totals['bookings'] += intval($row->quantity);
```

However, in InterSoccer's system:
- Each order item represents **one booking/participant**
- The `quantity` field may be `0`, `null`, or incorrectly set for some records
- This caused the booking count to be lower than the actual number of records

## Solution
Changed the booking count calculation to use the count of records instead of summing quantities:

**Before:**
```php
$totals['bookings'] += intval($row->quantity);
```

**After:**
```php
// Set bookings to the count of records (each order item = 1 booking)
// This ensures "Total Bookings" matches "records found"
$totals['bookings'] = count($data);
```

## Files Modified

### 1. `includes/reporting-discounts.php`
- **Function**: `intersoccer_get_financial_booking_report()`
- **Change**: Set `$totals['bookings'] = count($data)` at the end instead of summing quantities

### 2. `classes/services/financial-report-service.php`
- **Method**: `getFinancialBookingReport()`
- **Change**: Set `$totals['bookings'] = count($data)` at the end instead of summing quantities

## Expected Result
- **"records found"** and **"Total Bookings"** will now match
- Each order item = 1 booking, regardless of the quantity field value
- Accurate booking counts for financial reporting

## Rationale
In InterSoccer's booking system:
- Each order item represents one participant/booking
- The quantity field is not used for counting bookings
- Counting records is the correct approach for accurate reporting

