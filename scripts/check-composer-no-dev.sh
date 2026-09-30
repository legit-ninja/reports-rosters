#!/bin/bash
# Verify committed Composer autoload maps do not reference dev dependencies.
# Exits 0 if clean, 1 if dev dependencies found.

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

DEV_PATTERNS='deep-copy|phpunit|mockery|brain/monkey|yoast'
FOUND_DEV=0

for file in vendor/composer/autoload_files.php \
            vendor/composer/autoload_static.php \
            vendor/composer/autoload_psr4.php \
            vendor/composer/autoload_classmap.php; do
    if [[ -f "$file" ]] && grep -E "$DEV_PATTERNS" "$file" >/dev/null 2>&1; then
        echo -e "${RED}ERROR: $file contains dev dependencies${NC}" >&2
        grep -E "$DEV_PATTERNS" "$file" >&2
        FOUND_DEV=1
    fi
done

if [[ $FOUND_DEV -eq 1 ]]; then
    echo ""
    echo -e "${RED}FAIL: Committed autoload maps reference dev packages.${NC}" >&2
    echo "Production activate will fatal with 'Failed opening required' errors." >&2
    echo "" >&2
    echo "Fix: run 'composer install --no-dev --optimize-autoloader' and commit the cleaned maps." >&2
    exit 1
fi

echo -e "${GREEN}OK: No dev dependencies in committed autoload maps.${NC}"
exit 0
