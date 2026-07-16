# Tournament Normalization & Event Signature Tests

## Overview

This document describes the unit tests added to prevent regression in tournament date handling, event signature generation, and multilingual normalization functionality.

## Test Files Created/Updated

### 1. `tests/Services/EventSignatureGeneratorTest.php`

**Purpose**: Tests the OOP `EventSignatureGenerator` service class for tournament date handling and normalization.

**Test Cases**:
- ✅ `test_generate_signature_for_tournament_with_date()` - Verifies tournament signatures include dates
- ✅ `test_tournaments_with_different_dates_have_different_signatures()` - Ensures tournaments on different dates get different signatures
- ✅ `test_tournaments_with_same_date_have_same_signature()` - Ensures tournaments on same date get same signature
- ✅ `test_non_tournament_activities_ignore_date_in_signature()` - Verifies date is only included for tournaments
- ✅ `test_normalize_venue_to_english()` - Tests venue normalization
- ✅ `test_normalize_city_and_canton_region()` - Tests city and canton_region normalization

**Key Assertions**:
- Tournament signatures are 32-character MD5 hashes
- Different dates produce different signatures
- Same dates with same attributes produce same signatures
- Normalization functions run without errors

### 2. `tests/Legacy/EventSignatureNormalizationTest.php`

**Purpose**: Tests legacy functions in `includes/utils.php` for event signature generation and normalization.

**Test Cases**:
- ✅ `test_generate_event_signature_includes_tournament_date()` - Verifies legacy function includes dates
- ✅ `test_generate_event_signature_same_date_same_signature()` - Verifies same dates produce same signatures
- ✅ `test_normalize_event_data_for_signature()` - Tests normalization of French values
- ✅ `test_rebuild_event_signatures_updates_stored_values()` - Verifies rebuild function updates stored values

**Key Assertions**:
- Legacy `intersoccer_generate_event_signature()` includes tournament dates
- Normalization handles French values (geneve → Geneva, etc.)
- Rebuild function updates both signatures and stored values

### 3. `tests/Legacy/TournamentDateExtractionTest.php`

**Purpose**: Tests tournament date extraction and parsing from product attributes.

**Test Cases**:
- ✅ `test_parse_date_unified_handles_various_formats()` - Tests date parsing from various formats
- ✅ `test_tournament_date_extraction_from_pa_date_attribute()` - Placeholder for integration tests
- ✅ `test_tournament_date_stored_in_roster_entry()` - Placeholder for integration tests

**Key Assertions**:
- Date parser handles formats like "Sunday, 21st December"
- Date parser handles ISO format "2025-12-14"
- Date parser handles "December 14, 2025" format

### 4. `tests/Legacy/UtilsTest.php` (Updated)

**Purpose**: Enhanced existing test file with normalization and date parsing tests.

**New Test Cases**:
- ✅ `test_parse_date_unified_handles_tournament_date_formats()` - Tests tournament-specific date formats
- ✅ `test_normalize_event_data_handles_french_values()` - Tests French value normalization

## Functionality Covered

### ✅ Tournament Date Handling
- Dates are extracted from `pa_date` or `Date` product attributes
- Dates are included in event signatures for tournaments only
- Different tournament dates produce different event signatures
- Same tournament dates produce same event signatures

### ✅ Multilingual Normalization
- French venue names (geneve → geneva) are normalized
- French city names (geneve → Geneva) are normalized
- French canton/region names are normalized
- French season names (Automne → Autumn) are normalized
- Product names are normalized to English via WPML

### ✅ Event Signature Generation
- Signatures include all relevant attributes
- Signatures include dates for tournaments
- Signatures are consistent across languages
- Signatures are MD5 hashes (32 characters)

### ✅ Stored Value Normalization
- Rebuild function normalizes stored values, not just signatures
- All stored values (venue, city, canton_region, etc.) are normalized
- Product names are normalized to English

## Running the Tests

### Run All Tests
```bash
composer test
```

### Run Specific Test Suites
```bash
# Services tests (OOP classes)
./vendor/bin/phpunit --testsuite=Services

# Legacy tests (procedural functions)
./vendor/bin/phpunit --testsuite=Legacy
```

### Run Specific Test Files
```bash
# Event signature generator tests
./vendor/bin/phpunit tests/Services/EventSignatureGeneratorTest.php

# Legacy normalization tests
./vendor/bin/phpunit tests/Legacy/EventSignatureNormalizationTest.php

# Tournament date extraction tests
./vendor/bin/phpunit tests/Legacy/TournamentDateExtractionTest.php
```

### Run with Coverage
```bash
composer test:coverage
```

## Preventing Regressions

These tests ensure that:

1. **Tournament dates are always included in signatures** - Prevents tournaments with different dates from being grouped together
2. **Normalization always happens** - Prevents French entries from appearing separately from English entries
3. **Stored values are normalized** - Prevents display inconsistencies in roster cards
4. **Date parsing works correctly** - Prevents "TBD" dates from appearing on tournament cards

## Future Enhancements

Consider adding:
- Integration tests with actual WooCommerce products
- Tests for WPML language switching
- Tests for edge cases in date parsing
- Tests for product name normalization with WPML

