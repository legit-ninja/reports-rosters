# Woo Order Item Sync Controls

This feature adds native WooCommerce admin controls to each line item on the order edit screen so admins can quickly check and repair roster sync issues.

For bulk review of out-of-sync participants, use **Reports and Rosters → Roster Sync Queue** in the admin menu (or **Settings → Roster Sync Queue** tab). Direct URL: `admin.php?page=intersoccer-roster-sync-queue`.

## Where It Appears

- WooCommerce order edit screen (classic order editor and HPOS order editor)
- Under each line item meta block

Controls shown per line item:

- `Check Sync`
- `Fix Sync`
- `View Roster` (link, when a roster row exists for the line item)

Both sync controls use standard WordPress/WooCommerce button and notice classes. When no roster row exists yet, a muted **No roster yet** label is shown instead of the link.

## View Roster link

`View Roster` opens the admin **Roster Details** page for the **consolidated event group** that includes the line item—the same grouping used by **View Roster** on Courses/Camps listings (all `order_item_id` values sharing the consolidated roster key).

URL resolution (`intersoccer_get_roster_details_url_for_order_item()` in `includes/roster-details.php`):

1. Load roster row(s) for the `order_item_id`.
2. Find sibling roster rows (narrow DB query by variation/venue/course day or camp terms).
3. Filter siblings with `intersoccer_consolidated_roster_group_key()` so FR/DE/EN rows group together.
4. Prefer `event_signature` (stored or computed from roster facets) via `intersoccer_get_roster_details_url_for_listing_group()`.
5. Fall back to `order_item_ids` only when no signature can be resolved.

The link opens in the same tab (same as listing **View Roster** buttons).

## What "Check Sync" Does

`Check Sync` calls `intersoccer_trace_reports_rosters_item` and reports whether the selected order item appears in `intersoccer_rosters`.

Typical outcomes:

- **In sync**: one roster row found for the line item
- **Out of sync**: missing roster row or multiple rows detected

## What "Fix Sync" Does

`Fix Sync` calls `intersoccer_fix_reports_rosters_item_safe` and runs safe, item-scoped repairs:

- **full roster rebuild** from WooCommerce when the line item is missing, has placeholder rows (`Unknown Player` without `event_signature`), or incomplete player data
- insert minimal placeholder row only when rebuild still cannot produce a roster row
- backfill missing course-day item meta when roster row has the value
- quarantine orphan roster rows (missing in Woo)

The action is limited to the clicked order item and does not run a batch operation.

## Security

The AJAX endpoint requires:

- capability: `manage_options`
- nonce: `intersoccer_rebuild_nonce`

## Related Implementation Files

- `classes/woocommerce/hooks-manager.php`
- `includes/roster-details.php` (URL resolver: `intersoccer_get_roster_details_url_for_order_item()`)
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
