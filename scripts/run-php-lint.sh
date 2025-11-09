#!/bin/bash

# InterSoccer Reports & Rosters - PHP Lint Helper
#
# Usage:
#   ./scripts/run-php-lint.sh
#
# Lints all PHP files in the repository (excluding vendor, node_modules, tests, docs).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

echo "Running PHP lint checks..."

if ! command -v php >/dev/null 2>&1; then
    echo "Error: php executable not found in PATH." >&2
    exit 1
fi

EXIT_CODE=0

while IFS= read -r -d '' file; do
    if ! php -l "$file" >/dev/null; then
        echo "Lint failed: $file"
        EXIT_CODE=1
    fi
done < <(find "$REPO_ROOT" \
    \( -path "$REPO_ROOT/vendor" \
       -o -path "$REPO_ROOT/node_modules" \
       -o -path "$REPO_ROOT/tests" \
       -o -path "$REPO_ROOT/docs" \
       -o -path "$REPO_ROOT/.git" \) -prune -o \
    -type f -name "*.php" -print0)

if [ $EXIT_CODE -eq 0 ]; then
    echo "✅ PHP lint passed"
else
    echo "❌ PHP lint failed"
fi

exit $EXIT_CODE

