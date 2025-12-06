# Repository Structure

## Overview

This document describes the organization of the InterSoccer Reports and Rosters plugin repository.

## Root Directory

The root directory contains only essential plugin files:

```
intersoccer-reports-rosters/
├── intersoccer-reports-rosters.php  # Main plugin file
├── README.md                         # Plugin overview and quick start
├── composer.json                     # PHP dependencies
├── composer.lock                     # Locked dependency versions
├── phpunit.xml                       # PHPUnit configuration
├── taskfile.yaml                     # Task automation
├── deploy.sh                         # Deployment script
└── .gitignore                        # Git ignore rules
```

## Directory Structure

### `/classes/`
Object-oriented PHP classes organized by responsibility:

- **`admin/`** - Admin UI components
- **`ajax/`** - AJAX request handlers
- **`core/`** - Core functionality (Logger, Container, Cache, etc.)
- **`data/`** - Data layer (Repositories, Models)
- **`Exceptions/`** - Custom exception classes
- **`export/`** - Export functionality (CSV, Excel)
- **`reports/`** - Report generation services
- **`services/`** - Business logic services
- **`ui/`** - UI rendering classes
- **`utils/`** - Utility classes
- **`woocommerce/`** - WooCommerce integration

### `/includes/`
Legacy procedural PHP files (being gradually migrated to OOP):

- **`advanced.php`** - Advanced admin settings and tools
- **`db.php`** - Database operations and migrations
- **`utils.php`** - Utility functions
- **`rosters.php`** - Roster listing pages
- **`roster-details.php`** - Individual roster details
- **`roster-export.php`** - Roster export functionality
- **`reports.php`** - Booking reports UI
- **`reports-ajax.php`** - Report AJAX handlers
- **`reporting-discounts.php`** - Discount reporting logic
- **`placeholder-rosters.php`** - Placeholder roster management
- **`oop-adapter.php`** - Bridge between legacy and OOP code

### `/docs/`
All documentation files organized by topic:

#### General Documentation
- **`README-*.md`** - Specific feature guides
- **`QUICK-START.md`** - Getting started guide
- **`DEPLOYMENT.md`** - Deployment instructions
- **`TRANSLATION-FILES-GUIDE.md`** - Internationalization guide

#### Migration & Architecture
- **`OOP-MIGRATION-GUIDE.md`** - OOP migration strategy
- **`OOP-MIGRATION-STATUS.md`** - Migration progress tracking
- **`MIGRATION-COMPLETE.md`** - Completed migrations
- **`DATABASE-MIGRATION-PLAN.md`** - Database schema migrations

#### Feature Documentation
- **`MULTILINGUAL-EVENT-SIGNATURES.md`** - Event signature system
- **`PLACEHOLDER-ROSTERS.md`** - Placeholder roster system
- **`EVENT-COMPLETION-SUMMARY.md`** - Event completion feature
- **`SIGNATURE-VERIFICATION-SUMMARY.md`** - Signature verification

#### Bug Fixes & Troubleshooting
- **`TOURNAMENT-EVENT-SIGNATURE-FIX.md`** - Tournament signature bug analysis
- **`TOURNAMENT-SIGNATURE-IMPLEMENTATION.md`** - Tournament fix implementation
- **`UNIFIED-DATE-PARSER-IMPLEMENTATION.md`** - Date parsing improvements
- **`DISCOUNT-REPORTING-FIX.md`** - Discount reporting fixes
- **`BOOKING-REPORT-EXPORT-FIX.md`** - Excel export fixes
- **`TROUBLESHOOTING-*.md`** - Various troubleshooting guides

#### Status & Deployment
- **`CHANGELOG.md`** - Version history and changes
- **`CURRENT-STATUS-AND-NEXT-STEPS.md`** - Current project status
- **`DEPLOYMENT-READY.md`** - Pre-deployment checklist

### `/js/`
JavaScript files for admin interface:

- **`reports.js`** - Booking reports functionality
- **`rosters-tabs.js`** - Roster page tab navigation
- **`reports-charts.js`** - Chart rendering
- **`event-completion.js`** - Event completion UI
- **`advanced-ajax.js`** - Advanced settings AJAX

### `/css/`
Stylesheets:

- **`styles.css`** - Main plugin styles
- **`rebuild-admin.css`** - Rebuild page styles

### `/languages/`
Translation files (PO/MO files for French, German, Swiss English):

- `intersoccer-reports-rosters-fr_CH.po/mo`
- `intersoccer-reports-rosters-de_CH.po/mo`
- `intersoccer-reports-rosters-en_CH.po/mo`
- `intersoccer-reports-rosters.pot` (template)

### `/templates/`
PHP template files for UI rendering:

- **`admin-rebuild-page.php`** - Rebuild admin page template

### `/tests/`
PHPUnit tests organized by type:

- **`Unit/`** - Unit tests for individual classes
- **`Integration/`** - Integration tests for component interactions
- **`Services/`** - Service class tests
- **`Legacy/`** - Tests for legacy procedural code

### `/scripts/`
Utility scripts:

- **`deploy-*.sh`** - Deployment scripts
- **`update-*.sh`** - Update scripts
- **`*.php`** - PHP utility scripts

### `/bin/`
Binary utilities:

- **`update-headers.php`** - Update file headers

### `/vendor/`
Composer dependencies (managed by Composer)

## File Naming Conventions

### Documentation Files
- Use `UPPERCASE-WITH-HYPHENS.md` for documentation files
- Prefix with topic: `OOP-`, `DATABASE-`, `DEPLOYMENT-`, etc.
- Suffix with type: `-GUIDE.md`, `-STATUS.md`, `-FIX.md`, `-SUMMARY.md`

### PHP Class Files
- PascalCase: `EventSignatureGenerator.php`
- Match class name exactly
- One class per file

### PHP Procedural Files
- lowercase-with-hyphens: `roster-details.php`
- Descriptive function-focused names

### Test Files
- Match class name with `Test` suffix: `EventSignatureGeneratorTest.php`
- Organize in directories matching source structure

## Ignored Files

The following are excluded from version control (see `.gitignore`):

- `*.log` - Debug and error logs
- `debug_*.php` - Temporary debug files
- `temp_*.php` - Temporary PHP files
- `*.csv` - Exported data files
- `troubleshoot*.sql` - SQL troubleshooting scripts
- `investigate*.sql` - SQL investigation scripts
- `plan` - Temporary planning files
- `todo.list` - Temporary todo lists
- `deploy.local.sh` - Local deployment configuration

## Development Workflow

### Creating New Features
1. Create classes in `/classes/` following namespace structure
2. Add tests in `/tests/` matching class organization
3. Document in `/docs/` using appropriate naming convention
4. Update `CHANGELOG.md` with changes

### Bug Fixes
1. Create investigation documentation in `/docs/`
2. Implement fix in appropriate class or include file
3. Add/update tests
4. Document fix in `/docs/` with `-FIX.md` suffix
5. Update `CHANGELOG.md`

### Documentation
1. Feature documentation goes in `/docs/`
2. Use clear, descriptive filenames
3. Link related documents
4. Keep root README concise, link to detailed docs

## Migration Status

The plugin is currently migrating from procedural to object-oriented code:

- ✅ **Core Services** - Fully migrated
- ✅ **Repositories** - Fully migrated
- ✅ **Export** - Fully migrated
- 🔄 **Reports** - In progress
- 🔄 **Rosters** - In progress
- ⏳ **Legacy Includes** - Planned

See `docs/OOP-MIGRATION-STATUS.md` for detailed migration status.

## Best Practices

### Documentation
- Keep documentation in `/docs/` folder only
- No documentation in root directory (except README.md)
- Use clear, descriptive filenames
- Link between related documents

### Code Organization
- OOP classes in `/classes/`
- Legacy code in `/includes/`
- One class per file
- Follow PSR-4 autoloading

### Testing
- Write tests for all new features
- Maintain test coverage above 80%
- Organize tests matching source structure
- Use descriptive test method names

### Version Control
- Commit related changes together
- Use descriptive commit messages
- Keep commits focused and atomic
- Update CHANGELOG.md with each release

## Questions?

For questions about repository structure or organization, see:
- `docs/OOP-MIGRATION-GUIDE.md` - OOP migration strategy
- `docs/QUICK-START.md` - Getting started guide
- `README.md` - Plugin overview


