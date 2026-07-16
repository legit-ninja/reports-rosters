# Roster Export Testing Guide

This document describes how to test the roster export functionality to prevent regressions.

## Quick Test Commands

### Run All Roster Export Tests

```bash
cd /path/to/intersoccer-reports-rosters
./vendor/bin/phpunit tests/Legacy/RosterExportTest.php
```

### Run Specific Test Methods

```bash
# Test JSON response structure
./vendor/bin/phpunit --filter test_export_returns_json_response

# Test permission handling
./vendor/bin/phpunit --filter test_export_handles_permission_errors

# Test missing data handling
./vendor/bin/phpunit --filter test_export_handles_missing_data

# Test Excel content generation
./vendor/bin/phpunit --filter test_export_generates_valid_excel_content

# Test output buffering
./vendor/bin/phpunit --filter test_export_handles_output_buffering
```

### Run All Legacy Tests (includes export tests)

```bash
./vendor/bin/phpunit --testsuite=Legacy
```

## What the Tests Cover

The `RosterExportTest` class tests the following critical aspects of roster exports:

### 1. JSON Response Structure ✅
- **Test**: `test_export_returns_json_response()`
- **Verifies**: 
  - Export returns JSON (not direct file download)
  - Response has `success`, `data.content`, and `data.filename` keys
  - Content is base64-encoded
  - Filename has `.xlsx` extension

### 2. Permission Handling ✅
- **Test**: `test_export_handles_permission_errors()`
- **Verifies**:
  - Users without permissions get proper error response
  - Error message is included in JSON response

### 3. Missing Data Handling ✅
- **Test**: `test_export_handles_missing_data()`
- **Verifies**:
  - Empty roster data returns proper error
  - Error message indicates "No roster data"

### 4. Missing Parameters ✅
- **Test**: `test_export_handles_missing_parameters()`
- **Verifies**:
  - Missing required parameters (variation_ids, event_signature) returns error
  - Error message is descriptive

### 5. Valid Excel Content ✅
- **Test**: `test_export_generates_valid_excel_content()`
- **Verifies**:
  - Generated content is valid Excel file (starts with PK header)
  - Content is not empty
  - Base64 decoding works correctly

### 6. Output Buffering ✅
- **Test**: `test_export_handles_output_buffering()`
- **Verifies**:
  - Output buffers are properly cleared
  - No stray output interferes with JSON response
  - Function handles existing output buffers correctly

## Common Regression Scenarios

These tests prevent the following regressions:

### ❌ Regression: Direct File Download Instead of JSON
**Symptom**: Export redirects to admin-ajax.php instead of downloading file
**Test**: `test_export_returns_json_response()` will fail
**Fix**: Ensure `wp_send_json_success()` is used, not direct `header()` calls

### ❌ Regression: Headers Already Sent Error
**Symptom**: "Cannot modify header information - headers already sent"
**Test**: `test_export_handles_output_buffering()` will fail
**Fix**: Clear all output buffers before sending JSON response

### ❌ Regression: Missing Error Messages
**Symptom**: Errors don't show proper messages to users
**Test**: `test_export_handles_permission_errors()` and `test_export_handles_missing_data()` will fail
**Fix**: Ensure all error paths use `wp_send_json_error()` with message

### ❌ Regression: Invalid Excel Files
**Symptom**: Downloaded files are corrupted or can't be opened
**Test**: `test_export_generates_valid_excel_content()` will fail
**Fix**: Verify PhpSpreadsheet is generating valid Excel format

## Running Tests Before Deployment

### Pre-Deployment Checklist

1. **Run all export tests**:
   ```bash
   ./vendor/bin/phpunit tests/Legacy/RosterExportTest.php
   ```

2. **Verify all tests pass**:
   ```
   OK (6 tests, X assertions)
   ```

3. **Check for any warnings or errors**

4. **If tests fail**, fix the issues before deploying

### Integration with Deploy Script

The deploy script (`deploy.sh`) runs the Production test suite by default. To include export tests in deployment checks, add to `phpunit.xml`:

```xml
<testsuite name="Production">
    <file>tests/Core/LoggerTest.php</file>
    <file>tests/Legacy/RosterExportTest.php</file>
</testsuite>
```

## Manual Testing Checklist

While automated tests catch most issues, also manually test:

- [ ] Export button shows "Exporting..." during processing
- [ ] Success notification appears after export completes
- [ ] File downloads automatically
- [ ] Error notifications appear for permission errors
- [ ] Error notifications appear for missing data
- [ ] Large rosters (>100 players) export successfully
- [ ] Different activity types (Camp, Course, Tournament) export correctly
- [ ] Export works in different browsers (Chrome, Firefox, Safari)

## Troubleshooting Test Failures

### Test Fails: "PhpSpreadsheet not available"
**Solution**: Ensure PhpSpreadsheet is installed:
```bash
composer install
```

### Test Fails: "wp_send_json_success not found"
**Solution**: Ensure WordPress functions are properly mocked in test setup

### Test Fails: "Cannot redeclare function"
**Solution**: Check for duplicate function declarations in includes files

### Test Fails: "Headers already sent"
**Solution**: Verify output buffering is handled correctly in export function

## Adding New Tests

When adding new export features, add corresponding tests:

1. **Add test method** to `RosterExportTest.php`
2. **Follow naming convention**: `test_feature_description()`
3. **Test both success and failure cases**
4. **Verify JSON response structure**
5. **Test edge cases** (empty data, large datasets, special characters)

Example:
```php
public function test_export_handles_special_characters() {
    // Test export with special characters in player names
    // Verify they're properly encoded in Excel file
}
```

## Related Tests

- **ExcelExporterTest.php**: Tests the Excel exporter class
- **CSVExporterTest.php**: Tests the CSV exporter class
- **ExportWorkflowTest.php**: Tests the complete export workflow

## Support

For issues with export tests:
1. Check this guide
2. Review test output for specific error messages
3. Verify PhpSpreadsheet is installed and working
4. Check that WordPress functions are properly mocked

