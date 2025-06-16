# InterSoccer Reports and Rosters Plugin

## Overview
The InterSoccer Reports and Rosters plugin is a WordPress extension designed to integrate with WooCommerce, enabling the generation of event rosters and reports for InterSoccer Switzerland's Camps, Courses, and other events. This plugin facilitates booking management, roster creation, and data export for administrators, coaches, and organizers, targeting Swiss parents, coaches, and admins.

## Version
- **Current Version:** 1.2.87
- **Release Date:** June 15, 2025

## Functionality

### Current Features
- **Roster Generation:**
  - Automatically generates rosters from WooCommerce orders when the order status changes from "Processing" to "Completed."
  - Stores roster data in a custom database table (`wp_intersoccer_rosters`) with fields including `order_item_id`, `player_name`, `first_name`, `last_name`, `age`, `gender`, `booking_type`, `selected_days`, `camp_terms`, `venue`, `parent_phone`, `parent_email`, `medical_conditions`, `late_pickup`, `day_presence`, `age_group`, `start_date`, `end_date`, `event_dates`, `product_name`, `activity_type`, and `updated_at`.
  - Provides a read-only Admin UI with tabs for "All Rosters," "Camps," "Courses," "Girls Only," and "Other Events," allowing filtering by venue and activity type.

- **Manual Management:**
  - **Reconcile Rosters:** Syncs the roster database with completed WooCommerce orders, adding missing entries and removing obsolete ones. Accessible via a button on each roster page.
  - **Rebuild Rosters:** Clears and rebuilds the roster database from all completed orders. Available via AJAX on the "Advanced" sub-menu page.

- **Export Capabilities:**
  - Exports all rosters to Excel (`.xlsx`) format via AJAX, including all columns for a direct database dump when selecting "All Rosters."
  - Supports CSV export for "All Rosters" on the "Advanced" page (implementation in progress).
  - Individual roster exports are available from the "Roster Details" page.

- **User Roles and Permissions:**
  - **Administrator:** Full access to all features.
  - **Coach:** Read-only access to rosters and export capabilities, with permission to reconcile and rebuild.
  - **Event Organizer/Shop Manager:** Similar to Coach, with export access.

- **Analytics and Reporting:**
  - Displays overview charts (age groups, genders, weekly trends, venue attendance) on the main plugin page.
  - Placeholder for future detailed reports (to be implemented).

### Roster Groupings
Rosters are organized and displayed based on the following groupings to provide a structured view for administrators, coaches, and organizers:

- **All Rosters:**
  - **Definition:** A comprehensive list of all roster entries across all activity types, grouped by venue. This serves as a master view for all events, including Camps, Courses, Girls Only, and Other Events.
  - **Grouping Logic:** Rosters are grouped by `venue` column, with each group showing the total number of players and a link to view details for the first order item ID in that group.
  - **Purpose:** Provides a high-level overview for administrative export and reconciliation.

- **Camps:**
  - **Definition:** Rosters for full-week or single-day Camps, identified by `activity_type = 'Camp'`.
  - **Grouping Logic:** Grouped by `product_name` and date range (`start_date` to `end_date`), with sub-grouping by `venue` within each camp term. Each group includes the total player count and a link to view details.
  - **Purpose:** Tailored for camp-specific management, reflecting holiday-adjusted schedules.

- **Courses:**
  - **Definition:** Rosters for weekly afterschool or weekend Courses, identified by `activity_type = 'Course'`.
  - **Grouping Logic:** Grouped by `venue`, with each group showing the total number of players and a link to view details for the first order item ID.
  - **Purpose:** Supports seasonal course tracking with prorated pricing considerations.

- **Girls Only:**
  - **Definition:** Rosters for Girls Only Camps, identified by `activity_type = 'Girls Only'`, typically 1-2 day events.
  - **Grouping Logic:** Listed individually by `product_name` and `venue`, with each entry showing one player (current limitation) and a link to view details.
  - **Purpose:** Caters to gender-specific event management.

- **Other Events:**
  - **Definition:** Rosters for miscellaneous events (e.g., Birthdays), identified by `activity_type IN ('Event', 'Other')`.
  - **Grouping Logic:** Grouped by `venue`, with each group showing the total number of players and a link to view details for the first order item ID.
  - **Purpose:** Handles ad-hoc event rosters outside standard Camps and Courses.

### Installation
1. Upload the plugin files to the `/wp-content/plugins/intersoccer-reports-rosters/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure WooCommerce and PhpSpreadsheet (via Composer) are installed and configured.

### Development Workflow
- **Coding:** Develop locally, test on `dev.intersoccer.legit.ninja`, commit to `github.com/legit-ninja/reports-rosters`, and deploy via FTP.
- **Debugging:** Enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php`, check `wp-content/debug.log`, and use SSH/SCP for file access if needed.
- **Testing:** Use PHPUnit for unit tests and Cypress for E2E tests. Follow the staging checklist (activate plugin, verify features, test exports).

### Future Enhancements
- Tailor exports for specific activity types with customized column sets.
- Implement Google Sheets and Office365 API integrations for automated exports.
- Add mobile check-in features for parents and coaches.
- Introduce administration fees for order changes or late pickups.

### License
This plugin is licensed under the GPL-2.0+ license. See `LICENSE` for details.