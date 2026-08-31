<?php

/**
 * Browser tests for the HQ Menu Schedules tab
 * (`/hq/{brandSlug}/menus/{menuId}`).
 *
 * Maps to TESTS.md browser scenarios:
 *   - B1 — Schedules tab renders without JS console errors; empty state visible
 *   - B2 — Add Schedule form → new row appears with correct time + day badges
 *   - B3 — Inline Switch toggle updates is_active without page reload
 *   - B4 — Validation: no days selected → inline error shown, dialog stays open
 *   - B5 — Delete → AlertDialog confirm → row removed from list
 *
 * These tests are SKIPPED by default — browser tests require:
 *   1. `pnpm dev:admin` running on localhost:5430
 *   2. A seeded dev DB with at least one brand + menu
 *   3. `php artisan dusk` configured with DUSK_DRIVER_URL
 *
 * To unskip: remove the `->skip(...)` call on the specific scenario.
 */
it('schedules tab renders without console errors — empty state visible', function () {
    // Scenario B1: visit /hq/{brandSlug}/menus/{menuId}
    // click "Schedules" tab
    // assert no JS console errors
    // assert empty state text "No schedules configured" is visible
    // assert "Add Schedule" button is visible
})->skip('Browser tests require running dev server — set up Dusk to unskip.');

it('adds a schedule and new row appears in list', function () {
    // Scenario B2:
    // click "Add Schedule" button → dialog opens
    // fill start_time = 06:00
    // fill end_time = 10:30
    // click "Select all" to check all 7 days
    // click "Add Schedule" submit button
    // assert dialog closes
    // assert new row appears with "06:00 – 10:30" in time column
    // assert "Sun", "Mon", ..., "Sat" badges visible in row
})->skip('Browser tests require running dev server — set up Dusk to unskip.');

it('inline Switch toggles is_active without page reload', function () {
    // Scenario B3:
    // given a schedule row with is_active=true
    // click the Switch in that row
    // assert Switch visually flips to off
    // assert no full page reload (can be verified by checking absence of loading indicator)
})->skip('Browser tests require running dev server — set up Dusk to unskip.');

it('form validation shows inline error when no days selected', function () {
    // Scenario B4:
    // click "Add Schedule"
    // fill start_time = 09:00, end_time = 17:00
    // do NOT select any day
    // click "Add Schedule" submit button
    // assert dialog stays open (not closed)
    // assert error message "Select at least one day." is visible under Days of Week
})->skip('Browser tests require running dev server — set up Dusk to unskip.');

it('delete confirm removes schedule row from list', function () {
    // Scenario B5:
    // given a schedule row exists
    // click delete icon → AlertDialog opens
    // click "Delete" confirm button
    // assert row is removed from the table
})->skip('Browser tests require running dev server — set up Dusk to unskip.');
