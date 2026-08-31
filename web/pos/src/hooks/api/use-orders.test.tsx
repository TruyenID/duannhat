import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { orderKeys } from "@/hooks/api/query-keys";
import type { CustomerOrder } from "@/app/pos/types";

// ── Mocks ────────────────────────────────────────────────────────────────────
vi.mock("sonner", () => ({
  toast: Object.assign(
    () => {},
    { success: vi.fn(), error: vi.fn() },
  ),
}));

const orderServiceMock = vi.hoisted(() => ({
  list: vi.fn(),
  addItems: vi.fn(),
  updateItem: vi.fn(),
  mergeTable: vi.fn(),
  unmergeTable: vi.fn(),
  confirm: vi.fn(),
}));

vi.mock("@/services/order-service", () => ({
  orderService: orderServiceMock,
}));

// Import AFTER the mocks are registered.
import {
  useAddItems,
  useChangeTable,
  useConfirmOrder,
  useOpenOrders,
  useTakeawayOrders,
  useUpdateItem,
} from "./use-orders";

const SHOP = "shop-1";
const ORDER = "order-1";

function makeOrder(tableIds: string[]): { data: CustomerOrder } {
  return {
    data: {
      id: ORDER,
      tables: tableIds.map((id) => ({ id })),
    } as unknown as CustomerOrder,
  };
}

/** A promise whose resolution the test controls. */
function deferred<T>() {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

function makeClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
}

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
});

// ── #489 — takeaway orders (status=pending) must stay visible ────────────────
describe("useOpenOrders (#489)", () => {
  it("requests the `pending` status so freshly-created takeaway orders appear", () => {
    orderServiceMock.list.mockResolvedValue({ data: [] });
    const client = makeClient();

    renderHook(() => useOpenOrders(SHOP), { wrapper: wrapper(client) });

    expect(orderServiceMock.list).toHaveBeenCalledWith(
      SHOP,
      expect.objectContaining({
        status: expect.stringContaining("pending"),
      }),
    );
  });
});

// ── #1792 — the order feeds are WS-driven, so they must poll when (and only
//    when) the caller says there is no live channel. Before this they carried
//    no refetchInterval at all, so a POS whose socket was down while the
//    workstation stayed reachable over HTTP sat frozen. ───────────────────────
describe("useOpenOrders / useTakeawayOrders — polling fallback (#1792)", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("polls at the given interval when there is no live channel", async () => {
    orderServiceMock.list.mockResolvedValue({ data: [] });
    const client = makeClient();

    renderHook(() => useOpenOrders(SHOP, { refetchInterval: 15_000 }), {
      wrapper: wrapper(client),
    });

    await vi.advanceTimersByTimeAsync(0);
    expect(orderServiceMock.list).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(15_000);
    expect(orderServiceMock.list).toHaveBeenCalledTimes(2);

    await vi.advanceTimersByTimeAsync(15_000);
    expect(orderServiceMock.list).toHaveBeenCalledTimes(3);
  });

  it("does not poll when the socket is live (no interval passed)", async () => {
    orderServiceMock.list.mockResolvedValue({ data: [] });
    const client = makeClient();

    renderHook(() => useOpenOrders(SHOP), { wrapper: wrapper(client) });

    await vi.advanceTimersByTimeAsync(0);
    expect(orderServiceMock.list).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(120_000);
    expect(orderServiceMock.list).toHaveBeenCalledTimes(1);
  });

  it("takeaway feed takes the same gate", async () => {
    orderServiceMock.list.mockResolvedValue({ data: [] });
    const client = makeClient();

    renderHook(() => useTakeawayOrders(SHOP, { refetchInterval: 15_000 }), {
      wrapper: wrapper(client),
    });

    await vi.advanceTimersByTimeAsync(0);
    expect(orderServiceMock.list).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(15_000);
    expect(orderServiceMock.list).toHaveBeenCalledTimes(2);
  });
});

// ── #563 — cart item mutations must serialise so a stale full-order response
//    cannot clobber a newer one ────────────────────────────────────────────
describe("cart item mutation ordering (#563)", () => {
  it("does not start a second item mutation until the first has settled", async () => {
    const client = makeClient();
    const w = wrapper(client);

    const update = renderHook(() => useUpdateItem(SHOP, ORDER), { wrapper: w });
    const add = renderHook(() => useAddItems(SHOP, ORDER), { wrapper: w });

    const first = deferred<{ data: CustomerOrder }>();
    orderServiceMock.updateItem.mockReturnValue(first.promise);
    orderServiceMock.addItems.mockResolvedValue(makeOrder(["t1"]));

    // Fire update-qty, then immediately fire add-item (the race in the report).
    const updatePromise = update.result.current.mutateAsync({
      itemId: "i1",
      body: { quantity: 2 },
    });
    const addPromise = add.result.current.mutateAsync([{ sku_id: "s1" } as never]);

    // The scope keeps them serial: addItems must NOT have been sent while the
    // update-qty request is still in flight.
    await Promise.resolve();
    expect(orderServiceMock.addItems).not.toHaveBeenCalled();

    // Resolve the first request → the second is now allowed to run.
    first.resolve(makeOrder([]));
    await updatePromise;
    await addPromise;

    expect(orderServiceMock.addItems).toHaveBeenCalledTimes(1);
    // Final cache reflects the LAST submitted mutation, not a late first one.
    const detail = client.getQueryData<{ data: CustomerOrder }>(
      orderKeys.detail(SHOP, ORDER),
    );
    expect(detail?.data.tables).toHaveLength(1);
  });
});

// ── #540 — change-table must not flash the intermediate 2-table state ────────
describe("useChangeTable (#540)", () => {
  it("never writes the intermediate 2-table order to the detail cache", async () => {
    const client = makeClient();
    const setSpy = vi.spyOn(client, "setQueryData");
    const invalidateSpy = vi.spyOn(client, "invalidateQueries");

    orderServiceMock.mergeTable.mockResolvedValue(makeOrder(["from", "to"]));
    orderServiceMock.unmergeTable.mockResolvedValue(makeOrder(["to"]));

    const { result } = renderHook(() => useChangeTable(SHOP, ORDER), {
      wrapper: wrapper(client),
    });

    const res = await result.current("from", "to");
    expect(res.ok).toBe(true);

    // Collect every write to the order-detail cache key.
    const detailKey = JSON.stringify(orderKeys.detail(SHOP, ORDER));
    const detailWrites = setSpy.mock.calls
      .filter(([key]) => JSON.stringify(key) === detailKey)
      .map(([, value]) => value as { data: CustomerOrder });

    // Exactly one detail write, and it is the FINAL single-table state — the
    // 2-table intermediate snapshot must never reach the shared cache.
    expect(detailWrites).toHaveLength(1);
    expect(detailWrites[0].data.tables).toHaveLength(1);
    expect(
      detailWrites.some((w) => (w.data.tables ?? []).length === 2),
    ).toBe(false);

    // Open-orders list is invalidated (parity with the other order mutations).
    expect(invalidateSpy).toHaveBeenCalledWith({
      queryKey: orderKeys.lists(SHOP),
    });
  });

  it("writes the real 2-table state when unmerge fails (retry UI)", async () => {
    const client = makeClient();
    const setSpy = vi.spyOn(client, "setQueryData");

    orderServiceMock.mergeTable.mockResolvedValue(makeOrder(["from", "to"]));
    orderServiceMock.unmergeTable.mockRejectedValue(new Error("boom"));

    const { result } = renderHook(() => useChangeTable(SHOP, ORDER), {
      wrapper: wrapper(client),
    });

    const res = await result.current("from", "to");
    expect(res.ok).toBe(false);
    if (!res.ok) expect(res.stage).toBe("unmerge");

    const detailKey = JSON.stringify(orderKeys.detail(SHOP, ORDER));
    const detailWrites = setSpy.mock.calls
      .filter(([key]) => JSON.stringify(key) === detailKey)
      .map(([, value]) => value as { data: CustomerOrder });

    // The merged 2-table order is the true server state after a failed unmerge.
    expect(detailWrites).toHaveLength(1);
    expect(detailWrites[0].data.tables).toHaveLength(2);
  });
});

// ── Tiếp nhận đơn — accept a customer-submitted takeaway ─────────────────────
describe("useConfirmOrder", () => {
  it("cache-sets the returned open order and invalidates the list on success", async () => {
    const client = makeClient();
    const setSpy = vi.spyOn(client, "setQueryData");
    const invalidateSpy = vi.spyOn(client, "invalidateQueries");

    const accepted = {
      data: { id: ORDER, status: "open" } as unknown as CustomerOrder,
    };
    orderServiceMock.confirm.mockResolvedValue(accepted);

    const { result } = renderHook(() => useConfirmOrder(SHOP, ORDER), {
      wrapper: wrapper(client),
    });

    await result.current.mutateAsync();

    expect(orderServiceMock.confirm).toHaveBeenCalledWith(SHOP, ORDER);
    expect(setSpy).toHaveBeenCalledWith(
      orderKeys.detail(SHOP, ORDER),
      accepted,
    );
    expect(invalidateSpy).toHaveBeenCalledWith({
      queryKey: orderKeys.lists(SHOP),
    });
  });

  it("re-syncs the cached order on error (another terminal may have accepted)", async () => {
    const client = makeClient();
    const invalidateSpy = vi.spyOn(client, "invalidateQueries");

    orderServiceMock.confirm.mockRejectedValue(new Error("409 conflict"));

    const { result } = renderHook(() => useConfirmOrder(SHOP, ORDER), {
      wrapper: wrapper(client),
    });

    await expect(result.current.mutateAsync()).rejects.toThrow("409 conflict");

    expect(invalidateSpy).toHaveBeenCalledWith({
      queryKey: orderKeys.detail(SHOP, ORDER),
    });
    expect(invalidateSpy).toHaveBeenCalledWith({
      queryKey: orderKeys.lists(SHOP),
    });
  });
});
