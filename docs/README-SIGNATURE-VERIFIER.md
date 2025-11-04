# Event Signature Verifier - Feature Overview

## 🎉 What's New

We've added a comprehensive **Event Signature Verifier** tool to help you verify that multilingual roster grouping works correctly!

## ⚡ Key Features

### 1. **Smart Dropdowns from Live Data**
All form fields are populated with **actual terms from your WooCommerce system**:
- **Venues** from `pa_intersoccer-venues` taxonomy
- **Age Groups** from `pa_age-group` taxonomy  
- **Camp Terms** from `pa_camp-terms` taxonomy
- **Course Days** from `pa_course-day` taxonomy
- **Times** from `pa_camp-times` and `pa_course-times` taxonomies
- **Seasons** from `pa_program-season` taxonomy

**Benefits:**
- ✅ No typing errors
- ✅ Uses real data from your system
- ✅ See exactly what customers see in each language
- ✅ Fast testing with actual taxonomy terms

### 2. **Quick Load from Recent Orders**
Select from the **last 20 unique events** in your roster database:
- One-click loading of event data
- Shows event summary: "Camp: Geneva Centre - Summer Week 1 (Summer 2025)"
- Automatically populates all form fields
- Perfect for debugging production issues

**Use Cases:**
- Debug a specific roster that's splitting incorrectly
- Verify an event that just launched
- Re-test an event after WPML updates
- Train staff on how the system works

### 3. **WPML Language Indicator**
Shows which language the dropdowns are currently displaying:
```
🌍 Current WPML Language: Français (fr)
```

**Why This Matters:**
- Confirms you're testing in the expected language
- Reminds you to switch languages for multilingual testing
- Shows that dropdown values change based on WPML context

### 4. **Comprehensive Results Display**

After testing, you see:

#### Original Input Data
```json
{
  "venue": "Genève Centre",        // What you selected (French)
  "camp_terms": "Été Semaine 1...", 
  "season": "Été 2025"
}
```

#### Normalized Data (English)
```json
{
  "venue": "Geneva Centre",         // Converted to English
  "camp_terms": "Summer Week 1...",
  "season": "Summer 2025"
}
```

#### Changed Fields (Highlighted)
- `venue`: Genève Centre → Geneva Centre
- `camp_terms`: Été Semaine 1... → Summer Week 1...
- `season`: Été 2025 → Summer 2025

#### Generated Signature
```
a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6
```
Large, bold, green hash that's easy to copy and compare.

#### Signature Components
Shows exactly what values went into generating the hash (including slugs).

### 5. **Enhanced Debug Logging**
Every signature generation now logs three detailed entries:
1. Original event data (untranslated)
2. Normalized event data (English)
3. Final signature with identifying info

**Location:** `wp-content/debug.log`

## 🚀 How to Use (Quick Workflow)

### Testing a Specific Event:

1. **Go to**: WP Admin → InterSoccer → Advanced
2. **Scroll to**: Event Signature Verifier section
3. **Select**: Choose event from "Quick Load" dropdown
4. **Load**: Click "Load Selected Event"
5. **Test**: Click "Test Signature Generation"
6. **Copy**: Copy the signature hash
7. **Switch**: Change WPML language and refresh page
8. **Repeat**: Load same event (terms now translated) and test
9. **Compare**: Signature should be identical!

### Testing During Development:

1. **Create test order** in French
2. **Process order** (adds to roster)
3. **Open Verifier** tool
4. **Quick Load** the new event
5. **Test in French** → note signature
6. **Switch to English** and refresh
7. **Manually select** the same event (now in English)
8. **Test in English** → verify signature matches

## 📂 Documentation Structure

| Document | Purpose |
|----------|---------|
| `MULTILINGUAL-EVENT-SIGNATURES.md` | Complete technical documentation (480+ lines) |
| `SIGNATURE-VERIFIER-USAGE.md` | Quick usage guide for the testing tool |
| `SIGNATURE-VERIFICATION-SUMMARY.md` | Implementation summary for developers |
| `README-SIGNATURE-VERIFIER.md` | This file - feature overview |
| `DEPLOYMENT.md` | Deployment procedures |

## 🎯 Use Cases

### For Developers:
- Verify signature normalization logic works correctly
- Debug why rosters are splitting across languages
- Test after WPML configuration changes
- Validate code changes don't break signatures

### For Site Administrators:
- Ensure new camps/courses will group correctly
- Verify WPML translations are set up properly
- Troubleshoot roster grouping issues
- Train staff on multilingual system

### For QA/Testing:
- Validate multilingual functionality before launch
- Regression testing after plugin updates
- Confirm identical signatures across all languages
- Document test results with actual signatures

## 💡 Real-World Examples

### Example 1: New Summer Camp Launch

**Scenario**: You're launching "Summer Week 1" camp next week.

**Test Process:**
1. Quick Load → Select "Summer Week 1"
2. Test in English → Signature: `abc123...`
3. Switch to French, refresh
4. Quick Load → Select "Été Semaine 1" (same camp)
5. Test in French → Signature: `abc123...` ✅
6. Switch to German, refresh  
7. Quick Load → Select "Sommer Woche 1" (same camp)
8. Test in German → Signature: `abc123...` ✅

**Result**: Confident that all registrations will appear in ONE roster!

### Example 2: Debugging Split Rosters

**Scenario**: You notice "Geneva Summer Camp" has 3 separate rosters.

**Debug Process:**
1. Quick Load → Select one of the duplicate events
2. Note the signature it generates NOW
3. Check debug.log for when those orders were created
4. Compare signatures from debug.log with current signature
5. If different → WPML terms were updated/fixed after orders were placed
6. Run "Rebuild Event Signatures" to regenerate all signatures

### Example 3: After WPML Update

**Scenario**: You just added German translations to all taxonomy terms.

**Verification:**
1. Test an existing event in English → note signature
2. Switch to German (newly translated)
3. Test the same event → signature should match
4. If it matches → German customers will correctly group with existing rosters
5. If it differs → German term names don't match English equivalents

## 🏆 Why This is Awesome

### Before This Tool:
- ❌ Manual database queries to check signatures
- ❌ Create test orders in each language (slow!)
- ❌ Parse debug logs to understand normalization
- ❌ Guess why rosters are splitting
- ❌ Hope WPML is configured correctly

### With This Tool:
- ✅ Visual, interactive testing in admin UI
- ✅ One-click loading from real events
- ✅ Instant feedback on normalization
- ✅ Clear signature comparison
- ✅ Confidence in multilingual setup

## 📈 Impact

This tool prevents:
- **Roster Fragmentation**: Multiple rosters for the same event
- **Confused Staff**: Incomplete player counts
- **Parent Confusion**: Wrong roster lists sent to parents
- **Excel Export Chaos**: Same event split across rows
- **Last-Minute Panic**: Discover issues during season launch

## 🎓 Training Materials

Use this tool to train your team:
1. Show how WPML translations affect roster grouping
2. Demonstrate the normalization process visually
3. Teach staff to verify new events before launch
4. Build confidence in the multilingual system

## 📞 Quick Links

- **Full Documentation**: `MULTILINGUAL-EVENT-SIGNATURES.md`
- **Usage Guide**: `SIGNATURE-VERIFIER-USAGE.md`
- **Implementation Summary**: `SIGNATURE-VERIFICATION-SUMMARY.md`
- **Deployment Guide**: `DEPLOYMENT.md`

---

**Created**: November 3, 2025  
**Location**: WP Admin → InterSoccer → Advanced  
**Status**: ✅ Ready to Use

