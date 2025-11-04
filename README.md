# InterSoccer Reports and Rosters Plugin

## Overview
The InterSoccer Reports and Rosters plugin is a comprehensive WordPress extension that integrates with WooCommerce to provide advanced event roster management, analytics, and reporting capabilities for InterSoccer Switzerland's sports programs. It automatically generates and maintains rosters from completed orders, provides detailed analytics dashboards, and offers sophisticated export functionality for administrators, coaches, and organizers.

## Version
- **Current Version:** 1.10.9
- **Release Date:** October 9, 2025

## Core Features

### Roster Management System
- **Automatic Roster Generation**: Creates roster entries when WooCommerce orders transition from "Processing" to "Completed"
- **Comprehensive Database**: Stores detailed player information in custom `wp_intersoccer_rosters` table with 50+ fields including player details, medical info, contact data, and event metadata
- **Real-time Synchronization**: Maintains roster accuracy through reconciliation with WooCommerce orders
- **Advanced Filtering**: Multi-tab interface with venue and activity type filtering

### Analytics Dashboard
- **Interactive Charts**: Built with Chart.js for real-time data visualization
- **Key Metrics**:
  - Current attendance by venue
  - Regional attendance distribution
  - Age group demographics
  - Gender distribution
  - Weekly attendance trends
- **Responsive Design**: Mobile-friendly admin interface

### Roster Organization
Rosters are intelligently grouped for efficient management:

- **All Rosters**: Master view grouped by venue with participant counts
- **Camps**: Full-week and single-day camps grouped by product name, term, and venue
- **Courses**: Seasonal courses grouped by venue with session tracking
- **Girls Only**: Gender-specific events with individual listings
- **Other Events**: Miscellaneous activities (birthdays, special events)

### Advanced Database Management
- **Reconciliation**: Syncs roster data with WooCommerce orders without changing order statuses
- **Rebuild Functionality**: Complete database reconstruction from order history
- **Schema Management**: Automatic table creation and upgrades with data preservation
- **Batch Processing**: Efficient handling of large datasets with progress tracking
- **Error Logging**: Comprehensive debugging and error tracking

### Export Capabilities
- **Excel Export**: Full-featured `.xlsx` exports using PhpSpreadsheet
- **Flexible Formats**: Different column sets optimized for each activity type
- **Phone Number Normalization**: Swiss phone number formatting (+41xxxxxxxxx)
- **Birth Date Processing**: Automatic date format standardization
- **Audit Logging**: Export activity tracking

### User Roles & Permissions
- **Administrator**: Full access to all features, database management, and exports
- **Coach**: Read-only roster access with export capabilities and reconciliation
- **Event Organizer/Shop Manager**: Export access with roster management permissions

### Integration Features
- **WooCommerce Integration**: Seamless order-to-roster conversion
- **Player Management**: Integration with player assignment system
- **Product Variations**: Works with complex product attribute structures
- **Late Pickup**: Handles add-on services and pricing
- **Discount Tracking**: Records pricing adjustments and sibling discounts

## Technical Architecture

### Database Schema
Custom roster table with comprehensive indexing:
- Player information (name, contact, medical, emergency contacts)
- Event details (dates, venue, activity type, pricing)
- Order integration (order_id, order_item_id, variation_id)
- Administrative fields (timestamps, audit trails)

### AJAX-Powered Interface
- Real-time roster updates without page refreshes
- Asynchronous export processing
- Progress tracking for long-running operations
- Dynamic filtering and search capabilities

### Security & Performance
- Nonce-based AJAX security
- Role-based access control
- Efficient database queries with proper indexing
- Memory management for large exports
- Input sanitization and validation

## Dependencies
- **Required Plugins**:
  - WooCommerce (core e-commerce functionality)
  - InterSoccer Product Variations (product type detection)
  - Player Management (player assignment system)
- **PHP Libraries**:
  - PhpSpreadsheet (^4.3) - Excel export functionality
- **JavaScript Libraries**:
  - Chart.js (3.9.1) - Analytics visualization
  - jQuery UI Datepicker - Date filtering

## Installation & Setup
1. Upload plugin files to `/wp-content/plugins/intersoccer-reports-rosters/`
2. Activate through WordPress admin
3. Ensure all required plugins are active
4. Run database setup (automatic on activation)
5. Configure user roles and permissions

## Development Workflow

### Quick Deployment
```bash
# First time setup
cp deploy.local.sh.example deploy.local.sh
nano deploy.local.sh  # Set your server credentials

# Deploy to dev server
./deploy.sh

# Deploy with cache clearing (recommended)
./deploy.sh --clear-cache

# Preview before deploying
./deploy.sh --dry-run

# Run tests before deploying (when configured)
./deploy.sh --test
```

### Multilingual Architecture (WPML Support)

This plugin supports **English, French, and German** via WPML. Critical multilingual features:

#### Event Signature System
**Problem**: Without normalization, the same event creates 3 separate rosters (one per language)

**Solution**: Event signatures normalize all translatable attributes to English before generating MD5 hash:
- Taxonomy terms (venue, age group, camp terms, etc.) → English term names
- String values (seasons, times) → English format
- Result: Identical signature for same event across all languages

**Testing**: Use **Event Signature Verifier** tool in WP Admin → InterSoccer → Advanced

**Implementation**:
```php
// Normalize to English, then generate signature
$normalized_data = intersoccer_normalize_event_data_for_signature($event_data);
$signature = intersoccer_generate_event_signature($normalized_data);
```

Key functions in `includes/utils.php`:
- `intersoccer_normalize_event_data_for_signature()` - WPML language switching & term normalization
- `intersoccer_generate_event_signature()` - MD5 hash generation from normalized data
- `intersoccer_get_term_by_translated_name()` - Find English term from translated name

#### Translatable Taxonomies
All product attributes that must be normalized:
- `pa_intersoccer-venues` - Venue locations
- `pa_age-group` - Age groups
- `pa_camp-terms` - Camp date ranges
- `pa_course-day` - Course weekdays
- `pa_camp-times` / `pa_course-times` - Time slots
- `pa_program-season` - Seasons (Summer/Été/Sommer)

### Version Control & Git
- **Repository**: Store in version control (GitHub/GitLab)
- **Branching**: Use feature branches for development
- **Commits**: Clear, descriptive commit messages
- **Code Review**: Review before merging to main

### Testing
- **PHPUnit Tests**: Unit tests in `tests/` directory (run with `vendor/bin/phpunit`)
- **Manual Testing**: Use admin tools (Event Signature Verifier, Roster Migration)
- **Debug Logging**: Enable `WP_DEBUG` and check `wp-content/debug.log`

### Code Quality
- Input sanitization on all user inputs
- Nonce verification on AJAX endpoints
- Prepared SQL statements (prevent injection)
- Comprehensive error logging
- Role-based permission checks

## Configuration
- **Database**: Automatic table creation and schema management
- **User Roles**: Custom roles for coaches and organizers
- **Export Settings**: Configurable export formats and data processing
- **Analytics**: Chart configuration and data aggregation settings

## Key Metrics & Monitoring
- Roster accuracy and synchronization rates
- Export processing times and success rates
- User adoption and feature utilization
- Database performance and query optimization

## Recent Enhancements (November 2025)

### Multilingual Event Signature System
- **Problem Solved**: Prevents roster fragmentation across languages (EN/FR/DE)
- **How**: Normalizes all translatable attributes to English before generating event signatures
- **Benefit**: Same physical event generates ONE roster, not three
- **Documentation**: See `docs/MULTILINGUAL-EVENT-SIGNATURES.md`

### Event Signature Verifier Tool
- **Location**: WP Admin → InterSoccer → Advanced → Event Signature Verifier
- **Features**: 
  - Smart dropdowns from live WooCommerce taxonomies
  - Quick Load from recent events
  - WPML language awareness
  - Live normalization preview
  - Signature comparison across languages
- **Usage**: See `docs/SIGNATURE-VERIFIER-USAGE.md`

### Enhanced Roster Migration
- **New**: Cross-gender roster migration (Girls Only ↔ Regular)
- **Features**:
  - Safety checkbox for cross-gender moves
  - Enhanced roster labels with icons and badges
  - Detailed confirmation dialogs
  - Smart roster grouping
- **Usage**: See `docs/ROSTER-MIGRATION-READY.md`

## Documentation

> **Note**: Comprehensive development documentation is available in the `docs/` folder of the repository but is **excluded from production deployment** for security reasons.

### For Developers (Repository Only):
The `docs/` folder contains detailed guides:
- **MULTILINGUAL-EVENT-SIGNATURES.md** - Technical deep-dive on multilingual roster grouping
- **SIGNATURE-VERIFIER-USAGE.md** - Event Signature Verifier tool usage
- **ROSTER-MIGRATION-READY.md** - Enhanced roster migration guide
- **ROSTER-MIGRATION-IMPROVEMENTS.md** - Future enhancement roadmap
- **DEPLOYMENT.md** - Complete deployment procedures
- **QUICK-START.md** - Quick reference for common tasks

### Deployment Exclusions
The deployment script (`deploy.sh`) automatically excludes:
- All markdown files except `README.md`
- `docs/` folder (development documentation)
- `tests/` directory (PHPUnit tests)
- Development files (`composer.json`, `*.sh`, `*.log`)
- Temporary files (`debug_*.php`, `temp_*.php`)

**Result**: Only production-ready code is deployed to the server, keeping internal documentation private.

## Future Enhancements
- Google Sheets API integration for automated exports
- Mobile check-in applications for coaches
- Advanced reporting with custom date ranges
- API endpoints for external system integration
- Enhanced analytics with predictive modeling
- Automated parent email notifications for roster changes
- Migration audit log and undo functionality

## Troubleshooting
- **Debug Mode**: Enable `WP_DEBUG` for detailed error logging
- **Database Issues**: Use Advanced page rebuild functionality
- **Export Problems**: Check server memory limits and timeout settings
- **Permission Errors**: Verify user roles and capabilities
- **Performance**: Monitor database query performance and optimize indexes
- **Multilingual Issues**: Use Event Signature Verifier tool (WP Admin → InterSoccer → Advanced)
- **Migration Issues**: Use enhanced roster migration (View any roster → Player Management section)
- **Full Documentation**: Available in `docs/` folder of the repository

## License
GPL-2.0+ - See LICENSE file for details.

## Contributors
- Jeremy Lee (Lead Developer)