# Woo Order Item Sync Controls

This feature adds native WooCommerce admin controls to each line item on the order edit screen so admins can quickly check and repair roster sync issues.

## Where It Appears

- WooCommerce order edit screen (classic order editor and HPOS order editor)
- Under each line item meta block

Controls shown per line item:

- `Check Sync`
- `Fix Sync`

Both controls use standard WordPress/WooCommerce button and notice classes.

## What "Check Sync" Does

`Check Sync` calls `intersoccer_trace_reports_rosters_item` and reports whether the selected order item appears in `intersoccer_rosters`.

Typical outcomes:

- **In sync**: one roster row found for the line item
- **Out of sync**: missing roster row or multiple rows detected

## What "Fix Sync" Does

`Fix Sync` calls `intersoccer_fix_reports_rosters_item_safe` and runs safe, item-scoped repairs:

- insert missing roster placeholder row when Woo row exists
- backfill missing course-day item meta when roster row has the value
- quarantine orphan roster rows (missing in Woo)

The action is limited to the clicked order item and does not run a batch operation.

## Security

The AJAX endpoint requires:

- capability: `manage_options`
- nonce: `intersoccer_rebuild_nonce`

## Related Implementation Files

- `classes/woocommerce/hooks-manager.php`
- `classes/Admin/asset-manager.php`
- `js/order-item-sync-controls.js`
- `classes/Ajax/admin-tools-ajax-handler.php`
- `classes/services/reports-rosters-diagnostics-service.php`

## Woo Order Price Range Filters

The WooCommerce Orders list now supports two optional query params:

- `isrr_min_total`
- `isrr_max_total`

These render as `Min Total` and `Max Total` inputs on both:

- HPOS Orders list (`admin.php?page=wc-orders`)
- Classic Orders list (`edit.php?post_type=shop_order`)

Behavior:

- Min only: show orders with totals greater than or equal to Min.
- Max only: show orders with totals less than or equal to Max.
- Min + Max: show orders within range.
- If Min is greater than Max, values are auto-normalized (swapped) before query constraints are applied.

Implementation notes:

- Filter UI + query handlers are in `classes/woocommerce/hooks-manager.php`.
- Query constraints are additive and preserve existing Woo status/date/search filters.
