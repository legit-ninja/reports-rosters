# Plugin Activation Error: "The plugin does not have a valid header"

**Error**: "The plugin does not have a valid header"  
**When**: Activating InterSoccer Reports and Rosters plugin on staging

---

## 🔍 Diagnosis Steps

### Step 1: Check Plugin File Location on Server

SSH to staging and verify the file structure:

```bash
# SSH to staging
ssh staging-server

# Check if plugin directory exists
ls -la /path/to/wp-content/plugins/intersoccer-reports-rosters/

# Check if main file exists
ls -la /path/to/wp-content/plugins/intersoccer-reports-rosters/intersoccer-reports-rosters.php

# Verify file permissions
ls -l /path/to/wp-content/plugins/intersoccer-reports-rosters/intersoccer-reports-rosters.php
```

**Expected**:
- Directory: `intersoccer-reports-rosters/`
- Main file: `intersoccer-reports-rosters.php` 
- Permissions: `-rw-r--r--` or `-rw-rw-r--` (readable by web server)

---

### Step 2: Check File Encoding on Server

```bash
# Check file type and encoding
file /path/to/wp-content/plugins/intersoccer-reports-rosters/intersoccer-reports-rosters.php

# Should output: "PHP script, ASCII text" or "PHP script, UTF-8 Unicode text"
```

**If you see**:
- `with BOM` → Remove BOM (see fix below)
- `with CRLF line terminators` → Convert to Unix line endings (see fix below)

---

### Step 3: Check Header Format

```bash
# View first 15 lines of the file
head -15 /path/to/wp-content/plugins/intersoccer-reports-rosters/intersoccer-reports-rosters.php
```

**Should see**:
```php
<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: Generates event rosters and reports for InterSoccer Switzerland admins using WooCommerce data.
 * Version: 1.11.3
 * Author: Jeremy Lee
 * Text Domain: intersoccer-reports-rosters
 * License: GPL-2.0+
 */
```

**Common issues**:
- Missing `<?php` on line 1
- Extra whitespace before `<?php`
- Missing `Plugin Name:` field
- File is empty or truncated

---

## 🔧 Common Fixes

### Fix 1: Folder Name Mismatch

**Problem**: WordPress expects plugin folder to match the slug.

**Check**:
```bash
# What's the actual folder name?
ls -d /path/to/wp-content/plugins/intersoccer*
```

**Expected**: `/path/to/wp-content/plugins/intersoccer-reports-rosters/`

**If different**: Rename folder to match:
```bash
cd /path/to/wp-content/plugins/
mv old-folder-name intersoccer-reports-rosters
```

---

### Fix 2: File Permissions

**Problem**: Web server can't read the file.

**Fix**:
```bash
cd /path/to/wp-content/plugins/intersoccer-reports-rosters/

# Set correct permissions
chmod 644 intersoccer-reports-rosters.php
chmod 755 .

# Set correct ownership (replace www-data with your web server user)
chown -R www-data:www-data .
```

---

### Fix 3: Remove BOM (Byte Order Mark)

**Problem**: File has UTF-8 BOM at start, WordPress can't parse header.

**Fix**:
```bash
# Remove BOM
sed -i '1s/^\xEF\xBB\xBF//' intersoccer-reports-rosters.php

# Or use vim
vim -c ':set nobomb' -c ':wq' intersoccer-reports-rosters.php
```

---

### Fix 4: Fix Line Endings (CRLF → LF)

**Problem**: Windows line endings instead of Unix.

**Fix**:
```bash
# Convert CRLF to LF
dos2unix intersoccer-reports-rosters.php

# Or use sed
sed -i 's/\r$//' intersoccer-reports-rosters.php
```

---

### Fix 5: Deployment Issue

**Problem**: Files didn't deploy correctly or are in wrong location.

**Fix**: Redeploy from local:

```bash
# On local machine
cd /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-reports-rosters

# Verify local file is correct
head -15 intersoccer-reports-rosters.php

# Redeploy
./deploy.sh
```

After redeployment, verify on server:
```bash
# SSH to staging
head -15 /path/to/wp-content/plugins/intersoccer-reports-rosters/intersoccer-reports-rosters.php
```

---

## 🎯 Quick Fix (Most Common)

**Most likely cause**: File permissions or deployment path issue.

Try this first:
```bash
# SSH to staging
ssh staging-server

# Navigate to plugins directory
cd /path/to/wp-content/plugins/

# Check if folder exists and is correct
ls -la intersoccer-reports-rosters/

# If it exists, fix permissions
chmod 755 intersoccer-reports-rosters
chmod 644 intersoccer-reports-rosters/*.php
chmod 755 intersoccer-reports-rosters/includes
chmod 644 intersoccer-reports-rosters/includes/*.php
```

Then try activating again in WordPress admin.

---

## 🚨 If Still Failing

### Enable WordPress Debug Mode

Edit `wp-config.php` on staging:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Try activating plugin again, then check:
```bash
tail -50 /path/to/wp-content/debug.log
```

This will show the actual error WordPress is encountering.

---

## 📋 Verification Checklist

- [ ] Plugin folder exists at `/path/to/plugins/intersoccer-reports-rosters/`
- [ ] Main file exists at `intersoccer-reports-rosters/intersoccer-reports-rosters.php`
- [ ] File permissions are `644` or `rw-r--r--`
- [ ] Folder permissions are `755` or `rwxr-xr-x`
- [ ] Web server user owns the files
- [ ] File starts with `<?php` (no BOM, no whitespace before)
- [ ] Header contains `Plugin Name:` field
- [ ] File is readable: `cat intersoccer-reports-rosters.php | head -15` works

---

## 💡 Alternative: Manual Check via Web Server

Create a test file to verify WordPress can read the plugin:

```bash
# On server, create test file
cat > /path/to/wp-content/plugins/test-plugin-read.php << 'EOF'
<?php
$file = WP_PLUGIN_DIR . '/intersoccer-reports-rosters/intersoccer-reports-rosters.php';

echo "File exists: " . (file_exists($file) ? 'YES' : 'NO') . "\n";
echo "Readable: " . (is_readable($file) ? 'YES' : 'NO') . "\n";
echo "File size: " . filesize($file) . " bytes\n";
echo "\nFirst 500 chars:\n";
echo substr(file_get_contents($file), 0, 500);
EOF
```

Then run via WordPress:
```bash
wp eval-file /path/to/wp-content/plugins/test-plugin-read.php
```

Or navigate to: `https://staging.intersoccer.ch/wp-content/plugins/test-plugin-read.php`

---

## 🔍 What to Look For

The output should show:
```
File exists: YES
Readable: YES
File size: 18757 bytes

First 500 chars:
<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: ...
```

If it shows `NO` or errors, that's your clue!

---

**Status**: Awaiting server diagnostics  
**Next**: Run Step 1 checks on staging server

