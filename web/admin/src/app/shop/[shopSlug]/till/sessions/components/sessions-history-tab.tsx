"use client";

/**
 * Plan-036 — Sessions history view.
 *
 * Sole content of `/shop/{slug}/till/sessions` since the Stale tab was
 * removed — the filter bar (date-range + status chips + force_abandoned
 * toggle + opener + till) covers the workflow the old Stale tab handled.
 *
 * `/till?tab=history&status=open,closing` seeds the status chips through the
 * page wrapper's `initialFilters` prop; that is the link the currency guard in
 * shop settings uses to show what is blocking it (#1221). `?filter=…` is a
 * different axis and belongs to the Ca treo tab — it is not read here.
 */

import { useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import { Badge, Button, Card, CardContent, Skeleton } from "@godxjp/ui";

import { formatCurrency } from "@/lib/currency";
import { formatDateTime } from "@/lib/date";
import { useShopTimezone } from "@/providers/shop-timezone-provider";
import { useExportSessionsCsv, useShopTillSessions } from "@/hooks/api/use-shop-till-tracking";
import type { SessionsListFilters, TillStatus } from "@/services/shop-till-tracking-service";
import { useTranslation } from "@/providers/app-provider";

interface Props {
  shopSlug: string;
  /**
   * Seeds the filter state on first mount — `/till?tab=history&status=…`
   * carries a status preset in through here. Changes to this prop after mount
   * are ignored: the filter UI owns subsequent state. Pass `undefined` for the
   * default (no preset).
   */
  initialFilters?: SessionsListFilters;
}

const STATUS_VALUES: TillStatus[] = ["open", "closing", "settled", "abandoned", "expired"];

/** Matches `ListTillSessionsRequest::withValidator()` — server rejects > 365d. */
const MAX_RANGE_DAYS = 365;

/** Format a Date as `YYYY-MM-DD` in the LOCAL timezone — `toISOString()` shifts
 *  by the UTC offset which can land the user on the wrong day near midnight. */
function toLocalDateString(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

/** Today's date in the local tz, as YYYY-MM-DD — used as the absolute upper
 *  bound on both inputs (future shifts don't exist). */
function todayLocal(): string {
  return toLocalDateString(new Date());
}

/** Difference in whole days between two YYYY-MM-DD strings, inclusive of
 *  both endpoints. Lexical compare on the string is enough to detect
 *  `from > to`; the day count uses UTC midnight to avoid DST drift. */
function diffDaysInclusive(from: string, to: string): number {
  const f = Date.parse(`${from}T00:00:00Z`);
  const t = Date.parse(`${to}T00:00:00Z`);
  if (!Number.isFinite(f) || !Number.isFinite(t)) return 0;
  return Math.round((t - f) / 86_400_000) + 1;
}

/**
 * Validate the from/to pair against the backend contract:
 *   - `from <= to` (server returns 422 `INVALID_DATE_RANGE` on inversion)
 *   - range ≤ 365 days (server's `withValidator()` cap)
 *   - neither date is in the future (UI-only sanity — backend silently
 *     returns zero rows, but we surface the mistake at entry time)
 *
 * Returns a translation key for the user-visible message, or null when
 * both dates are valid (or either is empty — empty means "use server
 * default", which is fine).
 */
function validateRange(from: string | undefined, to: string | undefined): string | null {
  if (!from || !to) return null;
  if (from > to) return "till_tracking.sessions.range_invalid_order";
  if (diffDaysInclusive(from, to) > MAX_RANGE_DAYS) {
    return "till_tracking.sessions.range_too_wide";
  }
  const today = todayLocal();
  if (from > today || to > today) return "till_tracking.sessions.range_future";
  return null;
}

export function SessionsHistoryTab({ shopSlug, initialFilters }: Props) {
  const { t, locale } = useTranslation();
  // Shop-scoped screen: timestamps belong to the shop's clock, not the
  // viewer's browser (#1248). Null outside a shop route, which makes
  // formatDateTime fall back to the old behaviour.
  const shopTimezone = useShopTimezone();
  const router = useRouter();
  const [filters, setFilters] = useState<SessionsListFilters>(
    initialFilters ?? { per_page: 25, page: 1 }
  );

  const today = useMemo(() => todayLocal(), []);
  const rangeError = validateRange(filters.from, filters.to);
  const rangeIsValid = rangeError === null;

  // Skip the query while the range is invalid so the user doesn't see a
  // 422 / empty table flicker while they're mid-edit. The Export CSV
  // button is also disabled below.
  const { data, isLoading, isError, refetch } = useShopTillSessions(shopSlug, filters, {
    enabled: rangeIsValid,
  });
  const exportCsv = useExportSessionsCsv(shopSlug);

  const rows = useMemo(() => data?.data ?? [], [data]);

  const toggleStatus = (status: TillStatus) => {
    setFilters((prev) => {
      const next = new Set(prev.status ?? []);
      if (next.has(status)) {
        next.delete(status);
      } else {
        next.add(status);
      }
      return { ...prev, status: Array.from(next), page: 1 };
    });
  };

  /** Apply a relative preset: "last N days ending today", inclusive. */
  const applyPreset = (days: number) => {
    const toDate = new Date();
    const fromDate = new Date();
    fromDate.setDate(toDate.getDate() - (days - 1)); // inclusive: 7d = today + prior 6
    setFilters((p) => ({
      ...p,
      from: toLocalDateString(fromDate),
      to: toLocalDateString(toDate),
      page: 1,
    }));
  };

  const clearDates = () => setFilters((p) => ({ ...p, from: undefined, to: undefined, page: 1 }));

  return (
    <div className="space-y-3">
      {/* Filter bar */}
      <Card>
        <CardContent className="space-y-2 p-3">
          {/* Row 1 — date range + presets */}
          <div className="flex flex-wrap items-end gap-2">
            <div className="flex flex-col gap-0.5">
              <label
                htmlFor="till-sessions-from"
                className="text-[11px] font-medium text-muted-foreground"
              >
                {t("till_tracking.sessions.filter_from")}
              </label>
              <input
                id="till-sessions-from"
                type="date"
                value={filters.from ?? ""}
                // Clamp upper bound to whichever comes first: today, or the
                // current `to`. Lower bound left open so a user can pick an
                // older `from` first, then widen `to` — validateRange() will
                // surface the range-too-wide error if applicable.
                max={filters.to && filters.to < today ? filters.to : today}
                aria-invalid={!rangeIsValid || undefined}
                onChange={(e) =>
                  setFilters((p) => ({ ...p, from: e.target.value || undefined, page: 1 }))
                }
                className="rounded border px-2 py-1 text-sm"
              />
            </div>
            <div className="flex flex-col gap-0.5">
              <label
                htmlFor="till-sessions-to"
                className="text-[11px] font-medium text-muted-foreground"
              >
                {t("till_tracking.sessions.filter_to")}
              </label>
              <input
                id="till-sessions-to"
                type="date"
                value={filters.to ?? ""}
                // Bounded by `from` on the lower side and today on the upper
                // side. The lower bound prevents picking `to < from` at the
                // OS picker level on browsers that respect `min`.
                min={filters.from || undefined}
                max={today}
                aria-invalid={!rangeIsValid || undefined}
                onChange={(e) =>
                  setFilters((p) => ({ ...p, to: e.target.value || undefined, page: 1 }))
                }
                className="rounded border px-2 py-1 text-sm"
              />
            </div>
            <div className="flex flex-wrap gap-1">
              <Button variant="outline" size="sm" onClick={() => applyPreset(1)}>
                {t("till_tracking.sessions.preset_today")}
              </Button>
              <Button variant="outline" size="sm" onClick={() => applyPreset(7)}>
                {t("till_tracking.sessions.preset_7d")}
              </Button>
              <Button variant="outline" size="sm" onClick={() => applyPreset(30)}>
                {t("till_tracking.sessions.preset_30d")}
              </Button>
              <Button variant="outline" size="sm" onClick={() => applyPreset(90)}>
                {t("till_tracking.sessions.preset_90d")}
              </Button>
              <Button
                variant="ghost"
                size="sm"
                onClick={clearDates}
                disabled={!filters.from && !filters.to}
              >
                {t("till_tracking.sessions.preset_clear")}
              </Button>
            </div>
            <div className="ml-auto">
              <Button
                variant="outline"
                size="sm"
                onClick={() => exportCsv.mutate(filters)}
                // Hold export when the range is invalid OR when the export
                // request is already in-flight.
                disabled={!rangeIsValid || exportCsv.isPending}
              >
                {exportCsv.isPending
                  ? t("till_tracking.sessions.exporting")
                  : t("till_tracking.sessions.export_csv")}
              </Button>
            </div>
          </div>

          {/* Row 2 — status chips */}
          <div className="flex flex-wrap items-center gap-1">
            {STATUS_VALUES.map((status) => (
              <Badge
                key={status}
                variant={filters.status?.includes(status) ? "default" : "outline"}
                onClick={() => toggleStatus(status)}
                className="cursor-pointer"
              >
                {t(`till_tracking.sessions.status.${status}`)}
              </Badge>
            ))}
          </div>

          {/* Row 3 — inline error message OR caption with the active range */}
          {rangeError ? (
            <p className="text-xs text-destructive" role="alert">
              {t(rangeError, { max: MAX_RANGE_DAYS })}
            </p>
          ) : (
            <p className="text-[11px] text-muted-foreground">
              {filters.from && filters.to
                ? t("till_tracking.sessions.range_caption_active", {
                    days: diffDaysInclusive(filters.from, filters.to),
                  })
                : t("till_tracking.sessions.range_caption_default")}
            </p>
          )}
        </CardContent>
      </Card>

      {isError ? (
        <Card>
          <CardContent className="space-y-2 p-6 text-center text-sm">
            <p>{t("common.error_loading")}</p>
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              {t("common.retry")}
            </Button>
          </CardContent>
        </Card>
      ) : isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : rows.length === 0 ? (
        <Card>
          <CardContent className="p-6 text-center text-sm text-muted-foreground">
            {t("till_tracking.sessions.empty")}
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/30 text-xs">
                  <th className="px-3 py-2 text-left">{t("till_tracking.sessions.col_code")}</th>
                  <th className="px-3 py-2 text-left">{t("till_tracking.sessions.col_till")}</th>
                  <th className="px-3 py-2 text-left">{t("till_tracking.sessions.col_opener")}</th>
                  <th className="px-3 py-2 text-left">{t("till_tracking.sessions.col_opened")}</th>
                  <th className="px-3 py-2 text-left">{t("till_tracking.sessions.col_status")}</th>
                  <th className="px-3 py-2 text-right">
                    {t("till_tracking.sessions.col_payments")}
                  </th>
                  <th className="px-3 py-2 text-right">
                    {t("till_tracking.sessions.col_revenue")}
                  </th>
                  <th className="px-3 py-2 text-right">
                    {t("till_tracking.sessions.col_variance")}
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr
                    key={row.id}
                    className="cursor-pointer border-b hover:bg-muted/30"
                    onClick={() => router.push(`/shop/${shopSlug}/till/sessions/${row.id}`)}
                  >
                    <td className="px-3 py-2 font-medium text-emerald-700">{row.session_code}</td>
                    <td className="px-3 py-2">{row.till?.name ?? "—"}</td>
                    <td className="px-3 py-2">{row.opener_name ?? "—"}</td>
                    <td className="px-3 py-2 text-xs">
                      {row.opened_at ? formatDateTime(row.opened_at, locale, shopTimezone) : "—"}
                    </td>
                    <td className="px-3 py-2">
                      <Badge variant="outline">
                        {t(`till_tracking.sessions.status.${row.status}`)}
                      </Badge>
                    </td>
                    <td className="px-3 py-2 text-right font-mono">{row.payment_count}</td>
                    <td className="px-3 py-2 text-right font-mono">
                      {formatCurrency(
                        row.gross_revenue_amount,
                        locale,
                        row.currency_code ?? undefined
                      )}
                    </td>
                    <td
                      className={`px-3 py-2 text-right font-mono ${
                        row.cash_variance_amount === null || row.cash_variance_amount === 0
                          ? "text-muted-foreground"
                          : row.cash_variance_amount > 0
                            ? "text-emerald-600"
                            : "text-red-600"
                      }`}
                    >
                      {row.cash_variance_amount === null
                        ? "—"
                        : formatCurrency(
                            row.cash_variance_amount,
                            locale,
                            row.currency_code ?? undefined
                          )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {data?.meta && (
              <div className="flex items-center justify-between p-3 text-xs text-muted-foreground">
                <span>
                  {t("till_tracking.sessions.pager", {
                    from: (data.meta.current_page - 1) * data.meta.per_page + 1,
                    to: Math.min(data.meta.current_page * data.meta.per_page, data.meta.total),
                    total: data.meta.total,
                  })}
                </span>
                <div className="flex gap-1">
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={data.meta.current_page <= 1}
                    onClick={() => setFilters((p) => ({ ...p, page: (p.page ?? 1) - 1 }))}
                  >
                    {t("till_tracking.sessions.prev")}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={data.meta.current_page >= data.meta.last_page}
                    onClick={() => setFilters((p) => ({ ...p, page: (p.page ?? 1) + 1 }))}
                  >
                    {t("till_tracking.sessions.next")}
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
