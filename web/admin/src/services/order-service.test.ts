import { describe, it, expect, vi, beforeEach } from "vitest";
import { orderService } from "./order-service";

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

function mockOk(data: unknown) {
  mockFetch.mockResolvedValueOnce({
    ok: true,
    status: 200,
    json: () => Promise.resolve(data),
  });
}

beforeEach(() => {
  mockFetch.mockReset();
});

describe("orderService", () => {
  it("create sends POST with exact payload shape", async () => {
    const payload = {
      order_type: "dine_in" as const,
      customer_id: "c1",
      table_id: "t1",
      note: null,
      items: [{ product_sku_id: "sku-1", quantity: 2, unit_price: 1200 }],
    };
    mockOk({ data: { id: "ord-1", ...payload } });

    await orderService.create("shop-1", payload);

    const [, opts] = mockFetch.mock.calls[0];
    expect(opts.method).toBe("POST");
    const sent = JSON.parse(opts.body);
    expect(sent.items[0].unit_price).toBe(1200);
    expect(sent.items[0].product_sku_id).toBe("sku-1");
  });

  it("complete POSTs with payment_method and discount", async () => {
    mockOk({ data: { id: "ord-1", status: "completed" } });

    await orderService.complete("shop-1", "ord-1", {
      payment_method: "cash",
      discount_amount: 1000,
    });

    const [url, opts] = mockFetch.mock.calls[0];
    // orderService.complete() POSTs to the backend `/checkout` route
    // (backend/routes/api/pos.php) — the old "/complete" assertion was stale.
    expect(url).toContain("/orders/ord-1/checkout");
    expect(opts.method).toBe("POST");
    const body = JSON.parse(opts.body);
    expect(body.payment_method).toBe("cash");
    expect(body.discount_amount).toBe(1000);
  });

  it("list builds query string with status and date filters", async () => {
    mockOk({ data: [], links: {}, meta: {} });

    await orderService.list("shop-1", {
      status: "pending",
      date_from: "2026-04-01",
    });

    const url = mockFetch.mock.calls[0][0] as string;
    expect(url).toContain("status=pending");
    expect(url).toContain("date_from=2026-04-01");
  });
});
