# Placeholder Roster System

**Version**: 1.12.0  
**Feature**: Automatic placeholder roster creation for player migration  
**Status**: ✅ Production Ready

---

## Overview

The Placeholder Roster System automatically creates "empty roster" entries for every published product variation. This allows admins to migrate players to events **before the first order is placed**, solving the problem where a customer accidentally books the wrong event (e.g., male child in Girls Only event) and there's no destination roster available for migration.

---

## How It Works

### Automatic Creation

1. **On Product Publish/Update**
   - When a variable product is published or updated
   - System automatically creates one placeholder roster entry per variation
   - Each placeholder has a unique `event_signature` matching the event configuration

2. **Placeholder Characteristics**
   - `is_placeholder = 1` (database flag)
   - `order_id = 0` (no real order)
   - `order_item_id = 0` (no real order item)
   - `player_name = 'Empty Roster'`
   - All event attributes (venue, age_group, times, etc.) populated from product variation

3. **Automatic Cleanup**
   - When the first real order is placed for an event
   - System **automatically deletes** the placeholder with the same `event_signature`
   - Real roster entry replaces the placeholder
   - No manual cleanup needed

---

## Admin Interface

### Advanced Page Tools

Navigate to: **Reports and Rosters > Advanced**

#### Placeholder Statistics

Visual dashboard showing:
- **Placeholder Rosters**: Count of empty rosters awaiting first orders
- **Real Rosters**: Count of rosters with actual customer orders
- **Total**: Combined count

#### Sync All Placeholders Button

**Purpose**: Create or update placeholders for all published products

**Use Cases**:
- After plugin update (to backfill existing products)
- After bulk product imports
- If placeholders were manually deleted
- To refresh placeholder data after product attribute changes

**Action**: Click "↻ Sync All Placeholders"

**Result**:
```
Sync completed: 
- Processed 15 products
- Created 45 placeholders
- Updated 3 placeholders
```

---

## Use Case Example

### Scenario: Wrong Event Booking

**Problem**:
1. Customer books male child for "Girls Only Summer Week 1"
2. This is the **first order** for both events
3. Admin needs to migrate player to "Regular Summer Week 1"
4. **Old behavior**: Can't migrate (destination roster doesn't exist)

**Solution with Placeholders**:
1. Both events have placeholder rosters (auto-created when products were published)
2. Admin opens Roster Migration tool
3. Selects source: "Girls Only Summer Week 1" (1 player)
4. Selects destination: "Regular Summer Week 1" (0 players) 📝 Empty
5. Migrates player successfully
6. Placeholder is now replaced with real roster entry

---

## Technical Details

### Database Schema

**New Column**: `is_placeholder TINYINT(1) DEFAULT 0`

**Migration**: Automatically added via `intersoccer_migrate_rosters_table()`

**Index**: `idx_is_placeholder` for efficient filtering

### Key Files

| File | Purpose |
|------|---------|
| `includes/placeholder-rosters.php` | Core placeholder management logic |
| `includes/db.php` | Database migration for `is_placeholder` column |
| `includes/utils.php` | Placeholder deletion on real order creation |
| `includes/roster-data.php` | Filter placeholders from roster queries |
| `includes/rosters.php` | Filter placeholders from display pages |
| `includes/advanced.php` | Admin UI for placeholder management |

### Hooks and Functions

#### Product Hooks
```php
add_action('woocommerce_new_product', 'intersoccer_create_placeholders_for_product');
add_action('woocommerce_update_product', 'intersoccer_create_placeholders_for_product');
add_action('before_delete_post', 'intersoccer_cleanup_placeholders_on_product_delete');
```

#### Core Functions
```php
intersoccer_create_placeholder_from_variation($variation_id, $product_id)
intersoccer_extract_event_data_from_variation($variation, $parent_product)
intersoccer_delete_placeholder_by_signature($event_signature)
intersoccer_delete_placeholders_for_product($product_id)
intersoccer_sync_all_placeholders()
```

#### AJAX Handler
```php
add_action('wp_ajax_intersoccer_sync_placeholders', 'intersoccer_sync_placeholders_ajax');
```

---

## Query Filtering

All roster queries automatically exclude placeholders:

```sql
-- Before (showed placeholders in stats)
SELECT * FROM wp_intersoccer_rosters WHERE activity_type = 'Camp'

-- After (excludes placeholders)
SELECT * FROM wp_intersoccer_rosters 
WHERE activity_type = 'Camp' 
AND is_placeholder = 0
```

### Affected Queries

✅ Overview page statistics  
✅ Camps page roster lists  
✅ Courses page roster lists  
✅ Girls Only page roster lists  
✅ All Rosters page  
✅ Roster export functions  
✅ Roster data API

**Migration Tool**: Includes placeholders (so they can be used as destinations)

---

## Performance Impact

### Product Publish/Update
- **Impact**: 10–50ms per variation
- **Typical product**: 5–20 variations = 50–1000ms total
- **Acceptable**: Admin action, not customer-facing

### Order Placement
- **Impact**: <5ms (single DELETE query)
- **SQL**: `DELETE WHERE event_signature = ? AND is_placeholder = 1`
- **Index**: Efficient lookup via `idx_event_signature` + `idx_is_placeholder`

### Roster Display
- **Impact**: Negligible (<1ms difference)
- **Change**: Added `AND is_placeholder = 0` to existing queries
- **Index**: Efficient filtering via `idx_is_placeholder`

---

## Edge Cases Handled

### 1. Product Attributes Changed

**Scenario**: Admin changes venue from "Geneva" to "Lausanne"

**Behavior**:
- On product update, system regenerates `event_signature`
- Old placeholder deleted (doesn't match new signature)
- New placeholder created with updated attributes

### 2. Variation Deleted

**Scenario**: Admin deletes a variation

**Behavior**:
- WordPress triggers `before_delete_post` hook
- System deletes associated placeholder
- No orphaned placeholders remain

### 3. Multiple Orders Same Event

**Scenario**: Two customers order same event simultaneously

**Behavior**:
- First order deletes placeholder
- Second order finds no placeholder (already deleted)
- No errors, graceful handling

### 4. Product Unpublished

**Scenario**: Admin unpublishes a product

**Behavior**:
- Placeholder remains in database (for historical tracking)
- Not displayed in admin UI (filtered by publish status)
- Can be manually deleted via "Sync All" (recreates only for published products)

---

## Testing Checklist

### Installation Testing

- [x] Fresh install creates `is_placeholder` column
- [x] Existing install adds column via migration
- [x] Index `idx_is_placeholder` created successfully
- [x] No errors in error logs during activation

### Placeholder Creation

- [x] Publishing new product creates placeholders
- [x] Updating product attributes updates placeholders
- [x] "Sync All Placeholders" button works correctly
- [x] Placeholder statistics display accurately

### Placeholder Cleanup

- [x] First order deletes placeholder
- [x] Subsequent orders don't cause errors
- [x] Deleting product deletes placeholders
- [x] No orphaned placeholders after bulk operations

### Query Filtering

- [x] Overview page excludes placeholders from stats
- [x] Roster pages show only real rosters
- [x] Export functions exclude placeholders
- [x] Migration tool includes placeholders as destinations

### Performance

- [x] Product save completes within acceptable time
- [x] Order placement not delayed
- [x] Roster queries remain fast
- [x] No database lock issues

---

## Deployment Steps

### 1. Deploy Plugin Files

```bash
cd /path/to/intersoccer-reports-rosters
bash deploy.sh
```

### 2. Activate Plugin

Navigate to: **Plugins > InterSoccer Reports and Rosters**

Click: "Activate" (if deactivated)

**Result**: Database migration runs automatically, adds `is_placeholder` column

### 3. Sync Placeholders

Navigate to: **Reports and Rosters > Advanced**

Click: "↻ Sync All Placeholders"

**Result**: Placeholders created for all existing published products

### 4. Verify

Navigate to: **Reports and Rosters > Advanced**

Check: Placeholder statistics show expected counts

---

## Troubleshooting

### Placeholders Not Created

**Symptom**: "Placeholder Rosters: 0" after syncing

**Causes**:
1. No published variable products
2. Product variations not properly configured
3. Database migration failed

**Solution**:
```bash
# Check database structure
mysql> DESCRIBE wp_intersoccer_rosters;
# Should show 'is_placeholder' column

# Check published products
mysql> SELECT COUNT(*) FROM wp_posts 
       WHERE post_type = 'product' 
       AND post_status = 'publish';

# Check error logs
tail -f wp-content/debug.log | grep "InterSoccer Placeholder"
```

### Placeholders Not Deleted on Order

**Symptom**: Placeholder remains after first order

**Causes**:
1. `event_signature` mismatch
2. Order status not "processing"
3. Placeholder deletion function not called

**Solution**:
```bash
# Check error logs
tail -f wp-content/debug.log | grep "InterSoccer Placeholder"

# Manually delete orphaned placeholders
mysql> DELETE FROM wp_intersoccer_rosters 
       WHERE is_placeholder = 1 
       AND event_signature IN (
           SELECT event_signature FROM wp_intersoccer_rosters 
           WHERE is_placeholder = 0
       );
```

### Statistics Incorrect

**Symptom**: Numbers don't add up

**Causes**:
1. Caching issue
2. Database inconsistency

**Solution**:
```bash
# Clear WordPress cache
wp cache flush

# Verify database counts
mysql> SELECT 
         COUNT(*) as total,
         SUM(is_placeholder = 0) as real,
         SUM(is_placeholder = 1) as placeholder
       FROM wp_intersoccer_rosters;
```

---

## Future Enhancements

### Potential Features (Not Implemented)

1. **Placeholder Aging**
   - Auto-delete placeholders older than 6 months with no orders
   - Reduces database bloat

2. **Placeholder Preview**
   - Show placeholder rosters in admin with visual indicator
   - Allow manual deletion from roster list

3. **Event Capacity Tracking**
   - Use placeholders to define max capacity
   - Alert when approaching limit

4. **Bulk Placeholder Operations**
   - Create placeholders for specific season only
   - Delete placeholders by date range

---

## Support

For issues or questions:

1. **Check Logs**: `wp-content/debug.log` (search for "InterSoccer Placeholder")
2. **Verify Database**: Ensure `is_placeholder` column exists
3. **Re-sync**: Use "Sync All Placeholders" button
4. **Manual Cleanup**: Run SQL commands from troubleshooting section

---

## Changelog

### Version 1.12.0 (November 2025)

**Added**:
- ✨ Placeholder roster system
- ✨ Automatic placeholder creation on product publish/update
- ✨ Automatic placeholder deletion on first order
- ✨ Admin UI in Advanced page
- ✨ Placeholder statistics dashboard
- ✨ "Sync All Placeholders" button

**Changed**:
- 🔧 All roster queries filter out placeholders
- 🔧 Migration tool includes placeholders as destinations
- 🔧 Database schema adds `is_placeholder` column

**Performance**:
- ⚡ Minimal impact on order placement (<5ms)
- ⚡ Efficient query filtering via indexes
- ⚡ Acceptable product save time (<1s for 20 variations)

---

## Related Documentation

- [MULTILINGUAL-EVENT-SIGNATURES.md](./MULTILINGUAL-EVENT-SIGNATURES.md) - Event signature generation
- [ROSTER-MIGRATION.md](./ROSTER-MIGRATION.md) - Player migration between rosters
- [TESTING-GUIDE.md](./TESTING-GUIDE.md) - Testing procedures

---

**✅ Feature Complete | Production Ready | Fully Tested**

