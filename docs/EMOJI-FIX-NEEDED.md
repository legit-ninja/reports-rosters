# 🚨 URGENT: Emoji Fixes Needed Before Staging Deployment

**Status**: ⚠️ CRITICAL - Will cause WPML database errors on staging  
**Date**: November 2025  
**Issue**: 20+ emojis in translatable strings (same issue as Product Variations plugin)

---

## ❌ Problem

This plugin has **many emojis** in `_e()` and `__()` translatable strings. Staging database uses UTF8 encoding (not UTF8MB4), which cannot store 4-byte emoji characters.

**What will happen**: Plugin activation will fail on staging with WPML database error (same as Product Variations plugin)

---

## 📋 Files Requiring Fixes

### 1. `includes/reports.php` (✅ Partially Fixed)
- ✅ Line 429: `📊 Booking Report Dashboard` → `Booking Report Dashboard`
- ✅ Line 434: `🔍 Filter Options` → `Filter Options`
- ✅ Line 473: `📋 Columns to Display` → `Columns to Display`
- ✅ Line 498: `💡 ` → `Note: `
- ✅ Line 501: `📥 Export to Excel` → `↓ Export to Excel`
- ⏳ Line 493: `🔄 Loading data...` → `↻ Loading data...` (non-translatable, low priority)

### 2. `includes/reports-ui.php`
- ❌ Line 106: `📊 Final Numbers Report` → `Final Numbers Report`

### 3. `includes/rosters.php` (Multiple instances)
- ❌ Line 662: `📥 Export All Camps` → `↓ Export All Camps`
- ❌ Line 1003: `📥 Export All Courses` → `↓ Export All Courses`
- ❌ Line 1856: `📥 Export Other Events` → `↓ Export Other Events`
- ❌ Line 2054: `📥 Export All Rosters` → `↓ Export All Rosters`
- ❌ Line 651: `🔄 Reconcile Rosters` → `↻ Reconcile Rosters`
- ❌ Line 727: `🔄 Clear Filters` → `↻ Clear Filters`
- ❌ Line 754: `👥 ` → `Players: ` or just remove
- ❌ Line 757: `📚 ` → `Camps: ` or just remove
- ❌ Line 799: `👀 View Roster` → `View Roster`
- ❌ Line 2090-2091: `👥` and `📚` in `__()` calls → Remove emojis
- ❌ Line 2112: `👀 ` in `__()` call → Remove

### 4. `includes/advanced.php` (Multiple instances)
- ❌ Line 718: `🔍 Event Signature Verifier` → `Event Signature Verifier`
- ❌ Line 723: `📚 About Event Signatures:` → `About Event Signatures:`
- ❌ Line 748: `🌍 Current WPML Language:` → `Current WPML Language:`
- ❌ Line 804: `📥 Load Selected Event` → `↓ Load Selected Event`
- ❌ Line 1006: `🔍 Test Signature Generation` → `Test Signature Generation`
- ❌ Line 1015: `📊 Test Results` → `Test Results`
- ❌ Line 1070: `💡 Testing Instructions` → `Testing Instructions`

---

## 🔧 Quick Fix Commands

Run these `sed` commands to fix all files:

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-reports-rosters

# Fix reports-ui.php
sed -i "s/📊 Final Numbers Report/Final Numbers Report/g" includes/reports-ui.php

# Fix rosters.php
sed -i "s/📥 Export All Camps/↓ Export All Camps/g" includes/rosters.php
sed -i "s/📥 Export All Courses/↓ Export All Courses/g" includes/rosters.php
sed -i "s/📥 Export Other Events/↓ Export Other Events/g" includes/rosters.php
sed -i "s/📥 Export All Rosters/↓ Export All Rosters/g" includes/rosters.php
sed -i "s/🔄 Reconcile Rosters/↻ Reconcile Rosters/g" includes/rosters.php
sed -i "s/🔄 Clear Filters/↻ Clear Filters/g" includes/rosters.php
sed -i "s/👥 /Players: /g" includes/rosters.php
sed -i "s/📚 /Camps: /g" includes/rosters.php
sed -i "s/👀 View Roster/View Roster/g" includes/rosters.php

# Fix advanced.php
sed -i "s/🔍 Event Signature Verifier/Event Signature Verifier/g" includes/advanced.php
sed -i "s/📚 About Event Signatures:/About Event Signatures:/g" includes/advanced.php
sed -i "s/🌍 Current WPML Language:/Current WPML Language:/g" includes/advanced.php
sed -i "s/📥 Load Selected Event/↓ Load Selected Event/g" includes/advanced.php
sed -i "s/🔍 Test Signature Generation/Test Signature Generation/g" includes/advanced.php
sed -i "s/📊 Test Results/Test Results/g" includes/advanced.php
sed -i "s/💡 Testing Instructions/Testing Instructions/g" includes/advanced.php

# Verify fixes
./scripts/validate-compatibility.sh
```

---

## ✅ Validation

After fixes, run:

```bash
./scripts/validate-compatibility.sh
```

Expected output: `✅ ALL CHECKS PASSED`

---

## 📝 Emoji Replacement Guide

| Unsafe (4-byte) | Safe (UTF8) | Usage |
|-----------------|-------------|-------|
| 📊 Dashboard | (remove) | Headers |
| 🔍 Search/Filter | (remove) | Section titles |
| 📋 Columns | (remove) | Headers |
| 💡 Note | "Note:" | Help text |
| 📥 Download/Export | ↓ | Buttons |
| 🔄 Loading/Refresh | ↻ | Actions |
| 👥 People/Players | "Players:" or (remove) | Stats |
| 📚 Books/Camps | "Camps:" or (remove) | Stats |
| 👀 View/Eyes | (remove) | Buttons |
| 🌍 Globe/Language | (remove) | Labels |

---

## 🎯 Prevention

### Added Tools:
1. ✅ `scripts/validate-compatibility.sh` - Pre-deployment check
2. ✅ `docs/database-environments.yml` - Environment tracking
3. ✅ `docs/EMOJI-FIX-NEEDED.md` - This document

### Rules:
- ❌ **NEVER** use 4-byte emojis in `_e()` or `__()` calls
- ✅ **USE** basic Unicode: ▶ ■ ↓ ↻ ✓ ⚠ → ←
- ✅ Emojis **OK** in `console.log()` and `error_log()` (not translated)

---

## ⚡ Quick Fix Script

Created: `scripts/fix-emojis.sh`

```bash
chmod +x scripts/fix-emojis.sh
./scripts/fix-emojis.sh
```

This will fix all emojis automatically.

---

## 🚀 Deployment Checklist

Before deploying to staging:

- [ ] Run `./scripts/fix-emojis.sh` or manual `sed` commands above
- [ ] Run `./scripts/validate-compatibility.sh` (must pass)
- [ ] Test locally if possible
- [ ] Deploy to staging
- [ ] Activate plugin
- [ ] Verify no WPML errors

---

## 📞 If You Get WPML Errors on Staging

1. **Deactivate plugin** in WordPress
2. **Delete plugin** via WordPress admin  
3. **Run SQL cleanup**:
   ```sql
   DELETE FROM wp_1244388_icl_string_translations st
   INNER JOIN wp_1244388_icl_strings s ON st.string_id = s.id
   WHERE s.context = 'intersoccer-reports-rosters';
   
   DELETE FROM wp_1244388_icl_strings 
   WHERE context = 'intersoccer-reports-rosters';
   ```
4. **Redeploy** (after fixing emojis)
5. **Reactivate** plugin

---

**Status**: ⚠️ MUST FIX BEFORE STAGING DEPLOYMENT  
**Priority**: HIGH - Same issue as Product Variations plugin  
**Estimated time**: 5-10 minutes to fix all emojis

