#!/bin/bash

# Create Installable Plugin ZIP
# WordPress requires specific folder structure for plugin installation

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

PLUGIN_SLUG="intersoccer-reports-rosters"
VERSION=$(grep "Version:" ${PLUGIN_SLUG}.php | awk '{print $3}')
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo -e "${BLUE}Creating installable plugin ZIP...${NC}"
echo ""

# Create temp directory
TEMP_DIR="/tmp/${PLUGIN_SLUG}-zip-$$"
mkdir -p "$TEMP_DIR/$PLUGIN_SLUG"

echo "📦 Copying plugin files..."

# Copy all necessary files, excluding development files
rsync -av \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='tests' \
    --exclude='cypress' \
    --exclude='scripts' \
    --exclude='docs' \
    --exclude='.phpunit.result.cache' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
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
    ./ "$TEMP_DIR/$PLUGIN_SLUG/"

# Remove README.md but keep it for WordPress.org compatibility if needed
# You can add README.md back with --include='README.md' if publishing to WordPress.org

echo "✓ Files copied"
echo ""

# Create ZIP from the temp directory
cd "$TEMP_DIR"
echo "📦 Creating ZIP archive..."
zip -r "$ZIP_NAME" "$PLUGIN_SLUG" -q

# Move ZIP to original directory
mv "$ZIP_NAME" "$OLDPWD/"

# Cleanup
cd "$OLDPWD"
rm -rf "$TEMP_DIR"

echo -e "${GREEN}✓ ZIP created: $ZIP_NAME${NC}"
echo ""
echo "File size: $(du -h $ZIP_NAME | cut -f1)"
echo ""
echo "This ZIP can be installed via:"
echo "  WordPress Admin → Plugins → Add New → Upload Plugin"
echo ""
echo "Or uploaded to server and extracted:"
echo "  unzip $ZIP_NAME -d /path/to/wp-content/plugins/"
echo ""

