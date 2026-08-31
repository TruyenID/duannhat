import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

const voidReasonServiceMock = vi.hoisted(() => ({
  list: vi.fn(),
}));

vi.mock("@/services/void-reason-service", () => ({
  voidReasonService: voidReasonServiceMock,
}));

vi.mock("@/providers/app-provider", () => ({
  useLocale: () => ({ locale: "vi" }),
}));

import { useVoidReasons } from "./use-void-reasons";

const SHOP = "shop-1";

function makeClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
}

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  voidReasonServiceMock.list.mockResolvedValue({
    data: [
      {
        id: "vr-1",
        label: "Bấm nhầm",
        stock_effect: "restock",
        requires_note: false,
        sort_order: 0,
      },
    ],
  });
});

describe("useVoidReasons", () => {
  it("loads the shop's active void reasons", async () => {
    const { result } = renderHook(() => useVoidReasons(SHOP), {
      wrapper: wrapper(makeClient()),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(voidReasonServiceMock.list).toHaveBeenCalledWith(SHOP);
    expect(result.current.data?.data[0].id).toBe("vr-1");
  });

  it("stays disabled (never queries) while shopSlug is empty", () => {
    const { result } = renderHook(() => useVoidReasons(""), {
      wrapper: wrapper(makeClient()),
    });
    expect(voidReasonServiceMock.list).not.toHaveBeenCalled();
    expect(result.current.fetchStatus).toBe("idle");
  });

  it("settles into error state when the endpoint is unreachable (LAN fallback)", async () => {
    voidReasonServiceMock.list.mockRejectedValue(new Error("unreachable"));
    const { result } = renderHook(() => useVoidReasons(SHOP), {
      wrapper: wrapper(makeClient()),
    });
    // The hook opts into a single retry (delay ~1s) before settling, so
    // give waitFor room beyond its 1s default.
    await waitFor(() => expect(result.current.isError).toBe(true), {
      timeout: 5000,
    });
    // Callers treat error/empty identically — the dialog falls back to
    // free text, so no data shape is required here.
    expect(result.current.data).toBeUndefined();
  });
});
