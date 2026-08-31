/**
 * Till Tender Activation Hooks — #1156 per-branch tender activation.
 *
 * READ: GET /shops/{slug}/till/tender-types — the branch's EFFECTIVE tender
 * list (org vocabulary with branch overrides applied). NOTE (backend wave-1
 * limitation): the endpoint filters to ACTIVE rows, so a tender deactivated
 * in an earlier session does not come back on reload and can only be
 * re-activated by key via the PATCH. To keep the immediate flow usable, the
 * toggle below does NOT refetch after success — the just-disabled row stays
 * visible (switch off) so the operator can revert in place.
 *
 * WRITE: PATCH /shops/{slug}/till/tender-types/{tenderKey} { is_active } —
 * materializes/updates the branch-scoped override row; org-wide seeded rows
 * are never mutated. Optimistic with snapshot rollback + toast on error.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { tillTenderActivationService } from "@/services/till-tender-activation-service";
import type { TillTenderType } from "@/services/till-tender-type-service";
import { useTranslation } from "@/providers/app-provider";

const keys = {
  all: (shopSlug: string) => ["till-tender-activation", shopSlug] as const,
  list: (shopSlug: string) => ["till-tender-activation", shopSlug, "list"] as const,
};

export const tillTenderActivationKeys = keys;

/** Effective tender list for the branch (active rows, server-sourced). */
export function useTillTenderActivation(shopSlug: string) {
  return useQuery({
    queryKey: keys.list(shopSlug),
    queryFn: () => tillTenderActivationService.list(shopSlug),
    enabled: !!shopSlug,
  });
}

export function useToggleTillTenderActivation(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({ tenderKey, isActive }: { tenderKey: string; isActive: boolean }) =>
      tillTenderActivationService.update(shopSlug, tenderKey, isActive),

    onMutate: async ({ tenderKey, isActive }) => {
      await qc.cancelQueries({ queryKey: keys.list(shopSlug) });
      const previous = qc.getQueryData<{ data: TillTenderType[] }>(keys.list(shopSlug));
      qc.setQueryData<{ data: TillTenderType[] } | undefined>(keys.list(shopSlug), (prev) =>
        prev
          ? {
              data: prev.data.map((row) =>
                row.tender_key === tenderKey ? { ...row, is_active: isActive } : row
              ),
            }
          : prev
      );
      return { previous };
    },

    onError: (err: Error, _vars, context) => {
      if (context?.previous) {
        qc.setQueryData(keys.list(shopSlug), context.previous);
      }
      toast.error(err.message || t("shop.tenders.toast.failed"));
    },

    onSuccess: (res) => {
      // Reconcile with the authoritative override row IN PLACE — deliberately
      // no invalidation: a refetch would drop just-disabled rows (the GET
      // filters to active), stranding the operator with no way to revert.
      qc.setQueryData<{ data: TillTenderType[] } | undefined>(keys.list(shopSlug), (prev) =>
        prev
          ? {
              data: prev.data.map((row) =>
                row.tender_key === res.data.tender_key ? { ...row, ...res.data } : row
              ),
            }
          : prev
      );
      toast.success(t("shop.tenders.toast.saved"));
    },
  });
}
