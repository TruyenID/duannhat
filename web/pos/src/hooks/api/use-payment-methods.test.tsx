import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

const effectivePaymentOptionsServiceMock = vi.hoisted(() => ({
  list: vi.fn(),
}));

vi.mock("@/services/payment-method-service", () => ({
  effectivePaymentOptionsService: effectivePaymentOptionsServiceMock,
  paymentMethodService: effectivePaymentOptionsServiceMock,
}));

// The hook reads the operator's language (the option list is localized
// server-side, so locale is part of the query key). Stubbed rather than
// wrapped in a real AppProvider: these cases are about enablement, not
// language, and a provider here would pull theme + storage into them.
// Locale-switch behaviour is covered in use-payment-locale.test.tsx.
vi.mock("@/providers/app-provider", () => ({
  useLocale: () => ({ locale: "vi" }),
}));

import { useEffectivePaymentOptions } from "./use-payment-methods";

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
  effectivePaymentOptionsServiceMock.list.mockResolvedValue({
    data: { revision: 1, options: [] },
  });
});

describe("useEffectivePaymentOptions", () => {
  it("loads effective payment options for the shop", async () => {
    const { result } = renderHook(() => useEffectivePaymentOptions(SHOP), {
      wrapper: wrapper(makeClient()),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(effectivePaymentOptionsServiceMock.list).toHaveBeenCalledWith(SHOP);
  });

  it("stays disabled (never queries) while shopSlug is empty", () => {
    const { result } = renderHook(() => useEffectivePaymentOptions(""), {
      wrapper: wrapper(makeClient()),
    });
    expect(effectivePaymentOptionsServiceMock.list).not.toHaveBeenCalled();
    expect(result.current.fetchStatus).toBe("idle");
  });

  it("respects an explicit enabled:false guard", () => {
    renderHook(() => useEffectivePaymentOptions(SHOP, { enabled: false }), {
      wrapper: wrapper(makeClient()),
    });
    expect(effectivePaymentOptionsServiceMock.list).not.toHaveBeenCalled();
  });
});
