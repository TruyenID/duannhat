import { describe, it, expect, beforeEach } from "vitest";
import {
  saveOrdersSnapshot,
  loadOrdersSnapshot,
  clearOrdersSnapshot,
  type OrdersSnapshot,
} from "../idb";
import { makeOrder, makeItem } from "@/test/fixtures/kds";

const sampleSnapshot: OrdersSnapshot = {
  fetched_at: "2026-05-26T14:00:00Z",
  orders: [
    makeOrder({
      id: "o-1",
      order_code: "042",
      opened_at: "2026-05-26T13:55:00Z",
      items: [makeItem({ id: "i-1", menu_item_name: "Phở Bò", quantity: 1, status: "pending" })],
    }),
  ],
};

beforeEach(async () => {
  await clearOrdersSnapshot();
});

describe("idb orders snapshot cache", () => {
  it("returns null when cache empty", async () => {
    const result = await loadOrdersSnapshot();
    expect(result).toBeNull();
  });

  it("round-trips a snapshot", async () => {
    await saveOrdersSnapshot(sampleSnapshot);
    const loaded = await loadOrdersSnapshot();
    expect(loaded?.fetched_at).toBe(sampleSnapshot.fetched_at);
    expect(loaded?.orders).toHaveLength(1);
    expect(loaded?.orders[0].id).toBe("o-1");
  });

  it("overwrites on save (single 'latest' key)", async () => {
    await saveOrdersSnapshot(sampleSnapshot);
    const second: OrdersSnapshot = {
      fetched_at: "2026-05-26T14:30:00Z",
      orders: [],
    };
    await saveOrdersSnapshot(second);
    const loaded = await loadOrdersSnapshot();
    expect(loaded?.fetched_at).toBe(second.fetched_at);
    expect(loaded?.orders).toHaveLength(0);
  });

  it("clearOrdersSnapshot removes cached entry", async () => {
    await saveOrdersSnapshot(sampleSnapshot);
    await clearOrdersSnapshot();
    expect(await loadOrdersSnapshot()).toBeNull();
  });
});
