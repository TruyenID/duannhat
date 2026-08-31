import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";

const revenueServiceMock = vi.hoisted(() => ({
  summary: vi.fn(),
  byProduct: vi.fn(),
  voids: vi.fn(),
  voidEvents: vi.fn(),
}));

vi.mock("@/services/revenue-service", () => ({
  revenueService: revenueServiceMock,
}));

import { useRevenueVoidEvents, useRevenueVoids } from "./use-revenue";

const SHOP = "shop-1";

function makeClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
}

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <AppProvider>
        <QueryClientProvider client={client}>{children}</QueryClientProvider>
      </AppProvider>
    );
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  revenueServiceMock.voids.mockResolvedValue({
    data: {
      granularity: "day",
      from: "2026-05-01",
      to: "2026-05-01",
      kpis: {
        order_voids: 1,
        order_void_value: 3000,
        item_voids: 1,
        item_void_value: 1200,
        order_void_rate_pct: 50,
      },
      series: [],
      order_reasons: [],
      item_reasons: [],
      top_items: [],
      generated_at: "2026-05-02T00:00:00Z",
    },
  });
  revenueServiceMock.voidEvents.mockResolvedValue({
    data: {
      from: "2026-05-01",
      to: "2026-05-01",
      type: "all",
      total: 1,
      page: 1,
      per_page: 20,
      rows: [
        {
          kind: "item",
          order_id: "o1",
          order_code: "ORD-2026-0002",
          voided_at: "2026-05-01T14:00:00Z",
          reason: "wrong_item",
          item_name: "Spring Rolls",
          variant: "2pc",
          quantity: 2,
          item_count: 1,
          value: 1200,
        },
      ],
      generated_at: "2026-05-02T00:00:00Z",
    },
  });
});

describe("useRevenueVoids", () => {
  it("forwards the window filters to the service", async () => {
    const filters = { granularity: "day" as const, from: "2026-05-01", to: "2026-05-31" };
    const { result } = renderHook(() => useRevenueVoids(SHOP, filters), {
      wrapper: wrapper(makeClient()),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(revenueServiceMock.voids).toHaveBeenCalledWith(SHOP, filters);
  });

  it("unwraps the { data } envelope via select", async () => {
    const { result } = renderHook(() => useRevenueVoids(SHOP), {
      wrapper: wrapper(makeClient()),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    // `select` peels the envelope so consumers read `.kpis` directly.
    expect(result.current.data?.kpis.order_voids).toBe(1);
    expect(result.current.data?.kpis.item_void_value).toBe(1200);
  });

  it("stays disabled (never queries) while shopSlug is empty", () => {
    const { result } = renderHook(() => useRevenueVoids(""), {
      wrapper: wrapper(makeClient()),
    });
    expect(revenueServiceMock.voids).not.toHaveBeenCalled();
    expect(result.current.fetchStatus).toBe("idle");
  });
});

describe("useRevenueVoidEvents", () => {
  it("forwards window + type + pagination to the service", async () => {
    const filters = {
      granularity: "day" as const,
      from: "2026-05-01",
      to: "2026-05-31",
      type: "item" as const,
      page: 2,
      per_page: 25,
    };
    const { result } = renderHook(() => useRevenueVoidEvents(SHOP, filters), {
      wrapper: wrapper(makeClient()),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(revenueServiceMock.voidEvents).toHaveBeenCalledWith(SHOP, filters);
  });

  it("unwraps the { data } envelope via select", async () => {
    const { result } = renderHook(() => useRevenueVoidEvents(SHOP), {
      wrapper: wrapper(makeClient()),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.total).toBe(1);
    expect(result.current.data?.rows[0]?.order_code).toBe("ORD-2026-0002");
  });

  it("stays disabled (never queries) while shopSlug is empty", () => {
    const { result } = renderHook(() => useRevenueVoidEvents(""), {
      wrapper: wrapper(makeClient()),
    });
    expect(revenueServiceMock.voidEvents).not.toHaveBeenCalled();
    expect(result.current.fetchStatus).toBe("idle");
  });
});
