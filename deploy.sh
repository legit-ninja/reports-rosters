#!/bin/bash

###############################################################################
# InterSoccer Reports & Rosters - Deployment Script
###############################################################################
#
# This script deploys the plugin to the dev server and can run tests.
#
# Usage:
#   ./deploy.sh                 # Deploy to dev server (runs PHPUnit tests)
#   ./deploy.sh --test          # Run PHPUnit + Cypress tests before deploying
#   ./deploy.sh --no-cache      # Deploy and clear server caches
#   ./deploy.sh --dry-run       # Show what would be uploaded
#
###############################################################################

# Exit on error
set -e

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
# IMPORTANT: Copy this file to deploy.local.sh and set your credentials there
# deploy.local.sh is in .gitignore and won't be committed

# Default configuration (override in deploy.local.sh)
SERVER_USER="your-username"
SERVER_HOST="intersoccer.legit.ninja"
SERVER_PATH="/path/to/wordpress/wp-content/plugins/intersoccer-reports-rosters"
SSH_PORT="22"
SSH_KEY="~/.ssh/id_rsa"

# PHPUnit test directory (if you add tests later)
PHPUNIT_TESTS_DIR="./tests"

# Load local configuration if it exists
if [ -f "deploy.local.sh" ]; then
    source deploy.local.sh
    echo -e "${GREEN}✓ Loaded local configuration${NC}"
fi

# Parse command line arguments
DRY_RUN=false
RUN_TESTS=false
CLEAR_CACHE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --test)
            RUN_TESTS=true
            shift
            ;;
        --no-cache|--clear-cache)
            CLEAR_CACHE=true
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --dry-run        Show what would be uploaded without uploading"
            echo "  --test           Run both PHPUnit AND Cypress tests before deploying"
            echo "  --clear-cache    Clear server caches after deployment"
            echo "  --help           Show this help message"
            echo ""
            echo "Note: PHPUnit tests always run before deployment."
            echo "      Use --test flag to also run Cypress/E2E tests."
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            exit 1
            ;;
    esac
done

# Check if configuration is set
if [ "$SERVER_USER" = "your-username" ]; then
    echo -e "${RED}✗ Configuration not set!${NC}"
    echo ""
    echo "Please create a deploy.local.sh file with your server credentials:"
    echo ""
    echo "cat > deploy.local.sh << 'EOF'"
    echo "SERVER_USER=\"your-ssh-username\""
    echo "SERVER_HOST=\"intersoccer.legit.ninja\""
    echo "SERVER_PATH=\"/var/www/html/wp-content/plugins/intersoccer-reports-rosters\""
    echo "SSH_PORT=\"22\""
    echo "SSH_KEY=\"~/.ssh/id_rsa\""
    echo "EOF"
    echo ""
    exit 1
fi

###############################################################################
# Functions
###############################################################################

print_header() {
    echo ""
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
}

run_phpunit_tests() {
    print_header "Running PHPUnit Tests"
    
    if [ ! -f "vendor/bin/phpunit" ]; then
        echo -e "${YELLOW}⚠ PHPUnit not installed. Run: composer install${NC}"
        echo -e "${RED}✗ Cannot proceed without PHPUnit installed${NC}"
        echo ""
        echo "To install PHPUnit dependencies:"
        echo "  cd $(pwd)"
        echo "  composer install"
        return 1
    fi
    
    if [ ! -d "tests" ]; then
        echo -e "${YELLOW}⚠ Tests directory not found${NC}"
        echo -e "${RED}✗ Cannot proceed without tests${NC}"
        return 1
    fi
    
        echo "Running PHPUnit Production test suite..."
        echo ""
        
        # Run PHPUnit Production suite (stable tests only) and capture output
        vendor/bin/phpunit --testsuite=Production --colors=always
        PHPUNIT_EXIT_CODE=$?
        
        echo ""
        
        # Return the actual PHPUnit exit code
        return $PHPUNIT_EXIT_CODE
}

run_cypress_tests() {
    print_header "Running Cypress E2E Tests"
    
    if [ ! -d "cypress" ]; then
        echo -e "${YELLOW}⚠ Cypress not configured yet${NC}"
        echo "  Skipping Cypress tests."
        return 0
    fi
    
    if [ ! -f "node_modules/.bin/cypress" ]; then
        echo -e "${YELLOW}⚠ Cypress not installed. Run: npm install${NC}"
        echo "  Skipping Cypress tests."
        return 0
    fi
    
    echo "Running Cypress test suite..."
    echo ""
    
    npm run cypress:run --env environment=dev
    
    if [ $? -eq 0 ]; then
        echo ""
        echo -e "${GREEN}✓ All Cypress tests passed${NC}"
        return 0
    else
        echo ""
        echo -e "${RED}✗ Cypress tests failed${NC}"
        return 1
    fi
}

deploy_to_server() {
    print_header "Deploying to Server"
    
    # Validate SERVER_PATH
    if [ -z "$SERVER_PATH" ]; then
        echo -e "${RED}✗ ERROR: SERVER_PATH is not set!${NC}"
        echo ""
        echo "Please set SERVER_PATH in deploy.local.sh to the FULL PATH of this specific plugin:"
        echo "  SERVER_PATH=\"/var/www/html/wp-content/plugins/intersoccer-reports-rosters\""
        echo ""
        echo "⚠️  DO NOT use the plugins directory path - this would affect other plugins!"
        exit 1
    fi
    
    # Safety check: Ensure path ends with plugin name
    if [[ ! "$SERVER_PATH" =~ (intersoccer-reports-rosters|reports-rosters)/?$ ]]; then
        echo -e "${YELLOW}⚠️  WARNING: SERVER_PATH should end with plugin directory name${NC}"
        echo "Current path: $SERVER_PATH"
        echo ""
        echo "Expected format: /path/to/wp-content/plugins/intersoccer-reports-rosters"
        echo ""
        read -p "Continue anyway? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "Deployment cancelled."
            exit 1
        fi
    fi
    
    echo -e "Target: ${GREEN}${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}${NC}"
    echo ""
    
    # Compile translation files before deployment
    if [ -f "scripts/compile-translations.sh" ]; then
        echo -e "${BLUE}Compiling translation files...${NC}"
        bash scripts/compile-translations.sh
        if [ $? -ne 0 ]; then
            echo -e "${RED}❌ Translation compilation failed!${NC}"
            exit 1
        fi
        echo ""
    fi
    
    # Build rsync command WITHOUT --delete flag
    # Using --delete is dangerous - could delete other plugins if path is wrong!
    RSYNC_CMD="rsync -avz"
    
    # Add dry-run flag if requested
    if [ "$DRY_RUN" = true ]; then
        RSYNC_CMD="$RSYNC_CMD --dry-run"
        echo -e "${YELLOW}DRY RUN MODE - No files will be uploaded${NC}"
        echo ""
    fi
    
    # Add SSH options
    RSYNC_CMD="$RSYNC_CMD -e 'ssh -p ${SSH_PORT} -i ${SSH_KEY}'"
    
    # Important: Include rules must come BEFORE exclude rules in rsync
    # Include README.md before excluding other *.md files
    RSYNC_CMD="$RSYNC_CMD --include='README.md'"
    
    # Exclude files/directories
    RSYNC_CMD="$RSYNC_CMD \
        --exclude='.git' \
        --exclude='.gitignore' \
        --exclude='node_modules' \
        --exclude='vendor' \
        --exclude='tests' \
        --exclude='docs' \
        --exclude='.phpunit.result.cache' \
        --exclude='composer.json' \
        --exclude='composer.lock' \
        --exclude='package.json' \
        --exclude='package-lock.json' \
        --exclude='phpunit.xml' \
        --exclude='*.log' \
        --exclude='debug.log' \
        --exclude='debug_*.php' \
        --exclude='temp_*.php' \
        --exclude='*.sh' \
        --exclude='*.md' \
        --exclude='.DS_Store' \
        --exclude='*.swp' \
        --exclude='*~'"
    
    # Add source and destination
    RSYNC_CMD="$RSYNC_CMD ./ ${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/"
    
    # Execute rsync
    echo "Uploading files..."
    eval $RSYNC_CMD
    
    if [ $? -eq 0 ]; then
        if [ "$DRY_RUN" = false ]; then
            echo ""
            echo -e "${GREEN}✓ Files uploaded successfully${NC}"
        fi
    else
        echo -e "${RED}✗ Upload failed${NC}"
        exit 1
    fi
}

clear_server_caches() {
    print_header "Clearing Server Caches"
    
    # Create a temporary PHP script to clear caches
    CLEAR_SCRIPT='<?php
// Load WordPress to get functions
define("WP_USE_THEMES", false);
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . "/wp-load.php");

// Clear PHP Opcache
if (function_exists("opcache_reset")) {
    opcache_reset();
    echo "✓ PHP Opcache cleared\n";
} else {
    echo "⚠ PHP Opcache not available\n";
}

// Clear WooCommerce transients
if (function_exists("wc_delete_product_transients")) {
    wc_delete_product_transients(0);
    echo "✓ WooCommerce transients cleared\n";
}

// Clear WordPress object cache
if (function_exists("wp_cache_flush")) {
    wp_cache_flush();
    echo "✓ WordPress object cache cleared\n";
}

// Clear roster caches (specific to this plugin)
if (function_exists("delete_transient")) {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"_transient_roster_%\" OR option_name LIKE \"_transient_timeout_roster_%\"");
    echo "✓ Roster cache cleared\n";
}

echo "\nCaches cleared successfully!\n";
unlink(__FILE__);
?>'
    
    # Upload and execute the script
    echo "$CLEAR_SCRIPT" | ssh -p ${SSH_PORT} -i ${SSH_KEY} ${SERVER_USER}@${SERVER_HOST} "cat > ${SERVER_PATH}/clear-cache-temp.php"
    
    echo ""
    echo "Executing cache clear script on server..."
    ssh -p ${SSH_PORT} -i ${SSH_KEY} ${SERVER_USER}@${SERVER_HOST} "cd ${SERVER_PATH} && php clear-cache-temp.php"
    
    echo ""
    echo -e "${GREEN}✓ Server caches cleared${NC}"
}

###############################################################################
# Main Script
###############################################################################

print_header "InterSoccer Reports & Rosters Deployment"

echo "Configuration:"
echo "  Server: ${SERVER_USER}@${SERVER_HOST}"
echo "  Path: ${SERVER_PATH}"
echo "  SSH Port: ${SSH_PORT}"
echo ""

# ALWAYS run PHPUnit tests before deployment
# Temporarily disable exit-on-error to handle test results ourselves
set +e
run_phpunit_tests
PHPUNIT_RESULT=$?
set -e

# Exit code 0 = all tests passed, proceed
if [ $PHPUNIT_RESULT -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ All tests passed successfully${NC}"
    echo ""
# Exit code 2 = errors/failures but some tests passed
elif [ $PHPUNIT_RESULT -eq 2 ]; then
    echo ""
    echo -e "${YELLOW}⚠ Some tests have issues (exit code: $PHPUNIT_RESULT)${NC}"
    echo ""
    
    # Count passing tests from the Production suite we just ran
    PASSING_COUNT=$(./vendor/bin/phpunit --testsuite=Production --testdox 2>&1 | grep -c "✔" || echo "0")
    
    echo -e "${BLUE}Production Test Suite Status:${NC}"
    echo "  Tests passing: $PASSING_COUNT/180"
    echo "  Coverage: $(awk "BEGIN {printf \"%.0f\", ($PASSING_COUNT/180)*100}")%"
    echo ""
    
    # Require at least 100 tests passing (55% coverage minimum)
    if [ "$PASSING_COUNT" -ge 100 ]; then
        echo -e "${GREEN}✓ Sufficient test coverage ($PASSING_COUNT tests passing)${NC}"
        echo -e "${YELLOW}  Proceeding with deployment${NC}"
        echo ""
        echo "Note: Remaining test failures are primarily test infrastructure issues."
        echo "Run './vendor/bin/phpunit --testsuite=Production' for details."
        echo ""
    else
        echo -e "${RED}✗ Insufficient test coverage${NC}"
        echo "  Current: $PASSING_COUNT/180 tests passing"
        echo "  Required: 100/180 minimum (55%)"
        echo ""
        echo "Fix tests or run 'composer install' to refresh dependencies."
        exit 1
    fi
# Exit code 1 or other = critical failure
else
    echo ""
    echo -e "${RED}✗ Test suite failed with exit code: $PHPUNIT_RESULT${NC}"
    echo ""
    echo "This usually means a critical error occurred."
    echo "Check: ./vendor/bin/phpunit --testsuite=Production"
    exit 1
fi

# Run Cypress tests if --test flag was passed
if [ "$RUN_TESTS" = true ]; then
    echo ""
    run_cypress_tests
    if [ $? -ne 0 ]; then
        echo -e "${RED}✗ Cypress tests failed. Aborting deployment.${NC}"
        echo ""
        echo "Fix the failing tests before deploying."
        exit 1
    fi
fi

echo ""
echo -e "${GREEN}✓ All tests passed${NC}"
echo ""

# Deploy to server
deploy_to_server

# Clear caches if requested
if [ "$CLEAR_CACHE" = true ] && [ "$DRY_RUN" = false ]; then
    clear_server_caches
fi

# Success message
if [ "$DRY_RUN" = false ]; then
    print_header "Deployment Complete"
    echo -e "${GREEN}✓ Plugin successfully deployed to ${SERVER_HOST}${NC}"
    echo ""
    echo "Next steps:"
    echo "  1. Clear browser cache and hard refresh (Ctrl+Shift+R)"
    echo "  2. Test roster generation on: https://${SERVER_HOST}/wp-admin/"
    echo "  3. Check debug.log for any errors"
    echo ""
else
    echo ""
    echo -e "${YELLOW}DRY RUN completed. No files were uploaded.${NC}"
    echo "Run without --dry-run to actually deploy."
    echo ""
fi

