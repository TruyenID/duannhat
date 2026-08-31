/**
 * Recall Hooks — React-Query wrappers around recall-service.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { useTranslation } from "@/providers/app-provider";
import {
  recallService,
  type InitiateRecallInput,
  type RecallStatus,
} from "@/services/recall-service";

import { recallKeys } from "./query-keys";

export function useRecalls(
  brandSlug: string,
  filters: { status?: RecallStatus; search?: string; page?: number; per_page?: number } = {}
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: recallKeys.list(brandSlug, locale, filters),
    queryFn: () => recallService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useRecall(brandSlug: string, id: string | undefined) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: id ? recallKeys.detail(brandSlug, locale, id) : ["recalls", "detail", "noop"],
    queryFn: () => recallService.getById(brandSlug, id!),
    enabled: !!brandSlug && !!id,
  });
}

export function useRecallPreview(brandSlug: string, rootLotId: string | undefined) {
  return useQuery({
    queryKey: rootLotId
      ? ["recalls", brandSlug, "preview", rootLotId]
      : ["recalls", "preview", "noop"],
    queryFn: () => recallService.preview(brandSlug, rootLotId!),
    enabled: !!brandSlug && !!rootLotId,
  });
}

export function useRecallReport(brandSlug: string, id: string | undefined) {
  return useQuery({
    queryKey: id ? recallKeys.report(brandSlug, id) : ["recalls", "report", "noop"],
    queryFn: () => recallService.report(brandSlug, id!),
    enabled: !!brandSlug && !!id,
  });
}

export function useInitiateRecall(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (input: InitiateRecallInput) => recallService.initiate(brandSlug, input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recallKeys.all(brandSlug) });
      toast.success(t("recall.initiated"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useCompleteRecall(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => recallService.complete(brandSlug, id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recallKeys.all(brandSlug) });
      toast.success(t("recall.completed"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useCancelRecall(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      recallService.cancel(brandSlug, id, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recallKeys.all(brandSlug) });
      toast.success(t("recall.cancelled"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}
