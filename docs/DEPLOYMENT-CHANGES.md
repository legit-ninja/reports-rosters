# Deployment Script Changes

## Summary

The `deploy.sh` script has been updated to integrate automated testing into the deployment workflow.

## Key Changes

### 1. PHPUnit Tests - Always Run ✅

PHPUnit tests now **always run** before every deployment. This ensures:
- No broken code gets deployed
- All tests must pass before deployment proceeds
- Immediate feedback on test failures

**Behavior:**
- Tests run automatically on every `./deploy.sh` execution
- Deployment **aborts** if tests fail
- No way to skip tests (intentional safety measure)

### 2. Cypress Tests - Optional with --test Flag ✅

Cypress E2E tests run only when explicitly requested with the `--test` flag.

**Behavior:**
- `./deploy.sh` - Runs PHPUnit tests only
- `./deploy.sh --test` - Runs both PHPUnit AND Cypress tests
- Gracefully skips if Cypress not configured
- Deployment aborts if Cypress tests fail (when run)

## Usage Examples

### Standard Deployment
```bash
./deploy.sh
```
**What happens:**
1. ✅ Runs PHPUnit tests (required)
2. ✅ Deploys if tests pass
3. ❌ Aborts if tests fail

### Full Test Suite Deployment
```bash
./deploy.sh --test
```
**What happens:**
1. ✅ Runs PHPUnit tests (required)
2. ✅ Runs Cypress E2E tests (optional)
3. ✅ Deploys if all tests pass
4. ❌ Aborts if any tests fail

### Dry Run with Tests
```bash
./deploy.sh --test --dry-run
```
**What happens:**
1. ✅ Runs PHPUnit tests
2. ✅ Runs Cypress tests
3. ✅ Shows what would be deployed (doesn't upload)

### Deploy with Cache Clear
```bash
./deploy.sh --clear-cache
```
**What happens:**
1. ✅ Runs PHPUnit tests
2. ✅ Deploys if tests pass
3. ✅ Clears server caches

## Test Requirements

### PHPUnit (Required)
- Must have `composer install` run
- Must have `vendor/bin/phpunit` available
- Must have `tests/` directory
- Deployment **cannot proceed** without PHPUnit

### Cypress (Optional)
- Only runs with `--test` flag
- Gracefully skips if not configured
- Requires `npm install` if configured
- Requires `cypress/` directory

## Error Handling

### PHPUnit Test Failures
```
✗ PHPUnit tests failed. Aborting deployment.

Fix the failing tests before deploying.
```
Deployment stops immediately. Fix tests before retrying.

### Cypress Test Failures (with --test)
```
✗ Cypress tests failed. Aborting deployment.

Fix the failing tests before deploying.
```
Deployment stops. Fix E2E tests before retrying.

## Benefits

✅ **Safety**: No broken code reaches production
✅ **Confidence**: All tests pass before deployment
✅ **Speed**: Quick PHPUnit tests always run, slower E2E tests optional
✅ **Flexibility**: Choose test depth with flags
✅ **Feedback**: Clear pass/fail messaging

## Configuration

No additional configuration needed! The script automatically:
- Detects if PHPUnit is installed
- Checks for test directories
- Validates test setup
- Provides helpful error messages

## Help

View all options:
```bash
./deploy.sh --help
```

Output:
```
Usage: ./deploy.sh [OPTIONS]

Options:
  --dry-run        Show what would be uploaded without uploading
  --test           Run both PHPUnit AND Cypress tests before deploying
  --clear-cache    Clear server caches after deployment
  --help           Show this help message

Note: PHPUnit tests always run before deployment.
      Use --test flag to also run Cypress/E2E tests.
```

## Implementation Date

November 5, 2025

## Related Documentation

- `TESTING.md` - Complete testing guide
- `tests/README.md` - Test suite documentation
- `TEST-IMPLEMENTATION-SUMMARY.md` - Test implementation details

