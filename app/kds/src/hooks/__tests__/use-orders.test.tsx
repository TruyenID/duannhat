import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { useOrders } from "../use-orders";
import { setDeviceToken } from "@/services/auth/device-token";
import { AuthProvider } from "@/providers/AuthProvider";
import { makeOrder, makeItem } from "@/test/fixtures/kds";

vi.mock("@/providers/use-realtime", () => ({
  useRealtime: () => ({ recordBumpKey: vi.fn(), isConnected: false }),
}));

beforeEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
});

function wrap(qc: QueryClient) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <AuthProvider>
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      </AuthProvider>
    );
  };
}

describe("useOrders", () => {
  it("returns orders list when paired and /orders succeeds", async () => {
    setDeviceToken("tok", {
      id: "d-1",
      name: "KDS-1",
      type: "kds",
      branch_id: "b-1",
      branch_name: "Branch A",
    });
    global.fetch = vi.fn().mockImplementation(async (url: string) => {
      if (url.includes("/kds/me")) {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: {
              id: "d-1",
              name: "KDS-1",
              type: "kds",
              status: "active",
              branch_id: "b-1",
            },
          }),
        };
      }
      if (url.includes("/kds/orders")) {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: [
              makeOrder({
                id: "o-1",
                order_code: "042",
                opened_at: "2026-05-26T14:00:00Z",
                items: [
                  makeItem({
                    id: "i-1",
                    menu_item_name: "Phở Bò",
                    quantity: 2,
                    status: "pending",
                  }),
                ],
              }),
            ],
          }),
        };
      }
      throw new Error("Unexpected URL: " + url);
    });

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useOrders(), { wrapper: wrap(qc) });

    await waitFor(() => {
      expect(result.current.data?.orders.length).toBe(1);
      expect(result.current.data?.orders[0].items[0].menu_item_name).toBe("Phở Bò");
    });
  });

  it("is disabled when unpaired", async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useOrders(), { wrapper: wrap(qc) });

    await waitFor(() => {
      expect(result.current.fetchStatus).toBe("idle");
    });
  });
});
