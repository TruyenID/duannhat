<?php

/**
 * Browser tests — Plan-017 T7.10 — Shop receive form
 * (`/shop/{shopSlug}/material-lots/receive`).
 *
 * Maps to README.md "Receive a lot end-to-end via UI works (Journey 1)".
 *
 * Scenarios from TESTS.md:
 *   - B1 — Form renders Combobox for material + warehouse, qty/unit row,
 *          supplier card, Cost+Currency row, Temperature CCP card
 *   - B2 — Submit minimal happy-path (material + warehouse + received_qty)
 *          → toast.success → redirect to lot detail page
 *   - B3 — Submit without picking material → submit button disabled
 *   - B4 — When material requires temperature check, leaving temp empty
 *          surfaces the 422 message via toast and stays on the form
 *   - B5 — Out-of-range temperature without override_reason → 422
 *          toast; with reason → success + lot.is_temperature_compliant=false
 */
it('B1 — receive form renders all sections with Combobox pickers', function () {
    // visit /shop/{shop}/material-lots/receive
    // assert Material Combobox visible (not raw <Input>)
    // assert Warehouse Combobox visible
    // assert Cost row has both cost_per_unit + currency inputs
    // assert Temperature CCP card is rendered (full-width)
    // assert no JS console errors
})->skip('Browser tests need running dev server.');

it('B2 — happy-path submit redirects to lot detail', function () {
    // visit the form
    // open material Combobox, pick first option
    // open warehouse Combobox, pick first option
    // fill received_qty = 100, unit = kg
    // click "Receive" button
    // assert URL changes to /shop/{shop}/material-lots/{newLotId}
    // assert toast.success fires
})->skip('Browser tests need running dev server.');

it('B3 — submit button stays disabled until required fields filled', function () {
    // visit the form
    // assert Receive button is disabled
    // fill received_qty = 100
    // assert button still disabled (material + warehouse missing)
    // fill material + warehouse
    // assert button enabled
})->skip('Browser tests need running dev server.');

it('B4 — material requiring temperature check rejects empty temp with 422', function () {
    // pre-condition: a material with requires_temperature_check=true exists
    // visit the form
    // pick that material + a warehouse
    // fill received_qty
    // leave received_temperature blank
    // click Receive
    // assert toast.error contains "received_temperature is required"
    // assert URL did not change
})->skip('Browser tests need running dev server.');

it('B5 — out-of-range temp with override reason succeeds + flags non-compliant', function () {
    // pick material with min=-2, max=4
    // pick warehouse + qty
    // received_temperature = 8.5 (out of range)
    // temperature_override_reason = "Cooler door open during unload"
    // click Receive → success + redirect
    // on detail page, assert "Temp compliance" Badge shows "Override"
})->skip('Browser tests need running dev server.');
