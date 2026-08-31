import { test } from "node:test";
import assert from "node:assert/strict";

import { computeSelectionTotal } from "./tax.ts";

// ---------------------------------------------------------------------------
// issue #32 — a by-items selection owes its own consumption tax. Mirrors
// SplitByItemsCalculator::compute() (backend) for the single-bill case.
// ---------------------------------------------------------------------------

test("excluded mode: tax is added per rate group", () => {
  // The reported bill: 2 × ¥1,700 at 10%, prices 税抜 → ¥3,400 + ¥340.
  const { subtotal, tax } = computeSelectionTotal(
    [
      { perUnitSubtotal: 1700, units: 1, rate: 10 },
      { perUnitSubtotal: 1700, units: 1, rate: 10 },
    ],
    { currencyCode: "JPY" },
  );
  assert.equal(subtotal, 3400);
  assert.equal(tax, 340);
});

test("excluded mode: claiming part of a line taxes only that part", () => {
  const { subtotal, tax } = computeSelectionTotal(
    [{ perUnitSubtotal: 1700, units: 1, rate: 10 }],
    { currencyCode: "JPY" },
  );
  assert.equal(subtotal, 1700);
  assert.equal(tax, 170);
});

test("mixed rates round once per group (インボイス), not once overall", () => {
  // 8% group: 1,105 → 88.4 → 88.  10% group: 1,105 → 110.5 → 111 (half-up).
  // A single blended rounding would land elsewhere; per-group must be 199.
  const { subtotal, tax } = computeSelectionTotal(
    [
      { perUnitSubtotal: 1105, units: 1, rate: 8 },
      { perUnitSubtotal: 1105, units: 1, rate: 10 },
    ],
    { currencyCode: "JPY" },
  );
  assert.equal(subtotal, 2210);
  assert.equal(tax, 199);
});

test("included mode: tax is the inner tax, never added on top", () => {
  // 総額表示: ¥3,740 gross at 10% → net 3,400, inner tax 340.
  const { subtotal, tax } = computeSelectionTotal(
    [{ perUnitSubtotal: 3740, units: 1, rate: 10 }],
    { currencyCode: "JPY", isTaxIncluded: true },
  );
  assert.equal(subtotal, 3740);
  assert.equal(tax, 340);
});

test("legacy lines without a snapshot rate: pro-rata of the order tax", () => {
  // Whole order claimed → the whole order tax.
  const whole = computeSelectionTotal(
    [{ perUnitSubtotal: 1700, units: 2, rate: null }],
    { currencyCode: "JPY", orderSubtotal: 3400, orderTaxAmount: 340 },
  );
  assert.equal(whole.tax, 340);

  // Half the order claimed → half the tax.
  const half = computeSelectionTotal(
    [{ perUnitSubtotal: 1700, units: 1, rate: null }],
    { currencyCode: "JPY", orderSubtotal: 3400, orderTaxAmount: 340 },
  );
  assert.equal(half.tax, 170);
});

test("legacy lines with no order tax figure: no tax invented", () => {
  const { tax } = computeSelectionTotal(
    [{ perUnitSubtotal: 1700, units: 1, rate: null }],
    { currencyCode: "JPY", orderSubtotal: 3400, orderTaxAmount: 0 },
  );
  assert.equal(tax, 0);
});

test("nothing selected → zero, and non-positive units are ignored", () => {
  const empty = computeSelectionTotal([], { currencyCode: "JPY" });
  assert.deepEqual(empty, { subtotal: 0, tax: 0 });

  const pruned = computeSelectionTotal(
    [{ perUnitSubtotal: 1700, units: 0, rate: 10 }],
    { currencyCode: "JPY" },
  );
  assert.deepEqual(pruned, { subtotal: 0, tax: 0 });
});

test("cents currency keeps sub-unit precision", () => {
  // USD 10.05 × 2 at 10% → 20.10 base, 2.01 tax.
  const { subtotal, tax } = computeSelectionTotal(
    [{ perUnitSubtotal: 10.05, units: 2, rate: 10 }],
    { currencyCode: "USD" },
  );
  assert.equal(subtotal, 20.1);
  assert.equal(tax, 2.01);
});
