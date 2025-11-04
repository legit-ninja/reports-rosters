# InterSoccer Reports & Rosters - Deployment Guide

This plugin uses an automated deployment script to upload files to the dev server.

## Quick Start

### 1. First-Time Setup

Copy the example configuration and set your credentials:

```bash
cp deploy.local.sh.example deploy.local.sh
```

Edit `deploy.local.sh` and set your server credentials:

```bash
SERVER_USER="your-ssh-username"
SERVER_HOST="intersoccer.legit.ninja"
SERVER_PATH="/var/www/html/wp-content/plugins/intersoccer-reports-rosters"
SSH_PORT="22"
SSH_KEY="~/.ssh/id_rsa"
```

**Note:** `deploy.local.sh` is in `.gitignore` and will never be committed to version control.

### 2. Deploy to Server

```bash
./deploy.sh
```

## Usage

### Basic Deployment
```bash
./deploy.sh
```
Uploads all plugin files to the dev server.

### Dry Run (Preview Changes)
```bash
./deploy.sh --dry-run
```
Shows what would be uploaded without actually uploading anything.

### Deploy with Cache Clearing
```bash
./deploy.sh --clear-cache
```
Uploads files and clears PHP opcache, WooCommerce transients, and roster caches on the server.

### Run Tests Before Deploying
```bash
./deploy.sh --test
```
Runs PHPUnit tests (if configured) before deploying. Deployment is aborted if tests fail.

### Combined Options
```bash
./deploy.sh --test --clear-cache
```
Runs tests, deploys, and clears caches.

## What Gets Deployed

The script uploads all plugin files **except**:
- `.git` directory
- `node_modules/`
- `vendor/`
- `tests/`
- `*.sh` files (deployment scripts)
- `*.log` files
- `composer.json`, `package.json`
- Temporary files (`*.swp`, `*~`, `.DS_Store`)

## What Gets Excluded

The following files/directories are automatically excluded from deployment:
- Development dependencies (`node_modules`, `vendor`)
- Test files and directories
- Version control files (`.git`, `.gitignore`)
- Build configuration files
- Log files
- Deployment scripts themselves

## Cache Clearing

When using `--clear-cache`, the script clears:
1. **PHP Opcache** - Ensures PHP loads the new code
2. **WooCommerce Transients** - Clears product/order caches
3. **WordPress Object Cache** - Clears general WordPress cache
4. **Roster Caches** - Clears plugin-specific roster transients

## Testing Integration

### PHPUnit Tests
If PHPUnit tests are configured in `tests/`, they will run when using `--test`:

```bash
./deploy.sh --test
```

The script gracefully skips tests if:
- PHPUnit is not installed
- `tests/bootstrap.php` is not configured
- WordPress test suite is not found

To enable PHPUnit tests:
1. Install PHPUnit: `composer install`
2. Configure `tests/bootstrap.php` with `WP_TESTS_DIR`
3. Install WordPress test suite

## Troubleshooting

### Permission Denied
```bash
chmod +x deploy.sh
```

### SSH Connection Failed
- Check your `deploy.local.sh` credentials
- Verify SSH key permissions: `chmod 600 ~/.ssh/id_rsa`
- Test SSH connection: `ssh -p 22 -i ~/.ssh/id_rsa user@intersoccer.legit.ninja`

### Deployment Fails Mid-Upload
- Run with `--dry-run` first to preview changes
- Check server disk space
- Verify file permissions on server

### Changes Not Visible After Deploy
1. Clear server caches: `./deploy.sh --clear-cache`
2. Clear browser cache (Ctrl+Shift+R / Cmd+Shift+R)
3. Check if file was actually uploaded (compare timestamps)
4. Check for PHP errors in `debug.log`

### Files Not Being Uploaded
- Check if they're in the exclude list
- Check `.gitignore` (some excluded files mirror .gitignore patterns)
- Use `--dry-run` to see what would be uploaded

## Security Notes

- **Never commit `deploy.local.sh`** - It contains your credentials
- The `.gitignore` is configured to exclude `deploy.local.sh`
- SSH keys should have proper permissions (`chmod 600`)
- Only deploy to dev/staging servers, never production without testing

## Advanced Usage

### Custom SSH Port
```bash
# In deploy.local.sh
SSH_PORT="2222"
```

### Different SSH Key
```bash
# In deploy.local.sh
SSH_KEY="~/.ssh/intersoccer_rsa"
```

### View Rsync Details
For detailed upload information, you can add `-v` flags to the rsync command in `deploy.sh`.

## Related Documentation

- **Multilingual Event Signatures**: See `MULTILINGUAL-EVENT-SIGNATURES.md` for how rosters handle multiple languages
- **Signature Verifier Tool**: See `SIGNATURE-VERIFIER-USAGE.md` for testing multilingual signatures
- **PHPUnit Testing**: See `tests/README.md` (when tests are added)
- **Plugin Architecture**: See main plugin documentation
- **Roster Generation**: See `/includes/rosters.php`

## Testing Multilingual Features

After deploying changes that affect event signatures or roster grouping:

1. **Use the Signature Verifier**: WP Admin → InterSoccer → Advanced → Event Signature Verifier
2. **Test across languages**: Switch WPML language and verify signatures remain identical
3. **Check roster grouping**: Verify the same event doesn't create multiple rosters
4. **Review debug logs**: Check for normalization warnings or errors

See `SIGNATURE-VERIFIER-USAGE.md` for detailed testing procedures.

## Support

For deployment issues:
1. Check this guide
2. Review error messages carefully
3. Test SSH connection manually
4. Verify server paths and permissions
5. Check server logs

