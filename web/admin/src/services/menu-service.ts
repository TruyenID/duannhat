/**
 * Menu Service — pure TypeScript, no React dependency.
 *
 * All API calls for the Menu domain.
 * URL convention: /api/v1/hq/{brandSlug}/menus/...
 *
 * The React-Query layer lives in src/hooks/api/use-menus.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { MenuSection } from "@/services/menu-section-service";

// =========================================================================
//  Types
// =========================================================================

export type MenuStatus = "Draft" | "Pending" | "Approved" | "Active" | "Inactive" | "Rejected";

/** #463 — which customer ordering flow a menu shows in. */
export type MenuServiceType = "Takeaway" | "DineIn" | "Both";

export interface Menu {
  id: string;
  organization_id: string;
  brand_id: string;
  branch_id: string | null;
  name: string;
  description: string | null;
  translations?: Record<string, { name?: string | null; description?: string | null }>;
  status: MenuStatus;
  service_type: MenuServiceType;
  /**
   * #1218 tier 3 — one tax type for the WHOLE menu. null = inherit (product →
   * branch default → brand default).
   *
   * Non-optional here on purpose: this endpoint always serialises the column,
   * while the generated `Menu` type marks it optional because the schema allows
   * null. (Until omnify 5.9.20 the generated field was also mis-named
   * `taxType_id` after the association rather than the column — that generator
   * bug is fixed, so this declaration no longer works around anything.)
   */
  tax_type_id: string | null;
  priority: number;
  valid_from: string | null;
  valid_to: string | null;
  is_master: boolean;
  master_menu_id: string | null;
  // The relation serialises CAMEL-cased (`masterMenu`) even though its FK
  // column does not (`master_menu_id`) — the exact mismatch the note above
  // describes. This was declared `master_menu` and so never matched the
  // payload; nothing read it, which is why the dead key went unnoticed.
  masterMenu?: { id: string; name: string } | null;
  last_synced_at: string | null;
  menu_products_count?: number;
  cloned_menus_count?: number;
  menu_products?: MenuProduct[];
  menuSections?: MenuSection[];
  has_schedules: boolean | null;
  cart_timeout_minutes: number | null;
  hq_brand_timeout_minutes: number | null;
  created_by_id: string | null;
  approved_by_id: string | null;
  approved_at: string | null;
  rejected_by_id: string | null;
  rejected_at: string | null;
  rejection_reason: string | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface MenuProductSku {
  id: string;
  menu_product_id: string;
  product_sku_id: string;
  selling_price: number;
  is_price_overridden: boolean;
  is_active: boolean;
  default_price?: number | null;
  product_sku?: {
    id: string;
    sku: string | null;
    name: string | null;
    cost_price: string;
    selling_price?: string;
  } | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

/**
 * Section embedded on a MenuProduct. Single object (not array) — each
 * (product, section) pair is its own menu_products row.
 */
export interface MenuProductSectionRef {
  id: string;
  name: string;
}

export interface MenuProduct {
  id: string;
  menu_id: string;
  product_id: string;
  /**
   * Menu-item tax override (#1099, resolver tier 1). null = inherit from the
   * product, then the branch default, then the brand default.
   *
   * This is how a takeaway menu charges the reduced rate: a tax type is ONE
   * rate, so the consumption context is decided by WHICH menu line the customer
   * ordered from, not by a per-order-type rate pair.
   */
  tax_type_id: string | null;
  /** FK to menu_sections. A menu_product belongs to at most one section. */
  menu_section_id: string | null;
  is_active: boolean;
  display_order: number;
  master_menu_product_id: string | null;
  /**
   * Embedded section summary (id + name) when the `menuSection` relation
   * is eager-loaded. A product that appears in N sections has N separate
   * menu_products rows, each with its own `section`.
   */
  section?: MenuProductSectionRef | null;
  skus?: MenuProductSku[];
  product?: {
    id: string;
    name: string;
    slug: string | null;
    description: string | null;
    status: string;
    image_url?: string | null;
    categories?: { id: string; name: string }[];
    skus?: {
      id: string;
      name: string | null;
      sku: string | null;
      cost_price: string;
      is_active: boolean;
    }[];
  } | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface MenuLookupItem {
  id: string;
  name: string;
  status: MenuStatus;
  branch_id: string | null;
}

export interface MenuFilters {
  page?: number;
  per_page?: number;
  search?: string;
  status?: MenuStatus;
  branch_id?: string;
  master_menu_id?: string;
  is_master?: boolean;
  with_trashed?: boolean;
  sort?: string;
}

export interface CreateMenuInput {
  branch_id?: string;
  name: string;
  description?: string | null;
  service_type?: MenuServiceType;
  valid_from?: string | null;
  valid_to?: string | null;
  status?: MenuStatus;
  is_master?: boolean;
  product_ids?: string[];
  ja?: Record<string, string>;
  en?: Record<string, string>;
  vi?: Record<string, string>;
}

export interface CreateMasterMenuInput {
  name: string;
  description?: string | null;
  service_type?: MenuServiceType;
  ja?: Record<string, string>;
  en?: Record<string, string>;
  vi?: Record<string, string>;
}

export interface MasterMenuLookupItem {
  id: string;
  name: string;
}

export interface CheckSyncResult {
  new_products: { id: string; name: string }[];
}

export interface UpdateMenuInput {
  updated_at?: string;
  branch_id?: string;
  name?: string;
  description?: string | null;
  service_type?: MenuServiceType;
  valid_from?: string | null;
  valid_to?: string | null;
  priority?: number;
  cart_timeout_minutes?: number | null;
  ja?: Record<string, string>;
  en?: Record<string, string>;
  vi?: Record<string, string>;
}

export interface AddProductsInput {
  product_ids: string[];
  menu_section_id?: string | null;
}

export interface ReorderProductsInput {
  ordered_ids: string[];
}

export interface ReorderMenusInput {
  branch_id: string;
  menu_ids: string[];
}

export interface ReorderMasterMenusInput {
  menu_ids: string[];
}

export interface SyncLayoutItem {
  section_name: string;
  product_ids: string[];
}

export interface SyncLayoutInput {
  menu_items: SyncLayoutItem[];
}

export interface CloneToBranchInput {
  branch_id: string;
  name?: string;
  description?: string;
  valid_from?: string | null;
  valid_to?: string | null;
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/menus${path}`;
}

function masterMenuUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/master-menus${path}`;
}

function toParams(filters: MenuFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.status) params.set("status", filters.status);
  if (filters.branch_id) params.set("branch_id", filters.branch_id);
  if (filters.master_menu_id) params.set("master_menu_id", filters.master_menu_id);
  if (filters.is_master !== undefined) params.set("is_master", filters.is_master ? "1" : "0");
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const menuService = {
  // --- Query (read) ---

  list: (brandSlug: string, filters: MenuFilters = {}) =>
    apiFetch<PaginatedResponse<Menu>>(
      `${brandUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${id}`)),

  lookup: (brandSlug: string) =>
    apiFetch<{ data: MenuLookupItem[] }>(brandUrl(brandSlug, "/lookup")),

  // --- Mutation (write) ---

  create: (brandSlug: string, data: CreateMenuInput) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateMenuInput) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      brandUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),

  /**
   * Set (or clear) a menu item's tax-type override.
   *
   * `null` clears it — the line then inherits product → branch → brand.
   */
  updateProductTaxType: (
    brandSlug: string,
    menuId: string,
    menuProductId: string,
    taxTypeId: string | null
  ) =>
    apiFetch<{ data: MenuProduct }>(
      brandUrl(brandSlug, `/${menuId}/products/${menuProductId}/tax-type`),
      { method: "PATCH", body: JSON.stringify({ tax_type_id: taxTypeId }) }
    ),

  /**
   * #1218 tier 3 — set (or clear) the tax type for a WHOLE menu.
   *
   * `null` clears it and every line falls through to the product. This sits
   * ABOVE the product by ruling: the menu wins, and a menu-wide 8% does
   * override a product marked tax-exempt. To keep one item exempt inside a
   * taxed menu, give that menu line its own override — that is tier 1 and still
   * wins.
   */
  updateMenuTaxType: (brandSlug: string, menuId: string, taxTypeId: string | null) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${menuId}/tax-type`), {
      method: "PATCH",
      body: JSON.stringify({ tax_type_id: taxTypeId }),
    }),

  /**
   * #1218 tier 2 — set (or clear) the tax type for one section IN THIS MENU.
   *
   * Stored on the menu↔section pivot, so the same section keeps its own value
   * in every other menu that shows it. `null` inherits from the menu.
   */
  updateSectionTaxType: (
    brandSlug: string,
    menuId: string,
    menuSectionId: string,
    taxTypeId: string | null
  ) =>
    apiFetch<{ data: Menu }>(
      brandUrl(brandSlug, `/${menuId}/sections/${menuSectionId}/tax-type`),
      { method: "PATCH", body: JSON.stringify({ tax_type_id: taxTypeId }) }
    ),

  // --- State Transitions ---

  submit: (brandSlug: string, id: string) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${id}/submit`), {
      method: "POST",
    }),

  approve: (brandSlug: string, id: string) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${id}/approve`), {
      method: "POST",
    }),

  reject: (brandSlug: string, id: string, reason: string) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${id}/reject`), {
      method: "POST",
      body: JSON.stringify({ rejection_reason: reason }),
    }),

  activate: (brandSlug: string, id: string) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${id}/activate`), {
      method: "POST",
    }),

  deactivate: (brandSlug: string, id: string) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${id}/deactivate`), {
      method: "POST",
    }),

  // --- Menu Products ---

  addProducts: (brandSlug: string, menuId: string, data: AddProductsInput) =>
    apiFetch<{ data: MenuProduct[] }>(brandUrl(brandSlug, `/${menuId}/products`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  removeProduct: (brandSlug: string, menuId: string, menuProductId: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${menuId}/products/${menuProductId}`), {
      method: "DELETE",
    }),

  toggleProduct: (brandSlug: string, menuId: string, menuProductId: string) =>
    apiFetch<{ data: MenuProduct }>(
      brandUrl(brandSlug, `/${menuId}/products/${menuProductId}/toggle`),
      { method: "POST" }
    ),

  reorderProducts: (brandSlug: string, menuId: string, data: ReorderProductsInput) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${menuId}/products/reorder`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  reorderMenus: (brandSlug: string, data: ReorderMenusInput) =>
    apiFetch<{ message: string }>(brandUrl(brandSlug, "/reorder"), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  syncLayout: (brandSlug: string, menuId: string, data: SyncLayoutInput) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${menuId}/layout`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  // --- Master Menu Operations ---

  cloneToBranch: (brandSlug: string, masterMenuId: string, data: CloneToBranchInput) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${masterMenuId}/clone-to-branch`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  syncFromMaster: (brandSlug: string, menuId: string) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${menuId}/sync-from-master`), {
      method: "POST",
    }),

  duplicate: (brandSlug: string, menuId: string) =>
    apiFetch<{ data: Menu }>(brandUrl(brandSlug, `/${menuId}/duplicate`), {
      method: "POST",
    }),

  checkSync: (brandSlug: string, menuId: string) =>
    apiFetch<{ data: CheckSyncResult }>(brandUrl(brandSlug, `/${menuId}/check-sync`)),

  // --- Current active menu for a branch ---

  currentForBranch: (brandSlug: string, branchId: string) =>
    apiFetch<{ data: Menu | null }>(
      `${brandUrl(brandSlug)}/current?branch_id=${encodeURIComponent(branchId)}`
    ),

  // --- Master Menus (separate resource path) ---

  listMasterMenus: (brandSlug: string) => apiFetch<{ data: Menu[] }>(masterMenuUrl(brandSlug)),

  createMasterMenu: (brandSlug: string, data: CreateMasterMenuInput) =>
    apiFetch<{ data: Menu }>(masterMenuUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  lookupMasterMenus: (brandSlug: string) =>
    apiFetch<{ data: MasterMenuLookupItem[] }>(masterMenuUrl(brandSlug, "/lookup")),

  reorderMasterMenus: (brandSlug: string, data: ReorderMasterMenusInput) =>
    apiFetch<{ message: string }>(masterMenuUrl(brandSlug, "/reorder"), {
      method: "PUT",
      body: JSON.stringify(data),
    }),
};
