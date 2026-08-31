/**
 * Table Defaults Hooks (issue #890) — shop-side "Nhận bàn mặc định từ HQ".
 *
 * Preview is read-only; apply copies the brand's zone/table templates into
 * real shop zones/tables (idempotent by code on the backend).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { tableService } from "@/services/table-service";
import { useTranslation } from "@/providers/app-provider";
import { tableDefaultsKeys, tableKeys, zoneKeys } from "./query-keys";

export function useTableDefaultsPreview(shopSlug: string, enabled: boolean = true) {
  return useQuery({
    queryKey: tableDefaultsKeys.preview(shopSlug),
    queryFn: () => tableService.defaultsPreview(shopSlug),
    enabled: !!shopSlug && enabled,
    // The preview depends on both HQ templates and local tables — always
    // refetch when the dialog opens instead of trusting a stale cache.
    staleTime: 0,
  });
}

export function useApplyTableDefaults(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: () => tableService.applyDefaults(shopSlug),
    onSuccess: (result) => {
      toast.success(
        t("shop.tables.defaults.toast_applied", {
          zones: result.zones_created,
          tables: result.tables_created,
        })
      );
      qc.invalidateQueries({ queryKey: zoneKeys.all(shopSlug) });
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
      qc.invalidateQueries({ queryKey: tableDefaultsKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("shop.tables.defaults.toast_failed")),
  });
}
