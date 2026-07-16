# Booking Report Export Error Fix

## Error Description

The booking report export was failing with the following error:
```
InterSoccer ENHANCED: Export error: Enhanced Bookings Oct 29 - Nov !A150 -> Formula Error: An unexpected error occurred
```

The error occurred in PhpSpreadsheet's `Cell.php` at line 397, suggesting an issue with cell references or sheet name handling.

## Root Causes Identified

1. **Invalid Sheet Name Characters**: The sheet title "Enhanced Bookings Oct 29 - Nov" contained characters that could cause issues when PhpSpreadsheet creates internal cell references (the "!A150" notation suggests a sheet reference problem).

2. **Unvalidated Numeric Values**: Percentage calculations and other numeric operations could produce `NaN` or `Infinity` values, which PhpSpreadsheet cannot handle.

3. **Missing Error Handling**: Operations that could fail (sheet title setting, cell formatting, auto-sizing) lacked proper error handling.

4. **Auto-size Column Issues**: Auto-sizing columns without bounds checking could cause issues with large datasets.

## Fixes Implemented

### 1. Enhanced Sheet Title Sanitization

**Location**: `includes/reports.php` (lines ~553-581)

**Changes**:
- Removed all invalid Excel sheet name characters: `:`, `\`, `/`, `?`, `*`, `[`, `]`, `'`, `"`
- Removed leading/trailing spaces and dashes
- Ensured sheet name doesn't start with a number
- Added try-catch around `setTitle()` with fallback to safe default
- Added logging for debugging

**Before**:
```php
$sheet_title = 'Enhanced Bookings ' . $date_range;
$sheet->setTitle(substr($sheet_title, 0, 31));
```

**After**:
```php
$sheet_title = 'Enhanced Bookings ' . $date_range;
// Comprehensive sanitization
$sheet_title = str_replace([':', '\\', '/', '?', '*', '[', ']', "'", '"'], '', $sheet_title);
$sheet_title = preg_replace('/\s+/', ' ', $sheet_title);
$sheet_title = trim($sheet_title, " \t\n\r\0\x0B-");
$sheet_title = substr($sheet_title, 0, 31);
$sheet_title = trim($sheet_title);
// Ensure valid name
if (empty($sheet_title) || preg_match('/^\d/', $sheet_title)) {
    $sheet_title = 'Enhanced Bookings';
}
try {
    $sheet->setTitle($sheet_title);
} catch (\Exception $e) {
    // Fallback handling
}
```

### 2. Numeric Value Validation

**Location**: `includes/reports.php` (lines ~646-710)

**Changes**:
- Added `is_finite()` checks for all calculated values
- Explicit type casting to `float` and `int`
- Safe calculation of discount effectiveness with division-by-zero protection
- Validation before setting cell values

**Before**:
```php
$discount_rate = $report_data['totals']['base_price'] > 0 ? ($report_data['totals']['discount_amount'] / $report_data['totals']['base_price']) * 100 : 0;
```

**After**:
```php
$discount_rate = $report_data['totals']['base_price'] > 0 ? ((float)$report_data['totals']['discount_amount'] / (float)$report_data['totals']['base_price']) * 100 : 0;
$discount_rate = is_finite($discount_rate) ? $discount_rate : 0;
```

### 3. Error Handling for Cell Operations

**Location**: `includes/reports.php` (lines ~712-777)

**Changes**:
- Wrapped cell value setting in try-catch blocks
- Added validation for numeric values before setting
- Continue processing even if individual cells fail
- Added error logging for debugging

**Before**:
```php
$sheet->setCellValue('A' . $current_row, $summary_row[0]);
$sheet->setCellValue('B' . $current_row, $summary_row[1]);
```

**After**:
```php
try {
    $sheet->setCellValue('A' . $current_row, $summary_row[0]);
    $cell_b_value = $summary_row[1];
    if (is_numeric($cell_b_value) && !is_finite($cell_b_value)) {
        $cell_b_value = 0;
    }
    $sheet->setCellValue('B' . $current_row, $cell_b_value);
    $sheet->setCellValue('C' . $current_row, $summary_row[2]);
} catch (\Exception $e) {
    error_log('InterSoccer ENHANCED: Error setting cell values at row ' . $current_row . ': ' . $e->getMessage());
    $current_row++;
    continue;
}
```

### 4. Safe Percentage Formatting

**Location**: `includes/reports.php` (lines ~754-777)

**Changes**:
- Added try-catch around percentage formatting
- Validate percentage values before conversion
- Fallback to 0 for invalid values

**Before**:
```php
$sheet->getStyle('B' . $current_row)->getNumberFormat()->setFormatCode('0.0"%"');
$sheet->setCellValue('B' . $current_row, $summary_row[1] / 100);
```

**After**:
```php
try {
    $sheet->getStyle('B' . $current_row)->getNumberFormat()->setFormatCode('0.0"%"');
    $percentage_value = is_numeric($summary_row[1]) ? ($summary_row[1] / 100) : 0;
    if (is_finite($percentage_value)) {
        $sheet->setCellValue('B' . $current_row, $percentage_value);
    } else {
        $sheet->setCellValue('B' . $current_row, 0);
    }
} catch (\Exception $e) {
    error_log('InterSoccer ENHANCED: Error formatting cell B' . $current_row . ': ' . $e->getMessage());
}
```

### 5. Safe Auto-size Columns

**Location**: `includes/reports.php` (lines ~796-810)

**Changes**:
- Added bounds checking (limit to 26 columns for safety)
- Individual try-catch for each column
- Continue even if auto-size fails

**Before**:
```php
foreach (range('A', chr(64 + max(count($header_row), 4))) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
```

**After**:
```php
try {
    $max_cols = max(count($header_row), 4);
    $max_cols = min($max_cols, 26); // Limit to Z for safety
    foreach (range('A', chr(64 + $max_cols)) as $col) {
        try {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        } catch (\Exception $e) {
            error_log('InterSoccer ENHANCED: Failed to auto-size column ' . $col . ': ' . $e->getMessage());
        }
    }
} catch (\Exception $e) {
    error_log('InterSoccer ENHANCED: Failed to auto-size columns: ' . $e->getMessage());
}
```

### 6. Excel Generation Error Handling

**Location**: `includes/reports.php` (lines ~820-833)

**Changes**:
- Wrapped Excel generation in try-catch
- Clean up output buffer on error
- Validate generated content

**Before**:
```php
$writer = new Xlsx($spreadsheet);
ob_start();
$writer->save('php://output');
$content = ob_get_clean();
```

**After**:
```php
try {
    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();
    
    if (empty($content)) {
        throw new \Exception('Failed to generate Excel file content');
    }
} catch (\Throwable $e) {
    ob_end_clean();
    error_log('InterSoccer ENHANCED: Excel generation error: ' . $e->getMessage());
    throw $e;
}
```

## Testing Recommendations

1. **Test with Various Date Ranges**:
   - Short date ranges (e.g., "Oct 29 - Nov 1")
   - Long date ranges (e.g., "Jan 1 - Dec 31")
   - Single month ranges
   - Year-only ranges

2. **Test with Edge Cases**:
   - Reports with zero bookings
   - Reports with very large numbers
   - Reports with missing data fields
   - Reports with special characters in data

3. **Monitor Error Logs**:
   - Check for any new error messages
   - Verify that errors are logged with context
   - Ensure exports complete successfully

## Expected Behavior After Fix

- Sheet titles are properly sanitized and valid
- All numeric values are validated before use
- Errors in individual operations don't stop the entire export
- Detailed error logging helps diagnose any remaining issues
- Exports complete successfully even with problematic data

## Files Modified

- `includes/reports.php` - Enhanced error handling and validation throughout the export function

## Related Issues

This fix addresses the "Formula Error" that was occurring when PhpSpreadsheet tried to create cell references with invalid sheet names or invalid numeric values.

