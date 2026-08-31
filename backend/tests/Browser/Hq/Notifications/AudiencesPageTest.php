<?php

/**
 * Browser tests for the HQ Notification Audiences page
 * (`/hq/{brandSlug}/notifications/audiences`).
 *
 * Maps to plan-012 TESTS.md § M1 Browser:
 *   - Rule builder shows live preview count updating as rules added /
 *     removed (debounced ~400 ms); exclude zone reduces the count.
 *
 * SKIPPED by default — follows the project convention for Browser tests
 * (see tests/Browser/Hq/Products/ProductsListPageTest.php PHPDoc).
 * To run locally:
 *   1. `cd admin-web && pnpm dev` — wait for http://localhost:5430
 *   2. Remove the `->skip(...)` call below OR pass `--without-skipped`
 *   3. `php artisan test --compact tests/Browser/Hq/Notifications`
 *
 * Selectors — use `data-slot="audience-rule-builder"`,
 * `data-slot="audience-rule-card"`, and the Add-role button's
 * `aria-label` / visible text. Finalise against a live
 * `mcp__playwright__browser_snapshot` before un-skipping.
 */
it('shows live preview count that updates as rules are added and removed', function () {
    // Scenario: plan-012 M1 browser — debounced live preview
    // 1. Visit `/hq/{brandSlug}/notifications/audiences`
    // 2. Click "Create audience"
    // 3. Fill name "All warehouse managers"
    // 4. Click "Add role" → a role card appears with warehouse_manager selected
    // 5. Wait for the `Resolves to {N} recipients` Badge (400ms debounce)
    // 6. Add another role rule → count should update (strictly larger in OR mode)
    // 7. Delete the second rule → count should return to the first value
    // 8. Assert browser console is empty throughout
})->skip('Browser tests require running admin-web dev server — see file PHPDoc.');

it('exclude zone reduces the resolved count', function () {
    // Scenario: exclude removes resolved entries so count goes down
    // 1. Create rule with "Add role" warehouse_manager → note the count
    // 2. Click "Add exclude" and paste a user-uuid already in that role
    // 3. Count should decrement by 1 after 400ms debounce
})->skip('Browser tests require running admin-web dev server — see file PHPDoc.');

it('opens the audience detail Sheet via card click or View button', function () {
    // Scenario: clarify who receives the audience + usage hint
    // 1. Seed at least one audience with a role rule + one exclude user.
    // 2. Visit `/hq/{brandSlug}/notifications/audiences`.
    // 3. Click the audience card body (NOT a button inside it) → a
    //    detail Sheet opens.
    // 4. Alternatively, click the Eye-icon "View" action on the card —
    //    opens the same Sheet; its own buttons use stopPropagation so
    //    Edit / Delete do NOT open the Sheet.
    // 5. The Sheet shows: a "How this audience is used" hint block
    //    (broadcast composer wording), the resolved recipients section
    //    with a sample list (+N more when truncated), a rule breakdown
    //    list with a per-rule-type icon tile (role/user/shop/brand/
    //    device), an exclude breakdown in destructive tone, and a
    //    metadata block (created/updated/brand_id).
    // 6. Clicking "Edit" in the Sheet closes it and opens the editor
    //    pre-populated with the same audience.
    // 7. Assert browser console is empty.
})->skip('Browser tests require running admin-web dev server — see file PHPDoc.');
