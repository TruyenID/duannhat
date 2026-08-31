/**
 * Plan-023 M6 T6.11 — shop-scoped notification API client.
 * Wraps /api/v1/shops/{shopSlug}/notifications/*.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { AudienceRow, AudienceRule } from "@/services/notification-audience-service";

export interface ShopTemplateRow {
  id: string;
  organization_id: string;
  brand_id: string | null;
  branch_id: string | null;
  key: string;
  content: Record<string, { title?: string; body?: string }>;
  default_channels: string[] | null;
  params_schema: Record<string, unknown> | null;
  is_system: boolean;
}

export interface ShopChannelRouteRow {
  id: string;
  organization_id: string;
  brand_id: string | null;
  branch_id: string | null;
  type: string;
  channels: Record<string, boolean>;
  priority_overrides: Record<string, unknown> | null;
}

export interface ShopNotificationAuditRow {
  id: string;
  type: string;
  priority: string;
  created_at: string | null;
  aggregation_key: string | null;
}

const base = (shopSlug: string) => `/api/v1/shops/${shopSlug}/notifications`;

export const shopNotificationService = {
  // Audiences
  audiencesList(shopSlug: string, perPage = 25) {
    return apiFetch<PaginatedResponse<AudienceRow>>(
      `${base(shopSlug)}/audiences?per_page=${perPage}`
    );
  },
  audienceShow(shopSlug: string, id: string) {
    return apiFetch<{ data: AudienceRow }>(`${base(shopSlug)}/audiences/${id}`);
  },
  audienceCreate(
    shopSlug: string,
    payload: { name: string; description?: string; rule: AudienceRule }
  ) {
    return apiFetch<{ data: AudienceRow }>(`${base(shopSlug)}/audiences`, {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  audienceUpdate(
    shopSlug: string,
    id: string,
    payload: Partial<{ name: string; description: string; rule: AudienceRule }>
  ) {
    return apiFetch<{ data: AudienceRow }>(`${base(shopSlug)}/audiences/${id}`, {
      method: "PATCH",
      body: JSON.stringify(payload),
    });
  },
  audienceDelete(shopSlug: string, id: string) {
    return apiFetch<void>(`${base(shopSlug)}/audiences/${id}`, { method: "DELETE" });
  },

  // Templates
  templatesList(shopSlug: string, perPage = 25) {
    return apiFetch<PaginatedResponse<ShopTemplateRow>>(
      `${base(shopSlug)}/templates?per_page=${perPage}`
    );
  },
  templateCreate(
    shopSlug: string,
    payload: {
      key: string;
      content: Record<string, unknown>;
      default_channels?: string[];
      params_schema?: Record<string, unknown>;
    }
  ) {
    return apiFetch<{ data: ShopTemplateRow }>(`${base(shopSlug)}/templates`, {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  templateUpdate(
    shopSlug: string,
    id: string,
    payload: Partial<{
      content: Record<string, unknown>;
      default_channels: string[];
      params_schema: Record<string, unknown>;
    }>
  ) {
    return apiFetch<{ data: ShopTemplateRow }>(`${base(shopSlug)}/templates/${id}`, {
      method: "PATCH",
      body: JSON.stringify(payload),
    });
  },
  templateDelete(shopSlug: string, id: string) {
    return apiFetch<void>(`${base(shopSlug)}/templates/${id}`, { method: "DELETE" });
  },

  // Channel routes
  routesList(shopSlug: string) {
    return apiFetch<{ data: ShopChannelRouteRow[] }>(`${base(shopSlug)}/channel-routes`);
  },
  routeUpsert(
    shopSlug: string,
    payload: {
      type: string;
      channels: Record<string, boolean>;
      priority_overrides?: Record<string, unknown>;
    }
  ) {
    return apiFetch<{ data: ShopChannelRouteRow }>(`${base(shopSlug)}/channel-routes`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  routeDelete(shopSlug: string, type: string) {
    return apiFetch<void>(`${base(shopSlug)}/channel-routes/${type}`, { method: "DELETE" });
  },

  // Broadcast
  broadcast(
    shopSlug: string,
    payload: {
      audience_id: string;
      template_id: string;
      channels: string[];
      priority?: string;
      params?: Record<string, unknown>;
      scheduled_for?: string | null;
    }
  ) {
    return apiFetch<{ data: { id: string; type: string; scheduled_for: string | null } }>(
      `${base(shopSlug)}/broadcast`,
      { method: "POST", body: JSON.stringify(payload) }
    );
  },

  // Audit
  auditList(
    shopSlug: string,
    params: { type?: string; priority?: string; per_page?: number; page?: number } = {}
  ) {
    const qs = new URLSearchParams();
    if (params.type) qs.set("type", params.type);
    if (params.priority) qs.set("priority", params.priority);
    if (params.per_page) qs.set("per_page", String(params.per_page));
    if (params.page) qs.set("page", String(params.page));
    const tail = qs.toString();
    return apiFetch<PaginatedResponse<ShopNotificationAuditRow>>(
      `${base(shopSlug)}${tail ? `?${tail}` : ""}`
    );
  },
};
