<?php

/**
 * Browser tests — Plan-017 T7.10 — HQ Trace tool
 * (`/hq/{brandSlug}/trace`).
 *
 * Scenarios from TESTS.md:
 *   - B1 — Trace page renders Tabs (By Lot / By Customer Order)
 *   - B2 — Pick a lot via Combobox → Accordion tree of parents + children
 *          renders. Expanding a child drills into its own children.
 *   - B3 — By Customer Order tab → paste UUID, Lookup → source lot list
 *          shows expected supplier_name + lot_code
 *   - B4 — Empty result for unknown order shows "No edges" copy
 *
 * Skipped by default.
 */
it('B1 — trace page renders both tabs without console errors', function () {
    // visit /hq/{brand}/trace
    // assert Tabs visible: "By Lot" + "By Customer Order"
})->skip('Browser tests need running dev server.');

it('B2 — pick a lot → Accordion tree expands into children', function () {
    // visit /hq/{brand}/trace
    // click By Lot tab (default)
    // open Combobox, type lot_code prefix, select
    // assert Accordion tree renders with non-empty parents or children
    // click first child Accordion trigger → expect grand-children to render
})->skip('Browser tests need running dev server.');

it('B3 — paste a customer_order_id + Lookup → source lots populated', function () {
    // visit /hq/{brand}/trace
    // click By Customer Order tab
    // paste a real customer_order_id (must exist in seed data with sales-edge)
    // click Lookup
    // assert "Source lots" section renders with at least 1 lot row
})->skip('Browser tests need running dev server.');

it('B4 — unknown customer_order_id renders "No edges" copy', function () {
    // visit /hq/{brand}/trace
    // click By Customer Order tab
    // paste a random uuid that has no genealogy_links rows
    // click Lookup
    // assert "No trace edges for this order" is visible
})->skip('Browser tests need running dev server.');
