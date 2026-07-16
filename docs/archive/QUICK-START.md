# Quick Start Guide - InterSoccer Reports & Rosters

## 🚀 Deploy to Server

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-reports-rosters
./deploy.sh --clear-cache
```

---

## 🔍 Test Event Signature Verifier

**Location**: WP Admin → InterSoccer → Advanced → 🔍 Event Signature Verifier

### Quick Test:
1. Use **Quick Load** dropdown → Select a recent event
2. Click **📥 Load Selected Event**
3. Click **🔍 Test Signature Generation**
4. Copy the signature hash
5. Switch WPML language (EN → FR → DE)
6. Refresh page and repeat
7. **Verify all signatures are IDENTICAL** ✅

**Success**: Same event = Same signature in all languages!

---

## 🔄 Fix Girls Only → Regular Course Mistake

**Location**: WP Admin → InterSoccer → Courses → Girls Only → View Roster

### Step-by-Step:
1. Find the roster with incorrect registration
2. Click **👀 View Roster**
3. **Check** the player's checkbox (left column)
4. Action dropdown → Select **"Move to Another Roster"**
5. **✓ CHECK** the yellow warning box:
   ```
   ⚠️ Allow moving between Girls Only and Regular rosters
   ```
6. Destination dropdown → Select the correct **Regular Course**
   - Look for: `🏐 Summer Course - Geneva Centre... | ⚠️ Different Gender`
7. Click **Apply**
8. **Confirm** in the warning dialog
9. Wait for success message
10. **Verify** player moved successfully

**Success**: Player now appears in Regular Course roster! ✅

---

## 📊 What to Check After Deployment

### Event Signatures:
- [ ] Tool appears in Advanced page
- [ ] Quick Load shows recent events
- [ ] Dropdowns populate from taxonomies
- [ ] WPML language indicator shows
- [ ] Testing generates signatures
- [ ] Normalization shows in results

### Roster Migration:
- [ ] Cross-gender checkbox appears
- [ ] Cross-gender rosters show when enabled
- [ ] Enhanced labels with icons/badges
- [ ] Confirmation dialog shows warnings
- [ ] Migration completes successfully
- [ ] Order item updates correctly
- [ ] Roster database updates
- [ ] Player appears in new roster

### Debug Logging:
- [ ] Check `wp-content/debug.log` for signature logs
- [ ] Check for migration completion logs
- [ ] No errors in PHP error log

---

## 📚 Documentation Quick Links

| What You Need | Read This |
|---------------|-----------|
| Deploy to server | `DEPLOYMENT.md` |
| Understand event signatures | `MULTILINGUAL-EVENT-SIGNATURES.md` |
| Use signature verifier | `SIGNATURE-VERIFIER-USAGE.md` |
| Move player between rosters | `ROSTER-MIGRATION-READY.md` |
| Future improvements | `ROSTER-MIGRATION-IMPROVEMENTS.md` |
| Today's work summary | `SESSION-SUMMARY.md` |

---

## 🆘 Quick Troubleshooting

### Signatures Don't Match Across Languages
1. Check WPML string translations are complete
2. Verify term names are consistent
3. Check `debug.log` for normalization warnings
4. Use the Verifier tool to see what's changing

### Can't Move Player to Different Gender Type
1. Make sure you **checked** the cross-gender checkbox
2. Cross-gender rosters should appear in separate group
3. Look for "⚠️ Different Gender" in labels
4. Check debug.log for permission errors

### Deployment Fails
1. Verify `deploy.local.sh` has correct credentials
2. Test SSH connection manually
3. Check server disk space
4. Try `--dry-run` first to preview

---

## 💡 Pro Tips

1. **Always test with --clear-cache** after deployment
2. **Use Quick Load** instead of manual entry (faster!)
3. **Copy signatures** to a spreadsheet for comparison
4. **Enable WP_DEBUG** when debugging issues
5. **Document migrations** in order notes

---

## ✨ Key Features Summary

### Event Signature Verifier:
- 🎯 Interactive testing in admin UI
- 📥 Quick Load from recent events
- 🌍 WPML language awareness
- 📊 Visual normalization display
- 🔍 Detailed component breakdown

### Roster Migration:
- ⚠️ Cross-gender migration enabled
- 🏷️ Enhanced roster labels with icons
- 📋 Smart roster grouping
- 💬 Detailed confirmation dialogs
- 🔒 Safety features to prevent accidents

---

**Everything is ready to deploy and test! 🎉**

Command to deploy:
```bash
./deploy.sh --clear-cache
```

