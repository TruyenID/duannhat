/**
 * Plan-036 — Manager Till Tracking admin service.
 *
 * Four read endpoints under /shops/{shopSlug}/till/* — gated server-side by
 * ShopTillTrackingPolicy (manager+ only):
 *
 *   GET /api/v1/shops/{slug}/till/dashboard?trend_days=14
 *   GET /api/v1/shops/{slug}/till/sessions?from=&to=&till_id[]=&status[]=&...
 *   GET /api/v1/shops/{slug}/till/sessions/{id}
 *   GET /api/v1/shops/{slug}/till/sessions/{id}/z-report.pdf  → Blob
 *
 * All calls go through apiFetch — no raw fetch. The Z-report download uses
 * responseType: "blob" so the binary stays binary; the caller wraps it in a
 * createObjectURL + anchor click to trigger the browser download.
 */

import { apiFetch } from "@/lib/api";

// =============================================================================
//  Type contracts mirroring backend response shapes
// =============================================================================

export type TillStatus = "open" | "closing" | "settled" | "abandoned" | "expired";
export type VarianceBucket = "none" | "over" | "short" | "out_of_tolerance";
export type SortKey = "opened_at_desc" | "opened_at_asc" | "variance_abs_desc";
export type TrendDays = 7 | 14 | 30 | 90;

export interface DashboardTill {
  id: string;
  name: string;
  status: "open" | "idle";
  current_session: {
    id: string;
    session_code: string;
    opener_name: string | null;
    opened_at: string;
    duration_hours: number;
    stale_warning: boolean;
    stale_critical: boolean;
  } | null;
}

export interface DashboardResponse {
  data: {
    currency_code: string;
    tills: DashboardTill[];
    kpis: {
      open_till_count: number;
      total_till_count: number;
      settled_today: {
        count: number;
        gross_total_amount: number;
        currency_code: string;
      };
      variance_today: {
        net_amount: number;
        abs_max_session_id: string | null;
        abs_max_amount: number;
      };
      stale_count: { open_overdue: number; expired: number };
    };
    variance_trend: Array<{
      date: string;
      avg_amount: number;
      abs_max_amount: number;
      session_count: number;
    }>;
    recent_settlements: Array<{
      id: string;
      session_code: string;
      till_name: string | null;
      opener_name: string | null;
      expected_cash_amount: number;
      counted_cash_amount: number;
      variance_amount: number;
      closed_at: string | null;
    }>;
    force_abandon_activity_30d: Array<{
      actor_id: string;
      actor_name: string;
      force_abandon_count: number;
    }>;
  };
  meta: {
    stale_threshold_hours: number;
    warning_hours: number;
    trend_days: number;
    cached_at: string;
  };
}

export interface SessionListRow {
  id: string;
  session_code: string;
  status: TillStatus;
  till: { id: string; name: string | null } | null;
  opener_name: string | null;
  opened_by_id: string | null;
  closed_by_id: string | null;
  opened_at: string | null;
  closed_at: string | null;
  abandoned_at: string | null;
  expired_at: string | null;
  duration_hours: number | null;
  currency_code: string | null;
  opening_float_amount: number | null;
  expected_cash_amount: number | null;
  counted_cash_amount: number | null;
  cash_variance_amount: number | null;
  gross_revenue_amount: number;
  payment_count: number;
  force_abandoned: boolean;
  expire_reason: string | null;
}

export interface SessionsListFilters {
  from?: string;
  to?: string;
  till_id?: string[];
  status?: TillStatus[];
  opener_id?: string;
  variance?: VarianceBucket;
  force_abandoned?: boolean;
  per_page?: number;
  page?: number;
  sort?: SortKey;
}

export interface SessionsListResponse {
  data: SessionListRow[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: string;
    to: string;
    applied_filters: Record<string, unknown>;
  };
}

export interface SessionDetail {
  id: string;
  session_code: string;
  status: TillStatus;
  till: { id: string; name: string | null; variance_tolerance_amount: number | null } | null;
  branch: { id: string; name: string | null } | null;
  currency_code: string | null;
  business_date: string | null;
  /**
   * plan-043 — 総額表示 mode snapshotted at shift open. Shown as a badge so a
   * manager reconciling the close report sees which tax mode was active.
   * `null` on sessions opened before plan-043.
   */
  prices_include_tax_at_open: boolean | null;
  opener_name: string | null;
  opened_by: { id: string; name: string | null } | null;
  closed_by: { id: string; name: string | null } | null;
  opened_at: string | null;
  closed_at: string | null;
  abandoned_at: string | null;
  expired_at: string | null;
  expire_reason: string | null;
  expire_threshold_hours: number | null;
  duration_hours: number | null;
  force_abandoned: boolean;
  force_abandoned_by: { id: string; name: string | null } | null;
  force_abandon_reason_code: string | null;
  force_abandon_reason_detail: string | null;
  opening_float_amount: number | null;
  opening_note: string | null;
  closing_note: string | null;
  expected_cash_amount: number | null;
  counted_cash_amount: number | null;
  cash_variance_amount: number | null;
  opening_counts: Array<{
    denomination_id: string;
    label: string | null;
    denomination_value: number;
    quantity: number;
    subtotal_amount: number;
  }>;
  closing_counts: Array<{
    denomination_id: string;
    label: string | null;
    denomination_value: number;
    quantity: number;
    subtotal_amount: number;
  }>;
  cash_events: Array<{
    id: string;
    event_type: string;
    amount: number;
    currency_code: string | null;
    reason: string | null;
    occurred_at: string | null;
    manual_adjustment: boolean;
    performed_by_id: string | null;
  }>;
  reconciliation: {
    by_tender_category: Array<{
      category: string;
      tender_rows: Array<{
        tender_key: string;
        tender_name: string;
        expected_amount: number | null;
        declared_amount: number | null;
        variance_amount: number | null;
        transaction_count: number;
        variance_reason: string | null;
      }>;
      subtotal_expected_amount: number;
      subtotal_declared_amount: number;
      subtotal_variance_amount: number;
    }>;
  };
  audit_trail: Array<{
    id: string;
    action: string;
    actor_id: string | null;
    actor_name: string | null;
    actor_role: string | null;
    logged_at: string | null;
    payload: Record<string, unknown>;
  }>;
  links: { z_report_pdf: string; z_report_available: boolean };
}

// =============================================================================
//  HTTP layer
// =============================================================================

const base = (slug: string) => `/api/v1/shops/${slug}/till`;

export const shopTillTrackingService = {
  getDashboard: (slug: string, params: { trendDays?: TrendDays } = {}) => {
    const q = new URLSearchParams();
    if (params.trendDays) q.set("trend_days", String(params.trendDays));
    const qs = q.toString();
    return apiFetch<DashboardResponse>(`${base(slug)}/dashboard${qs ? `?${qs}` : ""}`);
  },

  listSessions: (slug: string, filters: SessionsListFilters) => {
    const q = new URLSearchParams();
    if (filters.from) q.set("from", filters.from);
    if (filters.to) q.set("to", filters.to);
    filters.till_id?.forEach((id) => q.append("till_id[]", id));
    filters.status?.forEach((s) => q.append("status[]", s));
    if (filters.opener_id) q.set("opener_id", filters.opener_id);
    if (filters.variance) q.set("variance", filters.variance);
    if (filters.force_abandoned !== undefined) {
      q.set("force_abandoned", filters.force_abandoned ? "1" : "0");
    }
    if (filters.per_page) q.set("per_page", String(filters.per_page));
    if (filters.page) q.set("page", String(filters.page));
    if (filters.sort) q.set("sort", filters.sort);
    return apiFetch<SessionsListResponse>(`${base(slug)}/sessions?${q.toString()}`);
  },

  getSessionDetail: (slug: string, id: string) =>
    apiFetch<{ data: SessionDetail }>(`${base(slug)}/sessions/${id}`),

  downloadZReport: (slug: string, id: string) =>
    apiFetch(`${base(slug)}/sessions/${id}/z-report.pdf`, { responseType: "blob" }),

  exportSessionsCsv: (slug: string, filters: SessionsListFilters) => {
    const q = new URLSearchParams();
    if (filters.from) q.set("from", filters.from);
    if (filters.to) q.set("to", filters.to);
    filters.till_id?.forEach((id) => q.append("till_id[]", id));
    filters.status?.forEach((s) => q.append("status[]", s));
    if (filters.opener_id) q.set("opener_id", filters.opener_id);
    if (filters.variance) q.set("variance", filters.variance);
    if (filters.force_abandoned !== undefined) {
      q.set("force_abandoned", filters.force_abandoned ? "1" : "0");
    }
    q.set("export", "csv");
    return apiFetch(`${base(slug)}/sessions?${q.toString()}`, { responseType: "blob" });
  },
};

// =============================================================================
//  Query key factory
// =============================================================================

export const shopTillTrackingKeys = {
  all: (slug: string) => ["shop", slug, "till-tracking"] as const,
  dashboard: (slug: string, trendDays: TrendDays) =>
    [...shopTillTrackingKeys.all(slug), "dashboard", trendDays] as const,
  sessions: (slug: string, filters: SessionsListFilters) =>
    [...shopTillTrackingKeys.all(slug), "sessions", filters] as const,
  session: (slug: string, id: string) =>
    [...shopTillTrackingKeys.all(slug), "session", id] as const,
};
