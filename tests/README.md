# InterSoccer Reports & Rosters - Test Suite

This directory contains comprehensive PHPUnit tests for the InterSoccer Reports and Rosters plugin.

## Overview

The test suite provides comprehensive coverage of the plugin's functionality:

- **Core Tests**: Plugin initialization, database operations, logging, dependencies
- **Service Tests**: Business logic for roster building, pricing calculations, data validation
- **Data Layer Tests**: Models, repositories, and collections
- **WooCommerce Integration**: Order processing, discount calculation, product variations
- **Export & Reports**: Excel/CSV exports, camp reports, overview statistics
- **Legacy Tests**: Tests for procedural code in `includes/`
- **Integration Tests**: End-to-end workflows and complex scenarios
- **Helper Classes**: Reusable test utilities

## Directory Structure

```
tests/
├── bootstrap.php              # Test environment setup
├── TestCase.php              # Base test class with utilities
├── Mocks/                    # Mock classes for dependencies
│   └── WooCommerceMock.php
├── Core/                     # Core component tests
│   ├── PluginTest.php
│   ├── DatabaseTest.php
│   ├── LoggerTest.php
│   ├── DependenciesTest.php
│   └── ActivatorTest.php
├── Services/                 # Service layer tests
│   ├── RosterBuilderTest.php
│   ├── PricingCalculatorTest.php
│   ├── DataValidatorTest.php
│   ├── EventMatcherTest.php
│   ├── PlayerMatcherTest.php
│   └── CacheManagerTest.php
├── Data/                     # Data layer tests
│   ├── Models/
│   ├── Repositories/
│   └── Collections/
├── WooCommerce/             # WooCommerce integration tests
├── Export/                  # Export functionality tests
├── Reports/                 # Report generation tests
├── Legacy/                  # Legacy code tests
├── Integration/             # Integration tests
└── Helpers/                 # Test helper classes
    ├── WooCommerceTestHelper.php
    ├── PlayerTestHelper.php
    └── DatabaseTestHelper.php
```

## Installation

### 1. Install Dependencies

```bash
cd /path/to/intersoccer-reports-rosters
composer install
```

This installs:
- PHPUnit 9.6
- Mockery for mocking
- Brain Monkey for WordPress function mocking
- Yoast PHPUnit Polyfills

### 2. Configure WordPress Test Environment (Optional)

For full WordPress integration testing, set up the WordPress test suite:

```bash
# Set environment variable
export WP_TESTS_DIR=/path/to/wordpress-tests-lib

# Or add to phpunit.xml
```

The test suite will work with or without the WordPress test environment using mocking.

## Running Tests

### Run All Tests

```bash
composer test
# or
./vendor/bin/phpunit
```

### Run Specific Test Suites

```bash
# Core tests only
composer test:unit
# or
./vendor/bin/phpunit --testsuite=Core

# Service tests
./vendor/bin/phpunit --testsuite=Services

# Integration tests
composer test:integration
# or
./vendor/bin/phpunit --testsuite=Integration

# WooCommerce tests
./vendor/bin/phpunit --testsuite=WooCommerce
```

### Run Specific Test File

```bash
./vendor/bin/phpunit tests/Core/LoggerTest.php
```

### Run Specific Test Method

```bash
./vendor/bin/phpunit --filter test_logger_initialization
```

## Code Coverage

### Generate HTML Coverage Report

```bash
composer test:coverage
```

This generates an HTML report in `coverage/html/` directory. Open `coverage/html/index.html` in your browser.

### Generate Coverage Summary

```bash
./vendor/bin/phpunit --coverage-text
```

### Coverage Targets

The test suite aims for the following coverage targets:

- **Core classes**: 90%+
- **Service classes**: 85%+
- **Data models**: 85%+
- **Repositories**: 80%+
- **Export classes**: 75%+
- **Legacy includes**: 60%+
- **Overall plugin**: 75%+

## Test Configuration

### PHPUnit Configuration (`phpunit.xml`)

The configuration file includes:

- Bootstrap file for test environment setup
- Test suites organized by component
- Coverage paths for both `classes/` and `includes/`
- PHP configuration (memory, error reporting)
- Database connection settings

### Environment Variables

Set these in `phpunit.xml` or as environment variables:

```xml
<php>
    <env name="WP_TESTS_DB_NAME" value="wp_test"/>
    <env name="WP_TESTS_DB_USER" value="root"/>
    <env name="WP_TESTS_DB_PASSWORD" value=""/>
    <env name="WP_TESTS_DB_HOST" value="localhost"/>
</php>
```

## Writing Tests

### Basic Test Structure

```php
<?php
namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Core\Logger;

class LoggerTest extends TestCase {
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        $this->logger = new Logger();
    }
    
    public function test_logger_initialization() {
        $this->assertInstanceOf(Logger::class, $this->logger);
    }
}
```

### Using Test Helpers

```php
use InterSoccer\ReportsRosters\Tests\Helpers\WooCommerceTestHelper;

$order = WooCommerceTestHelper::createMockOrder([
    'id' => 123,
    'status' => 'completed'
]);
```

### Mocking WordPress Functions

```php
use Brain\Monkey\Functions;

Functions\expect('get_option')
    ->once()
    ->with('my_option')
    ->andReturn('value');
```

### Mocking Database

```php
use InterSoccer\ReportsRosters\Tests\Helpers\DatabaseTestHelper;

$wpdb = DatabaseTestHelper::createMockWpdb();
DatabaseTestHelper::expectInsert($wpdb, 123);
```

## Best Practices

1. **Test Isolation**: Each test should be independent
2. **Clear Names**: Use descriptive test method names
3. **Arrange-Act-Assert**: Structure tests clearly
4. **Mock Dependencies**: Isolate the system under test
5. **Test Edge Cases**: Include boundary conditions and error scenarios
6. **Keep Tests Fast**: Mock expensive operations (DB, network)

## Continuous Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: php-actions/composer@v6
      - run: composer install
      - run: composer test
```

## Troubleshooting

### Class Not Found Errors

Ensure autoload is up to date:

```bash
composer dump-autoload
```

### WordPress Function Errors

Check that Brain Monkey is properly set up in `bootstrap.php` and functions are mocked in tests.

### Database Connection Errors

If not using WordPress test suite, ensure database mocking is properly configured.

## Coverage Analysis

### Current Coverage Status

Run coverage analysis to see current status:

```bash
composer test:coverage
```

### Critical Paths

Focus test coverage on:

1. **Roster Building**: Core business logic
2. **Pricing Calculations**: Discount rules and calculations
3. **Data Validation**: Input validation and sanitization
4. **Order Processing**: WooCommerce integration
5. **Database Operations**: CRUD operations

### Filling Coverage Gaps

To improve coverage:

1. Run coverage report to identify gaps
2. Focus on untested public methods
3. Add edge case tests
4. Test error handling paths
5. Add integration tests for complex workflows

## Support

For questions or issues with the test suite:

1. Check this README
2. Review existing tests for examples
3. Consult PHPUnit documentation: https://phpunit.de/
4. Review Brain Monkey docs: https://giuseppe-mazzapica.gitbook.io/brain-monkey/

## Contributing

When adding new features:

1. Write tests first (TDD approach)
2. Ensure all tests pass
3. Maintain or improve code coverage
4. Follow existing test patterns
5. Document complex test scenarios

## License

Same as the main plugin (GPL-2.0+)

