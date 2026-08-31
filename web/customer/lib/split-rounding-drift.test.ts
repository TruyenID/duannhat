import { test } from "node:test";
import assert from "node:assert/strict";

import { getRoundingStep, roundUpToStep } from "./split-rounding.ts";

// ---------------------------------------------------------------------------
// Stripe minor-unit cents-drift regression (Plan 029, Phase 2).
//
// customer-web snaps the chargeable amount UP to the currency's minor-unit
// boundary before handing it to the Stripe payment intent:
//
//   finalRemaining = roundUpToStep(remaining - couponDiscount, currencyStep)   // payment-view.tsx
//   perPersonAmount = roundUpToStep(finalRemaining / numPeople, splitStep)
//
// The subtle bug class this guards against: IEEE-754 makes `value / step`
// land a hair ABOVE an exact integer for many ordinary cents amounts
// (`0.07 / 0.01 === 7.000000000000001`). A naive `Math.ceil(value / step)`
// would then bump the charge by a WHOLE extra minor unit — over-charging the
// customer by a cent. `roundUpToStep` subtracts a 1e-9 tolerance before
// ceiling precisely to kill that drift. These tests lock that behaviour so a
// refactor that drops the tolerance is caught. There is no equivalent case in
// split-rounding.test.ts (its `roundUpToStep(3.34, 0.01)` literal drifts the
// harmless direction — below the integer — so it never exercises this guard).
// ---------------------------------------------------------------------------

/**
 * Every cent value in 0.01..9.99 whose `value / 0.01` ratio drifts strictly
 * ABOVE its intended integer. Each one is a live cents-drift landmine: without
 * the tolerance, `Math.ceil` returns one cent too many.
 */
function upwardDriftCents(): number[] {
  const out: number[] = [];
  for (let c = 1; c <= 999; c++) {
    const v = c / 100;
    if (v / 0.01 > Math.round(v / 0.01)) {
      out.push(v);
    }
  }
  return out;
}

test("cents-drift: 0.07/0.01 drifts above 7 in IEEE-754 (regression precondition)", () => {
  // Sanity-check the precondition so this file is self-documenting: the raw
  // ratio really does exceed the integer, which is exactly what would make a
  // naive Math.ceil over-charge.
  assert.ok(0.07 / 0.01 > 7, "expected 0.07/0.01 to drift above 7");
  assert.equal(0.07 / 0.01, 7.000000000000001);
});

test("cents-drift: an on-step cents value is never bumped a whole cent up", () => {
  // 0.07 is already an exact multiple of the 0.01 step — charging 0.08 would
  // over-collect. The 1e-9 tolerance keeps it at 0.07.
  assert.equal(roundUpToStep(0.07, 0.01), 0.07);
  assert.equal(roundUpToStep(0.14, 0.01), 0.14);
  assert.equal(roundUpToStep(0.28, 0.01), 0.28);
  assert.equal(roundUpToStep(0.56, 0.01), 0.56);
  assert.equal(roundUpToStep(1.11, 0.01), 1.11);
  assert.equal(roundUpToStep(4.19, 0.01), 4.19);
});

test("cents-drift: ALL upward-drifting cents values round-trip exactly (no over-charge)", () => {
  const cents = upwardDriftCents();
  // Guard the guard: if this set ever becomes empty the assertions above would
  // be vacuous, so assert we actually have drift landmines to check.
  assert.ok(cents.length >= 30, `expected many drift cases, got ${cents.length}`);
  for (const v of cents) {
    const rounded = roundUpToStep(v, 0.01);
    assert.equal(
      rounded,
      v,
      `over-charged: ${v} snapped up to ${rounded} (whole-cent drift bump)`,
    );
  }
});

test("cents-drift: a genuine sub-cent fraction still rounds UP (never under-charge)", () => {
  // The tolerance must be tiny enough NOT to swallow a real fraction. 0.335 is
  // a true half-cent → must become 0.34, not stay 0.33.
  assert.equal(roundUpToStep(0.335, 0.01), 0.34);
  assert.equal(roundUpToStep(33.331, 0.01), 33.34);
  // one ulp of genuine excess over an integer cent still bumps up
  assert.equal(roundUpToStep(0.1200001, 0.01), 0.13);
});

// ---------------------------------------------------------------------------
// JPY minor-unit snap (payment-view.tsx, bug 2026-06-12).
//
// BE coupon + tax aggregation can leak a fractional cent into `remaining` for a
// zero-fraction currency (e.g. 2688.13 JPY). Stripe only accepts integer JPY
// minor units, so the FE must snap UP to the whole unit before charging —
// under-charging from float truncation is not allowed.
// ---------------------------------------------------------------------------

test("JPY snap: fractional remaining 2688.13 charges 2689 (up, never truncate to 2688)", () => {
  const step = getRoundingStep("JPY", "auto"); // 1
  assert.equal(step, 1);
  assert.equal(roundUpToStep(2688.13, step), 2689);
});

test("JPY snap: never under-charges and always yields an integer minor unit", () => {
  const step = getRoundingStep("JPY", "auto");
  for (const remaining of [2688.13, 2688.001, 100.5, 999.999, 0.0001, 1500]) {
    const charged = roundUpToStep(remaining, step);
    assert.ok(charged >= remaining, `under-charged: ${remaining} → ${charged}`);
    assert.ok(Number.isInteger(charged), `non-integer JPY minor unit: ${charged}`);
    // and never over-charges by more than one whole unit
    assert.ok(charged - remaining < 1, `over-charged >1 unit: ${remaining} → ${charged}`);
  }
});

test("JPY snap: an already-integer remaining is left untouched (no phantom +1)", () => {
  assert.equal(roundUpToStep(1500, 1), 1500);
  assert.equal(roundUpToStep(2689, 1), 2689);
});
