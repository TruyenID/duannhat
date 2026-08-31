"use client";

/**
 * Plan-032 T5.5c (frontend half) — Force-abandon activity widget.
 *
 * Consumes GET /pos/till/sessions/stale?group_by=actor&within_days=30 and
 * renders the per-manager rollup as a simple horizontal bar list. No
 * external chart library — the data is "N rows × 1 number" which a plain
 * div+width-style bar communicates as well as a charting lib and avoids
 * the bundle cost.
 *
 * Manager display name is NOT resolved here — the backend returns
 * manager_id only (intentional: SSO user cache lookup belongs to the
 * sidebar resolver, not this widget). The id is shown verbatim; a
 * follow-up can swap in a name resolver hook when one is added to
 * admin-web's user-cache infra.
 */

import { Card, CardContent, CardDescription, CardHeader, CardTitle, Skeleton } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useStaleActorSummary } from "@/hooks/api/use-till-sessions-admin";

export interface ForceAbandonActivityCardProps {
  shopSlug: string;
}

export function ForceAbandonActivityCard({ shopSlug }: ForceAbandonActivityCardProps) {
  const { t } = useTranslation();
  const { data, isLoading } = useStaleActorSummary(shopSlug);

  const summary = data?.data;
  const top = summary?.per_manager ?? [];
  const maxCount = top.length > 0 ? Math.max(...top.map((m) => m.count)) : 1;

  return (
    <Card data-slot="force-abandon-activity-card">
      <CardHeader>
        <CardTitle>{t("till_sessions.activity.title")}</CardTitle>
        {summary && (
          <CardDescription className="flex gap-4 text-xs">
            <span>
              {t("till_sessions.activity.force_abandoned_count")}:{" "}
              <strong>{summary.force_abandoned_count}</strong>
            </span>
            <span>
              {t("till_sessions.activity.expired_count")}: <strong>{summary.expired_count}</strong>
            </span>
          </CardDescription>
        )}
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <div className="space-y-2">
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-3/4" />
            <Skeleton className="h-4 w-1/2" />
          </div>
        ) : top.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("till_sessions.activity.no_data")}</p>
        ) : (
          <div>
            <div className="mb-2 text-xs font-medium text-muted-foreground">
              {t("till_sessions.activity.per_manager_title")}
            </div>
            <ul className="space-y-1.5">
              {top.map((row) => {
                const widthPct = Math.max(8, Math.round((row.count / maxCount) * 100));
                return (
                  <li key={row.manager_id} className="flex items-center gap-3 text-xs">
                    <div className="w-44 truncate font-mono" title={row.manager_id}>
                      {row.manager_id.slice(0, 8)}…
                    </div>
                    <div className="relative h-5 flex-1 rounded bg-muted/40">
                      <div
                        className="h-full rounded bg-primary/70"
                        style={{ width: `${widthPct}%` }}
                      />
                    </div>
                    <div className="w-8 text-right tabular-nums">{row.count}</div>
                  </li>
                );
              })}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
