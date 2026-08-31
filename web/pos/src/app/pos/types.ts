/**
 * POS domain types — mirror the verified backend wire contract.
 *
 * Ground-truth sources (overrides beat bases; override's toArray is the
 * actual wire shape):
 *   - CustomerOrderResource.php                    → CustomerOrder
 *   - CustomerOrderItemResource.php (override)     → CustomerOrderItem
 *   - OrderPaymentResource.php (override)          → OrderPayment
 *   - PaymentMethodResource.php (passes base)      → PaymentMethod
 *   - TableResource.php (override replaces base)   → TableResource
 *
 * Decimal-cast fields come as strings on the wire (PHP decimal cast).
 * Fields loaded via whenLoaded() are marked optional on the FE type.
 */

// ===========================================================================
//  Table
// ===========================================================================

export type TableStatusValue =
  | "free"
  | "occupied"
  | "reserved"
  | "cleaning"
  | "out_of_service";

export interface TableZoneSummary {
  id: string;
  code: string;
  name: string;
}

export interface TableLastStatusChange {
  from: TableStatusValue | null;
  to: TableStatusValue;
  changed_by_id: string | null;
  changed_at: string;
}

/**
 * Matches TableResource override (app/Http/Resources/TableResource.php).
 * `zone` and `last_status_change` use whenLoaded so they may be absent
 * from the response when the controller doesn't eager-load the relation.
 */
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
  last_status_change?: TableLastStatusChange | null;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

/**
 * Compact table summary embedded in CustomerOrderResource.tables[]. See
 * CustomerOrderResource::toArray — intentionally thinner than TableResource.
 */
export interface TableSummary {
  id: string;
  code: string;
  name: string | null;
  status: TableStatusValue;
  qr_token: string;
}

/**
 * plan-043 — one per-rate tax group in an order's breakdown (インボイス
 * per-rate blocks: 8%対象 / 10%対象). Mirrors the backend `TaxGroup` wire
 * shape (`CustomerOrderResource::tax_breakdown` + `CustomerOrderDetailResource`).
 * `taxable` is the group's net base after any pro-rata coupon discount;
 * `tax` is rounded once for the whole group (端数処理は税率ごとに1回).
 */
export interface TaxBreakdownEntry {
  /** Rate percent, e.g. 8 or 10. */
  rate: number;
  /** Net taxable base for this rate group. */
  taxable: number;
  /** Tax for this rate group (rounded once, half-up to currency step). */
  tax: number;
}

// ===========================================================================
//  Customer Order (matches CustomerOrderResource override)
// ===========================================================================

export type CustomerOrderStatus =
  // Takeaway (customer-web / kiosk) orders are created at `pending` and only
  // advance to `open` once accepted at the counter — see useOpenOrders, which
  // includes `pending` so a fresh takeaway order shows up immediately.
  | "pending"
  // Counter-pay takeaway from customer-web: the guest reviewed the cart at
  // /order-confirm and submitted. Cloud stamps `confirmed` (see
  // CustomerOrderController::createBranchOrder — `confirmed` when the branch
  // requires payment before prep and the guest didn't pay online).
  // `awaiting_confirmation` is the pre-submit draft state. Both were missing
  // from this union AND from the open-orders filter, so a counter-pay
  // customer-web order was invisible at the POS entirely.
  | "awaiting_confirmation"
  | "confirmed"
  | "open"
  | "dining"
  | "checkout"
  | "paying"
  | "closed"
  | "voided"
  // Cloud auto-cancels an unpaid order past its payment window. The workstation
  // LAN list only excludes closed/voided by default, so an expired order can
  // reach these views — it needs a badge even though no filter asks for it.
  | "expired";

export type CustomerOrderType = "spot" | "dine_in" | "takeaway";

/**
 * Menu service type (#463 / #481). Matches the backend enum accepted by
 * `Shop\MenuController::validatedServiceType`. `Both` = valid for either
 * flow. A menu with a NULL service_type inherits from its master (HQ) —
 * that resolution happens server-side.
 */
export type MenuServiceType = "DineIn" | "Takeaway" | "Both";

export type OrderItemStatus =
  | "pending"
  | "preparing"
  | "ready"
  | "served"
  | "voided";

/**
 * Minimal product reference carried inside ProductSkuRef when the backend
 * eager-loads items.productSku.product. POS only reads the name for the
 * cart line label ("Product name — SKU name").
 */
export interface ProductRef {
  id: string;
  name: string;
  /**
   * Primary product photo — the cart's secondary thumbnail source when the
   * per-SKU `ProductSkuRef.image_url` is null (the backend pins both to the
   * product's first gallery row, so this is the correct fallback).
   */
  image_url?: string | null;
}

/**
 * ProductSku reference embedded in CustomerOrderItem.product_sku. Comes
 * from ProductSkuResource when eager-loaded. Plan-007 POS eager-loads
 * items.productSku.product via CustomerOrderService::findById so the
 * cart has everything it needs to render a proper line without a
 * follow-up fetch.
 */
export interface ProductSkuRef {
  id: string;
  product_id: string;
  /** SKU variant name, e.g. "Tô đặc biệt". */
  name: string | null;
  /** SKU code, e.g. "PHO-DB". */
  sku: string | null;
  /** Current selling price on the SKU (not the menu-override). */
  selling_price: number | string;
  is_active: boolean;
  /** Eager-loaded product for the combined display name. */
  product?: ProductRef;
  /**
   * URL of the SKU's first gallery image when the backend eager-loads
   * `galleryFirst` (POS cart does this in CustomerOrderService::findById).
   * Used for the cart line thumbnail. Null when the SKU has no gallery.
   */
  image_url?: string | null;
}

/**
 * Matches CustomerOrderItemResource override — base schema fields plus
 * snake-cased relation keys. `product_sku` is present only when the
 * controller eager-loads `items.productSku` (all POS flows do, via
 * CustomerOrderService::findById).
 */
export interface CustomerOrderItem {
  id: string;
  customer_order_id: string;
  product_sku_id: string;
  quantity: number | string;
  /** Decimal — arrives as a string from the backend's decimal cast. */
  unit_price: number | string;
  /**
   * Plan 015 — per-unit topping cost (sum of `unit_price` × `qty` per
   * selection within each group, after applying `free_up_to_n` discount).
   * `subtotal = quantity × (unit_price + topping_subtotal)`.
   */
  topping_subtotal: number | string;
  subtotal: number | string;
  status: OrderItemStatus;
  note: string | null;
  served_at: string | null;
  voided_at: string | null;
  void_reason: string | null;
  /**
   * Denormalized display-name snapshot stamped at add-item time (workstation
   * emits it as both `menu_item_name` and `name`; format may be
   * "Product · Variant"). This is the ONLY name that survives a product being
   * deleted or the menu re-synced — which orphans the item's `product_sku_id`
   * so `product_sku` comes back absent. Without reading this, such lines render
   * as "Món không xác định". See `orderItemDisplayName`.
   */
  menu_item_name?: string | null;
  name?: string | null;
  /** Variant-name snapshot (e.g. "Tô đặc biệt"), likewise add-time frozen. */
  sku_variant_name?: string | null;
  product_sku?: ProductSkuRef;
  /**
   * plan-043 — per-line consumption-tax snapshot (軽減税率 / インボイス),
   * stamped by the pricing engine at add-item time and immutable thereafter.
   * `tax_rate` is the resolved percent (e.g. 8 or 10); `null` on legacy /
   * unstamped lines (old clients / pre-plan-043 orders). `tax_amount` is the
   * per-line tax at that rate. The split calculator groups a bill's items by
   * `tax_rate` and rounds tax once per group.
   */
  tax_rate?: number | string | null;
  tax_amount?: number | string;
  /** plan-043 — the resolved TaxType id (null = inherited / legacy). */
  tax_type_id?: string | null;
  /**
   * plan-045 / #2159 — refund back-link. Set ⇒ THIS row is a refund line, not
   * a sellable line: `quantity` is negative and `unit_price` stays positive.
   *
   * Cả hai đường lấy đơn đều đã trả trường này từ lâu — workstation LAN
   * (`customer_order_shape.go`, `customerOrderItemShape`) và Cloud fallback
   * (`CustomerOrderItemResourceBase`) — client chỉ chưa bao giờ đọc.
   *
   * **Dùng trường NÀY làm discriminator, đừng dùng `is_refund`.** Chỉ
   * workstation phát `is_refund`; qua đường Cloud-fallback nó là `undefined`,
   * nên một nhánh `if (item.is_refund)` sẽ im lặng sai đúng lúc mạng LAN hỏng
   * — tức đúng lúc không ai đang nhìn.
   */
  refund_of_item_id?: string | null;
  /**
   * plan-045 / #2159 — số suất của dòng NÀY đã được hoàn. Chỉ được CỘNG vào,
   * không bao giờ trừ ra, và `quantity` **không** giảm theo. Nên số suất còn
   * bán được là `quantity − refunded_quantity`, không phải `quantity`.
   */
  refunded_quantity?: number | string | null;
  /** Plan 015 — chosen toppings for this line. Empty / undefined when none. */
  toppings?: OrderItemTopping[];
  /**
   * plan-019 — when a Happy Hour promotion auto-applied at addItem, the
   * pre-discount price is frozen here so the receipt + cart can render
   * strikethrough.
   */
  original_unit_price?: number | string | null;
  /** plan-019 — id of the MenuPromotion that drove the unit_price reduction. */
  applied_promotion_id?: string | null;
  /** plan-019 — snapshot of promotion name + percent at apply time. */
  applied_promotion_snapshot?: {
    name?: string;
    discount_percent?: number;
    stacking_mode?: "exclusive_with_coupons" | "stackable_with_coupons";
  } | null;
  created_at: string | null;
  updated_at: string | null;
}

/**
 * Compact relation summaries CustomerOrderResource eager-loads when the
 * controller opts in. Narrow to the fields the POS actually renders.
 */
export interface CustomerSummary {
  id: string;
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
}

/**
 * Matches CustomerOrderResource override (app/Http/Resources/CustomerOrderResource.php).
 * The override starts from schemaArray, drops deprecated `payment_method`
 * and `paid_at`, computes `remaining_amount`, and adds `tables` behind
 * whenLoaded. Nested relations are likewise whenLoaded — the POS flows
 * all go through CustomerOrderService::findById which eager-loads
 * customer, items.productSku, payments.paymentMethod, tables.
 */
export interface CustomerOrder {
  id: string;
  order_code: string;
  order_type: CustomerOrderType;
  status: CustomerOrderStatus;
  subtotal: number | string;
  discount_amount: number | string;
  service_charge: number | string;
  tax_amount: number | string;
  /**
   * plan-043 — tax mode snapshot. When true the item prices already include
   * consumption tax (総額表示 / 内税); the per-rate `tax` in `tax_breakdown`
   * is then a display-only extraction not added on top of the total.
   */
  is_tax_included?: boolean;
  /**
   * plan-043 — per-rate tax breakdown (8%対象 / 10%対象 blocks), present when
   * the order's items are eager-loaded (all POS flows). Voided lines are
   * excluded. Additive — absent on stale clients / bare creates → treat as [].
   */
  tax_breakdown?: TaxBreakdownEntry[];
  /**
   * plan-045 option-B — the order's tax-rounding snapshot. `tax_amount` (+ the
   * per-rate `tax_breakdown[].tax`) carry sub-unit precision (e.g. 93.50 on a
   * JPY shop with tax_rounding_decimals=2) for DISPLAY; `total_amount` stays
   * whole-yen (payable). The gap surfaces as a 端数調整 row. Decimals drives how
   * many fraction digits the tax figures render with.
   */
  tax_rounding_mode?: string | null;
  tax_rounding_decimals?: number | null;
  total_amount: number | string;
  paid_amount: number | string;
  total_tip: number | string;
  /** Computed server-side: max(0, total_amount - paid_amount). Always a string. */
  remaining_amount: string;
  /**
   * #2049 — ĐANG TREO: quán chưa nhận đủ tiền của đơn này. Đơn treo KHÔNG được
   * in biên lai hay hoá đơn đỏ, và workstation từ chối cứng bằng 409
   * `order_on_hold`; cờ này chỉ để giao diện đừng mời người ta bấm một nút chắc
   * chắn hỏng.
   *
   * BA trạng thái, và `null` KHÔNG phải `false`:
   *   `true`   treo
   *   `false`  không treo
   *   `null` / `undefined`  **bề mặt này không trả lời**. Cloud chỉ đóng dấu ở
   *           các đường ĐỌC (`GET /orders`, `GET /orders/{id}`); đường LAN của
   *           workstation thì luôn trả `true`/`false` vì nó đã tự kết luận rồi.
   *
   * Đừng gộp `null` thành `false`: nó biến "chưa ai hỏi" thành "đơn in được".
   * Xem `mergeOnHold` trong `lib/on-hold.ts` cho luật hợp nhất.
   */
  is_on_hold?: boolean | null;
  /** Lý do treo (`open_debt` | `part_paid`), `null` khi không treo/chưa biết. */
  on_hold_reason?: "open_debt" | "part_paid" | null;
  opened_at: string | null;
  checkout_at: string | null;
  closed_at: string | null;
  voided_at: string | null;
  void_reason: string | null;
  guest_count: number | null;
  note: string | null;
  /**
   * Takeaway contact captured at order time — a walk-in takeaway customer who
   * gave a name / phone without being a saved `customer` record. Present on
   * both the LAN shape (customerOrderShape) and Cloud (CustomerOrderResource);
   * null for dine-in / spot. The takeaway drawer shows these as the order's
   * identity, falling back to the linked `customer` relation.
   */
  customer_takeaway_name?: string | null;
  customer_takeaway_phone?: string | null;
  stock_out_transaction_id: string | null;
  created_by_id: string | null;
  customer_account_id: string | null;
  customer_id: string | null;
  branch_id: string;
  brand_id: string;
  organization_id: string;
  /** plan-019 — coupon applied to this order (nullable). */
  coupon_id?: string | null;
  /** plan-019 — frozen coupon code at apply time for receipt history. */
  coupon_code_snapshot?: string | null;
  /** Coupon discount amount + when it was applied — from order_coupons, for
   *  the table-history detail (workstation shape). */
  coupon_discount?: number | string | null;
  coupon_applied_at?: string | null;
  /** The coupon's own terms (workstation joins the `coupons` replica): its
   *  name, whether it's a fixed amount or a percentage, the value (amount or
   *  percent), and the max cap for percent coupons. Best-effort — absent if the
   *  coupon row was deleted. */
  coupon_name?: string | null;
  coupon_discount_type?: "fixed" | "percent" | null;
  coupon_discount_value?: number | string | null;
  coupon_max_discount_cap?: number | string | null;
  /** whenLoaded — present on findById + list queries, absent on bare creates. */
  tables?: TableSummary[];
  /** whenLoaded — present on findById. */
  items?: CustomerOrderItem[];
  /** whenLoaded — present on findById. */
  payments?: OrderPayment[];
  /** whenLoaded — present when list() eager-loads 'customer'. */
  customer?: CustomerSummary;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

// ===========================================================================
//  Payment
// ===========================================================================

export type PaymentStatus = "pending" | "succeeded" | "failed" | "refunded";

export interface PaymentMethodRef {
  id: string;
  code: string;
  name: string;
}

/**
 * Matches OrderPaymentResource override. IDs are always on the wire so
 * clients don't need the eager-loaded relation for bookkeeping. The
 * `payment_method` relation uses whenLoaded — present when the query
 * includes `payments.paymentMethod`.
 */
export interface OrderPayment {
  id: string;
  payment_code: string;
  customer_order_id: string;
  payment_method_id: string;
  payment_method?: PaymentMethodRef;
  amount: number | string;
  tip_amount: number | string;
  status: PaymentStatus;
  tendered_amount: number | string | null;
  change_amount: number | string | null;
  reference_no: string | null;
  /** #1156 — brand attribution stamped at checkout (nullable, optional on older backends). */
  tender_key?: string | null;
  paid_at: string | null;
  note: string | null;
  refund_of_id: string | null;
  received_by_id: string | null;
  created_at: string | null;
}

/**
 * Response of `GET /shops/{shopSlug}/orders/{orderId}/split-bill`.
 * Endpoint is a calculator: returns per-person amounts derived from
 * `remaining = total_amount - paid_amount`, with rounding remainder
 * absorbed by the first person. No payments are created here — staff
 * still POSTs `/payments` per person to actually take the money.
 *
 * All money fields are PHP-decimal strings (e.g. "334.00").
 */
export interface SplitBillResponse {
  total_amount: string;
  remaining_amount: string;
  split_count: number;
  per_person_amount: string;
  per_person_amounts: string[];
  rounding_note: string | null;
}

/**
 * Matches PaymentMethodResource (passes through to base schemaArray).
 * `name` arrives as a flat localized string via the Translatable trait;
 * raw per-locale values live under `translations`.
 */
export interface PaymentMethod {
  id: string;
  code: string;
  name: string;
  /**
   * Behavioural class of the method — `cash` | `card` | `transfer` | `qr` |
   * `on_account` | … Lives outside the Omnify schema (plan-038 migration), so
   * PaymentMethodResource adds it explicitly. Optional because a workstation
   * running an older build can still answer without it.
   */
  type?: string | null;
  is_auto_confirm: boolean;
  requires_tendered: boolean;
  is_active: boolean;
  sort_order: number;
  branch_id: string | null;
  organization_id: string;
  translations: Record<string, string>;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

/** Plan 047 T6.1 — resolver-backed effective payment option for POS checkout. */
export interface EffectivePaymentOptionClientCapabilities {
  requires_tendered: boolean;
  immediate_settlement: boolean;
  supports_pos_checkout: boolean;
}

export interface EffectivePaymentOption {
  id: string;
  display_name: string;
  provider: string;
  rail: string;
  method_type: string | null;
  effective: boolean;
  source: string;
  reason: string;
  error_code: string | null;
  connection_id: string | null;
  connection_option_id: string | null;
  shop_option_id: string | null;
  shop_preference: string;
  device_preference: string;
  legacy_payment_method_id: string | null;
  legacy_payment_method_code: string | null;
  // Optional at the type level as defense-in-depth: Cloud always sends it, but
  // a workstation LAN binary that predates the client-block mirror fix returns
  // options without it. Readers MUST guard (`option.client?.…`) so a lagging
  // workstation degrades to "no checkout methods" instead of white-screening
  // the whole POS. See checkoutCapableOptions / effectiveOptionToPaymentMethod.
  client?: EffectivePaymentOptionClientCapabilities;
}

export interface EffectivePaymentOptionsEnvelope {
  revision: number;
  snapshot_hash: string | null;
  ownership_revision: string;
  published_at?: string | null;
  options: EffectivePaymentOption[];
}

// ===========================================================================
//  Shop Menu (unchanged — consumed by shop-menu-service)
// ===========================================================================

export type MenuStatus =
  | "Draft"
  | "Pending"
  | "Approved"
  | "Active"
  | "Inactive"
  | "Rejected";

export interface ShopMenuProductSkuSummary {
  id: string;
  name?: string | null;
  sku?: string | null;
  cost_price?: string | number | null;
}

/**
 * One file (image/video) attached to a product's gallery. Backend
 * `ProductResource.gallery` eager-loads these when the shop menu-products
 * endpoint is hit, so POS can render a carousel per card without a
 * follow-up fetch.
 */
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
  /**
   * Backend `product_type.code` (e.g. 'FOOD', 'DRINK', 'combo'). Lower-case
   * `'combo'` is the canonical combo marker — see BrandCoreCatalogService.
   * Present only when the menu endpoint eager-loaded `product.productType`;
   * falls back to undefined on stale clients.
   */
  product_type_code?: string | null;
  /** Thumbnail (first gallery image resolved server-side). */
  image_url?: string | null;
  /** Full gallery for the order picker carousel. */
  gallery?: ProductGalleryItem[];
  /**
   * Plan 015 — topping groups attached to this product, scoped to the
   * shop's local time on the read path. Empty array when no groups apply.
   * Group order matches `product_topping_groups.sort_order`. Items inside
   * each group order by `topping_group_items.sort_order`.
   */
  topping_groups?: ShopMenuToppingGroup[];
  /**
   * #1099 — the product's effective consumption-tax rate, resolved server-side
   * (menu-item type → branch default → brand default). ONE rate: which one
   * applies is decided by the menu the item sits on, not by the order type.
   * Used to render the 税込 / 税抜 menu price. Null when nothing resolves (fresh
   * org) or a stale client that predates the field.
   */
  tax_rate?: number | null;
}

// =============================================================================
//  Topping types — Plan 015
// =============================================================================

/** Mirrors enum values: `flat` charges every selection at full extra_price;
 *  `free_up_to_n` waives the most expensive `free_quantity` selections,
 *  charges the rest. See ToppingPricingService. */
export type ToppingPriceStrategy = "flat" | "free_up_to_n";

/** `add` extras (mayo, cheese) carry extra_price; `remove` modifiers
 *  (no onion) always carry extra_price=0 by convention. */
export type ToppingModifierType = "add" | "remove";

/** Single-pick (radio) vs multi-pick (checkbox group). When `single`,
 *  `max_select === 1` is enforced server-side too. */
export type ToppingSelectionType = "single" | "multiple";

export interface ShopMenuToppingItemSku {
  id: string;
  topping_group_item_id: string;
  /** May be null for the simple-topping fallback row. */
  product_sku_id: string | null;
  /** Decimal — arrives as a string from the backend's decimal cast. */
  extra_price: string;
  sku_label?: string | null;
  sku_code?: string | null;
}

export interface ShopMenuToppingGroupItem {
  id: string;
  topping_group_id: string;
  product_id: string;
  /** Localized name from the parent topping product. */
  name?: string | null;
  /**
   * 1:1 thumbnail URL resolved from the parent topping product's
   * `galleryFirst`. Only present when the BE eager-loaded that relation;
   * `null` when the product has no gallery image.
   */
  image_url?: string | null;
  is_default: boolean;
  sort_order: number;
  skus?: ShopMenuToppingItemSku[];
}

export interface ShopMenuToppingGroup {
  id: string;
  name: string;
  selection_type: ToppingSelectionType;
  modifier_type: ToppingModifierType;
  price_strategy: ToppingPriceStrategy;
  /** Required for `free_up_to_n`; null otherwise. */
  free_quantity: number | null;
  min_select: number;
  /** null = unlimited. */
  max_select: number | null;
  max_qty_per_item: number;
  /**
   * `effective_min_select` / `effective_max_select` are folded server-side
   * from the per-product pivot's `*_select_override` (COALESCE), so
   * frontend can use these directly without re-applying the override.
   */
  effective_min_select: number;
  effective_max_select: number | null;
  sort_order?: number;
  /** HH:MM strings; null when always-on. */
  available_from: string | null;
  available_to: string | null;
  /** ISO weekday integers (1=Mon … 7=Sun); null when always-on. */
  available_days: number[] | null;
  is_active: boolean;
  items?: ShopMenuToppingGroupItem[];
}

/**
 * One topping selection sent in the addItems payload. Mirrors the
 * `items.*.toppings.*` shape the Laravel FormRequest accepts.
 *
 * `product_sku_id` is NOT NULL by Phase 2 contract — frontend resolves
 * it from the chosen ShopMenuToppingItemSku before submit.
 */
export interface ToppingSelection {
  topping_group_item_id: string;
  product_sku_id: string;
  quantity: number;
  note?: string | null;
}

/** Snapshotted topping returned on each CustomerOrderItem after a write. */
export interface OrderItemTopping {
  id: string;
  topping_group_id?: string | null;
  topping_group_name?: string | null;
  topping_group_item_id: string;
  product_sku_id: string;
  name?: string | null;
  modifier_type?: ToppingModifierType | null;
  quantity: number;
  /** Decimal — arrives as a string from the backend's decimal cast. */
  unit_price: string;
  note: string | null;
}

export interface ShopMenuProductSku {
  id: string;
  menu_product_id: string;
  /**
   * #1320 — set ONLY on a SKU that came from the spotlight ("Khung giờ ưu đãi"),
   * never on a menu SKU. It is the membership id
   * (`pos_floating_section_products.id`) the workstation hands out on
   * `GET /api/v1/pos/floating-sections`, and it is what tells the add-item path
   * which surface this line was sold from.
   *
   * Why it cannot ride in `id`: for a menu line `id` IS the MenuProductSku, and
   * the payload sends it as `menu_product_sku_id` so the backend pins the price
   * to that menu's override. A spotlight id in that field would name a row in a
   * different table — the lookup misses and the line silently prices off
   * something else. Two tables, two fields.
   */
  floating_section_product_id?: string;
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
  default_price?: number | null;
  product_sku?: ShopMenuProductSkuSummary | null;
}

export interface ShopMenuProductSectionRef {
  id: string;
  name: string;
}

export interface ShopMenuSection {
  id: string;
  name: string;
  display_order?: number;
}

export interface ShopMenuProduct {
  id: string;
  menu_id: string;
  product_id: string;
  menu_section_id: string | null;
  /** Eager-loaded section name — present when the backend includes the relation. */
  section?: ShopMenuProductSectionRef | null;
  is_active: boolean;
  display_order: number;
  skus?: ShopMenuProductSku[];
  product?: ShopMenuProductSummary | null;
  /**
   * plan-019 — overlay surfaced by `MenuPromotionService::resolveActivePromotionsForMenu`.
   * Present when the product is inside an active Happy Hour window.
   */
  active_promotion?: ActivePromotionBlock | null;
}

export interface ActivePromotionBlock {
  id: string;
  discount_percent: number;
  discounted_price: number;
  ends_at: string;
  stacking_mode: "exclusive_with_coupons" | "stackable_with_coupons";
}

export interface ShopMenuResource {
  id: string;
  name: string;
  description: string | null;
  status: MenuStatus;
  menu_products_count?: number;
  menu_products?: ShopMenuProduct[];
  /** Sections attached to this menu — present on the detail endpoint. */
  menu_sections?: ShopMenuSection[];
  /**
   * #1756 — the menu's OWN service type. Nullable on Cloud, where `null` means
   * "inherit the HQ master menu's type" and is therefore NOT displayable on its
   * own; prefer `effective_service_type`. On the workstation LAN feed this
   * field already carries the resolved value (Cloud's MenuCatalogReplicaBuilder
   * collapses the inheritance before the mirror stores it), and
   * `effective_service_type` is absent.
   *
   * Read both through `resolveMenuServiceType()` rather than either directly.
   */
  service_type?: MenuServiceType | null;
  /**
   * #1756 — Cloud-only: own value, else the master's, else "Both". Absent when
   * the caller didn't arrange for it (or against a backend deployed before
   * #1756) — absent must render as NO badge, never as a guess.
   */
  effective_service_type?: MenuServiceType | null;
}

/** Query filters for `GET /api/v1/pos/menus`. */
export interface ShopMenuFilters {
  search?: string;
  status?: MenuStatus;
  page?: number;
  per_page?: number;
  /**
   * #481 — gate menus by the active order's service type (DineIn / Takeaway
   * / Both). Absent → no gate (every menu), preserving old callers.
   */
  service_type?: MenuServiceType;
}

/** Query filters for `GET /api/v1/pos/menus/{menu}/products`. */
export interface ShopMenuProductFilters {
  search?: string;
  /**
   * #3163 — lấy món của MỘT section. `"none"` là nhóm chưa xếp.
   *
   * `"none"` phải tường minh: bỏ trống trường này đã mang nghĩa "mọi section",
   * nên không còn cách nào khác để hỏi riêng nhóm đó — mà món chưa xếp vẫn
   * phải bán được.
   */
  section_id?: string;
  /**
   * #3163 — tra MỘT món theo SKU, cho luồng SỬA MÓN.
   *
   * Hôm nay luồng đó dựa vào việc cả thực đơn đã nằm sẵn trong bộ nhớ POS. Khi
   * lưới thôi tải hết, đây là đường còn lại để sửa một món đã đặt.
   */
  sku_id?: string;
  page?: number;
  per_page?: number;
}

/**
 * #3163 — một section trong thanh pill, kèm SỐ MÓN.
 *
 * Tên KHÁC `ShopMenuSection` (dòng ~724) có chủ đích: cái kia là hình dạng SUY
 * RA từ món đã tải (`{id, name}`, id luôn có), còn cái này là HÀNG API — id
 * `null` cho nhóm chưa xếp, kèm số đếm của backend. Đặt trùng tên thì TypeScript
 * GỘP hai khai báo lại và báo lỗi ở chỗ thứ ba, xa hẳn nguyên nhân.
 *
 * `id === null` là nhóm CHƯA XẾP; gọi lại bằng `section_id: "none"`.
 *
 * Đường này rẻ và LUÔN ĐỦ — chi phí không phụ thuộc số món — nên thanh pill
 * đúng dù menu to cỡ nào. Đó là điều mà việc đọc cả thực đơn không cho được:
 * menu 89 dòng nặng 638 KB một vòng, và query có `refetchInterval` 60 giây.
 */
export interface ShopMenuSectionSummary {
  id: string | null;
  name: string | null;
  display_order: number;
  products_count: number;
}

/**
 * Response row for `GET /api/v1/pos/menus/by-day/{dayOfWeek}`.
 * Extends ShopMenuResource with the start/end of the highest-priority
 * MenuSchedule matching the requested day. Times arrive as PHP TIME
 * strings ("HH:MM:SS") — format on the FE before showing to staff.
 */
export interface ShopMenuByDayResource extends ShopMenuResource {
  /** "HH:MM:SS" — start of the highest-priority active schedule for the day. */
  start_time: string;
  /** "HH:MM:SS" — end of the highest-priority active schedule for the day. */
  end_time: string;
}

/** Query filters for `GET /api/v1/pos/menus/by-day/{dayOfWeek}`. */
export interface ShopMenuByDayFilters {
  search?: string;
  per_page?: number;
  /** #481 — gate menus by the active order's service type. See ShopMenuFilters. */
  service_type?: MenuServiceType;
}

// ===========================================================================
//  Plan-021 — Split-bill by-items
// ===========================================================================

/** Mode flag lifted to SplitBillDialog so confirm payload knows which body to build.
 *
 * Plan-038 T3.2 adds `"by_amount"` — split by free-form amount per person
 * (Σ amounts === order.total, no per-item allocation). Slip prints just the
 * label + amount, no item list.
 *
 * #2860 — ba giá trị này là TỪ VỰNG DUY NHẤT của chia bill, chung với backend
 * (`App\Services\Order\Enums\OrderSplitMode`), kiosk, customer-web và
 * workstation. Trước đó pos-web nói `equal` còn customer-web nói `by_people`
 * cho cùng một chế độ, và hai bên giao nhau đúng một giá trị.
 *
 * Thêm một chế độ ở đây mà không thêm ở enum backend thì `validate()` trả 422 —
 * đó là chỗ nó sẽ vỡ, không phải ở TypeScript.
 */
export type SplitMode = "even" | "by_items" | "by_amount";

// ===========================================================================
//  Plan-038 — Split-bill by-amount-per-person
// ===========================================================================

/** Per-row state for the "Theo số tiền" tab. */
export interface PersonBillByAmount {
  bill_index: number;
  total_bills: number;
  /** Editable label; defaults to `"Người 1"`, `"Người 2"`, … */
  label: string;
  /** Currency minor unit; e.g. 100000 for 100,000 đ. */
  amount: number;
  /** PaymentMethod.id chosen for this row (null before pick). */
  method_id: string | null;
  /** Per-row idempotency key — UUID v4 minted once at row spawn. */
  idempotency_key: string;
  /** UI status — drives row CTA enablement + spinner. */
  status: "draft" | "submitting" | "paid" | "failed";
  /** Inline error surfaced under the row when a Thu attempt fails. */
  errorMessage?: string | null;
  /**
   * Server payment id captured when this row is paid — the per-guest key the
   * receipt dialog toggles/prints on. Must be UNIQUE per row (sharing one id
   * across guests makes selecting one select all). Null until paid.
   */
  paymentId?: string | null;
  /** HH:MM captured the moment this row's payment landed. Null until paid. */
  paidAt?: string | null;
  /**
   * Cash this guest handed over, as typed. `null`/absent = untouched → the
   * row's exact amount is tendered. Only meaningful while the chosen method
   * has `requires_tendered`; reset on method change.
   */
  tenderRaw?: string | null;
  /** What was actually posted as tendered/change — kept for the receipt. */
  tendered?: number;
  change?: number;
}

/** Top-level state for the "Theo số tiền" tab. Snapshot-stable per row. */
export interface SplitByAmountState {
  rows: PersonBillByAmount[];
}

/**
 * Allocation of a single line item across people. `units` is per-physical-unit:
 * - For `quantity === 1` items, the array has length 1 (`[personIndex | null]`).
 * - For `quantity > 1`, each slot is the personIndex (0-based) that gets that
 *   unit, or `null` while still unassigned.
 * Topping cost on a multi-unit line is distributed evenly across units
 * (documented limitation — see plan-021 DESIGN.md Decision 4).
 */
export interface ItemAllocation {
  itemId: string;
  units: Array<number | null>;
}

/** Top-level state for the "Chia theo món" tab. */
export interface SplitByItemsState {
  /** ≥ 1. Default seeded from `order.guest_count ?? 2`. UI clamps to [1, 20]. */
  people: number;
  /** Keyed by `CustomerOrderItem.id`. Voided items are pre-filtered out. */
  allocations: Record<string, ItemAllocation>;
}

/** Per-bill breakdown materialized from `state.allocations` + `order.items`. */
export interface PersonBill {
  /** 0-based — `state.people` produces bills [0..people-1]. */
  index: number;
  /** Display label — "Người {N}" / "Person {N}" / "{N}人目". Built FE-side. */
  label: string;
  /** Same-item entries are compacted so the receipt prints "Phở × 2" not 2 rows. */
  itemsBreakdown: Array<{
    item: CustomerOrderItem;
    /** How many units of `item` were assigned to this bill. */
    units: number;
    /**
     * `(unit_price + topping_subtotal) × units`, integer JPY.
     *
     * NOT divided by the line quantity: `topping_subtotal` is per unit.
     */
    subtotal: number;
  }>;
  subtotal: number;
  /** Proportional cut of `order.discount_amount`. */
  discount: number;
  /** subtotal − discount. The base for tax/service. */
  taxableBase: number;
  /** Σ per-rate group tax for this bill (rounded once per rate group). */
  tax: number;
  service: number;
  total: number;
  /**
   * plan-043 — per-rate tax groups within THIS bill (インボイス). Each bill
   * groups its own items by their snapshot `tax_rate` and rounds tax once per
   * group, so a mixed-rate bill (bentō 8% + beer 10%) is taxed correctly.
   * Sorted by rate ascending. `tax` above is Σ of these. A single-rate bill
   * has one entry; an empty bill has [].
   */
  taxBreakdown: TaxBreakdownEntry[];
  /** True when nothing was allocated to this person — skip during payment loop. */
  isEmpty: boolean;
}

/** Output of `splitByItems`. */
export interface SplitByItemsResult {
  bills: PersonBill[];
  /** Units that still need assignment — drives "Còn N unit chưa gán" counter. */
  unassignedUnits: Array<{ itemId: string; unitIndex: number }>;
  /** Σ bills[i].total — used by tests + the dialog footer sanity check. */
  totalCheck: number;
}
