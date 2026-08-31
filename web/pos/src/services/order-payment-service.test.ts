import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { orderPaymentService } from "./order-payment-service";

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

describe("orderPaymentService.list", () => {
  it("GETs /shops/{slug}/orders/{id}/payments", async () => {
    mockOk({ data: [] });
    await orderPaymentService.list("shop-a", "o1");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/payments");
    expect(init?.method ?? "GET").toBe("GET");
  });
});

describe("orderPaymentService.create", () => {
  it("POSTs payment body to /payments", async () => {
    mockOk({ data: { id: "p1", status: "succeeded" } });
    await orderPaymentService.create("shop-a", "o1", {
      payment_method_id: "pm-cash",
      amount: 10000,
      tendered_amount: 10000,
    });
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/payments");
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({
      payment_method_id: "pm-cash",
      amount: 10000,
      tendered_amount: 10000,
    });
  });

  it("includes idempotency_key when provided", async () => {
    mockOk({ data: { id: "p1" } });
    await orderPaymentService.create("shop-a", "o1", {
      payment_method_id: "pm-cash",
      amount: 5000,
      idempotency_key: "test-key-uuid",
    });
    const { init } = callArgs();
    expect(JSON.parse(init.body as string).idempotency_key).toBe("test-key-uuid");
  });

  it("includes split-bill metadata for equal mode", async () => {
    mockOk({ data: { id: "p1" } });
    await orderPaymentService.create("shop-a", "o1", {
      payment_method_id: "pm-cash",
      amount: 5000,
      expected_total_amount: 15000,
      metadata: {
        split_mode: "even",
        bill_index: 0,
        total_bills: 3,
      },
    });
    const body = JSON.parse(callArgs().init.body as string);
    expect(body.expected_total_amount).toBe(15000);
    expect(body.metadata).toEqual({
      split_mode: "even",
      bill_index: 0,
      total_bills: 3,
    });
  });

  it("includes split-bill metadata for by_items mode", async () => {
    mockOk({ data: { id: "p1" } });
    await orderPaymentService.create("shop-a", "o1", {
      payment_method_id: "pm-cash",
      amount: 8000,
      metadata: {
        split_mode: "by_items",
        bill_index: 1,
        total_bills: 2,
        label: "Customer B",
        item_allocations: [{ item_id: "i1", units: 2 }],
      },
    });
    const body = JSON.parse(callArgs().init.body as string);
    expect(body.metadata.split_mode).toBe("by_items");
    expect(body.metadata.item_allocations).toEqual([{ item_id: "i1", units: 2 }]);
  });
});

describe("orderPaymentService.refund", () => {
  it("POSTs to /payments/{id}/refund with a stable idempotency key", async () => {
    mockOk({ data: { id: "p1", status: "refunded" } });
    await orderPaymentService.refund("shop-a", "o1", "p1", {
      idempotency_key: "split-refund:p1",
    });
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/orders/o1/payments/p1/refund");
    expect(init.method).toBe("POST");
    expect(init.headers).toEqual(
      expect.objectContaining({ "Idempotency-Key": "split-refund:p1" }),
    );
    expect(JSON.parse(init.body as string)).toEqual({
      idempotency_key: "split-refund:p1",
    });
  });

  it("forwards partial amount + note", async () => {
    mockOk({ data: { id: "p1" } });
    await orderPaymentService.refund("shop-a", "o1", "p1", {
      idempotency_key: "refund-partial-1",
      amount: 2500,
      note: "partial",
    });
    const body = JSON.parse(callArgs().init.body as string);
    expect(body).toEqual({
      idempotency_key: "refund-partial-1",
      amount: 2500,
      note: "partial",
    });
  });
});
