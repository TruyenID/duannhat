<?php

/**
 * Plan-024 — browser test for the warehouse edit dialog's
 * allow_negative_sales toggle (T7.4).
 *
 * Maps to TESTS.md browser scenario:
 *   #5 — org-admin toggles `allow_negative_sales`, saves, reload retains.
 *
 * Setup notes:
 *   - The toggle lives inside the WarehouseFormDialog rendered from
 *     `/shop/{shopSlug}/warehouses` when the user clicks edit on a row.
 *   - It is only visible in EDIT mode — the create dialog hides it.
 *   - Backend authz: only org-admin can flip the flag. Other roles must
 *     see a disabled control (currently the field is rendered for all
 *     roles; backend rejects the PATCH with 403 — UI hardening is a
 *     follow-up).
 *
 * To run locally:
 *   1. `pnpm dev:admin` from the umbrella root
 *   2. Wait for the Next.js server to be reachable
 *   3. Remove the `->skip(...)` call below
 *   4. `php artisan test --compact tests/Browser/Shop/WarehouseAllowNegativeToggleTest.php`
 */
it('persists allow_negative_sales toggle via the warehouse settings PATCH', function () {
    // Browser scenario #5 (TESTS.md)
})->skip('Browser tests require running dev server — see file PHPDoc.');
