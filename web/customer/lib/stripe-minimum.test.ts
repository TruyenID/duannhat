import { test } from "node:test";
import assert from "node:assert/strict";

import { isBelowStripeMinimum, stripeMinimumFor } from "./stripe-minimum.ts";

// ---------------------------------------------------------------------------
// #1427 — this predicate decides whether the "pay by Stripe" choice is offered
// at all on /orders/{id}/pay. Being wrong in the FALSE direction only sends the
// customer to Stripe's own error (what happened before #1427); being wrong in
// the TRUE direction takes away a payment method that would have worked. Hence
// the one-directional rule: only say "below" when we actually know.
// ---------------------------------------------------------------------------

test("below the guidance minimum → true", () => {
  // The ¥1 order in the #1427 report. JPY minimum is ¥50.
  assert.equal(isBelowStripeMinimum(1, "JPY"), true);
  assert.equal(isBelowStripeMinimum(49, "JPY"), true);
});

test("at or above the minimum → false", () => {
  assert.equal(isBelowStripeMinimum(50, "JPY"), false);
  assert.equal(isBelowStripeMinimum(1_200, "JPY"), false);
});

test("currency code is case-insensitive", () => {
  assert.equal(isBelowStripeMinimum(1, "jpy"), true);
  assert.equal(stripeMinimumFor("vnd"), 13_000);
});

test("unknown currency never disables the choice", () => {
  // No figure for these → we must NOT guess a customer out of paying by card.
  assert.equal(isBelowStripeMinimum(0.01, "GBP"), false);
  assert.equal(isBelowStripeMinimum(0.01, "XYZ"), false);
  assert.equal(stripeMinimumFor("GBP"), undefined);
});

test("missing currency or amount never disables the choice", () => {
  assert.equal(isBelowStripeMinimum(1, null), false);
  assert.equal(isBelowStripeMinimum(1, undefined), false);
  assert.equal(isBelowStripeMinimum(null, "JPY"), false);
  assert.equal(isBelowStripeMinimum(undefined, "JPY"), false);
  assert.equal(isBelowStripeMinimum(Number.NaN, "JPY"), false);
});

test("sub-unit currencies compare in major units", () => {
  // USD minimum is $0.50 — a 30-cent order is below it, a 50-cent one is not.
  assert.equal(isBelowStripeMinimum(0.3, "USD"), true);
  assert.equal(isBelowStripeMinimum(0.5, "USD"), false);
});

test("zero and negative totals count as below", () => {
  assert.equal(isBelowStripeMinimum(0, "JPY"), true);
  assert.equal(isBelowStripeMinimum(-5, "JPY"), true);
});
