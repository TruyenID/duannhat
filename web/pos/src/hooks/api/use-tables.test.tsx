import "fake-indexeddb/auto";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

// ── Mock the service so the hook never touches the network ───────────────────
const { list, changeStatus } = vi.hoisted(() => ({
  list: vi.fn(() => Promise.resolve({ data: [] })),
  changeStatus: vi.fn(),
}));

vi.mock("@/services/table-service", () => ({
  tableService: { list, changeStatus },
}));
vi.mock("sonner", () => ({ toast: { error: vi.fn(), info: vi.fn() } }));

// Import AFTER the mock is registered.
import { useTables, useUpdateTableStatus } from "./use-tables";
import { tableKeys } from "./query-keys";
import { clearLightActions, resetIdbConnection } from "@/lib/idb";
import { countLightActions } from "@/lib/offline-action-queue";

const SHOP = "shop-1";

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

function newClient() {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } });
}

beforeEach(() => {
  list.mockClear();
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe("useTables — #541 active polling on cloud-fallback", () => {
  it("polls at the given refetchInterval so a freed table reconciles", async () => {
    const client = newClient();
    renderHook(() => useTables(SHOP, {}, { refetchInterval: 15_000 }), {
      wrapper: makeWrapper(client),
    });

    // Flush the initial fetch (microtasks).
    await vi.advanceTimersByTimeAsync(0);
    expect(list).toHaveBeenCalledTimes(1);

    // Advance past one interval → a second fetch fires.
    await vi.advanceTimersByTimeAsync(15_000);
    expect(list).toHaveBeenCalledTimes(2);

    // …and keeps polling.
    await vi.advanceTimersByTimeAsync(15_000);
    expect(list).toHaveBeenCalledTimes(3);
  });

  it("does not poll when no refetchInterval is provided (WS reachable)", async () => {
    const client = newClient();
    renderHook(() => useTables(SHOP), { wrapper: makeWrapper(client) });

    await vi.advanceTimersByTimeAsync(0);
    expect(list).toHaveBeenCalledTimes(1);

    // Advancing well past any interval must not trigger extra fetches.
    await vi.advanceTimersByTimeAsync(120_000);
    expect(list).toHaveBeenCalledTimes(1);
  });

  it("stays disabled without a shopSlug", async () => {
    const client = newClient();
    renderHook(() => useTables("", {}, { refetchInterval: 15_000 }), {
      wrapper: makeWrapper(client),
    });

    await vi.advanceTimersByTimeAsync(30_000);
    expect(list).not.toHaveBeenCalled();
  });
});

describe("useUpdateTableStatus", () => {
  const LIST_KEY = tableKeys.list(SHOP, { per_page: 100 });

  it("posts the status change for the table", async () => {
    vi.useRealTimers(); // this suite awaits real promises, not fake timers
    changeStatus.mockResolvedValue({ data: { id: "t1", status: "cleaning" } });
    const { result } = renderHook(() => useUpdateTableStatus(SHOP), {
      wrapper: makeWrapper(newClient()),
    });

    result.current.mutate({ tableId: "t1", status: "cleaning" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(changeStatus).toHaveBeenCalledWith(SHOP, "t1", "cleaning");
  });

  it("optimistically patches the cached list before the request resolves", async () => {
    vi.useRealTimers();
    const client = newClient();
    client.setQueryData(LIST_KEY, {
      data: [{ id: "t1", status: "free" }],
      meta: {},
    });
    let resolve!: (v: unknown) => void;
    changeStatus.mockReturnValue(
      new Promise((r) => {
        resolve = r;
      }),
    );

    const { result } = renderHook(() => useUpdateTableStatus(SHOP), {
      wrapper: makeWrapper(client),
    });
    result.current.mutate({ tableId: "t1", status: "cleaning" });

    await waitFor(() => {
      const cached = client.getQueryData<{ data: { status: string }[] }>(
        LIST_KEY,
      );
      expect(cached?.data[0].status).toBe("cleaning");
    });
    resolve({ data: { id: "t1", status: "cleaning" } });
  });

  it("rolls the cache back when the request fails", async () => {
    vi.useRealTimers();
    const client = newClient();
    client.setQueryData(LIST_KEY, {
      data: [{ id: "t1", status: "free" }],
      meta: {},
    });
    changeStatus.mockRejectedValue(new Error("boom"));

    const { result } = renderHook(() => useUpdateTableStatus(SHOP), {
      wrapper: makeWrapper(client),
    });
    result.current.mutate({ tableId: "t1", status: "cleaning" });

    await waitFor(() => expect(result.current.isError).toBe(true));
    const cached = client.getQueryData<{ data: { status: string }[] }>(
      LIST_KEY,
    );
    expect(cached?.data[0].status).toBe("free");
  });
});

/*
 * #1501 — CHƯA GỬI ĐƯỢC khác với BỊ TỪ CHỐI.
 *
 * Đổi trạng thái bàn là hành động nhẹ, không dính tiền. Mất mạng thì giữ
 * nguyên trạng thái lạc quan và xếp hàng; một câu trả lời dứt khoát của máy
 * chủ thì hoàn nguyên như cũ.
 */
describe("useUpdateTableStatus — hàng đợi offline (#1501)", () => {
  const LIST_KEY = tableKeys.list(SHOP, { per_page: 100 });

  beforeEach(async () => {
    // fake-indexeddb chạy trên macrotask, nên phải thoát timer giả của
    // beforeEach cấp file trước khi await bất cứ thứ gì chạm IndexedDB.
    vi.useRealTimers();
    resetIdbConnection();
    await clearLightActions();
  });

  it("lỗi MẠNG ⇒ xếp hàng và GIỮ trạng thái lạc quan", async () => {
    vi.useRealTimers();
    const client = newClient();
    client.setQueryData(LIST_KEY, {
      data: [{ id: "t1", status: "free" }],
      meta: {},
    });
    changeStatus.mockRejectedValue(new TypeError("Failed to fetch"));

    const { result } = renderHook(() => useUpdateTableStatus(SHOP), {
      wrapper: makeWrapper(client),
    });
    result.current.mutate({ tableId: "t1", status: "cleaning" });

    await waitFor(() => expect(result.current.isError).toBe(true));
    await waitFor(async () => expect(await countLightActions()).toBe(1));

    const cached = client.getQueryData<{ data: { status: string }[] }>(LIST_KEY);
    expect(cached?.data[0].status).toBe("cleaning");
  });

  it("máy chủ TỪ CHỐI ⇒ hoàn nguyên, KHÔNG xếp hàng", async () => {
    vi.useRealTimers();
    const client = newClient();
    client.setQueryData(LIST_KEY, {
      data: [{ id: "t1", status: "free" }],
      meta: {},
    });
    changeStatus.mockRejectedValue(new Error("422"));

    const { result } = renderHook(() => useUpdateTableStatus(SHOP), {
      wrapper: makeWrapper(client),
    });
    result.current.mutate({ tableId: "t1", status: "cleaning" });

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(await countLightActions()).toBe(0);

    const cached = client.getQueryData<{ data: { status: string }[] }>(LIST_KEY);
    expect(cached?.data[0].status).toBe("free");
  });
});
