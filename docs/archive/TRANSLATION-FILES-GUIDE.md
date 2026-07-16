# Translation Files Guide (.po and .mo files)

**Issue**: `.mo` files not deploying to production  
**Solution**: Compile before deployment and ensure they're committed to git

---

## 📋 Translation File Types

### `.pot` - Template File
- **What**: Master template with all translatable strings
- **Generated**: Manually or via tools
- **Tracked in git**: ✅ YES
- **Deployed**: ❌ NO (not needed on server)

### `.po` - Translation Source
- **What**: Human-readable translations (French, German)
- **Edited**: By translators or you
- **Tracked in git**: ✅ YES
- **Deployed**: ⚠️ Optional (nice to have but not required)

### `.mo` - Compiled Binary
- **What**: Machine-readable compiled translations
- **Generated**: From `.po` files using `msgfmt`
- **Tracked in git**: ✅ **YES - REQUIRED FOR DEPLOYMENT**
- **Deployed**: ✅ **YES - WORDPRESS NEEDS THESE!**

**Critical**: WordPress only reads `.mo` files, not `.po` files!

---

## ⚠️ The Problem

Your `.mo` files weren't being deployed because:
1. They weren't compiled (didn't exist locally)
2. They might not be tracked in git
3. Deploy script might exclude them

---

## ✅ The Solution

### Step 1: Compile `.mo` Files

**Automatic** (Recommended):
```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-reports-rosters
./scripts/compile-translations.sh
```

**Manual**:
```bash
cd languages/
msgfmt -o intersoccer-reports-rosters-fr_CH.mo intersoccer-reports-rosters-fr_CH.po
msgfmt -o intersoccer-reports-rosters-de_CH.mo intersoccer-reports-rosters-de_CH.po
msgfmt -o intersoccer-reports-rosters-en_CH.mo intersoccer-reports-rosters-en_CH.po
```

---

### Step 2: Add `.mo` Files to Git

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-reports-rosters

# Check if .gitignore excludes .mo files
grep "\.mo" .gitignore

# If it does, remove that line or add exception:
# !languages/*.mo

# Add .mo files to git
git add languages/*.mo

# Commit
git commit -m "Add compiled translation files (.mo)"
```

---

### Step 3: Verify Deploy Script Includes `.mo` Files

Check `deploy.sh` doesn't exclude `.mo` files:

```bash
grep "exclude.*\.mo" deploy.sh
```

If it does, remove that exclusion! `.mo` files are required for production.

---

### Step 4: Redeploy

```bash
./deploy.sh
```

The `.mo` files will now be included and WordPress translations will work!

---

## 🔄 Automated Workflow (Already Added)

The `deploy.sh` script now automatically:
1. Runs `scripts/compile-translations.sh` before deployment
2. Compiles all `.po` → `.mo` files
3. Includes `.mo` files in rsync
4. Deploys to server

**You don't need to manually compile** - just run `./deploy.sh` and it handles everything!

---

## ✅ Verify on Production

After deployment, SSH to production and check:

```bash
# Check if .mo files exist
ls -la /path/to/wp-content/plugins/intersoccer-reports-rosters/languages/*.mo

# Should show:
# intersoccer-reports-rosters-fr_CH.mo
# intersoccer-reports-rosters-de_CH.mo
# intersoccer-reports-rosters-en_CH.mo
```

---

## 🧪 Test Translations

### On French Site:
1. Navigate to plugin admin page
2. Verify UI shows French text (not English)
3. Check browser console for errors

### On German Site:
1. Same as above
2. Verify German translations appear

### Debug if Not Working:

Check WordPress debug.log for translation loading:
```php
// Should see in debug.log
InterSoccer Reports: Loaded translations from plugin directory: .../languages/intersoccer-reports-rosters-fr_CH.mo
```

---

## 📦 All Plugins - Translation File Checklist

Run this for **each plugin** with translations:

### intersoccer-reports-rosters ✅
```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-reports-rosters
./scripts/compile-translations.sh
git add languages/*.mo
git commit -m "Add compiled translation files"
./deploy.sh
```

### customer-referral-system
```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/customer-referral-system
# Create compile script (if doesn't exist)
mkdir -p scripts
cp ../intersoccer-reports-rosters/scripts/compile-translations.sh scripts/
./scripts/compile-translations.sh
git add languages/*.mo
./deploy.sh
```

### player-management
```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/player-management
mkdir -p scripts
cp ../intersoccer-reports-rosters/scripts/compile-translations.sh scripts/
./scripts/compile-translations.sh
git add languages/*.mo
./deploy.sh
```

---

## 🎯 Best Practices

### DO:
- ✅ Compile `.mo` files before deployment
- ✅ Track `.mo` files in git
- ✅ Deploy `.mo` files to server
- ✅ Use `scripts/compile-translations.sh` for automation
- ✅ Verify translations load (check debug.log)

### DON'T:
- ❌ Manually edit `.mo` files (they're binary)
- ❌ Exclude `.mo` from git (they're required)
- ❌ Exclude `.mo` from deployment
- ❌ Forget to recompile after editing `.po` files

---

## 🔍 Troubleshooting

### Problem: Translations don't appear on site

**Checks**:
1. `.mo` files exist on server? `ls languages/*.mo`
2. File permissions OK? `chmod 644 languages/*.mo`
3. Plugin loads translations? Check debug.log
4. WPML language set correctly?

### Problem: Old translations showing

**Fix**:
1. Clear WordPress cache
2. Clear WPML cache
3. Recompile `.po` → `.mo`
4. Redeploy

### Problem: Some strings not translated

**Fix**:
1. Check `.po` file has the string
2. Recompile: `msgfmt -o file.mo file.po`
3. Redeploy
4. Clear cache

---

## 📝 Quick Reference

| File | Purpose | Edit? | Track in Git? | Deploy? |
|------|---------|-------|---------------|---------|
| `.pot` | Template | No | Yes | No |
| `.po` | Translations | Yes | Yes | Optional |
| `.mo` | Compiled | No | **YES** | **YES** |

**Remember**: WordPress only reads `.mo` files!

---

**Status**: ✅ Script created, translations compiled  
**Next**: Redeploy to production with `.mo` files

