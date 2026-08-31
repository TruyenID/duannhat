/**
 * Topping Group Service — pure TypeScript, no React dependency.
 *
 * Contains all API calls for the Topping Group domain.
 * Used by hooks in src/hooks/api/use-topping-groups.ts.
 *
 * URL convention: /api/v1/hq/{brandSlug}/topping-groups/...
 *                 /api/v1/hq/{brandSlug}/products/{product}/topping-groups/...
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export interface ToppingGroupProductRef {
  id: string;
  name: string;
  variants_count: number;
  translations: {
    en?: { name: string; description: string | null };
    ja?: { name: string; description: string | null };
    vi?: { name: string; description: string | null };
  };
}

export interface ToppingGroup {
  id: string;
  name: string;
  translations: {
    ja?: { name?: string };
    en?: { name?: string };
    vi?: { name?: string };
  };
  price_strategy: "flat" | "free_up_to_n";
  free_quantity: number | null;
  selection_type: "single" | "multiple";
  modifier_type: "add" | "remove";
  min_select: number;
  max_select: number | null;
  /** Stepper cap per topping item. Default 1 (binary pick → checkbox/radio).
   *  >1 unlocks the +/- counter UI in customer-web's product modal. */
  max_qty_per_item: number;
  sort_order: number;
  is_active: boolean;
  /** Populated by the detail endpoint — not present on list/lookup responses. */
  products?: ToppingGroupProductRef[];
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface ToppingGroupLookupItem {
  id: string;
  name: string;
  /** Number of non-soft-deleted items in the group. Drives the empty-group
   *  warning on the combo product picker — a group with 0 items is a
   *  configuration mistake (customer modifier modal would render empty). */
  items_count: number;
}

export interface ProductSkuRef {
  id: string;
  sku: string | null;
  name: string | null;
  selling_price: string | null;
  is_active: boolean;
  option_value1?: { label: string } | null;
  option_value2?: { label: string } | null;
  option_value3?: { label: string } | null;
}

export interface ToppingGroupItem {
  id: string;
  topping_group_id: string;
  product_id: string;
  product?: {
    id: string;
    name: string;
    sku?: string | null;
    image_url?: string | null;
  };
  sort_order: number;
  /** Number of active SKUs the topping product has — appended by ToppingGroupItemResource. */
  variants_count?: number;
  skus?: ToppingGroupItemSku[];
  /** Active ProductSku rows for the topping product, with option values — appended by ToppingGroupItemResource. */
  product_skus?: ProductSkuRef[];
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface ToppingGroupItemSku {
  id: string;
  topping_group_item_id: string;
  product_sku_id: string | null;
  productSku?: {
    id: string;
    sku: string | null;
    name: string | null;
    selling_price: string | null;
    option_value1?: { label: string } | null;
    option_value2?: { label: string } | null;
    option_value3?: { label: string } | null;
  } | null;
  extra_price: string;
}

export interface ProductToppingGroupItemOverride {
  id: string;
  product_id: string;
  topping_group_id: string;
  topping_group_item_id: string;
  product_sku_id: string | null;
  is_hidden: boolean;
  override_price: string | null;
  created_at: string;
  updated_at: string;
}

export interface OverrideSyncRow {
  topping_group_item_id: string;
  product_sku_id?: string | null;
  is_hidden: boolean;
  override_price?: number | null;
}

export interface ToppingGroupFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  with_trashed?: boolean;
  sort?: string;
}

export interface CreateToppingGroupInput {
  name: string;
  price_strategy?: "flat" | "free_up_to_n";
  free_quantity?: number | null;
  selection_type?: "single" | "multiple";
  modifier_type?: "add" | "remove";
  min_select?: number;
  max_select?: number | null;
  max_qty_per_item?: number;
  sort_order?: number;
  is_active?: boolean;
  // Translatable name — `buildI18nPayload({ name })` only emits keys that the
  // user actually filled in, so each locale is optional and the inner `name`
  // is also optional. Matches the recipe-service / product-service / payment-
  // method-service convention. Server-side Astrotomic treats missing locales
  // as "no translation".
  ja?: { name?: string };
  en?: { name?: string };
  vi?: { name?: string };
}

export interface UpdateToppingGroupInput {
  name?: string;
  price_strategy?: "flat" | "free_up_to_n";
  free_quantity?: number | null;
  selection_type?: "single" | "multiple";
  modifier_type?: "add" | "remove";
  min_select?: number;
  max_select?: number | null;
  max_qty_per_item?: number;
  sort_order?: number;
  is_active?: boolean;
  ja?: { name?: string };
  en?: { name?: string };
  vi?: { name?: string };
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/topping-groups${path}`;
}

function toParams(filters: ToppingGroupFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) params.set("is_active", filters.is_active ? "1" : "0");
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const toppingGroupService = {
  // --- ToppingGroup CRUD ---

  list: (brandSlug: string, filters: ToppingGroupFilters = {}) =>
    apiFetch<PaginatedResponse<ToppingGroup>>(
      `${brandUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: ToppingGroup }>(brandUrl(brandSlug, `/${id}`)),

  lookup: (brandSlug: string) =>
    apiFetch<{ data: ToppingGroupLookupItem[] }>(brandUrl(brandSlug, "/lookup")),

  create: (brandSlug: string, data: CreateToppingGroupInput) =>
    apiFetch<{ data: ToppingGroup }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateToppingGroupInput) =>
    apiFetch<{ data: ToppingGroup }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{
      deleted: number;
      errors: Array<{
        id: string;
        message: string;
        error?: string;
        used_by?: { id: string; name: string }[];
      }>;
    }>(brandUrl(brandSlug, "/bulk-delete"), {
      method: "POST",
      body: JSON.stringify({ ids }),
    }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: ToppingGroup }>(brandUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  reorder: (brandSlug: string, toppingGroupIds: string[]) =>
    apiFetch<{ message: string }>(brandUrl(brandSlug, "/reorder"), {
      method: "PUT",
      body: JSON.stringify({ topping_group_ids: toppingGroupIds }),
    }),

  // --- ToppingGroup Items ---

  listItems: (brandSlug: string, groupId: string) =>
    apiFetch<{ data: ToppingGroupItem[] }>(brandUrl(brandSlug, `/${groupId}/items`)),

  addItem: (
    brandSlug: string,
    groupId: string,
    data: { product_id: string; sort_order?: number }
  ) =>
    apiFetch<{ data: ToppingGroupItem }>(brandUrl(brandSlug, `/${groupId}/items`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  updateItem: (brandSlug: string, groupId: string, itemId: string, data: { sort_order: number }) =>
    apiFetch<{ data: ToppingGroupItem }>(brandUrl(brandSlug, `/${groupId}/items/${itemId}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  reorderItems: (brandSlug: string, groupId: string, itemIds: string[]) =>
    apiFetch<{ message: string }>(brandUrl(brandSlug, `/${groupId}/items/reorder`), {
      method: "PUT",
      body: JSON.stringify({ item_ids: itemIds }),
    }),

  /** Full-sync the product panel in one save: add + reorder + remove to match
   *  the given ordered product ids. */
  syncItems: (brandSlug: string, groupId: string, productIds: string[]) =>
    apiFetch<{ data: ToppingGroupItem[] }>(brandUrl(brandSlug, `/${groupId}/items/sync`), {
      method: "PUT",
      body: JSON.stringify({ product_ids: productIds }),
    }),

  deleteItem: (brandSlug: string, groupId: string, itemId: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${groupId}/items/${itemId}`), { method: "DELETE" }),

  // --- ToppingGroup Item SKUs ---

  listItemSkus: (brandSlug: string, groupId: string, itemId: string) =>
    apiFetch<{ data: ToppingGroupItemSku[] }>(
      brandUrl(brandSlug, `/${groupId}/items/${itemId}/skus`)
    ),

  addItemSku: (
    brandSlug: string,
    groupId: string,
    itemId: string,
    data: { product_sku_id?: string | null; extra_price: number }
  ) =>
    apiFetch<{ data: ToppingGroupItemSku }>(
      brandUrl(brandSlug, `/${groupId}/items/${itemId}/skus`),
      { method: "POST", body: JSON.stringify(data) }
    ),

  updateItemSku: (
    brandSlug: string,
    groupId: string,
    itemId: string,
    itemSkuId: string,
    data: { extra_price: number }
  ) =>
    apiFetch<{ data: ToppingGroupItemSku }>(
      brandUrl(brandSlug, `/${groupId}/items/${itemId}/skus/${itemSkuId}`),
      { method: "PUT", body: JSON.stringify(data) }
    ),

  deleteItemSku: (brandSlug: string, groupId: string, itemId: string, itemSkuId: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${groupId}/items/${itemId}/skus/${itemSkuId}`), {
      method: "DELETE",
    }),

  // --- Product ↔ ToppingGroup sync ---

  listForProduct: (brandSlug: string, productId: string) =>
    apiFetch<{ data: ToppingGroup[] }>(
      `/api/v1/hq/${brandSlug}/products/${productId}/topping-groups`
    ),

  syncForProduct: (
    brandSlug: string,
    productId: string,
    data: { topping_group_ids: string[]; sort_orders?: Record<string, number> }
  ) =>
    apiFetch<{ data: ToppingGroup[] }>(
      `/api/v1/hq/${brandSlug}/products/${productId}/topping-groups/sync`,
      { method: "POST", body: JSON.stringify(data) }
    ),

  // --- Per-product item overrides ---

  listOverrides: (brandSlug: string, productId: string, groupId: string) =>
    apiFetch<{ data: ProductToppingGroupItemOverride[] }>(
      `/api/v1/hq/${brandSlug}/products/${productId}/topping-groups/${groupId}/overrides`
    ),

  syncOverrides: (
    brandSlug: string,
    productId: string,
    groupId: string,
    overrides: OverrideSyncRow[]
  ) =>
    apiFetch<{ data: ProductToppingGroupItemOverride[] }>(
      `/api/v1/hq/${brandSlug}/products/${productId}/topping-groups/${groupId}/overrides/sync`,
      { method: "PUT", body: JSON.stringify({ overrides }) }
    ),
};
