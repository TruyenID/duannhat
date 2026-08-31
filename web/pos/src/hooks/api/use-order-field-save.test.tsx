import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ApiError } from "@/lib/api";
import type { CustomerOrder } from "@/app/pos/types";

// ── Mocks ────────────────────────────────────────────────────────────────────
vi.mock("sonner", () => ({
  toast: Object.assign(
    () => {},
    { success: vi.fn(), error: vi.fn() },
  ),
}));

const orderServiceMock = vi.hoisted(() => ({
  init: vi.fn(),
  update: vi.fn(),
}));

vi.mock("@/services/order-service", () => ({
  orderService: orderServiceMock,
}));

import { useOrderFieldSave } from "./use-orders";

const SHOP = "shop-1";

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

function order(over: Partial<CustomerOrder>): CustomerOrder {
  return {
    id: "ord-1",
    tables: [],
    guest_count: null,
    ...over,
  } as unknown as CustomerOrder;
}

function renderSave(o: CustomerOrder | null) {
  const client = makeClient();
  const { result } = renderHook(() => useOrderFieldSave(SHOP, o), {
    wrapper: wrapper(client),
  });
  return result;
}

beforeEach(() => {
  vi.clearAllMocks();
  orderServiceMock.init.mockResolvedValue({ data: order({}) });
  orderServiceMock.update.mockResolvedValue({ data: order({}) });
});

describe("useOrderFieldSave — endpoint selection (plan-007 first/last-write-wins)", () => {
  it("returns null while the order is not yet loaded", () => {
    expect(renderSave(null).current).toBeNull();
  });

  it("blank tables + save({table_ids}) → PUT /init (first-write-wins)", async () => {
    const save = renderSave(order({ tables: [], guest_count: null }));
    await save.current!({ table_ids: ["A2"] });

    expect(orderServiceMock.init).toHaveBeenCalledWith(SHOP, "ord-1", {
      table_ids: ["A2"],
    });
    expect(orderServiceMock.update).not.toHaveBeenCalled();
  });

  it("null guest_count + save({guest_count}) → PUT /init", async () => {
    const save = renderSave(order({ tables: [], guest_count: null }));
    await save.current!({ guest_count: 4 });

    expect(orderServiceMock.init).toHaveBeenCalledWith(SHOP, "ord-1", {
      guest_count: 4,
    });
    expect(orderServiceMock.update).not.toHaveBeenCalled();
  });

  it("tables set but guest still null, save({guest_count}) → PUT /init", async () => {
    const save = renderSave(
      order({ tables: [{ id: "A2" }] as never, guest_count: null }),
    );
    await save.current!({ guest_count: 4 });

    // guest_count is still null → first-write-wins path.
    expect(orderServiceMock.init).toHaveBeenCalledWith(SHOP, "ord-1", {
      guest_count: 4,
    });
    expect(orderServiceMock.update).not.toHaveBeenCalled();
  });

  it("guest already set, save({guest_count}) → PUT /{id} (last-write-wins)", async () => {
    const save = renderSave(
      order({ tables: [{ id: "A2" }] as never, guest_count: 4 }),
    );
    await save.current!({ guest_count: 6 });

    expect(orderServiceMock.update).toHaveBeenCalledWith(SHOP, "ord-1", {
      guest_count: 6,
    });
    expect(orderServiceMock.init).not.toHaveBeenCalled();
  });

  it("note is always last-write-wins → PUT /{id}", async () => {
    const save = renderSave(
      order({ tables: [{ id: "A2" }] as never, guest_count: 4 }),
    );
    await save.current!({ note: "...allergy..." });

    expect(orderServiceMock.update).toHaveBeenCalledWith(SHOP, "ord-1", {
      note: "...allergy...",
    });
    expect(orderServiceMock.init).not.toHaveBeenCalled();
  });

  it("propagates a 409 invalid_status rejection from the init endpoint", async () => {
    orderServiceMock.init.mockRejectedValueOnce(
      new ApiError(409, { message: "invalid_status" }),
    );
    const save = renderSave(order({ tables: [], guest_count: null }));

    await expect(save.current!({ table_ids: ["A2"] })).rejects.toBeInstanceOf(
      ApiError,
    );
  });
});
