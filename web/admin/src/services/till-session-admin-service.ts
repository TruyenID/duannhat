/**
 * Plan-032 — Admin-side TillSession service.
 *
 * Manager-only endpoints under /pos/till/sessions/* used by the "Ca treo" tab:
 *   GET  /api/v1/pos/till/sessions/stale?filter=...&branch_id=...&per_page=...
 *   GET  /api/v1/pos/till/sessions/stale?group_by=actor&within_days=30
 *   POST /api/v1/pos/till/sessions/{id}/force-abandon
 *   POST /api/v1/pos/till/sessions/{id}/manual-settle
 *
 * All calls go through `apiFetch` (per AGENTS.md). Shop context is resolved
 * server-side from the X-Shop-Slug header — the admin-web caller sets it via
 * the standard layout wrapper that already injects shop context on every
 * /shop/[shopSlug]/* page.
 */

import { apiFetch } from "@/lib/api";

export type StaleFilter = "open_overdue" | "expired" | "all_terminal";

export type ForceAbandonReasonCode =
  | "cashier_forgot_to_close"
  | "cashier_unavailable_today"
  | "pos_device_failure"
  | "network_outage"
  | "end_of_employment"
  | "other";

export interface StaleSession {
  id: string;
  session_code: string;
  status: "open" | "closing" | "settled" | "abandoned" | "expired";
  business_date: string;
  default_currency_code: string;
  opening_float_amount: number;
  expected_cash_amount: number | null;
  counted_cash_amount: number | null;
  cash_variance_amount: number | null;
  opened_at: string | null;
  closed_at: string | null;
  abandoned_at: string | null;
  expired_at: string | null;
  force_abandoned: boolean;
  force_abandoned_by_id: string | null;
  force_abandon_reason_code: ForceAbandonReasonCode | null;
  force_abandon_reason_detail: string | null;
  expire_reason: "no_activity" | "manual_admin" | "device_lost_signal" | null;
  expire_threshold_hours: number | null;
  branch_id: string;
  till_id: string;
  opened_by_id: string | null;
  closed_by_id: string | null;
}

/** `GET /pos/till/sessions/{id}/reconciliation` — cash block only (what the settle math uses). */
export interface SessionReconciliation {
  cash: {
    opening_float: number;
    cash_sales: number;
    cash_tips: number;
    paid_in: number;
    paid_out: number;
    loan_from_safe: number;
    pickup_to_safe: number;
    expected_cash: number;
  };
}

export interface StaleListResponse {
  data: StaleSession[];
  meta: {
    filter: StaleFilter;
    threshold_hours: number;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface StaleActorSummary {
  data: {
    force_abandoned_count: number;
    expired_count: number;
    per_manager: Array<{ manager_id: string; count: number }>;
  };
  meta: { within_days: number; branch_id: string | null };
}

export interface ForceAbandonInput {
  reason_code: ForceAbandonReasonCode;
  reason_detail?: string | null;
}

export interface ManualSettleInput {
  closing_counts: Array<{ denomination_id: string; quantity: number }>;
  tender_details: Array<{
    tender_key: string;
    gross_amount?: number;
    cancel_amount?: number;
    terminal_batch_total?: number | null;
    variance_reason?: string | null;
  }>;
  closing_note?: string | null;
  manual_settle_reason: string;
  opening_counts_override?: Array<{ denomination_id: string; quantity: number }> | null;
  post_hoc_cash_events?: Array<{
    event_type: string;
    amount: number;
    currency_code?: string;
    reason?: string | null;
    occurred_at?: string;
  }> | null;
}

const base = "/api/v1/pos/till/sessions";

export const tillSessionAdminService = {
  listStale: (
    shopSlug: string,
    params: {
      filter?: StaleFilter;
      branch_id?: string;
      per_page?: number;
      page?: number;
    } = {}
  ) => {
    const q = new URLSearchParams();
    if (params.filter) q.set("filter", params.filter);
    if (params.branch_id) q.set("branch_id", params.branch_id);
    if (params.per_page) q.set("per_page", String(params.per_page));
    if (params.page) q.set("page", String(params.page));
    return apiFetch<StaleListResponse>(`${base}/stale?${q.toString()}`, {
      headers: { "X-Shop-Slug": shopSlug },
    });
  },

  actorSummary: (shopSlug: string, withinDays = 30, branchId?: string) => {
    const q = new URLSearchParams({ group_by: "actor", within_days: String(withinDays) });
    if (branchId) q.set("branch_id", branchId);
    return apiFetch<StaleActorSummary>(`${base}/stale?${q.toString()}`, {
      headers: { "X-Shop-Slug": shopSlug },
    });
  },

  forceAbandon: (shopSlug: string, sessionId: string, input: ForceAbandonInput) =>
    apiFetch<{ data: StaleSession }>(`${base}/${sessionId}/force-abandon`, {
      method: "POST",
      body: JSON.stringify(input),
      headers: { "X-Shop-Slug": shopSlug },
    }),

  manualSettle: (shopSlug: string, sessionId: string, input: ManualSettleInput) =>
    apiFetch<{ data: StaleSession }>(`${base}/${sessionId}/manual-settle`, {
      method: "POST",
      body: JSON.stringify(input),
      headers: { "X-Shop-Slug": shopSlug },
    }),

  /**
   * Cash reconciliation for one session — the SAME numbers manual-settle
   * itself reconciles against (opening float + cash sales + tips + cash
   * events). `till_sessions.expected_cash_amount` is only written at close,
   * so an expired shift has none; this is the only read that can tell a
   * manager what the drawer should hold before they count it.
   */
  reconciliation: (shopSlug: string, sessionId: string) =>
    apiFetch<{ data: SessionReconciliation }>(`${base}/${sessionId}/reconciliation`, {
      headers: { "X-Shop-Slug": shopSlug },
    }),
};

/** Query keys for TanStack Query. */
export const tillSessionAdminQueryKeys = {
  all: ["till-sessions-admin"] as const,
  stale: (shopSlug: string, filter?: StaleFilter, branchId?: string) =>
    [
      ...tillSessionAdminQueryKeys.all,
      "stale",
      shopSlug,
      filter ?? "open_overdue",
      branchId ?? null,
    ] as const,
  actorSummary: (shopSlug: string, branchId?: string) =>
    [...tillSessionAdminQueryKeys.all, "actor-summary", shopSlug, branchId ?? null] as const,
  reconciliation: (shopSlug: string, sessionId: string) =>
    [...tillSessionAdminQueryKeys.all, "reconciliation", shopSlug, sessionId] as const,
};
