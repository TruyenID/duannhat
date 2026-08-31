/**
 * Notification Service — pure TypeScript, no React dependency.
 *
 * Wraps /api/v1/me/notifications/* (plan-008 Phase A). Admin-audit
 * endpoints under /hq/{brandSlug}/notifications are covered by a separate
 * service layer once that page lands (plan-008 T8.5).
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export type NotificationPriority = "low" | "normal" | "high" | "urgent";

export type NotificationStatus = "unread" | "read" | "all";

export interface NotificationMorph {
  type: string;
  id: string;
  display_name: string | null;
}

export interface NotificationRecipientState {
  seen_at: string | null;
  read_at: string | null;
  dismissed_at: string | null;
}

export interface NotificationRow {
  id: string;
  type: string;
  template_key: string;
  title: string;
  body: string;
  params: Record<string, unknown>;
  priority: NotificationPriority;
  actor: NotificationMorph | null;
  subject: NotificationMorph | null;
  aggregation_key: string | null;
  created_at: string | null;
  recipient: NotificationRecipientState | null;
}

export interface NotificationSummary {
  unread_count: number;
  priority_breakdown: Record<NotificationPriority, number>;
  latest_created_at: string | null;
}

export interface InboxFilters {
  status?: NotificationStatus;
  type?: string;
  priority?: NotificationPriority;
  since?: string;
  per_page?: number;
  page?: number;
  include_dismissed?: boolean;
  // Plan-023 M5 T5.3 — collapse by aggregation_key. Default `false` here
  // preserves the flat plan-008 shape every caller (bell, /inbox) was
  // built against; the collapsed shape ships in a follow-up FE pass.
  collapse?: boolean;
  aggregation_key?: string;
}

export type AdminActorTypeFilter = "User" | "Device" | "null";

export interface AdminNotificationFilters {
  type?: string[];
  actor_type?: AdminActorTypeFilter;
  actor_id?: string;
  recipient_type?: "User" | "Device";
  recipient_id?: string;
  priority?: NotificationPriority;
  from?: string;
  to?: string;
  organization_id?: string;
  search?: string;
  sort?: string;
  per_page?: number;
  page?: number;
}

export interface AdminNotificationRecipientsSummary {
  total: number;
  seen: number;
  read: number;
  dismissed: number;
}

export interface AdminNotificationListRow {
  id: string;
  type: string;
  template_key: string;
  params: Record<string, unknown>;
  priority: NotificationPriority;
  actor: NotificationMorph | null;
  subject: NotificationMorph | null;
  organization: { id: string; name: string | null } | null;
  aggregation_key: string | null;
  created_at: string | null;
  recipients_summary: AdminNotificationRecipientsSummary;
}

export interface AdminNotificationDetailRecipient {
  id: string;
  recipient: NotificationMorph | null;
  seen_at: string | null;
  read_at: string | null;
  dismissed_at: string | null;
  resolved_via: string | null;
}

export interface AdminNotificationDetail extends Omit<
  AdminNotificationListRow,
  "recipients_summary"
> {
  idempotency_key: string | null;
  recipients: AdminNotificationDetailRecipient[];
}

export interface AdminNotificationType {
  type: string;
  count: number;
  last_at: string | null;
}

export interface AdminNotificationStats {
  sent: number;
  scheduled: number;
  failed: number;
}

// =========================================================================
//  API calls
// =========================================================================

export const notificationService = {
  async list(filters: InboxFilters = {}): Promise<PaginatedResponse<NotificationRow>> {
    const params = new URLSearchParams();
    // Plan-023 M5 T5.3 — default `collapse=false` to keep current callers
    // (bell, /inbox) on the flat plan-008 shape until the collapsed payload
    // ships its own UI. Callers that want collapse opt in by passing true.
    const merged: InboxFilters = { collapse: false, ...filters };
    Object.entries(merged).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== "") {
        params.set(k, String(v));
      }
    });
    const q = params.toString();
    return apiFetch<PaginatedResponse<NotificationRow>>(
      `/api/v1/me/notifications${q ? `?${q}` : ""}`
    );
  },

  async summary(): Promise<NotificationSummary> {
    const res = await apiFetch<{ data: NotificationSummary }>("/api/v1/me/notifications/summary");
    return res.data;
  },

  async markSeen(id: string): Promise<void> {
    await apiFetch<void>(`/api/v1/me/notifications/${id}/seen`, {
      method: "PATCH",
    });
  },

  async markRead(id: string): Promise<void> {
    await apiFetch<void>(`/api/v1/me/notifications/${id}/read`, {
      method: "PATCH",
    });
  },

  async dismiss(id: string): Promise<void> {
    await apiFetch<void>(`/api/v1/me/notifications/${id}/dismissed`, {
      method: "PATCH",
    });
  },

  async bulkRead(input: {
    ids?: string[];
    all_unread?: boolean;
  }): Promise<{ marked_count: number }> {
    const res = await apiFetch<{ data: { marked_count: number } }>(
      "/api/v1/me/notifications/bulk-read",
      {
        method: "POST",
        body: JSON.stringify(input),
      }
    );
    return res.data;
  },

  async bulkDismiss(input: {
    ids?: string[];
    all_read?: boolean;
    all?: boolean;
  }): Promise<{ dismissed_count: number }> {
    const res = await apiFetch<{ data: { dismissed_count: number } }>(
      "/api/v1/me/notifications/bulk-dismiss",
      {
        method: "POST",
        body: JSON.stringify(input),
      }
    );
    return res.data;
  },
};

// =========================================================================
//  HQ admin audit
// =========================================================================

export const notificationAdminService = {
  async list(
    brandSlug: string,
    filters: AdminNotificationFilters = {}
  ): Promise<PaginatedResponse<AdminNotificationListRow>> {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([k, v]) => {
      if (v === undefined || v === null || v === "") return;
      if (Array.isArray(v)) {
        v.forEach((item) => params.append(`${k}[]`, String(item)));
      } else {
        params.set(k, String(v));
      }
    });
    const q = params.toString();
    return apiFetch<PaginatedResponse<AdminNotificationListRow>>(
      `/api/v1/hq/${brandSlug}/notifications${q ? `?${q}` : ""}`
    );
  },

  async detail(brandSlug: string, id: string): Promise<AdminNotificationDetail> {
    const res = await apiFetch<{ data: AdminNotificationDetail }>(
      `/api/v1/hq/${brandSlug}/notifications/${id}`
    );
    return res.data;
  },

  async types(brandSlug: string): Promise<AdminNotificationType[]> {
    const res = await apiFetch<{ data: AdminNotificationType[] }>(
      `/api/v1/hq/${brandSlug}/notifications/types`
    );
    return res.data;
  },

  async stats(brandSlug: string): Promise<AdminNotificationStats> {
    const res = await apiFetch<{ data: AdminNotificationStats }>(
      `/api/v1/hq/${brandSlug}/notifications/stats`
    );
    return res.data;
  },
};
