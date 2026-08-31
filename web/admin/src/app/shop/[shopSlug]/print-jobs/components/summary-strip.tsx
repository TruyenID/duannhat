"use client";

/**
 * The strip above the ledger — plan-052 M2 / T2.3 (#1166).
 *
 * It answers the only question an operator opens this page with: *is anything
 * waiting for me?* So `needs_attention` is a button, not a number: seeing "3"
 * and then having to build the filter yourself is how a backlog stays a
 * backlog.
 *
 * `meta.aging` counts only OPEN work (queued / delivering / needs_attention /
 * failed) and buckets it by DURATION, so it reads identically in Tokyo and
 * Hanoi (#1091). Silent printers are INFERRED — Cloud never dials a machine
 * behind the shop's NAT (P-38) — which is why the card says "last heard from",
 * not "offline".
 */

import { AlertTriangle, Clock, PrinterIcon, VolumeX } from "lucide-react";
import { Button } from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import type { PrintJobAging, SilentPrinter } from "@/types/models/PrintJob";

function humanDuration(seconds: number, t: (k: string, p?: Record<string, string | number>) => string): string {
  if (seconds < 3600) return t("print_jobs.duration.minutes", { count: Math.max(1, Math.round(seconds / 60)) });
  if (seconds < 86400) return t("print_jobs.duration.hours", { count: Math.round(seconds / 3600) });
  return t("print_jobs.duration.days", { count: Math.round(seconds / 86400) });
}

interface TileProps {
  label: string;
  value: number | string;
  icon: React.ReactNode;
  tone?: "neutral" | "danger" | "warning";
  active?: boolean;
  onClick?: () => void;
  hint?: string;
  testId?: string;
}

function Tile({ label, value, icon, tone = "neutral", active, onClick, hint, testId }: TileProps) {
  const toneClass =
    tone === "danger"
      ? "border-destructive/40 bg-destructive/5"
      : tone === "warning"
        ? "border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950"
        : "border-border bg-card";

  const content = (
    <>
      <div className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
        {icon}
        <span>{label}</span>
      </div>
      <div
        className={cn(
          "text-xl leading-tight font-semibold tabular-nums",
          tone === "danger" && "text-destructive",
          tone === "warning" && "text-amber-700 dark:text-amber-300"
        )}
      >
        {value}
      </div>
      {hint && <div className="text-[10px] text-muted-foreground">{hint}</div>}
    </>
  );

  const base = cn(
    "flex min-w-[140px] flex-1 flex-col gap-0.5 rounded-md border px-3 py-2 text-left",
    toneClass,
    active && "ring-2 ring-primary/50"
  );

  if (!onClick) {
    return (
      <div className={base} data-testid={testId}>
        {content}
      </div>
    );
  }

  return (
    <button type="button" onClick={onClick} className={cn(base, "hover:bg-accent")} data-testid={testId}>
      {content}
    </button>
  );
}

export function PrintJobSummaryStrip({
  aging,
  silentPrinters,
  activeStatuses,
  onFilterStatus,
}: {
  aging?: PrintJobAging;
  silentPrinters?: SilentPrinter[];
  activeStatuses: string[];
  onFilterStatus: (statuses: string[]) => void;
}) {
  const { t } = useTranslation();

  const needsAttention = aging?.needs_attention ?? 0;
  const moneyOpen = aging?.money_document_open ?? 0;
  const totalOpen = aging?.total ?? 0;
  const buckets = aging?.buckets ?? {};
  // The oldest non-empty bucket, i.e. the worst thing on the floor right now.
  const oldestBucket = Object.entries(buckets).filter(([, n]) => n > 0).pop();
  const silent = silentPrinters ?? [];

  const isActive = (status: string) => activeStatuses.length === 1 && activeStatuses[0] === status;

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap gap-2">
        <Tile
          testId="tile-needs-attention"
          label={t("print_jobs.summary.needs_attention")}
          value={needsAttention}
          tone={needsAttention > 0 ? "danger" : "neutral"}
          icon={<AlertTriangle className="size-3.5" />}
          active={isActive("needs_attention")}
          onClick={() => onFilterStatus(isActive("needs_attention") ? [] : ["needs_attention"])}
          hint={t("print_jobs.summary.needs_attention_hint")}
        />
        <Tile
          testId="tile-open"
          label={t("print_jobs.summary.open_total")}
          value={totalOpen}
          icon={<Clock className="size-3.5" />}
          active={activeStatuses.length === 4}
          onClick={() =>
            onFilterStatus(
              activeStatuses.length === 4 ? [] : ["queued", "delivering", "needs_attention", "failed"]
            )
          }
          hint={
            oldestBucket
              ? t("print_jobs.summary.oldest_bucket", { bucket: oldestBucket[0], count: oldestBucket[1] })
              : t("print_jobs.summary.nothing_ageing")
          }
        />
        <Tile
          testId="tile-money"
          label={t("print_jobs.summary.money_open")}
          value={moneyOpen}
          tone={moneyOpen > 0 ? "warning" : "neutral"}
          icon={<PrinterIcon className="size-3.5" />}
          hint={t("print_jobs.summary.money_open_hint")}
        />
        <Tile
          testId="tile-silent"
          label={t("print_jobs.summary.silent_printers")}
          value={silent.length}
          tone={silent.length > 0 ? "warning" : "neutral"}
          icon={<VolumeX className="size-3.5" />}
          hint={t("print_jobs.summary.silent_printers_hint")}
        />
      </div>

      {silent.length > 0 && (
        <div
          className="flex flex-col gap-1 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
          data-testid="silent-printers-banner"
        >
          {silent.map((printer) => (
            <div key={printer.printer_id} className="flex flex-wrap items-center gap-1.5">
              <VolumeX className="size-3.5 shrink-0" />
              <span className="font-medium">{printer.printer_name}</span>
              <span>
                {t("print_jobs.summary.silent_for", {
                  duration: humanDuration(printer.silent_for_seconds, t),
                })}
              </span>
              <span className="text-[10px] opacity-80">
                {t(`print_jobs.detection.${printer.detection}`)}
              </span>
            </div>
          ))}
        </div>
      )}

      {needsAttention > 0 && (
        <div
          className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-xs"
          data-testid="needs-attention-banner"
        >
          <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" />
          <div className="flex flex-col gap-1">
            <span>{t("print_jobs.summary.needs_attention_banner", { count: needsAttention })}</span>
            <Button
              size="sm"
              variant="outline"
              className="h-6 w-fit text-[11px]"
              onClick={() => onFilterStatus(["needs_attention"])}
            >
              {t("print_jobs.summary.show_needs_attention")}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
