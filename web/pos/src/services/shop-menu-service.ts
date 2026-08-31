/**
 * Shop Menu Service — pure TypeScript fetch layer.
 *
 * Mirrors godx-tempo-frontend/src/hooks/api/use-shop-menus.ts (but split into
 * service + hook per the convention in tempo's other domains). Exposes the
 * read-only subset the cashier needs:
 *
 *   1. GET /api/v1/pos/menus                       — list menus
 *   2. GET /api/v1/pos/menus/by-day/{dayOfWeek}    — schedule-driven
 *   3. GET /api/v1/pos/menus/{menu}                — detail + products
 *   4. GET /api/v1/pos/menus/{menu}/products       — paginated / searchable
 *
 * Toggle / price / sync mutations are intentionally omitted — cashier role
 * can only add items and change quantities in the cart.
 *
 * In DEV, if the backend is unreachable the service falls back to the mock
 * fixtures in `src/app/pos/data/mock-data.ts` so the UI still renders during
 * early local development. Same pattern as tempo's order-service.withMock.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import { captureMessage } from "@/lib/sentry";
import type {
  ShopMenuByDayFilters,
  ShopMenuByDayResource,
  ShopMenuFilters,
  ShopMenuProduct,
  ShopMenuProductFilters,
  ShopMenuResource,
  ShopMenuSectionSummary,
} from "@/app/pos/types";
import { mockShopMenu } from "@/app/pos/data/mock-data";

const DEV = import.meta.env.DEV;

/**
 * Page size for the product drain in `listAllProducts`.
 *
 * Cloud caps `per_page` at 100 (`Shop\MenuController::listProducts` does
 * `min($request->integer('per_page', 50), 100)`); the workstation LAN mirror
 * caps at 200. 100 is the largest value BOTH doors honour, so it is the
 * fewest round trips obtainable without one of the two silently trimming the
 * page and putting us back where this started.
 */
const PRODUCT_PAGE_SIZE = 100;

/**
 * Hard stop for the drain — 30 × 100 = 3000 menu products, well clear of the
 * largest real menu (367 rows on 本郷's 昼メニュー as of 2026-08).
 *
 * It is not a display limit; it is what keeps a server that ignores `page`
 * (or reports a wrong `last_page`) costing a bounded number of requests
 * instead of looping until the tablet gives up mid-service.
 */
const PRODUCT_MAX_PAGES = 30;

async function withMock<T>(
  fn: () => Promise<T>,
  mock: () => T | null,
  label: string,
): Promise<T> {
  try {
    return await fn();
  } catch (err) {
    if (DEV) {
      const m = mock();
      if (m !== null) {
        console.warn(`[DEV MOCK] ${label}`, err);
        return m;
      }
    }
    throw err;
  }
}

function menuUrl(_shopSlug: string, path: string = ""): string {
  // shopSlug resolved server-side via X-Shop-Slug header; param retained
  // for caller compatibility + TanStack Query cache keys.
  return `/api/v1/pos/menus${path}`;
}

function toMenuParams(filters: ShopMenuFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set("search", filters.search);
  if (filters.status) params.set("status", filters.status);
  if (filters.service_type) params.set("service_type", filters.service_type);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  return params.toString();
}

function toProductParams(filters: ShopMenuProductFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set("search", filters.search);
  // #3163 — không dùng `if (filters.section_id)` một mình là đủ, nhưng `"none"`
  // là chuỗi truthy nên nó đi qua; ghi rõ ở đây để không ai "dọn" thành
  // `!= null` rồi vô tình gửi chuỗi rỗng, thứ mà cả hai backend hiểu là
  // "mọi section".
  if (filters.section_id) params.set("section_id", filters.section_id);
  if (filters.sku_id) params.set("sku_id", filters.sku_id);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  return params.toString();
}

function toByDayParams(filters: ShopMenuByDayFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set("search", filters.search);
  if (filters.service_type) params.set("service_type", filters.service_type);
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  return params.toString();
}

// ---------------------------------------------------------------------------
//  Mock fabricators — only hit in DEV when the real API is down
// ---------------------------------------------------------------------------

function mockMenuList(
  filters: ShopMenuFilters,
): PaginatedResponse<ShopMenuResource> {
  const rows: ShopMenuResource[] = [
    { ...mockShopMenu, menu_products: undefined },
  ];
  const search = filters.search?.toLowerCase() ?? "";
  const filtered = search
    ? rows.filter((m) => m.name.toLowerCase().includes(search))
    : rows;
  return {
    data: filtered,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: 1,
      last_page: 1,
      per_page: filtered.length,
      to: filtered.length,
      total: filtered.length,
    },
  };
}

function mockMenuDetail(menuId: string): { data: ShopMenuResource } | null {
  if (menuId !== mockShopMenu.id) return null;
  return { data: mockShopMenu };
}

function mockMenuProducts(
  menuId: string,
  filters: ShopMenuProductFilters,
): PaginatedResponse<ShopMenuProduct> | null {
  if (menuId !== mockShopMenu.id) return null;
  const all = mockShopMenu.menu_products ?? [];
  const search = filters.search?.toLowerCase() ?? "";
  const filtered = search
    ? all.filter((mp) => mp.product?.name.toLowerCase().includes(search))
    : all;
  return {
    data: filtered,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: filters.page ?? 1,
      from: filtered.length === 0 ? null : 1,
      last_page: 1,
      per_page: filters.per_page ?? filtered.length,
      to: filtered.length === 0 ? null : filtered.length,
      total: filtered.length,
    },
  };
}

// ---------------------------------------------------------------------------
//  Service
// ---------------------------------------------------------------------------

export const shopMenuService = {
  /** #1 — list shop menus (default only Active). */
  list: (shopSlug: string, filters: ShopMenuFilters = { status: "Active" }) =>
    withMock(
      () =>
        apiFetch<PaginatedResponse<ShopMenuResource>>(
          `${menuUrl(shopSlug)}?${toMenuParams(filters)}`,
        ),
      () => mockMenuList(filters),
      `shopMenuService.list(${shopSlug})`,
    ),

  /** #2 — menu detail with eager-loaded menu_products + skus. */
  getById: (shopSlug: string, menuId: string) =>
    withMock(
      () =>
        apiFetch<{ data: ShopMenuResource }>(menuUrl(shopSlug, `/${menuId}`)),
      () => mockMenuDetail(menuId),
      `shopMenuService.getById(${shopSlug}, ${menuId})`,
    ),

  /** #3 — paginated, searchable product list inside one menu. */
  listProducts: (
    shopSlug: string,
    menuId: string,
    filters: ShopMenuProductFilters = {},
  ) =>
    withMock(
      () =>
        apiFetch<PaginatedResponse<ShopMenuProduct>>(
          `${menuUrl(shopSlug, `/${menuId}/products`)}?${toProductParams(filters)}`,
        ),
      () => mockMenuProducts(menuId, filters),
      `shopMenuService.listProducts(${shopSlug}, ${menuId})`,
    ),

  /**
   * #3b — EVERY page of one menu's products, merged into a single response.
   *
   * The POS grid is not a paged list: it groups whatever it receives into
   * sections and builds the section pill row from that grouping, so one page
   * is not "the first screenful" — it is the entire catalog the cashier can
   * reach, and nothing on screen says otherwise. Asking for 60 rows of 本郷's
   * 89-row dinner menu dropped デザート・飲み物, アルコール and 無料サービス
   * whole; the 127-row PHO EXPRESS menu showed 15 of its 26 sections. Both
   * looked like a complete menu.
   *
   * `last_page` from the first response decides how far to walk, so the usual
   * menu costs exactly one request and only a genuinely large one pays more.
   * A failure on any page rejects the whole call: the error state with its
   * retry button is honest, a quietly half-loaded menu is what we are fixing.
   */
  /**
   * #3163 — các section của menu kèm số món.
   *
   * Rẻ và luôn đủ: một truy vấn gộp ở đầu kia, không nạp quan hệ nào. Thanh pill
   * dựng từ đây nên nó ĐÚNG dù menu to cỡ nào — khác hẳn `listAllProducts`, thứ
   * phải kéo về 100% số dòng chỉ để biết có những section nào.
   *
   * KHÔNG có mock DEV: một danh sách section bịa sẽ vẽ ra thanh pill trông như
   * thật rồi mọi lượt bấm trả về rỗng, tức hỏng ở chỗ khó lần hơn là chỉ báo lỗi.
   */
  listSections: async (
    shopSlug: string,
    menuId: string,
  ): Promise<{ data: ShopMenuSectionSummary[] }> => {
    return apiFetch<{ data: ShopMenuSectionSummary[] }>(
      menuUrl(shopSlug, `/${menuId}/sections`),
    );
  },

  listAllProducts: async (
    shopSlug: string,
    menuId: string,
    filters: ShopMenuProductFilters = {},
  ): Promise<PaginatedResponse<ShopMenuProduct>> => {
    const perPage = filters.per_page ?? PRODUCT_PAGE_SIZE;
    const fetchPage = (page: number) =>
      shopMenuService.listProducts(shopSlug, menuId, {
        ...filters,
        page,
        per_page: perPage,
      });

    const first = await fetchPage(1);

    // Rows are keyed by `mp.id` in the grid. `menu_products.display_order` is
    // not unique (104 of one menu's 127 rows sit on 0), so a page boundary can
    // land inside a tie and hand the same row back twice — which React would
    // render as a duplicate card with a duplicate key.
    const seen = new Set<string>();
    const rows: ShopMenuProduct[] = [];
    const collect = (page: ShopMenuProduct[]): void => {
      for (const mp of page) {
        if (seen.has(mp.id)) continue;
        seen.add(mp.id);
        rows.push(mp);
      }
    };
    collect(first.data);

    const reportedPages = Math.max(Number(first.meta?.last_page) || 1, 1);
    const lastPage = Math.min(reportedPages, PRODUCT_MAX_PAGES);
    if (reportedPages > PRODUCT_MAX_PAGES) {
      // A bounded walk that trims in silence is the bug this function exists to
      // fix, one order of magnitude further out. Nobody standing at the till
      // can tell a 3000-row menu from a truncated one, so the truncation has to
      // say so somewhere a human will eventually read.
      captureMessage(
        `menu products truncated: menu ${menuId} reports ${reportedPages} pages, walked ${PRODUCT_MAX_PAGES}`,
        "error",
      );
    }
    // A page that comes back full but contributes no new row means the far end
    // is not honouring `page` at all — a proxy that cached page 1, a route that
    // dropped the query param, an older LAN mirror. Walking the rest cannot add
    // anything; it only costs a tablet in service another 28 round trips of the
    // same ~638KB payload. Two in a row, because one can legitimately happen
    // when a page boundary lands inside a tie.
    let barrenPages = 0;
    for (let page = 2; page <= lastPage; page++) {
      const next = await fetchPage(page);
      if (next.data.length === 0) break;
      const before = rows.length;
      collect(next.data);
      if (rows.length === before) {
        if (++barrenPages >= 2) break;
      } else {
        barrenPages = 0;
      }
    }

    // The walk can end short of the menu without any of the guards above
    // firing: a server that ignores `page` reports a perfectly ordinary
    // `last_page`, and a page boundary landing inside a `display_order` tie can
    // hand back one row twice while dropping another. Both come back as a menu
    // that looks complete and is not. `total` is the one number the far end
    // gives us that is independent of how it chose to slice the pages, so it is
    // what turns "looks complete" into a claim that can be checked.
    //
    // Only when `total` is actually a number: the workstation LAN mirror does
    // not emit one, and inferring a shortfall from an absent field would be
    // inventing an incident. And not when the ceiling already fired — that
    // truncation is already reported, one alarm per event.
    const reportedTotal = Number(first.meta?.total);
    if (
      reportedPages <= PRODUCT_MAX_PAGES &&
      Number.isFinite(reportedTotal) &&
      reportedTotal > rows.length
    ) {
      captureMessage(
        `menu products incomplete: menu ${menuId} reports ${reportedTotal} rows, drained ${rows.length}`,
        "error",
      );
    }

    return {
      ...first,
      data: rows,
      meta: {
        ...first.meta,
        current_page: 1,
        per_page: rows.length,
        from: rows.length === 0 ? null : 1,
        to: rows.length === 0 ? null : rows.length,
      },
    };
  },

  /**
   * #4 — schedule-driven branch menus active on a given day-of-week.
   *
   * `dayOfWeek` follows Carbon convention: 0=Sunday … 6=Saturday (matches
   * `Date.prototype.getDay()` in JS, so callers can pass `new Date().getDay()`
   * directly).
   *
   * Each row carries `start_time` / `end_time` from the highest-priority
   * `menu_schedules` row matching that day so the POS picker can render
   * "{name} · 11:00 – 14:30" without a follow-up fetch. Always-on menus
   * (no schedule rows) are intentionally excluded — this endpoint is
   * strictly schedule-driven by backend contract.
   */
  listByDay: (
    shopSlug: string,
    dayOfWeek: number,
    filters: ShopMenuByDayFilters = {},
  ) => {
    const safeDay = Math.max(0, Math.min(6, Math.trunc(dayOfWeek)));
    return apiFetch<PaginatedResponse<ShopMenuByDayResource>>(
      `${menuUrl(shopSlug, `/by-day/${safeDay}`)}?${toByDayParams(filters)}`,
    );
  },
};
