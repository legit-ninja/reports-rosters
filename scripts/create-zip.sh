#!/bin/bash
# Build a WordPress-admin install zip with production Composer vendor
# (composer install --no-dev). Does not modify the working-tree vendor/
# (keep with-dev for PHPUnit).

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

PLUGIN_SLUG="intersoccer-reports-rosters"
VERSION=$(grep "Version:" "${PLUGIN_SLUG}.php" | awk '{print $3}')
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

if ! command -v composer >/dev/null 2>&1; then
    echo -e "${RED}composer is required to build a production zip.${NC}" >&2
    echo "Install Composer, then re-run this script." >&2
    exit 1
fi

echo -e "${BLUE}Creating installable plugin ZIP (production vendor, --no-dev)...${NC}"
echo ""

TEMP_DIR="$(mktemp -d "/tmp/${PLUGIN_SLUG}-zip-XXXXXX")"
cleanup() {
    rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

STAGE="$TEMP_DIR/$PLUGIN_SLUG"
mkdir -p "$STAGE"

echo "Copying plugin files into staging (excluding working-tree vendor/)..."

rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='tests' \
    --exclude='cypress' \
    --exclude='docs' \
    --exclude='.phpunit.result.cache' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='phpunit.xml' \
    --exclude='*.log' \
    --exclude='debug.log' \
    --exclude='*.sh' \
    --exclude='*.md' \
    --exclude='*.list' \
    --exclude='*.zip' \
    --exclude='run-*.php' \
    --exclude='debug_*.php' \
    --exclude='temp_*.php' \
    --exclude='.DS_Store' \
    --exclude='*.swp' \
    --exclude='*~' \
    "$REPO_ROOT/" "$STAGE/"

# rsync --exclude='*.sh' would drop scripts/*.php is kept; ensure patch script + composer files exist
mkdir -p "$STAGE/scripts"
cp "$REPO_ROOT/scripts/patch-phpspreadsheet-zipstream.php" "$STAGE/scripts/"
cp "$REPO_ROOT/composer.json" "$REPO_ROOT/composer.lock" "$STAGE/"

echo "Running composer install --no-dev in staging (working-tree vendor/ unchanged)..."
(
    cd "$STAGE"
    composer install --no-dev --optimize-autoloader --no-interaction
)

rm -rf "$STAGE/scripts" "$STAGE/tests" "$STAGE/.git"

if [[ ! -f "$STAGE/vendor/autoload.php" ]]; then
    echo -e "${RED}Staging vendor/autoload.php missing after composer install.${NC}" >&2
    exit 1
fi
if [[ ! -d "$STAGE/vendor/ezyang/htmlpurifier" ]]; then
    echo -e "${RED}Expected production package vendor/ezyang/htmlpurifier is missing.${NC}" >&2
    exit 1
fi
if grep -E 'deep-copy|phpunit|mockery|brain/monkey' "$STAGE/vendor/composer/autoload_files.php" >/dev/null 2>&1; then
    echo -e "${RED}autoload_files.php still lists PHPUnit/dev packages; zip would fatal on Activate.${NC}" >&2
    exit 1
fi

echo "Creating ZIP archive..."
(
    cd "$TEMP_DIR"
    zip -r "$ZIP_NAME" "$PLUGIN_SLUG" -q
)
mv "$TEMP_DIR/$ZIP_NAME" "$REPO_ROOT/$ZIP_NAME"

echo -e "${GREEN}ZIP created: $ZIP_NAME${NC}"
echo "File size: $(du -h "$REPO_ROOT/$ZIP_NAME" | cut -f1)"
echo ""
echo "Working-tree vendor/ is unchanged (with-dev for PHPUnit)."
echo "This zip contains --no-dev vendor only. Do not copy local vendor/ into the zip by hand."
echo ""
echo "Install via WordPress Admin → Plugins → Add New → Upload Plugin"
echo "or: unzip $ZIP_NAME -d /path/to/wp-content/plugins/"
