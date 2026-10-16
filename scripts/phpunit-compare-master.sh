#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHPUNIT="${PHPUNIT:-$ROOT/vendor/bin/phpunit}"

run_suite() {
  local label="$1"
  local config="$2"
  local suite="$3"
  local outfile="$4"
  if [[ -n "$suite" ]]; then
    "$PHPUNIT" --configuration "$config" --testsuite "$suite" >"$outfile" 2>&1 || true
  else
    "$PHPUNIT" --configuration "$config" >"$outfile" 2>&1 || true
  fi
  local summary
  summary="$(grep -E '^(OK|Tests:|ERRORS!|FAILURES!)' "$outfile" | tail -3 | tr '\n' ' ')"
  local failures
  failures="$(grep -E '^\s*[0-9]+\)' "$outfile" | sed 's/^[[:space:]]*//' || true)"
  printf '%s\n' "$failures" >"${outfile}.failures"
  echo "$label|$summary"
}

compare_failures() {
  local a="$1"
  local b="$2"
  diff -u "$a" "$b" || true
}

WORKDIR="${TMPDIR:-/tmp}/is-phpunit-compare-$$"
mkdir -p "$WORKDIR"
trap 'rm -rf "$WORKDIR"' EXIT

cd "$ROOT"
git fetch origin master 2>/dev/null || true

for ref in origin/master HEAD; do
  name="${ref//\//-}"
  dir="$WORKDIR/$name"
  mkdir -p "$dir"
  git archive "$ref" | tar -x -C "$dir"
  (
    cd "$dir"
    if [[ ! -x vendor/bin/phpunit ]]; then
      composer install --no-interaction --ignore-platform-req=ext-gd >/dev/null 2>&1 || true
    fi
    run_suite "${ref}|default|phpunit.xml" phpunit.xml default "$WORKDIR/${name}-default.log"
    run_suite "${ref}|Production|phpunit.xml" phpunit.xml Production "$WORKDIR/${name}-production.log"
    run_suite "${ref}|phpunit.production.xml" phpunit.production.xml "" "$WORKDIR/${name}-production-config.log"
  )
done

echo "=== Summary ==="
cat "$WORKDIR"/*.log 2>/dev/null | head -1 || true
grep -h '|' "$WORKDIR"/*.log 2>/dev/null || for f in "$WORKDIR"/*-default.log; do head -1 "$f"; done

echo ""
echo "=== Failure name diff: default (master vs branch) ==="
compare_failures "$WORKDIR/origin-master-default.log.failures" "$WORKDIR/HEAD-default.log.failures"

echo ""
echo "=== Failure name diff: Production testsuite (master vs branch) ==="
compare_failures "$WORKDIR/origin-master-production.log.failures" "$WORKDIR/HEAD-production.log.failures"

echo ""
echo "=== Failure name diff: phpunit.production.xml (master vs branch) ==="
compare_failures "$WORKDIR/origin-master-production-config.log.failures" "$WORKDIR/HEAD-production-config.log.failures"
