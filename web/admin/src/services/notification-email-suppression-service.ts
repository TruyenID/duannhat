/**
 * Plan-023 M4 T4.9 — wraps /api/v1/hq/{brandSlug}/notifications/email-suppressions*.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

export type SuppressionReason = "hard_bounce" | "spam_complaint" | "subscription_change" | "manual";

export interface SuppressionRow {
  id: string;
  organization_id: string;
  email: string;
  reason: SuppressionReason;
  source_provider: string | null;
  suppressed_at: string;
  un_suppressed_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface SuppressionFilters {
  reason?: SuppressionReason;
  active_only?: boolean;
  per_page?: number;
  page?: number;
  /** ISO 8601 — only suppressions on/after this instant. */
  from?: string;
  /** ISO 8601 — only suppressions on/before this instant. */
  to?: string;
}

export interface EmailHealthMetrics {
  sent: number;
  delivered: number;
  bounced: number;
  spam: number;
  unsubscribed: number;
}

export interface EmailHealthMetricsResponse {
  data: EmailHealthMetrics;
  meta: { from: string; to: string };
}

export interface EmailHealthTimeseriesBucket {
  /** YYYY-MM-DD */
  date: string;
  sent: number;
  delivered: number;
  bounced: number;
  spam: number;
}

export interface EmailHealthTimeseriesResponse {
  data: EmailHealthTimeseriesBucket[];
  meta: { from: string; to: string };
}

const base = (brandSlug: string) => `/api/v1/hq/${brandSlug}/notifications/email-suppressions`;

export const notificationEmailSuppressionService = {
  list(brandSlug: string, filters: SuppressionFilters = {}) {
    const params = new URLSearchParams();
    if (filters.reason) params.set("reason", filters.reason);
    if (filters.active_only !== undefined)
      params.set("active_only", filters.active_only ? "1" : "0");
    if (filters.per_page) params.set("per_page", String(filters.per_page));
    if (filters.page) params.set("page", String(filters.page));
    if (filters.from) params.set("from", filters.from);
    if (filters.to) params.set("to", filters.to);
    const qs = params.toString();
    return apiFetch<PaginatedResponse<SuppressionRow>>(`${base(brandSlug)}${qs ? `?${qs}` : ""}`);
  },

  store(brandSlug: string, payload: { email: string; reason?: SuppressionReason }) {
    return apiFetch<{ data: SuppressionRow }>(base(brandSlug), {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  unsuppress(brandSlug: string, id: string) {
    return apiFetch<void>(`${base(brandSlug)}/${id}`, { method: "DELETE" });
  },

  metrics(brandSlug: string, range: { from?: string; to?: string } = {}) {
    const params = new URLSearchParams();
    if (range.from) params.set("from", range.from);
    if (range.to) params.set("to", range.to);
    const qs = params.toString();
    return apiFetch<EmailHealthMetricsResponse>(
      `/api/v1/hq/${brandSlug}/notifications/email-health/metrics${qs ? `?${qs}` : ""}`
    );
  },

  timeseries(brandSlug: string, range: { from?: string; to?: string } = {}) {
    const params = new URLSearchParams();
    if (range.from) params.set("from", range.from);
    if (range.to) params.set("to", range.to);
    const qs = params.toString();
    return apiFetch<EmailHealthTimeseriesResponse>(
      `/api/v1/hq/${brandSlug}/notifications/email-health/metrics/timeseries${qs ? `?${qs}` : ""}`
    );
  },
};
