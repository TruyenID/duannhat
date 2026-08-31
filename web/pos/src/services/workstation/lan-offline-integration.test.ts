import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { setMode } from "@/services/workstation/base-url-resolver";

/**
 * LAN-offline integration tests — these exercise the apiFetch resolver +
 * routing layer end-to-end against a mocked workstation + mocked cloud
 * to lock in the contract pos-web relies on for cashier-shift and
 * order/coupon/promotion LAN-offline operation:
 *
 *   1. Workstation-first request routing in "auto" and "workstation" modes.
 *   2. Cloud-only routing in "cloud" mode.
 *   3. Split-bill metadata survives the LAN round-trip on POST /payments.
 *   4. Workstation network failure in "auto" mode falls back to Cloud.
 *   5. Idempotency-Key header is preserved (critical for shift / order
 *      replay semantics — Cloud's sync UP handlers dedup on this).
 *
 * Mocks `global.fetch` because that's where the LAN/Cloud target choice
 * crystallises into a real URL; nothing below `apiFetch` is mocked, so
 * any regression in the resolver, the auth header injection, or the
 * 401 redirect would show here.
 */

const originalFetch = global.fetch;
const fetchMock = vi.fn();

function reply(body: unknown = { data: { ok: true } }, status = 200): void {
  fetchMock.mockResolvedValueOnce({
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers({ "content-type": "application/json" }),
    json: async () => body,
    text: async () => JSON.stringify(body),
  } as unknown as Response);
}

function replyNetworkError(): void {
  fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));
}

function requestUrl(index: number): string {
  return String(fetchMock.mock.calls[index]![0]);
}

function requestInit(index: number): RequestInit {
  return fetchMock.mock.calls[index]![1] as RequestInit;
}

beforeEach(() => {
  global.fetch = fetchMock;
  fetchMock.mockReset();
  // Stash a stub bearer under the real key so apiFetch attaches Authorization
  // and clears the #472 no-token fail-fast guard for /pos/*.
  localStorage.setItem("pos_device_token", "test-bearer");
});

afterEach(() => {
  global.fetch = originalFetch;
  localStorage.clear();
  setMode("auto");
});

describe("LAN routing — auto mode", () => {
  it("dispatches /pos/till/sessions to the workstation in auto mode", async () => {
    setMode("auto");
    reply({ data: { id: "sess-1", status: "open" } });

    await apiFetch("/api/v1/pos/till/sessions", {
      method: "POST",
      body: JSON.stringify({ opening_counts: [] }),
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    // Workstation LAN URL convention: workstation.local or 127.0.0.1.
    // Either way, it must NOT be the Cloud URL.
    const url = requestUrl(0);
    expect(url).not.toMatch(/godx\.jp/);
    expect(url).toContain("/api/v1/pos/till/sessions");
  });

  it("forwards Idempotency-Key header verbatim — sync replay depends on it", async () => {
    setMode("auto");
    reply({ data: {} });

    await apiFetch("/api/v1/pos/orders/o-1/payments", {
      method: "POST",
      headers: { "Idempotency-Key": "key-abc-123" },
      body: JSON.stringify({ amount: 500, payment_method: "cash" }),
    });

    const init = requestInit(0);
    const headers = new Headers(init.headers as HeadersInit);
    expect(headers.get("Idempotency-Key")).toBe("key-abc-123");
  });

  it("includes split-bill by_items metadata on the LAN POST so workstation can persist it", async () => {
    setMode("auto");
    reply({ data: { id: "pmt-1" } });

    const body = {
      amount: 250,
      payment_method: "cash",
      metadata: {
        split_mode: "by_items",
        bill_index: 0,
        total_bills: 3,
        label: "Alice",
        item_allocations: [{ item_id: "i1", units: 1 }],
      },
    };
    await apiFetch("/api/v1/pos/orders/o-1/payments", {
      method: "POST",
      body: JSON.stringify(body),
    });

    const init = requestInit(0);
    const parsed = JSON.parse(init.body as string);
    expect(parsed.metadata.split_mode).toBe("by_items");
    expect(parsed.metadata.item_allocations[0].item_id).toBe("i1");
  });
});

describe("LAN routing — explicit modes", () => {
  it("dispatches to Cloud in cloud-only mode", async () => {
    setMode("cloud");
    reply({ data: {} });

    await apiFetch("/api/v1/pos/till/current");

    const url = requestUrl(0);
    expect(url).toMatch(/godx\.jp|localhost|127\./);
    // Even local Cloud-emulator URLs include the full /api/v1/pos/ path.
    expect(url).toContain("/api/v1/pos/till/current");
  });

  it("stays on workstation in explicit workstation mode even when LAN fails", async () => {
    setMode("workstation");
    replyNetworkError();

    await expect(
      apiFetch("/api/v1/pos/till/current"),
    ).rejects.toBeDefined();

    // Exactly one attempt — no Cloud fallback because user pinned LAN.
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});

describe("LAN routing — coverage for the previously-proxied endpoints", () => {
  // After registering the 5 new local handlers (orders list/detail,
  // customers find-or-create + outstanding, menu products search) these
  // requests must land on the workstation in auto/workstation modes
  // instead of falling through to Cloud. Revenue/by-product is the 6th
  // and follows the same pattern.

  it("orders list lands on workstation, not Cloud", async () => {
    setMode("auto");
    reply({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } });

    await apiFetch("/api/v1/pos/orders?status=open,dining");

    const url = requestUrl(0);
    expect(url).not.toMatch(/godx\.jp/);
    expect(url).toContain("/api/v1/pos/orders");
  });

  it("order detail lands on workstation", async () => {
    setMode("auto");
    reply({ data: { id: "o-1" } });

    await apiFetch("/api/v1/pos/orders/o-1");

    const url = requestUrl(0);
    expect(url).not.toMatch(/godx\.jp/);
    expect(url).toContain("/api/v1/pos/orders/o-1");
  });

  it("customer outstanding lookup lands on workstation", async () => {
    setMode("auto");
    reply({ data: [], total_owed: "0" });

    await apiFetch("/api/v1/pos/customers/cust-1/outstanding");

    const url = requestUrl(0);
    expect(url).not.toMatch(/godx\.jp/);
    expect(url).toContain("/api/v1/pos/customers/cust-1/outstanding");
  });

  it("customer find-or-create POST lands on workstation with body intact", async () => {
    setMode("auto");
    reply({ data: { id: "c-1" }, created: true }, 201);

    await apiFetch("/api/v1/pos/customers/find-or-create", {
      method: "POST",
      body: JSON.stringify({ phone: "090-1111" }),
    });

    const url = requestUrl(0);
    const init = requestInit(0);
    expect(url).not.toMatch(/godx\.jp/);
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string).phone).toBe("090-1111");
  });

  it("revenue/by-product lands on workstation", async () => {
    setMode("auto");
    reply({ data: { rows: [] } });

    await apiFetch("/api/v1/pos/revenue/by-product?from=2026-01-01&to=2026-01-31");

    const url = requestUrl(0);
    expect(url).not.toMatch(/godx\.jp/);
    expect(url).toContain("/api/v1/pos/revenue/by-product");
  });

  it("menu products search lands on workstation (dispatched via /menus/{seg1}/{seg2})", async () => {
    setMode("auto");
    reply({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } });

    await apiFetch("/api/v1/pos/menus/m1/products?page=1&per_page=50&search=Pho");

    const url = requestUrl(0);
    expect(url).not.toMatch(/godx\.jp/);
    expect(url).toContain("/api/v1/pos/menus/m1/products");
    expect(url).toContain("search=Pho");
  });
});

describe("LAN routing — auto-mode failover", () => {
  it("falls back to Cloud after a workstation network error", async () => {
    setMode("auto");
    replyNetworkError(); // workstation attempt
    reply({ data: { ok: true } }); // cloud retry

    const out = await apiFetch<{ data: { ok: true } }>("/api/v1/pos/till/current");

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(out.data.ok).toBe(true);

    // 1st = workstation, 2nd = cloud.
    const cloudURL = requestUrl(1);
    expect(cloudURL).toMatch(/godx\.jp|localhost|127\./);
  });

  it("does NOT retry on a 4xx response from the workstation — only on network errors", async () => {
    setMode("auto");
    reply({ message: "bad payload" }, 422); // workstation returned, but failed

    await expect(
      apiFetch("/api/v1/pos/till/sessions", {
        method: "POST",
        body: "{}",
      }),
    ).rejects.toBeDefined();

    expect(fetchMock).toHaveBeenCalledTimes(1); // no fallback
  });
});
