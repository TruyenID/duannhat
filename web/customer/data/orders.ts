// Mock active orders keyed by table ID.
// Replace with real API call: GET /api/v1/customer/table/{qrToken}/order
// when backend ships the customer-facing endpoints.

export type OrderItemStatus = "pending" | "preparing" | "ready" | "served";

export interface ActiveOrderItemOption {
  id: string;
  name: string | null;
  unit_price: number; // JPY, per unit of this option
  quantity: number;
}

export interface ActiveOrderItem {
  id: string;
  name: string;
  image_url?: string | null;
  variant?: string | null;
  category?: string | null;
  qty: number;
  unit_price: number; // JPY
  subtotal: number;
  note?: string | null;
  options?: ActiveOrderItemOption[];
  status: OrderItemStatus;
  // Cumulative qty already paid for via a by_items payment (kiosk counter-pay
  // or POS). 0 when the prior payments were Tùy chọn / Chia đều (amount-based,
  // no per-item attribution). When paid_quantity >= qty the by_items UI marks
  // the item as fully paid: disabled controls + "đã thanh toán" badge so the
  // next guest can't double-charge it.
  paid_quantity?: number;
  created_at?: string | null;
  // #1099 — per-line consumption-tax snapshot. `tax_rate` null = a legacy
  // order taken before the tax engine. One rate per line; which one is decided
  // by the menu the customer ordered from.
  tax_type_id?: string | null;
  tax_rate?: number | null;
  tax_amount?: number;
}

export interface OrderPayment {
  id: string;
  amount: number;
  status: string;
  paid_at: string | null;
  payment_method: string | null;
}

export interface ActiveOrder {
  id: string;
  code: string;
  // Order lifecycle status (open/dining/checkout/paying/closed/voided/…). The
  // dine-in pay page reads this to tell a genuinely-settled bill from a ¥0 bill
  // that is still open and awaiting a free-close.
  status?: string;
  placed_at: string; // ISO8601
  items: ActiveOrderItem[];
  subtotal: number;
  total: number;
  paid?: number; // Amount already paid (for Stripe polling)
  remaining?: number;
  is_fully_paid?: boolean;
  payment_count?: number;
  payments?: OrderPayment[];
  // One coupon per order. FE reads these to gate the coupon input — split-bill
  // customers see "đã áp mã X" instead of an enabled input that 422s on submit.
  discount_amount?: number;
  coupon_code_snapshot?: string | null;
  // plan-043 — per-rate breakdown (8%対象 / 10%対象) + tax mode snapshot for
  // インボイス display. Additive; absent on legacy payloads.
  tax_amount?: number;
  service_charge?: number;
  is_tax_included?: boolean;
  tax_breakdown?: { rate: number; taxable: number; tax: number }[];
}

export const mockActiveOrders: Record<string, ActiveOrder> = {
  "t-a2": {
    id: "ord-a2-001",
    code: "ORD-001",
    placed_at: new Date(Date.now() - 25 * 60 * 1000).toISOString(),
    items: [
      { id: "i1", name: "Phở bò tái", qty: 2, unit_price: 1200, subtotal: 2400, status: "served" },
      { id: "i2", name: "Cơm tấm sườn bì chả", qty: 1, unit_price: 1500, subtotal: 1500, status: "ready" },
      { id: "i3", name: "Trà đá", qty: 3, unit_price: 300, subtotal: 900, status: "preparing" },
    ],
    subtotal: 4800,
    total: 4800,
  },
  "t-b1": {
    id: "ord-b1-001",
    code: "ORD-002",
    placed_at: new Date(Date.now() - 10 * 60 * 1000).toISOString(),
    items: [
      { id: "i4", name: "Bún bò Huế", qty: 1, unit_price: 1300, subtotal: 1300, status: "preparing" },
      { id: "i5", name: "Cà phê đen đá", qty: 2, unit_price: 500, subtotal: 1000, status: "pending" },
    ],
    subtotal: 2300,
    total: 2300,
  },
  "t-b5": {
    id: "ord-b5-001",
    code: "ORD-003",
    placed_at: new Date(Date.now() - 45 * 60 * 1000).toISOString(),
    items: [
      { id: "i6", name: "Phở gà", qty: 3, unit_price: 1100, subtotal: 3300, status: "served" },
      { id: "i7", name: "Bánh mì thịt", qty: 2, unit_price: 800, subtotal: 1600, status: "served" },
      { id: "i8", name: "Sinh tố bơ", qty: 2, unit_price: 900, subtotal: 1800, status: "ready" },
      { id: "i9", name: "Chè đậu xanh", qty: 3, unit_price: 500, subtotal: 1500, status: "pending" },
    ],
    subtotal: 8200,
    total: 8200,
  },
  "t-o3": {
    id: "ord-o3-001",
    code: "ORD-004",
    placed_at: new Date(Date.now() - 5 * 60 * 1000).toISOString(),
    items: [
      { id: "i10", name: "Hủ tiếu Nam Vang", qty: 2, unit_price: 1200, subtotal: 2400, status: "pending" },
      { id: "i11", name: "Nước cam tươi", qty: 2, unit_price: 800, subtotal: 1600, status: "pending" },
    ],
    subtotal: 4000,
    total: 4000,
  },
};

/** Returns the active order for a table, or null if none. */
export function getActiveOrder(tableId: string): ActiveOrder | null {
  return mockActiveOrders[tableId] ?? null;
}

/** Friendly "X phút trước" label */
export function minutesAgo(isoDate: string): string {
  const diff = Math.floor((Date.now() - new Date(isoDate).getTime()) / 60000);
  if (diff < 1) return "vừa xong";
  return `${diff} phút trước`;
}
