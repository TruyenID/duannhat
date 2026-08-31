import { test } from "node:test";
import assert from "node:assert/strict";

import {
  getRoundingStep,
  roundUpToStep,
  type SplitBillRoundingMode,
} from "./split-rounding.ts";

// ---------------------------------------------------------------------------
// getRoundingStep — step derivation per currency + mode
// ---------------------------------------------------------------------------

test("getRoundingStep auto: cents currency → 0.01", () => {
  assert.equal(getRoundingStep("USD", "auto"), 0.01);
  assert.equal(getRoundingStep("EUR", "auto"), 0.01);
  // case-insensitive
  assert.equal(getRoundingStep("usd", "auto"), 0.01);
});

test("getRoundingStep auto: integer currency → 1", () => {
  assert.equal(getRoundingStep("JPY", "auto"), 1);
  assert.equal(getRoundingStep("VND", "auto"), 1);
  assert.equal(getRoundingStep("KRW", "auto"), 1);
});

test("getRoundingStep auto: 3-decimal currency → 0.001", () => {
  assert.equal(getRoundingStep("KWD", "auto"), 0.001);
  assert.equal(getRoundingStep("BHD", "auto"), 0.001);
});

test("getRoundingStep integer mode: always 1 regardless of currency", () => {
  assert.equal(getRoundingStep("USD", "integer"), 1);
  assert.equal(getRoundingStep("JPY", "integer"), 1);
  assert.equal(getRoundingStep("KWD", "integer"), 1);
});

test("getRoundingStep two_decimals mode: always 0.01 regardless of currency", () => {
  assert.equal(getRoundingStep("USD", "two_decimals"), 0.01);
  assert.equal(getRoundingStep("JPY", "two_decimals"), 0.01);
  assert.equal(getRoundingStep("KWD", "two_decimals"), 0.01);
});

test("getRoundingStep none: 0 for integer currencies (exact division ok)", () => {
  assert.equal(getRoundingStep("JPY", "none"), 0);
  assert.equal(getRoundingStep("VND", "none"), 0);
});

test("getRoundingStep none: 0.01 fallback for non-integer (Stripe needs minor units)", () => {
  // sub-cent is not chargeable, so `none` must not produce step 0 for USD
  assert.equal(getRoundingStep("USD", "none"), 0.01);
  assert.equal(getRoundingStep("KWD", "none"), 0.01);
});

test("getRoundingStep unknown currency defaults to cents step under auto", () => {
  assert.equal(getRoundingStep("XYZ", "auto"), 0.01);
});

// ---------------------------------------------------------------------------
// roundUpToStep — round UP to nearest multiple, float-noise safe
// ---------------------------------------------------------------------------

test("roundUpToStep 3.3333 @ 0.01 → 3.34 (the $10/3 regression)", () => {
  assert.equal(roundUpToStep(3.3333, 0.01), 3.34);
});

test("roundUpToStep 333.33 @ 1 → 334", () => {
  assert.equal(roundUpToStep(333.33, 1), 334);
});

test("roundUpToStep step 0 → passthrough (no rounding)", () => {
  assert.equal(roundUpToStep(3.33, 0), 3.33);
  assert.equal(roundUpToStep(333.333, 0), 333.333);
});

test("roundUpToStep already-on-step value is unchanged (no spurious bump up)", () => {
  // float drift must not push 3.34 up to 3.35
  assert.equal(roundUpToStep(3.34, 0.01), 3.34);
  assert.equal(roundUpToStep(10, 1), 10);
  assert.equal(roundUpToStep(12.345, 0.001), 12.345);
});

test("roundUpToStep rounds strictly up for any remainder", () => {
  assert.equal(roundUpToStep(3.341, 0.01), 3.35);
  assert.equal(roundUpToStep(3.001, 1), 4);
});

test("roundUpToStep non-finite / zero inputs are safe", () => {
  assert.equal(roundUpToStep(Number.NaN, 0.01), 0);
  assert.equal(roundUpToStep(Number.POSITIVE_INFINITY, 0.01), 0);
  assert.equal(roundUpToStep(0, 0.01), 0);
});

// ---------------------------------------------------------------------------
// End-to-end split math — mirrors payment-view.tsx per-person calc.
//
// This is AUDIT-RELEVANT money math: each payer is charged
//   min(roundUpToStep(remaining / n, step), remaining)
// and the LAST payer absorbs the exact `remaining`. The invariant that MUST
// hold is: sum of all charged amounts === original total (to the currency's
// smallest unit). These tests lock that invariant so a rounding-mode change
// can never silently over- or under-collect.
// ---------------------------------------------------------------------------

/** Reproduce the payer-by-payer charge sequence from payment-view.tsx. */
function splitCharges(total: number, numPeople: number, step: number): number[] {
  const perPerson = roundUpToStep(total / numPeople, step);
  const charges: number[] = [];
  let remaining = total;
  for (let i = 0; i < numPeople; i++) {
    const isLast = i === numPeople - 1;
    const charge = isLast ? remaining : Math.min(perPerson, remaining);
    charges.push(charge);
    remaining = round6(remaining - charge);
  }
  return charges;
}

function round6(v: number): number {
  return Math.round(v * 1_000_000) / 1_000_000;
}

function sum(xs: number[]): number {
  return round6(xs.reduce((a, b) => a + b, 0));
}

test("split USD $10 / 3 (auto) → 3.34, 3.34, 3.32; sum === 10.00", () => {
  const step = getRoundingStep("USD", "auto");
  const charges = splitCharges(10, 3, step);
  assert.deepEqual(charges, [3.34, 3.34, 3.32]);
  assert.equal(sum(charges), 10);
});

test("split JPY ¥1000 / 3 (auto) → 334, 334, 332; sum === 1000 (no regression)", () => {
  const step = getRoundingStep("JPY", "auto");
  const charges = splitCharges(1000, 3, step);
  assert.deepEqual(charges, [334, 334, 332]);
  assert.equal(sum(charges), 1000);
});

test("split VND ₫100,000 / 7 (auto) → step=1, sum === 100000", () => {
  const step = getRoundingStep("VND", "auto");
  const charges = splitCharges(100000, 7, step);
  // 100000/7 = 14285.71 → ceil to 14286 for six payers, last absorbs remainder
  assert.deepEqual(charges.slice(0, 6), [14286, 14286, 14286, 14286, 14286, 14286]);
  assert.equal(charges[6], round6(100000 - 14286 * 6)); // 14284
  assert.equal(sum(charges), 100000);
});

test("split USD $10 / 3 with integer override → 4, 4, 2 (legacy Math.ceil)", () => {
  const step = getRoundingStep("USD", "integer");
  const charges = splitCharges(10, 3, step);
  assert.deepEqual(charges, [4, 4, 2]);
  assert.equal(sum(charges), 10);
});

test("split KWD 10 / 3 (auto, 3-decimal) preserves mils; sum exact", () => {
  const step = getRoundingStep("KWD", "auto");
  const charges = splitCharges(10, 3, step);
  assert.equal(charges[0], 3.334);
  assert.equal(charges[1], 3.334);
  assert.equal(charges[2], round6(10 - 3.334 * 2)); // 3.332
  assert.equal(sum(charges), 10);
});

test("split invariant holds across many currencies/counts/totals", () => {
  const cases: Array<[string, SplitBillRoundingMode]> = [
    ["USD", "auto"],
    ["JPY", "auto"],
    ["VND", "auto"],
    ["KWD", "auto"],
    ["USD", "integer"],
    ["USD", "two_decimals"],
    ["EUR", "auto"],
  ];
  for (const [ccy, mode] of cases) {
    const step = getRoundingStep(ccy, mode);
    for (const total of [10, 1000, 12345.67, 99.99, 100000]) {
      for (const n of [2, 3, 4, 5, 7, 11]) {
        const charges = splitCharges(total, n, step);
        assert.equal(
          sum(charges),
          round6(total),
          `sum mismatch for ${ccy}/${mode} total=${total} n=${n}`,
        );
        // no payer is ever charged a negative amount
        for (const c of charges) {
          assert.ok(c >= 0, `negative charge for ${ccy}/${mode} total=${total} n=${n}`);
        }
      }
    }
  }
});
