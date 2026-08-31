/**
 * Denomination Hooks — React wrappers around denominationService.
 *
 * Shop-scoped CRUD (one shop = one organization context). Reads return
 * global + org-scoped rows; mutations only affect org-scoped rows. Globals
 * surface as read-only in the UI.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  denominationService,
  type CreateDenominationInput,
  type UpdateDenominationInput,
} from "@/services/denomination-service";
import { useTranslation } from "@/providers/app-provider";

const keys = {
  all: (shopSlug: string) => ["denominations", shopSlug] as const,
  list: (shopSlug: string, currency?: string) =>
    [...keys.all(shopSlug), "list", currency ?? "all"] as const,
};

export function useDenominations(shopSlug: string, currency?: string) {
  return useQuery({
    queryKey: keys.list(shopSlug, currency),
    queryFn: () => denominationService.list(shopSlug, currency),
    enabled: !!shopSlug,
  });
}

export function useCreateDenomination(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateDenominationInput) => denominationService.create(shopSlug, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.all(shopSlug) });
      toast.success(t("settings.denomination.toast.created"));
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useUpdateDenomination(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateDenominationInput }) =>
      denominationService.update(shopSlug, id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.all(shopSlug) });
      toast.success(t("settings.denomination.toast.updated"));
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useDeleteDenomination(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => denominationService.remove(shopSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.all(shopSlug) });
      toast.success(t("settings.denomination.toast.deleted"));
    },
    onError: (e: Error) => toast.error(e.message),
  });
}
