/**
 * Notification template service (plan-012 T2.5) — wraps
 * /api/v1/hq/{brandSlug}/notifications/templates*.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

export type TemplateLocaleContent = { title: string; body: string };

export type TemplateContent = Record<"ja" | "en" | "vi", TemplateLocaleContent>;

export interface TemplateParamsSchema {
  required?: string[];
  optional?: string[];
}

export interface TemplateRow {
  id: string;
  key: string;
  content: TemplateContent;
  default_channels: string[] | null;
  params_schema: TemplateParamsSchema | null;
  brand_id: string | null;
  organization_id: string | null;
  is_system: boolean;
  created_by_id: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface StoreTemplateInput {
  key: string;
  content: TemplateContent;
  default_channels?: string[];
  params_schema?: TemplateParamsSchema | null;
  brand_id?: string | null;
}

export type UpdateTemplateInput = Partial<StoreTemplateInput>;

export interface RenderedTemplate {
  ja?: TemplateLocaleContent;
  en?: TemplateLocaleContent;
  vi?: TemplateLocaleContent;
}

export const templateService = {
  async list(brandSlug: string): Promise<PaginatedResponse<TemplateRow>> {
    return apiFetch<PaginatedResponse<TemplateRow>>(
      `/api/v1/hq/${brandSlug}/notifications/templates`
    );
  },

  async create(brandSlug: string, input: StoreTemplateInput): Promise<TemplateRow> {
    const res = await apiFetch<{ data: TemplateRow }>(
      `/api/v1/hq/${brandSlug}/notifications/templates`,
      { method: "POST", body: JSON.stringify(input) }
    );
    return res.data;
  },

  async update(brandSlug: string, id: string, input: UpdateTemplateInput): Promise<TemplateRow> {
    const res = await apiFetch<{ data: TemplateRow }>(
      `/api/v1/hq/${brandSlug}/notifications/templates/${id}`,
      { method: "PATCH", body: JSON.stringify(input) }
    );
    return res.data;
  },

  async destroy(brandSlug: string, id: string): Promise<void> {
    await apiFetch<void>(`/api/v1/hq/${brandSlug}/notifications/templates/${id}`, {
      method: "DELETE",
    });
  },

  async duplicate(brandSlug: string, id: string): Promise<TemplateRow> {
    const res = await apiFetch<{ data: TemplateRow }>(
      `/api/v1/hq/${brandSlug}/notifications/templates/${id}/duplicate`,
      { method: "POST" }
    );
    return res.data;
  },

  async renderSaved(
    brandSlug: string,
    templateId: string,
    params: Record<string, unknown>
  ): Promise<RenderedTemplate> {
    const res = await apiFetch<{ data: RenderedTemplate }>(
      `/api/v1/hq/${brandSlug}/notifications/templates/render`,
      { method: "POST", body: JSON.stringify({ template_id: templateId, params }) }
    );
    return res.data;
  },

  async renderInline(
    brandSlug: string,
    content: TemplateContent,
    params: Record<string, unknown>
  ): Promise<RenderedTemplate> {
    const res = await apiFetch<{ data: RenderedTemplate }>(
      `/api/v1/hq/${brandSlug}/notifications/templates/render`,
      {
        method: "POST",
        body: JSON.stringify({
          template_inline: { content },
          params,
        }),
      }
    );
    return res.data;
  },
};
