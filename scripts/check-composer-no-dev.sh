#!/bin/bash
# Fail if the git index (what would be committed) includes Composer require-dev
# packages or autoload maps that reference them. Working-tree vendor/ may stay
# with-dev for PHPUnit; only staged/index content is checked.
#
# Usage:
#   ./scripts/check-composer-no-dev.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# require-dev from composer.json, plus myclabs/deep-copy (PHPUnit files autoload).
# Do not match myclabs/php-enum (production).
CONTENT_PATTERN='phpunit/|yoast/phpunit|mockery/|brain/monkey|myclabs/deep-copy'
PATH_PATTERN='(^|/)vendor/(phpunit|yoast|mockery|brain)(/|$)|(^|/)vendor/myclabs/deep-copy(/|$)'

AUTOLOAD_INDEX_PATHS=(
    vendor/autoload.php
    vendor/composer/autoload_files.php
    vendor/composer/autoload_static.php
    vendor/composer/autoload_psr4.php
    vendor/composer/autoload_classmap.php
    vendor/composer/autoload_namespaces.php
)

failed=0

fail() {
    echo "Error: $*" >&2
    failed=1
}

if ! command -v git >/dev/null 2>&1; then
    echo "Error: git not found." >&2
    exit 1
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Error: not a git repository." >&2
    exit 1
fi

echo "Checking git index for Composer require-dev packages..."

staged_files=()
while IFS= read -r path; do
    [[ -n "$path" ]] && staged_files+=("$path")
done < <(git diff --cached --name-only --diff-filter=ACMR)

for path in "${staged_files[@]+"${staged_files[@]}"}"; do
    if echo "$path" | grep -Eq "$PATH_PATTERN"; then
        fail "staged path is a Composer require-dev package: $path"
    fi
done

for rel in "${AUTOLOAD_INDEX_PATHS[@]}"; do
    if ! git cat-file -e ":$rel" 2>/dev/null; then
        continue
    fi
    if git show ":$rel" | grep -Eq "$CONTENT_PATTERN"; then
        fail "index $rel references Composer require-dev (phpunit/mockery/brain/yoast/deep-copy). Restore production autoload maps (composer install --no-dev in a staging copy, or scripts/create-zip.sh); do not commit with-dev autoload_*.php."
    fi
done

for path in "${staged_files[@]+"${staged_files[@]}"}"; do
    case "$path" in
        *.zip)
            if git show ":$path" 2>/dev/null | unzip -l - 2>/dev/null | grep -Eq "$PATH_PATTERN|$CONTENT_PATTERN"; then
                fail "staged zip $path contains Composer require-dev paths. Use scripts/create-zip.sh (--no-dev)."
            fi
            ;;
    esac
done

if [[ "$failed" -ne 0 ]]; then
    echo "Composer no-dev check failed." >&2
    exit 1
fi

echo "Composer no-dev check passed."
exit 0
