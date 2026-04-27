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
