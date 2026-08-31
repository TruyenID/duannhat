// @vitest-environment node
import { describe, it, expect } from "vitest";
import { redistributeEqualSplit, type EqualSplitRow } from "./equal-split";

/** Terse row builder — amount + optional lock. */
function row(amount: number, locked = false): EqualSplitRow {
  return { amount, locked };
}

/** Σ of an amounts array, tolerating fp noise for decimal currencies. */
function sum(amounts: number[]): number {
  return amounts.reduce((s, a) => s + a, 0);
}

describe("redistributeEqualSplit — VND (minor unit = 1)", () => {
  it("leaves an even split untouched when the edit equals the current share", () => {
    // 300k / 3 = 100k each; re-typing 100k on row 0 keeps everyone at 100k.
    const rows = [row(100_000), row(100_000), row(100_000)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 100_000,
      orderTotal: 300_000,
      currency: "VND",
    });
    expect(out).toEqual([100_000, 100_000, 100_000]);
    expect(sum(out)).toBe(300_000);
  });

  it("redistributes the remainder equally across the other unpaid rows", () => {
    // Bump guest 0 to 200k on a 300k / 3 split → 100k left for 2 guests.
    const rows = [row(100_000), row(100_000), row(100_000)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 200_000,
      orderTotal: 300_000,
      currency: "VND",
    });
    expect(out).toEqual([200_000, 50_000, 50_000]);
    expect(sum(out)).toBe(300_000);
  });

  it("lands the rounding remainder on the LAST redistributed row", () => {
    // 100k left across 3 rows → 33_333 + 33_333 + 33_334.
    const rows = [row(0), row(0), row(0), row(0)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 200_000,
      orderTotal: 300_000,
      currency: "VND",
    });
    expect(out).toEqual([200_000, 33_333, 33_333, 33_334]);
    // The invariant that matters for till reconciliation.
    expect(sum(out)).toBe(300_000);
  });

  it("gives the whole remainder to a single other unpaid row (n = 1)", () => {
    const rows = [row(0), row(0)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 120_000,
      orderTotal: 300_000,
      currency: "VND",
    });
    expect(out).toEqual([120_000, 180_000]);
    expect(sum(out)).toBe(300_000);
  });

  it("clamps redistributed rows to 0 when the edit alone meets/exceeds the total", () => {
    const rows = [row(100_000), row(100_000), row(100_000)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 400_000, // over the 300k total
      orderTotal: 300_000,
      currency: "VND",
    });
    expect(out).toEqual([400_000, 0, 0]);
    // Over-allocated on purpose — the tab surfaces the +diff banner.
    expect(sum(out)).toBe(400_000);
  });

  it("treats a negative-going remainder as 0, never negative shares", () => {
    const rows = [row(0), row(0), row(0)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 350_000,
      orderTotal: 300_000,
      currency: "VND",
    });
    expect(out).toEqual([350_000, 0, 0]);
  });
});

describe("redistributeEqualSplit — locked rows", () => {
  it("excludes collected rows from redistribution but counts them toward the total", () => {
    // Row 1 already paid 100k. Editing row 0 to 120k leaves 80k for row 2.
    const rows = [row(100_000), row(100_000, true), row(100_000)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 120_000,
      orderTotal: 300_000,
      currency: "VND",
    });
    expect(out).toEqual([120_000, 100_000, 80_000]);
    expect(sum(out)).toBe(300_000);
  });

  it("keeps a locked row's amount byte-identical even at its index", () => {
    const rows = [row(0), row(90_000, true), row(0), row(0)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 60_000,
      orderTotal: 300_000,
      currency: "VND",
    });
    // Remaining for the 2 unpaid rows = 300k - 60k - 90k = 150k → 75k each.
    expect(out).toEqual([60_000, 90_000, 75_000, 75_000]);
    expect(sum(out)).toBe(300_000);
  });

  it("returns all-locked rows unchanged (nothing to redistribute)", () => {
    const rows = [row(100_000), row(100_000, true), row(200_000, true)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 50_000,
      orderTotal: 350_000,
      currency: "VND",
    });
    expect(out).toEqual([50_000, 100_000, 200_000]);
  });
});

describe("redistributeEqualSplit — 2-decimal currencies keep their cents", () => {
  it("splits $9.00 across two rows as 4.50 + 4.50, not 4 + 5 (USD)", () => {
    const rows = [row(1), row(0), row(0)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 1, // $1.00 — leaves $9.00 for two guests
      orderTotal: 10,
      currency: "USD",
    });
    expect(out).toEqual([1, 4.5, 4.5]);
    expect(sum(out)).toBeCloseTo(10, 10);
  });

  it("snaps to the cent and lands the sub-cent remainder on the last row (EUR)", () => {
    // €10.00 left across 3 rows → 3.33 + 3.33 + 3.34.
    const rows = [row(0), row(0), row(0), row(0)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 20,
      orderTotal: 30,
      currency: "EUR",
    });
    expect(out).toEqual([20, 3.33, 3.33, 3.34]);
    expect(sum(out)).toBeCloseTo(30, 10);
  });

  it("never emits a fraction smaller than one cent", () => {
    const rows = [row(0), row(0), row(0)];
    const out = redistributeEqualSplit({
      rows,
      index: 0,
      newAmount: 0.01,
      orderTotal: 1,
      currency: "USD",
    });
    // Remaining $0.99 across 2 rows → 0.49 + 0.50 (all whole cents).
    expect(out).toEqual([0.01, 0.49, 0.5]);
    for (const a of out) {
      expect(Math.round(a * 100)).toBeCloseTo(a * 100, 6);
    }
    expect(sum(out)).toBeCloseTo(1, 10);
  });
});
