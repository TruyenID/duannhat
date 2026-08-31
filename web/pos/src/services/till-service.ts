/**
 * Till Service — Plan 030 (Cashier Shift).
 *
 * Endpoints (all gated by sso.auth + ResolvePosShop on the backend):
 *
 *   GET    /api/v1/pos/till/current
 *   GET    /api/v1/pos/till/denominations?currency=JPY
 *   GET    /api/v1/pos/till/tender-types
 *   POST   /api/v1/pos/till/sessions
 *   GET    /api/v1/pos/till/sessions/{id}
 *   GET    /api/v1/pos/till/sessions/{id}/reconciliation
 *   POST   /api/v1/pos/till/sessions/{id}/cash-events
 *   PATCH  /api/v1/pos/till/sessions/{id}/draft
 *   POST   /api/v1/pos/till/sessions/{id}/close
 *   POST   /api/v1/pos/till/sessions/{id}/abandon
 */

import { apiFetch } from "@/lib/api";

// Plan 030 — workstation has a catch-all reverse proxy at /api/v1/pos/*
// (see godx-tempo-workstation-app internal/handler/pos_cloud_proxy.go).
// Every till call therefore flows through LAN first (workstation forwards
// to cloud, enforces the paired branch slug along the way), with the
// existing apiFetch network-error fallback dropping to Cloud if the
// workstation is unreachable. No till_opts override needed.

export type DenominationKind = "note" | "coin";
export type TillSessionStatus =
  | "open"
  | "closing"
  | "settled"
  | "abandoned";
export type TillCountPhase = "opening" | "closing";
export type TillCashEventType =
  | "paid_in"
  | "paid_out"
  | "loan_from_safe"
  | "pickup_to_safe";
/**
 * Legacy union of the 3 system category keys. Kept for narrow type
 * inference in places that only care about system buckets (variance
 * math, hardcoded labels). Custom shop-defined categories use the
 * `string` widening below.
 *
 * NOTE: the new `TillTenderCategoryRow` interface (CRUD row from
 * /tender-categories) is the runtime list of categories — iterate over
 * IT, not over this union, when rendering the reconciliation cards.
 */
export type TillTenderCategory = "cash" | "card" | "qr" | "emoney" | (string & {});

/** Row shape returned by /api/v1/pos/till/tender-categories. */
export interface TillTenderCategoryRow {
  id: string;
  key: string;
  name: string;
  sort_order: number;
  is_system: boolean;
}

export interface Denomination {
  id: string;
  currency_code: string;
  value: number;
  kind: DenominationKind;
  label: string | null;
  sort_order: number;
}

export interface TillTenderType {
  id: string;
  tender_key: string;
  name: Record<string, string> | string;
  category: TillTenderCategory;
  parent_tender_key: string | null;
  currency_code: string;
  payment_method_code: string | null;
  is_expected_anchor: boolean;
  requires_terminal_total: boolean;
  sort_order: number;
}

export interface DenominationCount {
  id?: string;
  denomination_id: string;
  currency_code: string;
  denomination_value: number;
  denomination_kind: DenominationKind;
  quantity: number;
  subtotal_amount: number;
}

export interface TillCashEvent {
  id: string;
  session_id: string;
  event_type: TillCashEventType;
  amount: number;
  currency_code: string;
  reason: string | null;
  reference_no: string | null;
  performed_by_id: string | null;
  occurred_at: string | null;
}

export interface TillSettlementDetail {
  id: string;
  tender_key: string;
  category: TillTenderCategory;
  currency_code: string;
  expected_amount: number | null;
  declared_gross_amount: number;
  declared_cancel_amount: number;
  declared_amount: number;
  terminal_batch_total: number | null;
  variance_amount: number | null;
  variance_reason: string | null;
}

export interface TillSession {
  id: string;
  session_code: string;
  status: TillSessionStatus;
  business_date: string | null;
  default_currency_code: string;
  opening_float_amount: number;
  expected_cash_amount: number | null;
  counted_cash_amount: number | null;
  cash_variance_amount: number | null;
  /**
   * 「Tiền lẻ」 — cash below the smallest configured denomination. Sent on
   * draft/close as `closing_cash_adjustment`; read back under this name.
   *
   * The type was missing it entirely, which is why nothing complained when the
   * close screen lost it on reload: there was no field to notice the absence of
   * (#1986).
   */
  closing_cash_adjustment_amount: number | null;
  opening_note: string | null;
  closing_note: string | null;
  opened_by_id: string | null;
  closed_by_id: string | null;
  opener_name: string | null;
  opened_at: string | null;
  closed_at: string | null;
  abandoned_at: string | null;
  abandon_reason: string | null;
  till_id: string;
  branch_id: string;
  // Plan-046 — chain of shifts.
  chain_id: string | null;
  chain_sequence: number;
  settlement_kind: TillSettlementKind | null;
  /** transient (open response) — this open continued a handover chain. */
  continues_chain?: boolean;
  /** transient (final-close response) — client should fetch + print the chain slip. */
  chain_summary_ready?: boolean;
  opening_counts?: DenominationCount[];
  closing_counts?: DenominationCount[];
  cash_events?: TillCashEvent[];
  settlement_details?: TillSettlementDetail[];
}

export type TillSettlementKind = "handover" | "final";

/** Plan-046 — GET /pos/till/chains/{chainId}/summary. */
export interface ChainSummaryShift {
  session_code: string;
  chain_sequence: number;
  settlement_kind: TillSettlementKind | null;
  opener_name: string | null;
  opened_at: string | null;
  closed_at: string | null;
  cash: Record<string, number>;
  tax_breakdown: { rate: number; taxable: number; tax: number }[];
  revenue: Record<string, number>;
}

export interface ChainSummary {
  chain_id: string;
  branch_id: string;
  till_code: string | null;
  opened_at: string | null;
  closed_at: string | null;
  chain_open: boolean;
  shifts: ChainSummaryShift[];
  grand_total: {
    cash: Record<string, number>;
    tax_breakdown: { rate: number; taxable: number; tax: number }[];
    revenue: Record<string, number>;
  };
}

export interface CurrentTill {
  till: {
    id: string;
    till_code: string;
    default_currency_code: string;
    variance_tolerance_amount: number;
    current_session_id: string | null;
  };
  open_session: TillSession | null;
}

export interface ReconciliationData {
  revenue: {
    gross: number;
    net: number;
    tax: number;
    discount: number;
    currency_code: string;
  };
  cash: {
    opening_float: number;
    cash_sales: number;
    paid_in: number;
    paid_out: number;
    expected_cash: number;
  };
  tenders: {
    tender_key: string;
    category: TillTenderCategory;
    parent: string | null;
    expected_amount: number | null;
  }[];
  category_expected: Record<TillTenderCategory, number>;
}

export interface OpenShiftPayload {
  till_code?: string;
  currency_code?: string;
  opening_counts: { denomination_id: string; quantity: number }[];
  opening_note?: string | null;
  opened_by_id?: string | null;
  opener_name?: string | null;
  /**
   * plan-044 R2 — gap reconciliation. Ids of close-gap payments (from
   * {@link GapPreview}) the cashier confirmed belong to THIS shift; the
   * backend/workstation stamps their `till_session_id` to the new session.
   */
  claimed_gap_payment_ids?: string[];
  /**
   * Required acknowledgement (when any claimed row is cash) that the gap cash
   * was physically held aside — NOT folded into the opening float — so
   * re-attributing it here can't double-count against the drawer.
   */
  gap_cash_held_separately_ack?: boolean;
}

/** One NULL-attributed payment taken during the previous shift's close-gap. */
export interface GapPreviewPayment {
  id: string;
  order_id: string;
  order_code: string;
  amount: number;
  method_code: string;
  /** Present on the Cloud response; absent on the workstation LAN replica. */
  method_label?: string;
  is_cash: boolean;
  created_at: string;
}

export interface GapPreview {
  previous_session: {
    id: string;
    session_code: string;
    ended_at: string;
  } | null;
  gap_window: { from: string; to: string } | null;
  currency_code: string;
  payments: GapPreviewPayment[];
  totals: {
    count: number;
    cash_amount: number;
    non_cash_amount: number;
  };
}

/** One unpaid order carrying into the next shift (close-screen summary). */
export interface UnpaidCarryOrder {
  id: string;
  order_code: string;
  status: string;
  total_amount: number;
  outstanding_amount: number;
  created_at: string;
}

export interface CloseOrderSummary {
  paid_orders_count: number;
  paid_orders_total: number;
  unpaid_carry_count: number;
  unpaid_carry_orders: UnpaidCarryOrder[];
}

/** One order still paying/checkout that lived past the previous shift close (#2696). */
export interface UnresolvedOrder {
  id: string;
  order_code: string | null;
  status: string;
  total_amount: number;
  paid_amount: number;
  outstanding_amount: number;
  /**
   * #2721 — đã thu ĐỦ, chỉ kẹt trạng thái: đơn vẫn phải hiện (ai đó phải đóng
   * nó) nhưng KHÔNG phải tiền thiếu. Cờ do backend quyết; đừng suy lại từ
   * `outstanding_amount === 0` ở đây.
   *
   * Optional vì backend chưa deploy vẫn trả thiếu trường — thiếu ⇒ panel giữ
   * nguyên cảnh báo cũ thay vì im lặng hạ tông một đơn thật sự thiếu tiền.
   */
  needs_close_only?: boolean;
  table_released: boolean;
  created_at: string;
}

export interface UnresolvedOrdersPreview {
  previous_session: {
    id: string;
    session_code: string;
    ended_at: string;
  } | null;
  currency_code: string;
  orders: UnresolvedOrder[];
  totals: {
    count: number;
    outstanding_amount: number;
    /** Số đơn CÒN THIẾU tiền thật (`count` − `pending_close_count`). */
    outstanding_count?: number;
    /** #2721 — số đơn đã thu đủ, chỉ chờ bấm đóng. */
    pending_close_count?: number;
  };
}

export interface DraftClosePayload {
  closing_counts?: { denomination_id: string; quantity: number }[];
  tender_details?: TenderDeclaredPayload[];
  closing_note?: string | null;
  /** "Tiền lẻ / điều chỉnh" — cash below the smallest denomination. */
  closing_cash_adjustment?: number | null;
}

export interface TenderDeclaredPayload {
  tender_key: string;
  gross_amount?: number;
  cancel_amount?: number;
  terminal_batch_total?: number | null;
  variance_reason?: string | null;
}

export interface CloseShiftPayload {
  closing_counts: { denomination_id: string; quantity: number }[];
  tender_details: TenderDeclaredPayload[];
  closing_note?: string | null;
  closed_by_id?: string | null;
  /** "Tiền lẻ / điều chỉnh" — cash below the smallest denomination. */
  closing_cash_adjustment?: number | null;
}

export interface CashEventPayload {
  event_type: TillCashEventType;
  amount: number;
  reason: string;
  currency_code?: string;
  reference_no?: string | null;
  occurred_at?: string | null;
}

/**
 * #1156 — one registered payment-terminal peripheral, as the POS till
 * namespace is expected to expose it (a thin projection of
 * PeripheralDeviceResource: id/name/is_active + `metadata.accepts`).
 *
 * NOTE: `GET /pos/till/payment-terminals` is the agreed FOLLOW-UP endpoint —
 * Cloud already stores `peripheral_devices.metadata.accepts` but only serves
 * it behind Platform SSO (`/shops/{slug}/peripheral-devices`), which a paired
 * POS device token cannot reach. Until the endpoint ships, the query resolves
 * to an empty list and consumers degrade gracefully (see usePaymentTerminals).
 */
export interface PosPaymentTerminalRow {
  id: string;
  name: string;
  is_active?: boolean;
  metadata?: {
    accepts?: string[] | null;
    model?: string | null;
    [key: string]: unknown;
  } | null;
}

const path = (suffix: string) => `/api/v1/pos/till${suffix}`;

export const tillService = {
  current: () => apiFetch<{ data: CurrentTill }>(path("/current")),

  denominations: (currency?: string) =>
    apiFetch<{ data: Denomination[] }>(
      path(`/denominations${currency ? `?currency=${currency}` : ""}`),
    ),

  tenderTypes: () =>
    apiFetch<{ data: TillTenderType[] }>(path("/tender-types")),

  /**
   * Categories drive which "Đối chiếu thiết bị thanh toán" cards render
   * + their order/label. The 3 system rows (card/qr/emoney) come from
   * the org seed; shop-defined custom rows overlay on top.
   */
  tenderCategories: () =>
    apiFetch<{ data: TillTenderCategoryRow[] }>(path("/tender-categories")),

  /** #1156 — registered payment terminals + their `accepts` (see PosPaymentTerminalRow note). */
  paymentTerminals: () =>
    apiFetch<{ data: PosPaymentTerminalRow[] }>(path("/payment-terminals")),

  sessionShow: (id: string) =>
    apiFetch<{ data: TillSession }>(path(`/sessions/${id}`)),

  /**
   * #3062 — danh sách ca của QUẦY NÀY, mới nhất trước.
   *
   * Nền cho trang lịch sử ca + nút in lại phiếu 精算. Máy trạm đọc từ bản
   * SQLite local chứ không proxy Cloud, có chủ đích: ca cần in lại nhất là ca
   * vừa hỏng, và lý do hỏng thường là mạng — một trang chỉ chạy khi có mạng là
   * trang vắng mặt đúng lúc cần.
   *
   * Lọc theo NGÀY NGHIỆP VỤ, không theo giờ mở: ca đêm mở 23:50 đóng 02:10
   * thuộc ngày hôm trước, và đó là điều nhân viên nghĩ khi nói "ca hôm qua".
   */
  sessionIndex: (params?: {
    businessDateFrom?: string;
    businessDateTo?: string;
    limit?: number;
  }) => {
    const q = new URLSearchParams();
    if (params?.businessDateFrom) {
      q.set("business_date_from", params.businessDateFrom);
    }
    if (params?.businessDateTo) {
      q.set("business_date_to", params.businessDateTo);
    }
    if (params?.limit) {
      q.set("limit", String(params.limit));
    }
    const qs = q.toString();

    return apiFetch<{ data: TillSession[] }>(
      path(`/sessions${qs ? `?${qs}` : ""}`),
    );
  },

  reconciliation: (id: string) =>
    apiFetch<{ data: ReconciliationData }>(
      path(`/sessions/${id}/reconciliation`),
    ),

  /** plan-044 R2 — NULL-attributed payments taken during the previous shift's close-gap. */
  gapPreview: () => apiFetch<{ data: GapPreview }>(path("/gap-preview")),

  /** #2696 — orders still paying/checkout that lived past the previous shift close. */
  unresolvedOrders: () =>
    apiFetch<{ data: UnresolvedOrdersPreview }>(path("/unresolved-orders")),

  /** plan-044 R2 — paid vs unpaid-carry order summary for the close screen. */
  orderSummary: (id: string) =>
    apiFetch<{ data: CloseOrderSummary }>(
      path(`/sessions/${id}/order-summary`),
    ),

  open: (body: OpenShiftPayload) =>
    apiFetch<{ data: TillSession }>(path("/sessions"), {
      method: "POST",
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
    }),

  cashEvent: (id: string, body: CashEventPayload) =>
    apiFetch<{ data: TillCashEvent }>(path(`/sessions/${id}/cash-events`), {
      method: "POST",
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
    }),

  saveDraft: (id: string, body: DraftClosePayload) =>
    apiFetch<{ data: TillSession }>(path(`/sessions/${id}/draft`), {
      method: "PATCH",
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
    }),

  close: (id: string, body: CloseShiftPayload) =>
    apiFetch<{ data: TillSession }>(path(`/sessions/${id}/close`), {
      method: "POST",
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
    }),

  // Plan-046 — handover: settle the shift but keep the chain open. Same payload
  // shape as close (a handover settles like close).
  handover: (id: string, body: CloseShiftPayload) =>
    apiFetch<{ data: TillSession }>(path(`/sessions/${id}/handover`), {
      method: "POST",
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
    }),

  // Plan-046 — aggregate chain summary (branch-scoped; 404 cross-branch).
  chainSummary: (chainId: string) =>
    apiFetch<{ data: ChainSummary }>(path(`/chains/${chainId}/summary`)),

  abandon: (id: string, reason?: string | null) =>
    apiFetch<{ data: TillSession }>(path(`/sessions/${id}/abandon`), {
      method: "POST",
      body: JSON.stringify({ abandon_reason: reason ?? null }),
      headers: { "Content-Type": "application/json" },
    }),
};
