# OOP Migration Guide - Complete Framework

**Status**: 🟢 **HYBRID MODE ENABLED** - Framework Complete  
**Date**: November 5, 2025

---

## ✅ What's Been Completed

### Infrastructure (100% Complete)
1. ✅ Composer autoloader integrated into main plugin file
2. ✅ OOP Plugin class initialization
3. ✅ Adapter layer (`includes/oop-adapter.php`) created
4. ✅ Feature flag system implemented
5. ✅ Deprecation framework in place
6. ✅ Hybrid mode tested and working
7. ✅ Deploy script supports hybrid mode
8. ✅ All 126 tests still passing

### Sample Migrations (Demonstrating Pattern)
- ✅ Database: `intersoccer_create_rosters_table()` wrapped
- ✅ Database: `intersoccer_validate_rosters_table()` wrapped
- ✅ Orders: `intersoccer_process_existing_orders()` wrapped
- ✅ Orders: `intersoccer_process_existing_orders_ajax()` marked deprecated

---

## 🔄 Migration Pattern (Copy-Paste Template)

### For Any Legacy Function

**Step 1**: Add deprecation notice
```php
/**
 * @deprecated 2.0.0 Use InterSoccer\ReportsRosters\Namespace\ClassName::method()
 */
function intersoccer_legacy_function($param1, $param2) {
```

**Step 2**: Add OOP check at start
```php
    // Use OOP if feature enabled
    if (defined('INTERSOCCER_OOP_ENABLED') && INTERSOCCER_OOP_ENABLED && 
        function_exists('intersoccer_use_oop_for') && 
        intersoccer_use_oop_for('feature_name')) {
        return intersoccer_oop_equivalent_function($param1, $param2);
    }
    
    // Legacy implementation follows...
```

**Step 3**: Add adapter function in `oop-adapter.php`
```php
function intersoccer_oop_equivalent_function($param1, $param2) {
    try {
        $service = intersoccer_oop_get_service_instance();
        return $service->method($param1, $param2);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error - ' . $e->getMessage());
        return false; // Or appropriate default
    }
}
```

**Step 4**: Test both paths
```bash
# Test legacy (default)
# Feature flag is off, uses legacy code

# Enable OOP
update_option('intersoccer_oop_features', ['feature_name' => true]);

# Test OOP
# Should use new code

# Monitor logs for errors
```

---

## 📋 Complete Migration Checklist

### Database Functions (2/10 migrated)

- [x] `intersoccer_create_rosters_table()` → `Database::create_tables()`
- [x] `intersoccer_validate_rosters_table()` → `Database::validate_table_schema()`
- [ ] `intersoccer_migrate_rosters_table()` → `Database::migrate_tables()`
- [ ] `intersoccer_upgrade_database()` → `Database::upgrade()`
- [ ] `intersoccer_rebuild_event_signatures()` → Custom migration script
- [ ] 5 more database utility functions...

**Feature Flag**: `database`  
**Estimated Time**: 1 week  
**Priority**: HIGH

---

### Order Processing (2/15 migrated)

- [x] `intersoccer_process_existing_orders()` → `OrderProcessor::process_batch()`
- [x] `intersoccer_process_existing_orders_ajax()` → Marked deprecated
- [ ] `intersoccer_safe_populate_rosters()` → `RosterBuilder::build()`
- [ ] `intersoccer_manual_update_roster_entry()` → `RosterRepository::update()`
- [ ] `intersoccer_move_players_ajax()` → `AjaxHandler::handle_move_players()`
- [ ] 10 more order/roster functions...

**Feature Flag**: `orders`  
**Estimated Time**: 2 weeks  
**Priority**: HIGH

---

### Roster Pages (0/20 migrated)

**Functions to Migrate**:
- `intersoccer_render_all_rosters_page()` → `OverviewPage::render()`
- `intersoccer_render_camps_page()` → `CampPages::render()`
- `intersoccer_render_courses_page()` → `CoursesPage::render()`
- `intersoccer_render_girls_only_page()` → `GirlsOnlyPage::render()`
- `intersoccer_render_other_events_page()` → `OtherEventsPage::render()`
- `intersoccer_render_roster_details_page()` → Detail page class
- 14 more roster-related functions...

**Feature Flag**: `rosters`  
**Estimated Time**: 3 weeks  
**Priority**: MEDIUM

---

### Reports (0/25 migrated)

**Functions to Migrate**:
- `intersoccer_render_reports_page()` → `ReportsPage::render()`
- `intersoccer_render_final_camp_reports_page()` → `CampReport::generate()`
- `intersoccer_render_final_course_reports_page()` → `CourseReport::generate()`
- Report data functions → `CampReport` / `OverviewReport` methods
- Export functions → `ExcelExporter` / `CSVExporter`
- 20 more report functions...

**Feature Flag**: `reports`  
**Estimated Time**: 4 weeks  
**Priority**: MEDIUM

---

### Admin & Menus (0/15 migrated)

**Functions to Migrate**:
- Menu registration → `MenuManager::register_menus()`
- `intersoccer_render_plugin_overview_page()` → `OverviewPage::render()`
- `intersoccer_render_advanced_page()` → `AdvancedPage::render()`
- Asset enqueuing → `AssetManager`
- 11 more admin functions...

**Feature Flag**: `admin`  
**Estimated Time**: 2 weeks  
**Priority**: LOW (current works fine)

---

### AJAX Handlers (1/10 migrated)

- [x] `intersoccer_process_existing_orders_ajax()` → Marked deprecated
- [ ] `intersoccer_move_players_ajax()` → `AjaxHandler`
- [ ] `intersoccer_rebuild_rosters_and_reports()` → `AjaxHandler`
- [ ] `intersoccer_rebuild_event_signatures_ajax()` → `AjaxHandler`
- [ ] `intersoccer_get_rebuild_errors()` → `AjaxHandler`
- [ ] `intersoccer_clear_rebuild_data()` → `AjaxHandler`
- [ ] 4 more AJAX handlers...

**Feature Flag**: `ajax`  
**Estimated Time**: 1 week  
**Priority**: MEDIUM

---

### Utilities (0/8 migrated)

**Functions to Migrate**:
- Date formatting → `DateHelper`
- Validation functions → `ValidationHelper`
- Data sanitization → `ValidationHelper`
- 5 more utility functions...

**Feature Flag**: `utils`  
**Estimated Time**: 1 week  
**Priority**: LOW

---

## 🚀 Recommended Migration Schedule

### Week 1: Database (HIGH PRIORITY)
```bash
# Day 1-2: Migrate remaining database functions
# Day 3: Enable feature flag on dev
# Day 4-5: Test and monitor
```

### Week 2-3: Orders & Rosters (HIGH PRIORITY)
```bash
# Week 2: Migrate order processing functions
# Week 3: Migrate roster page functions
# Test thoroughly - this is customer-facing
```

### Week 4-5: Reports & Export (MEDIUM PRIORITY)
```bash
# Week 4: Migrate report generation
# Week 5: Migrate export functions
# Verify Excel/CSV exports work correctly
```

### Week 6-7: Admin & AJAX (MEDIUM PRIORITY)
```bash
# Week 6: Migrate admin pages and menus
# Week 7: Migrate AJAX handlers
# Test admin interface thoroughly
```

### Week 8: Cleanup & Release (LOW PRIORITY)
```bash
# Remove legacy includes/
# Update version to 2.0.0
# Documentation
# Deploy to production
```

---

## 🎯 Quick Start: Enable OOP Now

### On Dev Server (via wp-cli or code)

```bash
# Enable database functions
wp option update intersoccer_oop_features '{"database":true}' --format=json

# Monitor logs
tail -f /var/www/*/wp-content/debug.log | grep "InterSoccer OOP"

# Test database operations work
# Check admin pages load
# Verify no errors

# If issues:
wp option delete intersoccer_oop_features  # Instant rollback
```

### In PHP (via plugin or theme)
```php
// Enable one feature at a time
update_option('intersoccer_oop_features', ['database' => true]);

// Enable multiple
update_option('intersoccer_oop_features', [
    'database' => true,
    'orders' => true
]);

// Enable everything (full migration)
update_option('intersoccer_oop_features', ['all' => true]);

// Rollback
delete_option('intersoccer_oop_features');
```

---

## 📊 Migration Progress Tracking

### Current Status
- **Infrastructure**: 100% ✅
- **Database**: 20% (2/10 functions)
- **Orders**: 13% (2/15 functions)
- **Rosters**: 0%
- **Reports**: 0%
- **Admin**: 0%
- **AJAX**: 10% (1/10 functions)
- **Utils**: 0%

**Overall**: ~5% of 103 functions migrated

### Target Milestones
- **Week 2**: 25% migrated (database + orders)
- **Week 4**: 50% migrated (+ rosters)
- **Week 6**: 75% migrated (+ reports + admin)
- **Week 8**: 100% migrated (cleanup)

---

## 🛡️ Safety Features

### Feature Flags (Gradual Rollout)
- Start with `database` only
- Add `orders` after 1 week
- Add others incrementally
- Full rollback anytime

### Monitoring
```bash
# Watch for OOP usage
grep "InterSoccer OOP" debug.log

# Watch for errors
grep "InterSoccer OOP: Error" debug.log

# Watch for deprecation warnings
grep "deprecated" debug.log
```

### Performance Testing
```php
// Compare legacy vs OOP performance
$start = microtime(true);
// Run operation
$time = microtime(true) - $start;
error_log("Operation took: {$time}s");
```

---

## 📝 Migration Log Template

For each function migrated, document:

```markdown
### Function: intersoccer_function_name()

- **Date Migrated**: 2025-11-XX
- **OOP Equivalent**: ClassName::method()
- **Feature Flag**: feature_name
- **Testing**: ✅ Passed
- **Issues**: None / [list any]
- **Rollback**: Set flag to false
```

---

## 🎓 Best Practices

### DO:
- ✅ Migrate one function at a time
- ✅ Test each function thoroughly
- ✅ Monitor logs for errors
- ✅ Keep legacy as fallback
- ✅ Document each migration

### DON'T:
- ❌ Enable all features at once
- ❌ Remove legacy code immediately
- ❌ Skip testing
- ❌ Forget to monitor logs
- ❌ Deploy without local testing

---

## 🚨 Rollback Procedures

### Immediate Rollback (All Features)
```php
delete_option('intersoccer_oop_features');
// Plugin instantly uses legacy code
```

### Selective Rollback (One Feature)
```php
$features = get_option('intersoccer_oop_features', []);
$features['database'] = false; // Disable just database
update_option('intersoccer_oop_features', $features);
```

### Emergency Rollback (Code Level)
Comment out in `intersoccer-reports-rosters.php` line 32:
```php
// require_once $autoloader; // Disable OOP entirely
```

---

## 📈 Success Metrics

### For Each Migrated Feature

**Before Enabling**:
- [ ] All functions wrapped with deprecation notices
- [ ] All OOP adapters created
- [ ] Tests pass
- [ ] Local testing complete

**After Enabling**:
- [ ] Monitor logs for 24 hours
- [ ] No fatal errors
- [ ] Performance equivalent or better
- [ ] User functionality unchanged
- [ ] Can rollback if needed

**After 1 Week**:
- [ ] No issues reported
- [ ] Performance stable
- [ ] Consider next feature

---

## 🎯 Critical Path to 100% OOP

**Minimum Viable Migration** (4 weeks):
1. Week 1: Database + Orders
2. Week 2: Rosters  
3. Week 3: Reports
4. Week 4: Test + Deploy

**Full Migration** (8 weeks):
- As above + Admin + AJAX + Utils + Cleanup

**Current Status**: Week 0 (Infrastructure complete, ready to start Week 1)

---

## 📞 Need Help?

### Common Issues

**"OOP not enabled"**
- Check: Composer installed? (`vendor/autoload.php` exists?)
- Run: `composer install`

**"Function not found"**
- Check: Adapter function exists in `oop-adapter.php`?
- Add adapter function

**"Tests failing after migration"**
- Rollback: `delete_option('intersoccer_oop_features')`
- Debug: Check error logs
- Fix: Update OOP class or adapter

---

## ✅ Ready for Production

**Current deployment includes**:
- ✅ OOP code (ready but not active)
- ✅ Adapter layer (ready but not active)
- ✅ Legacy code (active, working)
- ✅ Feature flags (all disabled by default)

**After deployment**:
- Can enable OOP features via feature flags
- Zero downtime migration
- Instant rollback if needed

**Deploy**: `./deploy.sh` ✅

---

*Framework Complete: November 5, 2025*  
*Next Step: Enable database feature flag on dev server*  
*Timeline: 8 weeks to 100% OOP*

