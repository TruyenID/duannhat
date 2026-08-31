import { renderHook, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { useMarkServed } from "../use-mark-served";
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

describe("useMarkServed", () => {
  it("records a self-echo key and optimistically sets status to served", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeItem({ id: "i-1", status: "served" }) }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "ready" })],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useMarkServed(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", itemId: "i-1" });
    });

    expect(recordBumpKeySpy).toHaveBeenCalledTimes(1);
    const after = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
    expect(after?.orders[0].items[0].status).toBe("served");
  });

  it("rolls back optimistic update on KDS_E003 anti-misclick", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 409,
      json: async () => ({ code: "KDS_E003", detail: "too soon after ready" }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "ready" })],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useMarkServed(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current
        .mutateAsync({ orderId: "o-1", itemId: "i-1" })
        .catch(() => {});
    });

    const after = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
    expect(after?.orders[0].items[0].status).toBe("ready");
  });
});
