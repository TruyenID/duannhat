"use client";

/**
 * Plan-032 + Plan-036 — "Ca treo" panel.
 *
 * Manager view of stale / expired shifts with force-abandon + manual-settle
 * actions. Extracted from the old /till/sessions page so it can live as one of
 * the three tabs on the consolidated /till dashboard (#1005). Fully
 * self-contained: owns its filter state, data hook, and the two action dialogs.
 */

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Skeleton,
  Tabs,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";

import { useTranslation } from "@/providers/app-provider";
import {
  useForceAbandonSession,
  useManualSettleSession,
  useStaleSessions,
} from "@/hooks/api/use-till-sessions-admin";
import type {
  ForceAbandonInput,
  ManualSettleInput,
  StaleFilter,
  StaleSession,
} from "@/services/till-session-admin-service";
import type { DashboardResponse } from "@/services/shop-till-tracking-service";

import { ForceAbandonActivityCard } from "../sessions/components/force-abandon-activity-card";
import { ForceAbandonDialog } from "../sessions/components/force-abandon-dialog";
import { ManualSettleDialog } from "../sessions/components/manual-settle-dialog";
import { StaleSessionsTable } from "../sessions/components/stale-sessions-table";

export const FILTERS: StaleFilter[] = ["open_overdue", "expired", "all_terminal"];
export const isStaleFilter = (v: string | null): v is StaleFilter =>
  v !== null && (FILTERS as string[]).includes(v);

export type StaleCounts = DashboardResponse["data"]["kpis"]["stale_count"];

export interface StaleSessionsPanelProps {
  shopSlug: string;
  /** Preselected stale filter (e.g. from a `?filter=open_overdue` deep link). */
  initialFilter?: StaleFilter;
  /**
   * Dashboard KPI breakdown, used for the per-sub-tab count badges and to pick
   * the bucket that actually holds rows. Undefined until the dashboard query
   * resolves — the panel renders fine without it.
   */
  counts?: StaleCounts;
}

export function StaleSessionsPanel({ shopSlug, initialFilter, counts }: StaleSessionsPanelProps) {
  const { t } = useTranslation();
  const router = useRouter();

  const [filter, setFilter] = useState<StaleFilter>(initialFilter ?? "open_overdue");
  const [forceTarget, setForceTarget] = useState<StaleSession | null>(null);
  const [settleTarget, setSettleTarget] = useState<StaleSession | null>(null);

  /**
   * #1220 — land on the bucket that has rows.
   *
   * The tab badge is `open_overdue + expired`, so a count of 1 living entirely in
   * `expired` used to drop the manager on an empty `open_overdue` sub-tab under a
   * "No stale shifts. Good!" empty state.
   *
   * This is React's "adjust state while rendering" pattern, not an effect: it
   * resolves before the browser paints, so the wrong sub-tab is never shown and no
   * request is fired for it. It runs at most once (`autoLanded` latches), which is
   * what keeps the 5s dashboard poll from yanking the sub-tab out from under a
   * manager when the counts later cross the boundary. A `key` remount on the
   * resolved filter would be worse still — it would close an open ManualSettleDialog
   * and destroy hand-entered denomination counts mid-count. A `?filter=` deep link
   * or an explicit click always wins.
   */
  const [autoLanded, setAutoLanded] = useState(false);
  if (!autoLanded && counts) {
    setAutoLanded(true);
    if (!initialFilter && counts.open_overdue === 0 && counts.expired > 0) setFilter("expired");
  }

  /**
   * True only when nothing is stuck anywhere — the one case where the celebratory
   * empty state is honest. `all_terminal` always gets the neutral copy: it lists
   * settled/abandoned shifts, so "all shifts are operating normally" is a non sequitur.
   * Without counts we can't rule out the other bucket, so stay neutral.
   */
  const allClear =
    filter === "open_overdue" && counts !== undefined && counts.open_overdue === 0 && counts.expired === 0;

  const filterCount = (f: StaleFilter): number | null => {
    if (!counts) return null;
    if (f === "open_overdue") return counts.open_overdue;
    if (f === "expired") return counts.expired;
    return null; // all_terminal has no KPI counterpart
  };

  const { data, isLoading, isError, refetch } = useStaleSessions(shopSlug, filter);
  const forceAbandonMut = useForceAbandonSession(shopSlug);
  const manualSettleMut = useManualSettleSession(shopSlug);

  const rows = useMemo(() => data?.data ?? [], [data]);
  const meta = data?.meta;

  const handleViewDetail = (s: StaleSession) => {
    router.push(`/shop/${shopSlug}/till/sessions/${s.id}`);
  };

  const handleConfirmForceAbandon = (input: ForceAbandonInput) => {
    if (!forceTarget) return;
    forceAbandonMut.mutate(
      { id: forceTarget.id, data: input },
      { onSuccess: () => setForceTarget(null) }
    );
  };

  const handleConfirmManualSettle = (input: ManualSettleInput) => {
    if (!settleTarget) return;
    manualSettleMut.mutate(
      { id: settleTarget.id, data: input },
      { onSuccess: () => setSettleTarget(null) }
    );
  };

  return (
    <div data-slot="stale-sessions-panel" className="space-y-4">
      <ForceAbandonActivityCard shopSlug={shopSlug} />

      <Card>
        <CardHeader>
          <CardTitle>{t("till_sessions.page_title")}</CardTitle>
          {meta && (
            <CardDescription>
              {/* The backend reports the threshold that actually selected these rows:
                  the 24h overdue band for open_overdue, the 48h reaper cutoff for the
                  terminal filters. Two different nouns, so two different labels. */}
              {filter === "open_overdue"
                ? t("till_sessions.overdue_threshold_label")
                : t("till_sessions.threshold_label")}
              : {meta.threshold_hours}h · {t("till_sessions.total_count", { count: meta.total })}
            </CardDescription>
          )}
        </CardHeader>
        <CardContent className="space-y-4">
          <Tabs value={filter} onValueChange={(v) => setFilter(v as StaleFilter)}>
            <TabsList>
              {FILTERS.map((f) => {
                const count = filterCount(f);
                return (
                  <TabsTrigger key={f} value={f}>
                    {t(`till_sessions.filter.${f}`)}
                    {count !== null && count > 0 && (
                      <Badge variant="destructive" className="px-1.5">
                        {count}
                      </Badge>
                    )}
                  </TabsTrigger>
                );
              })}
            </TabsList>
          </Tabs>

          {isLoading ? (
            <div className="space-y-2">
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
              <Skeleton className="h-9 w-full" />
            </div>
          ) : isError ? (
            <Alert variant="destructive">
              <AlertTitle>{t("till_sessions.error_loading")}</AlertTitle>
              <AlertDescription>
                <button
                  type="button"
                  onClick={() => refetch()}
                  className="text-sm underline underline-offset-2"
                >
                  {t("till_sessions.retry")}
                </button>
              </AlertDescription>
            </Alert>
          ) : rows.length === 0 ? (
            // "No stale shifts. Good!" is a claim about the whole shop, so it may only
            // be shown when nothing is stuck anywhere. On a filtered view — or while
            // another bucket still holds rows — it actively contradicts the badge the
            // manager just clicked, which is how #1220 was reported in the first place.
            <div className="rounded-md border border-dashed p-8 text-center text-sm">
              <div className="font-medium">
                {allClear
                  ? t("till_sessions.empty_state_title")
                  : t("till_sessions.empty_state_filtered_title")}
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                {allClear
                  ? t("till_sessions.empty_state_body")
                  : t("till_sessions.empty_state_filtered_body")}
              </p>
            </div>
          ) : (
            <StaleSessionsTable
              rows={rows}
              onViewDetail={handleViewDetail}
              onForceAbandon={setForceTarget}
              onManualSettle={setSettleTarget}
            />
          )}
        </CardContent>
      </Card>

      <ForceAbandonDialog
        open={forceTarget !== null}
        sessionCode={forceTarget?.session_code ?? ""}
        isPending={forceAbandonMut.isPending}
        onOpenChange={(o) => !o && !forceAbandonMut.isPending && setForceTarget(null)}
        onConfirm={handleConfirmForceAbandon}
      />

      <ManualSettleDialog
        open={settleTarget !== null}
        shopSlug={shopSlug}
        session={settleTarget}
        isPending={manualSettleMut.isPending}
        onOpenChange={(o) => !o && !manualSettleMut.isPending && setSettleTarget(null)}
        onConfirm={handleConfirmManualSettle}
      />
    </div>
  );
}
