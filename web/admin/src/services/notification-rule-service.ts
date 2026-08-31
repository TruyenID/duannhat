/**
 * Plan-023 M7 T7.5 client — /api/v1/hq/{brandSlug}/notifications/rules*.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

export type RuleTriggerEvent = "model.created" | "model.updated" | "model.deleted" | string; // custom.*

export type RulePriority = "low" | "normal" | "high" | "urgent";

export interface RuleConditionsTree {
  combinator?: "and" | "or";
  children?: RuleConditionsTree[];
  field?: string;
  op?: string;
  value?: unknown;
}

export interface RuleAction {
  template_key: string;
  channels: string[];
  priority?: RulePriority;
  audience_name?: string;
  audience_rule?: Record<string, unknown>;
  param_template?: Record<string, string | number | boolean | null>;
}

export interface RuleRow {
  id: string;
  organization_id: string;
  brand_id: string | null;
  branch_id: string | null;
  name: string;
  description: string | null;
  trigger_event: RuleTriggerEvent;
  trigger_model_type: string | null;
  conditions: RuleConditionsTree;
  action: RuleAction;
  cooldown_minutes: number;
  is_active: boolean;
  last_fired_at: string | null;
  fire_count: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface RuleListFilters {
  is_active?: boolean;
  trigger_event?: string;
  per_page?: number;
  page?: number;
}

export interface RulePayload {
  name: string;
  description?: string | null;
  trigger_event: RuleTriggerEvent;
  trigger_model_type?: string | null;
  conditions: RuleConditionsTree;
  action: RuleAction;
  cooldown_minutes?: number;
  is_active?: boolean;
}

export interface FieldOptionsResponse {
  model: string;
  fields: Array<{
    name: string;
    type: string;
    ops_supported: string[];
  }>;
  relations: Array<{
    name: string;
    target_alias: string;
    type: string;
  }>;
}

export interface DryRunResponse {
  considered: number;
  matched_count: number;
  sample: Array<{
    id: string | number;
    updated_at: string | null;
    trace: Array<{
      field: string;
      op: string;
      expected: unknown;
      actual: unknown;
      pass: boolean;
    }>;
  }>;
  window_since: string;
}

const base = (brandSlug: string) => `/api/v1/hq/${brandSlug}/notifications/rules`;

export const notificationRuleService = {
  list(brandSlug: string, filters: RuleListFilters = {}) {
    const params = new URLSearchParams();
    if (filters.is_active !== undefined) params.set("is_active", String(filters.is_active));
    if (filters.trigger_event) params.set("trigger_event", filters.trigger_event);
    if (filters.per_page) params.set("per_page", String(filters.per_page));
    if (filters.page) params.set("page", String(filters.page));
    const qs = params.toString();
    return apiFetch<PaginatedResponse<RuleRow>>(`${base(brandSlug)}${qs ? `?${qs}` : ""}`);
  },

  show(brandSlug: string, id: string) {
    return apiFetch<{ data: RuleRow & { recent_firings: unknown[] } }>(`${base(brandSlug)}/${id}`);
  },

  create(brandSlug: string, payload: RulePayload) {
    return apiFetch<{ data: RuleRow }>(base(brandSlug), {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },

  update(brandSlug: string, id: string, payload: Partial<RulePayload>) {
    return apiFetch<{ data: RuleRow }>(`${base(brandSlug)}/${id}`, {
      method: "PATCH",
      body: JSON.stringify(payload),
    });
  },

  destroy(brandSlug: string, id: string) {
    return apiFetch<void>(`${base(brandSlug)}/${id}`, { method: "DELETE" });
  },

  dryRun(brandSlug: string, id: string, sinceDays = 7) {
    return apiFetch<{ data: DryRunResponse }>(
      `${base(brandSlug)}/${id}/dry-run?since_days=${sinceDays}`,
      { method: "POST" }
    );
  },

  fieldOptions(brandSlug: string, model: string) {
    return apiFetch<{ data: FieldOptionsResponse }>(
      `/api/v1/hq/${brandSlug}/notifications/rules/field-options?model=${encodeURIComponent(model)}`
    );
  },

  firings(
    brandSlug: string,
    ruleId: string,
    params: { outcome?: string; page?: number; per_page?: number } = {}
  ) {
    const qs = new URLSearchParams();
    if (params.outcome) qs.set("outcome", params.outcome);
    if (params.page) qs.set("page", String(params.page));
    if (params.per_page) qs.set("per_page", String(params.per_page));
    const tail = qs.toString();
    return apiFetch<PaginatedResponse<FiringRow>>(
      `${base(brandSlug)}/${ruleId}/firings${tail ? `?${tail}` : ""}`
    );
  },

  retryFiring(brandSlug: string, ruleId: string, firingId: string) {
    return apiFetch<{ data: FiringRow }>(`${base(brandSlug)}/${ruleId}/firings/${firingId}/retry`, {
      method: "POST",
    });
  },
};

export interface FiringRow {
  id: string;
  rule_id: string;
  notification_id: string | null;
  model_type: string | null;
  model_id: string | null;
  fired_at: string;
  outcome: "matched" | "skipped_cooldown" | "skipped_condition" | "shadow" | "error";
  evaluation_trace: Array<{
    field: string;
    op: string;
    expected: unknown;
    actual: unknown;
    pass: boolean;
  }> | null;
  error_message: string | null;
}
