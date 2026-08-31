import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ApiError } from "@/lib/api";
import { orderKeys, tableKeys } from "@/hooks/api/query-keys";
import type { CustomerOrder } from "@/app/pos/types";

// ── Mocks ────────────────────────────────────────────────────────────────────
vi.mock("sonner", () => ({
  toast: Object.assign(
    () => {},
    { success: vi.fn(), error: vi.fn() },
  ),
}));

const orderServiceMock = vi.hoisted(() => ({
  create: vi.fn(),
  delete: vi.fn(),
  addItems: vi.fn(),
  mergeTable: vi.fn(),
  unmergeTable: vi.fn(),
}));

vi.mock("@/services/order-service", () => ({
  orderService: orderServiceMock,
}));

import {
  useAddItems,
  useChangeTable,
  useCreateOrder,
  useDeleteOrder,
} from "./use-orders";

const SHOP = "shop-1";
const ORDER = "order-1";

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

function orderResponse(over: Partial<CustomerOrder> = {}): {
  data: CustomerOrder;
} {
  return { data: { id: ORDER, tables: [], ...over } as unknown as CustomerOrder };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("useCreateOrder — cache seeding + conditional table invalidation", () => {
  it("writes the created order into the detail cache and invalidates order lists", async () => {
    const client = makeClient();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    const created = orderResponse({ id: "new-1" });
    orderServiceMock.create.mockResolvedValue(created);

    const { result } = renderHook(() => useCreateOrder(SHOP), {
      wrapper: wrapper(client),
    });

    await result.current.mutateAsync({ table_ids: ["A2"], guest_count: 4 });

    // Detail cache is seeded from the server response (no follow-up GET).
    expect(client.getQueryData(orderKeys.detail(SHOP, "new-1"))).toEqual(created);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: orderKeys.lists(SHOP) });
    // table_ids were sent → the table map is invalidated.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: tableKeys.all(SHOP) });
  });

  it("does NOT invalidate the table map when no table_ids are sent", async () => {
    const client = makeClient();
    const invalidate = vi.spyOn(client, "invalidateQueries");
    orderServiceMock.create.mockResolvedValue(orderResponse({ id: "new-2" }));

    const { result } = renderHook(() => useCreateOrder(SHOP), {
      wrapper: wrapper(client),
    });

    await result.current.mutateAsync({ guest_count: 4 });

    const tableInvalidated = invalidate.mock.calls.some(
      ([arg]) =>
        JSON.stringify((arg as { queryKey: unknown }).queryKey) ===
        JSON.stringify(tableKeys.all(SHOP)),
    );
    expect(tableInvalidated).toBe(false);
  });

  it("surfaces a 422 table_occupied as an ApiError rejection", async () => {
    const client = makeClient();
    orderServiceMock.create.mockRejectedValue(
      new ApiError(422, {
        message: "table_occupied",
        errors: { table_ids: ["occupied"] },
      }),
    );

    const { result } = renderHook(() => useCreateOrder(SHOP), {
      wrapper: wrapper(client),
    });

    await expect(
      result.current.mutateAsync({ table_ids: ["A2"] }),
    ).rejects.toMatchObject({ status: 422 });
  });
});

describe("useDeleteOrder — removes detail from cache", () => {
  it("drops the order's detail query and invalidates lists + tables", async () => {
    const client = makeClient();
    // Seed a detail entry so we can prove it gets removed.
    client.setQueryData(orderKeys.detail(SHOP, ORDER), orderResponse());
    const invalidate = vi.spyOn(client, "invalidateQueries");
    orderServiceMock.delete.mockResolvedValue(null);

    const { result } = renderHook(() => useDeleteOrder(SHOP), {
      wrapper: wrapper(client),
    });

    await result.current.mutateAsync(ORDER);

    expect(client.getQueryData(orderKeys.detail(SHOP, ORDER))).toBeUndefined();
    expect(invalidate).toHaveBeenCalledWith({ queryKey: orderKeys.lists(SHOP) });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: tableKeys.all(SHOP) });
  });
});

describe("useAddItems — 409 invalid_status error path", () => {
  it("rejects with an ApiError carrying status 409 and does not seed the cache", async () => {
    const client = makeClient();
    const err = new ApiError(409, { message: "invalid_status" });
    orderServiceMock.addItems.mockRejectedValue(err);

    const { result } = renderHook(() => useAddItems(SHOP, ORDER), {
      wrapper: wrapper(client),
    });

    await expect(
      result.current.mutateAsync([{ product_sku_id: "s1", quantity: 1 }]),
    ).rejects.toBe(err);

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(client.getQueryData(orderKeys.detail(SHOP, ORDER))).toBeUndefined();
  });
});

describe("useChangeTable — merge failure aborts before unmerge", () => {
  it("never fires unmerge when the merge step fails (2-step swap guard)", async () => {
    const client = makeClient();
    orderServiceMock.mergeTable.mockRejectedValue(
      new ApiError(422, { message: "table_occupied" }),
    );
    orderServiceMock.unmergeTable.mockResolvedValue(orderResponse());

    const { result } = renderHook(() => useChangeTable(SHOP, ORDER), {
      wrapper: wrapper(client),
    });

    const res = await result.current("A2", "B1");

    expect(res.ok).toBe(false);
    if (!res.ok) {
      expect(res.stage).toBe("merge");
      expect(res.error).toBeInstanceOf(ApiError);
    }
    // Step 2 must be untouched — we never dropped below one table.
    expect(orderServiceMock.unmergeTable).not.toHaveBeenCalled();
    // Server state unchanged → cache left as-is (no detail write happened).
    expect(client.getQueryData(orderKeys.detail(SHOP, ORDER))).toBeUndefined();
  });
});
