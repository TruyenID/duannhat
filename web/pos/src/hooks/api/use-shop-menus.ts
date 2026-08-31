/**
 * Shop Menu Hooks — read-only React Query wrappers for the cashier POS.
 *
 * Only the queries are exposed (list / detail / product search). Toggle,
 * price-override, and master-sync mutations from tempo-frontend are NOT
 * ported — cashier role can only read the menu and add items to a cart.
 */

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { shopMenuService } from "@/services/shop-menu-service";
import { useLocale } from "@/providers/app-provider";
import type {
  ShopMenuByDayFilters,
  ShopMenuFilters,
  ShopMenuProductFilters,
} from "@/app/pos/types";
import { shopMenuKeys } from "./query-keys";

/**
 * GET /api/v1/pos/menus
 *
 * Default shows EVERY branch menu regardless of status so POS staff can
 * see all menus the shop has (Draft, Active, Inactive, …). Passing an
 * explicit status filter narrows the list.
 */
export function useShopMenus(
  shopSlug: string,
  filters: ShopMenuFilters = {},
) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: shopMenuKeys.list(shopSlug, locale, filters),
    queryFn: () => shopMenuService.list(shopSlug, filters),
    enabled: !!shopSlug,
    // Keep the previous language's menu on screen while the new-language
    // refetch (triggered by the locale key change) is in flight, so names
    // swap in place instead of flashing an empty menu.
    placeholderData: keepPreviousData,
  });
}

/** GET /api/v1/pos/menus/{menu} */
export function useShopMenu(shopSlug: string, menuId: string | null) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: shopMenuKeys.detail(shopSlug, menuId ?? "", locale),
    queryFn: () => shopMenuService.getById(shopSlug, menuId as string),
    enabled: !!shopSlug && !!menuId,
    placeholderData: keepPreviousData,
  });
}

/**
 * GET /api/v1/pos/menus/{menu}/products — EVERY page, merged.
 *
 * `per_page` here is the size of each request in that walk, NOT a cap on what
 * the cashier sees: the catalog groups the result into sections and draws its
 * pill row from the grouping, so a partial answer reads as a complete menu
 * with sections missing. See `shopMenuService.listAllProducts`.
 */
/**
 * GET /api/v1/pos/menus/{menu}/sections — #3163
 *
 * Thanh pill của lưới POS. Rẻ và LUÔN ĐỦ: chi phí không phụ thuộc số món, nên
 * nó đúng dù menu to cỡ nào.
 *
 * Trước bản này thanh pill được suy ra từ CẢ thực đơn đã tải, nên nó chỉ đúng
 * khi lưới đã kéo về 100% số dòng — menu 89 dòng là 638 KB một vòng, và query
 * có `refetchInterval` 60 giây.
 *
 * KHÔNG có `refetchInterval` ở đây, có chủ đích: danh sách section gần như đứng
 * yên trong một ca, còn thứ đổi theo phút (giá, Happy Hour) nằm ở đường
 * `products`. Đặt nhịp làm mới lên cả hai là trả lại đúng chi phí vừa cắt được.
 */
export function useShopMenuSections(shopSlug: string, menuId: string | null) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: shopMenuKeys.sections(shopSlug, menuId ?? "", locale),
    queryFn: () => shopMenuService.listSections(shopSlug, menuId as string),
    enabled: !!shopSlug && !!menuId,
    // Tên section được backend bản địa hoá theo `Accept-Language`, nên đổi ngôn
    // ngữ phải đổi nhãn pill — khoá đã mang `locale`, giữ tên cũ trong lúc chờ.
    placeholderData: keepPreviousData,
  });
}

export function useShopMenuProducts(
  shopSlug: string,
  menuId: string | null,
  filters: ShopMenuProductFilters = {},
  /**
   * #3163 — `enabled: false` để TẮT hẳn lượt đi cả thực đơn.
   *
   * Lưới nay tải theo section, và đường đi-hết-các-trang chỉ còn dùng cho TÌM
   * KIẾM. Không có công tắc này thì nó vẫn chạy nền mỗi 60 giây và toàn bộ chi
   * phí vừa cắt được quay lại nguyên vẹn — im lặng, vì màn hình vẫn đúng.
   */
  options: { enabled?: boolean } = {},
) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: shopMenuKeys.products(shopSlug, menuId ?? "", locale, filters),
    queryFn: () =>
      shopMenuService.listAllProducts(shopSlug, menuId as string, filters),
    enabled: !!shopSlug && !!menuId && (options.enabled ?? true),
    // Plan-019 — periodic refetch catches Happy Hour window transitions
    // (both start AND end of the promotion's daily_time_from / to). A
    // 60s interval matches the backend MenuPromotionService cache TTL,
    // so we won't see UI flicker faster than the source-of-truth can
    // change. Stops when the tab loses focus.
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
    // Swap product names in place on a language switch (see list hook).
    placeholderData: keepPreviousData,
  });
}

/**
 * GET /api/v1/pos/menus/by-day/{dayOfWeek}
 *
 * Returns Active branch menus that have at least one schedule covering
 * the given day of week (0=Sun … 6=Sat — Carbon convention, matches
 * `Date.prototype.getDay()`). Each row is enriched with `start_time`
 * and `end_time` from the highest-priority schedule for that day.
 *
 * Note: if a menu has no schedule rows at all (always-on), it is
 * EXCLUDED from this endpoint. Use `useShopMenus` for the always-on
 * case (or for status filtering across the full menu list).
 */
export function useShopMenusByDay(
  shopSlug: string,
  dayOfWeek: number,
  filters: ShopMenuByDayFilters = {},
) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: shopMenuKeys.byDay(shopSlug, dayOfWeek, locale, filters),
    queryFn: () => shopMenuService.listByDay(shopSlug, dayOfWeek, filters),
    enabled:
      !!shopSlug &&
      Number.isInteger(dayOfWeek) &&
      dayOfWeek >= 0 &&
      dayOfWeek <= 6,
    placeholderData: keepPreviousData,
  });
}
