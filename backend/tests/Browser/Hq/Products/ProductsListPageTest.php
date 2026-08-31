<?php

/**
 * Browser tests for the HQ Products list page (`/hq/{brandSlug}/products`).
 *
 * Maps to TESTS.md browser scenarios:
 *   - #50 — list page renders, search filters results, status filter, bulk select
 *   - #56 — bulk-delete dialog confirms then refreshes list
 *   - #57 — restore action on a soft-deleted row brings it back
 *   - #58 — export button downloads CSV with the right filename
 *
 * These tests are SKIPPED by default. To run them locally:
 *   1. `cd frontend && npm run dev` (or `composer run dev`)
 *   2. Wait for the Next.js server to be reachable at the project URL
 *   3. Remove the `->skip(...)` calls below or pass `--without-skipped`
 *   4. `php artisan test --compact tests/Browser/Hq/Products`
 *
 * Selectors should be derived by running `mcp__playwright__browser_snapshot`
 * against each page first — they were not finalised at scaffold time because
 * the Phase 7 agent built the components without exporting stable test ids.
 * Add `data-testid` attributes to the components OR adopt label-based locators
 * (`getByRole`, `getByLabel`) before un-skipping.
 *
 * Each `it(...)` block MUST end with an assertion that the browser console is
 * empty — see `expect(\Pest\Browser\PendingAssertion::class)`.
 */
it('renders the products list with create / import / export buttons', function () {
    // Scenario #50
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('filters by search and status', function () {
    // Scenario #50
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('bulk-deletes selected products and refreshes the list', function () {
    // Scenario #56
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('restores a soft-deleted product from the with_trashed view', function () {
    // Scenario #57
})->skip('Browser tests require running dev server — see file PHPDoc.');

it('exports the products list as CSV with the brand-scoped filename', function () {
    // Scenario #58
})->skip('Browser tests require running dev server — see file PHPDoc.');
