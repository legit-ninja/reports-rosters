# Test Fix Plan - InterSoccer Reports & Rosters

## Current Status (After Analysis)

**Test Run Results (./deploy.sh --dry-run):**
- Total Tests: 214
- Passing: 12 (6%)
- Failing: 202 (94%)
- Exit Code: 255 (deployment blocked)

## ✅ GREAT NEWS: Classes Already Exist!

Your OOP refactor is **already in progress**! The `classes/` directory contains **47 implementation files**:

```
classes/
├── core/ (5 files) ✅ - Plugin, Database, Logger, Dependencies, Activator
├── services/ (7 files) ✅ - RosterBuilder, PricingCalculator, DataValidator, etc.
├── data/ (15 files) ✅ - Models, Repositories, Collections
├── woocommerce/ (3 files) ✅ - DiscountCalculator, OrderProcessor, ProductVariationHandler
├── export/ (4 files) ✅ - Excel, CSV exporters
├── reports/ (4 files) ✅ - CampReport, OverviewReport
└── ui/ (9 files) ✅ - Pages, Components
```

**This means:** Most of the work is done! We just need to fix some configuration issues.

---

## ❌ Root Cause: 3 Fixable Issues

### Issue 1: Namespace Inconsistencies (Critical)

**Problem:** Some files use inconsistent namespaces.

**Expected by tests (correct):**
```php
namespace InterSoccer\ReportsRosters\Core;
namespace InterSoccer\ReportsRosters\Services;
namespace InterSoccer\ReportsRosters\Data\Models;
```

**Currently in some files (wrong):**
```php
namespace InterSoccerReportsRosters\Export;           // Missing dot!
namespace InterSoccer\Services;                        // Missing ReportsRosters!
namespace InterSoccer\Utils;                           // Missing ReportsRosters!
```

**Files Needing Namespace Fix:**
1. `classes/export/excel-exporter.php` - Change to `InterSoccer\ReportsRosters\Export`
2. `classes/export/csv-exporter.php` - Change to `InterSoccer\ReportsRosters\Export`
3. `classes/export/export-exporter.php` - Change to `InterSoccer\ReportsRosters\Export`
4. `classes/export/export-interface.php` - Change to `InterSoccer\ReportsRosters\Export`
5. `classes/services/price-calculator.php` - Change to `InterSoccer\ReportsRosters\Services`
6. `classes/services/cache-manager-service.php` - Change to `InterSoccer\ReportsRosters\Services`
7. `classes/utils/data-helper.php` - Change to `InterSoccer\ReportsRosters\Utils`
8. `classes/utils/validation-helper.php` - Change to `InterSoccer\ReportsRosters\Utils`
9. `classes/reports/*.php` - Change to `InterSoccer\ReportsRosters\Reports`

### Issue 2: Missing Exception Classes (Critical)

**Problem:** Code references exceptions that don't exist.

**Missing Files:**
- `classes/Exceptions/ValidationException.php`
- `classes/Exceptions/DatabaseException.php`

**Used in:**
- RosterBuilder.php
- Player.php
- DataValidator.php
- Database.php

### Issue 3: Missing Admin/Ajax/UI Classes (Medium)

**Problem:** Plugin.php references classes that might not exist or have wrong namespaces.

**References in Plugin.php:**
```php
use InterSoccer\ReportsRosters\Admin\MenuManager;      // Check exists
use InterSoccer\ReportsRosters\Admin\AssetManager;     // Might be missing
use InterSoccer\ReportsRosters\Ajax\AjaxHandler;       // Might be missing
use InterSoccer\ReportsRosters\WooCommerce\HooksManager; // Might be missing
```

---

## Solution Plan: Fix in Order

### Phase 1: Fix Namespaces (30 minutes) - CRITICAL

#### Step 1.1: Fix Export Namespace (5 min)

Change in all export files:
```php
// FROM:
namespace InterSoccerReportsRosters\Export;

// TO:
namespace InterSoccer\ReportsRosters\Export;
```

**Files to update:**
- `classes/export/excel-exporter.php`
- `classes/export/csv-exporter.php`
- `classes/export/export-exporter.php`
- `classes/export/export-interface.php`

#### Step 1.2: Fix Services Namespace (5 min)

Change in price-calculator.php and cache-manager-service.php:
```php
// FROM:
namespace InterSoccer\Services;

// TO:
namespace InterSoccer\ReportsRosters\Services;
```

**Files to update:**
- `classes/services/price-calculator.php`
- `classes/services/cache-manager-service.php`

#### Step 1.3: Fix Utils Namespace (5 min)

Change in utils files:
```php
// FROM:
namespace InterSoccer\Utils;

// TO:
namespace InterSoccer\ReportsRosters\Utils;
```

**Files to update:**
- `classes/utils/data-helper.php`
- `classes/utils/validation-helper.php`

#### Step 1.4: Fix Reports Namespace (5 min)

Check and fix reports files:
```php
// FROM:
namespace InterSoccerReportsRosters\Reports;

// TO:
namespace InterSoccer\ReportsRosters\Reports;
```

**Files to update:**
- `classes/reports/camp-report.php`
- `classes/reports/overview-report.php`
- `classes/reports/abstract-report.php`
- `classes/reports/report-interface.php`

#### Step 1.5: Rebuild Autoloader (2 min)

```bash
composer dump-autoload
```

### Phase 2: Create Missing Exception Classes (10 minutes)

#### Step 2.1: Create ValidationException.php

```bash
mkdir -p classes/Exceptions
```

```php
<?php
namespace InterSoccer\ReportsRosters\Exceptions;

class ValidationException extends \Exception {
    protected $validation_errors = [];
    
    public function __construct($message = "", $code = 0, \Throwable $previous = null, array $errors = []) {
        parent::__construct($message, $code, $previous);
        $this->validation_errors = $errors;
    }
    
    public function getValidationErrors() {
        return $this->validation_errors;
    }
}
```

#### Step 2.2: Create DatabaseException.php

```php
<?php
namespace InterSoccer\ReportsRosters\Exceptions;

class DatabaseException extends \Exception {
    protected $sql_error;
    protected $sql_state;
    
    public function __construct($message = "", $code = 0, \Throwable $previous = null, $sql_error = '', $sql_state = '') {
        parent::__construct($message, $code, $previous);
        $this->sql_error = $sql_error;
        $this->sql_state = $sql_state;
    }
    
    public function getSqlError() {
        return $this->sql_error;
    }
    
    public function getSqlState() {
        return $this->sql_state;
    }
}
```

### Phase 3: Create Missing Support Classes (15 minutes)

#### Step 3.1: Check for Missing Classes

Run this to identify missing classes:
```bash
./vendor/bin/phpunit --testdox 2>&1 | grep "Class.*not found" | sort -u
```

#### Step 3.2: Create Stubs for Missing Classes

Based on Plugin.php imports, create:

**A. AssetManager.php** (if missing)
```bash
# Check if exists
ls classes/admin/asset-manager.php
```

**B. AjaxHandler.php** (if missing)
```bash
mkdir -p classes/Ajax
```

**C. HooksManager.php** (if missing)
```bash
# For WooCommerce hooks
```

### Phase 4: Fix Bootstrap to Load Classes (5 minutes)

Update `tests/bootstrap.php` to properly load the classes:

```php
// After composer autoload
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Explicitly load core classes if autoload isn't working
if (!class_exists('InterSoccer\ReportsRosters\Core\Plugin')) {
    foreach (glob(dirname(__DIR__) . '/classes/**/*.php') as $file) {
        require_once $file;
    }
}
```

### Phase 5: Run Tests and Fix Remaining Issues (30 minutes)

#### Step 5.1: Run Core Tests

```bash
./vendor/bin/phpunit tests/Core/ --testdox
```

Fix any remaining errors in Core classes.

#### Step 5.2: Run Service Tests

```bash
./vendor/bin/phpunit tests/Services/ --testdox
```

Fix any method signature mismatches.

#### Step 5.3: Run All Tests

```bash
./vendor/bin/phpunit --testdox
```

---

## Specific Fixes Needed

### Fix 1: Namespace Corrections (15 files)

| File | Current Namespace | Should Be |
|------|-------------------|-----------|
| `export/excel-exporter.php` | `InterSoccerReportsRosters\Export` | `InterSoccer\ReportsRosters\Export` |
| `export/csv-exporter.php` | `InterSoccerReportsRosters\Export` | `InterSoccer\ReportsRosters\Export` |
| `export/export-exporter.php` | `InterSoccerReportsRosters\Export` | `InterSoccer\ReportsRosters\Export` |
| `export/export-interface.php` | `InterSoccerReportsRosters\Export` | `InterSoccer\ReportsRosters\Export` |
| `services/price-calculator.php` | `InterSoccer\Services` | `InterSoccer\ReportsRosters\Services` |
| `services/cache-manager-service.php` | `InterSoccer\Services` | `InterSoccer\ReportsRosters\Services` |
| `utils/data-helper.php` | `InterSoccer\Utils` | `InterSoccer\ReportsRosters\Utils` |
| `utils/validation-helper.php` | `InterSoccer\Utils` | `InterSoccer\ReportsRosters\Utils` |
| `reports/camp-report.php` | Check | `InterSoccer\ReportsRosters\Reports` |
| `reports/overview-report.php` | Check | `InterSoccer\ReportsRosters\Reports` |
| `reports/abstract-report.php` | Check | `InterSoccer\ReportsRosters\Reports` |

### Fix 2: Create Exception Classes (2 files)

Create:
- `classes/Exceptions/ValidationException.php`
- `classes/Exceptions/DatabaseException.php`

### Fix 3: Check Missing Referenced Classes

Classes that Plugin.php imports but may not exist:
- `InterSoccer\ReportsRosters\Admin\AssetManager` (might be menu-manager.php?)
- `InterSoccer\ReportsRosters\Ajax\AjaxHandler` (create or stub)
- `InterSoccer\ReportsRosters\WooCommerce\HooksManager` (create or stub)

---

## Estimated Time to Fix

| Phase | Time | Difficulty |
|-------|------|------------|
| Fix Namespaces | 30 min | Easy |
| Create Exceptions | 10 min | Easy |
| Create Missing Classes | 20 min | Medium |
| Fix Bootstrap | 5 min | Easy |
| Test & Debug | 30 min | Medium |
| **TOTAL** | **~90 min** | **Easy-Medium** |

---

## Recommended Approach

### OPTION A: Quick Fix (90 minutes) - RECOMMENDED

**Goal:** Fix namespace/class issues, get all tests passing

**Steps:**
1. Fix namespace inconsistencies (15 files)
2. Create missing Exception classes (2 files)
3. Create/stub missing referenced classes (3-5 files)
4. Run `composer dump-autoload`
5. Test incrementally
6. Deploy successfully

**Result:** 180+ tests passing (85%+), can deploy confidently

### OPTION B: Test Legacy Only (30 minutes)

**Goal:** Deploy now, fix tests later

**Steps:**
1. Use minimal phpunit.xml (legacy tests only)
2. Deploy with 12 passing tests
3. Fix namespace issues later

**Result:** Can deploy today, but with limited test coverage

---

## Implementation Plan (Option A - Recommended)

### Step-by-Step Fix Guide

#### 1. Create Exception Classes (10 min)

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-reports-rosters

mkdir -p classes/Exceptions

cat > classes/Exceptions/ValidationException.php << 'EOF'
<?php
namespace InterSoccer\ReportsRosters\Exceptions;

class ValidationException extends \Exception {
    protected $validation_errors = [];
    
    public function __construct($message = "", $code = 0, \Throwable $previous = null, array $errors = []) {
        parent::__construct($message, $code, $previous);
        $this->validation_errors = $errors;
    }
    
    public function getValidationErrors() {
        return $this->validation_errors;
    }
}
EOF

cat > classes/Exceptions/DatabaseException.php << 'EOF'
<?php
namespace InterSoccer\ReportsRosters\Exceptions;

class DatabaseException extends \Exception {
    protected $sql_error;
    protected $sql_state;
    
    public function __construct($message = "", $code = 0, \Throwable $previous = null, $sql_error = '', $sql_state = '') {
        parent::__construct($message, $code, $previous);
        $this->sql_error = $sql_error;
        $this->sql_state = $sql_state;
    }
    
    public function getSqlError() {
        return $this->sql_error;
    }
    
    public function getSqlState() {
        return $this->sql_state;
    }
}
EOF
```

#### 2. Fix Export Namespace (5 min)

In each file in `classes/export/`:
```bash
# Find and replace in all export files
sed -i 's/namespace InterSoccerReportsRosters\\Export;/namespace InterSoccer\\ReportsRosters\\Export;/' classes/export/*.php
```

#### 3. Fix Services Namespace (5 min)

```bash
# Fix price-calculator.php
sed -i 's/namespace InterSoccer\\Services;/namespace InterSoccer\\ReportsRosters\\Services;/' classes/services/price-calculator.php

# Fix cache-manager-service.php
sed -i 's/namespace InterSoccer\\Services;/namespace InterSoccer\\ReportsRosters\\Services;/' classes/services/cache-manager-service.php
```

#### 4. Fix Utils Namespace (5 min)

```bash
# Fix all utils files
sed -i 's/namespace InterSoccer\\Utils;/namespace InterSoccer\\ReportsRosters\\Utils;/' classes/utils/*.php
```

#### 5. Fix Reports Namespace (5 min)

```bash
# Check and fix reports files
sed -i 's/namespace InterSoccerReportsRosters\\Reports;/namespace InterSoccer\\ReportsRosters\\Reports;/' classes/reports/*.php
```

#### 6. Fix WooCommerce Namespace (if needed) (5 min)

```bash
# Check and fix
sed -i 's/namespace InterSoccerReportsRosters\\WooCommerce;/namespace InterSoccer\\ReportsRosters\\WooCommerce;/' classes/woocommerce/*.php
```

#### 7. Fix UI Namespaces (if needed) (5 min)

```bash
# Fix UI components and pages
sed -i 's/namespace InterSoccerReportsRosters\\UI/namespace InterSoccer\\ReportsRosters\\UI/' classes/ui/**/*.php
sed -i 's/namespace InterSoccer\\UI/namespace InterSoccer\\ReportsRosters\\UI/' classes/ui/**/*.php
```

#### 8. Fix Admin Namespace (if needed) (5 min)

```bash
sed -i 's/namespace InterSoccer\\Admin;/namespace InterSoccer\\ReportsRosters\\Admin;/' classes/admin/*.php
```

#### 9. Rebuild Autoloader (2 min)

```bash
composer dump-autoload
```

#### 10. Create Missing Stubs (15 min)

Check what's still missing and create stubs:

```bash
# Run tests to see what's still missing
./vendor/bin/phpunit tests/Core/PluginTest.php --testdox 2>&1 | grep "not found"
```

If AssetManager, AjaxHandler, or HooksManager are missing, create simple stubs.

#### 11. Run Tests Incrementally (20 min)

```bash
# Test Core first
./vendor/bin/phpunit tests/Core/ --testdox

# Then Services
./vendor/bin/phpunit tests/Services/ --testdox

# Then Data
./vendor/bin/phpunit tests/Data/ --testdox

# Finally all
./vendor/bin/phpunit --testdox
```

---

## Quick Fix Commands (Copy-Paste Ready)

Run these in sequence:

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-reports-rosters

# 1. Create Exceptions directory
mkdir -p classes/Exceptions

# 2. Create ValidationException
cat > classes/Exceptions/ValidationException.php << 'EOF'
<?php
namespace InterSoccer\ReportsRosters\Exceptions;
class ValidationException extends \Exception {
    protected $validation_errors = [];
    public function __construct($message = "", $code = 0, \Throwable $previous = null, array $errors = []) {
        parent::__construct($message, $code, $previous);
        $this->validation_errors = $errors;
    }
    public function getValidationErrors() { return $this->validation_errors; }
}
EOF

# 3. Create DatabaseException
cat > classes/Exceptions/DatabaseException.php << 'EOF'
<?php
namespace InterSoccer\ReportsRosters\Exceptions;
class DatabaseException extends \Exception {}
EOF

# 4. Fix all namespace issues
find classes/export -name "*.php" -exec sed -i 's/namespace InterSoccerReportsRosters\\Export;/namespace InterSoccer\\ReportsRosters\\Export;/' {} \;
find classes/reports -name "*.php" -exec sed -i 's/namespace InterSoccerReportsRosters\\Reports;/namespace InterSoccer\\ReportsRosters\\Reports;/' {} \;
find classes/woocommerce -name "*.php" -exec sed -i 's/namespace InterSoccerReportsRosters\\WooCommerce;/namespace InterSoccer\\ReportsRosters\\WooCommerce;/' {} \;
find classes/ui -name "*.php" -exec sed -i 's/namespace InterSoccerReportsRosters\\UI/namespace InterSoccer\\ReportsRosters\\UI/' {} \;
find classes/ui -name "*.php" -exec sed -i 's/namespace InterSoccer\\UI/namespace InterSoccer\\ReportsRosters\\UI/' {} \;
find classes/data/models -name "*.php" -exec sed -i 's/namespace InterSoccerReportsRosters\\Data\\Models;/namespace InterSoccer\\ReportsRosters\\Data\\Models;/' {} \;
sed -i 's/namespace InterSoccer\\Services;/namespace InterSoccer\\ReportsRosters\\Services;/' classes/services/price-calculator.php
sed -i 's/namespace InterSoccer\\Services;/namespace InterSoccer\\ReportsRosters\\Services;/' classes/services/cache-manager-service.php
sed -i 's/namespace InterSoccer\\Utils;/namespace InterSoccer\\ReportsRosters\\Utils;/' classes/utils/*.php
sed -i 's/namespace InterSoccer\\Admin;/namespace InterSoccer\\ReportsRosters\\Admin;/' classes/admin/*.php

# 5. Rebuild autoloader
composer dump-autoload

# 6. Run tests
./vendor/bin/phpunit --testdox
```

---

## Expected Results

**After Phase 1 & 2:**
- 100+ tests should start passing
- Core tests should all pass
- Some Service/Data tests may still fail

**After Phase 3:**
- 180+ tests passing (85%+)
- Only minor method signature issues remaining

**After Phase 4:**
- 200+ tests passing (95%+)
- Ready to deploy

---

## Deployment Timeline

| Action | Time | Status |
|--------|------|--------|
| Run fix commands | 15 min | Ready |
| Test Core classes | 10 min | Ready |
| Create missing stubs | 20 min | As needed |
| Final test run | 10 min | Ready |
| Deploy | 5 min | After tests pass |
| **TOTAL** | **60 min** | **Ready to start** |

---

## Next Steps

**I recommend proceeding with Option A:**

1. I'll run the namespace fixes automatically
2. Create the missing Exception classes
3. Run tests to identify any remaining issues
4. Create stubs for any missing classes
5. Get to 85%+ tests passing
6. Deploy successfully

**Shall I proceed with the automated fixes?**
