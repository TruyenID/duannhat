"use client";

/**
 * One print job, in full — plan-052 M2 / T2.2 (#1166).
 *
 * The page exists to answer one question honestly: *why is this slip not
 * settled, and what may I do about it?* So the delivery block does not just
 * print `attempts: 1 / 1` — it names the tier that owns the queue (DESIGN §1b)
 * and, when `auto_retry_allowed` is false, says WHY in a sentence a manager
 * can act on. For a money document that sentence is always the same and always
 * true: the system will never re-send it on its own, because an ACK-lost
 * receipt is indistinguishable from a printed one and a wrong guess puts two
 * originals of one インボイス in the world (RISKS PR1).
 *
 * There is no retry button here, for any kind. Reprinting a money document is
 * an accounting event behind the reprint gate (P-10); retrying a kitchen ticket
 * is the workstation's job, on the workstation's schedule.
 */

import { useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Separator,
  Skeleton,
} from "@godxjp/ui";
import { AlertTriangle, Ban, CheckCircle2, Cpu, Info, ShieldAlert } from "lucide-react";
import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { formatDateTime } from "@/lib/date";
import { useTimezone, useTranslation } from "@/providers/app-provider";
import { usePrintJob, useResolvePrintJob } from "@/hooks/api/use-print-jobs";
import { formatTtl, noAutoRetryReasonKey, queueOwnerFor } from "@/lib/print-jobs";
import type { PrintJobDetail } from "@/types/models/PrintJob";
import { MetaPill, PrintJobConfidenceBadge, PrintJobStatusBadge } from "../components/job-badges";
import { ResolvePrintJobDialog } from "../components/resolve-dialog";

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-[11px] text-muted-foreground">{label}</span>
      <span className="text-xs">{children}</span>
    </div>
  );
}

export default function ShopPrintJobDetailPage() {
  const { shopSlug, jobId } = useParams<{ shopSlug: string; jobId: string }>();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const { data, isLoading, isFetching, refetch } = usePrintJob(shopSlug, jobId);
  const job: PrintJobDetail | null = data?.data ?? null;

  const resolveMutation = useResolvePrintJob(shopSlug, jobId);
  const [resolveOpen, setResolveOpen] = useState(false);

  const when = (value: string | null) => (value ? formatDateTime(value, locale, timezone) : "—");

  const queueOwner = job ? queueOwnerFor(job.transport) : "cloud";

  // Five distinct reasons, ordered by honesty — see noAutoRetryReasonKey.
  const noAutoRetryReason = useMemo(
    () => (job === null ? null : noAutoRetryReasonKey(job)),
    [job]
  );

  const ttl = job ? formatTtl(job.delivery.ttl_seconds) : null;

  return (
    <>
      <PageHeader
        title={t("print_jobs.detail.title")}
        description={job?.kind ? t(`print_jobs.kind.${job.kind}`) : undefined}
        backHref={`/shop/${shopSlug}/print-jobs`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        {job && job.status !== "printed" && (
          <Button
            size="sm"
            className="h-7 gap-1 text-xs"
            onClick={() => setResolveOpen(true)}
            data-testid="open-resolve"
          >
            <CheckCircle2 className="size-3.5" />
            {t("print_jobs.resolve.open")}
          </Button>
        )}
        {job && job.status === "printed" && (
          <Button
            size="sm"
            variant="outline"
            className="h-7 gap-1 text-xs"
            onClick={() => setResolveOpen(true)}
            data-testid="open-resolve-printed"
            title={t("print_jobs.resolve.error.already_printed")}
          >
            <CheckCircle2 className="size-3.5" />
            {t("print_jobs.resolve.open")}
          </Button>
        )}
      </PageHeader>

      <PageContent className="flex flex-col gap-3">
        {isLoading && !job ? (
          <div className="flex flex-col gap-2">
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-40 w-full" />
          </div>
        ) : !job ? (
          <p className="text-xs text-muted-foreground">{t("print_jobs.detail.not_found")}</p>
        ) : (
          <>
            {/* ── Header card: what happened, and how sure we are ────────── */}
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="flex flex-wrap items-center gap-2 text-sm">
                  {job.kind ? t(`print_jobs.kind.${job.kind}`) : "—"}
                  <PrintJobStatusBadge status={job.status} />
                  <PrintJobConfidenceBadge label={job.confidence_label} />
                  {job.is_money_document && (
                    <MetaPill emphasis>{t("print_jobs.money_document")}</MetaPill>
                  )}
                  {(job.reprint_no ?? 1) > 1 && (
                    <MetaPill>{t("print_jobs.reprint_no", { no: job.reprint_no ?? 1 })}</MetaPill>
                  )}
                </CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {job.confidence_label === "printed_sent_only" && (
                  <div
                    className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
                    data-testid="sent-only-explainer"
                  >
                    <Info className="mt-0.5 size-4 shrink-0" />
                    <span>{t("print_jobs.confidence.printed_sent_only_explainer")}</span>
                  </div>
                )}
                {job.confidence_label === "printed_confirmed" && (
                  <div
                    className="flex items-start gap-2 rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-200"
                    data-testid="confirmed-explainer"
                  >
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                    <span>{t("print_jobs.confidence.printed_confirmed_explainer")}</span>
                  </div>
                )}

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                  <Field label={t("print_jobs.col.event_at")}>{when(job.event_at)}</Field>
                  <Field label={t("print_jobs.col.printer")}>
                    {job.printer_name || "—"}
                    <span className="ml-1 text-[10px] text-muted-foreground">
                      {job.transport ? t(`print_jobs.transport.${job.transport}`) : ""}
                    </span>
                  </Field>
                  <Field label={t("print_jobs.detail.requested_via")}>
                    {job.requested_via || "—"}
                  </Field>
                  <Field label={t("print_jobs.detail.job_id")}>
                    <span className="font-mono text-[11px]">{job.id}</span>
                  </Field>
                </div>

                {job.reprint_reason && (
                  <Field label={t("print_jobs.detail.reprint_reason")}>{job.reprint_reason}</Field>
                )}
              </CardContent>
            </Card>

            {/* ── Delivery: attempts, ownership, and the retry truth ──────── */}
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-1.5 text-sm">
                  <Cpu className="size-4" />
                  {t("print_jobs.detail.delivery")}
                </CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                  <Field label={t("print_jobs.col.attempts")}>
                    <span className="tabular-nums">
                      {job.delivery.attempts ?? 0} / {job.delivery.max_attempts}
                    </span>
                  </Field>
                  <Field label={t("print_jobs.detail.queue_owner")}>
                    <Badge variant="soft" color={queueOwner === "workstation" ? "info" : "primary"} className="text-[10px]">
                      {t(`print_jobs.queue_owner.${job.delivery.queue_owner ?? queueOwner}`)}
                    </Badge>
                  </Field>
                  <Field label={t("print_jobs.detail.ttl")}>
                    {ttl ? t(ttl.key, { count: ttl.value }) : "—"}
                  </Field>
                  <Field label={t("print_jobs.detail.expires_at")}>{when(job.expires_at)}</Field>
                </div>

                {job.delivery.last_error && (
                  <Field label={t("print_jobs.col.last_error")}>
                    <span className="font-mono text-[11px] break-all">{job.delivery.last_error}</span>
                  </Field>
                )}

                {/* THE sentence. Never soften it and never hide it. */}
                {noAutoRetryReason ? (
                  <div
                    className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-xs"
                    data-testid="no-auto-retry"
                  >
                    <Ban className="mt-0.5 size-4 shrink-0 text-destructive" />
                    <div className="flex flex-col gap-1">
                      <span className="font-medium text-destructive">
                        {t("print_jobs.detail.no_auto_retry_title")}
                      </span>
                      <span>{t(noAutoRetryReason)}</span>
                      {job.is_money_document && (
                        <span className="text-[11px] text-muted-foreground">
                          {t("print_jobs.detail.money_next_steps")}
                        </span>
                      )}
                    </div>
                  </div>
                ) : (
                  <div
                    className="flex items-start gap-2 rounded-md border border-border bg-muted px-3 py-2 text-xs text-muted-foreground"
                    data-testid="auto-retry-allowed"
                  >
                    <Info className="mt-0.5 size-4 shrink-0" />
                    <span>
                      {t("print_jobs.detail.auto_retry_allowed", {
                        owner: t(`print_jobs.queue_owner.${job.delivery.queue_owner ?? queueOwner}`),
                      })}
                    </span>
                  </div>
                )}

                {job.delivery.queue_owner === "workstation" && (
                  <div className="flex items-start gap-2 text-[11px] text-muted-foreground">
                    <ShieldAlert className="mt-0.5 size-3.5 shrink-0" />
                    <span>{t("print_jobs.detail.workstation_owned")}</span>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* ── Timeline ───────────────────────────────────────────────── */}
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm">{t("print_jobs.detail.timeline")}</CardTitle>
              </CardHeader>
              <CardContent>
                {job.timeline.length === 0 ? (
                  <span className="text-xs text-muted-foreground">—</span>
                ) : (
                  <ol className="flex flex-col gap-1.5">
                    {job.timeline.map((entry) => (
                      <li key={entry.event} className="flex items-center gap-2 text-xs">
                        <span className="w-40 text-muted-foreground">
                          {t(`print_jobs.timeline.${entry.event}`)}
                        </span>
                        <span className="tabular-nums">{when(entry.at)}</span>
                      </li>
                    ))}
                  </ol>
                )}
              </CardContent>
            </Card>

            {/* ── Resolution history ─────────────────────────────────────── */}
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm">{t("print_jobs.detail.resolutions")}</CardTitle>
              </CardHeader>
              <CardContent>
                {job.resolution?.resolution ? (
                  <div className="flex flex-col gap-1.5" data-testid="resolution-block">
                    <div className="flex items-center gap-2">
                      <Badge variant="soft" color="info" className="text-[10px]">
                        {t(`print_jobs.resolution.${job.resolution.resolution}`)}
                      </Badge>
                      <span className="text-xs text-muted-foreground">
                        {when(job.resolution.resolved_at)}
                      </span>
                    </div>
                    <p className="text-xs">{job.resolution.reason}</p>
                    {job.resolution.resolved_by_id && (
                      <span className="font-mono text-[10px] text-muted-foreground">
                        {job.resolution.resolved_by_id}
                      </span>
                    )}
                  </div>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    {t("print_jobs.detail.no_resolution")}
                  </p>
                )}
              </CardContent>
            </Card>

            {/* ── Payload metadata (no slip body — see below) ─────────────── */}
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm">{t("print_jobs.detail.payload")}</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                <p className="text-[11px] text-muted-foreground">
                  {t("print_jobs.detail.payload_note")}
                </p>
                <Separator />
                {job.payload && Object.keys(job.payload).length > 0 ? (
                  <dl className="grid grid-cols-2 gap-2 md:grid-cols-3">
                    {Object.entries(job.payload).map(([key, value]) => (
                      <div key={key} className="flex flex-col gap-0.5">
                        <dt className="text-[11px] text-muted-foreground">{key}</dt>
                        <dd className="font-mono text-[11px] break-all">
                          {typeof value === "object" && value !== null
                            ? JSON.stringify(value)
                            : String(value)}
                        </dd>
                      </div>
                    ))}
                  </dl>
                ) : (
                  <span className="text-xs text-muted-foreground">—</span>
                )}
              </CardContent>
            </Card>

            {job.status === "needs_attention" && (
              <div className="flex items-start gap-2 rounded-md border border-border bg-muted px-3 py-2 text-xs text-muted-foreground">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                <span>
                  {t("print_jobs.detail.needs_attention_playbook")}{" "}
                  <Link
                    href={`/shop/${shopSlug}/printers`}
                    className="text-primary underline-offset-2 hover:underline"
                  >
                    {t("print_jobs.detail.open_printers")}
                  </Link>
                </span>
              </div>
            )}
          </>
        )}
      </PageContent>

      <ResolvePrintJobDialog
        open={resolveOpen}
        onOpenChange={setResolveOpen}
        job={job}
        isPending={resolveMutation.isPending}
        onSubmit={(input) => resolveMutation.mutateAsync(input)}
      />
    </>
  );
}
