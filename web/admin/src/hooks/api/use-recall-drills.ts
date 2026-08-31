/**
 * Recall Drill Hooks — React-Query wrappers around recall-drill-service.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { useTranslation } from "@/providers/app-provider";
import { recallDrillService, type RunDrillInput } from "@/services/recall-drill-service";

import { recallDrillKeys } from "./query-keys";

export function useRecallDrills(
  brandSlug: string,
  filters: { page?: number; per_page?: number } = {}
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: recallDrillKeys.list(brandSlug, locale, filters),
    queryFn: () => recallDrillService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useRunDrill(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (input: RunDrillInput) => recallDrillService.run(brandSlug, input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recallDrillKeys.all(brandSlug) });
      toast.success(t("recall_drill.run_success"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}
