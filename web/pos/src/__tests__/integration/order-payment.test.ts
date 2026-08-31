/**
 * Integration test — order → checkout → payment happy path.
 *
 * Verifies that the service layer orchestrates correctly when wired together:
 *   1. Create order
 *   2. Add items
 *   3. Checkout
 *   4. Create payment (cash auto-confirm)
 *   5. Verify final state
 *
 * Uses MSW to intercept fetch at the network layer (no service mocks).
 */

import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it } from "vitest";
import { http, HttpResponse } from "msw";
import { setupServer } from "msw/node";
import { orderService } from "@/services/order-service";
import { orderPaymentService } from "@/services/order-payment-service";
import { setMode } from "@/services/workstation/base-url-resolver";

const API_BASE = "http://localhost:5400";

// Force Cloud-direct mode so this integration suite hits the MSW handlers
// registered against API_BASE. Workstation routing (the new default in auto
// mode) would target http://localhost:8080 which these handlers don't cover —
// that's intentionally tested via workstation-app's own Go test suite.
beforeEach(() => {
  setMode("cloud");
  // Pair the device so /pos/* calls clear the #472 no-token fail-fast guard.
  localStorage.setItem("pos_device_token", "test-token");
});

// Mutable state shared across handlers — simulates backend persistence.
let orderState: {
  id: string;
  order_code: string;
  status: string;
  subtotal: number;
  total_amount: number;
  paid_amount: number;
  items: Array<{ id: string; product_sku_id: string; quantity: number; unit_price: number }>;
} = {
  id: "order-1",
  order_code: "ORD-2026-0001",
  status: "open",
  subtotal: 0,
  total_amount: 0,
  paid_amount: 0,
  items: [],
};

const server = setupServer(
  http.post(`${API_BASE}/api/v1/pos/orders`, async () => {
    orderState = {
      id: "order-1",
      order_code: "ORD-2026-0001",
      status: "open",
      subtotal: 0,
      total_amount: 0,
      paid_amount: 0,
      items: [],
    };
    return HttpResponse.json({ data: orderState });
  }),

  http.post(`${API_BASE}/api/v1/pos/orders/:id/items`, async ({ request }) => {
    const body = (await request.json()) as {
      items: Array<{ product_sku_id: string; quantity: number }>;
    };
    let nextItemId = orderState.items.length;
    for (const i of body.items) {
      orderState.items.push({
        id: `item-${++nextItemId}`,
        product_sku_id: i.product_sku_id,
        quantity: i.quantity,
        unit_price: 500,
      });
    }
    orderState.subtotal = orderState.items.reduce(
      (sum, it) => sum + it.unit_price * it.quantity,
      0,
    );
    orderState.total_amount = Math.round(orderState.subtotal * 1.1); // 10% tax
    return HttpResponse.json({ data: orderState });
  }),

  http.post(`${API_BASE}/api/v1/pos/orders/:id/checkout`, async () => {
    orderState.status = "checkout";
    return HttpResponse.json({ data: orderState });
  }),

  http.post(`${API_BASE}/api/v1/pos/orders/:id/payments`, async ({ request }) => {
    const body = (await request.json()) as {
      amount: number;
      idempotency_key?: string;
    };
    orderState.paid_amount += body.amount;
    if (orderState.paid_amount >= orderState.total_amount) {
      orderState.status = "closed";
    } else {
      orderState.status = "paying";
    }
    return HttpResponse.json({
      data: {
        id: "payment-1",
        amount: body.amount,
        status: "succeeded",
        idempotency_key: body.idempotency_key,
      },
    });
  }),

  http.get(`${API_BASE}/api/v1/pos/orders/:id`, () =>
    HttpResponse.json({ data: orderState }),
  ),
);

beforeAll(() => server.listen({ onUnhandledRequest: "error" }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe("integration: order → checkout → payment", () => {
  it("happy path closes the order with cash payment", async () => {
    // 1. Create order
    const createRes = await orderService.create("shop-a", { order_type: "dine_in", guest_count: 2 });
    expect(createRes.data.status).toBe("open");
    expect(createRes.data.order_code).toBe("ORD-2026-0001");

    // 2. Add 2 items × 500 = 1000 subtotal, 1100 total with tax
    const addRes = await orderService.addItems("shop-a", createRes.data.id, [
      { product_sku_id: "sku-1", quantity: 1 },
      { product_sku_id: "sku-2", quantity: 1 },
    ]);
    expect(addRes.data.items).toHaveLength(2);
    expect(addRes.data.subtotal).toBe(1000);
    expect(addRes.data.total_amount).toBe(1100);

    // 3. Checkout
    const checkoutRes = await orderService.checkout("shop-a", createRes.data.id);
    expect(checkoutRes.data.status).toBe("checkout");

    // 4. Pay full amount with cash
    const payRes = await orderPaymentService.create("shop-a", createRes.data.id, {
      payment_method_id: "pm-cash",
      amount: 1100,
      tendered_amount: 1100,
      idempotency_key: "idem-test-1",
    });
    expect(payRes.data.status).toBe("succeeded");
    expect(payRes.data.idempotency_key).toBe("idem-test-1");

    // 5. Verify final order state (refetch)
    const finalRes = await orderService.get("shop-a", createRes.data.id);
    expect(finalRes.data.status).toBe("closed");
    expect(finalRes.data.paid_amount).toBe(1100);
  });

  it("partial payment leaves order in 'paying' status", async () => {
    await orderService.create("shop-a", { order_type: "dine_in", guest_count: 1 });
    await orderService.addItems("shop-a", "order-1", [
      { product_sku_id: "sku-1", quantity: 2 }, // 2 × 500 = 1000, total 1100
    ]);
    await orderService.checkout("shop-a", "order-1");

    // Pay only half
    await orderPaymentService.create("shop-a", "order-1", {
      payment_method_id: "pm-cash",
      amount: 500,
    });

    const finalRes = await orderService.get("shop-a", "order-1");
    expect(finalRes.data.status).toBe("paying");
    expect(finalRes.data.paid_amount).toBe(500);
  });
});
