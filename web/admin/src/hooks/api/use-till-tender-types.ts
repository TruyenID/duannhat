/**
 * Till Tender Type Hooks — React wrappers around tillTenderTypeService.
 *
 * Same shape as use-denominations.ts: list reads merged (org-wide +
 * shop-scoped) rows; mutations affect only shop-scoped rows. Org-wide
 * rows surface in the UI as read-only badges.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  tillTenderTypeService,
  type CreateTillTenderTypeInput,
  type UpdateTillTenderTypeInput,
} from "@/services/till-tender-type-service";
import { useTranslation } from "@/providers/app-provider";

const keys = {
  all: (shopSlug: string) => ["till-tender-types", shopSlug] as const,
  list: (shopSlug: string) => [...keys.all(shopSlug), "list"] as const,
};

export function useTillTenderTypes(shopSlug: string) {
  return useQuery({
    queryKey: keys.list(shopSlug),
    queryFn: () => tillTenderTypeService.list(shopSlug),
    enabled: !!shopSlug,
  });
}

export function useCreateTillTenderType(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateTillTenderTypeInput) => tillTenderTypeService.create(shopSlug, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.all(shopSlug) });
      toast.success(t("settings.tender_type.toast.created"));
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useUpdateTillTenderType(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateTillTenderTypeInput }) =>
      tillTenderTypeService.update(shopSlug, id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.all(shopSlug) });
      toast.success(t("settings.tender_type.toast.updated"));
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useDeleteTillTenderType(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => tillTenderTypeService.remove(shopSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.all(shopSlug) });
      toast.success(t("settings.tender_type.toast.deleted"));
    },
    onError: (e: Error) => toast.error(e.message),
  });
}
