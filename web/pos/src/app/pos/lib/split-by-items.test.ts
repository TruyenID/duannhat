// @vitest-environment node
import { describe, it, expect } from "vitest";
import { areAllByItemsBillsSettled, splitByItems } from "./split-by-items";
import type {
  CustomerOrder,
  CustomerOrderItem,
  SplitByItemsState,
} from "../types";
import { readFileSync } from "node:fs";

// ---------------------------------------------------------------------------
//  Test fixture builders
// ---------------------------------------------------------------------------

let itemIdCounter = 0;

function makeItem(opts: {
  unitPrice: number;
  quantity?: number;
  toppingSubtotal?: number;
  status?: "pending" | "preparing" | "ready" | "served" | "voided";
  /** plan-043 — per-line snapshot tax rate (percent). Omit → legacy fallback. */
  taxRate?: number;
}): CustomerOrderItem {
  itemIdCounter += 1;
  const quantity = opts.quantity ?? 1;
  const toppingSubtotal = opts.toppingSubtotal ?? 0;
  return {
    id: `item-${itemIdCounter}`,
    customer_order_id: "order-1",
    product_sku_id: "sku-1",
    quantity,
    unit_price: opts.unitPrice,
    topping_subtotal: toppingSubtotal,
    // `topping_subtotal` is PER UNIT, so the line subtotal multiplies it with
    // the quantity — the same formula every writer on both transports uses
    // (`WritesCustomerOrders`, `order_service.go`). This fixture used to build
    // `quantity × unit_price + topping_subtotal`, a shape no writer produces,
    // which is how a whole suite could stay green over a calculator that
    // divided the extras across the units.
    subtotal: quantity * (opts.unitPrice + toppingSubtotal),
    status: opts.status ?? "pending",
    note: null,
    served_at: null,
    voided_at: null,
    void_reason: null,
    tax_rate: opts.taxRate ?? null,
    created_at: null,
    updated_at: null,
  };
}

function makeOrder(opts: {
  items: CustomerOrderItem[];
  discount?: number;
  taxRate?: number;
  serviceRate?: number;
}): {
  order: CustomerOrder;
  taxRate: number;
  serviceRate: number;
} {
  const taxRate = opts.taxRate ?? 0;
  const serviceRate = opts.serviceRate ?? 0;
  const validItems = opts.items.filter((i) => i.status !== "voided");
  const subtotal = validItems.reduce(
    (s, i) =>
      s + Number(i.quantity) * (Number(i.unit_price) + Number(i.topping_subtotal ?? 0)),
    0,
  );
  const discount = opts.discount ?? 0;
  const taxable = Math.max(0, subtotal - discount);
  const tax = Math.round((taxable * taxRate) / 100);
  const service = Math.round((taxable * serviceRate) / 100);
  const total = taxable + tax + service;

  return {
    order: {
      id: "order-1",
      order_code: "ORD-T-0001",
      order_type: "dine_in",
      status: "checkout",
      subtotal,
      discount_amount: discount,
      service_charge: service,
      tax_amount: tax,
      total_amount: total,
      paid_amount: 0,
      total_tip: 0,
      remaining_amount: String(total),
      opened_at: null,
      checkout_at: null,
      closed_at: null,
      voided_at: null,
      void_reason: null,
      guest_count: null,
      note: null,
      stock_out_transaction_id: null,
      created_by_id: null,
      customer_account_id: null,
      customer_id: null,
      branch_id: "branch-1",
      brand_id: "brand-1",
      organization_id: "org-1",
      items: opts.items,
      created_at: null,
      updated_at: null,
      deleted_at: null,
    },
    taxRate,
    serviceRate,
  };
}

/** Build a state where each item is fully assigned to one person. */
function assignAllToPerson(
  items: CustomerOrderItem[],
  people: number,
  personIdx: number,
): SplitByItemsState {
  const allocations: SplitByItemsState["allocations"] = {};
  for (const item of items.filter((i) => i.status !== "voided")) {
    const qty = Math.max(1, Number(item.quantity));
    allocations[item.id] = {
      itemId: item.id,
      units: Array.from({ length: qty }, () => personIdx),
    };
  }
  return { people, allocations };
}

// ---------------------------------------------------------------------------
//  Happy path — C1..C5
// ---------------------------------------------------------------------------

describe("splitByItems — happy", () => {
  it("C1 1 person 1 item — ratio 1, total === order.total", () => {
    const item = makeItem({ unitPrice: 1000 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [item], taxRate: 10 });
    const state = assignAllToPerson([item], 1, 0);

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills).toHaveLength(1);
    expect(r.bills[0]!.total).toBe(Number(order.total_amount));
    expect(r.totalCheck).toBe(Number(order.total_amount));
    expect(r.unassignedUnits).toHaveLength(0);
  });

  it("C2 2 people equal — bill1.total === bill2.total === order.total / 2", () => {
    const a = makeItem({ unitPrice: 500 });
    const b = makeItem({ unitPrice: 500 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [a, b],
      taxRate: 10,
      serviceRate: 5,
    });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills[0]!.total).toBe(r.bills[1]!.total);
    expect(r.totalCheck).toBe(Number(order.total_amount));
  });

  it("C3 user scenario — A 2 items (95k), B 3 items (250k), tax 10 svc 5", () => {
    const a1 = makeItem({ unitPrice: 50_000 });
    const a2 = makeItem({ unitPrice: 45_000 });
    const b1 = makeItem({ unitPrice: 100_000 });
    const b2 = makeItem({ unitPrice: 80_000 });
    const b3 = makeItem({ unitPrice: 70_000 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [a1, a2, b1, b2, b3],
      taxRate: 10,
      serviceRate: 5,
    });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [a1.id]: { itemId: a1.id, units: [0] },
        [a2.id]: { itemId: a2.id, units: [0] },
        [b1.id]: { itemId: b1.id, units: [1] },
        [b2.id]: { itemId: b2.id, units: [1] },
        [b3.id]: { itemId: b3.id, units: [1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills[0]!.subtotal).toBe(95_000);
    expect(r.bills[1]!.subtotal).toBe(250_000);
    expect(r.totalCheck).toBe(Number(order.total_amount));
  });

  it("C4 per-unit split — item qty=3, P1 2 units, P2 1 unit → 2/3 vs 1/3 of subtotal", () => {
    const item = makeItem({ unitPrice: 300, quantity: 3 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [item] });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [item.id]: { itemId: item.id, units: [0, 0, 1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills[0]!.subtotal).toBe(600);
    expect(r.bills[1]!.subtotal).toBe(300);
    expect(r.totalCheck).toBe(Number(order.total_amount));
  });

  it("C5 topping per-unit — each unit carries the FULL topping, not a share of it", () => {
    // REGRESSION (shop-reported, 2026-08-13). This case existed and was named
    // "per-unit", but its expectation was 10k + 30k/3 — the extras divided
    // across the units. Every guest but the last then under-paid and the last
    // absorbed the gap through the reconcile step, so the totals still added
    // up and nothing looked wrong. Cloud's SplitByItemsCalculator and the
    // workstation's LAN preview carried the identical division.
    const item = makeItem({
      unitPrice: 10_000,
      quantity: 3,
      toppingSubtotal: 30_000,
    });
    const { order, taxRate, serviceRate } = makeOrder({ items: [item] });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [item.id]: { itemId: item.id, units: [0, 0, 1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    // Per-unit subtotal = 10k + 30k = 40k. The order is 3 × 40k = 120k.
    expect(r.bills[0]!.subtotal).toBe(80_000); // 2 × 40k
    expect(r.bills[1]!.subtotal).toBe(40_000); // 1 × 40k
    expect(r.totalCheck).toBe(Number(order.total_amount));
  });

  it("C6 topping per-unit — an UNEVEN split cannot hide behind the reconcile", () => {
    // C5 divides evenly, so a per-unit price that is wrong by the same amount
    // on every unit still sums to the order total once the last bill absorbs
    // the drift. Two bills over three units is the shape where the error has
    // nowhere to go: bill 0 must be exactly 2 × 40k on its own.
    const item = makeItem({
      unitPrice: 10_000,
      quantity: 3,
      toppingSubtotal: 30_000,
    });
    const { order, taxRate, serviceRate } = makeOrder({ items: [item] });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [item.id]: { itemId: item.id, units: [0, 0, 1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills[0]!.subtotal + r.bills[1]!.subtotal).toBe(120_000);
    expect(r.bills[0]!.subtotal).toBe(80_000);
  });
});

// ---------------------------------------------------------------------------
//  Discount — D1..D3
// ---------------------------------------------------------------------------

describe("splitByItems — discount allocation", () => {
  it("D1 2 people equal subtotal, discount 10k → 5k each", () => {
    const a = makeItem({ unitPrice: 50_000 });
    const b = makeItem({ unitPrice: 50_000 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [a, b],
      discount: 10_000,
    });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills[0]!.discount).toBe(5_000);
    expect(r.bills[1]!.discount).toBe(5_000);
  });

  it("D2 60/40 ratio, discount 1000 → 600 / 400", () => {
    const a = makeItem({ unitPrice: 600 });
    const b = makeItem({ unitPrice: 400 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [a, b],
      discount: 1000,
    });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills[0]!.discount).toBe(600);
    expect(r.bills[1]!.discount).toBe(400);
  });

  it("D3 discount > small bill subtotal — bill total clamps ≥ 0", () => {
    const a = makeItem({ unitPrice: 200 });
    const b = makeItem({ unitPrice: 10_000 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [a, b],
      discount: 500, // 5 % of order subtotal 10_200
    });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    for (const bill of r.bills) {
      expect(bill.total).toBeGreaterThanOrEqual(0);
      expect(bill.taxableBase).toBeGreaterThanOrEqual(0);
    }
  });
});

// ---------------------------------------------------------------------------
//  Rounding — R1, R3 (R2 lives below as the property test)
// ---------------------------------------------------------------------------

describe("splitByItems — rounding", () => {
  it("R1 order total 100,003 split 3 → last non-empty hứng remainder", () => {
    const a = makeItem({ unitPrice: 33_334 });
    const b = makeItem({ unitPrice: 33_334 });
    const c = makeItem({ unitPrice: 33_335 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [a, b, c] });
    const state: SplitByItemsState = {
      people: 3,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [1] },
        [c.id]: { itemId: c.id, units: [2] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.totalCheck).toBe(Number(order.total_amount));
  });

  it("R3 tax/service consistent rounding — totalCheck within 1-unit tolerance of order.total_amount", () => {
    // Post plan-033 BE-parity fix: the calculator no longer reconciles Σ
    // bills to order.total_amount because the per-payment
    // `split_by_items_total_mismatch` guard recomputes each bill without
    // reconciliation, and a reconciled FE amount would drift from the
    // guard by ±1 unit. The invariant is now:
    //   |Σ bills - order.total_amount| ≤ #non-empty-bills × rounding step
    const a = makeItem({ unitPrice: 333 });
    const b = makeItem({ unitPrice: 667 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [a, b],
      taxRate: 8.25,
      serviceRate: 4.75,
    });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    // 2 bills × (tax rounding + service rounding) → max drift 4 units.
    expect(Math.abs(r.totalCheck - Number(order.total_amount))).toBeLessThanOrEqual(4);
  });
});

// ---------------------------------------------------------------------------
//  Edge — E1..E6
// ---------------------------------------------------------------------------

describe("splitByItems — edge cases", () => {
  it("E1 all unassigned — bills empty, unassignedUnits === Σ qty", () => {
    const a = makeItem({ unitPrice: 1000, quantity: 2 });
    const b = makeItem({ unitPrice: 2000 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [a, b] });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [a.id]: { itemId: a.id, units: [null, null] },
        [b.id]: { itemId: b.id, units: [null] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.unassignedUnits).toHaveLength(3);
    for (const bill of r.bills) {
      expect(bill.total).toBe(0);
      expect(bill.isEmpty).toBe(true);
    }
  });

  it("E2 1 person all assigned — single bill === order", () => {
    const a = makeItem({ unitPrice: 5000, quantity: 2 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [a],
      taxRate: 10,
    });
    const state = assignAllToPerson([a], 1, 0);

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills).toHaveLength(1);
    expect(r.bills[0]!.total).toBe(Number(order.total_amount));
  });

  it("E3 3 people only 2 used — last bill empty, total 0", () => {
    const a = makeItem({ unitPrice: 1000 });
    const b = makeItem({ unitPrice: 2000 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [a, b] });
    const state: SplitByItemsState = {
      people: 3,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [1] },
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills[2]!.isEmpty).toBe(true);
    expect(r.bills[2]!.total).toBe(0);
    expect(r.totalCheck).toBe(Number(order.total_amount));
  });

  it("E4 zero items (all voided) — bills are empty, no NaN", () => {
    const voided = makeItem({ unitPrice: 1000, status: "voided" });
    const { order, taxRate, serviceRate } = makeOrder({ items: [voided] });
    const state: SplitByItemsState = { people: 2, allocations: {} };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    for (const bill of r.bills) {
      expect(Number.isNaN(bill.total)).toBe(false);
      expect(bill.total).toBe(0);
    }
  });

  it("E5 order subtotal 0 — no div-by-zero, all bills 0", () => {
    const a = makeItem({ unitPrice: 0 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [a],
      taxRate: 10,
    });
    const state: SplitByItemsState = {
      people: 1,
      allocations: { [a.id]: { itemId: a.id, units: [0] } },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.bills[0]!.total).toBe(0);
  });

  it("E6 voided items excluded from allocation", () => {
    const live1 = makeItem({ unitPrice: 1000 });
    const live2 = makeItem({ unitPrice: 1000 });
    const voided = makeItem({ unitPrice: 9_999_999, status: "voided" });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [live1, live2, voided],
    });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [live1.id]: { itemId: live1.id, units: [0] },
        [live2.id]: { itemId: live2.id, units: [1] },
        // No allocation for voided item — calculator must skip it without crashing.
      },
    };

    const r = splitByItems({ order, taxRate, serviceRate, state });

    expect(r.totalCheck).toBe(Number(order.total_amount));
    // None of the bills should reflect the voided item's price.
    for (const bill of r.bills) {
      expect(bill.subtotal).toBeLessThan(9_999_999);
    }
  });
});

// ---------------------------------------------------------------------------
//  Property test — R2 / A2 — Σ bills.total === order.total_amount
// ---------------------------------------------------------------------------

function rng(seed: number): () => number {
  // Mulberry32 — small deterministic PRNG that always returns [0, 1).
  // (The earlier `Math.imul`/`% 0x7fffffff` form went negative on overflow
  // and produced out-of-range person indices, breaking the property test.)
  let s = seed >>> 0;
  return () => {
    s = (s + 0x6d2b79f5) >>> 0;
    let t = s;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

describe("splitByItems — property invariants", () => {
  it("R2 / A2 — 100 random fixtures: Σ bills.total === order.total_amount", () => {
    const rand = rng(20260511);
    for (let trial = 0; trial < 100; trial++) {
      const people = 2 + Math.floor(rand() * 4); // 2..5
      const itemCount = 1 + Math.floor(rand() * 10); // 1..10
      const items: CustomerOrderItem[] = [];
      for (let i = 0; i < itemCount; i++) {
        items.push(
          makeItem({
            unitPrice: 100 + Math.floor(rand() * 50_000),
            quantity: 1 + Math.floor(rand() * 3),
            toppingSubtotal: Math.random() > 0.7 ? Math.floor(rand() * 10_000) : 0,
          }),
        );
      }
      const discount = Math.random() > 0.5 ? Math.floor(rand() * 5_000) : 0;
      const { order, taxRate, serviceRate } = makeOrder({
        items,
        discount,
        taxRate: Math.floor(rand() * 15),
        serviceRate: Math.floor(rand() * 10),
      });

      const allocations: SplitByItemsState["allocations"] = {};
      for (const item of items) {
        const qty = Math.max(1, Number(item.quantity));
        const units: Array<number | null> = [];
        for (let u = 0; u < qty; u++) {
          units.push(Math.floor(rand() * people));
        }
        allocations[item.id] = { itemId: item.id, units };
      }

      const r = splitByItems({
        order,
        taxRate,
        serviceRate,
        state: { people, allocations },
      });

      // BE-parity: no reconciliation → Σ bills may drift from order.total
      // by at most 2 units per non-empty bill (one each for tax/service
      // rounding). The BE overpay guard clamps the final ≤1-unit drift
      // to exact outstanding.
      const nonEmpty = r.bills.filter((b) => !b.isEmpty).length;
      expect(Math.abs(r.totalCheck - Number(order.total_amount))).toBeLessThanOrEqual(
        Math.max(1, nonEmpty * 2),
      );
    }
  });
});

// ---------------------------------------------------------------------------
//  A1 — calculator stays pure (no service imports)
// ---------------------------------------------------------------------------

describe("splitByItems — architecture", () => {
  it("A1 — file does not import from @/services or React", () => {
    const src = readFileSync(
      new URL("./split-by-items.ts", import.meta.url),
      "utf8",
    );
    expect(src).not.toMatch(/from\s+["']@\/services\//);
    expect(src).not.toMatch(/from\s+["']react["']/);
    expect(src).not.toMatch(/from\s+["']react-dom["']/);
  });
});

describe("splitByItems — partial-payment aware (paidUnitsByItem)", () => {
  it("drops fully-paid items from unassigned + subtotal", () => {
    const paidItem = makeItem({ unitPrice: 1000, quantity: 2 });
    const freshItem = makeItem({ unitPrice: 500, quantity: 1 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [paidItem, freshItem],
    });
    // Simulate that a prior payment covered both units of `paidItem`.
    const partiallyPaidOrder: CustomerOrder = {
      ...order,
      paid_amount: 2000,
      remaining_amount: "500",
    };

    const result = splitByItems({
      order: partiallyPaidOrder,
      taxRate,
      serviceRate,
      state: {
        people: 1,
        allocations: {
          [freshItem.id]: { itemId: freshItem.id, units: [0] },
        },
      },
      paidUnitsByItem: { [paidItem.id]: 2 },
    });

    // Fully-paid item contributes zero unassigned slots + zero to the bill.
    expect(result.unassignedUnits).toEqual([]);
    expect(result.bills[0].subtotal).toBe(500);
    expect(result.bills[0].total).toBe(500);
    expect(result.totalCheck).toBe(500);
  });

  it("counts only remaining units as unassigned for partially-paid items", () => {
    const item = makeItem({ unitPrice: 300, quantity: 3 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [item] });
    // 1 of 3 units paid earlier → 2 units still to allocate.
    const partiallyPaidOrder: CustomerOrder = {
      ...order,
      paid_amount: 300,
      remaining_amount: "600",
    };

    const result = splitByItems({
      order: partiallyPaidOrder,
      taxRate,
      serviceRate,
      state: { people: 2, allocations: {} },
      paidUnitsByItem: { [item.id]: 1 },
    });

    // 2 remaining units — both unassigned.
    expect(result.unassignedUnits).toHaveLength(2);
    // Bills reconcile against remaining_amount, not the raw order total.
    expect(result.bills[0].subtotal).toBe(0);
    expect(result.bills[1].subtotal).toBe(0);
  });

  it("reconciles bill totals against remaining_amount, not total_amount", () => {
    const item = makeItem({ unitPrice: 700, quantity: 2 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [item] });
    const partiallyPaidOrder: CustomerOrder = {
      ...order,
      paid_amount: 700,
      remaining_amount: "700",
    };

    const result = splitByItems({
      order: partiallyPaidOrder,
      taxRate,
      serviceRate,
      state: {
        people: 1,
        allocations: {
          [item.id]: { itemId: item.id, units: [0] },
        },
      },
      paidUnitsByItem: { [item.id]: 1 },
    });

    // 1 remaining unit × 700 = 700 = remaining_amount → totalCheck matches.
    expect(result.totalCheck).toBe(700);
    expect(result.bills[0].total).toBe(700);
  });

  it("prorates the original discount to only the still-owed subtotal", () => {
    const paidItem = makeItem({ unitPrice: 500, quantity: 1 });
    const freshItem = makeItem({ unitPrice: 500, quantity: 1 });
    // Original: subtotal=1000, discount=100 → total=900 (no tax/service)
    const { order, taxRate, serviceRate } = makeOrder({
      items: [paidItem, freshItem],
      discount: 100,
    });
    // Paid the first item (500) already; remaining_amount = 400 (500 - 50 half-discount).
    const partiallyPaidOrder: CustomerOrder = {
      ...order,
      paid_amount: 450,
      remaining_amount: "450",
    };

    const result = splitByItems({
      order: partiallyPaidOrder,
      taxRate,
      serviceRate,
      state: {
        people: 1,
        allocations: {
          [freshItem.id]: { itemId: freshItem.id, units: [0] },
        },
      },
      paidUnitsByItem: { [paidItem.id]: 1 },
    });

    // Effective subtotal is 500 (fresh item only), half of raw 1000 →
    // effective discount = 50, taxable base = 450.
    expect(result.bills[0].subtotal).toBe(500);
    expect(result.bills[0].discount).toBe(50);
    expect(result.bills[0].total).toBe(450);
    expect(result.totalCheck).toBe(450);
  });

  it("does NOT inflate a non-empty bill to remaining when other units are still unassigned", () => {
    // Repro of the "cộng 1 món giá đã là total" bug: user assigns 1 out
    // of 2 units of item A + 1 out of 1 unit of item B to person 2, but
    // one unit of A is still unassigned. Person 2's bill should equal
    // A + B (not the whole remaining_amount) so the "Còn N món chưa gán"
    // badge is the one telling staff to keep allocating.
    const itemA = makeItem({ unitPrice: 2125, quantity: 2 });
    const itemB = makeItem({ unitPrice: 1658, quantity: 1 });
    const { order, taxRate, serviceRate } = makeOrder({
      items: [itemA, itemB],
    });
    // Assume no prior payments — remaining equals total.
    const result = splitByItems({
      order,
      taxRate,
      serviceRate,
      state: {
        people: 2,
        allocations: {
          [itemA.id]: { itemId: itemA.id, units: [null, 1] },
          [itemB.id]: { itemId: itemB.id, units: [1] },
        },
      },
    });

    // One unit of A still unassigned — badge should reflect that.
    expect(result.unassignedUnits).toHaveLength(1);
    // Person 1 is empty (no allocations at all).
    expect(result.bills[0].total).toBe(0);
    // Person 2 covers only the units allocated — NOT the full remaining.
    expect(result.bills[1].total).toBe(2125 + 1658);
    // totalCheck reflects the allocated units, not remaining_amount.
    expect(result.totalCheck).toBe(2125 + 1658);
  });

  it("defaults to raw quantities when paidUnitsByItem is omitted", () => {
    // Regression guard: existing call sites that don't pass the new param
    // must keep producing identical output.
    const item = makeItem({ unitPrice: 400, quantity: 2 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [item] });
    const state: SplitByItemsState = {
      people: 1,
      allocations: {
        [item.id]: { itemId: item.id, units: [0, 0] },
      },
    };

    const withParam = splitByItems({
      order,
      taxRate,
      serviceRate,
      state,
      paidUnitsByItem: {},
    });
    const withoutParam = splitByItems({ order, taxRate, serviceRate, state });

    expect(withParam.totalCheck).toBe(withoutParam.totalCheck);
    expect(withParam.bills[0].total).toBe(withoutParam.bills[0].total);
    expect(withParam.unassignedUnits).toEqual(withoutParam.unassignedUnits);
  });
});

// ---------------------------------------------------------------------------
//  plan-043 — mixed-rate bills (軽減税率 / インボイス)
//
//  Mirrors backend `tests/Unit/Services/SplitByItemsCalculatorTest.php`'s
//  mixed-rate assertions bit-for-bit: each bill groups its OWN items by
//  snapshot tax_rate and rounds tax ONCE per rate group (bentō 8% + beer 10%).
//  Currency JPY (step = 1) — same as VND, so per-rate numbers are exact yen.
// ---------------------------------------------------------------------------

/** bentō ¥1000 @ 8% + beer ¥500 @ 10% → order tax 130, total 1630. */
function mixedRateOrder(): {
  order: CustomerOrder;
  bento: CustomerOrderItem;
  beer: CustomerOrderItem;
} {
  const bento = makeItem({ unitPrice: 1000, taxRate: 8 });
  const beer = makeItem({ unitPrice: 500, taxRate: 10 });
  const { order } = makeOrder({ items: [bento, beer] });
  // makeOrder computes tax from a single `taxRate` — override with the real
  // per-rate engine result (80 + 50 = 130) so total_amount matches the
  // backend proof case.
  return {
    order: { ...order, tax_amount: 130, total_amount: 1630 },
    bento,
    beer,
  };
}

describe("splitByItems — mixed-rate (plan-043)", () => {
  it("taxes a single mixed-rate bill per rate group (8% + 10%)", () => {
    const { order, bento, beer } = mixedRateOrder();
    const state: SplitByItemsState = {
      people: 1,
      allocations: {
        [bento.id]: { itemId: bento.id, units: [0] },
        [beer.id]: { itemId: beer.id, units: [0] },
      },
    };

    const r = splitByItems({
      order,
      taxRate: 0,
      serviceRate: 0,
      state,
      currency: "JPY",
    });

    // 80 (8% of 1000) + 50 (10% of 500) = 130.
    expect(r.bills[0]!.tax).toBe(130);
    expect(r.bills[0]!.total).toBe(1630);
    // Per-rate breakdown surfaces both groups sorted ascending.
    expect(r.bills[0]!.taxBreakdown).toEqual([
      { rate: 8, taxable: 1000, tax: 80 },
      { rate: 10, taxable: 500, tax: 50 },
    ]);
  });

  it("splits a mixed-rate order across two bills, each taxed at its own rate", () => {
    const { order, bento, beer } = mixedRateOrder();
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [bento.id]: { itemId: bento.id, units: [0] },
        [beer.id]: { itemId: beer.id, units: [1] },
      },
    };

    const r = splitByItems({
      order,
      taxRate: 0,
      serviceRate: 0,
      state,
      currency: "JPY",
    });

    expect(r.bills[0]!.tax).toBe(80); // bentō only → 8%
    expect(r.bills[0]!.total).toBe(1080);
    expect(r.bills[0]!.taxBreakdown).toEqual([{ rate: 8, taxable: 1000, tax: 80 }]);
    expect(r.bills[1]!.tax).toBe(50); // beer only → 10%
    expect(r.bills[1]!.total).toBe(550);
    expect(r.bills[1]!.taxBreakdown).toEqual([{ rate: 10, taxable: 500, tax: 50 }]);
    expect(r.totalCheck).toBe(1630); // Σ = order total
  });

  it("rounds tax ONCE per rate group — 3 × ¥333 @ 8% = round(999×0.08)=80, not 27×3", () => {
    // The proof case: per-line rounding gives round(333×0.08)=27 → 81; the
    // per-GROUP rule gives round(999×0.08=79.92)=80. All three lines on one
    // bill collapse into a single 8% group.
    const a = makeItem({ unitPrice: 333, taxRate: 8 });
    const b = makeItem({ unitPrice: 333, taxRate: 8 });
    const c = makeItem({ unitPrice: 333, taxRate: 8 });
    const { order } = makeOrder({ items: [a, b, c] });
    const state: SplitByItemsState = {
      people: 1,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [0] },
        [c.id]: { itemId: c.id, units: [0] },
      },
    };

    const r = splitByItems({
      order,
      taxRate: 0,
      serviceRate: 0,
      state,
      currency: "JPY",
    });

    expect(r.bills[0]!.tax).toBe(80);
    expect(r.bills[0]!.taxBreakdown).toEqual([{ rate: 8, taxable: 999, tax: 80 }]);
  });

  it("prorates a coupon pro-rata per rate group then taxes each group once", () => {
    // 8% group ¥1000 + 10% group ¥1000, coupon ¥200 → 100 to each group.
    // 8% tax = round((1000-100)×0.08)=72 ; 10% tax = round((1000-100)×0.10)=90.
    const a = makeItem({ unitPrice: 1000, taxRate: 8 });
    const b = makeItem({ unitPrice: 1000, taxRate: 10 });
    const { order } = makeOrder({ items: [a, b], discount: 200 });
    const state: SplitByItemsState = {
      people: 1,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [0] },
      },
    };

    const r = splitByItems({
      order,
      taxRate: 0,
      serviceRate: 0,
      state,
      currency: "JPY",
    });

    expect(r.bills[0]!.discount).toBe(200);
    expect(r.bills[0]!.taxBreakdown).toEqual([
      { rate: 8, taxable: 900, tax: 72 },
      { rate: 10, taxable: 900, tax: 90 },
    ]);
    expect(r.bills[0]!.tax).toBe(162);
  });

  it("extracts 内税 per rate group in tax-included mode (総額表示)", () => {
    // Prices already include tax: bentō gross 1080 @8% → tax 80; beer gross
    // 550 @10% → tax 50. total = Σ gross = 1630, tax NOT added on top.
    const bento = makeItem({ unitPrice: 1080, taxRate: 8 });
    const beer = makeItem({ unitPrice: 550, taxRate: 10 });
    const { order } = makeOrder({ items: [bento, beer] });
    const includedOrder: CustomerOrder = {
      ...order,
      is_tax_included: true,
      tax_amount: 130,
      total_amount: 1630,
    };
    const state: SplitByItemsState = {
      people: 1,
      allocations: {
        [bento.id]: { itemId: bento.id, units: [0] },
        [beer.id]: { itemId: beer.id, units: [0] },
      },
    };

    const r = splitByItems({
      order: includedOrder,
      taxRate: 0,
      serviceRate: 0,
      state,
      currency: "JPY",
    });

    // 1080 − round(1080/1.08)=80 ; 550 − round(550/1.10)=50.
    expect(r.bills[0]!.tax).toBe(130);
    // Included mode: total = Σ gross (1630), tax already inside → not added.
    expect(r.bills[0]!.total).toBe(1630);
  });
});

// ---------------------------------------------------------------------------
//  areAllByItemsBillsSettled — the completion gate. Regression guard for the
//  premature-completion bug: paying the first person before the rest of the
//  order is allocated must NOT report the order done.
// ---------------------------------------------------------------------------

describe("areAllByItemsBillsSettled", () => {
  const paid = (...idxs: number[]) => {
    const set = new Set(idxs);
    return (i: number) => set.has(i);
  };

  it("THE BUG: person 0 paid but item B still unassigned → NOT settled", () => {
    const a = makeItem({ unitPrice: 1000 });
    const b = makeItem({ unitPrice: 2000 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [a, b] });
    // A → person 0; B assigned to nobody.
    const state: SplitByItemsState = {
      people: 2,
      allocations: { [a.id]: { itemId: a.id, units: [0] } },
    };
    const r = splitByItems({ order, taxRate, serviceRate, state });
    expect(r.unassignedUnits.length).toBe(1);
    expect(
      areAllByItemsBillsSettled({
        bills: r.bills,
        unassignedUnitsCount: r.unassignedUnits.length,
        isBillPaid: paid(0), // first person paid
      }),
    ).toBe(false);
  });

  it("all items allocated but only person 0 paid → NOT settled", () => {
    const a = makeItem({ unitPrice: 1000 });
    const b = makeItem({ unitPrice: 2000 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [a, b] });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [1] },
      },
    };
    const r = splitByItems({ order, taxRate, serviceRate, state });
    expect(r.unassignedUnits.length).toBe(0);
    expect(
      areAllByItemsBillsSettled({
        bills: r.bills,
        unassignedUnitsCount: 0,
        isBillPaid: paid(0),
      }),
    ).toBe(false);
  });

  it("all items allocated AND both people paid → settled", () => {
    const a = makeItem({ unitPrice: 1000 });
    const b = makeItem({ unitPrice: 2000 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [a, b] });
    const state: SplitByItemsState = {
      people: 2,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [1] },
      },
    };
    const r = splitByItems({ order, taxRate, serviceRate, state });
    expect(
      areAllByItemsBillsSettled({
        bills: r.bills,
        unassignedUnitsCount: 0,
        isBillPaid: paid(0, 1),
      }),
    ).toBe(true);
  });

  it("whole order to person 0 (others empty), person 0 paid → settled", () => {
    const a = makeItem({ unitPrice: 1000 });
    const b = makeItem({ unitPrice: 2000 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [a, b] });
    const state: SplitByItemsState = {
      people: 3,
      allocations: {
        [a.id]: { itemId: a.id, units: [0] },
        [b.id]: { itemId: b.id, units: [0] },
      },
    };
    const r = splitByItems({ order, taxRate, serviceRate, state });
    expect(r.unassignedUnits.length).toBe(0);
    // Only bill 0 is non-empty → paying it settles the whole order.
    expect(
      areAllByItemsBillsSettled({
        bills: r.bills,
        unassignedUnitsCount: 0,
        isBillPaid: paid(0),
      }),
    ).toBe(true);
  });

  it("nothing allocated yet → NOT settled (no non-empty bills)", () => {
    const a = makeItem({ unitPrice: 1000 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [a] });
    const state: SplitByItemsState = { people: 2, allocations: {} };
    const r = splitByItems({ order, taxRate, serviceRate, state });
    expect(
      areAllByItemsBillsSettled({
        bills: r.bills,
        unassignedUnitsCount: r.unassignedUnits.length,
        isBillPaid: paid(0, 1),
      }),
    ).toBe(false);
  });

  it("multi-unit item split across two people, only one paid → NOT settled", () => {
    const item = makeItem({ unitPrice: 1000, quantity: 3 });
    const { order, taxRate, serviceRate } = makeOrder({ items: [item] });
    // 2 units → person 0, 1 unit → person 1. Fully allocated.
    const state: SplitByItemsState = {
      people: 2,
      allocations: { [item.id]: { itemId: item.id, units: [0, 0, 1] } },
    };
    const r = splitByItems({ order, taxRate, serviceRate, state });
    expect(r.unassignedUnits.length).toBe(0);
    expect(
      areAllByItemsBillsSettled({
        bills: r.bills,
        unassignedUnitsCount: 0,
        isBillPaid: paid(0),
      }),
    ).toBe(false);
    expect(
      areAllByItemsBillsSettled({
        bills: r.bills,
        unassignedUnitsCount: 0,
        isBillPaid: paid(0, 1),
      }),
    ).toBe(true);
  });
});

// ---------------------------------------------------------------------------
//  #2159 — đơn đã hoàn tiền một phần
// ---------------------------------------------------------------------------

describe("#2159 — dòng hoàn không phải suất để chia", () => {
  /** Dòng gốc `qty` suất @¥1.000, đã hoàn `refunded` suất + dòng hoàn đi kèm. */
  function refundedOrder(qty: number, refunded: number) {
    const live = makeItem({ unitPrice: 1000, quantity: qty, status: "served" });
    live.refunded_quantity = refunded;

    const refundLine = makeItem({
      unitPrice: 1000,
      quantity: -refunded,
      status: "served",
    });
    refundLine.refund_of_item_id = live.id;
    refundLine.subtotal = -refunded * 1000;

    const remaining = (qty - refunded) * 1000;
    const order: CustomerOrder = {
      ...makeOrder({ items: [live] }).order,
      items: [live, refundLine],
      subtotal: remaining,
      total_amount: remaining,
    };

    return { order, liveId: live.id, refundId: refundLine.id };
  }

  const state = (allocations: SplitByItemsState["allocations"] = {}) =>
    ({ people: 2, allocations }) as SplitByItemsState;

  it("chỉ mời ra những suất CHƯA hoàn", () => {
    const { order } = refundedOrder(2, 1);

    const out = splitByItems({
      order,
      taxRate: 0,
      serviceRate: 0,
      state: state(),
    });

    // Trước #2159: 2 — suất đã trả lại tiền vẫn được đem chia lần nữa.
    expect(out.unassignedUnits).toHaveLength(1);
  });

  it("gán hết suất còn lại thì màn chia bill HOÀN TẤT được", () => {
    const { order, liveId } = refundedOrder(2, 1);

    const out = splitByItems({
      order,
      taxRate: 0,
      serviceRate: 0,
      state: state({ [liveId]: { units: [0] } }),
    });

    expect(out.unassignedUnits).toEqual([]);
    expect(out.bills[0]!.subtotal).toBe(1000);
    expect(out.bills[0]!.total).toBe(1000);
  });

  it("dòng hoàn không bao giờ xuất hiện như một món chia được", () => {
    const { order, refundId } = refundedOrder(2, 1);

    const out = splitByItems({
      order,
      taxRate: 0,
      serviceRate: 0,
      state: state({ [refundId]: { units: [1] } }),
    });

    expect(out.bills[1]!.itemsBreakdown).toEqual([]);
    expect(out.bills[1]!.total).toBe(0);
  });

  it("hoàn HẾT thì không còn suất nào để chia", () => {
    const { order } = refundedOrder(2, 2);

    const out = splitByItems({
      order,
      taxRate: 0,
      serviceRate: 0,
      state: state(),
    });

    expect(out.unassignedUnits).toEqual([]);
  });

  it("dòng chưa hoàn gì thì hành vi KHÔNG đổi", () => {
    const live = makeItem({ unitPrice: 1000, quantity: 3, status: "served" });
    const { order } = makeOrder({ items: [live] });

    const out = splitByItems({
      order,
      taxRate: 0,
      serviceRate: 0,
      state: state({ [live.id]: { units: [0, 0, null] } }),
    });

    expect(out.unassignedUnits).toHaveLength(1);
    expect(out.bills[0]!.subtotal).toBe(2000);
  });
});
