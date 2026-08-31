import { renderHook, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { useBumpAll } from "../use-bump-all";
import { useMarkReady } from "../use-mark-ready";
import { useMarkPreparing } from "../use-mark-preparing";
import { setDeviceToken } from "@/services/auth/device-token";
import { I18nProvider } from "@/i18n";
import { makeOrder, makeItem } from "@/test/fixtures/kds";
import type { KdsOrdersQueryData } from "@/services/kds/orders";

const { recordBumpKeySpy } = vi.hoisted(() => ({
  recordBumpKeySpy: vi.fn(),
}));

vi.mock("@/services/realtime/dispatcher", () => ({
  createRealtimeDispatcher: vi.fn(() => ({
    on: vi.fn(() => () => {}),
    connect: vi.fn(),
    close: vi.fn(),
  })),
}));

vi.mock("@/providers/use-realtime", () => ({
  useRealtime: () => ({
    mode: "lan" as const,
    isConnected: true,
    recordBumpKey: recordBumpKeySpy,
  }),
}));

function wrap(qc: QueryClient) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <I18nProvider>
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      </I18nProvider>
    );
  };
}

function newQc() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
  });
}

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
  recordBumpKeySpy.mockClear();
  setDeviceToken("tok", {
    id: "d-1",
    name: "KDS-1",
    type: "kds",
    status: "active",
    branch_id: "b-1",
  });
  global.fetch = vi.fn().mockImplementation(async () => ({
    ok: true,
    status: 200,
    json: async () => ({ data: makeOrder({ id: "o-1" }) }),
  }));
});

// ────────────────────────────────────────────────────────────────────────────
// Gap 1 — bump-all guard bypass (pending scope + out-of-scope + terminal items)
// ────────────────────────────────────────────────────────────────────────────
describe("useBumpAll scope + transition guard", () => {
  it("skips a pending item whose allowed_transitions lack mark-preparing (guard bypass)", async () => {
    const qc = newQc();
    // Pending item the server has gated (e.g. order not yet fireable) — no
    // `mark-preparing` in allowed_transitions. Bump-all MUST NOT advance it.
    const ok = makeItem({ id: "i-ok", status: "pending", allowed_transitions: ["mark-preparing"] });
    const gated = makeItem({ id: "i-gated", status: "pending", allowed_transitions: [] });
    const seed = makeOrder({ id: "o-1", items: [ok, gated] });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useBumpAll(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({
        orderId: "o-1",
        scope: "pending",
        targetItems: [ok, gated],
      });
    });

    // Only the eligible item gets a dedup key.
    expect(recordBumpKeySpy).toHaveBeenCalledTimes(1);
    expect((recordBumpKeySpy.mock.calls[0][0] as string).endsWith(":i-ok")).toBe(true);

    const items = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"])?.orders[0].items ?? [];
    expect(items.find((i) => i.id === "i-ok")?.status).toBe("preparing");
    expect(items.find((i) => i.id === "i-gated")?.status).toBe("pending");
  });

  it("never touches items outside the requested scope, including terminal served items", async () => {
    const qc = newQc();
    // A mixed order: pending (in scope), ready + served (out of scope). A
    // pending-scope bump-all must advance ONLY the pending item — advancing the
    // ready item to served, or resurrecting the terminal served item, would be
    // a guard bypass.
    const pending = makeItem({ id: "i-p", status: "pending", allowed_transitions: ["mark-preparing"] });
    const ready = makeItem({
      id: "i-r",
      status: "ready",
      allowed_transitions: ["mark-served", "revert"],
    });
    const served = makeItem({ id: "i-s", status: "served", allowed_transitions: ["revert"] });
    const seed = makeOrder({ id: "o-1", items: [pending, ready, served] });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useBumpAll(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({
        orderId: "o-1",
        scope: "pending",
        targetItems: [pending, ready, served],
      });
    });

    // Only the in-scope pending item is keyed + advanced.
    expect(recordBumpKeySpy).toHaveBeenCalledTimes(1);
    expect((recordBumpKeySpy.mock.calls[0][0] as string).endsWith(":i-p")).toBe(true);

    const items = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"])?.orders[0].items ?? [];
    expect(items.find((i) => i.id === "i-p")?.status).toBe("preparing");
    expect(items.find((i) => i.id === "i-r")?.status).toBe("ready"); // untouched
    expect(items.find((i) => i.id === "i-s")?.status).toBe("served"); // terminal, untouched
  });
});

// ────────────────────────────────────────────────────────────────────────────
// Gap 2 — served is terminal: a forward mark-* on a served item must roll back
// ────────────────────────────────────────────────────────────────────────────
describe("served terminal enforcement via mark-*", () => {
  it("rolls a served item back to served when the server rejects mark-ready (409)", async () => {
    const qc = newQc();
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 409,
      json: async () => ({ code: "KDS_E002", detail: "served is terminal" }),
    });
    const seed = makeOrder({
      id: "o-1",
      items: [
        makeItem({
          id: "i-1",
          status: "served",
          started_preparing_at: "2026-05-26T10:00:00Z",
          ready_at: "2026-05-26T10:05:00Z",
          served_at: "2026-05-26T10:10:00Z",
        }),
      ],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useMarkReady(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", itemId: "i-1" }).catch(() => {});
    });

    // Full rollback: the terminal served state (and its timestamps) is restored.
    const item = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"])?.orders[0].items[0];
    expect(item?.status).toBe("served");
    expect(item?.served_at).toBe("2026-05-26T10:10:00Z");
    expect(item?.ready_at).toBe("2026-05-26T10:05:00Z");
  });
});

// ────────────────────────────────────────────────────────────────────────────
// Gap 4 — concurrent idempotency: every distinct user action gets a fresh key
// ────────────────────────────────────────────────────────────────────────────
describe("idempotency key uniqueness across actions", () => {
  it("generates a distinct idempotency key for each single-item bump (no key reuse)", async () => {
    const qc = newQc();
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeItem({ id: "i-1", status: "preparing" }) }),
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], {
      orders: [makeOrder({ id: "o-1", items: [makeItem({ id: "i-1", status: "pending" })] })],
    });

    const { result } = renderHook(() => useMarkPreparing(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", itemId: "i-1" });
    });
    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", itemId: "i-1" });
    });

    expect(recordBumpKeySpy).toHaveBeenCalledTimes(2);
    const keys = recordBumpKeySpy.mock.calls.map((c) => c[0] as string);
    // A retried tap must NOT reuse the previous key — otherwise the second,
    // legitimately-intended action would be swallowed as a replay server-side.
    expect(keys[0]).not.toBe(keys[1]);
  });

  it("uses a fresh batch key per bump-all invocation (no cross-batch collision)", async () => {
    const qc = newQc();
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeOrder({ id: "o-1" }) }),
    });
    const item = () =>
      makeItem({ id: "i-1", status: "pending", allowed_transitions: ["mark-preparing"] });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], {
      orders: [makeOrder({ id: "o-1", items: [item()] })],
    });

    const { result } = renderHook(() => useBumpAll(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", scope: "pending", targetItems: [item()] });
    });
    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", scope: "pending", targetItems: [item()] });
    });

    expect(recordBumpKeySpy).toHaveBeenCalledTimes(2);
    const keys = recordBumpKeySpy.mock.calls.map((c) => c[0] as string);
    const batchPrefixes = keys.map((k) => k.split(":")[0]);
    // Two separate bulk actions → two distinct batch UUIDs.
    expect(batchPrefixes[0]).not.toBe(batchPrefixes[1]);
    // ...but both address the same item id.
    expect(keys.every((k) => k.endsWith(":i-1"))).toBe(true);
  });
});

// ────────────────────────────────────────────────────────────────────────────
// Gap 5 — optimistic-cache isolation: a bump must not leak across orders
// (FE analog of server-enforced branch/org scoping; blast-radius containment)
// ────────────────────────────────────────────────────────────────────────────
describe("optimistic update isolation across orders", () => {
  it("patches only the target order's item, leaving sibling orders untouched", async () => {
    const qc = newQc();
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeItem({ id: "i-1", status: "ready" }) }),
    });
    const orderA = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "preparing" })],
    });
    const orderB = makeOrder({
      id: "o-2",
      order_code: "099",
      items: [makeItem({ id: "i-1", status: "preparing" })], // same item id, different order
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [orderA, orderB] });

    const { result } = renderHook(() => useMarkReady(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", itemId: "i-1" });
    });

    const after = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
    // Target order advanced.
    expect(after?.orders.find((o) => o.id === "o-1")?.items[0].status).toBe("ready");
    // Sibling order with a same-id item is NOT touched — no cross-order bleed.
    expect(after?.orders.find((o) => o.id === "o-2")?.items[0].status).toBe("preparing");
  });

  it("leaves the whole cache unchanged when the target order id is absent", async () => {
    const qc = newQc();
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeItem({ id: "i-1", status: "ready" }) }),
    });
    const orderA = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "preparing" })],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [orderA] });

    const { result } = renderHook(() => useMarkReady(), { wrapper: wrap(qc) });

    await act(async () => {
      // orderId that isn't in cache (e.g. belongs to another branch/device view).
      await result.current.mutateAsync({ orderId: "o-UNKNOWN", itemId: "i-1" });
    });

    const after = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
    expect(after?.orders.find((o) => o.id === "o-1")?.items[0].status).toBe("preparing");
  });
});
