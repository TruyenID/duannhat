import { describe, expect, it } from "vitest";
import {
  computeByAmountFooter,
  minorUnit,
  seedRow,
} from "./split-by-amount";
import type { PersonBillByAmount } from "@/app/pos/types";

function row(amount: number): PersonBillByAmount {
  return {
    bill_index: 1,
    total_bills: 2,
    label: "Người 1",
    amount,
    method_id: "cash",
    idempotency_key: "k",
    status: "draft",
  };
}

describe("minorUnit", () => {
  it("returns 1 for JPY/VND/KRW", () => {
    expect(minorUnit("JPY")).toBe(1);
    expect(minorUnit("VND")).toBe(1);
    expect(minorUnit("KRW")).toBe(1);
  });
  it("returns 100 for USD/EUR", () => {
    expect(minorUnit("USD")).toBe(100);
    expect(minorUnit("EUR")).toBe(100);
  });
});

describe("computeByAmountFooter", () => {
  it("flags balanced when Σ === total AND every row > 0 AND ≥ 2 rows (VND)", () => {
    const got = computeByAmountFooter({
      rows: [row(100_000), row(80_000), row(70_000)],
      orderTotal: 250_000,
      currency: "VND",
    });
    expect(got.sumAllocated).toBe(250_000);
    expect(got.drift).toBe(0);
    expect(got.balanced).toBe(true);
  });

  it("flags unbalanced when Σ drifts below total", () => {
    const got = computeByAmountFooter({
      rows: [row(100_000), row(80_000), row(60_000)],
      orderTotal: 250_000,
      currency: "VND",
    });
    expect(got.drift).toBe(-10_000);
    expect(got.balanced).toBe(false);
  });

  it("disables balance when any row is 0", () => {
    const got = computeByAmountFooter({
      rows: [row(250_000), row(0)],
      orderTotal: 250_000,
      currency: "VND",
    });
    expect(got.drift).toBe(0);
    expect(got.balanced).toBe(false);
  });

  it("disables balance when only 1 row", () => {
    const got = computeByAmountFooter({
      rows: [row(250_000)],
      orderTotal: 250_000,
      currency: "VND",
    });
    expect(got.balanced).toBe(false);
  });

  it("snaps to cent for USD so pre-round drift doesn't break balance", () => {
    // 100/3 ≈ 33.333… — snapping to 0.01 means 33.33 + 33.33 + 33.34 = 100.00.
    const got = computeByAmountFooter({
      rows: [row(33.33), row(33.33), row(33.34)],
      orderTotal: 100,
      currency: "USD",
    });
    expect(got.drift).toBeCloseTo(0, 2);
    expect(got.balanced).toBe(true);
  });
});

describe("seedRow", () => {
  it("creates a draft row with unique idempotency keys", () => {
    const a = seedRow({ billIndex: 1, totalBills: 3, defaultLabel: "Người 1" });
    const b = seedRow({ billIndex: 2, totalBills: 3, defaultLabel: "Người 2" });
    expect(a.idempotency_key).not.toEqual(b.idempotency_key);
    expect(a.status).toBe("draft");
    expect(a.amount).toBe(0);
  });
});
