// src/types/kiosk.ts

export interface OrderItemExtra {
  label: string;
  /** Per-unit price of the extra. Multiply by `quantity` (default 1) for the
   *  line total. */
  price: number;
  /** Number of units of this modifier on the parent item. Optional so legacy
   *  payloads / cached responses default to 1. */
  quantity?: number;
  /** "add" (default) prints "+ label" with a positive price; "remove"
   *  prints "− label" with a negative price (matches workstation receipt
   *  rendering). Optional so legacy payloads default to "add". */
  modifier_type?: 'add' | 'remove';
}

/** plan-043 — one per-rate row of the consumption-tax breakdown. `taxable` is
 * the net base (net of the extracted 内税 when prices include tax); `tax` is the
 * once-per-group tax for the rate. Mirrors the backend KioskOrderResource shape. */
export interface KioskTaxBreakdownRow {
  rate: number;
  taxable: number;
  tax: number;
}

export interface OrderItem {
  id: string;
  /** Display name resolved server-side: sku.name → product.name (localized) → "(unknown)". */
  name: string;
  /** Always the product name (localized vi/ja/en), independent of sku.name. */
  product_name?: string;
  /** SKU code, e.g. "PV-1234-AB" (product_skus.sku). */
  sku_code?: string;
  quantity: number;
  unit_price: number;
  image_url?: string;
  extras?: OrderItemExtra[];
  note?: string;
  /** Units already paid for (by-items split). Server-authoritative; omitted by
   *  endpoints that don't compute claims. */
  paid_units?: number;
  /** Units still unpaid = quantity − paid_units. Server-authoritative. */
  remaining_units?: number;
}

export interface Order {
  id: string;
  table_id: string;
  table_name: string;
  items: OrderItem[];
  subtotal: number;
  service_charge?: number;
  tax_amount?: number;
  /** Branch tax rate as a percentage (e.g. 10 for 10%). Was hardcoded on
   * the kiosk side before; the BE pricing service uses this exact value
   * to compute `tax_amount`, so the kiosk label should mirror it. */
  tax_rate?: number;
  /** plan-043 — 総額表示 mode: true = prices already INCLUDE tax (税込),
   * false = tax is added on top (税抜). Drives the tax-line label so the
   * customer knows whether the shown total already contains the tax. Both the
   * workstation LAN and Cloud kiosk endpoints ship this. */
  is_tax_included?: boolean;
  /** plan-043 — per-rate consumption-tax breakdown. Present when the order
   * spans multiple rates (e.g. 8% food + 10% alcohol); the bill then shows one
   * line per rate. Falls back to the single `tax_amount` line when absent /
   * empty (older payloads, single-rate orders). */
  tax_breakdown?: KioskTaxBreakdownRow[] | null;
  /** Branch service charge rate as a percentage (e.g. 5 for 5%). */
  service_charge_rate?: number;
  /** @deprecated use tax_amount — kept for backwards compatibility */
  tax?: number;
  discount: number;
  total: number;
  currency: string;
  /** Total amount already paid for this order (0 or omitted if none). */
  paid_amount?: number;
  /** Amount still owed = total − paid_amount. Server-authoritative; omitted by
   *  endpoints that don't track payments (kiosk falls back to total − paid). */
  remaining_amount?: number;
  /** #377 — split-bill mode the customer-web (or POS) recorded. When
   * present the kiosk skips its own chooser screen and routes straight
   * into the matching allocation flow. */
  split_mode?: 'by_people' | 'by_items' | 'custom' | null;
  /** True after a real payment lands on the order — the mode can't be
   * switched anymore (would corrupt the allocation). */
  split_mode_locked?: boolean;
  /** plan-039 follow-up — headcount pre-declared by customer-web in the
   * counter-pay flow. Paired with `split_mode === "by_people"`; when set
   * the kiosk skips `/split/people` entirely and seeds the payment-flow
   * provider directly. NULL when the customer did not pre-declare. */
  split_people_count?: number | null;
  /** Convenience field server-computed as `total / split_people_count`
   * (rounded to 2 dp). The kiosk currently derives it client-side via
   * `perPersonAmount`, but having it here helps audit-log reconciliation
   * line up with the customer-web display. */
  amount_per_person?: number | null;
}

export type PaymentMethod = 'cash' | 'qr' | 'card' | 'emoney';

export type SplitMode = 'full' | 'split_even' | 'custom' | 'by_items';

/** A by-items allocation: paying for `units` of order item `item_id`. */
export interface ItemAllocation {
  item_id: string;
  units: number;
}

export interface PaymentPayload {
  order_id: string;
  method: PaymentMethod;
  amount: number;
  /** Cash tendered by the customer. Required by the backend for methods with
   *  `requires_tendered` (cash); must be >= amount. Backend derives change. */
  tendered_amount?: number;
  idempotency_key?: string;
  metadata?: {
    split_mode: 'equal' | 'by_items';
    bill_index?: number;
    total_bills?: number;
    label?: string;
    expected_total_amount?: number;
    /** by_items only: the order items + units covered by this sub-check.
     *  The backend preview sums these across non-failed payments to track
     *  which units are already claimed. */
    item_allocations?: ItemAllocation[];
  };
}

// ---------------------------------------------------------------------------
//  By-items split preview (GET /kiosk/orders/{id}/split-by-items/preview)
//  Money fields arrive as decimal strings (number_format) — parse with Number.
// ---------------------------------------------------------------------------

export interface SplitPreviewClaim {
  payment_id: string;
  bill_index: number;
  units: number;
  status: string;
}

export interface SplitPreviewItem {
  item_id: string;
  product_sku_id: string;
  quantity: number;
  units_claimed: number;
  units_remaining: number;
  claims: SplitPreviewClaim[];
}

export interface SplitPreviewBill {
  bill_index: number;
  subtotal: string;
  discount: string;
  tax: string;
  service: string;
  total: string;
  is_empty: boolean;
}

export interface SplitByItemsPreview {
  order_id: string;
  total_amount: string;
  allocated_amount: string;
  remaining_amount: string;
  rounding_mode: string;
  rounding_step: string;
  currency_code: string;
  items: SplitPreviewItem[];
  /** Present only when a candidate `allocations` query was supplied. */
  preview_bills?: SplitPreviewBill[];
}

// Canonical backend payment vocabulary (Go API). The kiosk speaks the SAME
// status names as the server — no client-side renaming — so the status from
// POST /payments and GET /payments/{id}/status flows straight through without a
// translation layer that can silently drift out of sync (the `paid` vs
// `succeeded` mismatch that hung the card flow).
export type PaymentStatus = 'pending' | 'succeeded' | 'failed' | 'refunded';

// UI-only superset: 'idle' = no payment started yet (pre-submit). Not a backend
// status, so it lives here rather than polluting PaymentStatus.
export type PaymentUiStatus = PaymentStatus | 'idle';

export interface PaymentResult {
  payment_id: string;
  reference_no: string;
  status: 'pending' | 'succeeded';
  method?: PaymentMethod | string;
  qr_url?: string;
  amount_paid: number;
  expires_at?: string;
  confirm_type: 'auto' | 'manual';
}

export interface CompletedPayment {
  payment_id: string;
  reference_no: string;
  method: PaymentMethod;
  amount: number;
  timestamp: string;
}
