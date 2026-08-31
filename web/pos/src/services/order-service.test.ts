import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { orderService } from "./order-service";

const originalFetch = global.fetch;
const fetchMock = vi.fn();

function mockOk(body: unknown = { data: {} }): void {
  fetchMock.mockResolvedValueOnce({
    ok: true,
    status: 200,
    json: async () => body,
  } as Response);
}

function callArgs(): { url: string; init: RequestInit } {
  const [url, init] = fetchMock.mock.calls[0];
  return { url: String(url), init: init as RequestInit };
}

beforeEach(() => {
  global.fetch = fetchMock;
  fetchMock.mockReset();
  // Pair the device so /pos/* calls clear the #472 no-token fail-fast guard.
  localStorage.setItem("pos_device_token", "test-token");
});

afterEach(() => {
  global.fetch = originalFetch;
});

describe("orderService.list", () => {
  it("calls GET /shops/{slug}/orders with no params", async () => {
    mockOk({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } });
    await orderService.list("shop-a");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders");
    expect(init?.method ?? "GET").toBe("GET");
  });

  it("serializes filters as query string", async () => {
    mockOk({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } });
    await orderService.list("shop-a", { status: "open,dining", per_page: 50 });
    const { url } = callArgs();
    expect(url).toContain("status=open%2Cdining");
    expect(url).toContain("per_page=50");
  });
});

describe("orderService.create", () => {
  it("POSTs to /orders with JSON body", async () => {
    mockOk({ data: { id: "o1", status: "open" } });
    await orderService.create("shop-a", { order_type: "dine_in", guest_count: 2 });
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({
      order_type: "dine_in",
      guest_count: 2,
    });
  });
});

describe("orderService.addItems", () => {
  it("POSTs items array to /orders/{id}/items", async () => {
    mockOk({ data: { id: "o1", items: [] } });
    await orderService.addItems("shop-a", "o1", [
      { product_sku_id: "sku-1", quantity: 2 },
    ]);
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/items");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({
      items: [{ product_sku_id: "sku-1", quantity: 2 }],
    });
  });
});

describe("orderService.updateItem", () => {
  it("PATCHes to /orders/{id}/items/{itemId}", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.updateItem("shop-a", "o1", "item-1", { quantity: 3 });
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/items/item-1");
    expect(init.method).toBe("PATCH");
    expect(JSON.parse(init.body as string)).toEqual({ quantity: 3 });
  });
});

describe("orderService.voidItem", () => {
  it("POSTs void reason to /items/{itemId}/void", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.voidItem("shop-a", "o1", "item-1", {
      void_reason: "made wrong",
    });
    const { url, init } = callArgs();
    expect(url).toContain("/items/item-1/void");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({ void_reason: "made wrong" });
  });

  it("sends void_reason_id + note when a master reason is picked (plan-051)", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.voidItem("shop-a", "o1", "item-1", {
      void_reason_id: "vr-1",
      void_reason: "table 5 asked to swap",
    });
    const { init } = callArgs();
    expect(JSON.parse(init.body as string)).toEqual({
      void_reason_id: "vr-1",
      void_reason: "table 5 asked to swap",
    });
  });

  it("omits absent keys so required_without validation sees the true shape", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.voidItem("shop-a", "o1", "item-1", {
      void_reason_id: "vr-1",
    });
    const { init } = callArgs();
    expect(JSON.parse(init.body as string)).toEqual({ void_reason_id: "vr-1" });
  });
});

describe("orderService.checkout", () => {
  it("POSTs to /orders/{id}/checkout", async () => {
    mockOk({ data: { id: "o1", status: "checkout" } });
    await orderService.checkout("shop-a", "o1");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/checkout");
    expect(init.method).toBe("POST");
  });
});

describe("orderService.voidOrder", () => {
  it("POSTs void_reason to /orders/{id}/void", async () => {
    mockOk({ data: { id: "o1", status: "voided" } });
    await orderService.voidOrder("shop-a", "o1", "test cancel");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/void");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({ void_reason: "test cancel" });
  });
});

describe("orderService.delete", () => {
  it("DELETEs /orders/{id}", async () => {
    mockOk(null);
    await orderService.delete("shop-a", "o1");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1");
    expect(init.method).toBe("DELETE");
  });
});

describe("orderService.applyCoupon", () => {
  it("POSTs coupon code to /apply-coupon", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.applyCoupon("shop-a", "o1", { code: "SAVE10" });
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/apply-coupon");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({ code: "SAVE10" });
  });

  it("forwards downgrade_exclusive_promotions flag", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.applyCoupon("shop-a", "o1", {
      code: "VIP",
      downgrade_exclusive_promotions: true,
    });
    const body = JSON.parse(callArgs().init.body as string);
    expect(body.downgrade_exclusive_promotions).toBe(true);
  });
});

describe("orderService.releaseCoupon", () => {
  it("DELETEs /coupon", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.releaseCoupon("shop-a", "o1");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/coupon");
    expect(init.method).toBe("DELETE");
  });
});

describe("orderService.mergeTable", () => {
  it("POSTs table_id to /merge-table", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.mergeTable("shop-a", "o1", "table-5");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/merge-table");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({ table_id: "table-5" });
  });
});

describe("orderService.unmergeTable", () => {
  it("POSTs table_id to /unmerge-table", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.unmergeTable("shop-a", "o1", "table-5");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/unmerge-table");
    expect(init.method).toBe("POST");
  });
});

describe("orderService.getSplitBill", () => {
  it("GETs /split-bill without query param when count omitted", async () => {
    mockOk({ split_count: 2, rows: [] });
    await orderService.getSplitBill("shop-a", "o1");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/split-bill");
    expect(url).not.toContain("split_count=");
    expect(init?.method ?? "GET").toBe("GET");
  });

  it("GETs /split-bill with split_count param", async () => {
    mockOk({ split_count: 4, rows: [] });
    await orderService.getSplitBill("shop-a", "o1", 4);
    const { url } = callArgs();
    expect(url).toContain("/split-bill?split_count=4");
  });
});

describe("orderService.update + init", () => {
  it("update PUTs to /orders/{id}", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.update("shop-a", "o1", { guest_count: 4 });
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1");
    expect(init.method).toBe("PUT");
    expect(JSON.parse(init.body as string)).toEqual({ guest_count: 4 });
  });

  it("init PUTs to /orders/{id}/init", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.init("shop-a", "o1", { order_type: "dine_in" });
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/init");
    expect(init.method).toBe("PUT");
  });
});

describe("orderService.removeItem", () => {
  it("DELETEs /items/{itemId}", async () => {
    mockOk({ data: { id: "o1" } });
    await orderService.removeItem("shop-a", "o1", "item-1");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/items/item-1");
    expect(init.method).toBe("DELETE");
  });
});
