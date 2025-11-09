# Database Operations Migration Plan

## Current Status ✅

### Already Implemented (OOP)
- **Database Core**:
  - ✅ Transaction support (begin, commit, rollback)
  - ✅ Table creation and schema management
  - ✅ Basic CRUD operations (insert, update, delete, select)
  - ✅ Table validation and existence checks

- **RosterRepository**:
  - ✅ find(), findMany(), findOrFail()
  - ✅ all(), where(), whereIn(), paginate()
  - ✅ create(), update(), delete(), save()
  - ✅ count(), exists()
  - ✅ Cache management
  - ✅ Transaction wrappers
  - ✅ rebuildFromOrders() (skeleton)

- **OOP Adapter Layer**:
  - ✅ intersoccer_oop_get_database()
  - ✅ intersoccer_oop_get_roster_repository()
  - ✅ intersoccer_oop_create_rosters_table()
  - ✅ intersoccer_oop_validate_rosters_table()
  - ✅ intersoccer_oop_get_rosters()
  - ✅ intersoccer_oop_get_roster()

## Remaining Legacy Functions to Migrate

### 1. Roster Rebuild Operations 🔴 **Priority: CRITICAL**

#### Legacy Functions:
```php
intersoccer_rebuild_rosters_and_reports()      // Full rebuild (drop + repopulate)
intersoccer_rebuild_rosters_and_reports_ajax() // AJAX handler
intersoccer_reconcile_rosters()                // Sync with current orders
intersoccer_reconcile_rosters_ajax()           // AJAX handler
```

#### OOP Migration Strategy:
**Create:** `classes/services/roster-builder.php`
- `RosterBuilder::rebuildAll($options)` - Full rebuild
- `RosterBuilder::reconcile($options)` - Sync with orders
- `RosterBuilder::processOrder($order_id)` - Process single order
- `RosterBuilder::processOrderItem($order_id, $item_id)` - Process single item

**Update:** `classes/ajax/roster-ajax-handler.php`
- `handle_rebuild_rosters()` - AJAX for rebuild
- `handle_reconcile_rosters()` - AJAX for reconcile

### 2. Single Entry Operations 🟡 **Priority: HIGH**

#### Legacy Functions:
```php
intersoccer_update_roster_entry($order_id, $item_id)
intersoccer_safe_update_roster_entry($order_id, $item_id)
intersoccer_prepare_roster_entry($order, $item, ...)
intersoccer_manual_update_roster_entry($order_id, $item_id, $variation_id)
```

#### OOP Migration Strategy:
**Create:** `classes/woocommerce/order-processor.php`
- `OrderProcessor::processOrderItem($order_id, $item_id)` - Main entry point
- `OrderProcessor::prepareRosterData($order, $item)` - Prepare data
- `OrderProcessor::validateOrderItem($order, $item)` - Validation
- `OrderProcessor::extractCustomerData($order)` - Extract parent data
- `OrderProcessor::extractPlayerData($item)` - Extract player data
- `OrderProcessor::extractEventData($item, $variation)` - Extract event data

### 3. Event Signature Operations 🟢 **Priority: MEDIUM**

#### Legacy Functions:
```php
intersoccer_generate_event_signature($data)
intersoccer_rebuild_event_signatures()
intersoccer_rebuild_event_signatures_ajax()
```

#### OOP Migration Strategy:
**Create:** `classes/services/event-signature-generator.php`
- `EventSignatureGenerator::generate($data)` - Generate signature
- `EventSignatureGenerator::rebuild()` - Rebuild all signatures
- `EventSignatureGenerator::normalize($data)` - Normalize data for signature

### 4. Database Upgrade Operations 🟢 **Priority: MEDIUM**

#### Legacy Functions:
```php
intersoccer_upgrade_database()
intersoccer_upgrade_database_ajax()
intersoccer_migrate_rosters_table()
```

#### OOP Migration Strategy:
**Create:** `classes/core/database-migrator.php`
- `DatabaseMigrator::upgrade()` - Run upgrade
- `DatabaseMigrator::getCurrentVersion()` - Get current schema version
- `DatabaseMigrator::runMigration($version)` - Run specific migration
- `DatabaseMigrator::addColumn($table, $column, $definition)` - Add column if missing
- `DatabaseMigrator::addIndex($table, $index, $columns)` - Add index if missing

### 5. Placeholder Operations 🟢 **Priority: LOW**

#### Legacy Functions:
```php
intersoccer_create_placeholders_for_product($product_id)
intersoccer_create_placeholder_from_variation($variation_id, $product_id)
intersoccer_delete_placeholder_by_signature($event_signature)
intersoccer_delete_placeholders_for_product($product_id)
intersoccer_cleanup_placeholders_on_product_delete($post_id)
```

#### OOP Migration Strategy:
**Create:** `classes/services/placeholder-manager.php`
- `PlaceholderManager::createForProduct($product_id)` - Create for product
- `PlaceholderManager::createFromVariation($variation_id)` - Create from variation
- `PlaceholderManager::deleteBySignature($signature)` - Delete by signature
- `PlaceholderManager::deleteForProduct($product_id)` - Delete for product
- `PlaceholderManager::cleanup()` - Cleanup orphaned placeholders

## Implementation Order

### Phase 1: Core Services (Week 1) ⏳
1. ✅ **OrderProcessor** - Process WooCommerce orders into roster entries
   - Extract data from orders/items
   - Validate data
   - Prepare roster entry data
   
2. ✅ **RosterBuilder** - Rebuild and reconcile rosters
   - Full rebuild from all orders
   - Reconcile with current orders
   - Batch processing with progress tracking

3. ✅ **EventSignatureGenerator** - Generate and manage event signatures
   - Normalize event data
   - Generate consistent signatures
   - Rebuild all signatures

### Phase 2: Database Utilities (Week 2)
4. **DatabaseMigrator** - Handle schema upgrades
   - Version tracking
   - Safe migrations
   - Rollback support

5. **PlaceholderManager** - Manage placeholder rosters
   - Create placeholders for future events
   - Sync with product changes
   - Cleanup orphaned placeholders

### Phase 3: AJAX Layer (Week 3)
6. **RosterAjaxHandler** - Handle AJAX requests
   - Rebuild rosters
   - Reconcile rosters
   - Upgrade database
   - Progress tracking
   - Error handling

### Phase 4: Adapter Layer Updates (Week 3)
7. **Update oop-adapter.php** - Route legacy calls to OOP
   - Add adapter functions for all migrated operations
   - Enable feature flags
   - Test backward compatibility

### Phase 5: Testing & Validation (Week 4)
8. **Integration Tests**
   - Test rebuild process
   - Test reconcile process
   - Test order processing
   - Test AJAX handlers

9. **Performance Testing**
   - Benchmark rebuild times
   - Test with large datasets (10k+ orders)
   - Monitor memory usage
   - Optimize bottlenecks

10. **Backward Compatibility**
    - Verify legacy code still works
    - Test feature flag toggling
    - Validate data consistency

## Success Criteria

- [ ] All database operations available via OOP classes
- [ ] All legacy functions route through adapter layer
- [ ] Feature flags control OOP usage
- [ ] No data loss during migration
- [ ] Performance equal or better than legacy
- [ ] 100% backward compatibility
- [ ] Comprehensive error logging
- [ ] Progress tracking for long operations
- [ ] Transaction support for data integrity
- [ ] Cache invalidation on updates

## Feature Flags

```php
// Enable specific OOP features
update_option('intersoccer_oop_use_database', true);
update_option('intersoccer_oop_use_order_processor', true);
update_option('intersoccer_oop_use_roster_builder', true);
update_option('intersoccer_oop_use_event_signatures', true);
```

## Rollback Plan

If issues arise:
1. Disable OOP feature flags
2. Legacy code continues to work
3. Fix issues in OOP code
4. Re-enable OOP features incrementally
5. Monitor error logs for any issues

## Notes

- Keep legacy functions intact with @deprecated tags
- Route through adapter layer, not direct replacement
- Add comprehensive error logging
- Use transactions for data integrity
- Implement progress tracking for long operations
- Clear caches after bulk operations
- Validate data before and after operations

