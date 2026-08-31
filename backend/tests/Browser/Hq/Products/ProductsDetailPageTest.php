<?php

/**
 * Browser tests for the HQ Products detail page
 * (`/hq/{brandSlug}/products/{id}`).
 *
 * Maps to TESTS.md browser scenarios:
 *   - #51 — create dialog → land on detail page
 *   - #52 — Basics tab edit + save round-trip
 *   - #53 — Options tab add option + values, then SKU count blocks position edit
 *   - #54 — SKUs tab generate-combinations dialog populates 9 SKUs
 *   - #55 — SKU edit dialog: assign recipe → cost_price_auto populated;
 *           toggle is_cost_override and observe cost_price decoupling
 *
 * These tests are SKIPPED by default — see ProductsListPageTest.php for the
 * unskip protocol and selector strategy.
 */
it('creates a product via the create dialog and lands on the detail page', function () {
    // Scenario #51
})->skip('Browser tests require running dev server — see ProductsListPageTest PHPDoc.');

it('edits Basics tab fields and persists on save', function () {
    // Scenario #52
})->skip('Browser tests require running dev server — see ProductsListPageTest PHPDoc.');

it('adds 2 options × 3 values via the Options tab', function () {
    // Scenario #53
})->skip('Browser tests require running dev server — see ProductsListPageTest PHPDoc.');

it('generates 9 SKU combinations via the SKUs tab', function () {
    // Scenario #54
})->skip('Browser tests require running dev server — see ProductsListPageTest PHPDoc.');

it('assigns a recipe to a SKU and toggles cost-override', function () {
    // Scenario #55
})->skip('Browser tests require running dev server — see ProductsListPageTest PHPDoc.');
