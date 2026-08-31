<?php

/**
 * Plan-024 — browser tests for the stock-alerts page inline
 * threshold-edit sheet (T7.1, T7.2, T7.3).
 *
 * Maps to TESTS.md browser scenarios:
 *   #1 — manager opens the sheet from an alert row, sees pre-filled values
 *   #2 — set min_stock=0 + Save → toast, sheet closes, alert auto-resolves
 *   #3 — set min_stock=10 + max_stock=5 → inline error, Save disabled
 *   #4 — shop-staff role hides the "Configure threshold" action
 *
 * These tests are SKIPPED by default. To run locally:
 *   1. `cd admin-web && pnpm dev` (or from umbrella root: `pnpm dev:admin`)
 *   2. Wait for the Next.js server to be reachable at the project URL
 *   3. Remove the `->skip(...)` calls below or pass `--without-skipped`
 *   4. `php artisan test --compact tests/Browser/Shop/StockAlertThresholdSheetTest.php`
 *
 * Use `mcp__playwright__browser_snapshot` to derive stable selectors against
 * the running page. The threshold sheet is rendered conditionally — open it
 * first (click `[data-slot=stock-alert-table]` row "..." → "Configure
 * threshold") before snapshotting its inputs.
 *
 * Each `it(...)` block MUST end with an assertion that the browser console
 * is empty.
 */
it('opens the threshold sheet with pre-filled min_stock from the active alert', function () {
    // Browser scenario #1 (TESTS.md)
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('auto-resolves the active alert when min_stock is set to 0', function () {
    // Browser scenario #2 (TESTS.md) — happy path
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('shows inline error and disables Save when min_stock > max_stock', function () {
    // Browser scenario #3 (TESTS.md) — validation
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('does not show the Configure threshold action for shop-staff users', function () {
    // Browser scenario #4 (TESTS.md) — authz
})->skip('Browser tests require running dev server — see file PHPDoc.');
