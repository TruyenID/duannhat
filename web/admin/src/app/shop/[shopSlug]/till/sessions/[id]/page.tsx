"use client";

/**
 * Plan-036 — Manager Till Tracking Session Detail.
 *
 * Read-only single-session view: header + opening / closing denomination
 * grids + cash event timeline + per-tender reconciliation table + audit
 * trail + Z-report download. Manager exit-door dialogs (force-abandon,
 * manual-settle) are reused from plan-032.
 */

import { useState } from "react";
import Link from "next/link";
import { notFound, useParams } from "next/navigation";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Skeleton,
} from "@godxjp/ui";

import type { ApiError } from "@/lib/api";
import { formatCurrency } from "@/lib/currency";
import { formatDateTime } from "@/lib/date";
import { useShopTimezone, useShopTimezoneLabel } from "@/providers/shop-timezone-provider";
import type { LocaleCode } from "@/i18n";
import {
  useDownloadZReport,
  useInvalidateShopTillTracking,
  useShopTillSessionDetail,
} from "@/hooks/api/use-shop-till-tracking";
import {
  useForceAbandonSession,
  useManualSettleSession,
} from "@/hooks/api/use-till-sessions-admin";
import type {
  ForceAbandonInput,
  ManualSettleInput,
  StaleSession,
} from "@/services/till-session-admin-service";
import type { SessionDetail } from "@/services/shop-till-tracking-service";
import { useTranslation } from "@/providers/app-provider";

import { ForceAbandonDialog } from "../components/force-abandon-dialog";
import { ManualSettleDialog } from "../components/manual-settle-dialog";

export default function ShopTillSessionDetailPage() {
  const { t, locale } = useTranslation();
  // Shop-scoped screen: timestamps belong to the shop's clock, not the
  // viewer's browser (#1248). Null outside a shop route, which makes
  // formatDateTime fall back to the old behaviour.
  const shopTimezone = useShopTimezone();
  const shopTimezoneLabel = useShopTimezoneLabel(locale);
  const params = useParams<{ shopSlug: string; id: string }>();
  const shopSlug = params.shopSlug;
  const sessionId = params.id;

  const { data, isLoading, isError, error, refetch } = useShopTillSessionDetail(
    shopSlug,
    sessionId
  );
  const downloadZReport = useDownloadZReport(shopSlug);
  const invalidateTracking = useInvalidateShopTillTracking(shopSlug);
  const forceAbandonMut = useForceAbandonSession(shopSlug);
  const manualSettleMut = useManualSettleSession(shopSlug);

  const [forceAbandonOpen, setForceAbandonOpen] = useState(false);
  const [manualSettleOpen, setManualSettleOpen] = useState(false);

  if (isError) {
    const status = (error as ApiError)?.status;
    if (status === 404 || status === 403) notFound();
    return (
      <div className="flex h-full flex-col items-center justify-center gap-3 p-6 text-sm text-muted-foreground">
        <p>{t("common.error_loading")}</p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          {t("common.retry")}
        </Button>
      </div>
    );
  }

  if (isLoading || !data) {
    return (
      <div className="space-y-3 p-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-40 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  const session = data.data;
  const currency = session.currency_code ?? "JPY";
  const fmt = (amount: number | null | undefined) => formatCurrency(amount ?? 0, locale, currency);
  const sign = (v: number | null | undefined) =>
    v === 0 || v === null
      ? "text-muted-foreground"
      : (v ?? 0) > 0
        ? "text-emerald-600"
        : "text-red-600";

  // Show the manager exit-door buttons based on session status:
  //   • open / closing → force-abandon (plan-032 "give up on this shift")
  //   • expired        → manual-settle (plan-032 "reconcile this stuck shift")
  // settled / abandoned shifts are terminal — no further action.
  const canForceAbandon = session.status === "open" || session.status === "closing";
  const canManualSettle = session.status === "expired";
  // ManualSettleDialog (plan-032) reads a StaleSession-shaped object. The
  // plan-036 detail endpoint returns a richer SessionDetail; this adapter
  // narrows it to the fields the dialog reads so we can reuse plan-032 as-is
  // without bending its API. See plan-032 components/manual-settle-dialog.tsx
  // for the exact field set the dialog consumes.
  const staleSessionAdapter = canManualSettle ? sessionDetailToStale(session) : null;

  const handleConfirmForceAbandon = (input: ForceAbandonInput) => {
    forceAbandonMut.mutate(
      { id: session.id, data: input },
      {
        onSuccess: () => {
          setForceAbandonOpen(false);
          invalidateTracking();
          refetch();
        },
      }
    );
  };

  const handleConfirmManualSettle = (input: ManualSettleInput) => {
    manualSettleMut.mutate(
      { id: session.id, data: input },
      {
        onSuccess: () => {
          setManualSettleOpen(false);
          invalidateTracking();
          refetch();
        },
      }
    );
  };

  return (
    <div data-slot="shop-till-session-detail" className="space-y-4 p-6">
      <div className="flex items-start justify-between">
        <div>
          <Link
            href={`/shop/${shopSlug}/till?tab=history`}
            className="text-sm text-muted-foreground hover:underline"
          >
            ← {t("till_tracking.detail.back_to_list")}
          </Link>
          <h1 className="mt-2 text-xl font-semibold">
            {session.session_code}{" "}
            <Badge variant={statusBadgeVariant(session.status)}>
              {t(`till_tracking.detail.status.${session.status}`)}
            </Badge>
          </h1>
          <p className="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted-foreground">
            <span>
              {session.till?.name} · {session.business_date} · {session.currency_code}
              {/* The business date is the SHOP's day (computed by BusinessClock,
                  #1091) and every timestamp below is now drawn in the shop's
                  zone too. The label says whose clock that is — shown only when
                  it differs from the reader's, since a Tokyo manager reading a
                  Tokyo shift needs no explanation. */}
              {shopTimezoneLabel ? ` · ${shopTimezoneLabel}` : ""}
            </span>
            {session.prices_include_tax_at_open != null && (
              <Badge variant="outline">
                {t("till_tracking.detail.tax_mode_at_open")}:{" "}
                {session.prices_include_tax_at_open
                  ? t("till_tracking.detail.tax_mode_included")
                  : t("till_tracking.detail.tax_mode_excluded")}
              </Badge>
            )}
          </p>
        </div>
        <div className="flex gap-2">
          {canForceAbandon && (
            <Button
              variant="destructive"
              disabled={forceAbandonMut.isPending}
              onClick={() => setForceAbandonOpen(true)}
            >
              {t("till_sessions.actions.force_abandon")}
            </Button>
          )}
          {canManualSettle && (
            <Button
              variant="outline"
              disabled={manualSettleMut.isPending}
              onClick={() => setManualSettleOpen(true)}
            >
              {t("till_sessions.actions.manual_settle")}
            </Button>
          )}
          <Button
            disabled={!session.links.z_report_available || downloadZReport.isPending}
            onClick={() =>
              downloadZReport.mutate({ id: session.id, sessionCode: session.session_code })
            }
            title={
              session.links.z_report_available
                ? undefined
                : t("till_tracking.detail.z_report_not_ready")
            }
          >
            {downloadZReport.isPending
              ? t("till_tracking.detail.printing_z_report")
              : t("till_tracking.detail.print_z_report")}
          </Button>
        </div>
      </div>

      <ForceAbandonDialog
        open={forceAbandonOpen}
        sessionCode={session.session_code}
        isPending={forceAbandonMut.isPending}
        onOpenChange={(o) => !o && !forceAbandonMut.isPending && setForceAbandonOpen(false)}
        onConfirm={handleConfirmForceAbandon}
      />

      <ManualSettleDialog
        open={manualSettleOpen}
        shopSlug={shopSlug}
        session={staleSessionAdapter}
        isPending={manualSettleMut.isPending}
        varianceTolerance={session.till?.variance_tolerance_amount ?? null}
        onOpenChange={(o) => !o && !manualSettleMut.isPending && setManualSettleOpen(false)}
        onConfirm={handleConfirmManualSettle}
      />

      {session.force_abandoned && (
        <Alert variant="destructive">
          <AlertTitle>{t("till_tracking.detail.force_abandoned_alert")}</AlertTitle>
          <AlertDescription>
            {t("till_tracking.detail.force_abandoned_reason", {
              code: session.force_abandon_reason_code
                ? t(`till_sessions.force_abandon.reason_code.${session.force_abandon_reason_code}`)
                : t("till_tracking.detail.unknown_value"),
              actor: session.force_abandoned_by?.name ?? t("till_tracking.detail.unknown_value"),
            })}
            {session.force_abandon_reason_detail && (
              <p className="mt-1 text-xs">{session.force_abandon_reason_detail}</p>
            )}
          </AlertDescription>
        </Alert>
      )}

      {/* Opening + Closing summary side-by-side */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <DenominationCard
          title={t("till_tracking.detail.opening_title")}
          rows={session.opening_counts}
          totalLabel={t("till_tracking.detail.opening_total")}
          totalValue={fmt(session.opening_float_amount)}
          note={session.opening_note}
          locale={locale}
          currency={currency}
        />
        <DenominationCard
          title={t("till_tracking.detail.closing_title")}
          rows={session.closing_counts}
          totalLabel={t("till_tracking.detail.closing_total")}
          totalValue={fmt(session.counted_cash_amount)}
          note={session.closing_note}
          locale={locale}
          currency={currency}
        />
      </div>

      {/* Cash variance summary */}
      <Card>
        <CardHeader>
          <CardTitle>{t("till_tracking.detail.cash_variance_title")}</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Metric
            label={t("till_tracking.detail.expected_cash")}
            value={fmt(session.expected_cash_amount)}
          />
          <Metric
            label={t("till_tracking.detail.counted_cash")}
            value={fmt(session.counted_cash_amount)}
          />
          <Metric
            label={t("till_tracking.detail.cash_variance")}
            value={fmt(session.cash_variance_amount)}
            valueClass={sign(session.cash_variance_amount)}
          />
        </CardContent>
      </Card>

      {/* Cash events */}
      {session.cash_events.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{t("till_tracking.detail.cash_events_title")}</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="divide-y text-sm">
              {session.cash_events.map((event) => (
                <li key={event.id} className="flex items-center justify-between py-2">
                  <div>
                    <div className="font-medium">
                      {translateOrFallback(
                        t,
                        `till_tracking.event_type.${event.event_type}`,
                        event.event_type
                      )}
                    </div>
                    <div className="text-xs text-muted-foreground">
                      {event.occurred_at ? formatDateTime(event.occurred_at, locale, shopTimezone) : "—"} ·{" "}
                      {event.reason ?? "—"}
                    </div>
                  </div>
                  <div className="font-mono">{fmt(event.amount)}</div>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      {/* Reconciliation */}
      <Card>
        <CardHeader>
          <CardTitle>{t("till_tracking.detail.reconciliation_title")}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {session.reconciliation.by_tender_category.map((cat) => (
            <div key={cat.category}>
              <h3 className="mb-2 text-sm font-semibold text-muted-foreground">
                {translateOrFallback(t, `till_tracking.category.${cat.category}`, cat.category)}
              </h3>
              {cat.tender_rows.length === 0 ? (
                <p className="text-xs text-muted-foreground">—</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b text-xs text-muted-foreground">
                        <th className="py-1 pr-4 text-left">
                          {t("till_tracking.detail.col_tender")}
                        </th>
                        <th className="py-1 pr-4 text-right">
                          {t("till_tracking.detail.col_expected")}
                        </th>
                        <th className="py-1 pr-4 text-right">
                          {t("till_tracking.detail.col_declared")}
                        </th>
                        <th className="py-1 pr-4 text-right">
                          {t("till_tracking.detail.col_variance")}
                        </th>
                        <th className="min-w-[8rem] py-1 pl-4 text-left">
                          {t("till_tracking.detail.col_reason")}
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {cat.tender_rows.map((row) => (
                        <tr key={row.tender_key} className="border-b">
                          <td className="py-1 pr-4">{row.tender_name}</td>
                          <td className="py-1 pr-4 text-right font-mono">
                            {fmt(row.expected_amount)}
                          </td>
                          <td className="py-1 pr-4 text-right font-mono">
                            {fmt(row.declared_amount)}
                          </td>
                          <td
                            className={`py-1 pr-4 text-right font-mono ${sign(row.variance_amount)}`}
                          >
                            {fmt(row.variance_amount)}
                          </td>
                          <td className="min-w-[8rem] py-1 pl-4 text-xs text-muted-foreground">
                            {row.variance_reason ?? "—"}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          ))}
        </CardContent>
      </Card>

      {/* Audit trail */}
      <Card>
        <CardHeader>
          <CardTitle>{t("till_tracking.detail.audit_title")}</CardTitle>
        </CardHeader>
        <CardContent>
          {session.audit_trail.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("till_tracking.detail.audit_empty")}</p>
          ) : (
            <ul className="divide-y text-sm">
              {session.audit_trail.map((row) => (
                <li key={row.id} className="py-2">
                  <div className="flex items-baseline justify-between gap-2">
                    <span className="font-medium">
                      {translateOrFallback(
                        t,
                        `till_tracking.audit_action.${row.action}`,
                        row.action
                      )}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {row.logged_at ? formatDateTime(row.logged_at, locale, shopTimezone) : "—"}
                    </span>
                  </div>
                  <div className="text-xs text-muted-foreground">{row.actor_name ?? "—"}</div>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

/**
 * Bridge the plan-036 SessionDetail shape onto the plan-032 StaleSession shape
 * the ManualSettleDialog expects. The dialog uses very few fields (session
 * code, currency, ids, force-abandon metadata, etc.) so the adapter is small;
 * keeping it inline makes the dependency direction explicit without leaking a
 * stale-session-shaped type into the till-tracking service surface.
 */
function sessionDetailToStale(s: SessionDetail): StaleSession {
  return {
    id: s.id,
    session_code: s.session_code,
    status: s.status,
    business_date: s.business_date ?? "",
    default_currency_code: s.currency_code ?? "JPY",
    opening_float_amount: s.opening_float_amount ?? 0,
    expected_cash_amount: s.expected_cash_amount,
    counted_cash_amount: s.counted_cash_amount,
    cash_variance_amount: s.cash_variance_amount,
    opened_at: s.opened_at,
    closed_at: s.closed_at,
    abandoned_at: s.abandoned_at,
    expired_at: s.expired_at,
    force_abandoned: s.force_abandoned,
    force_abandoned_by_id: s.force_abandoned_by?.id ?? null,
    force_abandon_reason_code:
      s.force_abandon_reason_code as StaleSession["force_abandon_reason_code"],
    force_abandon_reason_detail: s.force_abandon_reason_detail,
    expire_reason: s.expire_reason as StaleSession["expire_reason"],
    expire_threshold_hours: s.expire_threshold_hours,
    branch_id: s.branch?.id ?? "",
    till_id: s.till?.id ?? "",
    opened_by_id: s.opened_by?.id ?? null,
    closed_by_id: s.closed_by?.id ?? null,
  };
}

function statusBadgeVariant(status: string): "default" | "secondary" | "destructive" | "outline" {
  if (status === "settled") return "default";
  if (status === "abandoned" || status === "expired") return "destructive";
  if (status === "closing") return "outline";
  return "secondary";
}

/**
 * Translate an enum-keyed label. If the dictionary doesn't have the key,
 * t() returns the raw key — that would leak "till_tracking.event_type.foo"
 * into the UI. Fall through to the raw backend value instead so the cell
 * stays readable while we add the missing key. Acts as a tripwire too: any
 * untranslated enum surfaces as the raw enum, not as a dotted key path.
 */
function translateOrFallback(t: (key: string) => string, key: string, fallback: string): string {
  const translated = t(key);
  return translated === key ? fallback : translated;
}

function Metric({
  label,
  value,
  valueClass,
}: {
  label: string;
  value: string;
  valueClass?: string;
}) {
  return (
    <div>
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className={`mt-1 text-xl font-semibold ${valueClass ?? ""}`}>{value}</div>
    </div>
  );
}

function DenominationCard({
  title,
  rows,
  totalLabel,
  totalValue,
  note,
  locale,
  currency,
}: {
  title: string;
  rows: Array<{
    denomination_id: string;
    label: string | null;
    denomination_value: number;
    quantity: number;
    subtotal_amount: number;
  }>;
  totalLabel: string;
  totalValue: string;
  note: string | null;
  locale: LocaleCode;
  currency: string;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent>
        {rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">—</p>
        ) : (
          <table className="w-full text-sm">
            <tbody>
              {rows.map((row) => (
                <tr key={row.denomination_id} className="border-b text-xs">
                  <td className="py-1">
                    {row.label ?? formatCurrency(row.denomination_value, locale, currency)}
                  </td>
                  <td className="py-1 text-right">×{row.quantity}</td>
                  <td className="py-1 text-right font-mono">
                    {formatCurrency(row.subtotal_amount, locale, currency)}
                  </td>
                </tr>
              ))}
              <tr className="font-semibold">
                <td className="py-1">{totalLabel}</td>
                <td colSpan={2} className="py-1 text-right font-mono">
                  {totalValue}
                </td>
              </tr>
            </tbody>
          </table>
        )}
        {note && <p className="mt-2 text-xs text-muted-foreground">{note}</p>}
      </CardContent>
    </Card>
  );
}
