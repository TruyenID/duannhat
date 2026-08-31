import { test } from "node:test";
import assert from "node:assert/strict";

import { computeCartTax } from "./tax.ts";

// ---------------------------------------------------------------------------
// #1425 — the service charge carries its own consumption tax
// (`shop_order_settings.service_charge_tax_rate`). The preview used to ignore
// it entirely, so the total the customer approved was short by exactly that
// amount in 税抜 mode while the server charged the full figure.
//
// Every expectation below mirrors OrderPricingCalculator::priceGroups()
// (backend/app/Services/Customer/OrderPricingCalculator.php:74-132):
//
//   scTax = includeTax ? sc − round(sc / (1+r))       : round(sc × r)
//   total = includeTax ? Σgross + sc                  : base + Σtax + sc + scTax
//
// and the sc tax MERGES into the breakdown group of its own rate (gap #7)
// rather than forming a display line of its own — that is what the invoice
// and the order-detail screens show.
//
// Every figure below was cross-checked by running the same five scenarios
// through the real PHP calculator, not derived from reading it:
//
//   docker exec tempo-app php artisan tinker --execute '
//     $c = app(App\Services\Customer\OrderPricingCalculator::class);
//     $r = $c->priceGroups([10 => 10000], 0, 5.0, 10.0, false, 1.0);
//     echo $r->serviceCharge, " ", $r->serviceChargeTax, " ", $r->taxAmount;'
//   → 500 50 1050
// ---------------------------------------------------------------------------

const JPY = { currencyCode: "JPY" } as const;

test("excluded mode: sc tax lands in taxTotal and merges into its rate group", () => {
  // ¥10,000 of 10% items, 5% service charge (¥500), sc taxed at 10% (¥50).
  const { rows, taxTotal, serviceChargeTax } = computeCartTax(
    [{ subtotal: 10_000, rate: 10 }],
    { ...JPY, serviceCharge: 500, serviceChargeTaxRate: 10 },
  );

  assert.equal(serviceChargeTax, 50);
  assert.equal(taxTotal, 1_050); // 1,000 items + 50 service charge
  assert.deepEqual(rows, [{ rate: 10, taxable: 10_500, tax: 1_050 }]);

  // Backend total: base + Σ group tax + sc + scTax.
  assert.equal(10_000 + taxTotal + 500, 11_550);
});

test("excluded mode: a different sc rate forms its own group, ascending", () => {
  // 軽減 8% food, but the service charge is 標準 10% — two invoice groups.
  const { rows, taxTotal, serviceChargeTax } = computeCartTax(
    [{ subtotal: 10_000, rate: 8 }],
    { ...JPY, serviceCharge: 500, serviceChargeTaxRate: 10 },
  );

  assert.equal(serviceChargeTax, 50);
  assert.equal(taxTotal, 850); // 800 + 50
  assert.deepEqual(rows, [
    { rate: 8, taxable: 10_000, tax: 800 },
    { rate: 10, taxable: 500, tax: 50 },
  ]);
});

test("included mode (総額表示): sc tax is extracted, never added on top", () => {
  // ¥11,000 gross at 10% → net 10,000 / tax 1,000.
  // Service charge 5% of the gross base = ¥550, its 10% tax already inside.
  const { rows, taxTotal, serviceChargeTax } = computeCartTax(
    [{ subtotal: 11_000, rate: 10 }],
    {
      ...JPY,
      pricesIncludeTax: true,
      serviceCharge: 550,
      serviceChargeTaxRate: 10,
    },
  );

  assert.equal(serviceChargeTax, 50); // 550 − round(550 / 1.1)
  assert.equal(taxTotal, 1_050);
  // taxable is the NET share in included mode: 10,000 + (550 − 50).
  assert.deepEqual(rows, [{ rate: 10, taxable: 10_500, tax: 1_050 }]);

  // Backend total in included mode: Σ gross + sc — the tax is NOT re-added.
  assert.equal(11_000 + 550, 11_550);
});

test("sc tax rate 0 changes nothing (the shipped default must not regress)", () => {
  const withCharge = computeCartTax([{ subtotal: 10_000, rate: 10 }], {
    ...JPY,
    serviceCharge: 500,
    serviceChargeTaxRate: 0,
  });
  const without = computeCartTax([{ subtotal: 10_000, rate: 10 }], JPY);

  assert.equal(withCharge.serviceChargeTax, 0);
  assert.equal(withCharge.taxTotal, without.taxTotal);
  assert.deepEqual(withCharge.rows, without.rows);
});

test("no service charge → no phantom group even when a rate is configured", () => {
  const { rows, taxTotal, serviceChargeTax } = computeCartTax(
    [{ subtotal: 10_000, rate: 10 }],
    { ...JPY, serviceCharge: 0, serviceChargeTaxRate: 10 },
  );

  assert.equal(serviceChargeTax, 0);
  assert.equal(taxTotal, 1_000);
  assert.deepEqual(rows, [{ rate: 10, taxable: 10_000, tax: 1_000 }]);
});

test("sc tax rounds once, half-up, at the currency step", () => {
  // ¥333 × 8% = 26.64 → 27 (half-up at step 1). A truncating port would say 26.
  const { serviceChargeTax } = computeCartTax([{ subtotal: 1_000, rate: 10 }], {
    ...JPY,
    serviceCharge: 333,
    serviceChargeTaxRate: 8,
  });
  assert.equal(serviceChargeTax, 27);
});

test("sub-unit currency: sc tax honours the 0.01 step", () => {
  // $12.34 × 10% = 1.234 → 1.23. Math.round() (the pre-#1425 code) gave 1.
  const { serviceChargeTax } = computeCartTax([{ subtotal: 100, rate: 10 }], {
    currencyCode: "USD",
    serviceCharge: 12.34,
    serviceChargeTaxRate: 10,
  });
  assert.equal(serviceChargeTax, 1.23);
});

test("discount applies before the service charge is taxed", () => {
  // Caller computes sc on (subtotal − discount): 5% of (10,000 − 2,000) = 400.
  const { taxTotal, serviceChargeTax, rows } = computeCartTax(
    [{ subtotal: 10_000, rate: 10 }],
    { ...JPY, discount: 2_000, serviceCharge: 400, serviceChargeTaxRate: 10 },
  );

  assert.equal(serviceChargeTax, 40);
  assert.equal(taxTotal, 840); // 800 on the discounted 8,000 + 40
  assert.deepEqual(rows, [{ rate: 10, taxable: 8_400, tax: 840 }]);
});
