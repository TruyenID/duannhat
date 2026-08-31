/**
 * Shop Menu Hooks — React Query wrappers for the shop-side Menu API.
 *
 * Mirrors the 8 endpoints exposed under `/api/v1/shops/{shopSlug}/menus`:
 *
 *   1. GET  /menus                                  — list cloned branch menus
 *   2. GET  /menus/{menu}                            — detail with eager-loaded products + skus
 *   3. GET  /menus/{menu}/products                   — paginated product browsing
 *   4. POST /menus/{menu}/products/{mp}/toggle       — toggle whole product
 *   5. POST /menus/{menu}/products/{mp}/skus/{mps}/toggle      — toggle one SKU
 *   6. POST /menus/{menu}/products/{mp}/skus/{mps}/price       — override SKU price (Manager+)
 *   7. POST /menus/{menu}/products/{mp}/skus/{mps}/reset-price — reset SKU price (Manager+)
 *   8. POST /menus/{menu}/sync                        — pull new products from master
 *
 * Conventions:
 *   - Queries use `shopMenuKeys`; mutations invalidate `all(shopSlug)` so
 *     both the list cache and any detail caches refresh together.
 *   - Toggle mutations are optimistic: the cached detail object is patched
 *     immediately and rolled back on error, so the UI feels instant.
 *   - Price mutations patch the cache from the server response on success
 *     so the "overridden" badge flips in the same tick.
 *   - 403 responses (Manager-only endpoints) surface as sonner toasts.
 */

import { useMutation, useQuery, useQueryClient, type QueryKey } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type {
  MenuProductToppingItemOverride,
  ShopMenuFilters,
  ShopMenuProduct,
  ShopMenuProductFilters,
  ShopMenuProductSku,
  ShopMenuResource,
  ShopToppingOverrideSyncRow,
  UpdateMenuSkuPriceInput,
} from "@/types/shop";
import { useTranslation } from "@/providers/app-provider";
import { shopMenuKeys, shopMenuScheduleKeys } from "./query-keys";

// =========================================================================
//  URL / param helpers
// =========================================================================

function menuUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/menus${path}`;
}

function toMenuParams(filters: ShopMenuFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set("search", filters.search);
  if (filters.status) params.set("status", filters.status);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  return params.toString();
}

function toProductParams(filters: ShopMenuProductFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set("search", filters.search);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  return params.toString();
}

// =========================================================================
//  Queries
// =========================================================================

/** #1 — GET /api/v1/shops/{shopSlug}/menus */
export function useShopMenus(shopSlug: string, filters: ShopMenuFilters = { status: "Active" }) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: shopMenuKeys.list(shopSlug, locale, filters),
    queryFn: () =>
      apiFetch<PaginatedResponse<ShopMenuResource>>(
        `${menuUrl(shopSlug)}?${toMenuParams(filters)}`
      ),
    enabled: !!shopSlug,
  });
}

/** #2 — GET /api/v1/shops/{shopSlug}/menus/{menu} */
export function useShopMenu(
  shopSlug: string,
  menuId: string,
  options: { compact?: boolean } = {}
) {
  const { locale } = useTranslation();
  const compact = options.compact ?? false;
  return useQuery({
    queryKey: shopMenuKeys.detail(shopSlug, locale, menuId, { compact }),
    queryFn: async () => {
      const suffix = compact ? `/${menuId}?compact=1` : `/${menuId}`;
      const response = await apiFetch<{ data: ShopMenuResource }>(menuUrl(shopSlug, suffix));

      // MenuResource's canonical relationship key is `menuProducts`. Keep the
      // legacy alias client-side so existing optimistic cache updates continue
      // to operate without the backend serialising the same 2 MB collection
      // twice over the Amplify reverse proxy.
      return {
        ...response,
        data: {
          ...response.data,
          menu_products: response.data.menuProducts ?? response.data.menu_products ?? [],
        },
      };
    },
    enabled: !!shopSlug && !!menuId,
  });
}

/** #3 — GET /api/v1/shops/{shopSlug}/menus/{menu}/products */
export function useShopMenuProducts(
  shopSlug: string,
  menuId: string,
  filters: ShopMenuProductFilters = {}
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: shopMenuKeys.products(shopSlug, locale, menuId, filters),
    queryFn: () =>
      apiFetch<PaginatedResponse<ShopMenuProduct>>(
        `${menuUrl(shopSlug, `/${menuId}/products`)}?${toProductParams(filters)}`
      ),
    enabled: !!shopSlug && !!menuId,
  });
}

// =========================================================================
//  Cache helpers
// =========================================================================

/**
 * Patch the cached menu detail so a single MenuProduct row reflects an
 * update without refetching. Used by the optimistic product toggle.
 */
function patchCachedMenuProduct(
  qc: ReturnType<typeof useQueryClient>,
  detailKey: QueryKey,
  productId: string,
  patch: Partial<ShopMenuProduct>
): { previous: { data: ShopMenuResource } | undefined } {
  const previous = qc.getQueryData<{ data: ShopMenuResource }>(detailKey);
  if (previous?.data?.menu_products) {
    qc.setQueryData<{ data: ShopMenuResource }>(detailKey, {
      ...previous,
      data: {
        ...previous.data,
        menu_products: previous.data.menu_products.map((p) =>
          p.id === productId ? { ...p, ...patch } : p
        ),
      },
    });
  }
  return { previous };
}

/**
 * Patch the cached menu detail so a single MenuProductSku row reflects an
 * update without refetching. Used by toggle / price / reset mutations.
 */
function patchCachedMenuProductSku(
  qc: ReturnType<typeof useQueryClient>,
  detailKey: QueryKey,
  productId: string,
  skuId: string,
  patch: Partial<ShopMenuProductSku>
): { previous: { data: ShopMenuResource } | undefined } {
  const previous = qc.getQueryData<{ data: ShopMenuResource }>(detailKey);
  if (previous?.data?.menu_products) {
    qc.setQueryData<{ data: ShopMenuResource }>(detailKey, {
      ...previous,
      data: {
        ...previous.data,
        menu_products: previous.data.menu_products.map((p) => {
          if (p.id !== productId) return p;
          return {
            ...p,
            skus: p.skus?.map((s) => (s.id === skuId ? { ...s, ...patch } : s)),
          };
        }),
      },
    });
  }
  return { previous };
}

// =========================================================================
//  Mutations
// =========================================================================

/** #4 — POST /menus/{menu}/products/{menuProduct}/toggle */
export function useToggleShopMenuProduct(shopSlug: string, menuId: string) {
  const qc = useQueryClient();
  const { locale, t } = useTranslation();
  const detailKey = shopMenuKeys.detail(shopSlug, locale, menuId, { compact: true });

  return useMutation({
    mutationFn: ({ menuProductId }: { menuProductId: string }) =>
      apiFetch<{ data: ShopMenuProduct }>(
        menuUrl(shopSlug, `/${menuId}/products/${menuProductId}/toggle`),
        { method: "POST" }
      ),
    onMutate: async ({ menuProductId }) => {
      await qc.cancelQueries({ queryKey: detailKey });
      const previous = qc.getQueryData<{ data: ShopMenuResource }>(detailKey);
      const target = previous?.data?.menu_products?.find((p) => p.id === menuProductId);
      if (target) {
        patchCachedMenuProduct(qc, detailKey, menuProductId, {
          is_active: !target.is_active,
        });
      }
      return { previous };
    },
    onError: (e: Error, _vars, ctx) => {
      if (ctx?.previous) qc.setQueryData(detailKey, ctx.previous);
      toast.error(e.message || "Failed to toggle product.");
    },
    onSuccess: (result, { menuProductId }) => {
      patchCachedMenuProduct(qc, detailKey, menuProductId, result.data);
      toast.success(
        result.data.is_active
          ? t("shop.menu.detail.toast_product_activated")
          : t("shop.menu.detail.toast_product_deactivated")
      );
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: shopMenuKeys.all(shopSlug) });
    },
  });
}

/** Bulk toggle every product in one section — the "bật tất cả món" button. */
export function useBulkToggleSectionProducts(shopSlug: string, menuId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ sectionId, isActive }: { sectionId: string; isActive: boolean }) =>
      apiFetch<{ updated: number }>(
        menuUrl(shopSlug, `/${menuId}/sections/${sectionId}/products/bulk-toggle`),
        { method: "POST", body: JSON.stringify({ is_active: isActive }) }
      ),
    onSuccess: (result, { isActive }) => {
      toast.success(
        t(
          isActive
            ? "shop.menu.detail.section_bulk_on_done"
            : "shop.menu.detail.section_bulk_off_done",
          { count: result.updated }
        )
      );
      qc.invalidateQueries({ queryKey: shopMenuKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("shop.menu.detail.section_bulk_failed")),
  });
}

/** #5 — POST /menus/{menu}/products/{mp}/skus/{mps}/toggle */
export function useToggleShopMenuProductSku(shopSlug: string, menuId: string) {
  const qc = useQueryClient();
  const { locale, t } = useTranslation();
  const detailKey = shopMenuKeys.detail(shopSlug, locale, menuId, { compact: true });

  return useMutation({
    mutationFn: ({
      menuProductId,
      menuProductSkuId,
    }: {
      menuProductId: string;
      menuProductSkuId: string;
    }) =>
      apiFetch<{ data: ShopMenuProductSku }>(
        menuUrl(shopSlug, `/${menuId}/products/${menuProductId}/skus/${menuProductSkuId}/toggle`),
        { method: "POST" }
      ),
    onMutate: async ({ menuProductId, menuProductSkuId }) => {
      await qc.cancelQueries({ queryKey: detailKey });
      const previous = qc.getQueryData<{ data: ShopMenuResource }>(detailKey);
      const target = previous?.data?.menu_products
        ?.find((p) => p.id === menuProductId)
        ?.skus?.find((s) => s.id === menuProductSkuId);
      if (target) {
        patchCachedMenuProductSku(qc, detailKey, menuProductId, menuProductSkuId, {
          is_active: !target.is_active,
        });
      }
      return { previous };
    },
    onError: (e: Error, _vars, ctx) => {
      if (ctx?.previous) qc.setQueryData(detailKey, ctx.previous);
      toast.error(e.message || "Failed to toggle variant.");
    },
    onSuccess: (result, { menuProductId, menuProductSkuId }) => {
      patchCachedMenuProductSku(qc, detailKey, menuProductId, menuProductSkuId, result.data);
      toast.success(
        result.data.is_active
          ? t("shop.menu.detail.toast_variant_activated")
          : t("shop.menu.detail.toast_variant_deactivated")
      );
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: shopMenuKeys.all(shopSlug) });
    },
  });
}

/** #6 — POST /menus/{menu}/products/{mp}/skus/{mps}/price */
export function useUpdateShopMenuSkuPrice(shopSlug: string, menuId: string) {
  const qc = useQueryClient();
  const { locale } = useTranslation();
  const detailKey = shopMenuKeys.detail(shopSlug, locale, menuId, { compact: true });

  return useMutation({
    mutationFn: ({
      menuProductId,
      menuProductSkuId,
      data,
    }: {
      menuProductId: string;
      menuProductSkuId: string;
      data: UpdateMenuSkuPriceInput;
    }) =>
      apiFetch<{ data: ShopMenuProductSku }>(
        menuUrl(shopSlug, `/${menuId}/products/${menuProductId}/skus/${menuProductSkuId}/price`),
        { method: "POST", body: JSON.stringify(data) }
      ),
    onSuccess: (result, { menuProductId, menuProductSkuId }) => {
      toast.success("Price updated.");
      patchCachedMenuProductSku(qc, detailKey, menuProductId, menuProductSkuId, result.data);
      qc.invalidateQueries({ queryKey: shopMenuKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update price."),
  });
}

/** #7 — POST /menus/{menu}/products/{mp}/skus/{mps}/reset-price */
export function useResetShopMenuSkuPrice(shopSlug: string, menuId: string) {
  const qc = useQueryClient();
  const { locale } = useTranslation();
  const detailKey = shopMenuKeys.detail(shopSlug, locale, menuId, { compact: true });

  return useMutation({
    mutationFn: ({
      menuProductId,
      menuProductSkuId,
    }: {
      menuProductId: string;
      menuProductSkuId: string;
    }) =>
      apiFetch<{ data: ShopMenuProductSku }>(
        menuUrl(
          shopSlug,
          `/${menuId}/products/${menuProductId}/skus/${menuProductSkuId}/reset-price`
        ),
        { method: "POST" }
      ),
    onSuccess: (result, { menuProductId, menuProductSkuId }) => {
      toast.success("Price reset to default.");
      patchCachedMenuProductSku(qc, detailKey, menuProductId, menuProductSkuId, result.data);
      qc.invalidateQueries({ queryKey: shopMenuKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to reset price."),
  });
}

/** PATCH /menus/{menu}/settings — shop per-menu timeout override (tầng ④) */
export function useUpdateShopMenuTimeout(shopSlug: string, menuId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (cartTimeoutMinutes: number | null) =>
      apiFetch<{
        data: {
          hq_brand_timeout_minutes: number | null;
          hq_menu_timeout_minutes: number | null;
          shop_default_timeout_minutes: number | null;
          shop_menu_timeout_minutes: number | null;
          effective_timeout_minutes: number | null;
        };
      }>(menuUrl(shopSlug, `/${menuId}/settings`), {
        method: "PATCH",
        body: JSON.stringify({ cart_timeout_minutes: cartTimeoutMinutes }),
      }),
    onSuccess: () => {
      toast.success(t("shop.menus.timeout.toast_saved"));
      qc.invalidateQueries({ queryKey: shopMenuKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("shop.menus.timeout.toast_error")),
  });
}

// #1226 — `useUpdateShopMenuServiceType` lived here. The shop role may not
// change how a menu is served, so the screen reports the value instead of
// editing it and the mutation had no caller left. Deliberately deleted rather
// than kept "for later": an exported hook nobody calls is indistinguishable
// from a working feature until someone checks, which is exactly how the
// whole-menu tax control (#1218) stayed missing. Recover it from git if the
// permission model ever grants this back.
//
// NOTE: the endpoint still accepts `service_type` — this is a UI decision, not
// an authorization boundary. See #1226 for the backend side.

/** #8 — POST /menus/{menu}/sync */
export function useSyncShopMenuFromMaster(shopSlug: string, menuId: string) {
  const qc = useQueryClient();
  const { locale } = useTranslation();
  const detailKey = shopMenuKeys.detail(shopSlug, locale, menuId, { compact: true });

  return useMutation({
    mutationFn: () =>
      apiFetch<{ data: ShopMenuResource }>(menuUrl(shopSlug, `/${menuId}/sync`), {
        method: "POST",
      }),
    onSuccess: (result) => {
      toast.success("Synced from master menu.");
      // Replace the whole detail cache with the freshly synced response so
      // any newly-added products show up without an extra refetch.
      qc.setQueryData(detailKey, result);
      qc.invalidateQueries({ queryKey: shopMenuKeys.all(shopSlug) });
      // Invalidate schedules so newly synced schedules from master appear immediately.
      qc.invalidateQueries({ queryKey: shopMenuScheduleKeys.all(shopSlug, menuId) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to sync from master menu."),
  });
}

// =========================================================================
//  Shop-level topping extra_price / visibility overrides
//  GET/PUT /menus/{menu}/products/{mp}/topping-groups/{group}/overrides
// =========================================================================

function toppingOverrideUrl(
  shopSlug: string,
  menuId: string,
  menuProductId: string,
  toppingGroupId: string
): string {
  return menuUrl(
    shopSlug,
    `/${menuId}/products/${menuProductId}/topping-groups/${toppingGroupId}/overrides`
  );
}

export function useShopMenuToppingOverrides(
  shopSlug: string,
  menuId: string,
  menuProductId: string,
  toppingGroupId: string
) {
  return useQuery({
    queryKey: [
      "shop",
      shopSlug,
      "menu",
      menuId,
      "mp",
      menuProductId,
      "topping-overrides",
      toppingGroupId,
    ],
    queryFn: () =>
      apiFetch<{ data: MenuProductToppingItemOverride[] }>(
        toppingOverrideUrl(shopSlug, menuId, menuProductId, toppingGroupId)
      ),
    enabled: !!(shopSlug && menuId && menuProductId && toppingGroupId),
    staleTime: 30_000,
  });
}

export function useSyncShopMenuToppingOverrides(
  shopSlug: string,
  menuId: string,
  menuProductId: string,
  toppingGroupId: string
) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  const cacheKey = [
    "shop",
    shopSlug,
    "menu",
    menuId,
    "mp",
    menuProductId,
    "topping-overrides",
    toppingGroupId,
  ];

  return useMutation({
    mutationFn: (overrides: ShopToppingOverrideSyncRow[]) =>
      apiFetch<{ data: MenuProductToppingItemOverride[] }>(
        toppingOverrideUrl(shopSlug, menuId, menuProductId, toppingGroupId),
        { method: "PUT", body: JSON.stringify({ overrides }) }
      ),
    onSuccess: (result) => {
      toast.success(t("toast.topping_group.overrides_saved"));
      qc.setQueryData(cacheKey, result);
    },
    onError: (e: Error) => toast.error(e.message || t("toast.topping_group.overrides_error")),
  });
}
