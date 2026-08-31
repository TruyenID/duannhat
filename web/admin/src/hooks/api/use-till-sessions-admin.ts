/**
 * Plan-032 — TanStack Query hooks for the admin "Ca treo" tab.
 *
 * Pairs with `services/till-session-admin-service.ts`. Mutations invalidate
 * the stale list + actor summary so the UI refreshes after force-abandon /
 * manual-settle without manual refetch wiring at the call site.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import {
  tillSessionAdminQueryKeys,
  tillSessionAdminService,
  type ForceAbandonInput,
  type ManualSettleInput,
  type StaleFilter,
} from "@/services/till-session-admin-service";
import { shopTillTrackingKeys } from "@/services/shop-till-tracking-service";

/**
 * Build the toast body for a failed manager exit-door call (#1178).
 *
 * These endpoints reject for reasons the manager can act on — 422 validation
 * (`errors`), 409 guards (`VARIANCE_REASON_REQUIRED`, `SHIFT_NOT_EXPIRED`,
 * unpaid orders still open), 403 policy. Swallowing the body left every one of
 * them showing the same "Kết ca thất bại" line, which is what made #1178 take
 * a code read to diagnose. Fall back to the generic label only when the API
 * gave us nothing.
 */
function apiErrorDetail(error: unknown): string | undefined {
  if (!(error instanceof ApiError)) return undefined;

  const body = error.body as {
    message?: string;
    errors?: Record<string, string[]>;
  };

  const fieldErrors = Object.values(body?.errors ?? {})
    .flat()
    .filter(Boolean);
  if (fieldErrors.length > 0) return fieldErrors.join(" · ");

  return body?.message || error.message || undefined;
}

/**
 * The stale list backing the "Ca treo" tab.
 *
 * Polls on the same 5s cadence as `useShopTillDashboard` (#1220). The KPI/tab
 * badge comes from the dashboard query and the rows come from here; leaving this
 * one on refetch-on-mount meant the badge ticked while the table sat frozen, so
 * "badge says 1, tab is empty" could reappear from cache staleness alone even
 * with the backend thresholds aligned.
 */
export function useStaleSessions(shopSlug: string, filter: StaleFilter, branchId?: string) {
  return useQuery({
    queryKey: tillSessionAdminQueryKeys.stale(shopSlug, filter, branchId),
    queryFn: () =>
      tillSessionAdminService.listStale(shopSlug, { filter, branch_id: branchId, per_page: 50 }),
    refetchInterval: 5_000,
    refetchIntervalInBackground: false,
    staleTime: 4_000,
    enabled: Boolean(shopSlug),
  });
}

export function useStaleActorSummary(shopSlug: string, withinDays = 30, branchId?: string) {
  return useQuery({
    queryKey: [...tillSessionAdminQueryKeys.actorSummary(shopSlug, branchId), withinDays] as const,
    queryFn: () => tillSessionAdminService.actorSummary(shopSlug, withinDays, branchId),
    enabled: Boolean(shopSlug),
  });
}

/**
 * Cash reconciliation for a single session — powers the manual-settle
 * dialog's "expected cash" reference. Only fetched while the dialog is open
 * (`enabled`), and never cached stale: the manager is about to reconcile real
 * money against it.
 */
export function useSessionReconciliation(
  shopSlug: string,
  sessionId: string | null,
  enabled = true
) {
  return useQuery({
    queryKey: tillSessionAdminQueryKeys.reconciliation(shopSlug, sessionId ?? ""),
    queryFn: () => tillSessionAdminService.reconciliation(shopSlug, sessionId as string),
    enabled: Boolean(shopSlug) && Boolean(sessionId) && enabled,
    staleTime: 0,
  });
}

export function useForceAbandonSession(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: ForceAbandonInput }) =>
      tillSessionAdminService.forceAbandon(shopSlug, id, data),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: tillSessionAdminQueryKeys.all });
      // The dashboard KPI + tab badge count the row we just resolved. Without this
      // the table empties instantly while the badge keeps showing it for up to ~10s
      // (5s client poll x 5s server cache bucket) — the exact "badge says 1, tab is
      // empty" symptom (#1220). `all(slug)` prefix-matches every trend_days variant.
      qc.invalidateQueries({ queryKey: shopTillTrackingKeys.all(shopSlug) });
      toast.success(t("till_sessions.force_abandon.success", { code: res.data.session_code }));
    },
    onError: (error) => {
      toast.error(t("till_sessions.force_abandon.error"), {
        description: apiErrorDetail(error),
      });
    },
  });
}

export function useManualSettleSession(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: ManualSettleInput }) =>
      tillSessionAdminService.manualSettle(shopSlug, id, data),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: tillSessionAdminQueryKeys.all });
      qc.invalidateQueries({ queryKey: shopTillTrackingKeys.all(shopSlug) });
      toast.success(t("till_sessions.manual_settle.success", { code: res.data.session_code }));
    },
    onError: (error) => {
      toast.error(t("till_sessions.manual_settle.error"), {
        description: apiErrorDetail(error),
      });
    },
  });
}
