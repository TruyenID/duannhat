/**
 * Substitution Rule Hooks — React-Query wrappers around substitution-rule-service.
 *
 * Rules are scoped to a primary material (HQ brand scope). Mutations invalidate
 * the per-material list to keep the substitution rules card in sync.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { useTranslation } from "@/providers/app-provider";
import {
  substitutionRuleService,
  type SubstitutionRuleInput,
} from "@/services/substitution-rule-service";

import { substitutionRuleKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useSubstitutionRules(brandSlug: string, materialId: string | undefined) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: materialId
      ? substitutionRuleKeys.list(brandSlug, locale, materialId)
      : ["substitution-rules", "list", "noop"],
    queryFn: () => substitutionRuleService.list(brandSlug, materialId!),
    enabled: !!brandSlug && !!materialId,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateSubstitutionRule(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (input: SubstitutionRuleInput) => substitutionRuleService.create(brandSlug, input),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: substitutionRuleKeys.all(brandSlug),
      });
      toast.success(t("material.substitution_created"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useUpdateSubstitutionRule(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: Partial<SubstitutionRuleInput> }) =>
      substitutionRuleService.update(brandSlug, id, input),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: substitutionRuleKeys.all(brandSlug),
      });
      toast.success(t("material.substitution_updated"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useDeleteSubstitutionRule(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (id: string) => substitutionRuleService.delete(brandSlug, id),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: substitutionRuleKeys.all(brandSlug),
      });
      toast.success(t("material.substitution_deleted"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}
