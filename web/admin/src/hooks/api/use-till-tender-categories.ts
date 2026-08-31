/**
 * Till Tender Category Hooks — React wrappers around
 * tillTenderCategoryService. Mirrors use-till-tender-types in shape so
 * the tender-types tab can treat both lists identically.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  tillTenderCategoryService,
  type CreateTillTenderCategoryInput,
  type UpdateTillTenderCategoryInput,
} from "@/services/till-tender-category-service";
import { useTranslation } from "@/providers/app-provider";

const keys = {
  all: (shopSlug: string) => ["till-tender-categories", shopSlug] as const,
  list: (shopSlug: string) => [...keys.all(shopSlug), "list"] as const,
};

export function useTillTenderCategories(shopSlug: string) {
  return useQuery({
    queryKey: keys.list(shopSlug),
    queryFn: () => tillTenderCategoryService.list(shopSlug),
    enabled: !!shopSlug,
  });
}

export function useCreateTillTenderCategory(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateTillTenderCategoryInput) =>
      tillTenderCategoryService.create(shopSlug, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.all(shopSlug) });
      toast.success(t("settings.tender_category.toast.created"));
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useUpdateTillTenderCategory(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateTillTenderCategoryInput }) =>
      tillTenderCategoryService.update(shopSlug, id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.all(shopSlug) });
      toast.success(t("settings.tender_category.toast.updated"));
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useDeleteTillTenderCategory(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => tillTenderCategoryService.remove(shopSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: keys.all(shopSlug) });
      toast.success(t("settings.tender_category.toast.deleted"));
    },
    onError: (e: Error) => toast.error(e.message),
  });
}
