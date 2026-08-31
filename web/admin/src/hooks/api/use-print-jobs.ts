/**
 * Print job hooks — plan-052 M2 / T2.2 (#1166).
 *
 * There is no retry mutation here and there must never be one: the queue is
 * owned by the workstation for `ws_lan` jobs (DESIGN §1b) and reprinting a
 * money document goes through the reprint gate (P-10). `useResolvePrintJob`
 * records a manager's note about a job — it does not touch the ledger row.
 *
 * The resolve error is deliberately NOT toasted here. 409 (already printed),
 * 403 (cashier) and 422 (missing reason) each need a sentence rendered inside
 * the dialog next to the field the manager is looking at; a toast that vanishes
 * in four seconds is the wrong shape for "the record disagrees with you".
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  printJobService,
  type PrintJobFilters,
  type ResolvePrintJobInput,
} from "@/services/print-job-service";

export const printJobKeys = {
  all: (shopSlug: string) => ["print-jobs", shopSlug] as const,
  list: (shopSlug: string, filters?: object) => ["print-jobs", shopSlug, "list", filters] as const,
  detail: (shopSlug: string, id: string) => ["print-jobs", shopSlug, "detail", id] as const,
};

export function usePrintJobs(shopSlug: string, filters: PrintJobFilters = {}) {
  return useQuery({
    queryKey: printJobKeys.list(shopSlug, filters),
    queryFn: () => printJobService.list(shopSlug, filters),
    enabled: !!shopSlug,
    placeholderData: keepPreviousData,
  });
}

export function usePrintJob(shopSlug: string, id: string) {
  return useQuery({
    queryKey: printJobKeys.detail(shopSlug, id),
    queryFn: () => printJobService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
    // A ledger row can change under the operator (the journal arrives, the
    // reconcile sweep flags it). Never serve a stale decision surface.
    staleTime: 0,
  });
}

export function useResolvePrintJob(shopSlug: string, id: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (input: ResolvePrintJobInput) => printJobService.resolve(shopSlug, id, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: printJobKeys.all(shopSlug) });
    },
  });
}
