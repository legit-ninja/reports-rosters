# InterSoccer Reports and Rosters

WordPress/WooCommerce plugin for event roster management, Final Numbers / Live Snapshot reports, and Excel exports.

## Version

- **Current Version:** 2.10.17

- Camp event dates prefer variation `_camp_start_date` / `_camp_end_date` (and order-item stamps) via `intersoccer_reports_resolve_camp_schedule()`. Parsing `camp_terms` strings is a **deprecated transitional fallback** until catalogue migration is complete.
- Final Numbers / Live Snapshot resolve year via `intersoccer_reports_resolve_program_year()`: order-item `Year` / `pa_program-year` → digits in season → event dates. Evergreen seasons (e.g. `Autumn`) no longer require year digits in the season string. Season close requires an explicit year.
- **Release Date:** October 1, 2026

## Owns

- `wp_intersoccer_rosters` table and order → roster sync
- Admin roster listings, details, analytics
- Final Camp / Course Reports and Live Snapshot
- Excel exports (PhpSpreadsheet) — Live sticky export and Office 365 checkbox are the main UX surfaces alongside the downloaded file
- Order-item Check Sync / Fix Sync / Roster Sync Queue

## Does not own

Cart/pricing (product-variations), player CRUD (player-management), CRM sync (intersoccer-crm).

## Dependencies

- WooCommerce
- InterSoccer Product Variations
- Player Management
- Optional: Customer Referral System (coach assignments — guarded)

## Documentation

Taxonomy, order-meta keys, and distressed bands: InterSoccer Project Context `docs/taxonomy-standard.md`.

Canonical guidance for agents and developers is in the InterSoccer workspace Cursor skill **reports-rosters** and the `reports-rosters-*` rules (see [docs/README.md](docs/README.md)).

Historical markdown: [docs/archive/](docs/archive/).

## Installation

1. Place plugin in `/wp-content/plugins/intersoccer-reports-rosters/`
2. Activate in WordPress admin (requires WooCommerce + sibling plugins)
3. Database schema is created/upgraded on activation

## Deploy / tests

```bash
./deploy.sh
# Production PHPUnit (includes Final Numbers accuracy):
vendor/bin/phpunit --testsuite=Production
```

## Pre-commit checks

A local pre-commit hook (`bin/pre-commit`) checks staged changes before each commit. It normally finishes in a few seconds.

What it checks:

- `php -l` on staged `.php` files (the staged version).
- PHPCS on staged `.php` files with the repo's phpcs config. If `vendor/bin/phpcs` or a phpcs config is missing (true today), this step is skipped with a warning.
- A fast PHPUnit subset: the `Production` testsuite, the same one CI runs (no WordPress or database). This repo commits runtime `vendor/` but not PHPUnit, so set `intersoccer.hooks.vendorDir` to a `vendor/` with the dev dependencies, or the step is skipped with a warning. It runs only when PHP, `phpunit.xml` or Composer files are staged, and it tests the working tree.
- Added lines must not contain internal hostnames (`*.local`, `*.lan`, `*.internal` and similar, or host names containing `staging`), IPv4 addresses (loopback, all-zero addresses and version numbers are allowed), private keys, or new `error_log(`, `var_dump(` or `print_r(` calls in PHP files. The author and committer email must not use a local domain.
- No staged deletions under `vendor/`.

Install once per clone (this covers every worktree of the clone):

```bash
bin/install-hooks.sh              # or: composer install-hooks
bin/install-hooks.sh --uninstall  # remove it again
```

PHP: the hook uses `php` from your PATH. If there is none, it runs PHP in the `php:8.x-cli` Docker image named at the top of `bin/pre-commit` (pull it first). PHPUnit needs `vendor/bin/phpunit`. In a worktree without its own `vendor/`, point the hook at one (mounted read-only, Docker runner only):

```bash
git config intersoccer.hooks.vendorDir /path/to/a/vendor   # or INTERSOCCER_HOOK_VENDOR=... for one run
```

Set `INTERSOCCER_HOOK_SKIP_PHPUNIT=1` to skip only the tests.

Local deny-list: list real internal hostnames, one per line, in `~/.config/intersoccer-hooks/denylist` or `.git/hooks-denylist`. Both are outside the tracked tree. Never commit their contents.

Bypass: `git commit --no-verify` skips every check for that one commit. Use it sparingly, for example for an intentional `vendor/` update.
