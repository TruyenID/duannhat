<?php

/**
 * Browser tests for the HQ Allergen master-data + Recipe approval-workflow UI
 * (plan-003). Covers `/hq/{brandSlug}/allergens`, `.../allergens/new`,
 * `.../materials/[id]`, `.../recipes`, and `.../recipes/[id]`.
 *
 * Maps to TESTS.md §Browser (UI) scenarios (the 8 bullets):
 *   1 — allergens list renders w/o JS console errors, shows seeded JP rows
 *   2 — create allergen via /allergens/new across 3 locale tabs → toast
 *   3 — materials/[id] allergen Combobox pick 2 → Save → chips + toast
 *   4 — recipes list status Badge variant per approval_status
 *   5 — recipes list Reject… dialog → reason → confirm → row updates + toast
 *   6 — recipes list Reject with empty reason → inline validation, no API call
 *   7 — recipes/[id] edit description (no banner) vs edit ingredients (banner)
 *   8 — user A cannot approve own submission: Approve disabled + tooltip AND
 *        server 422 if bypassed
 *
 * These tests are SKIPPED by default — identical convention to
 * tests/Browser/Hq/Products/ProductsListPageTest.php. admin-web is a separate
 * Next.js submodule, so the browser suite needs its dev server reachable at the
 * project URL before it can drive the real pages. To run them locally:
 *   1. `cd admin-web && pnpm dev` (Next.js at http://localhost:5430)
 *   2. Point the browser suite at that origin
 *   3. Remove the `->skip(...)` calls below
 *   4. `php artisan test --compact tests/Browser/Hq/AllergenRecipeApprovalBrowserTest.php`
 *
 * Selectors should be derived from a live `browser_snapshot` first — prefer
 * label/role locators (`getByRole`, `getByLabel`) or add stable `data-testid`
 * attributes to the components before un-skipping. Each `it(...)` must end by
 * asserting the browser console is empty.
 */
$reason = 'Browser tests require the admin-web Next.js dev server — see file PHPDoc.';

it('renders the allergens list without JS console errors and shows seeded JP allergens', function () {
    // Scenario 1
})->skip($reason);

it('creates an allergen via /allergens/new across ja/en/vi tabs and shows a success toast', function () {
    // Scenario 2
})->skip($reason);

it('assigns allergens to a material via the Combobox and renders removable chips + toast', function () {
    // Scenario 3
})->skip($reason);

it('renders recipe status badges with the correct variant per approval_status', function () {
    // Scenario 4
})->skip($reason);

it('rejects a pending recipe through the dialog, updating the row and firing a success toast', function () {
    // Scenario 5
})->skip($reason);

it('blocks the reject dialog with an inline validation error when the reason is empty (no API call)', function () {
    // Scenario 6
})->skip($reason);

it('shows the re-approval warning banner only when a structural field (ingredients) is edited', function () {
    // Scenario 7
})->skip($reason);

it('disables the Approve action with a tooltip for the submitter and 422s if bypassed', function () {
    // Scenario 8
})->skip($reason);
