<?php

/**
 * Browser tests for the shop menu screens introduced by plan-004.
 *
 * Maps to TESTS.md browser scenarios:
 *   - B1 — list page renders, item counts visible, JS console clean
 *   - B2 — clicking the availability switch updates the row without
 *          a full page reload (optimistic UI)
 *   - B3 — editing the price cell inline + Enter shows the new price
 *          and the "overridden" badge
 *   - B4 — clicking Reset on an overridden row reverts to master_price
 *          and removes the badge
 *
 * These tests are SKIPPED by default. To run them locally:
 *   1. `cd frontend && npm run dev` (or `composer run dev`)
 *   2. Wait for the Next.js server to be reachable at the project URL
 *   3. Remove the `->skip(...)` calls below or pass `--without-skipped`
 *   4. `php artisan test --compact tests/Browser/Shop`
 *
 * Selectors should be derived by running `mcp__playwright__browser_snapshot`
 * against each page first — the menu detail page does not yet export stable
 * test ids. Add `data-testid` attributes to the row, availability dropdown,
 * price input, and reset button OR adopt label-based locators (`getByRole`,
 * `getByLabel`) before un-skipping.
 *
 * Each `it(...)` block MUST end with an assertion that the browser console
 * is empty.
 */
it('renders the shop menu list with item counts', function () {
    // Scenario B1
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('toggles item availability without a full page reload', function () {
    // Scenario B2
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('edits the price inline and shows the overridden badge', function () {
    // Scenario B3
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('resets an overridden price back to master_price', function () {
    // Scenario B4
})->skip('Browser tests require running dev server — see file PHPDoc.');
