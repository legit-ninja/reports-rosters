# InterSoccer Reports and Rosters Plugin

## Overview
The `reports-rosters` plugin generates real-time rosters and reports for InterSoccer Switzerland’s soccer camps, courses, and birthday events. It integrates with WooCommerce (WooComm) and the `player-management-plugin` to provide coaches and event organizers with detailed attendee lists, leveraging data from the 2024 Spring Courses (605 total participants across regions like Versoix, Geneva, Zurich, etc.). This plugin is part of the InterSoccer Website Redesign project on WordPress (WP).

**Author**: Jeremy Lee

## Features
- **Roster Generation**:
  - Creates rosters for events, listing assigned players, event details (e.g., "Days-of-week" for camps, prorated weeks for courses), and attributes like venue and city.
  - Supports custom roles (`coach`, `event organizer`) for secure access.
- **Reports**:
  - Generates summaries of event participation (e.g., 40 girls booked with GIRLSFREE24 code in 2024).
  - Provides regional breakdowns (e.g., 144 participants in Geneva, 128 in Zurich).
- **Real-Time Updates**:
  - Rosters update dynamically as parents book events and assign players.
- **Export Options**:
  - Allows exporting rosters as CSV or PDF for offline use by coaches.

## Installation
1. **Clone the Repository**:
   ```bash
   git clone https://github.com/legit-ninja/reports-rosters.git
   ```
2. **Install Dependencies**:
   Copy the plugin folder to `wp-content/plugins/`. Ensure WordPress, WooCommerce, and `player-management-plugin` are installed.
3. **Activate Plugin**:
   In the WordPress admin panel, activate "InterSoccer Reports and Rosters".
4. **Configure Permissions**:
   Restrict roster access to `coach`, `event organizer`, and `shop_manager` roles.

## Usage
1. **Coach/Organizer Workflow**:
   - Access rosters via the WordPress admin dashboard or a custom frontend interface.
   - Filter rosters by event, region (e.g., Geneva, Nyon), or date.
   - Export rosters for offline use.
2. **Admin Workflow**:
   - Shop managers generate reports for participation trends (e.g., 78% of 2023 numbers in 2024).
   - View detailed breakdowns of bookings (e.g., 58 BuyClub numbers).
3. **Parent Workflow**:
   - Indirectly benefits parents by ensuring accurate event data for coaches.

## Development
- **Dependencies**: Requires WordPress, WooCommerce, `player-management-plugin`, and `intersoccer-product-variations`.
- **Testing**: Cypress tests are planned to validate roster accuracy and report generation.
- **Code Structure**:
  - `includes/`: Logic for roster and report generation.
  - `assets/`: JS and CSS for frontend report displays.
  - `admin/`: Admin interfaces for report management.

## Contribution
1. Fork the repository.
2. Create a feature branch (`git checkout -b feature/YourFeature`).
3. Commit changes (`git commit -m 'Add YourFeature'`).
4. Push to the branch (`git push origin feature/YourFeature`).
5. Open a Pull Request.

Adhere to WordPress coding standards and include tests for new features.

## Issues
Report bugs or suggest features via the [GitHub Issues](https://github.com/legit-ninja/reports-rosters/issues) page.

## License
GPLv2 or later, compatible with WordPress.
