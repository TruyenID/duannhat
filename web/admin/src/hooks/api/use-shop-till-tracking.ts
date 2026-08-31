/**
 * Plan-036 — Manager Till Tracking React Query hooks.
 *
 * useShopTillDashboard polls every 5s while the tab is visible. The other
 * three hooks refetch on mount + invalidation only.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { ApiError } from "@/lib/api";
import {
  shopTillTrackingKeys,
  shopTillTrackingService,
  type SessionsListFilters,
  type TrendDays,
} from "@/services/shop-till-tracking-service";
import { useTranslation } from "@/providers/app-provider";

/**
 * Map a backend ApiError to a locale-aware toast message. The backend
 * envelopes errors as { code: "Z_REPORT_NOT_READY", message: "Z-report is
 * only available..." } — message is English. Project convention elsewhere
 * shows err.message verbatim, which leaks English to ja/vi locale. This
 * resolver looks up `till_tracking.errors.{code}` first and only falls
 * back to a translated generic when no key matches — never to the raw
 * backend message.
 */
function resolveTrackingError(
  err: unknown,
  t: (key: string) => string,
  fallbackKey: string
): string {
  if (err instanceof ApiError) {
    const code = err.body?.code;
    if (typeof code === "string" && code.length > 0) {
      const candidate = `till_tracking.errors.${code}`;
      const translated = t(candidate);
      if (translated !== candidate) return translated;
    }
  }
  return t(fallbackKey);
}

export function useShopTillDashboard(
  slug: string,
  options: { trendDays?: TrendDays; enabled?: boolean } = {}
) {
  const trendDays = options.trendDays ?? 14;
  return useQuery({
    queryKey: shopTillTrackingKeys.dashboard(slug, trendDays),
    queryFn: () => shopTillTrackingService.getDashboard(slug, { trendDays }),
    refetchInterval: 5_000,
    refetchIntervalInBackground: false,
    staleTime: 4_000,
    enabled: options.enabled ?? Boolean(slug),
  });
}

export function useShopTillSessions(
  slug: string,
  filters: SessionsListFilters,
  options: { enabled?: boolean } = {}
) {
  return useQuery({
    queryKey: shopTillTrackingKeys.sessions(slug, filters),
    queryFn: () => shopTillTrackingService.listSessions(slug, filters),
    // Caller may gate the request behind client-side validation (e.g. when
    // the date range is `from > to`) to avoid a guaranteed 422 round-trip
    // while the user is mid-edit. Defaults to "request once slug resolves".
    enabled: (options.enabled ?? true) && Boolean(slug),
    staleTime: 30_000,
  });
}

export function useShopTillSessionDetail(slug: string, id: string | undefined) {
  return useQuery({
    queryKey: shopTillTrackingKeys.session(slug, id ?? ""),
    queryFn: () => shopTillTrackingService.getSessionDetail(slug, id ?? ""),
    enabled: Boolean(slug) && Boolean(id),
    staleTime: 30_000,
  });
}

/**
 * Download the Z-report PDF blob and trigger a browser download. Returns a
 * mutation so the consumer can chain `isPending` to a spinner and rely on
 * the existing onError toast wiring.
 */
export function useDownloadZReport(slug: string) {
  const { t } = useTranslation();
  return useMutation({
    mutationFn: async ({ id, sessionCode }: { id: string; sessionCode: string }) => {
      const blob = await shopTillTrackingService.downloadZReport(slug, id);
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `z-report-${sessionCode}.pdf`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    },
    onError: (err: unknown) => {
      toast.error(resolveTrackingError(err, t, "till_tracking.z_report.error"));
    },
  });
}

/**
 * Download a CSV export of the current session list using the active filters.
 */
export function useExportSessionsCsv(slug: string) {
  const { t } = useTranslation();
  return useMutation({
    mutationFn: async (filters: SessionsListFilters) => {
      const blob = await shopTillTrackingService.exportSessionsCsv(slug, filters);
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      const from = filters.from ?? "all";
      const to = filters.to ?? "all";
      a.download = `sessions-${from}-to-${to}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    },
    onError: (err: unknown) => {
      toast.error(resolveTrackingError(err, t, "till_tracking.sessions.export_error"));
    },
  });
}

export function useInvalidateShopTillTracking(slug: string) {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: shopTillTrackingKeys.all(slug) });
}
