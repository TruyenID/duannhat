/**
 * Product Service — pure TypeScript, no React dependency.
 *
 * All API calls for the Product domain.
 * URL convention: /api/v1/hq/{brandSlug}/products/...
 *
 * Mirrors the shape of category-service.ts; the React-Query layer lives in
 * src/hooks/api/use-products.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { ProductImageFile } from "./product-image-service";

// =========================================================================
//  Types
// =========================================================================

/**
 * Full workflow state space (see backend ProductStatusEnum).
 *
 *   draft ──submit──▶ pending ──approve──▶ approved ──activate──▶ active ──deactivate──▶ inactive
 *                       │                                                                    │
 *                       └──reject──▶ rejected ──submit──▶ pending                            │
 *                                                                                            │
 *                                            approved ◀──activate── inactive ◀───────────────┘
 *
 * New products always start as `draft` — enforced by ProductStoreRequest.
 * Status transitions go through the dedicated workflow endpoints
 * (submit-for-approval / approve / reject / activate / deactivate), NOT via
 * PUT /products/{id}.
 */
export type ProductStatus = "draft" | "pending" | "approved" | "active" | "inactive" | "rejected";

export interface ProductTypeRef {
  id: string;
  name: string;
  key?: string | null;
}

export interface CategoryRef {
  id: string;
  name: string;
  slug?: string | null;
}

/** plan-043 — the product's assigned tax type, eager-loaded on show(). */
export interface TaxTypeRef {
  id: string;
  code: string;
  name: string;
}

export interface ProductOptionValueTranslations {
  ja?: { label?: string | null };
  en?: { label?: string | null };
  vi?: { label?: string | null };
}

export interface ProductOptionValue {
  id: string;
  option_id: string;
  value: string;
  label: string | null;
  position: number;
  is_active: boolean;
  translations?: ProductOptionValueTranslations;
}

export interface ProductOptionTranslations {
  ja?: { name?: string | null };
  en?: { name?: string | null };
  vi?: { name?: string | null };
}

export interface ProductOption {
  id: string;
  product_id: string;
  key: string;
  name: string | null;
  position: number;
  is_active: boolean;
  values?: ProductOptionValue[];
  values_count?: number;
  translations?: ProductOptionTranslations;
}

export interface ProductSku {
  id: string;
  product_id: string;
  sku: string | null;
  name: string | null;
  option_value1_id: string | null;
  option_value2_id: string | null;
  option_value3_id: string | null;
  option_value1?: ProductOptionValue | null;
  option_value2?: ProductOptionValue | null;
  option_value3?: ProductOptionValue | null;
  option_signature: string;
  recipe_id: string | null;
  recipe_multiplier: string;
  cost_price: string;
  cost_price_auto: string;
  is_cost_override: boolean;
  selling_price: string;
  is_active: boolean;
  /**
   * Plan-024 — order-close stock deduction policy. `made_to_order`
   * (default) means no SKU stock-out and no recipe-based material
   * deduction. `track_stock` enables both. Backend default is
   * `made_to_order` for existing rows post-migration.
   */
  inventory_mode: "made_to_order" | "track_stock";
  /** Full gallery — only present on the show() endpoint. */
  gallery?: ProductImageFile[];
  /** First gallery image (sort_order = 0) thumbnail URL — present on list
   *  endpoints that eager-load `galleryFirst`. */
  image_url?: string | null;
  deleted_at: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface ProductTranslations {
  ja?: { name?: string; description?: string };
  en?: { name?: string; description?: string };
  vi?: { name?: string; description?: string };
}

export interface Product {
  id: string;
  organization_id: string;
  brand_id: string | null;
  product_type_id: string | null;
  productType?: ProductTypeRef | null;
  // plan-043 — per-product tax assignment. `tax_type_id` null = inherit the
  // brand default; `taxType` is eager-loaded on show().
  // the standard rate (a reduced tax type is rejected by the backend).
  tax_type_id?: string | null;
  taxType?: TaxTypeRef | null;
  categories?: CategoryRef[];
  options?: ProductOption[];
  skus?: ProductSku[];
  sku: string | null;
  name: string;
  slug: string | null;
  description: string | null;
  /**
   * Per-locale name/description, serialized by Astrotomic on show(). Absent
   * on list endpoints (for perf) so edit pages should fall back to the
   * top-level `name`/`description` when this is missing.
   */
  translations?: ProductTranslations;
  status: ProductStatus;
  is_hidden: boolean;
  options_count?: number;
  skus_count?: number;
  active_skus_count?: number;
  has_default_sku_only?: boolean | null;
  /** Gallery images — only present on the show endpoint (not on list) */
  gallery?: ProductImageFile[];
  /** Lightweight thumbnail URL — first gallery image (sort_order = 0).
   *  Present on list endpoints that eager-load `galleryFirst`. */
  image_url?: string | null;
  // Workflow audit — populated by detail endpoint after approve/reject.
  rejection_reason?: string | null;
  approved_at?: string | null;
  rejected_at?: string | null;
  approved_by_id?: string | null;
  rejected_by_id?: string | null;
  review_up_count: string;
  review_total_count: string;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface ProductLookupItem {
  id: string;
  name: string;
  sku: string | null;
}

export interface ProductFilters {
  page?: number;
  per_page?: number;
  search?: string;
  status?: ProductStatus;
  product_type_id?: string;
  category_id?: string;
  is_hidden?: boolean;
  with_trashed?: boolean;
  only_trashed?: boolean;
  /**
   * Opt-in: include each product's active SKUs in the response. Used by the
   * menu builder's left panel so it can render SKUs as draggable items. The
   * plain list page does not need this.
   */
  with_skus?: boolean;
  sort?: string;
}

/**
 * Per-locale overrides for translatable fields. Locale dict shape is the
 * single source of truth from omnify's generated `BaseProductCreate` (issue
 * #53), so adding a new locale to `omnify.yaml` flows here automatically.
 *
 * Locales whose values are all empty should be omitted entirely so the API
 * does not write empty translation rows. Use `buildI18nPayload()` from
 * `@/types/models/payload-helpers` (generated, issue #55) to assemble this.
 */
import type { BaseProductCreate } from "@/types/models/base/Product";
type ProductLocaleOverrides = Pick<BaseProductCreate, "ja" | "en" | "vi">;

export interface CreateProductInput extends ProductLocaleOverrides {
  name: string;
  product_type_id: string;
  sku?: string | null;
  description?: string | null;
  status?: ProductStatus;
  is_hidden?: boolean;
  // plan-043 — null tax_type_id = inherit the brand default.
  tax_type_id?: string | null;
  category_ids?: string[];
  /** Ordered list of temp File UUIDs uploaded via POST /api/v1/files/upload.
   *  Backend attaches them as permanent `gallery` files on create. */
  gallery_file_ids?: string[];
}

/**
 * Shopify-style nested-create payload. Sent to the same
 * `POST /api/v1/hq/{brandSlug}/products` endpoint as `CreateProductInput`,
 * but with `options[]` and `skus[]` arrays added so the backend persists
 * the product, its options, its option values, and its SKUs in a single
 * DB transaction.
 *
 * Each entry in `skus.value_indices` is a 0-based index into the values
 * array of the option at the same input index. So for two options
 * `[{values:[S,M]},{values:[Red,Blue]}]`, a SKU with `value_indices=[0,1]`
 * is the (S, Blue) combo. The length of `value_indices` MUST match the
 * length of `options`.
 */
export interface CreateProductWithOptionsInput extends ProductLocaleOverrides {
  name: string;
  product_type_id: string;
  slug?: string | null;
  description?: string | null;
  status?: ProductStatus;
  is_hidden?: boolean;
  // plan-043 — null tax_type_id = inherit the brand default.
  tax_type_id?: string | null;
  category_ids?: string[];
  /** Ordered list of temp File UUIDs uploaded via POST /api/v1/files/upload.
   *  Backend attaches them as permanent `gallery` files on create. */
  gallery_file_ids?: string[];
  options: Array<{
    key: string;
    name: string;
    position: 1 | 2 | 3;
    is_active?: boolean;
    values: Array<{
      value: string;
      label: string;
      position?: number;
      is_active?: boolean;
    }>;
  }>;
  skus: Array<{
    value_indices: number[];
    sku?: string | null;
    name?: string | null;
    cost_price?: number | null;
    // The operator-entered price is the SELLING price (menu price). cost_price
    // defaults to 0 and is auto-computed later from recipe/material (issue #875).
    selling_price?: number | null;
    is_cost_override?: boolean;
    is_active?: boolean;
  }>;
}

export interface UpdateProductInput extends ProductLocaleOverrides {
  name?: string;
  slug?: string | null;
  product_type_id?: string;
  sku?: string | null;
  description?: string | null;
  status?: ProductStatus;
  is_hidden?: boolean;
  // plan-043 — null tax_type_id = inherit the brand default.
  tax_type_id?: string | null;
  category_ids?: string[];
}

/**
 * Shape returned by `POST /products/import`. Matches BE `ImportResult` —
 * `errors[].errors` is an array of strings, one per validation/lookup
 * failure for that row (e.g. "product_type_code 'BEV' not found").
 */
export interface ProductImportResult {
  success_count?: number;
  error_count?: number;
  created_count?: number;
  updated_count?: number;
  // Legacy aliases — kept so older call sites don't break.
  imported?: number;
  skipped?: number;
  errors?: Array<{ row: number; errors: string[] }>;
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/products${path}`;
}

function toParams(filters: ProductFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.status) params.set("status", filters.status);
  if (filters.product_type_id) params.set("product_type_id", filters.product_type_id);
  if (filters.category_id) params.set("category_id", filters.category_id);
  if (filters.is_hidden !== undefined) params.set("is_hidden", filters.is_hidden ? "1" : "0");
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.only_trashed) params.set("only_trashed", "1");
  if (filters.with_skus) params.set("with_skus", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const productService = {
  // --- Query ---

  list: (brandSlug: string, filters: ProductFilters = {}) =>
    apiFetch<PaginatedResponse<Product>>(
      `${brandUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  /**
   * Fetch EVERY product matching `filters`, walking all pages.
   *
   * The backend caps `per_page` at 100 (ProductController), so a single
   * request can never return a brand's full catalog once it exceeds 100
   * products. This pulls page 1 at the max page size, reads `meta.last_page`,
   * then fetches the remaining pages in parallel and concatenates them — so
   * callers (e.g. the menu-builder product picker) can search/filter over the
   * complete set client-side without silently dropping products.
   */
  listAll: async (
    brandSlug: string,
    filters: Omit<ProductFilters, "page" | "per_page"> = {}
  ): Promise<Product[]> => {
    const PER_PAGE = 100; // backend hard cap
    const first = await productService.list(brandSlug, { ...filters, page: 1, per_page: PER_PAGE });
    const lastPage = first.meta?.last_page ?? 1;
    if (lastPage <= 1) {
      return first.data;
    }

    const rest = await Promise.all(
      Array.from({ length: lastPage - 1 }, (_, i) =>
        productService.list(brandSlug, { ...filters, page: i + 2, per_page: PER_PAGE })
      )
    );

    return [first, ...rest].flatMap((response) => response.data);
  },

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug, `/${id}`)),

  lookup: (brandSlug: string) =>
    apiFetch<{ data: ProductLookupItem[] }>(brandUrl(brandSlug, "/lookup")),

  // --- Mutation ---

  create: (brandSlug: string, data: CreateProductInput) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  createWithOptions: (brandSlug: string, data: CreateProductWithOptionsInput) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateProductInput) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      brandUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),

  // --- Workflow transitions ---
  // Each call hits a dedicated endpoint so the BE can enforce the state
  // machine (see ProductService::submitForApproval / approve / reject /
  // activate / deactivate — assertStatus guards each transition).

  submitForApproval: (brandSlug: string, id: string) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug, `/${id}/submit-for-approval`), {
      method: "POST",
    }),

  approve: (brandSlug: string, id: string) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug, `/${id}/approve`), {
      method: "POST",
    }),

  reject: (brandSlug: string, id: string, rejection_reason: string) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug, `/${id}/reject`), {
      method: "POST",
      body: JSON.stringify({ rejection_reason }),
    }),

  activate: (brandSlug: string, id: string) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug, `/${id}/activate`), {
      method: "POST",
    }),

  deactivate: (brandSlug: string, id: string) =>
    apiFetch<{ data: Product }>(brandUrl(brandSlug, `/${id}/deactivate`), {
      method: "POST",
    }),

  // --- Import / Export ---

  import: async (brandSlug: string, file: File, brand_id: string): Promise<ProductImportResult> => {
    const body = new FormData();
    body.append("file", file);
    body.append("brand_id", brand_id);

    // apiFetch detects FormData and lets the browser set the multipart
    // boundary automatically — passing Content-Type ourselves would break
    // the upload.
    const json = await apiFetch<{ data?: ProductImportResult } | ProductImportResult>(
      brandUrl(brandSlug, "/import"),
      { method: "POST", body }
    );
    return (json as { data?: ProductImportResult }).data ?? (json as ProductImportResult);
  },

  /**
   * Download the import template as a CSV file. Routed through apiFetch so
   * the Bearer token is attached — a bare `<a href download>` would fail
   * with 401 because cookies-only browsers don't carry the Authorization
   * header that the BE expects.
   */
  downloadTemplate: async (brandSlug: string): Promise<void> => {
    const blob = await apiFetch(brandUrl(brandSlug, "/import/template"), {
      headers: { Accept: "text/csv" },
      responseType: "blob",
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "import_products_template.csv";
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  },

  exportCsv: async (
    brandSlug: string,
    /**
     * Same shape the list page passes to `useProducts(brandSlug, apiFilters)`.
     * Forwarded as query string so the BE exporter scopes the CSV to whatever
     * the user is looking at. Without this, the export silently dumps every
     * product in the org regardless of the current search/filter.
     *
     * Array values (e.g. `ids: ["a", "b"]`) are serialized as Laravel-style
     * repeated keys `ids[]=a&ids[]=b` so they hydrate as PHP arrays.
     */
    filters: Record<string, unknown> = {},
    /**
     * When `keepPagination` is true, `page`/`per_page` keys are preserved so
     * the BE can slice. Default (false) strips them — full filtered stream.
     */
    options: { keepPagination?: boolean } = {}
  ): Promise<string> => {
    const qs = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
      if (value === undefined || value === null || value === "") continue;
      if (Array.isArray(value)) {
        for (const v of value) {
          if (v === undefined || v === null || v === "") continue;
          qs.append(`${key}[]`, String(v));
        }

        continue;
      }
      // Booleans round-trip as "true"/"false" — Laravel's FILTER_VALIDATE_BOOLEAN
      // reads strings, not native booleans from query.
      qs.set(key, typeof value === "boolean" ? String(value) : String(value));
    }
    if (!options.keepPagination) {
      qs.delete("page");
      qs.delete("per_page");
    }

    const path = qs.size > 0 ? `/export?${qs.toString()}` : "/export";
    const blob = await apiFetch(brandUrl(brandSlug, path), {
      headers: { Accept: "text/csv" },
      responseType: "blob",
    });
    const filename = `products-${brandSlug}-${new Date().toISOString().slice(0, 10)}.csv`;

    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);

    return filename;
  },
};
