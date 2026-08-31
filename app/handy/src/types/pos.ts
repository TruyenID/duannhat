/**
 * POS domain types — synced from pos-web/src/app/pos/types.ts
 * Ground truth: backend Resource PHP files.
 */

// ===========================================================================
//  Table
// ===========================================================================

export type TableStatusValue =
  | 'free'
  | 'occupied'
  | 'reserved'
  | 'cleaning'
  | 'out_of_service';

export interface TableZoneSummary {
  id: string;
  code: string;
  name: string;
}

export interface TableResource {
  id: string;
  code: string;
  name: string | null;
  seat_count: number;
  status: TableStatusValue;
  is_active: boolean;
  qr_token: string;
  current_order_id: string | null;
  zone?: TableZoneSummary;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

export interface TableSummary {
  id: string;
  code: string;
  name: string | null;
  status: TableStatusValue;
  qr_token: string;
}

// ===========================================================================
//  Customer Order
// ===========================================================================

export type CustomerOrderStatus =
  | 'open'
  | 'dining'
  | 'checkout'
  | 'paying'
  | 'closed'
  | 'voided';

export type CustomerOrderType = 'spot' | 'dine_in' | 'takeaway';

export type OrderItemStatus =
  | 'pending'
  | 'preparing'
  | 'ready'
  | 'served'
  | 'voided';

export interface ProductRef {
  id: string;
  name: string;
}

export interface ProductSkuRef {
  id: string;
  product_id: string;
  /** SKU variant name e.g. "小", "並", "特上". */
  name: string | null;
  sku: string | null;
  selling_price: number | string;
  is_active: boolean;
  product?: ProductRef;
  image_url?: string | null;
}

export interface CustomerOrderItem {
  id: string;
  customer_order_id: string;
  product_sku_id: string;
  quantity: number | string;
  unit_price: number | string;
  topping_subtotal: number | string;
  subtotal: number | string;
  status: OrderItemStatus;
  note: string | null;
  served_at: string | null;
  voided_at: string | null;
  void_reason: string | null;
  product_sku?: ProductSkuRef;
  toppings?: OrderItemTopping[];
  // #1099 — per-line consumption-tax snapshot. OPTIONAL: older payloads
  // (before the tax engine) omit these; the UI falls back to the single
  // order-level tax line. One rate per line — the menu the customer ordered
  // from decides which.
  tax_type_id?: string | null;
  tax_rate?: number | string | null;
  tax_amount?: number | string | null;
  original_unit_price?: number | string | null;
  applied_promotion_id?: string | null;
  applied_promotion_snapshot?: {
    name?: string;
    discount_percent?: number;
  } | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface CustomerOrder {
  id: string;
  order_code: string;
  order_type: CustomerOrderType;
  status: CustomerOrderStatus;
  subtotal: number | string;
  discount_amount: number | string;
  service_charge: number | string;
  tax_amount: number | string;
  // plan-043 — per-rate breakdown (8%対象 / 10%対象) + tax mode snapshot.
  // OPTIONAL: absent on payloads from an old cloud/workstation build — the UI
  // then renders the single `tax_amount` line.
  is_tax_included?: boolean;
  tax_breakdown?: { rate: number; taxable: number; tax: number }[];
  total_amount: number | string;
  paid_amount: number | string;
  total_tip: number | string;
  remaining_amount: string;
  opened_at: string | null;
  checkout_at: string | null;
  closed_at: string | null;
  voided_at: string | null;
  void_reason: string | null;
  guest_count: number | null;
  note: string | null;
  branch_id: string;
  brand_id: string;
  tables?: TableSummary[];
  items?: CustomerOrderItem[];
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

// ===========================================================================
//  Toppings — Plan 015
// ===========================================================================

export type ToppingPriceStrategy = 'flat' | 'free_up_to_n';
export type ToppingModifierType = 'add' | 'remove';
export type ToppingSelectionType = 'single' | 'multiple';

export interface ShopMenuToppingItemSku {
  id: string;
  topping_group_item_id: string;
  product_sku_id: string | null;
  /** Decimal string from backend — use parseFloat() before arithmetic. */
  extra_price: string;
  /** True when this SKU row is hidden for the current product context (HQ override). */
  is_hidden?: boolean;
  sku_label?: string | null;
  sku_code?: string | null;
}

export interface ShopMenuToppingGroupItem {
  id: string;
  topping_group_id: string;
  product_id: string;
  name?: string | null;
  image_url?: string | null;
  is_default: boolean;
  /** True when this item is hidden for the current product context (HQ override). */
  is_hidden?: boolean;
  sort_order: number;
  skus?: ShopMenuToppingItemSku[];
}

export interface ShopMenuToppingGroup {
  id: string;
  name: string;
  selection_type: ToppingSelectionType;
  modifier_type: ToppingModifierType;
  price_strategy: ToppingPriceStrategy;
  free_quantity: number | null;
  min_select: number;
  max_select: number | null;
  effective_min_select: number;
  effective_max_select: number | null;
  is_active: boolean;
  items?: ShopMenuToppingGroupItem[];
}

/** Sent in POST /orders/{id}/items body */
export interface ToppingSelection {
  topping_group_item_id: string;
  product_sku_id: string;
  quantity: number;
  note?: string | null;
  // Enriched fields for workstation printer — ignored by cloud API
  name?: string;
  modifier_type?: ToppingModifierType;
  unit_price?: number;
  topping_group_id?: string;
  topping_group_name?: string;
}

/** Snapshot returned on each CustomerOrderItem */
export interface OrderItemTopping {
  id: string;
  topping_group_id?: string | null;
  topping_group_name?: string | null;
  topping_group_item_id: string;
  product_sku_id: string;
  name?: string | null;
  modifier_type?: ToppingModifierType | null;
  quantity: number;
  unit_price: string;
  note: string | null;
}

// ===========================================================================
//  Shop Menu
// ===========================================================================

export type MenuStatus =
  | 'Draft'
  | 'Pending'
  | 'Approved'
  | 'Active'
  | 'Inactive'
  | 'Rejected';

/** Compact SKU info embedded inside ShopMenuProductSku.product_sku */
export interface ShopMenuProductSkuSummary {
  id: string;
  /** SKU variant name e.g. "小", "並", "特上". */
  name?: string | null;
  sku?: string | null;
  cost_price?: string | number | null;
}

export interface ProductGalleryItem {
  id: string;
  url: string;
  original_name: string | null;
  mime_type: string | null;
  sort_order: number | null;
}

export interface ShopMenuProductSummary {
  id: string;
  name: string;
  slug?: string | null;
  description?: string | null;
  status?: string | null;
  /** e.g. 'FOOD', 'DRINK', 'combo' — lowercase combo is the canonical combo marker */
  product_type_code?: string | null;
  image_url?: string | null;
  gallery?: ProductGalleryItem[];
  /** Plan 015 — topping groups attached to this product. */
  topping_groups?: ShopMenuToppingGroup[];
}

/**
 * One SKU row inside ShopMenuProduct.skus[].
 * id = menu_product_sku_id (used in addItems payload as menu_product_sku_id).
 * product_sku?.name is the display label (e.g. "小", "並", "特上").
 */
export interface ShopMenuProductSku {
  id: string;                               // menu_product_sku_id
  menu_product_id: string;
  product_sku_id: string;
  selling_price: number;
  is_price_overridden: boolean;
  is_active: boolean;
  default_price?: number | null;
  /** Eager-loaded — carries name/sku for display. */
  product_sku?: ShopMenuProductSkuSummary | null;
}

export interface ShopMenuSection {
  id: string;
  name: string;
  display_order?: number;
}

export interface ShopMenuProductSectionRef {
  id: string;
  name: string;
}

export interface ActivePromotionBlock {
  id: string;
  discount_percent: number;
  discounted_price: number;
  ends_at: string;
  stacking_mode: 'exclusive_with_coupons' | 'stackable_with_coupons';
}

export interface ShopMenuProduct {
  id: string;
  menu_id: string;
  product_id: string;
  menu_section_id: string | null;
  section?: ShopMenuProductSectionRef | null;
  is_active: boolean;
  display_order: number;
  skus?: ShopMenuProductSku[];
  product?: ShopMenuProductSummary | null;
  active_promotion?: ActivePromotionBlock | null;
}

export interface ShopMenuResource {
  id: string;
  name: string;
  description: string | null;
  status: MenuStatus;
  menu_products_count?: number;
  menu_products?: ShopMenuProduct[];
  menu_sections?: ShopMenuSection[];
}

/**
 * Row returned by GET /menus/by-day/{dayOfWeek}.
 * Extends ShopMenuResource with schedule times.
 */
export interface ShopMenuByDayResource extends ShopMenuResource {
  start_time: string;   // "HH:MM:SS"
  end_time: string;     // "HH:MM:SS"
}

// ===========================================================================
//  Input bodies
// ===========================================================================

export interface OrderCreateInput {
  order_type?: CustomerOrderType;
  table_ids?: string[];
  guest_count?: number;
  note?: string;
}

export interface OrderInitInput {
  table_ids?: string[];
  guest_count?: number;
}

export interface OrderItemInput {
  product_sku_id: string;
  menu_product_sku_id?: string;
  quantity: number;
  /** Unit price including promotion discount and topping surcharges (integer, e.g. 45500). */
  selling_price?: number;
  note?: string;
  toppings?: ToppingSelection[];
}

export interface OrderItemUpdateInput {
  quantity?: number;
  note?: string | null;
  status?: Exclude<OrderItemStatus, 'voided'>;
}

// ===========================================================================
//  Shop / Settings
// ===========================================================================

export interface ShopDetail {
  id: string;
  slug: string;
  name: string;
  brand_id: string;
}

export interface ShopOrderSettings {
  currency_code: string;
  tax_rate: string;
  service_charge_rate: string;
  enable_quick_order: boolean;
  default_order_item_status: OrderItemStatus | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  links?: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    from?: number | null;
    last_page: number;
    per_page: number;
    to?: number | null;
    total: number;
  };
}

// ===========================================================================
//  Cart (local state — not an API type)
// ===========================================================================

export interface CartItem {
  product_sku_id: string;
  menu_product_sku_id: string;
  /** Display name: product.name only (no SKU variant suffix) */
  name: string;
  /** SKU variant name e.g. "Regular", "Large" — separate from product name */
  sku_variant_name?: string;
  selling_price: number;
  quantity: number;
  note?: string;
  toppings?: ToppingSelection[];
  /** Human-readable topping lines, e.g. ["+ 牛肉 追加 ×2", "- ねぎ"] */
  topping_labels?: string[];
}
