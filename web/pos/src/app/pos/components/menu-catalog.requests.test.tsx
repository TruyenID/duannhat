import { render } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const menuHooks = vi.hoisted(() => ({
  detail: vi.fn(),
  products: vi.fn(),
  byDay: vi.fn(),
  sections: vi.fn(),
}));

vi.mock("@/hooks/api/use-shop-menus", () => ({
  useShopMenu: menuHooks.detail,
  useShopMenuProducts: menuHooks.products,
  useShopMenusByDay: menuHooks.byDay,
  useShopMenuSections: menuHooks.sections,
}));

// #3163 — lưới nay chạy MỘT truy vấn mỗi section đã mở, qua `useQueries`.
// Giả lập ở tầng này thay vì dựng QueryClient thật: bài này đo AI GỌI GÌ, không
// đo React Query.
vi.mock("@tanstack/react-query", async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  useQueries: () => ({ itemsByKey: new Map(), sectionLoading: new Set() }),
  keepPreviousData: undefined,
}));

vi.mock("@/hooks/api/use-floating-sections", () => ({
  useFloatingSections: () => ({ data: [] }),
}));

vi.mock("@/providers/app-provider", () => ({
  useTranslation: () => ({ t: (key: string) => key }),
  useLocale: () => ({ locale: "vi" }),
  useOptionalTranslation: () => ({
    t: (key: string) => key,
    locale: "vi",
  }),
}));

import { MenuCatalog } from "./menu-catalog";

describe("MenuCatalog request ownership", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    menuHooks.byDay.mockReturnValue({
      data: { data: [] },
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
    });
    menuHooks.products.mockReturnValue({
      data: { data: [] },
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
    });
    menuHooks.sections.mockReturnValue({
      data: { data: [] },
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
    });
  });

  it("loads the product collection without requesting eager menu detail", () => {
    render(
      <MenuCatalog
        shopSlug="shop-1"
        menuId="menu-1"
        onSelectMenuId={vi.fn()}
        onAddItem={vi.fn()}
      />,
    );

    expect(menuHooks.products).toHaveBeenCalledTimes(1);
    expect(menuHooks.detail).not.toHaveBeenCalled();
  });

  it("changes menu ownership without reintroducing a detail request", () => {
    const props = {
      shopSlug: "shop-1",
      onSelectMenuId: vi.fn(),
      onAddItem: vi.fn(),
    };
    const { rerender } = render(<MenuCatalog {...props} menuId="menu-lunch" />);

    rerender(<MenuCatalog {...props} menuId="menu-dinner" />);

    expect(menuHooks.products).toHaveBeenNthCalledWith(
      1,
      "shop-1",
      "menu-lunch",
      { search: undefined, per_page: 100 },
      { enabled: false },
    );
    expect(menuHooks.products).toHaveBeenLastCalledWith(
      "shop-1",
      "menu-dinner",
      { search: undefined, per_page: 100 },
      { enabled: false },
    );
    expect(menuHooks.detail).not.toHaveBeenCalled();
  });

  /*
   * #3163 — đây là bài đắt nhất của cả file.
   *
   * Lưới nay tải theo section, nhưng đường đi-HẾT-các-trang vẫn còn trong mã
   * cho luồng TÌM KIẾM. Nếu nó không bị tắt khi không tìm kiếm thì nó vẫn chạy
   * nền mỗi 60 giây và **toàn bộ chi phí vừa cắt được quay lại nguyên vẹn** —
   * im lặng, vì màn hình vẫn đúng và không gì đỏ.
   *
   * Không phép đo nào khác trong repo bắt được điều đó: byte đi qua mạng không
   * có rào, và số section trên màn hình vẫn đủ.
   */
  it("#3163 KHÔNG đi cả thực đơn khi không tìm kiếm", () => {
    render(
      <MenuCatalog
        shopSlug="shop-1"
        menuId="menu-1"
        onSelectMenuId={vi.fn()}
        onAddItem={vi.fn()}
      />,
    );

    const [, , , options] = menuHooks.products.mock.calls[0];
    expect(options).toEqual({ enabled: false });
  });

  it("#3163 thanh pill lấy từ đường SECTIONS, không suy từ món đã tải", () => {
    // Đây là vế correctness: danh sách section phải đủ ngay cả khi chưa món nào
    // về. Suy từ món đã tải chính là cách #3159 xảy ra.
    render(
      <MenuCatalog
        shopSlug="shop-1"
        menuId="menu-1"
        onSelectMenuId={vi.fn()}
        onAddItem={vi.fn()}
      />,
    );

    expect(menuHooks.sections).toHaveBeenCalledWith("shop-1", "menu-1");
  });
});
