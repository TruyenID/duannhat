/**
 * Notification Audience service — wraps /api/v1/hq/{brandSlug}/notifications/audiences*
 * (plan-012 T1.4 backend + T1.5 frontend).
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Rule shape (mirrors schemas/Backend/Notification/NotificationAudience.yaml)
// =========================================================================

export type AudienceRuleType = "role" | "user" | "shop" | "brand" | "device";

export interface AudienceSubRule {
  type: AudienceRuleType;
  role?: string;
  user_ids?: string[];
  shop_ids?: string[];
  brand_id?: string;
  device_types?: string[];
  branch_id?: string;
  include_members?: boolean;
  include_all_members?: boolean;
  scope?: Record<string, string>;
}

export interface AudienceRule {
  version?: number;
  combinator?: "or" | "and";
  rules: AudienceSubRule[];
  exclude?: AudienceSubRule[];
}

// =========================================================================
//  Domain row
// =========================================================================

export interface AudienceRow {
  id: string;
  name: string;
  description: string | null;
  rule: AudienceRule;
  brand_id: string | null;
  organization_id: string;
  is_system: boolean;
  created_by_id: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface AudiencePreviewSampleEntry {
  id: string;
  type: string;
  label: string;
}

export interface AudiencePreview {
  count: number;
  sample: AudiencePreviewSampleEntry[];
}

export interface StoreAudienceInput {
  name: string;
  description?: string | null;
  rule: AudienceRule;
  brand_id?: string | null;
}

export type UpdateAudienceInput = Partial<StoreAudienceInput>;

// =========================================================================
//  API calls
// =========================================================================

export const audienceService = {
  async list(brandSlug: string): Promise<PaginatedResponse<AudienceRow>> {
    return apiFetch<PaginatedResponse<AudienceRow>>(
      `/api/v1/hq/${brandSlug}/notifications/audiences`
    );
  },

  async show(brandSlug: string, id: string): Promise<AudienceRow> {
    const res = await apiFetch<{ data: AudienceRow }>(
      `/api/v1/hq/${brandSlug}/notifications/audiences/${id}`
    );
    return res.data;
  },

  async create(brandSlug: string, input: StoreAudienceInput): Promise<AudienceRow> {
    const res = await apiFetch<{ data: AudienceRow }>(
      `/api/v1/hq/${brandSlug}/notifications/audiences`,
      { method: "POST", body: JSON.stringify(input) }
    );
    return res.data;
  },

  async update(brandSlug: string, id: string, input: UpdateAudienceInput): Promise<AudienceRow> {
    const res = await apiFetch<{ data: AudienceRow }>(
      `/api/v1/hq/${brandSlug}/notifications/audiences/${id}`,
      { method: "PATCH", body: JSON.stringify(input) }
    );
    return res.data;
  },

  async destroy(brandSlug: string, id: string): Promise<void> {
    await apiFetch<void>(`/api/v1/hq/${brandSlug}/notifications/audiences/${id}`, {
      method: "DELETE",
    });
  },

  async preview(brandSlug: string, rule: AudienceRule): Promise<AudiencePreview> {
    const res = await apiFetch<{ data: AudiencePreview }>(
      `/api/v1/hq/${brandSlug}/notifications/audiences/preview`,
      { method: "POST", body: JSON.stringify({ rule }) }
    );
    return res.data;
  },
};
