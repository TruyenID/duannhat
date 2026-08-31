import { renderHook, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { toast } from "sonner";
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

describe("useMarkPreparing", () => {
  it("records a self-echo key and optimistically sets status to preparing", async () => {
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
      items: [makeItem({ id: "i-1", status: "pending" })],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useMarkPreparing(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", itemId: "i-1" });
    });

    expect(recordBumpKeySpy).toHaveBeenCalledTimes(1);
    expect(typeof recordBumpKeySpy.mock.calls[0][0]).toBe("string");
    const after = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
    expect(after?.orders[0].items[0].status).toBe("preparing");
  });

  it("rolls back optimistic update on server error", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 409,
      json: async () => ({ code: "KDS_E002", detail: "invalid transition" }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "pending" })],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useMarkPreparing(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current
        .mutateAsync({ orderId: "o-1", itemId: "i-1" })
        .catch(() => {});
    });

    const after = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
    expect(after?.orders[0].items[0].status).toBe("pending");
  });

  // Integration with error-toast: mutation onError → useErrorToast → sonner.
  // Pure mapper coverage is in src/lib/__tests__/error-toast.test.ts; this
  // locks the WIRING (correct toast variant called for each code class).
  it("fires sonner toast.error with the i18n title on RFC 7807 error", async () => {
    const errorSpy = vi.spyOn(toast, "error").mockImplementation(() => "id");
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 409,
      json: async () => ({ code: "KDS_E001", detail: "Order finalized." }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], {
      orders: [makeOrder({ id: "o-1", items: [makeItem({ id: "i-1", status: "pending" })] })],
    });

    const { result } = renderHook(() => useMarkPreparing(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current
        .mutateAsync({ orderId: "o-1", itemId: "i-1" })
        .catch(() => {});
    });

    expect(errorSpy).toHaveBeenCalledTimes(1);
    const [title, opts] = errorSpy.mock.calls[0];
    expect(title).toBe("注文は終了済みです"); // ja default + KDS_E001 → order_finalized
    expect((opts as { description?: string })?.description).toBe("Order finalized.");
    errorSpy.mockRestore();
  });

  it("does not fire any toast for silent code KDS_E005 (anti-double-tap)", async () => {
    const errorSpy = vi.spyOn(toast, "error").mockImplementation(() => "id");
    const warningSpy = vi.spyOn(toast, "warning").mockImplementation(() => "id");
    const infoSpy = vi.spyOn(toast, "info").mockImplementation(() => "id");
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 429,
      json: async () => ({ code: "KDS_E005", detail: "throttled" }),
    });
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], {
      orders: [makeOrder({ id: "o-1", items: [makeItem({ id: "i-1", status: "pending" })] })],
    });

    const { result } = renderHook(() => useMarkPreparing(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current
        .mutateAsync({ orderId: "o-1", itemId: "i-1" })
        .catch(() => {});
    });

    expect(errorSpy).not.toHaveBeenCalled();
    expect(warningSpy).not.toHaveBeenCalled();
    expect(infoSpy).not.toHaveBeenCalled();
    errorSpy.mockRestore();
    warningSpy.mockRestore();
    infoSpy.mockRestore();
  });
});
