"use client";

/**
 * HQ Email Delivery Health page.
 *
 * Three tabs share one date-range filter hoisted at the page level so
 * switching tabs preserves the window the operator chose:
 *   - Metrics: 4 clickable tiles (sent/delivered/bounced/spam) over the
 *     selected window. Clicking a tile that maps to a suppression reason
 *     (bounce → hard_bounce, spam → spam_complaint) jumps to the
 *     Suppressions tab pre-filtered to that reason.
 *   - Suppression list: paginated, filter by reason, manual-add +
 *     un-suppress, honors the same date range.
 *   - Retry failures (deadletter): placeholder until per-channel
 *     retry surface lands in M4 follow-up.
 */

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Skeleton,
  Spinner,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import { AlertCircle, MailX, Plus, ShieldX } from "lucide-react";
import { useParams } from "next/navigation";
import { useMemo, useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import {
  useEmailHealthMetrics,
  useEmailHealthTimeseries,
  useEmailSuppressions,
  useStoreEmailSuppression,
  useUnsuppressEmail,
} from "@/hooks/api/use-notification-email-suppressions";
import { DeliverabilityChart } from "./components/deliverability-chart";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime, localDateString } from "@/lib/date";
import type {
  EmailHealthMetrics,
  SuppressionReason,
  SuppressionRow,
} from "@/services/notification-email-suppression-service";

type Tab = "metrics" | "suppressions" | "deadletter";

interface DateRange {
  from: string;
  to: string;
}

function defaultRange(): DateRange {
  const from = new Date();
  from.setDate(from.getDate() - 30);
  // localDateString (LOCAL date), not toISOString (UTC) — in +offset zones the
  // UTC date is yesterday before 09:00 local, silently dropping today's data.
  return { from: localDateString(from), to: localDateString() };
}

/**
 * Convert `YYYY-MM-DD` page-state strings to the ISO instants the backend
 * expects. `to` is bumped to the end of the local day so picking the same
 * day for from/to still includes events that happened during it.
 */
function toIsoRange(range: DateRange): { from?: string; to?: string } {
  const out: { from?: string; to?: string } = {};
  if (range.from) out.from = new Date(`${range.from}T00:00:00`).toISOString();
  if (range.to) out.to = new Date(`${range.to}T23:59:59.999`).toISOString();
  return out;
}

export default function EmailHealthPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();
  const [tab, setTab] = useState<Tab>("metrics");
  const [range, setRange] = useState<DateRange>(defaultRange);
  const [reason, setReason] = useState<SuppressionReason | "all">("all");

  // Lexical compare works on `YYYY-MM-DD` strings — no need to parse.
  const rangeInvalid = Boolean(range.from && range.to && range.from > range.to);

  function drilldown(r: SuppressionReason) {
    setReason(r);
    setTab("suppressions");
  }

  return (
    <>
      <PageHeader
        title={t("notifications.email_health.title")}
        description={t("notifications.email_health.subtitle")}
      />
      <PageContent>
        <DateRangeBar value={range} onChange={setRange} invalid={rangeInvalid} />

        <Tabs value={tab} onValueChange={(v) => setTab(v as Tab)} className="w-full">
          <TabsList>
            <TabsTrigger value="metrics">
              {t("notifications.email_health.tab.deliverability")}
            </TabsTrigger>
            <TabsTrigger value="suppressions">
              {t("notifications.email_health.tab.suppressions")}
            </TabsTrigger>
            <TabsTrigger value="deadletter">
              {t("notifications.email_health.tab.deadletter")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="metrics" className="pt-4">
            <MetricsTab
              brandSlug={brandSlug}
              range={range}
              enabled={!rangeInvalid}
              onDrilldown={drilldown}
            />
          </TabsContent>
          <TabsContent value="suppressions" className="pt-4">
            <SuppressionsTab
              brandSlug={brandSlug}
              range={range}
              reason={reason}
              onReasonChange={setReason}
              enabled={tab === "suppressions" && !rangeInvalid}
            />
          </TabsContent>
          <TabsContent value="deadletter" className="pt-4">
            <DeadletterTab />
          </TabsContent>
        </Tabs>
      </PageContent>
    </>
  );
}

function DateRangeBar({
  value,
  onChange,
  invalid,
}: {
  value: DateRange;
  onChange: (next: DateRange) => void;
  invalid: boolean;
}) {
  const { t } = useTranslation();

  function applyPreset(days: number) {
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - days);
    onChange({ from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) });
  }

  return (
    <div className="mb-4 space-y-2">
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground" htmlFor="email-health-from">
            {t("notifications.email_health.range.from")}
          </label>
          <Input
            id="email-health-from"
            type="date"
            value={value.from}
            max={value.to || undefined}
            aria-invalid={invalid || undefined}
            onChange={(e) => onChange({ ...value, from: e.target.value })}
            className="w-40"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground" htmlFor="email-health-to">
            {t("notifications.email_health.range.to")}
          </label>
          <Input
            id="email-health-to"
            type="date"
            value={value.to}
            min={value.from || undefined}
            aria-invalid={invalid || undefined}
            onChange={(e) => onChange({ ...value, to: e.target.value })}
            className="w-40"
          />
        </div>
        <div className="flex gap-1">
          <Button variant="outline" size="sm" onClick={() => applyPreset(0)}>
            {t("notifications.email_health.range.today")}
          </Button>
          <Button variant="outline" size="sm" onClick={() => applyPreset(7)}>
            {t("notifications.email_health.range.last_7_days")}
          </Button>
          <Button variant="outline" size="sm" onClick={() => applyPreset(30)}>
            {t("notifications.email_health.range.last_30_days")}
          </Button>
        </div>
      </div>
      {invalid ? (
        <p className="text-xs text-destructive" role="alert">
          {t("notifications.email_health.range.invalid")}
        </p>
      ) : null}
    </div>
  );
}

function MetricsTab({
  brandSlug,
  range,
  enabled,
  onDrilldown,
}: {
  brandSlug: string;
  range: DateRange;
  enabled: boolean;
  onDrilldown: (reason: SuppressionReason) => void;
}) {
  const { t } = useTranslation();
  const iso = useMemo(() => toIsoRange(range), [range]);
  const { data, isLoading, isError, refetch } = useEmailHealthMetrics(brandSlug, iso, { enabled });
  const timeseries = useEmailHealthTimeseries(brandSlug, iso, { enabled });

  if (isError) {
    return (
      <Alert variant="destructive">
        <AlertCircle className="size-4" />
        <AlertDescription className="flex items-center justify-between">
          {t("common.error_loading")}
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            {t("common.retry")}
          </Button>
        </AlertDescription>
      </Alert>
    );
  }

  const m: EmailHealthMetrics = data?.data ?? {
    sent: 0,
    delivered: 0,
    bounced: 0,
    spam: 0,
    unsubscribed: 0,
  };

  const tiles: Array<{
    key: keyof EmailHealthMetrics;
    labelKey: string;
    value: number;
    drillTo?: SuppressionReason;
    accent: string;
  }> = [
    {
      key: "sent",
      labelKey: "notifications.email_health.metrics.sent",
      value: m.sent,
      accent: "text-foreground",
    },
    {
      key: "delivered",
      labelKey: "notifications.email_health.metrics.delivered",
      value: m.delivered,
      accent: "text-emerald-600 dark:text-emerald-400",
    },
    {
      key: "bounced",
      labelKey: "notifications.email_health.metrics.bounced",
      value: m.bounced,
      drillTo: "hard_bounce",
      accent: "text-amber-600 dark:text-amber-400",
    },
    {
      key: "spam",
      labelKey: "notifications.email_health.metrics.spam",
      value: m.spam,
      drillTo: "spam_complaint",
      accent: "text-red-600 dark:text-red-400",
    },
    {
      key: "unsubscribed",
      labelKey: "notifications.email_health.metrics.unsubscribed",
      value: m.unsubscribed,
      drillTo: "subscription_change",
      accent: "text-sky-600 dark:text-sky-400",
    },
  ];

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
        {tiles.map((tile) => {
          const clickable = tile.drillTo !== undefined;
          const inner = (
            <CardContent className="p-4">
              <p className="text-xs tracking-wide text-muted-foreground uppercase">
                {t(tile.labelKey)}
              </p>
              {isLoading ? (
                <Skeleton className="mt-2 h-8 w-16" />
              ) : (
                <p className={`mt-1 text-2xl font-semibold ${tile.accent}`}>
                  {tile.value.toLocaleString()}
                </p>
              )}
            </CardContent>
          );
          return clickable ? (
            <Card
              key={tile.key}
              data-slot={`metric-${tile.key}`}
              role="button"
              tabIndex={0}
              onClick={() => tile.drillTo && onDrilldown(tile.drillTo)}
              onKeyDown={(e) => {
                if ((e.key === "Enter" || e.key === " ") && tile.drillTo) {
                  e.preventDefault();
                  onDrilldown(tile.drillTo);
                }
              }}
              className="cursor-pointer transition-colors hover:bg-muted/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {inner}
            </Card>
          ) : (
            <Card key={tile.key} data-slot={`metric-${tile.key}`}>
              {inner}
            </Card>
          );
        })}
      </div>
      <p className="text-xs text-muted-foreground">
        {t("notifications.email_health.metrics.click_to_drilldown")}
      </p>

      <Card data-slot="deliverability-chart-card">
        <CardContent className="p-4">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="text-sm font-medium">{t("notifications.email_health.chart.title")}</h2>
            <p className="text-xs text-muted-foreground">
              {t("notifications.email_health.chart.subtitle")}
            </p>
          </div>
          <DeliverabilityChart
            data={timeseries.data?.data ?? []}
            isLoading={timeseries.isLoading}
          />
        </CardContent>
      </Card>
    </div>
  );
}

function DeadletterTab() {
  const { t } = useTranslation();
  return (
    <Card data-slot="deadletter-placeholder">
      <CardContent className="p-8 text-center text-sm text-muted-foreground">
        <MailX className="mx-auto mb-3 size-8 text-muted-foreground/60" />
        <p>{t("notifications.email_health.deliverability.coming_soon")}</p>
      </CardContent>
    </Card>
  );
}

function SuppressionsTab({
  brandSlug,
  range,
  reason,
  onReasonChange,
  enabled,
}: {
  brandSlug: string;
  range: DateRange;
  reason: SuppressionReason | "all";
  onReasonChange: (reason: SuppressionReason | "all") => void;
  enabled: boolean;
}) {
  const { t } = useTranslation();
  const [addDialog, setAddDialog] = useState(false);
  const [confirmUnsuppress, setConfirmUnsuppress] = useState<SuppressionRow | null>(null);

  const iso = useMemo(() => toIsoRange(range), [range]);
  const filters = useMemo(
    () => ({
      ...(reason !== "all" ? { reason } : {}),
      active_only: true,
      ...iso,
    }),
    [reason, iso]
  );

  const { data, isLoading, isError, refetch } = useEmailSuppressions(brandSlug, filters, {
    enabled,
  });
  const unsuppressMutation = useUnsuppressEmail(brandSlug);

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <ReasonFilter value={reason} onChange={onReasonChange} />
        <Button size="sm" onClick={() => setAddDialog(true)}>
          <Plus className="mr-1 size-3.5" />
          {t("notifications.email_health.suppressions.add")}
        </Button>
      </div>

      {isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertDescription className="flex items-center justify-between">
            {t("common.error_loading")}
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              {t("common.retry")}
            </Button>
          </AlertDescription>
        </Alert>
      ) : null}

      {isLoading ? (
        <div className="space-y-2">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-14 w-full" />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <Card>
          <CardContent className="p-8 text-center text-sm text-muted-foreground">
            <ShieldX className="mx-auto mb-3 size-8 text-muted-foreground/60" />
            {t("notifications.email_health.suppressions.empty")}
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-2">
          {rows.map((row) => (
            <SuppressionCard
              key={row.id}
              row={row}
              onUnsuppress={() => setConfirmUnsuppress(row)}
            />
          ))}
        </div>
      )}

      <AddSuppressionDialog
        open={addDialog}
        brandSlug={brandSlug}
        onClose={() => setAddDialog(false)}
      />

      <Dialog
        open={confirmUnsuppress !== null}
        onOpenChange={(open) => !open && setConfirmUnsuppress(null)}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("notifications.email_health.suppressions.unsuppress")}</DialogTitle>
            <DialogDescription>{confirmUnsuppress?.email}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmUnsuppress(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              onClick={async () => {
                if (!confirmUnsuppress) return;
                await unsuppressMutation.mutateAsync(confirmUnsuppress.id);
                setConfirmUnsuppress(null);
              }}
              disabled={unsuppressMutation.isPending}
            >
              {unsuppressMutation.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
              {t("notifications.email_health.suppressions.unsuppress")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function ReasonFilter({
  value,
  onChange,
}: {
  value: SuppressionReason | "all";
  onChange: (v: SuppressionReason | "all") => void;
}) {
  const { t } = useTranslation();
  const options: Array<{ value: SuppressionReason | "all"; label: string }> = [
    { value: "all", label: t("common.all") },
    {
      value: "hard_bounce",
      label: t("notifications.email_health.suppressions.reason.hard_bounce"),
    },
    {
      value: "spam_complaint",
      label: t("notifications.email_health.suppressions.reason.spam_complaint"),
    },
    {
      value: "subscription_change",
      label: t("notifications.email_health.suppressions.reason.subscription_change"),
    },
    { value: "manual", label: t("notifications.email_health.suppressions.reason.manual") },
  ];
  return (
    <div className="flex flex-wrap items-center gap-1">
      {options.map((opt) => (
        <Button
          key={opt.value}
          variant={value === opt.value ? "default" : "outline"}
          size="sm"
          onClick={() => onChange(opt.value)}
        >
          {opt.label}
        </Button>
      ))}
    </div>
  );
}

function SuppressionCard({ row, onUnsuppress }: { row: SuppressionRow; onUnsuppress: () => void }) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  return (
    <Card data-slot="suppression-card">
      <CardContent className="flex items-center gap-4 p-3">
        <MailX className="size-5 text-muted-foreground" />
        <div className="min-w-0 flex-1">
          <p className="truncate font-medium">{row.email}</p>
          <p className="text-xs text-muted-foreground">
            {formatDateTime(row.suppressed_at, locale, timezone)}
          </p>
        </div>
        <Badge variant="secondary">
          {t(`notifications.email_health.suppressions.reason.${row.reason}` as const)}
        </Badge>
        <Button variant="ghost" size="sm" onClick={onUnsuppress}>
          {t("notifications.email_health.suppressions.unsuppress")}
        </Button>
      </CardContent>
    </Card>
  );
}

function AddSuppressionDialog({
  open,
  brandSlug,
  onClose,
}: {
  open: boolean;
  brandSlug: string;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const [email, setEmail] = useState("");
  const [error, setError] = useState<string | null>(null);
  const mutation = useStoreEmailSuppression(brandSlug);

  async function onSubmit() {
    setError(null);
    if (!email.includes("@")) {
      setError("invalid_email");
      return;
    }
    try {
      await mutation.mutateAsync({ email, reason: "manual" });
      setEmail("");
      onClose();
    } catch {
      setError(t("common.error_loading"));
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        if (!o) {
          setEmail("");
          setError(null);
          onClose();
        }
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("notifications.email_health.suppressions.add_dialog_title")}</DialogTitle>
          <DialogDescription>
            {t("notifications.email_health.suppressions.add_dialog_body")}
          </DialogDescription>
        </DialogHeader>
        <Input
          type="email"
          placeholder={t("notifications.email_health.suppressions.add_email_placeholder")}
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
        {error ? (
          <Alert variant="destructive">
            <AlertCircle className="size-4" />
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        ) : null}
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button onClick={onSubmit} disabled={mutation.isPending}>
            {mutation.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
            {t("notifications.email_health.suppressions.add")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
