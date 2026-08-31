import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

const shopMenuServiceMock = vi.hoisted(() => ({
  list: vi.fn(),
  getById: vi.fn(),
  listProducts: vi.fn(),
  listAllProducts: vi.fn(),
  listByDay: vi.fn(),
}));

vi.mock("@/services/shop-menu-service", () => ({
  shopMenuService: shopMenuServiceMock,
}));

// The operator's language is a mutable ref so a test can flip it and rerender,
// simulating the header language switch.
const localeRef = vi.hoisted(() => ({ current: "vi" }));
vi.mock("@/providers/app-provider", () => ({
  useLocale: () => ({ locale: localeRef.current }),
}));

import { useShopMenu, useShopMenuProducts } from "./use-shop-menus";
import { shopMenuKeys } from "./query-keys";

const SHOP = "shop-1";
const MENU = "menu-1";

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
  localeRef.current = "vi";
  shopMenuServiceMock.listProducts.mockResolvedValue({ data: [] });
  shopMenuServiceMock.listAllProducts.mockResolvedValue({ data: [] });
  shopMenuServiceMock.getById.mockResolvedValue({ data: {} });
});

describe("menu hooks refetch on language switch", () => {
  it("useShopMenuProducts refetches when the operator changes locale", async () => {
    const { result, rerender } = renderHook(
      () => useShopMenuProducts(SHOP, MENU),
      { wrapper: wrapper(makeClient()) },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(shopMenuServiceMock.listAllProducts).toHaveBeenCalledTimes(1);

    // Operator switches vi -> ja in the header; useLocale now returns "ja".
    localeRef.current = "ja";
    rerender();

    // The locale is part of the query key, so React Query fires a fresh fetch
    // (which carries the new Accept-Language) instead of serving the vi cache.
    await waitFor(() =>
      expect(shopMenuServiceMock.listAllProducts).toHaveBeenCalledTimes(2),
    );
  });

  it("đi qua listAllProducts — MỘT trang không phải là cả thực đơn", async () => {
    // Màn POS gom sản phẩm thành section rồi dựng thanh section từ chính kết
    // quả đó, nên một trang thiếu đọc lên như thực đơn đủ bị hụt section chứ
    // không phải như "còn nữa". Quay về `listProducts` là tái lập đúng lỗi đó.
    const { result } = renderHook(() => useShopMenuProducts(SHOP, MENU), {
      wrapper: wrapper(makeClient()),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(shopMenuServiceMock.listAllProducts).toHaveBeenCalled();
    expect(shopMenuServiceMock.listProducts).not.toHaveBeenCalled();
  });

  it("useShopMenu refetches the detail when locale changes", async () => {
    const { result, rerender } = renderHook(() => useShopMenu(SHOP, MENU), {
      wrapper: wrapper(makeClient()),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(shopMenuServiceMock.getById).toHaveBeenCalledTimes(1);

    localeRef.current = "en";
    rerender();

    await waitFor(() =>
      expect(shopMenuServiceMock.getById).toHaveBeenCalledTimes(2),
    );
  });
});

describe("shopMenuKeys embed locale", () => {
  it("produces distinct keys per locale and stable keys for the same locale", () => {
    expect(shopMenuKeys.products(SHOP, MENU, "ja")).not.toEqual(
      shopMenuKeys.products(SHOP, MENU, "vi"),
    );
    expect(shopMenuKeys.detail(SHOP, MENU, "ja")).not.toEqual(
      shopMenuKeys.detail(SHOP, MENU, "vi"),
    );
    expect(shopMenuKeys.list(SHOP, "ja")).not.toEqual(
      shopMenuKeys.list(SHOP, "vi"),
    );
    expect(shopMenuKeys.byDay(SHOP, 1, "ja")).not.toEqual(
      shopMenuKeys.byDay(SHOP, 1, "vi"),
    );

    // Same inputs must yield an equal key so React Query keeps cache identity.
    expect(shopMenuKeys.products(SHOP, MENU, "ja")).toEqual(
      shopMenuKeys.products(SHOP, MENU, "ja"),
    );
  });
});
