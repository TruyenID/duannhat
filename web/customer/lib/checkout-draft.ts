"use client";

import type { OrderToppingPayload } from "@/lib/cart-toppings";
import type { CartOptionLine } from "@/components/cart-item-options";
import type { TaxBreakdownRow } from "@/lib/tax";

const STORAGE_KEY_PREFIX = "tempo:checkout-draft:";

export interface CheckoutDraftItem {
  id: string;
  product_sku_id: string;
  name: string;
  variant?: string;
  qty: number;
  unit_price: number;
  subtotal: number;
  image_url?: string;
  // BE-ready topping payload so the order-confirm commit step can forward
  // the selections without re-running the cart resolver (CartItem isn't
  // available at that point — only the persisted draft is).
  toppings?: OrderToppingPayload[];
  // Display-only option + topping lines (name + add/remove/info kind), captured
  // from the cart at draft time via buildCartOptionLines so /order-confirm can
  // show each item's selected options + toppings (#435). The `toppings` payload
  // above only holds IDs, so it can't render names on its own.
  option_lines?: CartOptionLine[];
  // #1768 — per-item note ("Không hành", "Ít cay"). BE accepts `items.*.note`
  // in CustomerOrderStoreRequest; the counter-pay draft path used to drop it
  // (draft has no field → order-confirm POST can't forward), so the kitchen
  // never saw it. Now persisted so /order-confirm can (a) show the note back
  // to the customer for verification and (b) POST it in the commit step.
  note?: string;
}

export interface CheckoutDraft {
  id: string;
  code: string;
  shop_slug: string;
  items: CheckoutDraftItem[];
  customer_name?: string;
  customer_phone?: string;
  customer_email?: string;
  note?: string;
  payment_method: "counter";
  coupon_code?: string;
  downgrade_promos?: boolean;
  pickup_type?: "immediate" | "scheduled";
  scheduled_pickup_time?: string;
  // --- Money breakdown (#39) -----------------------------------------------
  // The draft is a pre-DB snapshot of what checkout already showed the
  // customer, so /order-confirm can repeat the same figures instead of
  // displaying a bare pre-tax merchandise sum. All optional: drafts saved
  // before this shipped only carry `total`, and the confirm screen falls back
  // to Σ line subtotals for them. The server stays authoritative at commit.
  /** Pre-tax merchandise subtotal (Σ line subtotals, before coupon). */
  subtotal?: number;
  /** Coupon discount applied at draft time. */
  discount_amount?: number;
  /** Consumption tax, rounded once per rate group (plan-043). */
  tax_amount?: number;
  /** Per-rate rows (8%対象 / 10%対象) for インボイス-style display. */
  tax_breakdown?: TaxBreakdownRow[];
  /** true = 総額表示 — menu prices already contain the tax. */
  prices_include_tax?: boolean;
  /** Service charge computed on (subtotal − discount). */
  service_charge?: number;
  /** Service-charge percent, for the "Phí dịch vụ (x%)" label. */
  service_charge_rate?: number;
  /** Final payable total — subtotal − discount + tax (if excluded) + service. */
  total: number;
  currency_code: string;
  created_at: string;
  expires_at: string;
}

function key(id: string): string {
  return `${STORAGE_KEY_PREFIX}${id}`;
}

export function saveCheckoutDraft(draft: CheckoutDraft): boolean {
  if (typeof window === "undefined") return false;
  try {
    const json = JSON.stringify(draft);
    window.localStorage.setItem(key(draft.id), json);
    return window.localStorage.getItem(key(draft.id)) === json;
  } catch (err) {
    console.error("[checkout-draft] save failed", err);
    return false;
  }
}

export function loadCheckoutDraft(id: string): CheckoutDraft | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.localStorage.getItem(key(id));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as CheckoutDraft;
    if (!parsed?.id || !Array.isArray(parsed?.items)) return null;
    const expMs = Date.parse(parsed.expires_at);
    if (Number.isNaN(expMs) || expMs <= Date.now()) {
      removeCheckoutDraft(id);
      return null;
    }
    return parsed;
  } catch {
    return null;
  }
}

export function removeCheckoutDraft(id: string): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.removeItem(key(id));
  } catch {
    /* no-op */
  }
}

/**
 * Tìm draft active đầu tiên (chưa hết hạn) trong localStorage. Dùng làm
 * guard cho /menus, /takeaway, /checkout, /orders/{id}/pay — nếu có draft
 * active thì redirect customer về /order-confirm/{id} để không đặt món
 * mới hoặc thanh toán đơn khác khi đang chờ xác nhận.
 */
export function findActiveCheckoutDraft(): CheckoutDraft | null {
  if (typeof window === "undefined") return null;
  try {
    for (let i = 0; i < window.localStorage.length; i++) {
      const k = window.localStorage.key(i);
      if (!k || !k.startsWith(STORAGE_KEY_PREFIX)) continue;
      const id = k.slice(STORAGE_KEY_PREFIX.length);
      const draft = loadCheckoutDraft(id);
      if (draft) return draft;
    }
  } catch {
    /* no-op */
  }
  return null;
}

export function generateDraftCode(): string {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  let code = "DR-";
  for (let i = 0; i < 4; i++) {
    code += chars[Math.floor(Math.random() * chars.length)];
  }
  return code;
}
