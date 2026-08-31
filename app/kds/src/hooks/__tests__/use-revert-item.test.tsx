import { renderHook, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { useRevertItem } from "../use-revert-item";
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
});

describe("useRevertItem", () => {
  it("reverts ready → preparing optimistically and records a self-echo key", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeItem({ id: "i-1", status: "preparing" }) }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "ready" })],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useRevertItem(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({
        orderId: "o-1",
        itemId: "i-1",
        to: "preparing",
      });
    });

    expect(recordBumpKeySpy).toHaveBeenCalledTimes(1);
    const after = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
    expect(after?.orders[0].items[0].status).toBe("preparing");
  });

  it("clears ready_at when reverting ready → preparing so it can't skip the ready gate", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeItem({ id: "i-1", status: "preparing", ready_at: null }) }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [
        makeItem({
          id: "i-1",
          status: "ready",
          started_preparing_at: "2026-05-26T10:00:00Z",
          ready_at: "2026-05-26T10:05:00Z",
        }),
      ],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useRevertItem(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", itemId: "i-1", to: "preparing" });
    });

    const item = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"])?.orders[0].items[0];
    expect(item?.status).toBe("preparing");
    // ready_at must be cleared — a stale ready_at would let a reader treat the
    // reverted item as still "ready".
    expect(item?.ready_at).toBeNull();
    // still preparing, so started_preparing_at is preserved.
    expect(item?.started_preparing_at).toBe("2026-05-26T10:00:00Z");
  });

  it("clears started_preparing_at and ready_at when reverting all the way to pending", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeItem({ id: "i-1", status: "pending" }) }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [
        makeItem({
          id: "i-1",
          status: "preparing",
          started_preparing_at: "2026-05-26T10:00:00Z",
        }),
      ],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useRevertItem(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", itemId: "i-1", to: "pending" });
    });

    const item = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"])?.orders[0].items[0];
    expect(item?.status).toBe("pending");
    expect(item?.started_preparing_at).toBeNull();
    expect(item?.ready_at).toBeNull();
  });

  it("reverts preparing → pending optimistically", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeItem({ id: "i-1", status: "pending" }) }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "preparing" })],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useRevertItem(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({
        orderId: "o-1",
        itemId: "i-1",
        to: "pending",
      });
    });

    const after = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
    expect(after?.orders[0].items[0].status).toBe("pending");
  });

  it("rolls back on KDS_E002 invalid transition (server rejects served → ready)", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 409,
      json: async () => ({ code: "KDS_E002", detail: "served is terminal" }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "served" })],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useRevertItem(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current
        .mutateAsync({
          orderId: "o-1",
          itemId: "i-1",
          to: "preparing",
        })
        .catch(() => {});
    });

    const after = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
    expect(after?.orders[0].items[0].status).toBe("served");
  });
});
