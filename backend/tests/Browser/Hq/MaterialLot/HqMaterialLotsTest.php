<?php

/**
 * Browser tests — Plan-017 T7.10 / T11.3 — HQ MaterialLot screens
 * (`/hq/{brandSlug}/material-lots` and `/hq/{brandSlug}/material-lots/{id}`).
 *
 * Scenarios from TESTS.md:
 *   - B1 — List page renders without JS console errors; STT + clickable
 *          green lot_code link + StatusBadge + qty_on_hand + expiry visible
 *   - B2 — Filter by status=quarantined narrows the list
 *   - B3 — Click a lot row → detail page loads with Overview / Cost /
 *          Temperature / Actions cards
 *   - B4 — Quarantine action: empty reason → button stays disabled;
 *          fill reason → status flips to Quarantined; release → back to Active
 *   - B5 — Force-dispose with stock on hand → confirm dialog → status →
 *          Disposed + qty_on_hand = 0
 *
 * Skipped by default — same convention as the rest of the suite. To run:
 *   1. `docker compose up -d` (backend + mysql)
 *   2. `pnpm dev:admin` on localhost:5430
 *   3. Seed: `php artisan db:seed --class=MaterialSeeder` (gives demo lots)
 *   4. Configure DUSK_DRIVER_URL to point at the running app
 *   5. Remove the `->skip(...)` call on the specific scenario
 */
it('B1 — material-lots list renders without console errors', function () {
    // visit /hq/{brand}/material-lots
    // assert no JS console errors
    // assert STT column header visible
    // assert at least one row's lot_code link is green (text-emerald-700)
    // assert StatusBadge renders for each row
})->skip('Browser tests need running dev server — set up Dusk to unskip.');

it('B2 — filter by status=quarantined narrows the list', function () {
    // assume seeded data has both active and quarantined lots
    // visit /hq/{brand}/material-lots?status=quarantined
    // assert all visible rows have Quarantined status
})->skip('Browser tests need running dev server.');

it('B3 — clicking a lot opens the detail page with all cards', function () {
    // visit /hq/{brand}/material-lots
    // click first lot_code link
    // assert URL is /hq/{brand}/material-lots/{id}
    // assert Overview / Supplier / Cost / Temperature / Actions cards visible
})->skip('Browser tests need running dev server.');

it('B4 — quarantine + release round trip', function () {
    // visit a lot detail page where status=Active
    // assert Quarantine button is disabled with empty reason input
    // fill "lab hold pending QA result" into reason input
    // assert button is enabled, click it
    // assert toast.success fires
    // assert status badge shows Quarantined
    // click Release → assert status → Active
})->skip('Browser tests need running dev server.');

it('B5 — force-dispose with stock confirms before destroying', function () {
    // visit a lot with qty_on_hand > 0
    // click Dispose → expect AlertDialog showing the qty + unit
    // click Confirm → toast.success → status badge → Disposed
    // assert qty_on_hand = 0 in the Overview card
})->skip('Browser tests need running dev server.');
