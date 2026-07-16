# InterSoccer Reports and Rosters

WordPress/WooCommerce plugin for event roster management, Final Numbers / Live Snapshot reports, and Excel exports.

## Version

- **Current Version:** 2.7.16
- **Release Date:** July 16, 2026

## Owns

- `wp_intersoccer_rosters` table and order → roster sync
- Admin roster listings, details, analytics
- Final Camp / Course Reports and Live Snapshot
- Excel exports (PhpSpreadsheet)
- Order-item Check Sync / Fix Sync / Roster Sync Queue

## Does not own

Cart/pricing (product-variations), player CRUD (player-management), CRM sync (intersoccer-crm).

## Dependencies

- WooCommerce
- InterSoccer Product Variations
- Player Management
- Optional: Customer Referral System (coach assignments — guarded)

## Documentation

Canonical guidance for agents and developers is in the InterSoccer workspace Cursor skill **reports-rosters** and the `reports-rosters-*` rules (see [docs/README.md](docs/README.md)).

Historical markdown: [docs/archive/](docs/archive/).

## Installation

1. Place plugin in `/wp-content/plugins/intersoccer-reports-rosters/`
2. Activate in WordPress admin (requires WooCommerce + sibling plugins)
3. Database schema is created/upgraded on activation

## Deploy / tests

```bash
./deploy.sh
# Production PHPUnit (includes Final Numbers accuracy):
vendor/bin/phpunit --testsuite=Production
```
