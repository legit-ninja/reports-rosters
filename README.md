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
- **Local Development**: Code locally, test on `dev.intersoccer.legit.ninja`
- **Version Control**: Commit to `github.com/legit-ninja/reports-rosters`
- **Deployment**: FTP deployment with staging verification
- **Testing**: PHPUnit unit tests and Cypress E2E tests
- **Code Quality**: Pre-commit hooks for automated testing

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

## Future Enhancements
- Google Sheets API integration for automated exports
- Mobile check-in applications for coaches
- Advanced reporting with custom date ranges
- API endpoints for external system integration
- Enhanced analytics with predictive modeling

## Troubleshooting
- **Debug Mode**: Enable `WP_DEBUG` for detailed error logging
- **Database Issues**: Use Advanced page rebuild functionality
- **Export Problems**: Check server memory limits and timeout settings
- **Permission Errors**: Verify user roles and capabilities
- **Performance**: Monitor database query performance and optimize indexes

## License
GPL-2.0+ - See LICENSE file for details.

## Contributors
- Jeremy Lee (Lead Developer)