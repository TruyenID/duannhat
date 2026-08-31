/**
 * Shop domain types — Zone, Table, TableStatusChange.
 *
 * These are hand-written types that layer on top of the Omnify-generated
 * bases in `./models/base/Zone.ts`, `./models/base/Table.ts`, and
 * `./models/base/TableStatusChange.ts` (DO NOT edit those).
 *
 * This file lives outside `./models/` because the `models/` directory is
 * reserved for the Omnify-generated wrappers. All shop-domain service/hook
 * code should import from here.
 */

import type { Zone as ZoneBase } from "./models/base/Zone";
import type { Table as TableBaseModel } from "./models/base/Table";
import type { TableStatusChange as TableStatusChangeBase } from "./models/base/TableStatusChange";
import { TableStatus } from "./models/enum/TableStatus";

export { TableStatus };

// ============================================================================
// TableStatus union (string literal form of the enum)
// ============================================================================

/**
 * String-literal union of every `TableStatus` value. Prefer the `TableStatus`
 * enum itself when writing new code; this union is provided for ergonomic
 * payload typing (e.g. `changeStatus({ status: "cleaning" })`).
 */
export type TableStatusValue = "free" | "occupied" | "reserved" | "cleaning" | "out_of_service";

// ============================================================================
// Zone
// ============================================================================

/** Full Zone domain type (extends Omnify base with derived API fields). */
export interface Zone extends ZoneBase {
  /** Number of tables in this zone — present on the list/show resource. */
  table_count?: number;
}

/** Shape of a Zone as returned by the backend ZoneResource JSON. */
export interface ZoneResource {
  id: string;
  code: string;
  name: string;
  description: string | null;
  display_order: number;
  is_active: boolean;
  /** issue #890 / BR-Z04: non-null ⇒ copied from the HQ default layout (shop cannot delete). */
  zone_template_id?: string | null;
  table_count?: number;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface CreateZoneInput {
  code: string;
  name: string;
  description?: string | null;
  display_order?: number;
  is_active?: boolean;
}

export interface UpdateZoneInput {
  code?: string;
  name?: string;
  description?: string | null;
  display_order?: number;
  is_active?: boolean;
}

export interface ZoneFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  with_trashed?: boolean;
  sort?: string;
}

export interface ZoneLookupItem {
  id: string;
  code: string;
  name: string;
}

// ============================================================================
// Table
// ============================================================================

/** Embedded zone on a TableResource. */
export interface TableZoneSummary {
  id: string;
  code: string;
  name: string;
}

/** Last status change summary embedded on a TableResource. */
export interface TableLastStatusChange {
  from: TableStatusValue | null;
  to: TableStatusValue;
  changed_by_id: string | null;
  changed_at: string;
}

/**
 * Full Table domain type. Omits `zone` from the Omnify base (which is the
 * full Zone type) and replaces it with the lightweight summary shape that
 * the backend TableResource embeds.
 */
export interface Table extends Omit<TableBaseModel, "zone"> {
  zone?: TableZoneSummary;
  last_status_change?: TableLastStatusChange | null;
}

/** Shape of a Table as returned by the backend TableResource JSON. */
export interface TableResource {
  id: string;
  code: string;
  name: string | null;
  seat_count: number;
  status: TableStatusValue;
  is_active: boolean;
  qr_token: string;
  /** issue #890 / BR-T09: non-null ⇒ copied from the HQ default layout (shop cannot delete). */
  table_template_id?: string | null;
  zone: TableZoneSummary;
  last_status_change: TableLastStatusChange | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface CreateTableInput {
  zone_id: string;
  code: string;
  name?: string | null;
  seat_count?: number;
  status?: TableStatusValue;
  is_active?: boolean;
}

export interface UpdateTableInput {
  zone_id?: string;
  code?: string;
  name?: string | null;
  seat_count?: number;
  is_active?: boolean;
}

export interface ChangeTableStatusInput {
  status: TableStatusValue;
  note?: string;
}

export interface TableFilters {
  page?: number;
  per_page?: number;
  search?: string;
  zone_id?: string;
  status?: TableStatusValue;
  is_active?: boolean;
  with_trashed?: boolean;
  sort?: string;
}

// ============================================================================
// TableStatusChange
// ============================================================================

export interface TableStatusChange extends TableStatusChangeBase {
  changed_by?: {
    id: string;
    name: string;
  } | null;
}

export interface TableStatusChangeResource {
  id: string;
  table_id: string;
  from_status: TableStatusValue | null;
  to_status: TableStatusValue;
  changed_by_id: string | null;
  changed_by: { id: string; name: string } | null;
  changed_at: string;
}

// ============================================================================
// Shop Menu — mirrors the real `/api/v1/shops/{shopSlug}/menus` API surface
// ============================================================================
//
// IMPORTANT: the previous flat `ShopMenuItemResource` model was wrong.
// The backend exposes a 3-level hierarchy:
//
//   Menu
//    └─ MenuProduct (one row per product in the menu)
//        └─ MenuProductSku (one row per variant; price + active toggle live here)
//
// Shop staff can:
//   - toggle a whole MenuProduct active/inactive
//   - toggle an individual MenuProductSku active/inactive
//   - override / reset the per-shop selling_price of a single SKU
//   - sync newly-added master products into the branch menu
//
// There is no "availability" enum on the backend — only `is_active: boolean`.

export type MenuStatus = "Draft" | "Pending" | "Approved" | "Active" | "Inactive" | "Rejected";

/**
 * Translated option value embedded on a ProductSku. `label` + `option.name`
 * come back already localized via Astrotomic's `Accept-Language` fallback,
 * so callers can render them as-is without touching translation payloads.
 */
export interface ShopMenuProductOptionValueRef {
  id: string;
  option_id: string;
  value: string;
  label: string;
  position?: number;
  option?: { id: string; key: string; name: string } | null;
}

/** Embedded product sku summary on a ShopMenuProductSku. */
export interface ShopMenuProductSkuSummary {
  id: string;
  /** Sku display name (often the variant label, e.g. "Large"). */
  name?: string | null;
  /** Sku code / barcode. */
  sku?: string | null;
  cost_price?: string | number | null;
  /** Canonical HQ selling price. Backend ProductSkuResource always includes
   *  this when productSku is eager-loaded — use as the single source of truth
   *  for "Giá HQ" on the shop-side UI. */
  selling_price?: number | null;
  /** First gallery image of the SKU (sort_order = 0). Resolves to `null` when
   *  the SKU has no images — callers should fall back to the parent product's
   *  image_url (simple SKUs share the product gallery). */
  image_url?: string | null;
  option_value1?: ShopMenuProductOptionValueRef | null;
  option_value2?: ShopMenuProductOptionValueRef | null;
  option_value3?: ShopMenuProductOptionValueRef | null;
}

/** A single image attached to a product gallery. */
export interface ProductGalleryImage {
  id: string;
  url: string;
  sort_order: number;
  original_name?: string;
}

// =========================================================================
//  Topping group types — loaded by MenuService.loadToppingsForMenu (Plan 015)
// =========================================================================

/** Per-SKU price row for a topping option (topping_group_item_skus table). */
export interface MenuToppingGroupItemSku {
  id: string;
  topping_group_item_id: string;
  product_sku_id: string | null;
  /** Decimal string — effective price after 3-tier override resolution. */
  extra_price: string;
  /** Decimal string — HQ base price BEFORE any override. Use this for the
   *  "default price" column so a saved override never overwrites the base. */
  base_extra_price?: string;
  /** True when this SKU row is hidden by an active override. */
  is_hidden?: boolean;
  /**
   * Catalog-level availability of the underlying variant
   * (product_skus.is_active). False = the HQ disabled this SKU, so the
   * customer menu hides it — the admin badge must reflect that too.
   * Wildcard rows (product_sku_id null) are always true.
   */
  is_active?: boolean;
  sku_label?: string | null;
  sku_code?: string | null;
  /**
   * Does this row still price anything?
   *
   * Only ever false for a wildcard row (`product_sku_id: null`) whose every
   * active SKU has since gained a scoped row of its own — the resolver sorts
   * NULL last, so it never reaches the wildcard. Such a row was drawn as an
   * ordinary line in the variants table, which is how an operator read
   * "2 variants" off a topping that sells one (#1275 → #1316).
   *
   * Backend defaults it to true whenever it could not compute the answer, so a
   * working price row is never labelled dead. Treat `undefined` as true.
   */
  applies?: boolean;
}

/** One option inside a topping group (topping_group_items table). */
export interface MenuToppingGroupItem {
  id: string;
  topping_group_id: string;
  product_id: string;
  name?: string | null;
  image_url?: string | null;
  is_default: boolean;
  /** True when this item is hidden by an active override (HQ or shop level). */
  is_hidden?: boolean;
  sort_order: number;
  skus?: MenuToppingGroupItemSku[];
}

/**
 * One row in the shop-level topping override table
 * (menu_product_topping_item_overrides).
 */
export interface MenuProductToppingItemOverride {
  id: string;
  menu_product_id: string;
  topping_group_id: string;
  topping_group_item_id: string;
  product_sku_id: string | null;
  is_hidden: boolean;
  override_price: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface ShopToppingOverrideSyncRow {
  topping_group_item_id: string;
  product_sku_id: string | null;
  is_hidden: boolean;
  override_price: number | null;
}

/** A topping group attached to a product via product_topping_groups pivot. */
export interface MenuToppingGroup {
  id: string;
  name: string;
  selection_type: string;
  modifier_type: string;
  price_strategy: string;
  min_select: number;
  max_select: number | null;
  max_qty_per_item: number;
  effective_min_select: number;
  effective_max_select: number | null;
  sort_order?: number;
  is_active: boolean;
  items?: MenuToppingGroupItem[];
}

/** Embedded product summary on a ShopMenuProduct. */
export interface ShopMenuProductSummary {
  id: string;
  name: string;
  slug?: string | null;
  description?: string | null;
  status?: string | null;
  /** First gallery image (sort_order = 0). Convenience field. */
  image_url?: string | null;
  /** All gallery images, ordered by sort_order. Eager-loaded by the shop
   *  menu show endpoint so the order picker can render a carousel. */
  gallery?: ProductGalleryImage[];
  /** Topping groups eager-loaded by MenuService.loadToppingsForMenu (Plan 015). */
  topping_groups?: MenuToppingGroup[];
}

/** A single SKU/variant row inside a MenuProduct on the shop side. */
export interface ShopMenuProductSku {
  id: string;
  menu_product_id: string;
  product_sku_id: string;
  selling_price: number;
  /** Live sale price after an active Floating Section is applied. */
  effective_selling_price?: number | null;
  active_floating_section?: {
    floating_section_id: string;
    name: string;
    price: number;
  } | null;
  is_price_overridden: boolean;
  is_active: boolean;
  /** Default selling price from the canonical productSku, if loaded. */
  default_price?: number | null;
  product_sku?: ShopMenuProductSkuSummary | null;
  created_at: string;
  updated_at: string;
}

/**
 * Section embedded on a ShopMenuProduct. Single object (not array) — each
 * (product, section) pair is its own menu_products row.
 */
export interface ShopMenuProductSectionRef {
  id: string;
  name: string;
}

/** A brand-level section row attached to a ShopMenuResource. */
export interface ShopMenuSection {
  id: string;
  name: string;
  /** Optional — present when the backend exposes the pivot order. */
  display_order?: number;
}

/** A product row inside a Menu on the shop side. */
export interface ShopMenuProduct {
  id: string;
  menu_id: string;
  product_id: string;
  /** FK to menu_sections. A menu_product belongs to at most one section. */
  menu_section_id: string | null;
  is_active: boolean;
  display_order: number;
  master_menu_product_id: string | null;
  /**
   * Embedded section (id + name) when eager-loaded. Same product in N
   * sections = N separate menu_products rows, each with its own `section`.
   */
  section?: ShopMenuProductSectionRef | null;
  skus?: ShopMenuProductSku[];
  product?: ShopMenuProductSummary | null;
  /**
   * #1227 — the rate this line is actually billed at, already resolved by the
   * backend across all six tiers (item → section → menu → product → branch →
   * brand). Deliberately NOT derivable here: the shop response also carries the
   * per-tier ids, but re-walking them client-side would drift from the receipt
   * the moment a tier changed. Null when nothing resolves (brand with no tax
   * types) or on responses that do not stamp it.
   */
  effective_tax_rate?: number | null;
  created_at: string;
  updated_at: string;
}

/** Shape of a Menu as returned by the shop-side MenuResource JSON. */
export interface ShopMenuResource {
  id: string;
  name: string;
  description: string | null;
  status: MenuStatus;
  priority: number;
  valid_from: string | null;
  valid_to: string | null;
  is_master: boolean;
  master_menu_id: string | null;
  branch_id: string | null;
  /** Count of products in the menu (top-level) — present on list responses. */
  menu_products_count?: number;
  /** Eager-loaded products (with their skus) on the detail / sync responses. */
  menu_products?: ShopMenuProduct[];
  /** Canonical Laravel relationship key returned by MenuResource. */
  menuProducts?: ShopMenuProduct[];
  /**
   * Sections attached to this menu via the `menu_menu_sections` pivot.
   * Only present on the detail response (show endpoint). Ordered by pivot
   * `display_order` ascending. Includes empty sections (no products).
   */
  menuSections?: ShopMenuSection[];
  /**
   * #1227 — the whole-menu tax tier's rate, or null when the menu sets none and
   * lines fall through to their section/product. Detail response only.
   */
  tax_rate?: number | null;
  /**
   * #1227 — each section's own rate for THIS menu, keyed by menu_section_id.
   * A section is shared across menus and keeps a separate rate in each, so this
   * cannot live on the section object. null value = that section sets none.
   */
  section_tax_rates?: Record<string, number | null>;
  /** Cart-timeout inheritance chain — only present on the detail (show) response. */
  hq_brand_timeout_minutes: number | null;
  hq_menu_timeout_minutes: number | null;
  shop_default_timeout_minutes: number | null;
  shop_menu_timeout_minutes: number | null;
  effective_timeout_minutes: number | null;
  /**
   * Service-type inheritance chain (#463) — only present on the detail (show)
   * response. `shop_service_type` null = inherit the master (HQ) menu's type;
   * `effective_service_type` is the resolved value the customer sees.
   */
  hq_service_type: "Takeaway" | "DineIn" | "Both" | null;
  shop_service_type: "Takeaway" | "DineIn" | "Both" | null;
  effective_service_type: "Takeaway" | "DineIn" | "Both" | null;
  /** Whether the menu has at least one schedule row. Present on detail responses. */
  has_schedules?: boolean;
  created_at: string;
  updated_at: string;
}

export interface ShopMenuFilters {
  /** Free-text search by menu name (server-side LIKE %term%). */
  search?: string;
  /** Defaults to "Active" on the backend if omitted. */
  status?: MenuStatus;
  page?: number;
  per_page?: number;
}

export interface ShopMenuProductFilters {
  search?: string;
  page?: number;
  per_page?: number;
}

export interface UpdateMenuSkuPriceInput {
  selling_price: number;
}
