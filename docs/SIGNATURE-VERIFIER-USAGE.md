# Event Signature Verifier - Quick Usage Guide

## 🎯 Purpose

The Event Signature Verifier helps you verify that the same event generates **identical signatures** across all languages (English, French, German), ensuring rosters don't split into multiple groups.

## 📍 Location

**WP Admin → InterSoccer → Advanced → 🔍 Event Signature Verifier** (scroll down)

## ⚡ Quick Start (Fast Testing Method)

### Step 1: Load a Recent Event

1. In the **Quick Load from Recent Order** section:
   - Select an event from the dropdown (shows last 20 unique events)
   - Click **📥 Load Selected Event**
   - Form fields automatically populate with that event's data

### Step 2: Test in English

1. Make sure WPML is set to **English** (check the language indicator)
2. If not, switch WPML admin language to English and refresh the page
3. Load an event using Quick Load (or select manually)
4. Click **🔍 Test Signature Generation**
5. **Copy the signature** (the big green hash)

### Step 3: Test in French

1. Switch WPML admin language to **French**
2. Refresh the Advanced page
3. Load the **SAME event** (terms now show in French)
4. Click **🔍 Test Signature Generation**
5. **Compare the signature** - should be IDENTICAL to English

### Step 4: Test in German

1. Switch WPML admin language to **German**
2. Refresh the Advanced page
3. Load the **SAME event** (terms now show in German)
4. Click **🔍 Test Signature Generation**
5. **Compare the signature** - should be IDENTICAL to English and French

## ✅ Success Criteria

**All three languages generate the EXACT SAME signature!**

Example:
- English: `a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6`
- French:  `a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6` ✅
- German:  `a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6` ✅

## 🔧 Manual Testing Method

If you prefer to manually select attributes:

1. **Don't use Quick Load**
2. Manually select from each dropdown:
   - Venue (e.g., "Geneva Centre" in EN, "Genève Centre" in FR)
   - Age Group
   - Camp Terms or Course Day
   - Times
   - Season
   - Product ID
3. Follow Steps 2-4 above to test across languages

## 📊 Understanding the Results

After clicking "Test Signature Generation", you'll see:

### 1. Original Input Data
What you selected/entered (in whatever language)
```json
{
  "venue": "Genève Centre",
  "age_group": "5-13a (Journée complète)",
  "camp_terms": "Été Semaine 1 - juillet 7-juillet 11 - 5 jours",
  "season": "Été 2025"
}
```

### 2. Normalized Data (English)
How the system converted it to English
```json
{
  "venue": "Geneva Centre",
  "age_group": "5-13y (Full Day)",
  "camp_terms": "Summer Week 1 - July 7-July 11 - 5 days",
  "season": "Summer 2025"
}
```

### 3. Changed Fields
Highlights what was translated
- `venue`: Genève Centre → Geneva Centre
- `age_group`: 5-13a (Journée complète) → 5-13y (Full Day)
- `camp_terms`: Été Semaine 1... → Summer Week 1...
- `season`: Été 2025 → Summer 2025

### 4. Generated Event Signature
The final MD5 hash (should be identical across languages)
```
a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6
```

### 5. Signature Components
Shows slugs and final values used in the hash

## 🐛 Troubleshooting

### Problem: Different Signatures for Same Event

**Symptom**: English generates `abc123...` but French generates `xyz789...`

**Causes & Solutions:**

1. **Missing WPML Translation**
   - Check: Do all taxonomy terms have translations?
   - Fix: Go to **WPML → String Translation** and complete missing terms

2. **Inconsistent Term Names**
   - Check: Are term names consistent across languages?
   - Fix: Edit taxonomy terms to ensure they match

3. **Term Not Found**
   - Check `debug.log` for messages like "Could not find term..."
   - Fix: Verify the term exists in the taxonomy

### Problem: No Changes Shown (Already in English)

**Symptom**: "Changed Fields" shows nothing, but you entered French data

**Possible Causes:**
- WPML is not active
- You're viewing in English language context
- Terms were already in English

**This is OK if you're testing in English!**

### Problem: Dropdown is Empty

**Symptom**: A dropdown shows "-- Select --" but no options

**Causes:**
- No terms exist in that taxonomy yet
- Create terms via **Products → Attributes** in WooCommerce

## 💡 Pro Tips

### Tip 1: Test with Real Data
Use Quick Load to test with actual events from your system rather than making up data.

### Tip 2: Note Signatures in a Spreadsheet
Keep track of signatures for the same event across languages:
| Event | English Signature | French Signature | German Signature | Match? |
|-------|------------------|------------------|------------------|--------|
| Summer Week 1 | abc123... | abc123... | abc123... | ✅ |

### Tip 3: Test After WPML Changes
Whenever you:
- Add new taxonomy terms
- Update term translations
- Modify product attributes

Run this verifier to ensure signatures remain consistent.

### Tip 4: Use Debug Log
Enable `WP_DEBUG` and check `wp-content/debug.log` for detailed normalization traces:
```
[2025-11-03] InterSoccer Signature: Original event data: {...}
[2025-11-03] InterSoccer Signature: Normalized event data: {...}
[2025-11-03] InterSoccer Signature: Generated event_signature=abc123...
```

### Tip 5: Test Before Season Launch
Before launching a new season:
1. Create one test order in each language
2. Use this verifier to confirm they generate the same signature
3. Check the roster view to ensure they group together

## 🎓 Training Example

Here's a complete test scenario to train your team:

### Scenario: Summer Week 1 Camp at Geneva Centre

**Setup:**
- Product ID: 12345
- Venue: Geneva Centre
- Age Group: 5-13y (Full Day)
- Camp Terms: Summer Week 1 - July 7-July 11 - 5 days
- Season: Summer 2025

**Testing Process:**

1. **English Test:**
   - WPML Language: English
   - Quick Load → Select "Camp: Geneva Centre - Summer Week 1 (Summer 2025)"
   - Test → Note signature: `a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6`

2. **French Test:**
   - WPML Language: Français
   - Refresh page (dropdowns now show French terms)
   - Quick Load → Select "Camp: Genève Centre - Été Semaine 1 (Été 2025)"
   - Test → Signature should be: `a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6` ✅

3. **German Test:**
   - WPML Language: Deutsch
   - Refresh page (dropdowns now show German terms)
   - Quick Load → Select "Camp: Genf Zentrum - Sommer Woche 1 (Sommer 2025)"
   - Test → Signature should be: `a3f5b8c2d9e1f4a7b6c3d2e5f1a4b7c6` ✅

**Expected Result:**
- All three tests generate **IDENTICAL signatures**
- "Changed Fields" shows the translation transformations
- Roster reports will group all three orders together

## 📞 Support

If you encounter issues:
1. Check the **Testing Instructions** on the verifier page
2. Review `MULTILINGUAL-EVENT-SIGNATURES.md` for detailed documentation
3. Enable `WP_DEBUG` and check debug.log
4. Verify WPML string translations are complete

---

**Quick Reference:**
- **Tool Location**: WP Admin → InterSoccer → Advanced
- **Purpose**: Verify multilingual signature consistency
- **When to Use**: Before season launch, after WPML changes, when debugging roster splits
- **Success**: All languages → Same signature

