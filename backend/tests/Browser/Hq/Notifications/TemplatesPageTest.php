<?php

/**
 * Browser tests for the HQ Notification Templates page
 * (`/hq/{brandSlug}/notifications/templates`).
 *
 * Maps to plan-012 TESTS.md § M2 Browser:
 *   - admin creates a new template via the key picker + 3 stacked locale
 *     cards + sticky live preview; param chips insert into the active
 *     locale when clicked
 *   - DELETE button disabled on is_system=true rows
 *
 * SKIPPED by default — same convention as other Browser tests.
 * Un-skip after `cd admin-web && pnpm dev` is running and selectors
 * finalised via `mcp__playwright__browser_snapshot`.
 */
it('creates a template via key picker + 3 locale cards + live preview', function () {
    // 1. Visit `/hq/{brandSlug}/notifications/templates`
    // 2. Click "Create template"
    // 3. Key field is a Combobox, NOT free-text — open it, pick a known
    //    emitter-bound type (e.g. `stock.alert.low`) from the list. The
    //    info panel beside the picker should render the "emitter-bound"
    //    tint + hint copy.
    // 4. Alternatively, click the `+ Custom key` button to unlock the
    //    free-text input. Regex `^[a-z][a-z0-9._-]*$` blocks saving an
    //    invalid key (footer shows `validation.key_required`).
    // 5. Content section renders as 3 stacked locale cards (ja/en/vi),
    //    NOT tabs. Focusing a locale's inputs highlights that card and
    //    makes the sticky right-hand preview panel render that locale.
    // 6. Click a param chip (e.g. `{{item_name}}`) — it inserts into the
    //    active-locale body textarea at the cursor position.
    // 7. Save is disabled until each locale has a title + body; footer
    //    flips `validation.ready` and the button enables.
    // 8. Preview panel shows the rendered title + body with the sample
    //    `<param>` values.
    // 9. Assert browser console is empty.
})->skip('Browser tests require running admin-web dev server — see file PHPDoc.');

it('disables the Delete button on is_system=true rows', function () {
    // Seed SystemNotificationTemplateSeeder then visit the page. The row
    // for `recipe.approved` should render with a `System` Badge and the
    // Delete button should have `disabled` attribute.
})->skip('Browser tests require running admin-web dev server — see file PHPDoc.');
