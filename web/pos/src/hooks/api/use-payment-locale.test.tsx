import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

const tillServiceMock = vi.hoisted(() => ({
  tenderTypes: vi.fn(),
  tenderCategories: vi.fn(),
}));
vi.mock("@/services/till-service", () => ({ tillService: tillServiceMock }));

const paymentOptionsServiceMock = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock("@/services/payment-method-service", () => ({
  effectivePaymentOptionsService: paymentOptionsServiceMock,
}));

// The operator's language is a mutable ref so a test can flip it and rerender,
// simulating the language switch in Settings.
const localeRef = vi.hoisted(() => ({ current: "vi" }));
vi.mock("@/providers/app-provider", () => ({
  useLocale: () => ({ locale: localeRef.current }),
}));

import { useTenderTypes, useTenderCategories, tillKeys } from "./use-till";
import { useEffectivePaymentOptions } from "./use-payment-methods";
import { effectivePaymentOptionKeys } from "./query-keys";

const SHOP = "shop-1";

function wrapper() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  localeRef.current = "vi";
  tillServiceMock.tenderTypes.mockResolvedValue({ data: [] });
  tillServiceMock.tenderCategories.mockResolvedValue({ data: [] });
  paymentOptionsServiceMock.list.mockResolvedValue({ data: { options: [] } });
});

/*
 * The POS payment dialog reads two localized feeds — the method buttons
 * (effective-payment-options) and the tender-brand chips (till/tender-types).
 * Both are localized SERVER-side from Accept-Language, so the response text
 * changes per language while the URL does not. Without the locale in the query
 * key nothing refetches on a switch: the key never moves and staleTime is five
 * minutes, so the cashier keeps reading the previous language.
 */
describe("payment dialog feeds refetch on language switch", () => {
  it("useTenderTypes refetches when the operator changes locale", async () => {
    const { result, rerender } = renderHook(() => useTenderTypes(SHOP), {
      wrapper: wrapper(),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(tillServiceMock.tenderTypes).toHaveBeenCalledTimes(1);

    localeRef.current = "ja";
    rerender();

    await waitFor(() =>
      expect(tillServiceMock.tenderTypes).toHaveBeenCalledTimes(2),
    );
  });

  it("useEffectivePaymentOptions refetches when the operator changes locale", async () => {
    const { result, rerender } = renderHook(
      () => useEffectivePaymentOptions(SHOP),
      { wrapper: wrapper() },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(paymentOptionsServiceMock.list).toHaveBeenCalledTimes(1);

    localeRef.current = "en";
    rerender();

    await waitFor(() =>
      expect(paymentOptionsServiceMock.list).toHaveBeenCalledTimes(2),
    );
  });

  // Category headings are NOT translatable by ruling (one shop-owned name per
  // row — schemas/Backend/Till/TillTenderCategory.yaml). Keying them by locale
  // would fragment the cache and fire a pointless refetch for an identical
  // response, so this asserts the deliberate asymmetry rather than leaving it
  // to be "fixed" later by symmetry.
  it("useTenderCategories does NOT refetch on a locale switch", async () => {
    const { result, rerender } = renderHook(() => useTenderCategories(SHOP), {
      wrapper: wrapper(),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(tillServiceMock.tenderCategories).toHaveBeenCalledTimes(1);

    localeRef.current = "ja";
    rerender();

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(tillServiceMock.tenderCategories).toHaveBeenCalledTimes(1);
  });
});

describe("keys embed locale", () => {
  it("distinct per locale, stable for the same locale", () => {
    expect(tillKeys.tenderTypes(SHOP, "ja")).not.toEqual(
      tillKeys.tenderTypes(SHOP, "vi"),
    );
    expect(tillKeys.tenderTypes(SHOP, "ja")).toEqual(
      tillKeys.tenderTypes(SHOP, "ja"),
    );
    expect(effectivePaymentOptionKeys.list(SHOP, "ja")).not.toEqual(
      effectivePaymentOptionKeys.list(SHOP, "vi"),
    );

    // The offline cache policy keys off the ROOT element, and these two roots
    // are on the never-cache money list. Appending a locale must not shift it.
    expect(tillKeys.tenderTypes(SHOP, "ja")[0]).toBe("till");
    expect(effectivePaymentOptionKeys.list(SHOP, "ja")[0]).toBe(
      "effective-payment-options",
    );
  });
});
