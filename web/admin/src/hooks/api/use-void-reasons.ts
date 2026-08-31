/**
 * Void Reason hooks — plan-051 (#1149). Brand-scoped master CRUD
 * (index / create / update / soft-deactivate — no delete by design:
 * historical order lines reference reasons by id).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  voidReasonService,
  type CreateVoidReasonInput,
  type UpdateVoidReasonInput,
} from "@/services/void-reason-service";
import { useTranslation } from "@/providers/app-provider";
import { voidReasonKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useVoidReasons(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: voidReasonKeys.list(brandSlug, locale),
    queryFn: () => voidReasonService.list(brandSlug),
    enabled: !!brandSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateVoidReason(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateVoidReasonInput) => voidReasonService.create(brandSlug, data),
    onSuccess: () => {
      toast.success(t("toast.void_reason.created"));
      qc.invalidateQueries({ queryKey: voidReasonKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.void_reason.create_failed")),
  });
}

export function useUpdateVoidReason(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateVoidReasonInput }) =>
      voidReasonService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success(t("toast.void_reason.updated"));
      qc.invalidateQueries({ queryKey: voidReasonKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.void_reason.update_failed")),
  });
}

export function useToggleVoidReasonStatus(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, currentIsActive }: { id: string; currentIsActive: boolean }) =>
      voidReasonService.toggleStatus(brandSlug, id, currentIsActive),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: voidReasonKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.void_reason.update_failed")),
  });
}
