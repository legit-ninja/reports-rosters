# Plugin ZIP Installation Guide

**Issue**: "The plugin does not have a valid header" when installing from ZIP  
**Cause**: Incorrect ZIP folder structure

---

## ✅ The Problem & Solution

### Why It Happens

When WordPress installs a plugin from a ZIP file:
1. It extracts the ZIP to `/wp-content/plugins/`
2. It looks for a PHP file with a plugin header
3. **It expects the ZIP to contain a single folder with the plugin files inside**

### Incorrect ZIP Structure (Causes Error)

```
intersoccer-reports-rosters.zip
├── intersoccer-reports-rosters.php  ❌ Files at root of ZIP
├── includes/
├── classes/
└── languages/
```

**Result**: WordPress extracts to `/plugins/intersoccer-reports-rosters.zip/` and can't find the header.

### Correct ZIP Structure (Works)

```
intersoccer-reports-rosters.zip
└── intersoccer-reports-rosters/  ✅ Folder wrapping everything
    ├── intersoccer-reports-rosters.php
    ├── includes/
    ├── classes/
    └── languages/
```

**Result**: WordPress extracts to `/plugins/intersoccer-reports-rosters/` ✓

---

## 🚀 Quick Solution

### Method 1: Use the ZIP Creation Script (Recommended)

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-reports-rosters

# Create properly structured ZIP
./scripts/create-zip.sh
```

**Output**: `intersoccer-reports-rosters-1.11.3.zip` (ready to install)

This ZIP:
- ✅ Has correct folder structure
- ✅ Excludes development files (tests, docs, scripts)
- ✅ Only includes production-ready code
- ✅ Works with WordPress plugin installer

---

### Method 2: Manual ZIP Creation

If you need to create the ZIP manually:

#### On Linux/Mac:
```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/

# Important: Zip the FOLDER, not the contents
zip -r intersoccer-reports-rosters.zip intersoccer-reports-rosters/ \
    -x "*.git*" \
    -x "*node_modules/*" \
    -x "*vendor/*" \
    -x "*tests/*" \
    -x "*docs/*" \
    -x "*scripts/*" \
    -x "*.log" \
    -x "*.sh"
```

#### On Windows:
1. Navigate to `/home/jeremy-lee/projects/underdog/intersoccer/`
2. Right-click the `intersoccer-reports-rosters` **folder** (not its contents)
3. Choose "Send to" → "Compressed (zipped) folder"
4. Rename to `intersoccer-reports-rosters.zip`

**Critical**: Zip the **folder itself**, not the files inside!

---

### Method 3: Create Clean Production ZIP

For a clean ZIP without any development files:

```bash
cd /home/jeremy-lee/projects/underdog/intersoccer/

# Create temp copy
cp -r intersoccer-reports-rosters intersoccer-reports-rosters-clean

# Remove development files
cd intersoccer-reports-rosters-clean
rm -rf tests/ cypress/ node_modules/ vendor/ docs/ scripts/
rm -f *.log *.sh composer.* package.* phpunit.xml

# Go back and create ZIP
cd ..
zip -r intersoccer-reports-rosters.zip intersoccer-reports-rosters-clean/

# Rename the folder inside ZIP
# (This step ensures correct structure)
unzip intersoccer-reports-rosters.zip
mv intersoccer-reports-rosters-clean intersoccer-reports-rosters
zip -r intersoccer-reports-rosters-final.zip intersoccer-reports-rosters/

# Cleanup
rm -rf intersoccer-reports-rosters-clean intersoccer-reports-rosters
mv intersoccer-reports-rosters-final.zip intersoccer-reports-rosters.zip
```

---

## 📦 Installing the ZIP

### Via WordPress Admin (Recommended)

1. Log into WordPress admin on staging
2. Navigate to **Plugins** → **Add New**
3. Click **Upload Plugin** button
4. Choose the ZIP file: `intersoccer-reports-rosters-1.11.3.zip`
5. Click **Install Now**
6. Click **Activate Plugin**

**Should work without errors** ✓

---

### Via SFTP/SSH

```bash
# Upload ZIP to server
scp intersoccer-reports-rosters.zip user@staging-server:/tmp/

# SSH to server
ssh staging-server

# Extract to plugins directory
cd /path/to/wp-content/plugins/
unzip /tmp/intersoccer-reports-rosters.zip

# Verify structure
ls -la intersoccer-reports-rosters/
# Should show: intersoccer-reports-rosters.php and folders

# Set permissions
chmod 755 intersoccer-reports-rosters
chmod 644 intersoccer-reports-rosters/*.php

# Cleanup
rm /tmp/intersoccer-reports-rosters.zip
```

Then activate via WordPress admin.

---

## 🔍 Verify ZIP Structure

Before uploading, verify the ZIP is correct:

### Linux/Mac:
```bash
# List contents
unzip -l intersoccer-reports-rosters.zip | head -20

# Should show:
# intersoccer-reports-rosters/
# intersoccer-reports-rosters/intersoccer-reports-rosters.php
# intersoccer-reports-rosters/includes/
# etc.
```

### Windows:
1. Right-click ZIP → "Extract All" (to temp folder)
2. Check: Should see ONE folder `intersoccer-reports-rosters/`
3. Inside that folder: Should see `intersoccer-reports-rosters.php`

**If you see files at the root level** → ZIP is wrong, recreate it.

---

## 🎯 What Files to Include/Exclude

### ✅ Include (Production Files)
- `*.php` (all PHP files)
- `includes/` (required)
- `classes/` (required)
- `languages/` (translations)
- `assets/` (if you have CSS/JS)
- `README.md` (optional, for WordPress.org)

### ❌ Exclude (Development Files)
- `tests/` - PHPUnit tests
- `cypress/` - E2E tests
- `docs/` - Development documentation
- `scripts/` - Build/deploy scripts
- `node_modules/` - NPM dependencies
- `vendor/` - Composer dependencies
- `.git/` - Git repository
- `.gitignore` - Git config
- `*.log` - Log files
- `*.sh` - Shell scripts
- `composer.json`, `package.json` - Dependency manifests
- `phpunit.xml` - Test config
- `debug_*.php`, `temp_*.php` - Debug files

The `create-zip.sh` script handles all this automatically!

---

## 📝 Quick Reference

| Method | Use When | Command |
|--------|----------|---------|
| **Auto script** | Most cases | `./scripts/create-zip.sh` |
| **Manual (clean)** | Publishing | Zip the folder, exclude dev files |
| **Quick & dirty** | Testing only | `zip -r plugin.zip foldername/` |

---

## ✅ Success Checklist

After creating ZIP:
- [ ] ZIP contains ONE folder: `intersoccer-reports-rosters/`
- [ ] Inside that folder: `intersoccer-reports-rosters.php` exists
- [ ] No development files included (tests, docs, scripts)
- [ ] ZIP is under 5MB (WordPress upload limit on some hosts)
- [ ] Test installation on staging first
- [ ] Plugin activates without "invalid header" error

---

## 🚨 Common Mistakes

### Mistake 1: Zipping the Contents

```bash
# ❌ WRONG - Zips files at root level
cd intersoccer-reports-rosters/
zip -r ../plugin.zip *
```

```bash
# ✅ CORRECT - Zips the folder
cd ..
zip -r plugin.zip intersoccer-reports-rosters/
```

### Mistake 2: Wrong Folder Name in ZIP

If ZIP contains `intersoccer-reports-rosters-v1.11.3/` instead of `intersoccer-reports-rosters/`, WordPress will install to the wrong path.

**Fix**: Rename folder before zipping, or use the script.

### Mistake 3: Including Symlinks

If your plugin has symlinks, they might not extract correctly.

**Fix**: Use `rsync` to copy files (resolves symlinks), then zip.

---

**Status**: Ready to create proper installation ZIPs  
**Tool**: `./scripts/create-zip.sh` (automatic, production-ready)

