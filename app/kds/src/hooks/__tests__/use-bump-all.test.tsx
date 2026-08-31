import { renderHook, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { useBumpAll } from "../use-bump-all";
import { setDeviceToken } from "@/services/auth/device-token";
import { I18nProvider } from "@/i18n";
import { makeOrder, makeItem } from "@/test/fixtures/kds";

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

function wrap(qc: QueryClient) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <I18nProvider>
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      </I18nProvider>
    );
  };
}

describe("useBumpAll dedup strategy", () => {
  it("records one idempotency key per item in targetItems, sharing a batch UUID prefix", async () => {
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });

    const pendingItems = [
      makeItem({ id: "i-1", status: "pending" }),
      makeItem({ id: "i-2", status: "pending" }),
      makeItem({ id: "i-4", status: "pending" }),
    ];

    const { result } = renderHook(() => useBumpAll(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({
        orderId: "o-1",
        scope: "pending",
        targetItems: pendingItems,
      });
    });

    expect(recordBumpKeySpy).toHaveBeenCalledTimes(3);

    const calls = recordBumpKeySpy.mock.calls.map((c) => c[0] as string);
    const batchKeys = new Set(calls.map((k) => k.split(":")[0]));
    const itemIds = new Set(calls.map((k) => k.split(":")[1]));

    expect(batchKeys.size).toBe(1);
    expect(itemIds).toEqual(new Set(["i-1", "i-2", "i-4"]));
  });

  it("skips items blocked by the toppings-parent-ready guard (allowed_transitions without mark-ready)", async () => {
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });

    // Two preparing items: one ready to advance, one still blocked by toppings
    // (server withholds `mark-ready` from allowed_transitions).
    const advanceable = makeItem({
      id: "i-ok",
      status: "preparing",
      allowed_transitions: ["mark-ready", "revert"],
    });
    const blocked = makeItem({
      id: "i-blocked",
      status: "preparing",
      is_blocked_by_toppings: true,
      allowed_transitions: ["revert"],
    });
    const seed = makeOrder({ id: "o-1", items: [advanceable, blocked] });
    qc.setQueryData(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useBumpAll(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({
        orderId: "o-1",
        scope: "preparing",
        targetItems: [advanceable, blocked],
      });
    });

    // Only the advanceable item gets a dedup key — the blocked one is skipped.
    expect(recordBumpKeySpy).toHaveBeenCalledTimes(1);
    expect((recordBumpKeySpy.mock.calls[0][0] as string).endsWith(":i-ok")).toBe(true);

    // Optimistic state: advanceable → ready, blocked stays preparing.
    const after = qc.getQueryData<{ orders: (typeof seed)[] }>(["kds", "orders"]);
    const items = after?.orders[0].items ?? [];
    expect(items.find((i) => i.id === "i-ok")?.status).toBe("ready");
    expect(items.find((i) => i.id === "i-blocked")?.status).toBe("preparing");
  });

  it("records zero keys when targetItems is empty", async () => {
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });

    const { result } = renderHook(() => useBumpAll(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({
        orderId: "o-1",
        scope: "pending",
        targetItems: [],
      });
    });

    expect(recordBumpKeySpy).not.toHaveBeenCalled();
  });

  it("rolls back optimistic update on server error", async () => {
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "pending" })],
    });
    qc.setQueryData(["kds", "orders"], { orders: [seed] });

    global.fetch = vi.fn().mockImplementation(async () => ({
      ok: false,
      status: 409,
      json: async () => ({ code: "KDS_E001", detail: "finalized" }),
    }));

    const { result } = renderHook(() => useBumpAll(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current
        .mutateAsync({
          orderId: "o-1",
          scope: "pending",
          targetItems: seed.items,
        })
        .catch(() => {});
    });

    const after = qc.getQueryData<{ orders: typeof seed[] }>(["kds", "orders"]);
    expect(after?.orders[0].items[0].status).toBe("pending");
  });
});
