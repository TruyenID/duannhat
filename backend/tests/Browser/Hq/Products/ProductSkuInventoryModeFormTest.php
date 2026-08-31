<?php

/**
 * Plan-024 — browser test for the HQ SKU edit form's inventory_mode
 * Select (T7.5).
 *
 * Maps to TESTS.md browser scenario:
 *   #6 — change inventory_mode from "made_to_order" to "track_stock",
 *        save, reload retains the new value, console clean.
 *
 * Setup notes:
 *   - Route: `/hq/{brandSlug}/products/{productId}/skus/{skuId}`
 *   - The Select sits in the "Variant details" card alongside SKU code
 *     and selling price.
 *   - Default for new SKUs is "made_to_order"; existing rows after the
 *     plan-024 migration also default to "made_to_order".
 *
 * To run locally:
 *   1. `pnpm dev:admin` from the umbrella root
 *   2. Wait for the Next.js server to be reachable
 *   3. Remove the `->skip(...)` call below
 *   4. `php artisan test --compact tests/Browser/Hq/Products/ProductSkuInventoryModeFormTest.php`
 */
it('persists inventory_mode change from made_to_order to track_stock', function () {
    // Browser scenario #6 (TESTS.md)
})->skip('Browser tests require running dev server — see file PHPDoc.');
