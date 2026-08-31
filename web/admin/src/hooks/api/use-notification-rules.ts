/**
 * Plan-023 M7 T7.7 — TanStack Query wrappers for notification rules.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  notificationRuleService,
  type RuleListFilters,
  type RulePayload,
} from "@/services/notification-rule-service";

export const ruleKeys = {
  all: (brandSlug: string) => ["notifications", "rules", brandSlug] as const,
  list: (brandSlug: string, filters?: RuleListFilters) =>
    ["notifications", "rules", brandSlug, "list", filters] as const,
  detail: (brandSlug: string, id: string) =>
    ["notifications", "rules", brandSlug, "detail", id] as const,
  fieldOptions: (brandSlug: string, model: string) =>
    ["notifications", "rules", brandSlug, "field-options", model] as const,
};

export function useRules(brandSlug: string, filters: RuleListFilters = {}) {
  return useQuery({
    queryKey: ruleKeys.list(brandSlug, filters),
    queryFn: () => notificationRuleService.list(brandSlug, filters),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}

export function useRule(brandSlug: string, id: string | null) {
  return useQuery({
    queryKey: ruleKeys.detail(brandSlug, id ?? ""),
    queryFn: () => notificationRuleService.show(brandSlug, id!),
    enabled: !!id,
  });
}

export function useCreateRule(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: RulePayload) => notificationRuleService.create(brandSlug, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ruleKeys.all(brandSlug) }),
  });
}

export function useUpdateRule(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<RulePayload> }) =>
      notificationRuleService.update(brandSlug, id, payload),
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({ queryKey: ruleKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: ruleKeys.detail(brandSlug, vars.id) });
    },
  });
}

export function useDeleteRule(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationRuleService.destroy(brandSlug, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ruleKeys.all(brandSlug) }),
  });
}

export function useDryRunRule(brandSlug: string) {
  return useMutation({
    mutationFn: ({ id, sinceDays }: { id: string; sinceDays?: number }) =>
      notificationRuleService.dryRun(brandSlug, id, sinceDays),
  });
}

export function useFieldOptions(brandSlug: string, model: string | null) {
  return useQuery({
    queryKey: ruleKeys.fieldOptions(brandSlug, model ?? ""),
    queryFn: () => notificationRuleService.fieldOptions(brandSlug, model!),
    enabled: !!model,
    staleTime: 60_000,
  });
}

export function useRuleFirings(
  brandSlug: string,
  ruleId: string | null,
  params: { outcome?: string; page?: number; per_page?: number } = {}
) {
  return useQuery({
    queryKey: ["notifications", "rules", brandSlug, "firings", ruleId, params] as const,
    queryFn: () => notificationRuleService.firings(brandSlug, ruleId!, params),
    enabled: !!ruleId,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}

export function useRetryFiring(brandSlug: string, ruleId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (firingId: string) =>
      notificationRuleService.retryFiring(brandSlug, ruleId, firingId),
    onSuccess: () => {
      // A retry writes a new firing row and may bump the rule's fire_count.
      qc.invalidateQueries({ queryKey: ["notifications", "rules", brandSlug, "firings", ruleId] });
      qc.invalidateQueries({ queryKey: ruleKeys.detail(brandSlug, ruleId) });
    },
  });
}
