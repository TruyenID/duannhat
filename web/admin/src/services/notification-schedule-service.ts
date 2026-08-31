/**
 * Notification Schedule service — wraps
 * /api/v1/hq/{brandSlug}/notifications/schedules*
 * (plan-023 M3 T3.5 backend + T3.8 frontend).
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

export type ScheduleStatus = "active" | "paused" | "completed" | "cancelled";

export type ChannelCode = "in_app" | "realtime" | "email" | "push";
export type Priority = "low" | "normal" | "high" | "urgent";

export interface ScheduleRow {
  id: string;
  organization_id: string;
  brand_id: string;
  template_key: string;
  audience_id: string;
  audience_name: string | null;
  channels: ChannelCode[];
  priority: Priority;
  params: Record<string, unknown> | null;
  rrule: string;
  timezone: string;
  starts_at: string | null;
  ends_at: string | null;
  occurrences_remaining: number | null;
  next_occurrence_at: string | null;
  last_occurrence_at: string | null;
  status: ScheduleStatus;
  created_by_id: string | null;
  created_at: string | null;
  updated_at: string | null;
  next_5_occurrences: string[];
}

export interface SchedulePayload {
  template_key: string;
  audience_id: string;
  channels: ChannelCode[];
  priority?: Priority;
  params?: Record<string, unknown> | null;
  rrule: string;
  timezone: string;
  starts_at: string;
  ends_at?: string | null;
  occurrences_remaining?: number | null;
}

export type SchedulePatchPayload = Partial<SchedulePayload>;

export interface PreviewRrulePayload {
  rrule: string;
  timezone: string;
  starts_at: string;
  count?: number;
}

export interface ScheduleListFilters {
  status?: ScheduleStatus;
  per_page?: number;
  page?: number;
}

const base = (brandSlug: string) => `/api/v1/hq/${brandSlug}/notifications/schedules`;

export const notificationScheduleService = {
  list(brandSlug: string, filters: ScheduleListFilters = {}) {
    const params = new URLSearchParams();
    if (filters.status) params.set("status", filters.status);
    if (filters.per_page) params.set("per_page", String(filters.per_page));
    if (filters.page) params.set("page", String(filters.page));
    const qs = params.toString();
    return apiFetch<PaginatedResponse<ScheduleRow>>(`${base(brandSlug)}${qs ? `?${qs}` : ""}`);
  },

  show(brandSlug: string, id: string) {
    return apiFetch<{ data: ScheduleRow }>(`${base(brandSlug)}/${id}`);
  },

  create(brandSlug: string, payload: SchedulePayload) {
    return apiFetch<{ data: ScheduleRow }>(base(brandSlug), {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  update(brandSlug: string, id: string, payload: SchedulePatchPayload) {
    return apiFetch<{ data: ScheduleRow }>(`${base(brandSlug)}/${id}`, {
      method: "PATCH",
      body: JSON.stringify(payload),
    });
  },

  cancel(brandSlug: string, id: string) {
    return apiFetch<void>(`${base(brandSlug)}/${id}`, { method: "DELETE" });
  },

  pause(brandSlug: string, id: string) {
    return apiFetch<{ data: ScheduleRow }>(`${base(brandSlug)}/${id}/pause`, { method: "POST" });
  },

  resume(brandSlug: string, id: string) {
    return apiFetch<{ data: ScheduleRow }>(`${base(brandSlug)}/${id}/resume`, { method: "POST" });
  },

  previewRrule(brandSlug: string, payload: PreviewRrulePayload) {
    return apiFetch<{ data: { occurrences: string[] } }>(`${base(brandSlug)}/preview-rrule`, {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
};
